<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Kernel;

use Drupal\cloudflare_purge\Controller\CloudflarePurgeController;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests purge history substitutes watchdog log placeholders.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CloudflarePurgeController::class)]
#[CoversMethod(CloudflarePurgeController::class, 'formatWatchdogMessage')]
#[CoversMethod(CloudflarePurgeController::class, 'history')]
#[RunTestsInSeparateProcesses]
final class CloudflarePurgeHistoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'dblog',
    'cloudflare_purge',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['system']);
  }

  /**
   * Rows with variables render values, not literal @placeholders.
   */
  public function testHistorySubstitutesWatchdogVariables(): void {
    $timestamp = $this->container->get('datetime.time')->getRequestTime();

    $this->container->get('database')->insert('watchdog')
      ->fields([
        'uid' => 0,
        'type' => 'cloudflare_purge',
        'message' => 'Queue worker purged @count cache tag(s) from Cloudflare.',
        'variables' => serialize(['@count' => 3]),
        'severity' => RfcLogLevel::INFO,
        'link' => '',
        'location' => '',
        'referer' => '',
        'hostname' => 'localhost',
        'timestamp' => $timestamp,
      ])
      ->execute();

    $this->container->get('database')->insert('watchdog')
      ->fields([
        'uid' => 0,
        'type' => 'cloudflare_purge',
        'message' => 'Cloudflare cache purged successfully. Payload: @payload',
        'variables' => serialize(['@payload' => '{"tags":["node:1"]}']),
        'severity' => RfcLogLevel::INFO,
        'link' => '',
        'location' => '',
        'referer' => '',
        'hostname' => 'localhost',
        'timestamp' => $timestamp - 1,
      ])
      ->execute();

    $role = Role::create([
      'id' => 'history_viewer',
      'label' => 'History viewer',
    ]);
    $role->grantPermission('view cloudflare purge history');
    $role->save();

    $user = User::create([
      'name' => 'tester',
      'mail' => 'tester@example.com',
      'roles' => ['history_viewer'],
    ]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);

    $controller = CloudflarePurgeController::create($this->container);
    $build = $controller->history();

    $this->assertArrayHasKey('table', $build);
    $rows = $build['table']['#rows'];
    $this->assertCount(2, $rows);

    $countMessage = $rows[0][2];
    $this->assertInstanceOf(FormattableMarkup::class, $countMessage);
    $renderedCount = (string) $countMessage;
    $this->assertStringContainsString('3 cache tag(s)', $renderedCount);
    $this->assertStringNotContainsString('@count', $renderedCount);

    $payloadMessage = $rows[1][2];
    $renderedPayload = (string) $payloadMessage;
    $this->assertStringContainsString('{&quot;tags&quot;:[&quot;node:1&quot;]}', $renderedPayload);
    $this->assertStringNotContainsString('@payload', $renderedPayload);
  }

  /**
   * Messages without variables are returned unchanged.
   */
  public function testFormatWatchdogMessageWithoutVariables(): void {
    $message = 'Purge everything requested.';
    $formatted = CloudflarePurgeController::formatWatchdogMessage($message, '');
    $this->assertSame($message, $formatted);
  }

}
