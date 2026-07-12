<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use Drupal\cloudflare_purge\Cache\CacheTagFormatterInterface;
use Drupal\cloudflare_purge\Cache\CloudflareCacheTagInvalidator;
use Drupal\cloudflare_purge\CloudflarePurgeApiInterface;
use Drupal\cloudflare_purge\CloudflarePurgeResult;
use Drupal\cloudflare_purge\CredentialResolverInterface;
use Drupal\cloudflare_purge\Purge;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests that purging everything clears the pending cache-tag purge queue.
 *
 * A queue of cache-tag purges can build up (e.g. bulk updates against a low
 * rate limit). Once "purge everything" has cleared the whole zone, those
 * queued tags are redundant, so they should not remain to be re-purged.
 *
 * @group cloudflare_purge
 */
#[CoversClass(Purge::class)]
final class PurgeEverythingQueueTest extends UnitTestCase {

  /**
   * Builds a Purge service with a mocked API and queue factory.
   *
   * @param \Drupal\cloudflare_purge\CloudflarePurgeApiInterface $api
   *   The API mock, expected to implement purgeEverything().
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory mock.
   *
   * @return \Drupal\cloudflare_purge\Purge
   *   The service under test.
   */
  private function buildPurge(CloudflarePurgeApiInterface $api, QueueFactory $queueFactory): Purge {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key) => match ($key) {
      'rate_limit_enabled' => FALSE,
      'logging_enabled' => FALSE,
      default => NULL,
    });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('cloudflare_purge.settings')->willReturn($config);

    $credentialResolver = $this->createMock(CredentialResolverInterface::class);
    $credentialResolver->method('getCredentials')->willReturn([
      'use_bearer' => TRUE,
      'zone_id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
      'bearer_token' => 'test-token',
      'email' => NULL,
      'authorization' => NULL,
    ]);

    $purge = new Purge(
      $configFactory,
      $this->createMock(LoggerChannelInterface::class),
      $this->createMock(MessengerInterface::class),
      $api,
      $this->createMock(StateInterface::class),
      $this->createMock(CacheTagFormatterInterface::class),
      $credentialResolver,
      $queueFactory,
    );
    $purge->setStringTranslation($this->getStringTranslationStub());

    return $purge;
  }

  /**
   * A successful purge-everything deletes the pending tag-purge queue.
   */
  public function testSuccessfulPurgeEverythingClearsQueue(): void {
    $api = $this->createMock(CloudflarePurgeApiInterface::class);
    $api->method('purgeEverything')->willReturn(new CloudflarePurgeResult(TRUE, 200, 'ok'));

    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())->method('deleteQueue');

    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->expects($this->once())
      ->method('get')
      ->with(CloudflareCacheTagInvalidator::QUEUE_NAME)
      ->willReturn($queue);

    $purge = $this->buildPurge($api, $queueFactory);
    $result = $purge->purgeEverything(FALSE);

    $this->assertTrue($result->isSuccess());
  }

  /**
   * A failed purge-everything leaves the pending tag-purge queue intact.
   *
   * Nothing was actually cleared on Cloudflare's side, so the queued tags
   * are still needed and must not be discarded.
   */
  public function testFailedPurgeEverythingLeavesQueueIntact(): void {
    $api = $this->createMock(CloudflarePurgeApiInterface::class);
    $api->method('purgeEverything')->willReturn(new CloudflarePurgeResult(FALSE, 500, 'Internal Server Error'));

    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->expects($this->never())->method('get');

    $purge = $this->buildPurge($api, $queueFactory);
    $result = $purge->purgeEverything(FALSE);

    $this->assertFalse($result->isSuccess());
  }

}
