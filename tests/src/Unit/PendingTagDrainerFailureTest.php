<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\cloudflare_purge\PendingTagDrainer;
use Drupal\cloudflare_purge\PendingTagsStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the drainer's behavior when the queue backend rejects an item.
 *
 * @group cloudflare_purge
 */
#[CoversClass(PendingTagDrainer::class)]
#[Group('cloudflare_purge')]
final class PendingTagDrainerFailureTest extends UnitTestCase {

  /**
   * A failed createItem() must not delete the pending rows.
   *
   * QueueInterface::createItem() may return FALSE without throwing. If the
   * drainer deleted the pending rows regardless, those tags would be lost:
   * gone from the pending set but never queued, so never purged. The rows must
   * survive for the next drain, and the failure must be logged.
   */
  public function testCreateItemFailureLeavesRowsPending(): void {
    $store = $this->createMock(PendingTagsStoreInterface::class);
    $store->method('getOldest')->willReturn([
      ['id' => 1, 'tag' => 'node:1', 'created' => 100, 'last_invalidated' => 100],
      ['id' => 2, 'tag' => 'node_list', 'created' => 100, 'last_invalidated' => 100],
    ]);
    // The critical assertion: rows are never deleted when the queue rejects
    // the item.
    $store->expects($this->never())->method('deleteDrained');

    $queue = $this->createStub(QueueInterface::class);
    $queue->method('createItem')->willReturn(FALSE);

    $queueFactory = $this->createStub(QueueFactory::class);
    $queueFactory->method('get')->willReturn($queue);

    $lock = $this->createStub(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->atLeastOnce())->method('error');

    $drainer = new PendingTagDrainer($store, $queueFactory, $lock, $logger);

    $this->assertSame(0, $drainer->drain());
  }

  /**
   * When another drain holds the lock, drain() is a no-op.
   */
  public function testDrainSkipsWhenLockUnavailable(): void {
    $store = $this->createMock(PendingTagsStoreInterface::class);
    $store->expects($this->never())->method('getOldest');
    $store->expects($this->never())->method('deleteDrained');

    $queueFactory = $this->createStub(QueueFactory::class);

    $lock = $this->createStub(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(FALSE);

    $logger = $this->createStub(LoggerChannelInterface::class);

    $drainer = new PendingTagDrainer($store, $queueFactory, $lock, $logger);

    $this->assertSame(0, $drainer->drain());
  }

}
