<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Drains the pending-tags store into batched Cloudflare purge queue items.
 *
 * @package Drupal\cloudflare_purge
 */
interface PendingTagDrainerInterface {

  /**
   * Moves pending tags into queue items, oldest first.
   *
   * Reads all pending tags, splits them into batches no larger than
   * CloudflarePurgeApi::MAX_BATCH_SIZE, creates one queue item per batch, and
   * removes the drained tags from the pending set.
   * Because the pending set already deduplicates, this is where the "combine
   * already-queued tags within Cloudflare's thresholds" behavior lives: many
   * small, overlapping invalidations collapse into the minimum number of
   * queue items (and therefore Cloudflare API calls) needed to cover the
   * distinct tags actually pending.
   *
   * @return int
   *   The number of tags drained into queue items.
   */
  public function drain(): int;

}
