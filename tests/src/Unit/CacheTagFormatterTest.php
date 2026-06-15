<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use Drupal\cloudflare_purge\Cache\CacheTagFormatter;
use Drupal\cloudflare_purge\CloudflarePurgeSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the CacheTagFormatter service.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CacheTagFormatter::class)]
class CacheTagFormatterTest extends UnitTestCase {

  /**
   * Default config used by most tests.
   *
   * @return array<string, mixed>
   *   The default configuration values.
   */
  private static function defaultConfig(): array {
    return [
      'auto_purge_entity_types' => ['node', 'taxonomy_term', 'media'],
      'tag_prefix' => '',
      'auto_purge_filter_header_tags' => TRUE,
      'auto_purge_hash_tags' => FALSE,
      'auto_purge_hash_length' => 16,
      'auto_purge_header_max_bytes' => 7168,
    ];
  }

  /**
   * Builds a formatter backed by the given configuration.
   *
   * @param array<string, mixed> $config
   *   Configuration overrides merged over the defaults.
   * @param \Drupal\Core\Logger\LoggerChannelInterface|null $logger
   *   Optional logger mock.
   */
  private function createFormatter(array $config = [], ?LoggerChannelInterface $logger = NULL): CacheTagFormatter {
    $values = $config + self::defaultConfig();

    $immutable = $this->createMock(ImmutableConfig::class);
    $immutable->method('get')->willReturnCallback(
      static fn(string $key): mixed => $values[$key] ?? NULL,
    );

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with(CloudflarePurgeSettings::NAME)->willReturn($immutable);

    return new CacheTagFormatter(
      $configFactory,
      $logger ?? $this->createMock(LoggerChannelInterface::class),
    );
  }

  /**
   * Tests that only purgeable entity-type tags survive filtering.
   */
  public function testFilterPurgeable(): void {
    $formatter = $this->createFormatter();

    $tags = [
      'node:1',
      'node_list',
      'media:7',
      'taxonomy_term:3',
      'config:system.site',
      'http_response',
      'rendered',
      'user:1',
      '',
    ];

    $result = $formatter->filterPurgeable($tags);

    sort($result);
    $this->assertSame(['media:7', 'node:1', 'node_list', 'taxonomy_term:3'], $result);
  }

  /**
   * Tests that an empty enabled-entity-types config yields no purgeable tags.
   */
  public function testFilterPurgeableWithNoEntityTypes(): void {
    $formatter = $this->createFormatter(['auto_purge_entity_types' => []]);
    $this->assertSame([], $formatter->filterPurgeable(['node:1', 'node_list']));
  }

  /**
   * By default, selectForPurge keeps only purgeable tags (filtering on).
   */
  public function testSelectForPurgeFiltersByDefault(): void {
    $formatter = $this->createFormatter();

    $result = $formatter->selectForPurge(['node:1', 'config:system.site', 'http_response', '']);

    $this->assertSame(['node:1'], $result);
  }

  /**
   * With header filtering disabled, selectForPurge keeps every tag.
   *
   * This is the header/purge symmetry guard: when the subscriber emits all
   * tags, the purge path must act on the same full set, so non-entity tags are
   * retained (de-duplicated, empties dropped) rather than filtered away.
   */
  public function testSelectForPurgeReturnsAllWhenFilteringDisabled(): void {
    $formatter = $this->createFormatter(['auto_purge_filter_header_tags' => FALSE]);

    $result = $formatter->selectForPurge(['node:1', 'config:system.site', 'http_response', 'node:1', '']);

    sort($result);
    $this->assertSame(['config:system.site', 'http_response', 'node:1'], $result);
  }

  /**
   * With filtering disabled, the header emits non-purgeable tags too.
   *
   * Confirms buildHeaderValue and selectForPurge stay consistent.
   */
  public function testBuildHeaderValueEmitsAllTagsWhenFilteringDisabled(): void {
    $formatter = $this->createFormatter(['auto_purge_filter_header_tags' => FALSE]);

    $header = $formatter->buildHeaderValue(['node:1', 'config:system.site']);

    $this->assertIsString($header);
    $this->assertStringContainsString('config:system.site', $header);
    $this->assertStringContainsString('node:1', $header);
  }

  /**
   * Tests that the prefix is applied exactly once and not hashed.
   */
  public function testFormatForWireWithPrefix(): void {
    $formatter = $this->createFormatter(['tag_prefix' => 'mysite-']);
    $this->assertSame(['mysite-node:1', 'mysite-node_list'], $formatter->formatForWire(['node:1', 'node_list']));
  }

  /**
   * Tests hashing produces fixed-length, deterministic tokens.
   */
  public function testFormatForWireWithHashing(): void {
    $formatter = $this->createFormatter([
      'auto_purge_hash_tags' => TRUE,
      'auto_purge_hash_length' => 12,
    ]);

    $result = $formatter->formatForWire(['node:1']);

    $this->assertCount(1, $result);
    $this->assertSame(12, strlen($result[0]));
    $this->assertSame(substr(hash('sha256', 'node:1'), 0, 12), $result[0]);
  }

  /**
   * Tests the consistency invariant: header token equals purge token.
   *
   * The response subscriber and the purge call both run tags through
   * formatForWire(), so a given tag must map to the same token in both.
   */
  public function testHeaderAndPurgeTokensMatch(): void {
    foreach ([FALSE, TRUE] as $hash) {
      $formatter = $this->createFormatter([
        'tag_prefix' => 'p-',
        'auto_purge_hash_tags' => $hash,
      ]);

      // Header path: filter then format.
      $headerTokens = $formatter->formatForWire($formatter->filterPurgeable(['node:1', 'config:x']));
      // Purge path: format the invalidated entity tag.
      $purgeTokens = $formatter->formatForWire(['node:1']);

      $this->assertSame($purgeTokens, $headerTokens, $hash ? 'hashed' : 'plain');
    }
  }

  /**
   * Tests buildHeaderValue filters and joins purgeable tags.
   */
  public function testBuildHeaderValueFiltersAndJoins(): void {
    $formatter = $this->createFormatter();
    $value = $formatter->buildHeaderValue(['node:1', 'config:system.site', 'node_list']);
    $this->assertSame('node:1,node_list', $value);
  }

  /**
   * Tests buildHeaderValue emits all tags when filtering is disabled.
   */
  public function testBuildHeaderValueWithoutFiltering(): void {
    $formatter = $this->createFormatter(['auto_purge_filter_header_tags' => FALSE]);
    $value = $formatter->buildHeaderValue(['node:1', 'config:system.site']);
    $this->assertSame('node:1,config:system.site', $value);
  }

  /**
   * Tests buildHeaderValue returns NULL when nothing is purgeable.
   */
  public function testBuildHeaderValueReturnsNullWhenEmpty(): void {
    $formatter = $this->createFormatter();
    $this->assertNull($formatter->buildHeaderValue(['config:system.site', 'http_response']));
  }

  /**
   * Tests the size guard caps the header and logs a warning.
   */
  public function testBuildHeaderValueSizeGuard(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('warning');

    // Cap at the minimum 1024 bytes; generate enough node tags to exceed it.
    $formatter = $this->createFormatter([
      'auto_purge_header_max_bytes' => 1024,
    ], $logger);

    $tags = [];
    for ($i = 0; $i < 500; $i++) {
      $tags[] = 'node:' . $i;
    }

    $value = $formatter->buildHeaderValue($tags);

    $this->assertNotNull($value);
    $this->assertLessThanOrEqual(1024, strlen($value));
  }

}
