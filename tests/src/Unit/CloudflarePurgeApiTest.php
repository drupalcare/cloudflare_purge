<?php

declare(strict_types=1);

namespace Drupal\Tests\cloudflare_purge\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\cloudflare_purge\CloudflarePurgeApi;
use Drupal\Core\Logger\LoggerChannelInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CloudflarePurgeApi service.
 *
 * @group cloudflare_purge
 */
#[CoversClass(CloudflarePurgeApi::class)]
#[Group('cloudflare_purge')]
class CloudflarePurgeApiTest extends TestCase {

  /**
   * Valid test zone ID (32-character hex).
   */
  private const TEST_ZONE_ID = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

  /**
   * A successful purge must not log here; the Purge service owns success logs.
   *
   * Regression test for #3606632: this low-level client previously logged an
   * ungated info() on every success, flooding dblog on auto-purge even with
   * detailed logging disabled.
   */
  public function testSuccessfulPurgeDoesNotLog(): void {
    $httpClient = $this->createStub(ClientInterface::class);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $response = new Response(200, [], (string) json_encode(['success' => TRUE, 'result' => ['id' => 'test']]));
    $httpClient->method('request')->willReturn($response);

    // The client must stay silent on success at every level.
    $logger->expects($this->never())->method('info');
    $logger->expects($this->never())->method('notice');
    $logger->expects($this->never())->method('warning');
    $logger->expects($this->never())->method('error');

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeByTags(TRUE, self::TEST_ZONE_ID, 'test-token', NULL, NULL, ['node-1']);

    $this->assertTrue($result->isSuccess());
  }

  /**
   * Test purge everything with Bearer Token.
   */
  public function testPurgeEverythingWithBearerToken(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createStub(LoggerChannelInterface::class);

    $responseBody = json_encode(['success' => TRUE, 'result' => ['id' => 'test']]);
    $response = new Response(200, [], $responseBody);

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cloudflare.com/client/v4/zones/' . self::TEST_ZONE_ID . '/purge_cache',
        $this->callback(function ($options) {
          return isset($options['headers']['Authorization'])
            && $options['headers']['Authorization'] === 'Bearer test-token'
            && $options['json']['purge_everything'] === TRUE;
        })
      )
      ->willReturn($response);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeEverything(TRUE, self::TEST_ZONE_ID, 'test-token', NULL, NULL);

    $this->assertTrue($result->isSuccess());
    $this->assertSame(200, $result->getStatusCode());
  }

  /**
   * Test purge everything with legacy API Key.
   */
  public function testPurgeEverythingWithApiKey(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createStub(LoggerChannelInterface::class);

    $responseBody = json_encode(['success' => TRUE, 'result' => ['id' => 'test']]);
    $response = new Response(200, [], $responseBody);

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cloudflare.com/client/v4/zones/' . self::TEST_ZONE_ID . '/purge_cache',
        $this->callback(function ($options) {
          return isset($options['headers']['X-Auth-Email'])
            && $options['headers']['X-Auth-Email'] === 'test@example.com'
            && isset($options['headers']['X-Auth-Key'])
            && $options['headers']['X-Auth-Key'] === 'test-api-key';
        })
      )
      ->willReturn($response);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeEverything(FALSE, self::TEST_ZONE_ID, '', 'test-api-key', 'test@example.com');

    $this->assertTrue($result->isSuccess());
  }

  /**
   * Test purge by URLs.
   */
  public function testPurgeByUrls(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createStub(LoggerChannelInterface::class);

    $responseBody = json_encode(['success' => TRUE, 'result' => ['id' => 'test']]);
    $response = new Response(200, [], $responseBody);

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cloudflare.com/client/v4/zones/' . self::TEST_ZONE_ID . '/purge_cache',
        $this->callback(function ($options) {
          return isset($options['json']['files'])
            && $options['json']['files'] === ['https://example.com/page1', 'https://example.com/page2'];
        })
      )
      ->willReturn($response);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeByUrls(
      TRUE,
      self::TEST_ZONE_ID,
      'test-token',
      NULL,
      NULL,
      ['https://example.com/page1', 'https://example.com/page2']
    );

    $this->assertTrue($result->isSuccess());
  }

  /**
   * Test purge by tags.
   */
  public function testPurgeByTags(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createStub(LoggerChannelInterface::class);

    $responseBody = json_encode(['success' => TRUE, 'result' => ['id' => 'test']]);
    $response = new Response(200, [], $responseBody);

    $httpClient->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://api.cloudflare.com/client/v4/zones/' . self::TEST_ZONE_ID . '/purge_cache',
        $this->callback(function ($options) {
          return isset($options['json']['tags'])
            && $options['json']['tags'] === ['node-123', 'taxonomy-term-45'];
        })
      )
      ->willReturn($response);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeByTags(
      TRUE,
      self::TEST_ZONE_ID,
      'test-token',
      NULL,
      NULL,
      ['node-123', 'taxonomy-term-45']
    );

    $this->assertTrue($result->isSuccess());
  }

  /**
   * Test invalid zone ID format.
   */
  public function testInvalidZoneIdFormat(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createStub(LoggerChannelInterface::class);

    // Should not make HTTP request with invalid zone ID.
    $httpClient->expects($this->never())->method('request');

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeEverything(TRUE, 'invalid-zone-id', 'test-token', NULL, NULL);

    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString('Invalid Zone ID', $result->getMessage());
  }

  /**
   * Test empty URLs returns error.
   */
  public function testEmptyUrlsReturnsError(): void {
    $httpClient = $this->createMock(ClientInterface::class);
    $logger = $this->createStub(LoggerChannelInterface::class);

    $httpClient->expects($this->never())->method('request');

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeByUrls(TRUE, self::TEST_ZONE_ID, 'test-token', NULL, NULL, []);

    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString('No valid URLs', $result->getMessage());
  }

  /**
   * A Cloudflare API error must be reported as a result, never fatal.
   *
   * Regression test for the Guzzle 8 / Drupal 12 fatal: Guzzle 7 declares
   * hasResponse() and getResponse() on RequestException, Guzzle 8 declares
   * neither — a response lives only on ResponseException and its subclasses,
   * which still extend RequestException and so still reach the same catch.
   * Before the fix, every Cloudflare API *failure* on Drupal 12 died with
   * "Call to undefined method GuzzleHttp\Exception\ClientException::
   * hasResponse()" instead of returning a failed CloudflarePurgeResult. The
   * whole 141-test suite passed throughout, because nothing here had ever
   * thrown a Guzzle exception at this client.
   *
   * RequestException::create() is the factory Guzzle itself uses, and exists
   * in both majors, so this test asserts on what Guzzle really throws rather
   * than on a hand-built stand-in.
   */
  public function testApiErrorWithResponseIsReportedNotFatal(): void {
    $httpClient = $this->createStub(ClientInterface::class);
    $logger = $this->createStub(LoggerChannelInterface::class);

    $body = (string) json_encode([
      'success' => FALSE,
      'errors' => [['code' => 10000, 'message' => 'Authentication error']],
    ]);
    // A 403 makes Guzzle's factory return ClientException on both majors.
    $exception = RequestException::create(
      new Request('POST', 'https://api.cloudflare.com/client/v4/zones/' . self::TEST_ZONE_ID . '/purge_cache'),
      new Response(403, [], $body)
    );
    $httpClient->method('request')->willThrowException($exception);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeEverything(TRUE, self::TEST_ZONE_ID, 'test-token', NULL, NULL);

    $this->assertFalse($result->isSuccess());
    $this->assertSame(403, $result->getStatusCode());
    $this->assertStringContainsString('Authentication error', $result->getMessage());
    $this->assertStringContainsString('code: 10000', $result->getMessage());
    $this->assertFalse($result->getData()['success'] ?? NULL);
  }

  /**
   * A request exception carrying no response must not be treated as one.
   *
   * Guzzle 8 throws a bare RequestException ("Error completing request") when
   * a transfer fails before any response headers arrive. There is no response
   * to read, and the feature detection must fall through to NULL rather than
   * calling a method the class does not declare.
   */
  public function testRequestExceptionWithoutResponseIsHandled(): void {
    $httpClient = $this->createStub(ClientInterface::class);
    $logger = $this->createStub(LoggerChannelInterface::class);

    $exception = RequestException::create(
      new Request('POST', 'https://api.cloudflare.com/client/v4/zones/' . self::TEST_ZONE_ID . '/purge_cache')
    );
    $httpClient->method('request')->willThrowException($exception);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeEverything(TRUE, self::TEST_ZONE_ID, 'test-token', NULL, NULL);

    $this->assertFalse($result->isSuccess());
    $this->assertSame(0, $result->getStatusCode());
    $this->assertNull($result->getData());
    $this->assertStringContainsString('Error completing request', $result->getMessage());
  }

  /**
   * A response outside 4xx/5xx is where the two Guzzle majors diverge most.
   *
   * Guzzle 7 returns a plain RequestException that carries the response and
   * answers TRUE to hasResponse(); Guzzle 8 returns a ResponseException, whose
   * parent has no hasResponse() at all. Both must yield the same result here,
   * which is the whole point of detecting the feature rather than the version.
   */
  public function testNonClientOrServerErrorResponseIsStillRead(): void {
    $httpClient = $this->createStub(ClientInterface::class);
    $logger = $this->createStub(LoggerChannelInterface::class);

    $body = (string) json_encode([
      'success' => FALSE,
      'errors' => [['code' => 1234, 'message' => 'Unexpected redirect']],
    ]);
    $exception = RequestException::create(
      new Request('POST', 'https://api.cloudflare.com/client/v4/zones/' . self::TEST_ZONE_ID . '/purge_cache'),
      new Response(302, [], $body)
    );
    $httpClient->method('request')->willThrowException($exception);

    $api = new CloudflarePurgeApi($httpClient, $logger);
    $result = $api->purgeEverything(TRUE, self::TEST_ZONE_ID, 'test-token', NULL, NULL);

    $this->assertFalse($result->isSuccess());
    $this->assertSame(302, $result->getStatusCode());
    $this->assertStringContainsString('Unexpected redirect', $result->getMessage());
  }

}
