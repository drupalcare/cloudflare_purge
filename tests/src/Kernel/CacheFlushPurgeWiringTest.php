<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Kernel;

use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\cloudflare_purge\CloudflarePurgeSettings;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a full cache flush actually reaches the zone purge.
 *
 * This is the regression guard for issue #3614126. The unit test for
 * purgeEverythingOnFlush() calls the method directly, so it stays green
 * whether or not anything is wired to call it — which is how 3.1.2 shipped a
 * flush purge that was fatal on Drupal 10, and how simply dropping the
 * interface would have silently disabled the feature on Drupal 11.2+.
 *
 * These tests exercise the real container and the real hook dispatch instead,
 * so they fail if the flush is ever disconnected from the purge again.
 */
#[Group('cloudflare_purge')]
#[RunTestsInSeparateProcesses]
class CacheFlushPurgeWiringTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['cloudflare_purge'];

  /**
   * {@inheritdoc}
   *
   * Swaps the real purge service for a recording double before the container
   * is compiled, so the invalidator is built against it however early the
   * cache_tags_invalidator collector instantiates it.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('cloudflare_purge.purge')
      ->setClass(RecordingPurge::class)
      ->setArguments([]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['cloudflare_purge']);
  }

  /**
   * Sets the opt-in flush setting, then invokes hook_cache_flush().
   *
   * @param bool $enabled
   *   Value for purge_everything_on_flush.
   *
   * @return int
   *   The number of zone purges the flush triggered.
   */
  private function flushWithSetting(bool $enabled): int {
    $this->config(CloudflarePurgeSettings::NAME)
      ->set('purge_everything_on_flush', $enabled)
      ->save();

    RecordingPurge::$purgeEverythingCalls = 0;
    // The same dispatch drupal_flush_all_caches() performs, without the
    // container rebuild that would discard the recording double.
    $this->container->get('module_handler')->invokeAll('cache_flush');

    return RecordingPurge::$purgeEverythingCalls;
  }

  /**
   * The module registers an implementation of hook_cache_flush().
   *
   * Without it, purgeEverythingOnFlush() is dead code and the opt-in setting
   * silently does nothing.
   */
  public function testCacheFlushHookIsRegistered(): void {
    $this->assertTrue(
      $this->container->get('module_handler')->hasImplementations('cache_flush', ['cloudflare_purge']),
      'cloudflare_purge implements hook_cache_flush().',
    );
  }

  /**
   * The invalidator does not implement a core-version-specific interface.
   *
   * CacheTagsPurgeInterface only exists in Drupal 11.2 and later; implementing
   * it makes the class fatal to autoload on Drupal 10 while the module still
   * declares ^10 support (issue #3614126).
   */
  public function testInvalidatorDoesNotImplementVersionSpecificInterface(): void {
    $interfaces = class_implements(CloudflareCacheTagInvalidator::class);
    $this->assertIsArray($interfaces);
    $this->assertNotContains(
      'Drupal\Core\Cache\CacheTagsPurgeInterface',
      $interfaces,
      'The invalidator must not implement the Drupal 11.2+ only CacheTagsPurgeInterface.',
    );
  }

  /**
   * With the opt-in setting on, a cache flush purges the whole zone.
   */
  public function testFlushReachesZonePurgeWhenEnabled(): void {
    $this->assertSame(1, $this->flushWithSetting(TRUE), 'A full cache flush purged the Cloudflare zone once.');
  }

  /**
   * With the opt-in setting off (the default), a cache flush purges nothing.
   */
  public function testFlushDoesNotPurgeWhenDisabled(): void {
    $this->assertSame(0, $this->flushWithSetting(FALSE), 'A full cache flush did not purge the Cloudflare zone.');
  }

}
