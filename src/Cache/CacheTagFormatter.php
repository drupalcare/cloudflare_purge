<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Cache;

use Drupal\cloudflare_purge\CloudflarePurgeSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Default cache-tag formatter.
 *
 * Owns the filter, prefix, optional hashing and size-guard logic shared by the
 * response subscriber and the purge path. Centralizing it here guarantees the
 * Cache-Tag header and the tag-purge call always use the same token for a
 * given Drupal cache tag.
 *
 * @package Drupal\cloudflare_purge\Cache
 */
final class CacheTagFormatter implements CacheTagFormatterInterface {

  /**
   * Default header byte cap when none is configured.
   *
   * ~7 KB, comfortably below the ~8 KB header limit commonly enforced by web
   * servers and proxies (Apache, Nginx, Varnish). Cloudflare itself allows the
   * aggregate Cache-Tag header to reach 16 KB, but the lower default keeps the
   * header safe across intermediaries that cap headers more aggressively.
   */
  private const DEFAULT_MAX_BYTES = 7168;

  /**
   * Default hashed-tag length (hex characters) when none is configured.
   */
  private const DEFAULT_HASH_LENGTH = 16;

  /**
   * Constructs a CacheTagFormatter.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function filterPurgeable(array $tags): array {
    $config = $this->configFactory->get(CloudflarePurgeSettings::NAME);
    $enabledEntityTypes = $config->get('auto_purge_entity_types');

    if (!is_array($enabledEntityTypes) || $enabledEntityTypes === []) {
      return [];
    }

    $filtered = [];

    foreach ($tags as $tag) {
      if (!is_string($tag) || $tag === '') {
        continue;
      }

      foreach ($enabledEntityTypes as $entityType) {
        if (!is_string($entityType) || $entityType === '') {
          continue;
        }

        // Match patterns like "node:123", "node_list", or exactly "node".
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
   * {@inheritdoc}
   */
  public function selectForPurge(array $tags): array {
    $tags = array_values(array_filter(
      $tags,
      static fn($tag): bool => is_string($tag) && $tag !== '',
    ));

    // When filtering is enabled (default), keep only purgeable tags. When the
    // operator opts to emit every tag in the header, the purge path must act on
    // the same full set, otherwise tags advertised on edge objects would never
    // be purged.
    if ($this->shouldFilterTags()) {
      return $this->filterPurgeable($tags);
    }

    return array_values(array_unique($tags));
  }

  /**
   * {@inheritdoc}
   */
  public function formatForWire(array $tags): array {
    $prefix = $this->getPrefix();
    $hash = $this->hashEnabled();
    $length = $this->hashLength();

    $formatted = [];

    foreach ($tags as $tag) {
      if (!is_string($tag) || $tag === '') {
        continue;
      }

      $value = $prefix . $tag;

      if ($hash) {
        $value = substr(hash('sha256', $value), 0, $length);
      }

      $formatted[] = $value;
    }

    return array_values(array_unique($formatted));
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeaderValue(array $tags): ?string {
    // Use the same tag selection as the purge path so the header and the
    // tag-purge call always operate on an identical set.
    $tags = $this->selectForPurge($tags);

    if ($tags === []) {
      return NULL;
    }

    $wireTags = $this->formatForWire($tags);

    if ($wireTags === []) {
      return NULL;
    }

    return $this->joinWithinLimit($wireTags);
  }

  /**
   * Joins tags with commas without exceeding the configured byte limit.
   *
   * @param array<int, string> $tags
   *   The formatted tokens.
   *
   * @return string|null
   *   The header value, or NULL if nothing fits.
   */
  private function joinWithinLimit(array $tags): ?string {
    $maxBytes = $this->maxBytes();

    $kept = [];
    $bytes = 0;
    $dropped = 0;

    foreach ($tags as $tag) {
      // Account for the joining comma for every tag after the first.
      $addition = ($kept === [] ? 0 : 1) + strlen($tag);

      if ($bytes + $addition > $maxBytes) {
        $dropped++;
        continue;
      }

      $kept[] = $tag;
      $bytes += $addition;
    }

    if ($dropped > 0) {
      $this->logger->warning('Cloudflare Cache-Tag header truncated: emitted @kept of @total tags (@bytes bytes, limit @max). Enable cache tag hashing or raise the limit to avoid dropping tags.', [
        '@kept' => count($kept),
        '@total' => count($tags),
        '@bytes' => $bytes,
        '@max' => $maxBytes,
      ]);
    }

    return $kept === [] ? NULL : implode(',', $kept);
  }

  /**
   * Gets the configured cache tag prefix.
   */
  private function getPrefix(): string {
    $prefix = $this->configFactory->get(CloudflarePurgeSettings::NAME)->get('tag_prefix');
    return is_string($prefix) ? $prefix : '';
  }

  /**
   * Determines whether tags should be limited to purgeable ones.
   *
   * Governs both the Cache-Tag header and the purge path so they stay
   * symmetric.
   */
  private function shouldFilterTags(): bool {
    // Default TRUE: only act on tags the auto-purge system can purge.
    return $this->configFactory->get(CloudflarePurgeSettings::NAME)->get('auto_purge_filter_header_tags') !== FALSE;
  }

  /**
   * Determines whether tags should be hashed.
   */
  private function hashEnabled(): bool {
    return $this->configFactory->get(CloudflarePurgeSettings::NAME)->get('auto_purge_hash_tags') === TRUE;
  }

  /**
   * Gets the configured hashed-tag length, clamped to a sane range.
   */
  private function hashLength(): int {
    $length = $this->configFactory->get(CloudflarePurgeSettings::NAME)->get('auto_purge_hash_length');

    if (!is_int($length) || $length < 6 || $length > 64) {
      return self::DEFAULT_HASH_LENGTH;
    }

    return $length;
  }

  /**
   * Gets the configured header byte cap, clamped to a sane range.
   */
  private function maxBytes(): int {
    $max = $this->configFactory->get(CloudflarePurgeSettings::NAME)->get('auto_purge_header_max_bytes');

    if (!is_int($max) || $max < 1024 || $max > 16384) {
      return self::DEFAULT_MAX_BYTES;
    }

    return $max;
  }

}
