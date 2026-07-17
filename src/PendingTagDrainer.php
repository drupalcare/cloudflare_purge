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
class PendingTagDrainer implements PendingTagDrainerInterface {

  /**
   * Lock name guarding concurrent drains (e.g. overlapping cron runs).
   */
  private const LOCK_NAME = 'cloudflare_purge_pending_tag_drain';

  /**
   * Hard ceiling on tags read per drain call.
   *
   * Not user-configurable: how fast Cloudflare can actually process purges is
   * already governed by CloudflarePurgeApi::MAX_BATCH_SIZE (tags per API
   * call) and the rate-limit/TTL settings, so capping this any lower would
   * only slow how quickly genuinely pending tags get promoted into queue
   * items. This exists purely as a memory-safety backstop against reading an
   * extreme, pathological backlog (e.g. millions of rows from a botched
   * migration) into a single PHP array in one cron run.
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
    if (!$this->lock->acquire(self::LOCK_NAME)) {
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
    $rows = $this->pendingTagsStore->getOldest(self::HARD_LIMIT);
    if ($rows === []) {
      return 0;
    }

    $queue = $this->queueFactory->get(CloudflareCacheTagInvalidator::QUEUE_NAME);
    $batches = array_chunk($rows, max(1, CloudflarePurgeApi::MAX_BATCH_SIZE));
    $drained = 0;

    foreach ($batches as $batch) {
      $tags = array_column($batch, 'tag');
      $ids = array_column($batch, 'id');
      // Age the queue item from its oldest tag, not from drain time, so the
      // configured queue TTL still reflects how long the invalidation has
      // genuinely been waiting.
      $timestamp = min(array_column($batch, 'created'));

      $queue->createItem([
        'tags' => $tags,
        'timestamp' => $timestamp,
        'retries' => 0,
      ]);
      $this->pendingTagsStore->deleteByIds($ids);
      $drained += count($tags);
    }

    $this->logger->debug('Drained @count pending cache tag(s) into @batches Cloudflare purge queue item(s).', [
      '@count' => $drained,
      '@batches' => count($batches),
    ]);

    return $drained;
  }

}
