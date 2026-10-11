<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Kernel;

use Drupal\cloudflare_purge\PendingTagDrainer;
use PHPUnit\Framework\Attributes\Group;
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
 * PendingTagDrainer sets a drained queue item's 'timestamp' to the freshest
 * invalidation in the batch (its newest last_invalidated), not the moment it
 * was drained, so the age CloudflarePurgeQueueWorker sees reflects how long
 * ago the content was last actually invalidated. A tag re-invalidated recently
 * must therefore survive the TTL even if it was first queued long ago. This
 * ties drain() and processItem() together in one test rather than trusting
 * their separate unit/kernel test suites compose correctly.
 *
 * @group cloudflare_purge
 */
#[CoversClass(PendingTagDrainer::class)]
#[CoversClass(CloudflarePurgeQueueWorker::class)]
#[RunTestsInSeparateProcesses]
#[Group('cloudflare_purge')]
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
   * Rewrites a pending tag's timestamps to simulate a specific age.
   *
   * The store's add() method always stamps the current time, so an old pending
   * tag can only be simulated by rewriting the row directly. When
   * $lastInvalidated is omitted it defaults to $created, i.e. a tag whose only
   * invalidation happened at $created.
   */
  private function setTagTimes(string $tag, int $created, ?int $lastInvalidated = NULL): void {
    $this->container->get('database')->update('cloudflare_purge_pending_tags')
      ->fields([
        'created' => $created,
        'last_invalidated' => $lastInvalidated ?? $created,
      ])
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
      $this->createStub(LoggerChannelInterface::class),
      $this->container->get('queue'),
      $this->container->get('config.factory'),
      $this->container->get('state'),
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
    $this->setTagTimes('node:1', time() - 7200);

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
   * A long-pending tag re-invalidated recently is NOT discarded.
   *
   * Regression test for the TTL/re-invalidation bug: the tag was first queued
   * two hours ago but re-invalidated one second ago. Ageing the queue item
   * from the freshest invalidation keeps it under a 15-minute TTL, so it is
   * purged rather than wrongly dropped as stale while the edge still holds the
   * newly-changed content.
   */
  public function testReInvalidatedTagSurvivesTtlAfterDrain(): void {
    $this->store()->add(['node:3']);
    // First queued long ago, but its most recent invalidation is fresh.
    $this->setTagTimes('node:3', time() - 7200, time());

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
