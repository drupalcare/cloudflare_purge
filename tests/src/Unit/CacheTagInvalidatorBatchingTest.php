<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use Drupal\cloudflare_purge\Cache\CacheTagFormatterInterface;
use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\cloudflare_purge\CloudflarePurgeResult;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Golden-master characterization of the cache-tag invalidator's batching.
 *
 * After the batching refactor the invalidator no longer pre-chunks for the
 * immediate path — it hands the full tag set to the Purge service, which owns
 * batching. The queue path still emits one item per MAX_BATCH_SIZE batch so
 * each queued request stays a single, rate-limit-friendly call. Both
 * behaviors are pinned here.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CloudflareCacheTagInvalidator::class)]
final class CacheTagInvalidatorBatchingTest extends TestCase {

  /**
   * Builds N synthetic, distinct tags.
   *
   * @return array<int, string>
   *   The generated tags.
   */
  private function makeTags(int $n): array {
    $tags = [];
    for ($i = 1; $i <= $n; $i++) {
      $tags[] = "node:{$i}";
    }
    return $tags;
  }

  /**
   * Builds an invalidator wired for the chosen processing mode.
   *
   * The formatter is a pass-through so the test isolates batch boundaries from
   * the (separately tested) filter/transform behavior.
   *
   * @param bool $useQueue
   *   Whether auto_purge_use_queue is enabled.
   * @param \Drupal\cloudflare_purge\PurgeInterface&\PHPUnit\Framework\MockObject\MockObject $purgeService
   *   The purge service mock.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory mock.
   *
   * @return \Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator
   *   The invalidator under test.
   */
  private function buildInvalidator(bool $useQueue, PurgeInterface&MockObject $purgeService, QueueFactory $queueFactory): CloudflareCacheTagInvalidator {
    $configMap = [
      'auto_purge_enabled' => TRUE,
      'auto_purge_use_queue' => $useQueue,
    ];
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key) => $configMap[$key] ?? NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('cloudflare_purge.settings')->willReturn($config);

    $purgeService->method('hasCredentials')->willReturn(TRUE);

    $formatter = $this->createMock(CacheTagFormatterInterface::class);
    $formatter->method('selectForPurge')->willReturnArgument(0);

    return new CloudflareCacheTagInvalidator(
      $purgeService,
      $configFactory,
      $this->createMock(LoggerChannelInterface::class),
      $queueFactory,
      $formatter,
    );
  }

  /**
   * Immediate mode: the full tag set is handed to Purge in a single call.
   *
   * The invalidator no longer pre-chunks; the Purge service splits the set
   * into Cloudflare-sized batches internally.
   */
  public function testImmediatePurgeDelegatesFullSetToPurgeService(): void {
    $received = [];
    $calls = 0;
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->method('purgeByTags')
      ->willReturnCallback(function (array $tags) use (&$received, &$calls): CloudflarePurgeResult {
        $calls++;
        $received = $tags;
        return new CloudflarePurgeResult(TRUE, 200, 'ok');
      });

    $queueFactory = $this->createMock(QueueFactory::class);
    $invalidator = $this->buildInvalidator(FALSE, $purgeService, $queueFactory);

    $invalidator->invalidateTags($this->makeTags(65));

    $this->assertSame(1, $calls);
    $this->assertCount(65, $received);
  }

  /**
   * Queue mode: 250 tags become one item per batch, sizes [100, 100, 50].
   */
  public function testQueueModeCreatesOneItemPerBatch(): void {
    $itemSizes = [];
    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')
      ->willReturnCallback(function (array $data) use (&$itemSizes): int {
        $itemSizes[] = count($data['tags']);
        return 1;
      });

    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')
      ->with(CloudflareCacheTagInvalidator::QUEUE_NAME)
      ->willReturn($queue);

    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->never())->method('purgeByTags');

    $invalidator = $this->buildInvalidator(TRUE, $purgeService, $queueFactory);

    $invalidator->invalidateTags($this->makeTags(250));

    $this->assertSame([CloudflarePurgeApi::MAX_BATCH_SIZE, CloudflarePurgeApi::MAX_BATCH_SIZE, 50], $itemSizes);
  }

  /**
   * Immediate mode always delegates in a single purge-service call.
   */
  public function testSingleBatchYieldsOneCall(): void {
    $calls = 0;
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->method('purgeByTags')
      ->willReturnCallback(function () use (&$calls): CloudflarePurgeResult {
        $calls++;
        return new CloudflarePurgeResult(TRUE, 200, 'ok');
      });

    $invalidator = $this->buildInvalidator(FALSE, $purgeService, $this->createMock(QueueFactory::class));

    $invalidator->invalidateTags($this->makeTags(30));

    $this->assertSame(1, $calls);
  }

}
