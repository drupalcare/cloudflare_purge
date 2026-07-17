<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Database-backed pending-tags store.
 *
 * @internal
 *
 * @package Drupal\cloudflare_purge
 */
class PendingTagsStore implements PendingTagsStoreInterface {

  /**
   * The name of the pending-tags table.
   */
  private const TABLE = 'cloudflare_purge_pending_tags';

  /**
   * Constructs a PendingTagsStore.
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
  public function add(array $tags): int {
    $tags = ItemBatcher::sanitize($tags);
    if ($tags === []) {
      return 0;
    }

    $hashes = array_map(self::hash(...), $tags);
    $existing = $this->database->select(self::TABLE, 'p')
      ->fields('p', ['tag_hash'])
      ->condition('p.tag_hash', $hashes, 'IN')
      ->execute()
      ->fetchCol();
    $existing = array_flip($existing);

    $now = time();
    $insert = $this->database->insert(self::TABLE)
      ->fields(['tag_hash', 'tag', 'created']);
    $newCount = 0;

    foreach ($tags as $index => $tag) {
      $hash = $hashes[$index];
      if (isset($existing[$hash])) {
        continue;
      }
      $insert->values(['tag_hash' => $hash, 'tag' => $tag, 'created' => $now]);
      $newCount++;
    }

    if ($newCount === 0) {
      return 0;
    }

    try {
      $insert->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // A concurrent request inserted one of these hashes between the SELECT
      // above and this INSERT; retry row by row so the rest of the batch
      // still lands, skipping whichever rows now collide.
      $newCount = $this->addRowByRow($tags, $hashes, $existing, $now);
    }

    return $newCount;
  }

  /**
   * Inserts tags one at a time, silently skipping any that already exist.
   *
   * Fallback path for the rare race where a concurrent request inserts a tag
   * between this store's existence check and its bulk insert.
   *
   * @param array<int, string> $tags
   *   The sanitized tags being added.
   * @param array<int, string> $hashes
   *   The tag hashes, same order/keys as $tags.
   * @param array<string, int> $existing
   *   Hashes already known to be pending, keyed by hash.
   * @param int $now
   *   The timestamp to record for newly inserted rows.
   *
   * @return int
   *   The number of rows actually inserted.
   */
  private function addRowByRow(array $tags, array $hashes, array $existing, int $now): int {
    $inserted = 0;

    foreach ($tags as $index => $tag) {
      $hash = $hashes[$index];
      if (isset($existing[$hash])) {
        continue;
      }

      try {
        $this->database->insert(self::TABLE)
          ->fields(['tag_hash' => $hash, 'tag' => $tag, 'created' => $now])
          ->execute();
        $inserted++;
      }
      catch (IntegrityConstraintViolationException) {
        // Lost the race for this specific tag; it is already pending.
      }
    }

    return $inserted;
  }

  /**
   * {@inheritdoc}
   */
  public function getOldest(int $limit): array {
    $results = $this->database->select(self::TABLE, 'p')
      ->fields('p', ['id', 'tag', 'created'])
      ->orderBy('p.id', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();

    return array_map(
      static fn($row): array => [
        'id' => (int) $row->id,
        'tag' => (string) $row->tag,
        'created' => (int) $row->created,
      ],
      $results,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByIds(array $ids): int {
    if ($ids === []) {
      return 0;
    }

    return (int) $this->database->delete(self::TABLE)
      ->condition('id', $ids, 'IN')
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    return (int) $this->database->select(self::TABLE, 'p')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function getOldestTimestamp(): ?int {
    $timestamp = $this->database->select(self::TABLE, 'p')
      ->fields('p', ['created'])
      ->orderBy('p.id', 'ASC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $timestamp === FALSE ? NULL : (int) $timestamp;
  }

  /**
   * Hashes a tag for use as the table's dedup key.
   *
   * @param string $tag
   *   The tag text.
   *
   * @return string
   *   The SHA-256 hex digest.
   */
  private static function hash(string $tag): string {
    return hash('sha256', $tag);
  }

}
