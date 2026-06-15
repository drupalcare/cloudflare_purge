<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Cache;

use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\cloudflare_purge\CloudflarePurgeSettings;
use Drupal\cloudflare_purge\ItemBatcher;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Cloudflare cache tag invalidator.
 *
 * This service integrates with Drupal's cache tag system to automatically
 * purge Cloudflare cache when cache tags are invalidated. It can operate
 * in immediate mode or queue purge requests for later processing.
 *
 * @package Drupal\cloudflare_purge\Cache
 */
final class CloudflareCacheTagInvalidator implements CacheTagsInvalidatorInterface {

  /**
   * Queue name for deferred purge operations.
   */
  public const QUEUE_NAME = 'cloudflare_purge_tags';

  /**
   * Constructs a CloudflareCacheTagInvalidator.
   *
   * @param \Drupal\cloudflare_purge\PurgeInterface $purgeService
   *   The Cloudflare purge service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\cloudflare_purge\Cache\CacheTagFormatterInterface $cacheTagFormatter
   *   The cache tag formatter.
   */
  public function __construct(
    private readonly PurgeInterface $purgeService,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
    private readonly QueueFactory $queueFactory,
    private readonly CacheTagFormatterInterface $cacheTagFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    // Early return if no tags.
    if ($tags === []) {
      return;
    }

    $config = $this->configFactory->get(CloudflarePurgeSettings::NAME);

    // Check if auto-purge is enabled.
    if ($config->get('auto_purge_enabled') !== TRUE) {
      return;
    }

    // Check if credentials are configured before attempting to purge.
    // This prevents unnecessary error messages when auto-purge is enabled
    // but credentials haven't been set up yet.
    if (!$this->purgeService->hasCredentials()) {
      return;
    }

    // Select the tags to purge via the shared formatter so the purge path uses
    // the same selection and wire transform as the response subscriber's
    // Cache-Tag header — including when header filtering is disabled. (The
    // header may additionally be byte-capped, so it can carry fewer tags than
    // are purged here; that only ever under-emits, never mismatches a token.)
    $filteredTags = $this->cacheTagFormatter->selectForPurge($tags);

    if ($filteredTags === []) {
      return;
    }

    // Decide whether to queue or process immediately.
    if ($config->get('auto_purge_use_queue') === TRUE) {
      $this->queueTags($filteredTags);
    }
    else {
      $this->purgeTags($filteredTags);
    }
  }

  /**
   * Queues tags for later processing.
   *
   * @param array<int, string> $tags
   *   The cache tags to queue.
   */
  private function queueTags(array $tags): void {
    try {
      $queue = $this->queueFactory->get(self::QUEUE_NAME);

      // Batch tags into groups of max batch size so each queue item is a
      // single Cloudflare request, kept small to drain within the rate limit.
      $batches = ItemBatcher::chunk($tags, CloudflarePurgeApi::MAX_BATCH_SIZE);

      foreach ($batches as $batch) {
        $queue->createItem([
          'tags' => $batch,
          'timestamp' => time(),
          'retries' => 0,
        ]);
      }

      $this->logger->debug('Queued @count cache tag(s) for Cloudflare purge.', [
        '@count' => count($tags),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to queue cache tags for Cloudflare purge: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Immediately purges tags from Cloudflare.
   *
   * @param array<int, string> $tags
   *   The cache tags to purge.
   */
  private function purgeTags(array $tags): void {
    // The Purge service splits the tags into Cloudflare-sized batches itself,
    // so hand it the full set rather than pre-chunking here.
    try {
      $this->purgeService->purgeByTags($tags, FALSE);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to purge cache tags: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
