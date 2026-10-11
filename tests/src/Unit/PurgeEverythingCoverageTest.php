<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use PHPUnit\Framework\Attributes\Group;
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
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests that purging everything records a full-purge timestamp.
 *
 * A queue of cache-tag purges can build up (e.g. bulk updates against a low
 * rate limit). Once "purge everything" has cleared the whole zone, those
 * queued tags are redundant. Rather than deleting the queue outright — which
 * races with tags being drained in while the purge is in flight — the service
 * records when the purge ran; the queue worker then skips any tag purge whose
 * invalidation predates it.
 *
 * @group cloudflare_purge
 */
#[CoversClass(Purge::class)]
#[Group('cloudflare_purge')]
final class PurgeEverythingCoverageTest extends UnitTestCase {

  /**
   * Builds a Purge service with a mocked API and state service.
   *
   * @param \Drupal\cloudflare_purge\CloudflarePurgeApiInterface $api
   *   The API mock, expected to implement purgeEverything().
   * @param \Drupal\Core\State\StateInterface $state
   *   The state mock.
   *
   * @return \Drupal\cloudflare_purge\Purge
   *   The service under test.
   */
  private function buildPurge(CloudflarePurgeApiInterface $api, StateInterface $state): Purge {
    $config = $this->createStub(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key) => match ($key) {
      'rate_limit_enabled' => FALSE,
      'logging_enabled' => FALSE,
      default => NULL,
    });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('cloudflare_purge.settings')->willReturn($config);

    $credentialResolver = $this->createStub(CredentialResolverInterface::class);
    $credentialResolver->method('getCredentials')->willReturn([
      'use_bearer' => TRUE,
      'zone_id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
      'bearer_token' => 'test-token',
      'email' => NULL,
      'authorization' => NULL,
    ]);

    $purge = new Purge(
      $configFactory,
      $this->createStub(LoggerChannelInterface::class),
      $this->createStub(MessengerInterface::class),
      $api,
      $state,
      $this->createStub(CacheTagFormatterInterface::class),
      $credentialResolver,
    );
    $purge->setStringTranslation($this->getStringTranslationStub());

    return $purge;
  }

  /**
   * A successful purge-everything records the full-purge timestamp in state.
   */
  public function testSuccessfulPurgeEverythingRecordsTimestamp(): void {
    $api = $this->createStub(CloudflarePurgeApiInterface::class);
    $api->method('purgeEverything')->willReturn(new CloudflarePurgeResult(TRUE, 200, 'ok'));

    $state = $this->createMock(StateInterface::class);
    $state->expects($this->once())
      ->method('set')
      ->with(
        CloudflareCacheTagInvalidator::LAST_FULL_PURGE_STATE_KEY,
        $this->callback(static fn($value): bool => is_int($value) && $value > 0),
      );

    $purge = $this->buildPurge($api, $state);
    $result = $purge->purgeEverything(FALSE);

    $this->assertTrue($result->isSuccess());
  }

  /**
   * A failed purge-everything records nothing.
   *
   * Nothing was actually cleared on Cloudflare's side, so queued tags are still
   * needed and must not be marked as covered.
   */
  public function testFailedPurgeEverythingRecordsNothing(): void {
    $api = $this->createStub(CloudflarePurgeApiInterface::class);
    $api->method('purgeEverything')->willReturn(new CloudflarePurgeResult(FALSE, 500, 'Internal Server Error'));

    $state = $this->createMock(StateInterface::class);
    $state->expects($this->never())->method('set');

    $purge = $this->buildPurge($api, $state);
    $result = $purge->purgeEverything(FALSE);

    $this->assertFalse($result->isSuccess());
  }

}
