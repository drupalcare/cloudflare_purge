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
 * Returns responses for cloudflare_purge module routes.
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
   * Stay on the same page.
   *
   * @return string
   *   The current URL.
   */
  public function getCurrentUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request->server->get('HTTP_REFERER')) {
      return $request->server->get('HTTP_REFERER');
    }
    return base_path();
  }

  /**
   * Purge cloudflare cache.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the previous url.
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
