# Changelog

All notable changes to the Cloudflare Purge module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- Fatal error on Drupal 10: `Interface "Drupal\Core\Cache\CacheTagsPurgeInterface" not found` (#3614126). 3.1.2 wired the flush-time zone purge through `CacheTagsPurgeInterface`, which core only added in 11.2, while the module still declares `^10` support. Because PHP resolves an `implements` clause when the class is compiled, `CloudflareCacheTagInvalidator` — a tagged `cache_tags_invalidator` service, so loaded on any cache-tag invalidation — became fatal to autoload on Drupal 10, taking the site down. The flush purge is now triggered from `hook_cache_flush()` instead, which `drupal_flush_all_caches()` invokes at the same point in the flush on Drupal 10, 11 and 12 alike, so one code path serves every supported core version and the opt-in `purge_everything_on_flush` setting keeps working on all of them. Simply removing the interface (as an interim patch on the issue did) fixes Drupal 10 but silently disables the feature on 11.2+, because core dispatches the purge on an `instanceof` check.
- Verified by loading the class under a real Drupal 10.5.6 autoloader (fatal before, clean after) and by running the full suite on Drupal 11.4.5 and 12.0-dev.

### Changed
- `CloudflareCacheTagInvalidator::purge()` is renamed to `purgeEverythingOnFlush()`, since it is no longer a core interface method. Its behavior, guards and opt-in setting are unchanged. Internal to the module's own service; no configuration or API surface a site depends on changes.

### Added
- `CacheFlushPurgeWiringTest` kernel test asserting that a cache flush actually reaches the zone purge through the real container and hook dispatch, and that the invalidator does not implement a core-version-specific interface. The existing unit test calls the method directly, so it stayed green through both the Drupal 10 fatal and the "feature silently does nothing" regression; this test fails on either.

## [3.1.2] - 2026-07-18

### Added
- Deduplicated pending-tags merging for queued automatic purging (#3611366). Previously, every cache-tag invalidation created its own queue item immediately, so a client resaving the same item (or two separate items both invalidating a shared listing tag like `node_list`) piled up overlapping queue items — a real problem on hosting with infrequent cron (e.g. Upsun's 5-minute minimum). Invalidated tags are now merged into a deduplicated pending set (new `cloudflare_purge_pending_tags` table via `cloudflare_purge.pending_tags_store`) using a portable database `MERGE` (upsert); repeated invalidations of the same tag do not duplicate a row but do refresh its `last_invalidated` timestamp. `hook_cron()` drains all pending tags oldest-first (FIFO) into queue items batched at `CloudflarePurgeApi::MAX_BATCH_SIZE` via a new `cloudflare_purge.pending_tag_drainer` service, so however many overlapping invalidations occurred, the queue only ever gets the minimum number of items needed to cover the distinct pending tags. Draining runs on every cron regardless of the queue setting, so tags are never stranded, and the drain is guarded by a lock (renewed across large backlogs). The immediate (non-queue) purge path is unaffected.
- New "Queued Tags" review page (`/admin/config/cloudflare-purge/queued-tags`) showing the distinct pending tag count, the number of batched purge requests already in the Cloudflare purge queue, and the oldest pending tag's age, with a "Drain queued tags now" action that runs the same drain hook_cron() performs, without waiting for the next cron run. The page (and the drain action) also surface and clear tags left pending after queue mode is switched off, so nothing is stranded. Reachable by a purge operator or a Cloudflare Purge administrator, and linked from the admin menu.
- Unit and kernel test coverage for the new pending-tags store and drainer (`PendingTagsStoreTest`, `PendingTagDrainerTest`, `PendingTagDrainerFailureTest`), including regression tests pinning the "overlapping invalidations collapse into one purge" behavior, the re-invalidation TTL refresh, the freshest-in-batch queue timestamp, and the "queue backend rejects the item, pending rows survive" path.
- Queue-backlog advisory warnings (`cloudflare_purge.queue_backlog_advisor`), shown on both the Auto-Purge Settings and Queued Tags pages when automatic purging is queueing: if no rate limit is set and the estimated number of tags awaiting purge exceeds 1,000, or a rate limit is set but would take more than 10 minutes to clear the requests awaiting purge (queued items **plus** tags still pending a drain), a warning suggests checking the log for Cloudflare `429` responses and considering a full cache clear for one-off spikes. Either warning also recommends enabling "Discard queued items older than the configured TTL" when it's currently off. Deliberately advisory rather than enforced (no automatic cap was reintroduced) — the module surfaces the signal, the site builder decides. Unit test coverage in `QueueBacklogAdvisorTest`.

### Fixed
- A Cloudflare rate-limit response (`429`) during queue processing duplicated the queue item: the worker manually requeued the tags with the same retry count and then threw `SuspendQueueException`, but core's cron queue runner also releases the original claimed item back on that exception — leaving two copies of the same tags in the queue. The worker no longer requeues manually; it relies on core's release of the original (unclaimed, unchanged) item, so rate limiting no longer amplifies queue growth. Added a regression test (`testRateLimitSuspendsWithoutRequeueing`).
- Drain-time data loss: the drainer deleted pending rows immediately after `QueueInterface::createItem()`, but that call can return `FALSE` on a backend failure without throwing — silently discarding the tags (gone from pending, never queued, never purged). The drainer now deletes rows only after a queue item is confirmed created, and stops (leaving the rest pending for the next run) otherwise (`PendingTagDrainerFailureTest`). The queue worker's retry path now throws `RequeueException` when its retry item cannot be created, so core keeps the original claimed item instead of dropping the purge (`testRequeueFailureThrowsRequeueException`).
- Drain-race data loss: a tag re-invalidated between the drainer reading it and deleting it could be lost. Drained rows are now deleted only when their `last_invalidated` has not advanced past the drained batch's timestamp, so a re-invalidation in that window keeps the row for the next drain (`testDeleteDrainedKeepsReInvalidatedRows`).
- TTL discard could drop freshly-changed content: a tag re-invalidated shortly before draining kept its original queued time, so the queue worker's optional "discard items older than the TTL" check treated it as stale and skipped the purge, leaving the edge serving the newly-changed content. Tags now carry a `last_invalidated` timestamp (refreshed forward-only on every re-invalidation, so a delayed out-of-order request can't move it backwards), and a drained queue item is aged from the freshest invalidation in its batch, so one older tag can no longer drag a batch of fresh tags below the TTL. Regression coverage in `QueueMaxAgeAfterDrainTest`.
- Merge-based inserts avoid a PostgreSQL hazard: the previous "insert, catch the unique-key violation" path raised a constraint exception inside the entity-save transaction, which on PostgreSQL aborts that whole transaction and could roll back the content save. The Merge API keeps the caller's path free of that exception.
- "Purge Everything" no longer deletes the purge queue and pending set outright, which raced with tags being drained in while the purge was in flight (they could be lost from both layers). Instead it records the time of the full purge in state; the queue worker then skips any queued tag purge whose invalidation predates it — already covered by the full purge — while anything invalidated at or after that instant is still purged. Same "no redundant API calls after a full purge" outcome, without the race (`PurgeEverythingCoverageTest`, `testItemCoveredByFullPurgeIsDiscarded`).
- The "Queued Tags" route is now reachable by administrators as well as purge operators, so the link to it from the (admin-only) Auto-Purge Settings form no longer resolves to a 403. The manual "Drain" button is shown only when tags are actually pending a drain; when only already-batched queue items remain, the page explains the queue worker will process them on cron.

### Changed
- `cloudflare_purge_update_9007()` now inlines its table definition instead of reading the live `hook_schema()`, per Drupal's update-hook guidance, so a future schema change upgrades cleanly. The table carries a `last_invalidated` column; `cloudflare_purge_update_9008()` adds it defensively for any site that installed an earlier build of the table without it.

## [3.1.1] - 2026-06-26

### Fixed
- Successful purges were logged at the `info` level regardless of the "Enable detailed logging" setting, so operators who left it off still saw watchdog entries on every content save (e.g. ~16 messages when saving a tag-rich homepage). The redundant success log in the low-level API client is removed (the Purge service already records successful operations, gated by the setting), and the queue worker now honors `logging_enabled` for its success log. Errors and warnings are still always logged, and no service signatures change. (#3606632)
- Users with only the "Perform manual Cloudflare purge operations" permission could not reach the purge forms through the admin menu, because the top-level menu link resolved access via the credentials route (which requires "Administer Cloudflare Purge"). The section now uses a neutral landing route (`cloudflare_purge.admin_config`) gated by the union of the module's permissions, so the menu is reachable by anyone holding at least one Cloudflare Purge permission; each child link still enforces its own permission. (#3606643)

## [3.1.0] - 2026-06-14

### Added
- In-page tab-style navigation linking every Cloudflare Purge admin page (Credentials, Auto-Purge, the four purge forms, Purge Everything, Purge History, Plans & Limits), built from a single `CloudflarePurgeLinks` source of truth. Links the current user cannot access are hidden, the current page is the active tab, and the styling inherits the admin theme palette (light/dark) with an orange active underline.
- "Plans & Limits" reference page (`/admin/config/cloudflare-purge/limits`) listing Cloudflare purge limits and rate limits by plan, each with its authoritative source URL and last-verified date.
- Configurable purge-history display limit (`history_limit`, default 100, on the Auto-Purge Settings page) controlling how many recent entries the Purge History page shows.
- "Clear history" action on the Purge History page: a confirmation form that deletes only the module's own log entries (watchdog type `cloudflare_purge`), behind the `administer cloudflare purge` permission. The history page is now marked uncacheable and shows context-aware empty states (Database Logging disabled vs. detailed logging off vs. genuinely empty).
- Automatic batching of large purge requests across all entry points. The Purge service now owns splitting a list into Cloudflare-sized batches (`CloudflarePurgeApi::MAX_BATCH_SIZE`, 100): it sanitizes the whole list once (so de-duplication spans the entire input), splits it, runs each batch with a per-request rate-limit check, and returns an aggregate result. A new shared `ItemBatcher` helper is the single source of truth for the sanitize + chunk policy, used by the API, the Purge service, the cache-tag invalidator (queue mode), and the Drush command. **Behavior change:** the four purge forms (URL, Cache Tag, Prefix, Hostname) no longer reject submissions of more than 100 items — they accept any number and the service batches them, reporting "Purged X of Y" (and a partial-success warning if the rate limit is reached part-way, so the remainder can be resubmitted shortly). Help text updated accordingly. Drush and the auto-purge invalidator now hand the full list to the purge service instead of pre-chunking, so the batching/short-circuit logic applies across the whole command input (auto-purge queue items remain one batch each so they drain within the rate limit). A multi-batch run continues past a single failed batch (purging as much as possible) but short-circuits on operation-wide failures — a reached rate limit (the module's own limiter *or* a Cloudflare `429`) or an authentication error (`401/403`) — instead of firing every remaining batch; the aggregate result preserves that status code so callers can still classify it via `isRateLimited()` / `isAuthenticationError()`. A malformed Zone ID is now caught up front (reusing `CloudflarePurgeApi::ZONE_ID_PATTERN`) so it fails fast with one clear message rather than being re-rejected per batch.
- Unit test coverage for batching: `ItemBatcherTest` (sanitize/chunk), `PurgeBatchingTest` (single-batch verbatim result, multi-batch aggregation, cross-batch de-duplication, rate-limit partial), plus golden-master characterization tests (`BatchingCharacterizationTest`, `CacheTagInvalidatorBatchingTest`) that pinned the pre-refactor behavior.
- Cache-Tag header size management to prevent oversized headers (issue #3598876). On tag-rich pages (Layout Builder, Site Studio, large listings) the auto-purge `Cache-Tag` header could grow very large; while Cloudflare itself allows up to 16 KB, intermediate web servers and proxies often enforce lower (~8 KB) header limits, causing a 5xx with no PHP error logged. A new shared `CacheTagFormatter` service (single source of truth used by the response subscriber, the cache tag invalidator, and the purge service) now: (1) emits only purgeable entity-type tags in the header by default (`auto_purge_filter_header_tags`) — and this setting governs the purge path too, so the header and the tag-purge call always act on the same tag set (even when filtering is disabled), (2) optionally hashes tags identically on the header and purge sides so tag purges still match at Cloudflare (`auto_purge_hash_tags` + `auto_purge_hash_length`), and (3) caps the header at a configurable byte limit, dropping excess tags and logging a warning instead of emitting an over-limit header (`auto_purge_header_max_bytes`, default 7168). New config is added by `cloudflare_purge_update_9005()` with safe defaults (filtering on, hashing off).
- Unit test coverage for the cache tag formatter (`CacheTagFormatterTest`): filtering, prefix/hash wire transform, the header↔purge consistency invariant, and the size guard.
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
- Internal refactor for single source of truth (no behavior change): default config now ships from `config/install`; the config name and auth/storage constants moved to a dedicated `CloudflarePurgeSettings` class (the `CloudflarePurgeForm::SETTINGS`/`AUTH_*`/`STORAGE_*` constants remain as deprecated aliases); credential, auth-method and storage-method resolution extracted into a shared `CredentialResolver` service used by both the purge service and the settings form; textarea parsing across the four purge forms extracted into a `LineInputParser` helper; the Zone ID validation pattern is now a single `CloudflarePurgeApi::ZONE_ID_PATTERN` constant; the purge-history watchdog query moved out of `CloudflarePurgeController` into a dedicated `cloudflare_purge.history` service (`PurgeHistory`) so data access lives in one place (page output unchanged).

### Deprecated
- `CloudflarePurgeForm::SETTINGS`, `::AUTH_BEARER`, `::AUTH_LEGACY`, `::STORAGE_PLAIN`, `::STORAGE_KEY` are deprecated in favour of the equivalent constants on `\Drupal\cloudflare_purge\CloudflarePurgeSettings`. The old constants still work (they alias the new ones).

### Security
- Fixed information disclosure: exception messages no longer shown to users (logged instead)
- Added Zone ID format validation in credentials form to prevent invalid input
- Added explicit permission check in controller for defense-in-depth
- Added security warning when using plain text credential storage
- Documented rate limiting limitations for multi-server/load-balanced environments

### Fixed
- Purge requests larger than 100 items no longer silently drop the excess. The API layer previously capped a request at 100 items with `array_slice()` and discarded the rest with no log, turning an oversized request into a partial purge with no trace — and the four UI forms blocked the case entirely by rejecting more than 100 items. Large lists are now batched automatically (see Added) and the API's per-request cap is retained only as a defensive backstop that logs a warning if a caller ever over-feeds it.
- Oversized `Cache-Tag` header no longer causes 5xx errors on tag-rich pages (issue #3598876). Previously the response subscriber emitted every Drupal cache tag, raw and unbounded; a page with a few hundred tags produced a multi-KB header that the web server/CDN rejected. The header is now filtered, optionally hashed, and size-capped (see Added). This also resolves the header/purge asymmetry: the tags emitted in the header now match exactly the tags sent to the purge API.
- Purge by prefix no longer fails with Cloudflare error 1119 ("URI scheme provided in prefix … URI schemes must not be provided"). The UI form and Drush command previously *required* a full URL with a scheme, while Cloudflare *rejects* schemes — making prefix purging impossible. Prefixes are now accepted with or without a scheme and normalized to `host/path` (scheme stripped) before the API call, via a new shared `PrefixNormalizer` helper used by the form, the Drush command, and the purge service. Path-only values without a host are rejected with a clear message.
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
  - Purge by URL (automatically batched)
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
  - `drush cf-purge-all` - Purge entire cache
  - `drush cf-purge-url` - Purge specific URLs
  - `drush cf-purge-tags` - Purge by cache tags
  - `drush cf-purge-prefixes` - Purge by URL prefix
  - `drush cf-purge-hosts` - Purge by hostname
- Cache-Tag response header integration
- Purge history page with watchdog integration
- Configuration forms with validation
- Comprehensive logging
- Status page requirements check
- Support for Drupal 10 and 11
- PHP 8.1+ with strict typing
