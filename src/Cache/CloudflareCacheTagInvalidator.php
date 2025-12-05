<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Cache;

use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\cloudflare_purge\Form\CloudflarePurgeForm;
use Drupal\cloudflare_purge\Purge;
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
   * @param \Drupal\cloudflare_purge\Purge $purgeService
   *   The Cloudflare purge service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   */
  public function __construct(
    private readonly Purge $purgeService,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    // Early return if no tags.
    if ($tags === []) {
      return;
    }

    $config = $this->configFactory->get(CloudflarePurgeForm::SETTINGS);

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

    // Filter tags to only include those we care about.
    $filteredTags = $this->filterTags($tags);

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
   * Filters cache tags to only include relevant entity tags.
   *
   * @param array<int|string, string> $tags
   *   The original cache tags.
   *
   * @return array<int, string>
   *   The filtered cache tags.
   */
  private function filterTags(array $tags): array {
    $config = $this->configFactory->get(CloudflarePurgeForm::SETTINGS);
    $enabledEntityTypes = $config->get('auto_purge_entity_types');

    if (!is_array($enabledEntityTypes) || $enabledEntityTypes === []) {
      return [];
    }

    $filtered = [];

    foreach ($tags as $tag) {
      if (!is_string($tag) || $tag === '') {
        continue;
      }

      // Check if tag matches an enabled entity type pattern.
      foreach ($enabledEntityTypes as $entityType) {
        if (!is_string($entityType)) {
          continue;
        }

        // Match patterns like "node:123", "node_list", etc.
        if (str_starts_with($tag, $entityType . ':') ||
            str_starts_with($tag, $entityType . '_list') ||
            $tag === $entityType) {
          $filtered[] = $tag;
          break;
        }
      }
    }

    return array_values(array_unique($filtered));
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

      // Batch tags into groups of max batch size.
      $batches = array_chunk($tags, CloudflarePurgeApi::MAX_BATCH_SIZE);

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
    // Batch tags into groups of max batch size.
    $batches = array_chunk($tags, CloudflarePurgeApi::MAX_BATCH_SIZE);

    foreach ($batches as $batch) {
      try {
        $this->purgeService->purgeByTags($batch, FALSE);
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to purge cache tags: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

}
