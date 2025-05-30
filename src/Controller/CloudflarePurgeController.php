<?php

namespace Drupal\cloudflare_purge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns responses for cloudflare_purge module routes.
 */
class CloudflarePurgeController extends ControllerBase {

  /**
   * A request stack symfony instance.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a CloudflarePurgeController object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   A request stack Symfony instance.
   */
  public function __construct(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
    );
  }

  /**
   * Stay on the same page.
   */
  public function getCurrentUrl() {
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
    \Drupal::service('cloudflare_purge.purge')->purge();
    return new RedirectResponse($this->getCurrentUrl());
  }

}
