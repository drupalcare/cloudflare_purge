<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Deduplicated store of cache tags awaiting a Cloudflare purge.
 *
 * Cache tag invalidation can fire far more often than the underlying content
 * actually changes (the same tag reinvalidated by unrelated saves, several
 * entities sharing a listing tag, etc.). Rather than queueing a purge request
 * per invalidation, tags are merged into this deduplicated set; a drain step
 * (@see PendingTagDrainerInterface) later turns the set into properly sized
 * queue items. Backend-agnostic (a plain database table), unlike Drupal's
 * core Queue API, which offers no portable way to inspect or top up unclaimed
 * items.
 *
 * @package Drupal\cloudflare_purge
 */
interface PendingTagsStoreInterface {

  /**
   * Merges tags into the pending set.
   *
   * A tag already pending keeps its original insertion order and created time
   * (for FIFO ordering) but has its last_invalidated timestamp refreshed, so a
   * re-invalidated tag is never treated as stale by the queue TTL. It is never
   * duplicated.
   *
   * @param array<int, string> $tags
   *   The cache tags to merge in.
   *
   * @return int
   *   The number of tags that were newly added (not already pending).
   */
  public function add(array $tags): int;

  /**
   * Loads the oldest pending tags, for draining into queue items.
   *
   * @param int $limit
   *   The maximum number of rows to return.
   *
   * @return array<int, array{id: int, tag: string, created: int, last_invalidated: int}>
   *   Rows ordered oldest first (FIFO).
   */
  public function getOldest(int $limit): array;

  /**
   * Removes drained rows, skipping any re-invalidated since they were read.
   *
   * @param array<int, int> $ids
   *   The row IDs to remove, typically the IDs returned by getOldest().
   * @param int $maxLastInvalidated
   *   The queue item's timestamp (the freshest last_invalidated in the drained
   *   batch). Rows whose last_invalidated has since advanced beyond this value
   *   are kept — they were re-invalidated after the drain read them and must
   *   be drained again rather than lost.
   *
   * @return int
   *   The number of rows deleted.
   */
  public function deleteDrained(array $ids, int $maxLastInvalidated): int;

  /**
   * Counts the number of tags currently pending.
   *
   * @return int
   *   The pending tag count.
   */
  public function count(): int;

  /**
   * Gets the timestamp of the oldest pending tag.
   *
   * @return int|null
   *   The Unix timestamp the oldest pending tag was queued, or NULL if the
   *   pending set is empty.
   */
  public function getOldestTimestamp(): ?int;

}
