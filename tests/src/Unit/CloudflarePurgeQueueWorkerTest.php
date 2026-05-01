<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use Drupal\cloudflare_purge\CloudflarePurgeResult;
use Drupal\cloudflare_purge\Plugin\QueueWorker\CloudflarePurgeQueueWorker;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the queue TTL discard branch in CloudflarePurgeQueueWorker.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CloudflarePurgeQueueWorker::class)]
class CloudflarePurgeQueueWorkerTest extends TestCase {

  /**
   * Builds a worker wired with the given config values.
   *
   * @param array<string, mixed> $configMap
   *   Map of config key to value, returned by ImmutableConfig::get().
   * @param \Drupal\cloudflare_purge\PurgeInterface $purgeService
   *   The purge service mock.
   *
   * @return \Drupal\cloudflare_purge\Plugin\QueueWorker\CloudflarePurgeQueueWorker
   *   The worker under test.
   */
  private function buildWorker(array $configMap, PurgeInterface $purgeService): CloudflarePurgeQueueWorker {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key) => $configMap[$key] ?? NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('cloudflare_purge.settings')->willReturn($config);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $queueFactory = $this->createMock(QueueFactory::class);

    return new CloudflarePurgeQueueWorker(
      [],
      'cloudflare_purge_tags',
      [],
      $purgeService,
      $logger,
      $queueFactory,
      $configFactory,
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

}
