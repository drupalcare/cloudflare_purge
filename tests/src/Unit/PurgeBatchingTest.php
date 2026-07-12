<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use Drupal\cloudflare_purge\Cache\CacheTagFormatterInterface;
use Drupal\cloudflare_purge\CloudflarePurgeApiInterface;
use Drupal\cloudflare_purge\CloudflarePurgeResult;
use Drupal\cloudflare_purge\CredentialResolverInterface;
use Drupal\cloudflare_purge\Purge;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the Purge service's batching, aggregation and rate-limit handling.
 *
 * These cover the post-refactor behavior where the Purge service owns
 * splitting a list into Cloudflare-sized batches, executing each with a
 * per-request rate-limit check, and aggregating the outcome.
 *
 * @group cloudflare_purge
 */
#[CoversClass(Purge::class)]
final class PurgeBatchingTest extends UnitTestCase {

  /**
   * Builds N distinct cache tags.
   *
   * @return array<int, string>
   *   The tags.
   */
  private function makeTags(int $n): array {
    $tags = [];
    for ($i = 1; $i <= $n; $i++) {
      $tags[] = "node:{$i}";
    }
    return $tags;
  }

  /**
   * Builds a Purge service with the given config and API mock.
   *
   * Credentials resolve to a valid bearer token, the formatter is a
   * pass-through, and the rate-limit counter is backed by a stateful mock so
   * per-request increments behave like the real State API.
   *
   * @param array<string, mixed> $configMap
   *   Config keys returned by ImmutableConfig::get().
   * @param \Drupal\cloudflare_purge\CloudflarePurgeApiInterface $api
   *   The API mock.
   * @param string $zoneId
   *   The resolved Zone ID (defaults to a valid 32-char hex string).
   *
   * @return \Drupal\cloudflare_purge\Purge
   *   The service under test.
   */
  private function buildPurge(array $configMap, CloudflarePurgeApiInterface $api, string $zoneId = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'): Purge {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key) => $configMap[$key] ?? NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('cloudflare_purge.settings')->willReturn($config);

    $credentialResolver = $this->createMock(CredentialResolverInterface::class);
    $credentialResolver->method('getCredentials')->willReturn([
      'use_bearer' => TRUE,
      'zone_id' => $zoneId,
      'bearer_token' => 'test-token',
      'email' => NULL,
      'authorization' => NULL,
    ]);

    $formatter = $this->createMock(CacheTagFormatterInterface::class);
    $formatter->method('formatForWire')->willReturnArgument(0);

    // Stateful rate-limit store, seeded in the current minute at count 0.
    // Captured by reference so per-request increments persist across calls
    // (arrow functions capture by value, so a regular closure is required).
    $store = ['minute' => (int) floor(time() / 60), 'count' => 0];
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(static function (string $k) use (&$store) {
      return $store;
    });
    $state->method('set')->willReturnCallback(static function (string $k, $value) use (&$store): void {
      $store = $value;
    });

    $purge = new Purge(
      $configFactory,
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(MessengerInterface::class),
      $api,
      $state,
      $formatter,
      $credentialResolver,
      $this->createMock(QueueFactory::class),
    );
    $purge->setStringTranslation($this->getStringTranslationStub());

    return $purge;
  }

  /**
   * A single batch returns the API result verbatim (byte-identical path).
   */
  public function testSingleBatchReturnsApiResultVerbatim(): void {
    $calls = 0;
    $api = $this->createMock(CloudflarePurgeApiInterface::class);
    $api->method('purgeByTags')
      ->willReturnCallback(function () use (&$calls): CloudflarePurgeResult {
        $calls++;
        return new CloudflarePurgeResult(TRUE, 200, 'API verbatim message');
      });

    $purge = $this->buildPurge(['rate_limit_enabled' => FALSE, 'logging_enabled' => FALSE], $api);

    $result = $purge->purgeByTags($this->makeTags(10), FALSE);

    $this->assertSame(1, $calls);
    $this->assertTrue($result->isSuccess());
    // The verbatim API message is returned, not a synthesized aggregate one.
    $this->assertSame('API verbatim message', $result->getMessage());
    // The purged count is attached even on the single-batch verbatim path.
    $this->assertSame(10, $result->getPurgedCount());
  }

  /**
   * Multiple batches: each is sent and the result aggregates to full success.
   */
  public function testMultipleBatchesAggregateSuccess(): void {
    $sizes = [];
    $api = $this->createMock(CloudflarePurgeApiInterface::class);
    $api->method('purgeByTags')
      ->willReturnCallback(function (bool $b, string $z, string $t, $a, $e, array $tags) use (&$sizes): CloudflarePurgeResult {
        $sizes[] = count($tags);
        return new CloudflarePurgeResult(TRUE, 200, 'ok');
      });

    $purge = $this->buildPurge(['rate_limit_enabled' => FALSE, 'logging_enabled' => FALSE], $api);

    $result = $purge->purgeByTags($this->makeTags(250), FALSE);

    $this->assertSame([100, 100, 50], $sizes);
    $this->assertTrue($result->isSuccess());
    $this->assertSame('Purged 250 of 250 tags.', $result->getMessage());
    $this->assertSame(250, $result->getPurgedCount());
  }

  /**
   * Hitting the rate limit mid-run stops and reports a partial purge.
   */
  public function testRateLimitMidRunReturnsPartial(): void {
    $calls = 0;
    $api = $this->createMock(CloudflarePurgeApiInterface::class);
    $api->method('purgeByTags')
      ->willReturnCallback(function () use (&$calls): CloudflarePurgeResult {
        $calls++;
        return new CloudflarePurgeResult(TRUE, 200, 'ok');
      });

    // Allow only 2 requests per minute: batches 1-2 succeed, batch 3 trips.
    $purge = $this->buildPurge(
      [
        'rate_limit_enabled' => TRUE,
        'rate_limit_per_minute' => 2,
        'logging_enabled' => FALSE,
      ],
      $api,
    );

    // 250 tags -> [100, 100, 50]; the limiter allows 2 requests then trips.
    $result = $purge->purgeByTags($this->makeTags(250), FALSE);

    $this->assertSame(2, $calls);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('Purged 200 of 250 tags.', $result->getMessage());
    // The local rate-limit stop is surfaced as a 429 on the aggregate.
    $this->assertTrue($result->isRateLimited());
  }

  /**
   * A failed batch does not stop the others; the purge is reported partial.
   *
   * Cache invalidation should purge as much as possible, so a mid-list batch
   * failure must not abort the remaining batches.
   */
  public function testFailedBatchContinuesAndReportsPartial(): void {
    $call = 0;
    $api = $this->createMock(CloudflarePurgeApiInterface::class);
    $api->method('purgeByTags')
      ->willReturnCallback(function () use (&$call): CloudflarePurgeResult {
        $call++;
        // The second batch (of three) fails; the first and third succeed.
        $success = ($call !== 2);
        return new CloudflarePurgeResult($success, $success ? 200 : 500, 'x');
      });

    $purge = $this->buildPurge(['rate_limit_enabled' => FALSE, 'logging_enabled' => FALSE], $api);

    // 250 tags -> [100, 100, 50]; batch 2 fails -> 100 + 50 = 150 purged.
    $result = $purge->purgeByTags($this->makeTags(250), FALSE);

    $this->assertSame(3, $call);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('Purged 150 of 250 tags.', $result->getMessage());
    $this->assertSame(150, $result->getPurgedCount());
  }

  /**
   * Cross-batch de-duplication: duplicates spanning batches collapse to one.
   */
  public function testCrossBatchDeduplication(): void {
    $sizes = [];
    $api = $this->createMock(CloudflarePurgeApiInterface::class);
    $api->method('purgeByTags')
      ->willReturnCallback(function (bool $b, string $z, string $t, $a, $e, array $tags) use (&$sizes): CloudflarePurgeResult {
        $sizes[] = count($tags);
        return new CloudflarePurgeResult(TRUE, 200, 'ok');
      });

    $purge = $this->buildPurge(['rate_limit_enabled' => FALSE, 'logging_enabled' => FALSE], $api);

    // 120 unique tags, each duplicated (240 raw) -> 120 deduped -> [100, 20].
    $unique = $this->makeTags(120);
    $withDupes = array_merge($unique, $unique);

    $result = $purge->purgeByTags($withDupes, FALSE);

    $this->assertSame([100, 20], $sizes);
    $this->assertSame('Purged 120 of 120 tags.', $result->getMessage());
    // The reported count reflects the de-duplicated total (120), not the 240
    // raw items submitted — this is what lets Drush report an accurate count.
    $this->assertSame(120, $result->getPurgedCount());
  }

  /**
   * An auth error short-circuits the run instead of attempting every batch.
   *
   * Authentication failures recur identically on every batch, so once the
   * first batch returns 403 the remaining batches are not attempted.
   */
  public function testAuthErrorShortCircuitsRemainingBatches(): void {
    $calls = 0;
    $api = $this->createMock(CloudflarePurgeApiInterface::class);
    $api->method('purgeByTags')
      ->willReturnCallback(function () use (&$calls): CloudflarePurgeResult {
        $calls++;
        return new CloudflarePurgeResult(FALSE, 403, 'Forbidden');
      });

    $purge = $this->buildPurge(['rate_limit_enabled' => FALSE, 'logging_enabled' => FALSE], $api);

    // 250 tags -> 3 batches, but the first 403 stops the run after one call.
    $result = $purge->purgeByTags($this->makeTags(250), FALSE);

    $this->assertSame(1, $calls);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('Purged 0 of 250 tags.', $result->getMessage());
    // The aggregate must preserve the auth status so callers can classify it.
    $this->assertTrue($result->isAuthenticationError());
  }

  /**
   * A Cloudflare 429 mid-run short-circuits and preserves rate-limit status.
   *
   * Distinct from the module's local rate limiter: even with local rate
   * limiting off, a 429 returned by Cloudflare must stop the run (it will
   * recur) instead of firing the remaining batches.
   */
  public function testApiRateLimitMidRunShortCircuits(): void {
    $call = 0;
    $api = $this->createMock(CloudflarePurgeApiInterface::class);
    $api->method('purgeByTags')
      ->willReturnCallback(function () use (&$call): CloudflarePurgeResult {
        $call++;
        // First batch succeeds; the second gets a 429 from Cloudflare.
        return $call === 1
          ? new CloudflarePurgeResult(TRUE, 200, 'ok')
          : new CloudflarePurgeResult(FALSE, 429, 'Too Many Requests');
      });

    $purge = $this->buildPurge(['rate_limit_enabled' => FALSE, 'logging_enabled' => FALSE], $api);

    // 250 tags -> [100, 100, 50]; batch 1 ok, batch 2 -> 429 stops the run.
    $result = $purge->purgeByTags($this->makeTags(250), FALSE);

    $this->assertSame(2, $call);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('Purged 100 of 250 tags.', $result->getMessage());
    $this->assertTrue($result->isRateLimited());
  }

  /**
   * A malformed Zone ID fails fast: validation rejects it before any batch.
   */
  public function testInvalidZoneIdFailsFastWithoutApiCalls(): void {
    $calls = 0;
    $api = $this->createMock(CloudflarePurgeApiInterface::class);
    $api->method('purgeByTags')
      ->willReturnCallback(function () use (&$calls): CloudflarePurgeResult {
        $calls++;
        return new CloudflarePurgeResult(TRUE, 200, 'ok');
      });

    $purge = $this->buildPurge(
      ['rate_limit_enabled' => FALSE, 'logging_enabled' => FALSE],
      $api,
      'not-a-valid-zone-id',
    );

    $result = $purge->purgeByTags($this->makeTags(65), FALSE);

    $this->assertSame(0, $calls);
    $this->assertFalse($result->isSuccess());
  }

}
