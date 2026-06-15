<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\Core\Database\Connection;

/**
 * Default purge-history reader backed by the watchdog (dblog) table.
 *
 * @package Drupal\cloudflare_purge
 */
final class PurgeHistory implements PurgeHistoryInterface {

  /**
   * Constructs a PurgeHistory service.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->database->schema()->tableExists('watchdog');
  }

  /**
   * {@inheritdoc}
   */
  public function getRecentEntries(int $limit = 100): ?array {
    $query = $this->database->select('watchdog', 'w')
      ->fields('w', ['wid', 'uid', 'type', 'message', 'variables', 'severity', 'timestamp'])
      ->condition('w.type', 'cloudflare_purge')
      ->orderBy('w.timestamp', 'DESC')
      ->range(0, $limit)
      ->extend('Drupal\Core\Database\Query\TableSortExtender');

    $results = $query->execute();
    if ($results === NULL) {
      return NULL;
    }

    return $results->fetchAll();
  }

  /**
   * {@inheritdoc}
   */
  public function clear(): int {
    // Nothing to delete if Database Logging is not installed.
    if (!$this->isAvailable()) {
      return 0;
    }

    return (int) $this->database->delete('watchdog')
      ->condition('type', 'cloudflare_purge')
      ->execute();
  }

}
