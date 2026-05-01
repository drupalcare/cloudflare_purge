# Changelog

All notable changes to the Cloudflare Purge module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Optional TTL-based discard for queued purge items (`queue_max_age_enabled` + `queue_max_age`). When enabled, the queue worker drops items older than the configured age (default 900s) so a rate-limited queue cannot grow without bound. Disabled by default; intended to match the site's Cloudflare "Cache Everything" TTL since the edge would have expired the content anyway. Discard events are logged at the `debug` level to avoid flooding watchdog on backlogged sites. Requires a cache rebuild after upgrade because the queue worker now depends on `config.factory`.
- Unit test coverage for the queue worker's TTL discard branch (`CloudflarePurgeQueueWorkerTest`).
- Rate limiting implementation that actually uses the `rate_limit_enabled` and `rate_limit_per_minute` configuration settings
- Proper retry tracking in queue worker with incremented retry counter
- Retries field initialization when queueing cache tags
- Credential validation check before auto-purge to prevent unnecessary errors

### Changed
- Updated documentation to reflect Cloudflare's April 2025 policy change: all purge methods (tag, prefix, hostname) now available on all plans including Free tier
- Updated UI warnings and help text to show accurate rate limits for Free tier (5 requests/minute)
- Queue worker now properly tracks retry attempts by creating new queue items with incremented retry count
- Forms now use `Url::fromRoute()` instead of hard-coded paths for credential links
- Made `CloudflarePurgeForm` class final for consistency with other form classes
- Controller and module file now use `Url::fromRoute()` instead of hard-coded paths
- Replaced error-suppressed `@unserialize()` with proper try-catch block

### Security
- Fixed information disclosure: exception messages no longer shown to users (logged instead)
- Added Zone ID format validation in credentials form to prevent invalid input
- Added explicit permission check in controller for defense-in-depth
- Added security warning when using plain text credential storage
- Documented rate limiting limitations for multi-server/load-balanced environments

### Fixed
- Queue worker now accepts numeric-string `timestamp` and `retries` payload fields. Previously the strict `is_int()` check silently reset the age to zero on queue backends that serialize integers as strings (e.g. some Redis-backed queues), which would have defeated the new TTL discard for those items.
- Unit tests updated to match current API methods (`purgeEverything`, `purgeByUrls`, etc.)
- Unit tests updated to test multi-URL form validation correctly
- Queue worker retry counter now properly increments on failed attempts
- Auto-purge now checks for configured credentials before attempting to purge
- Controller history page now handles malformed serialized data gracefully

### Removed
- Unused `purgeAll()` controller method and related code
- Unused service dependencies from controller (RequestStack, Purge)

## [1.0.0] - Initial Release

### Added
- Support for all Cloudflare purge methods:
  - Purge Everything
  - Purge by URL (up to 30 URLs per request)
  - Purge by Cache Tags (all plans)
  - Purge by URL Prefix (all plans)
  - Purge by Hostname (all plans)
- Two authentication methods:
  - Bearer Token (recommended)
  - Legacy API Key + Email
- Three-tier credential storage:
  - settings.php override (highest priority, recommended for production)
  - Key module integration (secure storage)
  - Plain text configuration (backward compatibility)
- Automatic cache tag invalidation via CacheTagsInvalidatorInterface
- Queue support for deferred/batched purge operations
- Drush 13 commands with PHP attributes:
  - `drush cf:purge-all` - Purge entire cache
  - `drush cf:purge-url` - Purge specific URLs
  - `drush cf:purge-tag` - Purge by cache tags
  - `drush cf:purge-prefix` - Purge by URL prefix
  - `drush cf:purge-hostname` - Purge by hostname
- Cache-Tag response header integration
- Purge history page with watchdog integration
- Configuration forms with validation
- Comprehensive logging
- Status page requirements check
- Support for Drupal 10 and 11
- PHP 8.1+ with strict typing
