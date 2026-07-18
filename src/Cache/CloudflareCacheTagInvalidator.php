<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Cache;

use Drupal\cloudflare_purge\CloudflarePurgeSettings;
use Drupal\cloudflare_purge\PendingTagsStoreInterface;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\CacheTagsPurgeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Installer\InstallerKernel;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Cloudflare cache tag invalidator.
 *
 * This service integrates with Drupal's cache tag system to automatically
 * purge Cloudflare cache when cache tags are invalidated. It can operate
 * in immediate mode or queue purge requests for later processing.
 *
 * @package Drupal\cloudflare_purge\Cache
 */
final class CloudflareCacheTagInvalidator implements CacheTagsInvalidatorInterface, CacheTagsPurgeInterface {

  /**
   * Queue name for deferred purge operations.
   */
  public const QUEUE_NAME = 'cloudflare_purge_tags';

  /**
   * State key holding the Unix timestamp of the last full zone purge.
   *
   * Set when a purge-everything succeeds; read by the queue worker to skip
   * queued tag purges whose invalidations predate it (already covered by the
   * full purge), instead of deleting the queue outright and risking a race
   * with tags drained into it mid-purge.
   */
  public const LAST_FULL_PURGE_STATE_KEY = 'cloudflare_purge.last_purge_everything';

  /**
   * Constructs a CloudflareCacheTagInvalidator.
   *
   * @param \Drupal\cloudflare_purge\PurgeInterface $purgeService
   *   The Cloudflare purge service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\cloudflare_purge\PendingTagsStoreInterface $pendingTagsStore
   *   The pending-tags store.
   * @param \Drupal\cloudflare_purge\Cache\CacheTagFormatterInterface $cacheTagFormatter
   *   The cache tag formatter.
   */
  public function __construct(
    private readonly PurgeInterface $purgeService,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
    private readonly PendingTagsStoreInterface $pendingTagsStore,
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
   * {@inheritdoc}
   *
   * Core calls this from the cache_tags.invalidator service during
   * drupal_flush_all_caches() (e.g. `drush cr`, the "Clear all caches" button,
   * or rebuild_cache_access), immediately before the cache bins are cleared. A
   * full flush invalidates every Drupal cache tag, so the only faithful
   * Cloudflare action is to purge the entire zone. Because a full flush happens
   * often (every rebuild, deploy, and module install), this is opt-in and off
   * by default so routine `drush cr` never purges the CDN unexpectedly.
   */
  public function purge(): void {
    $config = $this->configFactory->get(CloudflarePurgeSettings::NAME);

    // Opt-in only.
    if ($config->get('purge_everything_on_flush') !== TRUE) {
      return;
    }

    // A full cache flush is part of site installation, when no zone or
    // credentials exist yet; never purge in that state.
    if (InstallerKernel::installationAttempted()) {
      return;
    }

    if (!$this->purgeService->hasCredentials()) {
      return;
    }

    // This runs inside the critical cache-rebuild path, so a Cloudflare failure
    // must never interrupt the flush: swallow and log everything.
    try {
      $this->purgeService->purgeEverything(FALSE);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to purge Cloudflare cache on full cache flush: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Merges tags into the pending set for later, batched processing.
   *
   * Tags are not queued directly here: repeated invalidations of the same
   * tag (a resave, several entities sharing a listing tag, etc.) would each
   * create their own queue item. Instead tags are merged into a deduplicated
   * pending set; hook_cron() drains it into properly batched queue items,
   * oldest tags first, via PendingTagDrainerInterface.
   *
   * @param array<int, string> $tags
   *   The cache tags to merge in.
   */
  private function queueTags(array $tags): void {
    try {
      $this->pendingTagsStore->add($tags);
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
