<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Cache;

/**
 * Transforms Drupal cache tags into the tokens used with Cloudflare.
 *
 * This is the single source of truth for the cache-tag "wire format" so the
 * value emitted in the Cache-Tag response header is, by construction, identical
 * to the value sent to Cloudflare's tag-purge API. Cloudflare only invalidates
 * an edge object when a purged tag matches a tag the object was tagged with, so
 * both sides MUST format tags the same way.
 *
 * @package Drupal\cloudflare_purge\Cache
 */
interface CacheTagFormatterInterface {

  /**
   * Filters cache tags down to those eligible for automatic purging.
   *
   * Only tags matching a configured entity type (e.g. "node:1", "node_list")
   * are kept; framework tags such as "config:*", "http_response" or "rendered"
   * are dropped because the auto-purge system never acts on them.
   *
   * @param array<int|string, mixed> $tags
   *   The raw cache tags.
   *
   * @return array<int, string>
   *   The purgeable cache tags.
   */
  public function filterPurgeable(array $tags): array;

  /**
   * Selects the tags to act on, honoring the header-filtering setting.
   *
   * This is the shared decision used by BOTH the Cache-Tag header and the
   * purge path, so the two stay symmetric. When `auto_purge_filter_header_tags`
   * is enabled (the default) it returns only purgeable tags; when disabled it
   * returns every (non-empty, de-duplicated) tag, so a tag emitted in the
   * header is always one the purge path will also act on.
   *
   * @param array<int|string, mixed> $tags
   *   The raw cache tags.
   *
   * @return array<int, string>
   *   The tags to emit and purge.
   */
  public function selectForPurge(array $tags): array;

  /**
   * Applies the wire transform (prefix and optional hashing) to each tag.
   *
   * The transform is deterministic: the same input tag always produces the
   * same token, whether called from the response subscriber or from a purge
   * call. Empty/non-string values are dropped and the result is de-duplicated.
   *
   * @param array<int|string, mixed> $tags
   *   The cache tags to format.
   *
   * @return array<int, string>
   *   The formatted tokens ready to send to Cloudflare.
   */
  public function formatForWire(array $tags): array;

  /**
   * Builds the Cache-Tag header value from a page's cache tags.
   *
   * Optionally filters to purgeable tags, applies the wire transform and caps
   * the result at the configured byte limit so the header cannot exceed
   * web-server/CDN limits and trigger a 5xx. When the cap is hit, excess tags
   * are dropped and a warning is logged.
   *
   * @param array<int|string, mixed> $tags
   *   The page's cache tags.
   *
   * @return string|null
   *   The comma-separated header value, or NULL when there is nothing to emit.
   */
  public function buildHeaderValue(array $tags): ?string;

}
