<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Controller;

use Drupal\cloudflare_purge\Purge;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides HTTP controller endpoints for Cloudflare cache purging.
 *
 * This controller handles user-initiated cache purge requests for the
 * Cloudflare CDN integration. It provides route handlers that trigger
 * cache invalidation operations and redirect users back to their
 * originating pages with appropriate success or error messages.
 *
 * The controller implements comprehensive error handling and logging
 * to ensure reliable cache purge operations and proper user feedback.
 */
class CloudflarePurgeController extends ControllerBase {

  /**
   * Constructs a CloudflarePurgeController object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   A request stack Symfony instance.
   * @param \Drupal\cloudflare_purge\Purge $purgeService
   *   The Cloudflare purge service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected Purge $purgeService,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('request_stack'),
      $container->get('cloudflare_purge.purge'),
      $container->get('logger.channel.cloudflare_purge')
    );
  }

  /**
   * Gets the URL to redirect to after a cache purge operation.
   *
   * Retrieves the HTTP referer from the current request to redirect users
   * back to the page they came from. Falls back to the site base path if
   * no referer is available.
   *
   * @return string
   *   The URL to redirect to, either the HTTP referer or the site base path.
   */
  public function getCurrentUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request->server->get('HTTP_REFERER')) {
      return $request->server->get('HTTP_REFERER');
    }
    return base_path();
  }

  /**
   * Purges all content from the Cloudflare CDN cache.
   *
   * Initiates a complete cache purge operation for the Cloudflare CDN.
   * Success and error messages are displayed to the user via the messenger
   * service. The user is redirected back to their originating page after
   * the operation completes.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   HTTP redirect response to the user's previous page.
   *
   * @throws \Exception
   *   Thrown when the cache purge operation fails (caught internally and
   *   converted to user error messages).
   */
  public function purgeAll(): RedirectResponse {
    try {
      $this->purgeService->purge();
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to purge cache: @message', [
        '@message' => $e->getMessage(),
      ]));
      $this->logger->error('Exception during cache purge: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return new RedirectResponse($this->getCurrentUrl());
  }

}
