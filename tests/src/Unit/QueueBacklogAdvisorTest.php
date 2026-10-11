<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Tests\UnitTestCase;
use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\cloudflare_purge\PendingTagsStoreInterface;
use Drupal\cloudflare_purge\QueueBacklogAdvisor;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the queue-backlog advisor's warning conditions.
 *
 * @group cloudflare_purge
 */
#[CoversClass(QueueBacklogAdvisor::class)]
#[Group('cloudflare_purge')]
final class QueueBacklogAdvisorTest extends UnitTestCase {

  /**
   * Builds an advisor wired with the given config, pending and queue counts.
   *
   * @param array<string, mixed> $configMap
   *   Map of config key to value, returned by ImmutableConfig::get().
   * @param int $pendingCount
   *   The value PendingTagsStoreInterface::count() should return.
   * @param int $queueItemCount
   *   The value QueueInterface::numberOfItems() should return.
   *
   * @return \Drupal\cloudflare_purge\QueueBacklogAdvisor
   *   The advisor under test.
   */
  private function buildAdvisor(array $configMap, int $pendingCount, int $queueItemCount): QueueBacklogAdvisor {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key) => $configMap[$key] ?? NULL);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('cloudflare_purge.settings')->willReturn($config);

    $pendingTagsStore = $this->createStub(PendingTagsStoreInterface::class);
    $pendingTagsStore->method('count')->willReturn($pendingCount);

    $queue = $this->createStub(QueueInterface::class);
    $queue->method('numberOfItems')->willReturn($queueItemCount);

    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->with(CloudflareCacheTagInvalidator::QUEUE_NAME)->willReturn($queue);

    return new QueueBacklogAdvisor($configFactory, $pendingTagsStore, $queueFactory, $this->getStringTranslationStub());
  }

  /**
   * Queueing disabled: no warnings regardless of backlog size.
   */
  public function testNoWarningsWhenQueueingDisabled(): void {
    $advisor = $this->buildAdvisor(['auto_purge_use_queue' => FALSE], 5000, 100);
    $this->assertSame([], $advisor->getWarnings());
  }

  /**
   * No rate limit, small backlog: no warning.
   */
  public function testNoRateLimitSmallBacklogNoWarning(): void {
    $advisor = $this->buildAdvisor([
      'auto_purge_use_queue' => TRUE,
      'rate_limit_enabled' => FALSE,
    ], 500, 0);

    $this->assertSame([], $advisor->getWarnings());
  }

  /**
   * No rate limit, backlog over the threshold: warning fires.
   */
  public function testNoRateLimitLargeBacklogWarns(): void {
    $advisor = $this->buildAdvisor([
      'auto_purge_use_queue' => TRUE,
      'rate_limit_enabled' => FALSE,
      'queue_max_age_enabled' => TRUE,
    ], 1500, 0);

    $warnings = $advisor->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('No rate limit is set', (string) $warnings[0]);
    $this->assertStringContainsString('1500', (string) $warnings[0]);
  }

  /**
   * Queue items count toward the backlog estimate (via MAX_BATCH_SIZE).
   */
  public function testNoRateLimitCountsQueuedItemsTowardEstimate(): void {
    // 5 pending + 10 queue items * 100/item = 1005, just over the threshold.
    $advisor = $this->buildAdvisor([
      'auto_purge_use_queue' => TRUE,
      'rate_limit_enabled' => FALSE,
      'queue_max_age_enabled' => TRUE,
    ], 5, 10);

    $this->assertCount(1, $advisor->getWarnings());
  }

  /**
   * Rate limit set, queue would drain quickly: no warning.
   */
  public function testRateLimitFastDrainNoWarning(): void {
    $advisor = $this->buildAdvisor([
      'auto_purge_use_queue' => TRUE,
      'rate_limit_enabled' => TRUE,
      'rate_limit_per_minute' => 60,
    ], 0, 5);

    $this->assertSame([], $advisor->getWarnings());
  }

  /**
   * Rate limit set, queue would take too long to drain: warning fires.
   */
  public function testRateLimitSlowDrainWarns(): void {
    // 120 items at 10/minute = 12 minutes, over the 10-minute threshold.
    $advisor = $this->buildAdvisor([
      'auto_purge_use_queue' => TRUE,
      'rate_limit_enabled' => TRUE,
      'rate_limit_per_minute' => 10,
      'queue_max_age_enabled' => TRUE,
    ], 0, 120);

    $warnings = $advisor->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('12 minutes', (string) $warnings[0]);
    $this->assertStringContainsString('120 purge request', (string) $warnings[0]);
  }

  /**
   * Pending tags count toward the rate-limited drain estimate.
   *
   * Regression test: the estimate must include tags still in the pending set
   * (each becomes ~1 request per MAX_BATCH_SIZE once drained), not only items
   * already in the queue — otherwise the advisory is silent right after a
   * burst of invalidations, before the next cron drain, exactly when the
   * backlog is largest.
   */
  public function testRateLimitCountsPendingTowardEstimate(): void {
    // 11000 pending tags => ceil(11000 / 100) = 110 requests; nothing queued
    // yet. At 10/minute that is 11 minutes, over the 10-minute threshold.
    $advisor = $this->buildAdvisor([
      'auto_purge_use_queue' => TRUE,
      'rate_limit_enabled' => TRUE,
      'rate_limit_per_minute' => 10,
      'queue_max_age_enabled' => TRUE,
    ], 11000, 0);

    $warnings = $advisor->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('11 minutes', (string) $warnings[0]);
  }

  /**
   * A zero or invalid rate limit never divides-by-zero into a warning.
   */
  public function testRateLimitZeroDoesNotWarn(): void {
    $advisor = $this->buildAdvisor([
      'auto_purge_use_queue' => TRUE,
      'rate_limit_enabled' => TRUE,
      'rate_limit_per_minute' => 0,
    ], 0, 500);

    $this->assertSame([], $advisor->getWarnings());
  }

  /**
   * TTL discard disabled: the warning appends the recommendation to enable it.
   */
  public function testTtlRecommendationAppendedWhenDisabled(): void {
    $advisor = $this->buildAdvisor([
      'auto_purge_use_queue' => TRUE,
      'rate_limit_enabled' => FALSE,
      'queue_max_age_enabled' => FALSE,
    ], 1500, 0);

    $warnings = $advisor->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('Discard queued items older than the configured TTL', (string) $warnings[0]);
  }

  /**
   * TTL discard already enabled: no recommendation is appended.
   */
  public function testTtlRecommendationOmittedWhenEnabled(): void {
    $advisor = $this->buildAdvisor([
      'auto_purge_use_queue' => TRUE,
      'rate_limit_enabled' => FALSE,
      'queue_max_age_enabled' => TRUE,
    ], 1500, 0);

    $warnings = $advisor->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertStringNotContainsString('Discard queued items', (string) $warnings[0]);
  }

}
