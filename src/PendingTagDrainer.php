<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Default pending-tag drainer.
 *
 * @internal
 *
 * @package Drupal\cloudflare_purge
 */
final class PendingTagDrainer implements PendingTagDrainerInterface {

  /**
   * Lock name guarding concurrent drains (e.g. overlapping cron runs).
   */
  private const LOCK_NAME = 'cloudflare_purge_pending_tag_drain';

  /**
   * Lock lease, in seconds.
   *
   * Longer than the core default (30s) because a large drain can perform many
   * queue-insert/delete round trips. The lease is re-acquired (renewed) after
   * every page so even an unusually long drain keeps holding the lock rather
   * than letting a second cron run start draining the same rows.
   */
  private const LOCK_LEASE = 120.0;

  /**
   * Number of pending rows read per page.
   *
   * Bounds how many rows are held in memory at once and gives a natural point
   * to renew the lock between pages. A multiple of MAX_BATCH_SIZE so pages
   * split cleanly into whole Cloudflare-sized queue items.
   */
  private const PAGE_SIZE = 2000;

  /**
   * Hard ceiling on tags drained per call.
   *
   * Not user-configurable: how fast Cloudflare can actually process purges is
   * already governed by CloudflarePurgeApi::MAX_BATCH_SIZE (tags per API
   * call) and the rate-limit/TTL settings, so capping this any lower would
   * only slow how quickly genuinely pending tags get promoted into queue
   * items. This exists purely as a backstop against draining an extreme,
   * pathological backlog (e.g. millions of rows from a botched migration) in a
   * single cron run; the remainder is drained on subsequent runs.
   */
  private const HARD_LIMIT = 100000;

  /**
   * Constructs a PendingTagDrainer.
   *
   * @param \Drupal\cloudflare_purge\PendingTagsStoreInterface $pendingTagsStore
   *   The pending-tags store.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    private readonly PendingTagsStoreInterface $pendingTagsStore,
    private readonly QueueFactory $queueFactory,
    private readonly LockBackendInterface $lock,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function drain(): int {
    // Draining is not reentrant-safe (a second, concurrent drain could read
    // and delete the same rows twice); skip rather than block if another
    // drain (e.g. an overlapping cron run) is already in progress.
    if (!$this->lock->acquire(self::LOCK_NAME, self::LOCK_LEASE)) {
      return 0;
    }

    try {
      return $this->doDrain();
    }
    finally {
      $this->lock->release(self::LOCK_NAME);
    }
  }

  /**
   * Performs the actual drain, once the lock is held.
   *
   * @return int
   *   The number of tags drained into queue items.
   */
  private function doDrain(): int {
    $queue = $this->queueFactory->get(CloudflareCacheTagInvalidator::QUEUE_NAME);
    $drained = 0;
    $batches = 0;

    while ($drained < self::HARD_LIMIT) {
      $pageSize = min(self::PAGE_SIZE, self::HARD_LIMIT - $drained);
      $rows = $this->pendingTagsStore->getOldest($pageSize);
      if ($rows === []) {
        break;
      }

      foreach (array_chunk($rows, max(1, CloudflarePurgeApi::MAX_BATCH_SIZE)) as $batch) {
        $tags = array_column($batch, 'tag');
        $ids = array_column($batch, 'id');
        // Age the queue item from the freshest invalidation in the batch, not
        // the oldest. The queue TTL discard is all-or-nothing per item, so
        // stamping with the newest keeps a recently re-invalidated tag from
        // being dropped just because an older tag rides along in the same
        // batch. Worst case an already-stale tag is re-purged in the same API
        // call — free, since it costs no extra request.
        $timestamp = max(array_column($batch, 'last_invalidated'));

        // Only remove the pending rows once the queue item is safely stored.
        // QueueInterface::createItem() can return FALSE (without throwing) on a
        // backend failure; deleting first would silently lose the purge. Leave
        // the rows in place and stop; the next drain retries them.
        $itemId = $queue->createItem([
          'tags' => $tags,
          'timestamp' => $timestamp,
          'retries' => 0,
        ]);
        if ($itemId === FALSE) {
          $this->logger->error('Failed to create a Cloudflare purge queue item while draining pending tags; @count tag(s) left pending for the next drain.', [
            '@count' => count($tags),
          ]);
          $this->logDrained($drained, $batches);
          return $drained;
        }

        // Delete only rows not re-invalidated since they were read: a tag
        // whose last_invalidated advanced past the queued timestamp is kept so
        // its fresh invalidation is drained again rather than lost.
        $this->pendingTagsStore->deleteDrained($ids, $timestamp);
        $drained += count($tags);
        $batches++;

        // Renew the lock lease after every batch so even a very slow drain
        // keeps holding it, bounding the unprotected window to a single batch.
        // If another process has taken the lock over (our lease expired), stop
        // rather than risk a concurrent drain double-queueing rows.
        if (!$this->lock->acquire(self::LOCK_NAME, self::LOCK_LEASE)) {
          $this->logDrained($drained, $batches);
          return $drained;
        }
      }

      // A short final page means the store is now empty; avoid an extra query.
      if (count($rows) < $pageSize) {
        break;
      }
    }

    $this->logDrained($drained, $batches);

    return $drained;
  }

  /**
   * Logs the outcome of a drain, if anything was drained.
   *
   * @param int $drained
   *   The number of tags drained into queue items.
   * @param int $batches
   *   The number of queue items created.
   */
  private function logDrained(int $drained, int $batches): void {
    if ($drained === 0) {
      return;
    }

    $this->logger->debug('Drained @count pending cache tag(s) into @batches Cloudflare purge queue item(s).', [
      '@count' => $drained,
      '@batches' => $batches,
    ]);
  }

}
