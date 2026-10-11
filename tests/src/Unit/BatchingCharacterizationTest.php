<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Golden-master characterization of CloudflarePurgeApi batching/sanitization.
 *
 * These tests pin the CURRENT observable behavior of the API layer's
 * item-sanitization pipeline (trim, dedupe, re-index, cap at MAX_BATCH_SIZE)
 * so a refactor of the batching design can be proven against a known baseline.
 *
 * Behaviors marked "GOLDEN (preserve)" remain byte-identical after the
 * refactor. The former silent truncation is now a logged backstop — the
 * intended, documented behavior change — asserted below.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CloudflarePurgeApi::class)]
#[Group('cloudflare_purge')]
final class BatchingCharacterizationTest extends TestCase {

  /**
   * Valid test zone ID (32-character hex).
   */
  private const TEST_ZONE_ID = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

  /**
   * Builds an API client whose HTTP request payload is captured by reference.
   *
   * @param array<string, mixed>|null $captured
   *   Receives the decoded `json` option of the single HTTP request.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The (usually mocked) logger to inject.
   *
   * @return \Drupal\cloudflare_purge\CloudflarePurgeApi
   *   The API client under test.
   */
  private function apiCapturingPayload(?array &$captured, LoggerChannelInterface $logger): CloudflarePurgeApi {
    $httpClient = $this->createStub(ClientInterface::class);
    $response = new Response(200, [], (string) json_encode(['success' => TRUE, 'result' => ['id' => 'x']]));

    $httpClient->method('request')
      ->willReturnCallback(function (string $method, string $uri, array $options) use (&$captured, $response): Response {
        $captured = $options['json'] ?? [];
        return $response;
      });

    return new CloudflarePurgeApi($httpClient, $logger);
  }

  /**
   * GOLDEN (preserve): duplicate items are collapsed and re-indexed.
   */
  public function testDeduplicatesAndReindexes(): void {
    $captured = NULL;
    $api = $this->apiCapturingPayload($captured, $this->createStub(LoggerChannelInterface::class));

    $api->purgeByTags(TRUE, self::TEST_ZONE_ID, 'token', NULL, NULL, [
      'node:1',
      'node:2',
      'node:1',
      'node:2',
      'node:3',
    ]);

    $this->assertSame(['node:1', 'node:2', 'node:3'], $captured['tags']);
  }

  /**
   * GOLDEN (preserve): items are trimmed; empties and non-strings dropped.
   */
  public function testTrimsAndDropsEmpties(): void {
    $captured = NULL;
    $api = $this->apiCapturingPayload($captured, $this->createStub(LoggerChannelInterface::class));

    $api->purgeByUrls(TRUE, self::TEST_ZONE_ID, 'token', NULL, NULL, [
      '  https://example.com/a  ',
      '',
      '   ',
      'https://example.com/b',
    ]);

    $this->assertSame(
      ['https://example.com/a', 'https://example.com/b'],
      $captured['files'],
    );
  }

  /**
   * The API caps a single request at MAX_BATCH_SIZE but LOGS the dropped ones.
   *
   * The API stays a single-request transport: callers batch via the Purge
   * service, so receiving more than MAX_BATCH_SIZE here means a caller failed
   * to batch. The cap remains as defense-in-depth, but the overflow is no
   * longer dropped silently — a warning is logged so the misuse is
   * diagnosable. (Pre-refactor this drop was silent; that was the defect.)
   */
  public function testTruncationAboveMaxBatchSizeIsLogged(): void {
    $max = CloudflarePurgeApi::MAX_BATCH_SIZE;
    $over = $max + 5;

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('exceeding the @max-item per-request limit'),
        $this->callback(static fn(array $context): bool => ($context['@count'] ?? NULL) === $over
          && ($context['@max'] ?? NULL) === $max
          && ($context['@dropped'] ?? NULL) === 5),
      );

    $captured = NULL;
    $api = $this->apiCapturingPayload($captured, $logger);

    $urls = [];
    for ($i = 1; $i <= $over; $i++) {
      $urls[] = "https://example.com/page{$i}";
    }

    $api->purgeByUrls(TRUE, self::TEST_ZONE_ID, 'token', NULL, NULL, $urls);

    $this->assertCount($max, $captured['files']);
    $this->assertSame('https://example.com/page1', $captured['files'][0]);
    $this->assertSame("https://example.com/page{$max}", $captured['files'][$max - 1]);
    $this->assertNotContains('https://example.com/page' . ($max + 1), $captured['files']);
  }

}
