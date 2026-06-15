<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Single source of truth for preparing purge items for Cloudflare requests.
 *
 * Cloudflare accepts at most CloudflarePurgeApi::MAX_BATCH_SIZE items per purge
 * request. This helper centralizes the two pure, deterministic steps every
 * purge path needs — sanitization (trim, drop empties/non-strings, de-dupe)
 * and splitting into request-sized batches — so the rule lives in exactly one
 * place instead of being re-implemented by each caller.
 *
 * @package Drupal\cloudflare_purge
 */
final class ItemBatcher {

  /**
   * Sanitizes a raw list of purge items.
   *
   * Trims each value, discards empty strings and non-string entries, removes
   * duplicates and re-indexes. Order of first occurrence is preserved.
   *
   * @param array<int|string, mixed> $items
   *   The raw items.
   *
   * @return array<int, string>
   *   The sanitized, duplicate-free, zero-indexed list.
   */
  public static function sanitize(array $items): array {
    $sanitized = [];

    foreach ($items as $item) {
      if (!is_string($item)) {
        continue;
      }

      $trimmed = trim($item);
      if ($trimmed !== '') {
        $sanitized[] = $trimmed;
      }
    }

    return array_values(array_unique($sanitized));
  }

  /**
   * Splits a list into batches no larger than the per-request limit.
   *
   * @param array<int, string> $items
   *   The items to split (typically already sanitized).
   * @param int $size
   *   The maximum batch size.
   *
   * @return array<int, array<int, string>>
   *   The list of batches. Empty input yields an empty array.
   */
  public static function chunk(array $items, int $size): array {
    return array_chunk($items, max(1, $size));
  }

}
