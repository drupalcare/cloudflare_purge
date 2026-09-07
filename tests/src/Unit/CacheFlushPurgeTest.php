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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the full-cache-flush purge decision logic.
 *
 * The hook implementation cloudflare_purge_cache_flush() calls
 * purgeEverythingOnFlush() during drupal_flush_all_caches() (e.g. `drush cr`).
 * The invalidator maps that to a full Cloudflare zone purge, but only when
 * opted in and credentialed.
 *
 * These tests call the method directly, so they cover the decision logic only.
 * That the flush actually reaches this method is covered by
 * \Drupal\Tests\cloudflare_purge\Kernel\CacheFlushPurgeWiringTest.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CloudflareCacheTagInvalidator::class)]
#[Group('cloudflare_purge')]
final class CacheFlushPurgeTest extends TestCase {

  /**
   * Builds the invalidator with the given flush flag and credential state.
   *
   * @param bool $flushEnabled
   *   Value of the purge_everything_on_flush setting.
   * @param \Drupal\cloudflare_purge\PurgeInterface&\PHPUnit\Framework\MockObject\MockObject $purgeService
   *   The purge service mock (expectations set by the caller).
   * @param bool $hasCredentials
   *   Whether credentials are configured.
   *
   * @return \Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator
   *   The invalidator under test.
   */
  private function buildInvalidator(bool $flushEnabled, PurgeInterface&MockObject $purgeService, bool $hasCredentials): CloudflareCacheTagInvalidator {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn(string $key) => $key === 'purge_everything_on_flush' ? $flushEnabled : NULL,
    );

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('cloudflare_purge.settings')->willReturn($config);

    $purgeService->method('hasCredentials')->willReturn($hasCredentials);

    return new CloudflareCacheTagInvalidator(
      $purgeService,
      $configFactory,
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(PendingTagsStoreInterface::class),
      $this->createMock(CacheTagFormatterInterface::class),
    );
  }

  /**
   * When enabled and credentialed, the flush purges the entire zone once.
   */
  public function testFlushPurgesEntireZoneWhenEnabled(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->once())
      ->method('purgeEverything')
      ->with(FALSE)
      ->willReturn(new CloudflarePurgeResult(TRUE, 200, 'ok'));

    $this->buildInvalidator(TRUE, $purgeService, TRUE)->purgeEverythingOnFlush();
  }

  /**
   * When the opt-in setting is off, the flush does nothing.
   */
  public function testFlushDoesNothingWhenDisabled(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->never())->method('purgeEverything');

    $this->buildInvalidator(FALSE, $purgeService, TRUE)->purgeEverythingOnFlush();
  }

  /**
   * Without credentials, the flush does not purge even when enabled.
   */
  public function testFlushDoesNothingWithoutCredentials(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->expects($this->never())->method('purgeEverything');

    $this->buildInvalidator(TRUE, $purgeService, FALSE)->purgeEverythingOnFlush();
  }

  /**
   * A Cloudflare failure during flush is swallowed, never rethrown.
   *
   * Because purgeEverythingOnFlush() runs inside the critical cache-rebuild
   * path, an API exception must not escape and interrupt
   * drupal_flush_all_caches().
   */
  public function testFlushSwallowsPurgeExceptions(): void {
    $purgeService = $this->createMock(PurgeInterface::class);
    $purgeService->method('purgeEverything')
      ->willThrowException(new \RuntimeException('Cloudflare unreachable'));

    $this->buildInvalidator(TRUE, $purgeService, TRUE)->purgeEverythingOnFlush();
    $this->addToAssertionCount(1);
  }

}
