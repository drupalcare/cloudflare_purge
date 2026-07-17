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
   * Deleting by ID removes only the specified rows.
   */
  public function testDeleteByIdsRemovesOnlySpecifiedRows(): void {
    $this->store()->add(['node:1', 'node:2', 'node:3']);
    $rows = $this->store()->getOldest(10);
    $idToDelete = $rows[0]['id'];

    $deleted = $this->store()->deleteByIds([$idToDelete]);

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

}
