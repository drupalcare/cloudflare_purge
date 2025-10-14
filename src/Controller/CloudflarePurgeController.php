<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Controller;

use Drupal\cloudflare_purge\Purge;
use Drupal\Core\Controller\ControllerBase;
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
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected Purge $purgeService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('request_stack'),
      $container->get('cloudflare_purge.purge'),
    );
  }

  /**
   * Gets the current URL from the referer.
   *
   * @return string
   *   The referer URL or base path.
   */
  protected function getCurrentUrl(): string {
    $request = $this->requestStack->getCurrentRequest();
    if ($request->server->get('HTTP_REFERER')) {
      return $request->server->get('HTTP_REFERER');
    }
    return base_path();
  }

  /**
   * Purges Cloudflare cache.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the previous url.
   */
  public function purgeAll(): RedirectResponse {
    $this->purgeService->purge();
    return new RedirectResponse($this->getCurrentUrl());
  }

}
