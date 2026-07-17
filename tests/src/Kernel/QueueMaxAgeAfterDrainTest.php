<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\cloudflare_purge\CloudflarePurgeResult;
use Drupal\cloudflare_purge\Plugin\QueueWorker\CloudflarePurgeQueueWorker;
use Drupal\cloudflare_purge\PendingTagDrainerInterface;
use Drupal\cloudflare_purge\PendingTagsStoreInterface;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Confirms the queue TTL discard still works after the pending-tags refactor.
 *
 * PendingTagDrainer sets a drained queue item's 'timestamp' to the tag's
 * ORIGINAL pending time (not the moment it was drained), so the age
 * CloudflarePurgeQueueWorker sees must reflect how long the invalidation has
 * genuinely been waiting, not how long it has been an actual queue item. This
 * ties drain() and processItem() together in one test rather than trusting
 * their separate unit/kernel test suites compose correctly.
 *
 * @group cloudflare_purge
 */
#[CoversClass(\Drupal\cloudflare_purge\PendingTagDrainer::class)]
#[CoversClass(CloudflarePurgeQueueWorker::class)]
#[RunTestsInSeparateProcesses]
final class QueueMaxAgeAfterDrainTest extends KernelTestBase {

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
    $this->installConfig(['cloudflare_purge']);
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
   * Backdates a pending tag's 'created' column to simulate a long wait.
   *
   * The store's add() method always stamps the current time, so an old
   * pending tag can only be simulated by rewriting the row directly.
   */
  private function backdateTag(string $tag, int $timestamp): void {
    $this->container->get('database')->update('cloudflare_purge_pending_tags')
      ->fields(['created' => $timestamp])
      ->condition('tag', $tag)
      ->execute();
  }

  /**
   * Builds a real queue worker with the given TTL config and purge mock.
   */
  private function buildWorker(bool $ttlEnabled, int $maxAge, PurgeInterface $purgeService): CloudflarePurgeQueueWorker {
    $config = $this->config('cloudflare_purge.settings');
    $config->set('queue_max_age_enabled', $ttlEnabled);
    $config->set('queue_max_age', $maxAge);
    $config->save();

    return new CloudflarePurgeQueueWorker(
      [],
      'cloudflare_purge_tags',
      [],
      $purgeService,
      $this->createMock(LoggerChannelInterface::class),
      $this->container->get('queue'),
      $this->container->get('config.factory'),
    );
  }

  /**
   * A tag that was already stale when it was first invalidated is discarded.
   *
   * The pending tag is backdated to look 2 hours old before draining; the
   * drained queue item must carry that original age through to the worker,
   * which discards it under a 15-minute TTL without ever calling the API.
   */
  public function testStalePendingTagIsDiscardedAfterDrain(): void {
    $this->store()->add(['node:1']);
    $this->backdateTag('node:1', time() - 7200);

    $this->drainer()->drain();

    $queue = $this->container->get('queue')->get(CloudflareCacheTagInvalidator::QUEUE_NAME);
    $item = $queue->claimItem();
    $this->assertIsObject($item);

    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->never())->method('purgeByTags');

    $worker = $this->buildWorker(TRUE, 900, $purgeService);
    $worker->processItem($item->data);
  }

  /**
   * A freshly pending tag still gets purged normally after draining.
   *
   * Positive control: proves the TTL check isn't just discarding everything
   * regardless of age.
   */
  public function testFreshPendingTagIsPurgedAfterDrain(): void {
    $this->store()->add(['node:2']);

    $this->drainer()->drain();

    $queue = $this->container->get('queue')->get(CloudflareCacheTagInvalidator::QUEUE_NAME);
    $item = $queue->claimItem();
    $this->assertIsObject($item);

    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->once())
      ->method('purgeByTags')
      ->willReturn(new CloudflarePurgeResult(TRUE, 200, 'ok'));

    $worker = $this->buildWorker(TRUE, 900, $purgeService);
    $worker->processItem($item->data);
  }

}
