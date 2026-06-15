<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use Drupal\cloudflare_purge\ItemBatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the shared item sanitization and chunking helper.
 *
 * @group cloudflare_purge
 */
#[CoversClass(ItemBatcher::class)]
final class ItemBatcherTest extends TestCase {

  /**
   * Sanitize trims, drops empties/non-strings, de-dupes and re-indexes.
   */
  public function testSanitize(): void {
    $result = ItemBatcher::sanitize([
      '  node:1  ',
      'node:2',
      'node:1',
      '',
      '   ',
      // Non-string entries are skipped.
      42,
      NULL,
      'node:2',
      'node:3',
    ]);

    $this->assertSame(['node:1', 'node:2', 'node:3'], $result);
  }

  /**
   * Sanitize of an empty list yields an empty list.
   */
  public function testSanitizeEmpty(): void {
    $this->assertSame([], ItemBatcher::sanitize([]));
  }

  /**
   * Chunk splits into batches no larger than the requested size.
   */
  public function testChunkBoundaries(): void {
    $items = [];
    for ($i = 1; $i <= 65; $i++) {
      $items[] = "item{$i}";
    }

    $batches = ItemBatcher::chunk($items, 30);

    $this->assertCount(3, $batches);
    $this->assertSame([30, 30, 5], array_map('count', $batches));
  }

  /**
   * A list at or below the batch size yields a single batch.
   */
  public function testChunkSingleBatch(): void {
    $batches = ItemBatcher::chunk(['a', 'b', 'c'], 30);
    $this->assertCount(1, $batches);
    $this->assertSame(['a', 'b', 'c'], $batches[0]);
  }

  /**
   * Chunking an empty list yields no batches.
   */
  public function testChunkEmpty(): void {
    $this->assertSame([], ItemBatcher::chunk([], 30));
  }

  /**
   * A non-positive size is clamped to 1 so chunking can never loop forever.
   */
  public function testChunkClampsNonPositiveSize(): void {
    $batches = ItemBatcher::chunk(['a', 'b'], 0);
    $this->assertSame([['a'], ['b']], $batches);
  }

}
