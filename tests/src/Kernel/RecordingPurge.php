<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Kernel;

use Drupal\cloudflare_purge\CloudflarePurgeResult;
use Drupal\cloudflare_purge\PurgeInterface;

/**
 * A purge service double that records whether the zone purge was reached.
 *
 * Used by CacheFlushPurgeWiringTest in place of cloudflare_purge.purge so a
 * cache flush can be observed end to end without contacting Cloudflare.
 */
final class RecordingPurge implements PurgeInterface {

  /**
   * Number of purgeEverything() calls since the counter was last reset.
   */
  public static int $purgeEverythingCalls = 0;

  /**
   * {@inheritdoc}
   */
  public function purgeEverything(bool $showMessage = TRUE): CloudflarePurgeResult {
    self::$purgeEverythingCalls++;
    return new CloudflarePurgeResult(TRUE, 200, 'ok');
  }

  /**
   * {@inheritdoc}
   */
  public function purgeByUrls(array $urls, bool $showMessage = TRUE): CloudflarePurgeResult {
    return new CloudflarePurgeResult(TRUE, 200, 'ok');
  }

  /**
   * {@inheritdoc}
   */
  public function purgeByTags(array $tags, bool $showMessage = TRUE): CloudflarePurgeResult {
    return new CloudflarePurgeResult(TRUE, 200, 'ok');
  }

  /**
   * {@inheritdoc}
   */
  public function purgeByPrefixes(array $prefixes, bool $showMessage = TRUE): CloudflarePurgeResult {
    return new CloudflarePurgeResult(TRUE, 200, 'ok');
  }

  /**
   * {@inheritdoc}
   */
  public function purgeByHostnames(array $hostnames, bool $showMessage = TRUE): CloudflarePurgeResult {
    return new CloudflarePurgeResult(TRUE, 200, 'ok');
  }

  /**
   * {@inheritdoc}
   */
  public function hasCredentials(): bool {
    return TRUE;
  }

}
