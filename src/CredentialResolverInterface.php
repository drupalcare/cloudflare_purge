<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Resolves Cloudflare credentials and auth/storage methods.
 *
 * Single source of truth for credential resolution, shared by the purge
 * service (runtime) and the configuration form (UI). Resolution priority for
 * the runtime methods is: settings.php override → Key module → plain config.
 *
 * @package Drupal\cloudflare_purge
 */
interface CredentialResolverInterface {

  /**
   * Gets the effective authentication method (settings.php aware).
   *
   * Priority: settings.php override → saved config preference → detection from
   * stored credentials → bearer.
   *
   * @return string
   *   CloudflarePurgeSettings::AUTH_BEARER or ::AUTH_LEGACY.
   */
  public function getAuthMethod(): string;

  /**
   * Gets a single credential value using three-tier resolution.
   *
   * Priority: settings.php override → Key module → plain config.
   *
   * @param string $name
   *   The credential name (e.g. "zone_id", "bearer_token").
   *
   * @return string|null
   *   The credential value, or NULL if unset.
   */
  public function getCredential(string $name): ?string;

  /**
   * Gets the full resolved credential set.
   *
   * @return array{use_bearer: bool, zone_id: string, bearer_token: string, email: string|null, authorization: string|null}
   *   The resolved credentials.
   */
  public function getCredentials(): array;

  /**
   * Checks whether a complete, usable credential set is configured.
   *
   * @return bool
   *   TRUE if credentials are configured, FALSE otherwise.
   */
  public function hasCredentials(): bool;

  /**
   * Checks whether settings.php provides a COMPLETE credential set.
   *
   * @return bool
   *   TRUE if a complete bearer or legacy set exists in settings.php.
   */
  public function hasSettingsOverride(): bool;

  /**
   * Checks whether settings.php provides an INCOMPLETE credential set.
   *
   * @return bool
   *   TRUE if settings.php has some but not a complete set of credentials.
   */
  public function hasIncompleteSettingsOverride(): bool;

  /**
   * Classifies the auth method present in the settings.php override.
   *
   * @return string|null
   *   CloudflarePurgeSettings::AUTH_BEARER, ::AUTH_LEGACY, or NULL if neither
   *   can be determined from settings.php.
   */
  public function getSettingsOverrideAuthType(): ?string;

  /**
   * Detects the auth method for UI defaults (config only, no settings.php).
   *
   * Priority: saved config preference → detection from stored credentials →
   * bearer.
   *
   * @return string
   *   CloudflarePurgeSettings::AUTH_BEARER or ::AUTH_LEGACY.
   */
  public function detectAuthMethod(): string;

  /**
   * Detects the storage method for UI defaults.
   *
   * Priority: saved config preference → detection from stored credentials →
   * Key module if installed, otherwise plain.
   *
   * @return string
   *   CloudflarePurgeSettings::STORAGE_PLAIN or ::STORAGE_KEY.
   */
  public function detectStorageMethod(): string;

}
