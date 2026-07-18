<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\cloudflare_purge\PendingTagDrainerInterface;
use Drupal\cloudflare_purge\PendingTagsStoreInterface;
use Drupal\Core\Queue\QueueInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests draining the pending-tags store into batched queue items.
 *
 * @group cloudflare_purge
 */
#[CoversClass(\Drupal\cloudflare_purge\PendingTagDrainer::class)]
#[RunTestsInSeparateProcesses]
class PendingTagDrainerTest extends KernelTestBase {

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
   * Returns the pending-tags store.
   */
  private function store(): PendingTagsStoreInterface {
    return $this->container->get('cloudflare_purge.pending_tags_store');
  }

  /**
   * Returns the drainer under test.
   */
  private function drainer(): PendingTagDrainerInterface {
    return $this->container->get('cloudflare_purge.pending_tag_drainer');
  }

  /**
   * Claims the next queue item and returns its data array.
   *
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue to claim from.
   *
   * @return array<string, mixed>
   *   The claimed item's data.
   */
  private function claimItemData(QueueInterface $queue): array {
    $item = $queue->claimItem();
    $this->assertIsObject($item);
    return $item->data;
  }

  /**
   * Draining an empty pending set creates no queue items.
   */
  public function testDrainEmptyDoesNothing(): void {
    $drained = $this->drainer()->drain();

    $this->assertSame(0, $drained);
    $this->assertSame(0, $this->container->get('queue')->get(CloudflareCacheTagInvalidator::QUEUE_NAME)->numberOfItems());
  }

  /**
   * A small pending set becomes a single queue item and empties the store.
   */
  public function testDrainSmallSetCreatesOneItem(): void {
    $this->store()->add(['node:1', 'node:2', 'node_list']);

    $drained = $this->drainer()->drain();

    $this->assertSame(3, $drained);
    $this->assertSame(0, $this->store()->count());

    $queue = $this->container->get('queue')->get(CloudflareCacheTagInvalidator::QUEUE_NAME);
    $this->assertSame(1, $queue->numberOfItems());

    $data = $this->claimItemData($queue);
    $this->assertCount(3, $data['tags']);
    $this->assertSame(['node:1', 'node:2', 'node_list'], $data['tags']);
  }

  /**
   * A pending set larger than MAX_BATCH_SIZE splits into multiple items.
   *
   * This is the "combine within Cloudflare's thresholds" behavior: however
   * many separate invalidations contributed these tags, draining always
   * yields the minimum number of Cloudflare-sized queue items.
   */
  public function testDrainLargeSetSplitsIntoMultipleBatches(): void {
    $tags = [];
    for ($i = 1; $i <= CloudflarePurgeApi::MAX_BATCH_SIZE + 50; $i++) {
      $tags[] = "node:{$i}";
    }
    $this->store()->add($tags);

    $drained = $this->drainer()->drain();

    $this->assertSame(count($tags), $drained);
    $this->assertSame(0, $this->store()->count());

    $queue = $this->container->get('queue')->get(CloudflareCacheTagInvalidator::QUEUE_NAME);
    $this->assertSame(2, $queue->numberOfItems());

    $first = $this->claimItemData($queue);
    $second = $this->claimItemData($queue);
    $this->assertCount(CloudflarePurgeApi::MAX_BATCH_SIZE, $first['tags']);
    $this->assertCount(50, $second['tags']);
  }

  /**
   * Overlapping invalidations of the same tags collapse into one purge.
   *
   * Simulates the reported scenario: a client resaving the same item (or two
   * items sharing a listing tag) repeatedly, between cron runs. However many
   * times invalidateTags() effectively ran, drain() must only ever emit the
   * distinct tags once.
   */
  public function testOverlappingInvalidationsCollapseBeforeDraining(): void {
    $this->store()->add(['node:1', 'node_list']);
    $this->store()->add(['node:2', 'node_list']);
    $this->store()->add(['node:1', 'node_list']);

    $drained = $this->drainer()->drain();

    // Only 3 distinct tags exist despite 3 overlapping invalidation events
    // covering 6 (tag) invocations total.
    $this->assertSame(3, $drained);

    $queue = $this->container->get('queue')->get(CloudflareCacheTagInvalidator::QUEUE_NAME);
    $this->assertSame(1, $queue->numberOfItems());
  }

  /**
   * A queued item's timestamp is the freshest invalidation in its batch.
   *
   * The queue TTL discard is all-or-nothing per item, so a batch must be aged
   * from its newest last_invalidated: a recently re-invalidated tag riding
   * alongside an older one must not drag the item's age back and get the whole
   * batch discarded. Worst case an already-stale tag is re-purged for free in
   * the same request.
   */
  public function testQueueItemTimestampUsesFreshestInvalidation(): void {
    // Two tags in the same batch with very different last_invalidated times.
    $this->insertPending('node:1', time() - 7200, time() - 7200);
    $this->insertPending('node:2', time() - 7200, time() - 5);

    $this->drainer()->drain();

    $queue = $this->container->get('queue')->get(CloudflareCacheTagInvalidator::QUEUE_NAME);
    $data = $this->claimItemData($queue);
    // The freshest last_invalidated (node:2, ~5s ago), not the oldest.
    $this->assertGreaterThan(time() - 60, $data['timestamp']);
  }

  /**
   * Inserts a pending row directly with explicit timestamps.
   *
   * The store's add() always stamps "now", so exercising drain-time age logic
   * requires writing controlled created/last_invalidated values.
   */
  private function insertPending(string $tag, int $created, int $lastInvalidated): void {
    $this->container->get('database')->insert('cloudflare_purge_pending_tags')
      ->fields([
        'tag_hash' => hash('sha256', $tag),
        'tag' => $tag,
        'created' => $created,
        'last_invalidated' => $lastInvalidated,
      ])
      ->execute();
  }

}
