<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Low-level client contract for the Cloudflare purge API.
 *
 * @package Drupal\cloudflare_purge
 */
interface CloudflarePurgeApiInterface {

  /**
   * Purges everything from the Cloudflare cache.
   *
   * @param bool $useBearerToken
   *   Whether to use bearer token authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearerToken
   *   The bearer token.
   * @param string|null $authorization
   *   The API key.
   * @param string|null $email
   *   The account email.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeEverything(
    bool $useBearerToken,
    string $zoneId,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
  ): CloudflarePurgeResult;

  /**
   * Purges specific URLs.
   *
   * @param bool $useBearerToken
   *   Whether to use bearer token authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearerToken
   *   The bearer token.
   * @param string|null $authorization
   *   The API key.
   * @param string|null $email
   *   The account email.
   * @param array<int|string, mixed> $urls
   *   URLs to purge.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByUrls(
    bool $useBearerToken,
    string $zoneId,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
    array $urls,
  ): CloudflarePurgeResult;

  /**
   * Purges by cache tags.
   *
   * @param bool $useBearerToken
   *   Whether to use bearer token authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearerToken
   *   The bearer token.
   * @param string|null $authorization
   *   The API key.
   * @param string|null $email
   *   The account email.
   * @param array<int|string, mixed> $tags
   *   Cache tags to purge.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByTags(
    bool $useBearerToken,
    string $zoneId,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
    array $tags,
  ): CloudflarePurgeResult;

  /**
   * Purges by URL prefixes.
   *
   * @param bool $useBearerToken
   *   Whether to use bearer token authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearerToken
   *   The bearer token.
   * @param string|null $authorization
   *   The API key.
   * @param string|null $email
   *   The account email.
   * @param array<int|string, mixed> $prefixes
   *   URL prefixes to purge.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByPrefixes(
    bool $useBearerToken,
    string $zoneId,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
    array $prefixes,
  ): CloudflarePurgeResult;

  /**
   * Purges by hostnames.
   *
   * @param bool $useBearerToken
   *   Whether to use bearer token authentication.
   * @param string $zoneId
   *   The Cloudflare zone ID.
   * @param string $bearerToken
   *   The bearer token.
   * @param string|null $authorization
   *   The API key.
   * @param string|null $email
   *   The account email.
   * @param array<int|string, mixed> $hostnames
   *   Hostnames to purge.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeResult
   *   The purge result.
   */
  public function purgeByHostnames(
    bool $useBearerToken,
    string $zoneId,
    string $bearerToken,
    ?string $authorization,
    ?string $email,
    array $hostnames,
  ): CloudflarePurgeResult;

}
