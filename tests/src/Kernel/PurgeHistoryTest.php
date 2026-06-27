<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Kernel;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\cloudflare_purge\PurgeHistoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the purge-history reader service.
 *
 * @group cloudflare_purge
 */
#[CoversClass(\Drupal\cloudflare_purge\PurgeHistory::class)]
#[RunTestsInSeparateProcesses]
final class PurgeHistoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'dblog',
    'cloudflare_purge',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
  }

  /**
   * Returns the service under test.
   */
  private function history(): PurgeHistoryInterface {
    return $this->container->get('cloudflare_purge.history');
  }

  /**
   * Inserts a cloudflare_purge watchdog row at the given timestamp.
   */
  private function insertEntry(int $timestamp, string $type = 'cloudflare_purge'): void {
    $this->container->get('database')->insert('watchdog')
      ->fields([
        'uid' => 0,
        'type' => $type,
        'message' => 'Test message',
        'variables' => serialize([]),
        'severity' => RfcLogLevel::INFO,
        'link' => '',
        'location' => '',
        'referer' => '',
        'hostname' => 'localhost',
        'timestamp' => $timestamp,
      ])
      ->execute();
  }

  /**
   * The watchdog table is reported available once dblog schema is installed.
   */
  public function testIsAvailable(): void {
    $this->assertTrue($this->history()->isAvailable());
  }

  /**
   * Entries are returned newest-first and filtered to cloudflare_purge.
   */
  public function testGetRecentEntriesOrdersAndFilters(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->insertEntry($now - 10);
    $this->insertEntry($now);
    // An unrelated log type must be excluded.
    $this->insertEntry($now - 5, 'system');

    $entries = $this->history()->getRecentEntries();

    $this->assertIsArray($entries);
    $this->assertCount(2, $entries);
    $this->assertSame($now, (int) $entries[0]->timestamp);
    $this->assertSame($now - 10, (int) $entries[1]->timestamp);
    foreach ($entries as $entry) {
      $this->assertSame('cloudflare_purge', $entry->type);
    }
  }

  /**
   * The limit argument caps the number of returned rows.
   */
  public function testGetRecentEntriesRespectsLimit(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    for ($i = 0; $i < 5; $i++) {
      $this->insertEntry($now - $i);
    }

    $entries = $this->history()->getRecentEntries(3);

    $this->assertIsArray($entries);
    $this->assertCount(3, $entries);
  }

  /**
   * With no matching rows the reader returns an empty list, not NULL.
   */
  public function testGetRecentEntriesEmpty(): void {
    $this->assertSame([], $this->history()->getRecentEntries());
  }

  /**
   * Clearing deletes only cloudflare_purge rows and returns the deleted count.
   */
  public function testClearDeletesOnlyCloudflareEntries(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->insertEntry($now);
    $this->insertEntry($now - 10);
    // An unrelated log type must survive the clear.
    $this->insertEntry($now - 5, 'system');

    $deleted = $this->history()->clear();

    $this->assertSame(2, $deleted);
    $this->assertSame([], $this->history()->getRecentEntries());

    // The unrelated entry is untouched.
    $remaining = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['type'])
      ->execute()
      ->fetchAll();
    $this->assertCount(1, $remaining);
    $this->assertSame('system', $remaining[0]->type);
  }

  /**
   * Clearing with no matching rows deletes nothing and returns zero.
   */
  public function testClearWithNoEntriesReturnsZero(): void {
    $this->assertSame(0, $this->history()->clear());
  }

}
