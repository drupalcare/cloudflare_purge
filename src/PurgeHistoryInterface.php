<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Provides access to recent Cloudflare purge log entries.
 *
 * Encapsulates the watchdog query previously embedded in the controller so the
 * data access has a single home and can be tested in isolation.
 *
 * @package Drupal\cloudflare_purge
 */
interface PurgeHistoryInterface {

  /**
   * Checks whether the purge-history backing store is available.
   *
   * @return bool
   *   TRUE if the watchdog table exists (Database Logging is enabled).
   */
  public function isAvailable(): bool;

  /**
   * Loads the most recent Cloudflare purge log entries.
   *
   * @param int $limit
   *   The maximum number of entries to return.
   *
   * @return array<int, object>|null
   *   A list of watchdog rows (wid, uid, type, message, variables, severity,
   *   timestamp), newest first, or NULL if the query yielded no result set.
   */
  public function getRecentEntries(int $limit = 100): ?array;

  /**
   * Deletes all Cloudflare purge entries from the backing store.
   *
   * Only the module's own log entries (watchdog type "cloudflare_purge") are
   * removed; other log entries are left untouched.
   *
   * @return int
   *   The number of entries deleted.
   */
  public function clear(): int;

}
