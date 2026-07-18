<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;

/**
 * Database-backed pending-tags store.
 *
 * Concurrency note: add() (upsert) and the drainer's read-then-deleteDrained()
 * are individually safe and the common re-invalidation cases are covered — a
 * tag re-invalidated to a value newer than the drained batch's timestamp is
 * kept and re-drained. A narrow residual window remains only when a tag is
 * re-invalidated within the same one-second timestamp resolution as a
 * concurrent drain of that same tag: e.g. add()'s MERGE reads the row and the
 * drainer deletes it between the MERGE's internal SELECT and UPDATE (the
 * UPDATE then matches nothing while MERGE still reports an update), or a
 * re-invalidation lands after the drained item was already processed but
 * carries the same second, so deleteDrained() still removes it. Either way a
 * single re-invalidation can be neither re-queued nor kept, and it can only
 * cause a missed purge when the optional queue TTL discard is enabled and the
 * drained item is dropped as stale. Fully closing it would require a per-row
 * revision column and a hand-rolled upsert, which would reintroduce the
 * unique-violation exception this store deliberately avoids (see add()); that
 * trade is not worth a more likely PostgreSQL failure for this opt-in,
 * sub-second edge case.
 *
 * @internal
 *
 * @package Drupal\cloudflare_purge
 */
final class PendingTagsStore implements PendingTagsStoreInterface {

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

    $now = time();
    $added = 0;

    // MERGE inserts a new row or, if the tag is already pending, updates it —
    // keeping the original id/created for FIFO ordering and moving
    // last_invalidated forward. Using the Merge API rather than "SELECT then
    // INSERT and catch the unique-key violation" keeps the caller's code path
    // free of an integrity-constraint exception, which matters because tag
    // invalidation runs inside the entity-save transaction: on PostgreSQL such
    // an exception aborts the whole transaction and could roll back the
    // content save. The update uses a CASE expression (rather than a plain
    // assignment) so a delayed, out-of-order request can never move
    // last_invalidated backwards under concurrency.
    foreach ($tags as $tag) {
      $hash = self::hash($tag);
      // tag_hash is listed in insertFields() explicitly rather than relying on
      // key() to contribute it: insertFields() sets the insert field list
      // outright, so a key() added before it would be dropped from the INSERT
      // and violate the NOT NULL constraint. Listing it here is unambiguous
      // across Drupal versions.
      $status = $this->database->merge(self::TABLE)
        ->key('tag_hash', $hash)
        ->insertFields([
          'tag_hash' => $hash,
          'tag' => $tag,
          'created' => $now,
          'last_invalidated' => $now,
        ])
        ->expression(
          'last_invalidated',
          // Two distinct placeholders for the same value: reusing one named
          // placeholder can break on drivers that use real (non-emulated)
          // prepared statements, such as PostgreSQL.
          'CASE WHEN last_invalidated < :cfp_now_a THEN :cfp_now_b ELSE last_invalidated END',
          [':cfp_now_a' => $now, ':cfp_now_b' => $now],
        )
        ->execute();

      if ($status === Merge::STATUS_INSERT) {
        $added++;
      }
    }

    return $added;
  }

  /**
   * {@inheritdoc}
   */
  public function getOldest(int $limit): array {
    if ($limit < 1) {
      return [];
    }

    $results = $this->database->select(self::TABLE, 'p')
      ->fields('p', ['id', 'tag', 'created', 'last_invalidated'])
      ->orderBy('p.id', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();

    return array_map(
      static fn($row): array => [
        'id' => (int) $row->id,
        'tag' => (string) $row->tag,
        'created' => (int) $row->created,
        'last_invalidated' => (int) $row->last_invalidated,
      ],
      $results,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function deleteDrained(array $ids, int $maxLastInvalidated): int {
    if ($ids === []) {
      return 0;
    }

    // Delete only rows whose last_invalidated has not advanced past the value
    // that was drained into the queue item (its timestamp). A row
    // re-invalidated between the drain's read and this delete has a newer
    // last_invalidated and is intentionally kept, so its fresh invalidation is
    // re-drained on the next run rather than being lost.
    return (int) $this->database->delete(self::TABLE)
      ->condition('id', $ids, 'IN')
      ->condition('last_invalidated', $maxLastInvalidated, '<=')
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
