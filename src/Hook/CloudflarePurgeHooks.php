<?php

namespace Drupal\cloudflare_purge\Hook;

use Drupal\Core\Url;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for cloudflare_purge.
 */
class CloudflarePurgeHooks {
  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    switch ($route_name) {
      case 'help.page.cloudflare_purge':
        $output = '';
        $output .= '<h2>' . $this->t('About') . '</h2>';
        $output .= '<p>' . $this->t('The Cloudflare Purge module provides comprehensive cache purging capabilities for sites using Cloudflare CDN.') . '</p>';
        $output .= '<h2>' . $this->t('Features') . '</h2>';
        $output .= '<ul>';
        $output .= '<li>' . $this->t('<strong>Purge Everything:</strong> Clear the entire Cloudflare cache for your zone.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Purge by URL:</strong> Purge specific URLs (large lists are batched automatically, up to 100 per request).') . '</li>';
        $output .= '<li>' . $this->t('<strong>Purge by Cache Tag:</strong> Purge content by cache tags (all plans).') . '</li>';
        $output .= '<li>' . $this->t('<strong>Purge by Prefix:</strong> Purge all content under a URL prefix (all plans).') . '</li>';
        $output .= '<li>' . $this->t('<strong>Purge by Hostname:</strong> Purge all content for specific hostnames (all plans).') . '</li>';
        $output .= '<li>' . $this->t('<strong>Automatic Purging:</strong> Automatically purge Cloudflare when Drupal cache tags are invalidated.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Queue Support:</strong> Queue purge requests for batch processing during cron.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Drush Commands:</strong> Full Drush support for all purge operations.') . '</li>';
        $output .= '</ul>';
        $output .= '<h2>' . $this->t('Configuration') . '</h2>';
        $output .= '<p>' . $this->t('Configure your Cloudflare credentials at <a href=":url">Administration » Configuration » Cloudflare Purge</a>.', [
          ':url' => Url::fromRoute('cloudflare_purge.form')->toString(),
        ]) . '</p>';
        $output .= '<h2>' . $this->t('Credential Storage') . '</h2>';
        $output .= '<p>' . $this->t('Credentials can be stored in three ways (in priority order):') . '</p>';
        $output .= '<ol>';
        $output .= '<li>' . $this->t('<strong>settings.php:</strong> Most secure, recommended for production.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Key module:</strong> Secure storage with the Key module.') . '</li>';
        $output .= '<li>' . $this->t('<strong>Plain text:</strong> Stored in database configuration (least secure).') . '</li>';
        $output .= '</ol>';
        $output .= '<h2>' . $this->t('Drush Commands') . '</h2>';
        $output .= '<ul>';
        $output .= '<li><code>drush cloudflare:purge-all</code> - ' . $this->t('Purge entire cache') . '</li>';
        $output .= '<li><code>drush cloudflare:purge-url URL</code> - ' . $this->t('Purge specific URL(s)') . '</li>';
        $output .= '<li><code>drush cloudflare:purge-tags TAG1 TAG2</code> - ' . $this->t('Purge by cache tags') . '</li>';
        $output .= '<li><code>drush cloudflare:purge-prefixes PREFIX</code> - ' . $this->t('Purge by URL prefix') . '</li>';
        $output .= '<li><code>drush cloudflare:purge-hostnames HOST</code> - ' . $this->t('Purge by hostname') . '</li>';
        $output .= '<li><code>drush cloudflare:status</code> - ' . $this->t('Check configuration status') . '</li>';
        $output .= '</ul>';
        return $output;

      case 'cloudflare_purge.form':
        return '<p>' . $this->t('Configure your Cloudflare API credentials to enable cache purging. For security, consider storing credentials in settings.php or using the Key module.') . '</p>';

      case 'cloudflare_purge.settings':
        return '<p>' . $this->t('Configure automatic cache purging behavior. When enabled, Cloudflare cache will be automatically purged when Drupal cache tags are invalidated.') . '</p>';

      case 'cloudflare_purge.purge_by_url':
        return '<p>' . $this->t('Enter URLs to purge from the Cloudflare cache. URLs must be fully qualified including the scheme (https://).') . '</p>';

      case 'cloudflare_purge.purge_by_tag':
        return '<p>' . $this->t('Enter cache tags to purge. Available on all Cloudflare plans. Free tier: 5 requests/minute, max 100 tags/request.') . '</p>';

      case 'cloudflare_purge.purge_by_prefix':
        return '<p>' . $this->t('Enter URL prefixes to purge all content under those paths. Available on all Cloudflare plans.') . '</p>';

      case 'cloudflare_purge.purge_by_hostname':
        return '<p>' . $this->t('Enter hostnames to purge all cached content for those domains. Available on all Cloudflare plans.') . '</p>';

      case 'cloudflare_purge.queued_tags':
        return '<p>' . $this->t('Cache tags queued for a Cloudflare purge are merged into a deduplicated set so repeated invalidations of the same tag never create duplicate purge requests. This page is most relevant when queueing is enabled for automatic purging; it also lets you review and drain any tags left over after queueing is turned off.') . '</p>';

      default:
        return '';
    }
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    try {
      $config = \Drupal::config('cloudflare_purge.settings');
      // Merge pending cache tags into batched, FIFO-ordered queue items before
      // the queue worker (which core's cron already invokes) runs. The queue
      // worker itself handles the actual purge processing.
      //
      // This runs unconditionally, not only while queueing is enabled: the
      // drain is a cheap no-op when the pending set is empty, and running it
      // always
      // means tags left pending when queueing is turned off (or on setups that
      // process the queue separately from cron) are still drained and purged
      // rather than stranded. The drainer takes its own lock, so overlapping
      // cron runs are safe.
      \Drupal::service('cloudflare_purge.pending_tag_drainer')->drain();
      // Log cron run if detailed logging is enabled.
      if ($config->get('logging_enabled') === TRUE) {
        \Drupal::logger('cloudflare_purge')->debug('Cloudflare Purge cron executed.');
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('cloudflare_purge')->error('Error during cron: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
