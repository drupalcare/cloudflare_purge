<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\cloudflare_purge\Cache\CacheTagFormatterInterface;
use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\cloudflare_purge\CloudflarePurgeResult;
use Drupal\cloudflare_purge\PendingTagsStoreInterface;
use Drupal\cloudflare_purge\PurgeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Golden-master characterization of the cache-tag invalidator's batching.
 *
 * After the batching refactor the invalidator no longer pre-chunks for the
 * immediate path — it hands the full tag set to the Purge service, which owns
 * batching. The queue path merges the full tag set into the pending-tags
 * store as-is; batching into Cloudflare-sized queue items happens later, at
 * drain time (@see PendingTagDrainer), so it is not exercised here. Both
 * behaviors are pinned here.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CloudflareCacheTagInvalidator::class)]
#[Group('cloudflare_purge')]
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
   * @param \Drupal\cloudflare_purge\PurgeInterface&\PHPUnit\Framework\MockObject\Stub $purgeService
   *   The purge service mock.
   * @param \Drupal\cloudflare_purge\PendingTagsStoreInterface $pendingTagsStore
   *   The pending-tags store mock.
   *
   * @return \Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator
   *   The invalidator under test.
   */
  private function buildInvalidator(bool $useQueue, PurgeInterface&Stub $purgeService, PendingTagsStoreInterface $pendingTagsStore): CloudflareCacheTagInvalidator {
    $configMap = [
      'auto_purge_enabled' => TRUE,
      'auto_purge_use_queue' => $useQueue,
    ];
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key) => $configMap[$key] ?? NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('cloudflare_purge.settings')->willReturn($config);

    $purgeService->method('hasCredentials')->willReturn(TRUE);

    $formatter = $this->createStub(CacheTagFormatterInterface::class);
    $formatter->method('selectForPurge')->willReturnArgument(0);

    return new CloudflareCacheTagInvalidator(
      $purgeService,
      $configFactory,
      $this->createStub(LoggerChannelInterface::class),
      $pendingTagsStore,
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
    $purgeService = $this->createStub(PurgeInterface::class);
    $purgeService->method('purgeByTags')
      ->willReturnCallback(function (array $tags) use (&$received, &$calls): CloudflarePurgeResult {
        $calls++;
        $received = $tags;
        return new CloudflarePurgeResult(TRUE, 200, 'ok');
      });

    $pendingTagsStore = $this->createMock(PendingTagsStoreInterface::class);
    $pendingTagsStore->expects($this->never())->method('add');
    $invalidator = $this->buildInvalidator(FALSE, $purgeService, $pendingTagsStore);

    $invalidator->invalidateTags($this->makeTags(65));

    $this->assertSame(1, $calls);
    $this->assertCount(65, $received);
  }

  /**
   * Queue mode: the full tag set is merged into the pending-tags store as-is.
   *
   * Splitting into Cloudflare-sized batches is the drainer's job, not the
   * invalidator's, so all 250 tags arrive in a single add() call here.
   */
  public function testQueueModeMergesFullSetIntoPendingStore(): void {
    $received = [];
    $calls = 0;
    $pendingTagsStore = $this->createStub(PendingTagsStoreInterface::class);
    $pendingTagsStore->method('add')
      ->willReturnCallback(function (array $tags) use (&$received, &$calls): int {
        $calls++;
        $received = $tags;
        return count($tags);
      });

    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->never())->method('purgeByTags');

    $invalidator = $this->buildInvalidator(TRUE, $purgeService, $pendingTagsStore);

    $invalidator->invalidateTags($this->makeTags(250));

    $this->assertSame(1, $calls);
    $this->assertCount(250, $received);
  }

  /**
   * Immediate mode always delegates in a single purge-service call.
   */
  public function testSingleBatchYieldsOneCall(): void {
    $calls = 0;
    $purgeService = $this->createStub(PurgeInterface::class);
    $purgeService->method('purgeByTags')
      ->willReturnCallback(function () use (&$calls): CloudflarePurgeResult {
        $calls++;
        return new CloudflarePurgeResult(TRUE, 200, 'ok');
      });

    $invalidator = $this->buildInvalidator(FALSE, $purgeService, $this->createStub(PendingTagsStoreInterface::class));

    $invalidator->invalidateTags($this->makeTags(30));

    $this->assertSame(1, $calls);
  }

}
