<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\cloudflare_purge\Hook\CloudflarePurgeHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyContainerBuilder;

/**
 * The #[LegacyHook] wrappers work when the Hook class is not a service.
 *
 * WHY THIS EXISTS. Issue #3628961: "You have requested a non-existent
 * service". The CloudflarePurgeHooks service was first declared in 3.1.4. A
 * site updated from 3.1.3 runs the new .module against the container compiled
 * for 3.1.3 until a cache rebuild, and both wrappers called
 * \Drupal::service(CloudflarePurgeHooks::class), so hook_help() and hook_cron()
 * threw until `drush cr` or `drush updb`.
 *
 * This test builds exactly that container: the Hook class definition is
 * removed after every other compiler pass, including core's
 * HookCollectorPass, which re-registers Hook classes on Drupal 11.1+.
 *
 * @group cloudflare_purge
 */
#[Group('cloudflare_purge')]
#[RunTestsInSeparateProcesses]
class LegacyHookStaleContainerTest extends KernelTestBase implements CompilerPassInterface {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'cloudflare_purge',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->addCompilerPass($this, PassConfig::TYPE_AFTER_REMOVING, -1000);
  }

  /**
   * Removes the Hook class service, as a 3.1.3 container lacks it.
   */
  public function process(SymfonyContainerBuilder $container): void {
    $container->removeDefinition(CloudflarePurgeHooks::class);
  }

  /**
   * The wrappers resolve the Hook class without the container service.
   */
  public function testWrappersRunWithoutTheHookService(): void {
    // Control: without this, a green result would prove nothing.
    $this->assertFalse(
      $this->container->has(CloudflarePurgeHooks::class),
      'The Hook class must be absent from the container, as on a site updated from 3.1.3 before a cache rebuild.'
    );

    $route_match = $this->container->get('current_route_match');

    // The settings-form help text proves the Hook class really was
    // instantiated and its method ran, not merely that nothing threw.
    $help = cloudflare_purge_help('cloudflare_purge.form', $route_match);
    $this->assertStringContainsString('Cloudflare API credentials', $help);
    $this->assertSame('', cloudflare_purge_help('some.other.route', $route_match));

    // hook_cron() delegates through the second wrapper.
    cloudflare_purge_cron();
  }

}
