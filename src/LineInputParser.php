<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Parses multi-line textarea input into a clean list of values.
 *
 * Shared by the manual purge forms (tags, URLs, prefixes, hostnames) so the
 * line-splitting, trimming and de-duplication behavior lives in one place.
 *
 * @package Drupal\cloudflare_purge
 */
final class LineInputParser {

  /**
   * Splits textarea input into trimmed, de-duplicated, non-empty lines.
   *
   * @param string $input
   *   The raw textarea value.
   * @param bool $lowercase
   *   Whether to lowercase each line (used for hostnames).
   *
   * @return array<int, string>
   *   The parsed values, re-indexed.
   */
  public static function parse(string $input, bool $lowercase = FALSE): array {
    $lines = preg_split('/\r\n|\r|\n/', $input);

    if ($lines === FALSE) {
      return [];
    }

    $values = [];
    foreach ($lines as $line) {
      $value = trim($line);
      if ($lowercase) {
        $value = strtolower($value);
      }
      if ($value !== '') {
        $values[] = $value;
      }
    }

    return array_values(array_unique($values));
  }

}
