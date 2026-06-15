<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Central definition of the module's configuration name and value constants.
 *
 * This is the single source of truth for the settings object name and the
 * authentication/storage method values, so runtime services do not have to
 * depend on a Form class merely to read them.
 *
 * @package Drupal\cloudflare_purge
 */
final class CloudflarePurgeSettings {

  /**
   * The configuration object name.
   */
  public const NAME = 'cloudflare_purge.settings';

  /**
   * Authentication method: API bearer token.
   */
  public const AUTH_BEARER = 'bearer';

  /**
   * Authentication method: legacy API key + email.
   */
  public const AUTH_LEGACY = 'legacy';

  /**
   * Credential storage: plain text in configuration.
   */
  public const STORAGE_PLAIN = 'plain';

  /**
   * Credential storage: Key module.
   */
  public const STORAGE_KEY = 'key';

}
