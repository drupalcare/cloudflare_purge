<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\cloudflare_purge\CloudflarePurgeResult;
use Drupal\cloudflare_purge\Plugin\QueueWorker\CloudflarePurgeQueueWorker;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\State\StateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the queue TTL discard branch in CloudflarePurgeQueueWorker.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CloudflarePurgeQueueWorker::class)]
#[Group('cloudflare_purge')]
class CloudflarePurgeQueueWorkerTest extends TestCase {

  /**
   * Builds a worker wired with the given config values.
   *
   * @param array<string, mixed> $configMap
   *   Map of config key to value, returned by ImmutableConfig::get().
   * @param \Drupal\cloudflare_purge\PurgeInterface $purgeService
   *   The purge service mock.
   * @param \Drupal\Core\Logger\LoggerChannelInterface|null $logger
   *   An optional logger mock so callers can assert on log calls; a bare mock
   *   is created when omitted.
   * @param \Drupal\Core\Queue\QueueFactory|null $queueFactory
   *   An optional queue factory mock so callers can assert on requeue calls;
   *   a bare mock is created when omitted.
   * @param int $lastFullPurge
   *   The timestamp the state service reports for the last full zone purge; 0
   *   (the default) means no full purge has run.
   *
   * @return \Drupal\cloudflare_purge\Plugin\QueueWorker\CloudflarePurgeQueueWorker
   *   The worker under test.
   */
  private function buildWorker(array $configMap, PurgeInterface $purgeService, ?LoggerChannelInterface $logger = NULL, ?QueueFactory $queueFactory = NULL, int $lastFullPurge = 0): CloudflarePurgeQueueWorker {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key) => $configMap[$key] ?? NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('cloudflare_purge.settings')->willReturn($config);

    $logger ??= $this->createMock(LoggerChannelInterface::class);
    $queueFactory ??= $this->createMock(QueueFactory::class);

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(
      static fn(string $key, $default = NULL) => $key === 'cloudflare_purge.last_purge_everything' ? $lastFullPurge : $default,
    );

    return new CloudflarePurgeQueueWorker(
      [],
      'cloudflare_purge_tags',
      [],
      $purgeService,
      $logger,
      $queueFactory,
      $configFactory,
      $state,
    );
  }

  /**
   * Feature disabled: an old item is still purged.
   */
  public function testFeatureDisabledStillPurgesOldItem(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->once())
      ->method('purgeByTags')
      ->willReturn(new CloudflarePurgeResult(TRUE, 200, 'ok'));

    $worker = $this->buildWorker(['queue_max_age_enabled' => FALSE], $purgeService);

    $worker->processItem([
      'tags' => ['node:1'],
      // Two hours old.
      'timestamp' => time() - 7200,
      'retries' => 0,
    ]);
  }

  /**
   * Feature enabled and item exceeds TTL: dropped without API call.
   */
  public function testEnabledAndOverTtlDropsWithoutApiCall(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->never())->method('purgeByTags');

    $worker = $this->buildWorker(
      [
        'queue_max_age_enabled' => TRUE,
        'queue_max_age' => 900,
      ],
      $purgeService,
    );

    $worker->processItem([
      'tags' => ['node:1'],
      // 30 minutes old, well past 15-minute TTL.
      'timestamp' => time() - 1800,
      'retries' => 0,
    ]);
  }

  /**
   * Feature enabled but item under TTL: still purged.
   */
  public function testEnabledAndUnderTtlStillPurges(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->once())
      ->method('purgeByTags')
      ->willReturn(new CloudflarePurgeResult(TRUE, 200, 'ok'));

    $worker = $this->buildWorker(
      [
        'queue_max_age_enabled' => TRUE,
        'queue_max_age' => 900,
      ],
      $purgeService,
    );

    $worker->processItem([
      'tags' => ['node:1'],
      // 1 minute old.
      'timestamp' => time() - 60,
      'retries' => 0,
    ]);
  }

  /**
   * Numeric-string timestamp (Redis-style queue payload) is honored.
   *
   * Regression test: a strict is_int() check would reset age to zero and
   * silently bypass the TTL discard. The worker must accept numeric strings.
   */
  public function testNumericStringTimestampIsHonored(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->never())->method('purgeByTags');

    $worker = $this->buildWorker(
      [
        'queue_max_age_enabled' => TRUE,
        'queue_max_age' => 900,
      ],
      $purgeService,
    );

    $worker->processItem([
      'tags' => ['node:1'],
      // 30-minute-old timestamp passed as a numeric string.
      'timestamp' => (string) (time() - 1800),
      'retries' => 0,
    ]);
  }

  /**
   * Detailed logging off: a successful purge is not logged.
   *
   * Regression test for #3606632: the worker previously logged an ungated
   * info() on every success, flooding dblog on auto-purge.
   */
  public function testSuccessfulPurgeNotLoggedWhenLoggingDisabled(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->method('purgeByTags')
      ->willReturn(new CloudflarePurgeResult(TRUE, 200, 'ok'));

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->never())->method('info');

    $worker = $this->buildWorker(['logging_enabled' => FALSE], $purgeService, $logger);

    $worker->processItem(['tags' => ['node:1'], 'timestamp' => time(), 'retries' => 0]);
  }

  /**
   * Detailed logging on: a successful purge is logged once.
   */
  public function testSuccessfulPurgeLoggedWhenLoggingEnabled(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->method('purgeByTags')
      ->willReturn(new CloudflarePurgeResult(TRUE, 200, 'ok'));

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('info')
      ->with($this->stringContains('Queue worker purged'), $this->anything());

    $worker = $this->buildWorker(['logging_enabled' => TRUE], $purgeService, $logger);

    $worker->processItem(['tags' => ['node:1'], 'timestamp' => time(), 'retries' => 0]);
  }

  /**
   * Feature enabled but TTL is zero (broken config): never discards.
   */
  public function testEnabledWithZeroTtlNeverDiscards(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->once())
      ->method('purgeByTags')
      ->willReturn(new CloudflarePurgeResult(TRUE, 200, 'ok'));

    $worker = $this->buildWorker(
      [
        'queue_max_age_enabled' => TRUE,
        'queue_max_age' => 0,
      ],
      $purgeService,
    );

    $worker->processItem([
      'tags' => ['node:1'],
      'timestamp' => time() - 7200,
      'retries' => 0,
    ]);
  }

  /**
   * Rate limiting suspends the queue without creating a duplicate item.
   *
   * Regression test: requeueing here as well as suspending would leave two
   * copies of the same tags in the queue, because core's cron queue runner
   * also releases the original claimed item back on SuspendQueueException.
   */
  public function testRateLimitSuspendsWithoutRequeueing(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->method('purgeByTags')
      ->willReturn(new CloudflarePurgeResult(FALSE, 429, 'Rate limited'));

    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->never())->method('createItem');

    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->willReturn($queue);

    $worker = $this->buildWorker([], $purgeService, NULL, $queueFactory);

    $this->expectException(SuspendQueueException::class);

    $worker->processItem(['tags' => ['node:1'], 'timestamp' => time(), 'retries' => 0]);
  }

  /**
   * An item covered by a later full purge is discarded, no API call.
   *
   * The item's timestamp (its freshest invalidation) predates the recorded
   * purge-everything, so the content is already gone from the zone.
   */
  public function testItemCoveredByFullPurgeIsDiscarded(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->never())->method('purgeByTags');

    // Item last invalidated an hour ago; a full purge ran 30 minutes ago.
    $worker = $this->buildWorker([], $purgeService, NULL, NULL, time() - 1800);

    $worker->processItem(['tags' => ['node:1'], 'timestamp' => time() - 3600, 'retries' => 0]);
  }

  /**
   * An item newer than the last full purge is still purged.
   *
   * Positive control: content invalidated after the full purge is not covered
   * by it and must still be purged.
   */
  public function testItemNewerThanFullPurgeIsStillPurged(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->once())
      ->method('purgeByTags')
      ->willReturn(new CloudflarePurgeResult(TRUE, 200, 'ok'));

    // Full purge ran an hour ago; this invalidation is fresh.
    $worker = $this->buildWorker([], $purgeService, NULL, NULL, time() - 3600);

    $worker->processItem(['tags' => ['node:1'], 'timestamp' => time(), 'retries' => 0]);
  }

  /**
   * A failed retry re-queue throws RequeueException instead of losing the item.
   *
   * Regression test: on a retriable failure the worker creates a fresh retry
   * item and returns normally so core deletes the original. If that createItem
   * fails (FALSE), returning normally would drop the purge, so the worker
   * throws RequeueException and core keeps the original claimed item.
   */
  public function testRequeueFailureThrowsRequeueException(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->method('purgeByTags')
      ->willReturn(new CloudflarePurgeResult(FALSE, 500, 'Internal Server Error'));

    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willReturn(FALSE);

    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->willReturn($queue);

    $worker = $this->buildWorker([], $purgeService, NULL, $queueFactory);

    $this->expectException(RequeueException::class);

    $worker->processItem(['tags' => ['node:1'], 'timestamp' => time(), 'retries' => 0]);
  }

  /**
   * A retry re-queue that throws is also surfaced as RequeueException.
   *
   * Covers the other failure mode: the queue backend throws (rather than
   * returning FALSE) while creating the retry item.
   */
  public function testRequeueBackendExceptionThrowsRequeueException(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->method('purgeByTags')
      ->willReturn(new CloudflarePurgeResult(FALSE, 500, 'Internal Server Error'));

    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willThrowException(new \RuntimeException('Queue backend down'));

    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->willReturn($queue);

    $worker = $this->buildWorker([], $purgeService, NULL, $queueFactory);

    $this->expectException(RequeueException::class);

    $worker->processItem(['tags' => ['node:1'], 'timestamp' => time(), 'retries' => 0]);
  }

}
