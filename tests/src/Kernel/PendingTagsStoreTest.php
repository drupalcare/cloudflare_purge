<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\cloudflare_purge\PendingTagsStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the deduplicated pending-tags store.
 *
 * @group cloudflare_purge
 */
#[CoversClass(\Drupal\cloudflare_purge\PendingTagsStore::class)]
#[RunTestsInSeparateProcesses]
class PendingTagsStoreTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cloudflare_purge'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('cloudflare_purge', ['cloudflare_purge_pending_tags']);
  }

  /**
   * Returns the service under test.
   */
  private function store(): PendingTagsStoreInterface {
    return $this->container->get('cloudflare_purge.pending_tags_store');
  }

  /**
   * Adding new tags reports them as new and they become visible immediately.
   */
  public function testAddNewTags(): void {
    $added = $this->store()->add(['node:1', 'node_list']);

    $this->assertSame(2, $added);
    $this->assertSame(2, $this->store()->count());
  }

  /**
   * Re-adding an already-pending tag is a no-op: no duplicate, no new count.
   *
   * This is the core "combine repeated invalidations" behavior: a client
   * resaving the same node must not grow the pending set.
   */
  public function testAddDuplicateTagIsMergedNotDuplicated(): void {
    $this->store()->add(['node:1', 'node_list']);
    $added = $this->store()->add(['node:1', 'node_list', 'node:2']);

    $this->assertSame(1, $added);
    $this->assertSame(3, $this->store()->count());
  }

  /**
   * Empty, non-string, and whitespace-only input adds nothing.
   */
  public function testAddSanitizesInput(): void {
    $added = $this->store()->add(['', '  ', 'node:1', 'node:1']);

    $this->assertSame(1, $added);
    $this->assertSame(1, $this->store()->count());
  }

  /**
   * Reading the oldest tags returns rows in insertion (FIFO) order.
   */
  public function testGetOldestOrdersFifo(): void {
    $this->store()->add(['node:1']);
    $this->store()->add(['node:2']);
    $this->store()->add(['node:3']);

    $rows = $this->store()->getOldest(2);

    $this->assertCount(2, $rows);
    $this->assertSame('node:1', $rows[0]['tag']);
    $this->assertSame('node:2', $rows[1]['tag']);
  }

  /**
   * Draining removes only the specified rows.
   */
  public function testDeleteDrainedRemovesOnlySpecifiedRows(): void {
    $this->store()->add(['node:1', 'node:2', 'node:3']);
    $rows = $this->store()->getOldest(10);
    $idToDelete = $rows[0]['id'];

    // A generous watermark deletes the row regardless of its last_invalidated.
    $deleted = $this->store()->deleteDrained([$idToDelete], time() + 1);

    $this->assertSame(1, $deleted);
    $this->assertSame(2, $this->store()->count());
    $remainingTags = array_column($this->store()->getOldest(10), 'tag');
    $this->assertNotContains('node:1', $remainingTags);
  }

  /**
   * An empty pending set reports a NULL oldest timestamp.
   */
  public function testGetOldestTimestampEmptyIsNull(): void {
    $this->assertNull($this->store()->getOldestTimestamp());
  }

  /**
   * The oldest timestamp reflects the first tag added, not the latest.
   */
  public function testGetOldestTimestampReflectsFirstAdd(): void {
    $this->store()->add(['node:1']);
    $firstTimestamp = $this->store()->getOldestTimestamp();

    $this->store()->add(['node:2']);

    $this->assertSame($firstTimestamp, $this->store()->getOldestTimestamp());
  }

  /**
   * Each returned row carries a last_invalidated timestamp.
   *
   * On first insert it equals created; both are populated.
   */
  public function testGetOldestReturnsLastInvalidated(): void {
    $this->store()->add(['node:1']);

    $rows = $this->store()->getOldest(1);

    $this->assertArrayHasKey('last_invalidated', $rows[0]);
    $this->assertGreaterThan(0, $rows[0]['last_invalidated']);
    $this->assertSame($rows[0]['created'], $rows[0]['last_invalidated']);
  }

  /**
   * Re-adding a pending tag refreshes last_invalidated but keeps created.
   *
   * Verified by rewriting the row to an old time, then re-adding: created (the
   * FIFO / "waiting since" clock) is preserved, while last_invalidated (the
   * TTL clock) jumps forward to now.
   */
  public function testReAddRefreshesLastInvalidatedButKeepsCreated(): void {
    $this->store()->add(['node:1']);

    // Backdate both clocks to two hours ago.
    $this->container->get('database')->update('cloudflare_purge_pending_tags')
      ->fields(['created' => time() - 7200, 'last_invalidated' => time() - 7200])
      ->condition('tag', 'node:1')
      ->execute();

    // A fresh invalidation of the same tag.
    $added = $this->store()->add(['node:1']);
    $this->assertSame(0, $added);

    $rows = $this->store()->getOldest(1);
    // The created value stays old; last_invalidated is refreshed to ~now.
    $this->assertLessThan(time() - 3600, $rows[0]['created']);
    $this->assertGreaterThan(time() - 60, $rows[0]['last_invalidated']);
  }

  /**
   * Draining keeps a row re-invalidated after it was read.
   *
   * Models the drain race: a tag's last_invalidated advances past the drained
   * queue item's timestamp between the drain's read and its delete, so the row
   * must survive and be drained again rather than lost.
   */
  public function testDeleteDrainedKeepsReInvalidatedRows(): void {
    $this->store()->add(['node:1']);
    $rows = $this->store()->getOldest(1);
    $id = $rows[0]['id'];
    $drainedTimestamp = $rows[0]['last_invalidated'];

    // Simulate a re-invalidation after the drain read the row: last_invalidated
    // jumps past the timestamp the drain will delete against.
    $this->container->get('database')->update('cloudflare_purge_pending_tags')
      ->fields(['last_invalidated' => $drainedTimestamp + 100])
      ->condition('id', $id)
      ->execute();

    $deleted = $this->store()->deleteDrained([$id], $drainedTimestamp);

    $this->assertSame(0, $deleted);
    $this->assertSame(1, $this->store()->count());
  }

  /**
   * Re-adding never moves last_invalidated backwards.
   *
   * A delayed, out-of-order invalidation (an older timestamp arriving after a
   * newer one already landed) must not reduce last_invalidated, or the queue
   * TTL could wrongly treat a freshly-changed tag as stale.
   */
  public function testReAddNeverLowersLastInvalidated(): void {
    $this->store()->add(['node:1']);

    // Simulate a newer invalidation having already landed by pushing
    // last_invalidated into the future.
    $future = time() + 3600;
    $this->container->get('database')->update('cloudflare_purge_pending_tags')
      ->fields(['last_invalidated' => $future])
      ->condition('tag', 'node:1')
      ->execute();

    // A re-add stamped with "now" must not pull last_invalidated back.
    $this->store()->add(['node:1']);

    $rows = $this->store()->getOldest(1);
    $this->assertSame($future, $rows[0]['last_invalidated']);
  }

}
