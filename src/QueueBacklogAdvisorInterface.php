<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge;

/**
 * Advises whether the Cloudflare purge queue is growing faster than it drains.
 *
 * Deliberately advisory rather than enforced: there is no site-wide "correct"
 * rate limit or backlog size, so this surfaces the same signals a site
 * builder would otherwise have to go digging for (queue depth vs. configured
 * throughput) and leaves the response to them, per the module's watchdog
 * logging of Cloudflare 429s and the queue's own TTL discard.
 *
 * @package Drupal\cloudflare_purge
 */
interface QueueBacklogAdvisorInterface {

  /**
   * Builds the current list of backlog warnings, if any.
   *
   * @return array<int, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Zero or more warning messages, ready to render (e.g. via
   *   Messenger::addWarning()). Empty when automatic purging isn't using the
   *   queue, or when nothing looks concerning.
   */
  public function getWarnings(): array;

}
