<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Interface for the Cloudflare purge service.
 *
 * This interface defines the contract for purging Cloudflare cache,
 * allowing for dependency injection and testing via mocks.
 *
 * @package Drupal\cloudflare_purge
 */
interface PurgeInterface {

  /**
   * Purges everything from the Cloudflare cache.
   *
   * @param bool $showMessage
   *   Whether to show a status message.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeEverything(bool $showMessage = TRUE): CloudflarePurgeResult;

  /**
   * Purges specific URLs from the Cloudflare cache.
   *
   * @param array<int|string, string> $urls
   *   Array of URLs to purge.
   * @param bool $showMessage
   *   Whether to show a status message.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByUrls(array $urls, bool $showMessage = TRUE): CloudflarePurgeResult;

  /**
   * Purges content by cache tags.
   *
   * @param array<int|string, string> $tags
   *   Array of cache tags to purge.
   * @param bool $showMessage
   *   Whether to show a status message.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByTags(array $tags, bool $showMessage = TRUE): CloudflarePurgeResult;

  /**
   * Purges content by URL prefixes.
   *
   * @param array<int|string, string> $prefixes
   *   Array of URL prefixes to purge.
   * @param bool $showMessage
   *   Whether to show a status message.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByPrefixes(array $prefixes, bool $showMessage = TRUE): CloudflarePurgeResult;

  /**
   * Purges content by hostnames.
   *
   * @param array<int|string, string> $hostnames
   *   Array of hostnames to purge.
   * @param bool $showMessage
   *   Whether to show a status message.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByHostnames(array $hostnames, bool $showMessage = TRUE): CloudflarePurgeResult;

  /**
   * Checks if credentials are configured and valid.
   *
   * @return bool
   *   TRUE if credentials are configured, FALSE otherwise.
   */
  public function hasCredentials(): bool;

}
