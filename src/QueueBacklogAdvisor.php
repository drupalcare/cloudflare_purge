<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Default queue-backlog advisor.
 *
 * @internal
 *
 * @package Drupal\cloudflare_purge
 */
class QueueBacklogAdvisor implements QueueBacklogAdvisorInterface {

  use StringTranslationTrait;

  /**
   * Above this estimated tag count, an unset rate limit is flagged.
   */
  private const HIGH_TAG_COUNT_THRESHOLD = 1000;

  /**
   * Above this estimated minutes-to-clear, a configured rate limit is flagged.
   */
  private const SLOW_DRAIN_MINUTES_THRESHOLD = 10;

  /**
   * Constructs a QueueBacklogAdvisor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\cloudflare_purge\PendingTagsStoreInterface $pendingTagsStore
   *   The pending-tags store.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PendingTagsStoreInterface $pendingTagsStore,
    private readonly QueueFactory $queueFactory,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function getWarnings(): array {
    $config = $this->configFactory->get(CloudflarePurgeSettings::NAME);

    // Nothing is merged into the pending set (and therefore nothing can back
    // up) unless queueing is in use.
    if ($config->get('auto_purge_use_queue') !== TRUE) {
      return [];
    }

    $pendingCount = $this->pendingTagsStore->count();
    $queueItemCount = $this->queueFactory->get(CloudflareCacheTagInvalidator::QUEUE_NAME)->numberOfItems();

    $warning = ($config->get('rate_limit_enabled') === TRUE)
      ? $this->slowDrainWarning($config, $pendingCount, $queueItemCount)
      : $this->highTagCountWarning($pendingCount, $queueItemCount);

    if ($warning === NULL) {
      return [];
    }

    if ($config->get('queue_max_age_enabled') !== TRUE) {
      $warning = $this->t('@warning Since queued items are not currently discarded by age, consider enabling "Discard queued items older than the configured TTL" below so the queue cannot grow without bound while purges catch up.', [
        '@warning' => $warning,
      ]);
    }

    return [$warning];
  }

  /**
   * Builds the "no rate limit, high tag count" warning, if it applies.
   *
   * @param int $pendingCount
   *   The number of distinct tags in the pending set.
   * @param int $queueItemCount
   *   The number of items already in the Cloudflare purge queue.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The warning, or NULL if the estimated tag count isn't high enough.
   */
  private function highTagCountWarning(int $pendingCount, int $queueItemCount): ?TranslatableMarkup {
    // Each queue item can carry up to MAX_BATCH_SIZE tags; used as an upper
    // estimate since the exact count would require claiming every item.
    $estimatedTagCount = $pendingCount + ($queueItemCount * CloudflarePurgeApi::MAX_BATCH_SIZE);

    if ($estimatedTagCount <= self::HIGH_TAG_COUNT_THRESHOLD) {
      return NULL;
    }

    return $this->t('No rate limit is set, but the number of cache tags awaiting a Cloudflare purge seems high (approximately @count). Check your logs to see if Cloudflare is returning any 429 responses, which may indicate the queue is growing faster than tags can be purged. If this is a one-off due to temporarily high content changes, consider a full cache clear instead.', [
      '@count' => $estimatedTagCount,
    ]);
  }

  /**
   * Builds the "rate limit too slow to keep up" warning, if it applies.
   *
   * The estimate includes the pending set as well as the queue: pending tags
   * are drained into roughly ceil(pending / MAX_BATCH_SIZE) further requests,
   * so counting only already-queued items would understate the backlog at the
   * very moment it is largest — right after a burst of invalidations, before
   * the next cron drain has run.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module's settings.
   * @param int $pendingCount
   *   The number of distinct tags in the pending set.
   * @param int $queueItemCount
   *   The number of items already in the Cloudflare purge queue.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The warning, or NULL if the estimated drain time isn't long enough.
   */
  private function slowDrainWarning(ImmutableConfig $config, int $pendingCount, int $queueItemCount): ?TranslatableMarkup {
    $ratePerMinute = (int) ($config->get('rate_limit_per_minute') ?? 0);

    // Pending tags become at least this many additional purge requests once
    // drained (one request per Cloudflare-sized batch).
    $pendingRequests = (int) ceil($pendingCount / CloudflarePurgeApi::MAX_BATCH_SIZE);
    $estimatedRequests = $queueItemCount + $pendingRequests;

    if ($ratePerMinute < 1 || $estimatedRequests === 0) {
      return NULL;
    }

    $minutesToClear = $estimatedRequests / $ratePerMinute;

    if ($minutesToClear <= self::SLOW_DRAIN_MINUTES_THRESHOLD) {
      return NULL;
    }

    return $this->t('Your configured rate limit (@rate requests/minute) would take approximately @minutes minutes to clear the @count purge request(s) currently awaiting a Cloudflare purge (queued items plus tags still pending a drain). This is a theoretical minimum; actual throughput is also bounded by how often cron runs. Check your logs to see if Cloudflare is returning any 429 responses, which may indicate the backlog is growing faster than it can be purged. If this is a one-off due to temporarily high content changes, consider a full cache clear instead.', [
      '@rate' => $ratePerMinute,
      '@minutes' => (int) ceil($minutesToClear),
      '@count' => $estimatedRequests,
    ]);
  }

}
