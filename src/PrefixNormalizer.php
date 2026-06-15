<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Normalizes and validates URL prefixes for Cloudflare's prefix-purge API.
 *
 * Cloudflare's "purge by prefix" endpoint expects each prefix as "host/path"
 * with NO URI scheme. Including a scheme makes the API reject the request:
 *
 * @code
 *   URI scheme provided in prefix https://example.com/files/.
 *   URI schemes must not be provided. (code: 1119)
 * @endcode
 *
 * This helper is the single source of truth for prefix handling so the UI
 * form, the Drush command and the purge service all normalize and validate
 * identically.
 *
 * @package Drupal\cloudflare_purge
 */
final class PrefixNormalizer {

  /**
   * Strips any URI scheme (or protocol-relative prefix) from a URL prefix.
   *
   * The transformation is idempotent: a value that already has no scheme is
   * returned unchanged apart from surrounding whitespace. Path, query and
   * fragment are preserved as-is — only the scheme is removed, which is the
   * sole cause of Cloudflare error 1119.
   *
   * @param string $prefix
   *   The raw prefix, with or without a scheme.
   *
   * @return string
   *   The prefix without a scheme, e.g. "example.com/files/".
   */
  public static function normalize(string $prefix): string {
    $prefix = trim($prefix);

    // Strip a leading "scheme://" (RFC 3986 scheme characters).
    $prefix = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $prefix) ?? $prefix;

    // Strip a protocol-relative "//host/path" prefix.
    if (str_starts_with($prefix, '//')) {
      $prefix = substr($prefix, 2);
    }

    return $prefix;
  }

  /**
   * Determines whether a prefix is valid for Cloudflare prefix purging.
   *
   * A valid prefix contains a host component once its scheme is removed.
   * A path-only value such as "/files/" is rejected because Cloudflare
   * requires the host in the prefix.
   *
   * @param string $prefix
   *   The raw prefix, with or without a scheme.
   *
   * @return bool
   *   TRUE if the prefix has a host component, FALSE otherwise.
   */
  public static function isValid(string $prefix): bool {
    $normalized = self::normalize($prefix);

    if ($normalized === '') {
      return FALSE;
    }

    // A leading slash means the value is path-only with no host.
    if (str_starts_with($normalized, '/')) {
      return FALSE;
    }

    // Re-add a scheme so the native parser can extract the host.
    $host = parse_url('https://' . $normalized, PHP_URL_HOST);

    return is_string($host) && $host !== '';
  }

}
