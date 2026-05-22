# Testing Strategy

## Watchdog log message rendering (Purge History)

### The bug class

Drupal stores log messages in `watchdog` as a **template** (`message`) plus **serialized placeholders** (`variables`). Display code must substitute them (e.g. `@count`, `@payload`). Wrapping the whole template in `$this->t('@message', ['@message' => $row->message])` leaves inner placeholders unsubstituted.

### Automated checks

| Check | Command | When to run |
|-------|---------|-------------|
| Static scan (no Drupal required) | `composer check-log-rendering` or `php scripts/check-log-message-rendering.php` | Before every commit; add to CI |
| Kernel test | From Drupal root: `php core/scripts/run-tests.sh --php /path/to/php --class 'Drupal\Tests\cloudflare_purge\Kernel\CloudflarePurgeHistoryTest'` | With other module tests |

The scanner flags:

- `$this->t('@message', ...)` / `t('@message', ...)` anti-patterns
- PHP files that query `watchdog` and use `$row->message` without `formatWatchdogMessage()`, `FormattableMarkup`, or dblog-style formatting

Use `CloudflarePurgeController::formatWatchdogMessage()` for any new code that reads from `watchdog`.

### CI suggestion

Add a job step before PHPUnit:

```yaml
check_log_rendering:
  script:
    - cd web/modules/contrib/cloudflare_purge && php scripts/check-log-message-rendering.php
```

(Adjust path to match your project layout.)

---

## Skip Uncacheable Responses (max-age = 0)

## Problem

Edit pages (forms) in Drupal implement `CacheableResponseInterface` but aren't meant to be cached. They can have hundreds of cache tags (one for every form element/field), and when all those get joined into a single `Cache-Tag` HTTP response header, it exceeds the web server's header size limit (typically ~8KB), causing HTTP 500 errors.

## Fix

The event subscriber now checks `getCacheMaxAge() === 0` and skips uncacheable responses entirely. Edit pages, admin pages, and other non-cacheable responses won't get the `Cache-Tag` header added at all.

```php
$cacheableMetadata = $response->getCacheableMetadata();

if ($cacheableMetadata->getCacheMaxAge() === 0) {
    return;
}
```

---

## Unit Test Scenarios

| # | Scenario | Auto Purge | Response Type | Max-Age | Tags | Expected Result |
|---|----------|------------|---------------|---------|------|-----------------|
| 1 | Cacheable response with tags | Enabled | CacheableResponseInterface | > 0 | `['node:1', 'node_list']` | `Cache-Tag` header added |
| 2 | Uncacheable response (edit page) | Enabled | CacheableResponseInterface | 0 | `['node:1', 'form:edit']` | No `Cache-Tag` header, early return |
| 3 | Non-cacheable response object | Enabled | Regular Response (not CacheableResponseInterface) | N/A | N/A | No `Cache-Tag` header, early return |
| 4 | Auto-purge disabled | Disabled | CacheableResponseInterface | > 0 | `['node:1']` | No `Cache-Tag` header, early return |
| 5 | Empty tags after filtering | Enabled | CacheableResponseInterface | > 0 | `[]` | No `Cache-Tag` header, early return |
| 6 | Tags with prefix configured | Enabled | CacheableResponseInterface | > 0 | `['node:1']` | `Cache-Tag: myprefix_node:1` |
| 7 | Sub-request (not main) | Enabled | CacheableResponseInterface | > 0 | `['node:1']` | No `Cache-Tag` header, early return |

---

## Local Testing (Manual)

### Prerequisites

- A Drupal site with the `cloudflare_purge` module installed
- `auto_purge_enabled` set to `TRUE` in module settings
- `debug_cacheability_headers` set to `false` in `services.yml` (default)

### Step 1: Verify Cache-Tag Header on Public Pages

```bash
curl -I https://your-site.local/node/1
```

**Expected:** Response includes a `Cache-Tag` header with comma-separated tags like:

```
Cache-Tag: node:1,node_list,user:1
```

### Step 2: Verify No Cache-Tag Header on Edit Pages

```bash
curl -I https://your-site.local/node/1/edit
```

**Expected:** Response does NOT include a `Cache-Tag` header. Page loads normally (no 500 error).

### Step 3: Verify No Cache-Tag Header on Admin Pages

```bash
curl -I https://your-site.local/admin/content
curl -I https://your-site.local/admin/structure
```

**Expected:** No `Cache-Tag` header on admin pages. Pages load normally.

### Step 4: Verify Auto-Purge Still Fires on Node Save

1. Open Drupal admin > Reports > Recent log messages (`/admin/reports/dblog`)
2. Edit and save a node at `/node/1/edit`
3. Check the logs for a Cloudflare purge entry

**Expected:** A log entry confirming cache tags were purged (e.g., `node:1`).

---

## Staging Environment Testing (With Cloudflare)

### Prerequisites

- A staging site behind Cloudflare
- Valid Cloudflare API credentials configured in the module
- `auto_purge_enabled` set to `TRUE`
- `debug_cacheability_headers` set to `false`

### Test 1: Edit Page Loads Without 500

1. Navigate to `/node/X/edit` on the staging site
2. **Expected:** Page loads normally without HTTP 500

### Test 2: Auto-Purge Works on Node Save

1. Visit `/node/X` in an incognito browser (to prime Cloudflare cache)
2. Check response headers for `cf-cache-status: HIT` (confirms Cloudflare is caching)
3. Edit and save the node at `/node/X/edit`
4. Visit `/node/X` again in incognito
5. **Expected:** `cf-cache-status: MISS` or `EXPIRED` (confirms cache was purged)

### Test 3: Cache-Tag Header Present on Public Pages

```bash
curl -sI https://staging.example.com/node/1 | grep -i cache-tag
```

**Expected:** `Cache-Tag` header is present with Drupal cache tags.

### Test 4: Cache-Tag Header Absent on Edit Pages

```bash
curl -sI -b "session_cookie=..." https://staging.example.com/node/1/edit | grep -i cache-tag
```

**Expected:** No `Cache-Tag` header in response.

### Test 5: Stress Test with Many Fields

1. Create a content type with 50+ fields
2. Visit the edit page for a node of that type
3. **Expected:** Page loads without 500 (previously would fail due to header size)

---

## Regression Checklist

- [ ] Edit pages (`/node/X/edit`) load without HTTP 500
- [ ] Admin pages (`/admin/*`) load without HTTP 500
- [ ] Public node pages (`/node/X`) include `Cache-Tag` header
- [ ] Taxonomy term pages include `Cache-Tag` header
- [ ] Auto-purge fires when a node is saved
- [ ] Auto-purge fires when a taxonomy term is saved
- [ ] Tag prefix is applied correctly when configured
- [ ] Module works with `debug_cacheability_headers: false`
- [ ] Module works with `debug_cacheability_headers: true` (backward compatibility)
- [ ] Queue mode works (`auto_purge_use_queue: true`)
- [ ] Immediate mode works (`auto_purge_use_queue: false`)

---

## Key Drupal Concepts

| Concept | Description |
|---------|-------------|
| `CacheableResponseInterface` | Interface indicating a response carries cache metadata. Most Drupal responses implement this, **including edit pages**. |
| `getCacheMaxAge()` | Returns the max-age for the response. `0` means "do not cache" (used by forms, admin pages). `-1` (Cache::PERMANENT) means "cache indefinitely". |
| `getCacheTags()` | Returns an array of cache tags associated with the response. Used by Drupal's cache invalidation system. |
| `Cache-Tag` header | Custom HTTP header consumed by Cloudflare for tag-based cache purging. Must stay within web server header size limits (~8KB). |
