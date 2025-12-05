# Cloudflare Purge

Comprehensive Cloudflare CDN cache purging directly from your Drupal site.

## Table of Contents

- [Introduction](#introduction)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Permissions](#permissions)
- [Drush Commands](#drush-commands)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [Maintainers](#maintainers)

## Introduction

This module provides integration with Cloudflare's API to purge cached content from your Drupal site. It uses Guzzle HTTP client (included in Drupal core) instead of cURL for all API calls, making it secure and reliable.

### When to Use This Module

Use this module when you need to purge Cloudflare cache and:

- You're using Cloudflare through a [Certified Cloudflare Hosting Partner](https://www.cloudflare.com/partners) but don't have direct access to the Cloudflare dashboard
- You need a simple, lightweight solution without additional dependencies
- You want command-line control via Drush commands
- You can't use the full Purge module ecosystem
- You want automatic cache invalidation when Drupal content changes (Enterprise)

## Features

- **Purge Everything** - Clear all cached content with confirmation dialog
- **Purge by URL** - Clear one or more specific URLs (up to 30 per request)
- **Purge by Cache Tag** - Clear content by cache tags (Enterprise plan required)
- **Purge by Prefix** - Clear all content under URL prefixes (Enterprise plan required)
- **Purge by Hostname** - Clear all content for specific hostnames (Enterprise plan required)
- **Automatic Cache Tag Purging** - Automatically purge Cloudflare when Drupal cache tags are invalidated
- **Queue-Based Processing** - Queue purge requests for batch processing during cron
- **Purge History** - View recent purge operations from the admin UI
- **Bearer Token Authentication** - Modern, secure API authentication (recommended)
- **Legacy API Key Authentication** - Support for Email + API Key method
- **Key Module Integration** - Secure credential storage with the Key module (optional)
- **Plain Text Storage** - Quick setup with database storage
- **Settings.php Override** - Store credentials securely in settings.php for production
- **Smart Detection** - Automatically detects existing configuration on upgrade
- **Drush 13 Commands** - Command-line cache purging for all operations
- **Credential Validation** - Forms disabled until credentials are configured
- **CSRF Protection** - Built-in security for all routes
- **Permissions System** - Granular access control
- **Free Tier Compatible** - Works with Cloudflare's free plan
- **Responsive UI** - Modern two-column layout with Cloudflare branding

## Requirements

- **Drupal**: 10.3+ or 11+
- **PHP**: 8.1 or higher
- **Cloudflare Account**: Free tier or higher
- **Cloudflare API Access**: Zone ID and either:
  - Bearer Token with Cache Purge permission (recommended), OR
  - Global API Key + Account Email

### Optional

- **[Key Module](https://www.drupal.org/project/key)** - For enhanced credential security (recommended for production)
- **Cloudflare Enterprise Plan** - Required for tag, prefix, and hostname purging

## Installation

Install as you would normally install a contributed Drupal module. Visit the 
[Drupal.org documentation](https://www.drupal.org/docs/extending-drupal/installing-modules) 
for further information.

```bash
composer require drupal/cloudflare_purge
drush en cloudflare_purge
```

## Configuration

### Via Web Interface

1. Navigate to **Administration** » **Configuration** » **Cloudflare Purge**  
   (`/admin/config/cloudflare-purge/credentials`)

2. **Choose Authentication Method:**

   - **Bearer Token** (recommended) - Modern API Token authentication
   - **Legacy API Key/Email** - Email + Global API Key method

3. **Choose Storage Method:**

   - **Plain text input** - Store credentials in Drupal configuration (database)
   - **Secure key input** - Use the Key module for enhanced security (requires Key module)

4. Enter your credentials based on your selections

5. Save configuration

#### Authentication: Bearer Token (Recommended)

This is the most secure method with granular permissions.

1. Select **"Bearer Token"** as your authentication method
2. Choose your storage method
3. Enter your **Zone ID**
4. Enter your **Bearer Token** (or select from Key module if using secure storage)
5. Save configuration

**Creating a Bearer Token:**

1. Log into your Cloudflare dashboard
2. Go to **Profile** » **API Tokens**
3. Click **Create Token**
4. Use the **"Edit zone DNS"** template or create a custom token
5. Set permissions:
   - **Zone** » **Cache Purge** » **Purge**
6. Select your specific zone(s)
7. Click **Continue to summary** » **Create Token**
8. Copy the token (you won't see it again!)

#### Authentication: Legacy API Key

Less secure, grants full account access. Use only if Bearer Token is not available.

1. Select **"Legacy API Key/Email"** as your authentication method
2. Choose your storage method
3. Enter your **Zone ID**
4. Enter your **Email** (Cloudflare account email)
5. Enter your **API Key** (Global API Key)
6. Save configuration

#### Storage: Secure Key Input (Recommended)

For production environments, use the Key module to store credentials outside the database:

1. Install and enable the [Key module](https://www.drupal.org/project/key):
   ```bash
   composer require drupal/key
   drush en key
   ```

2. Create keys for your credentials:
   - Navigate to **Configuration** » **System** » **Keys** (`/admin/config/system/keys`)
   - Create a key for Zone ID
   - Create a key for Bearer Token (or Email/API Key for legacy)
   - Recommended key provider: "Configuration" for development, "File" for production

3. In Cloudflare Purge settings:
   - Select **"Secure key input"** as storage method
   - Select your keys from the dropdown menus
   - Save configuration

#### Storage: Plain Text Input

For development or quick setup:

1. Select **"Plain text input"** as storage method
2. Enter credentials directly in the text fields
3. Save configuration

**Note:** Plain text credentials are stored in Drupal's configuration system (database). For production, use either Secure key input or settings.php override.

**Finding Your Credentials:**

- **Zone ID**: Found in your Cloudflare dashboard under the domain overview page (right sidebar). It's a 32-character hexadecimal string.
- **Global API Key**: Profile » API Tokens » View Global API Key

### Via settings.php (Recommended for Production)

For enhanced security, store credentials in `settings.php`. When a **complete set** of credentials is detected in settings.php, the configuration form will be automatically disabled.

**Important:** You must provide a complete set of credentials:
- Bearer Token method: `zone_id` + `bearer_token`
- Legacy method: `zone_id` + `email` + `authorization`

Partial credentials will trigger a warning but allow form configuration.

#### Bearer Token (Recommended)

```php
$settings['cloudflare_purge_credentials'] = [
  'zone_id' => 'your-zone-id-here',
  'bearer_token' => 'your-bearer-token-here',
];
```

#### Legacy API Key

```php
$settings['cloudflare_purge_credentials'] = [
  'zone_id' => 'your-zone-id-here',
  'email' => 'your-email@example.com',
  'authorization' => 'your-global-api-key-here',
];
```

#### Environment-Specific Configuration

```php
// Load from environment variables
if (isset($_ENV['CLOUDFLARE_ZONE_ID'])) {
  $settings['cloudflare_purge_credentials'] = [
    'zone_id' => $_ENV['CLOUDFLARE_ZONE_ID'],
    'bearer_token' => $_ENV['CLOUDFLARE_BEARER_TOKEN'],
  ];
}
```

**Benefits of settings.php:**
- Credentials never stored in database
- Not exported with configuration
- Perfect for multi-environment workflows (dev/staging/production)
- Highest security level

### Auto-Purge Settings (Optional)

Navigate to **Configuration** » **Cloudflare Purge** » **Auto-Purge Settings**  
(`/admin/config/cloudflare-purge/settings`)

Configure automatic cache purging when Drupal cache tags are invalidated:

- **Enable automatic purging** - Purge Cloudflare when content changes
- **Entity types** - Select which entities trigger automatic purges (node, taxonomy_term, media, etc.)
- **Queue processing** - Use queue for high-traffic sites (recommended)
- **Tag prefix** - Add prefix for multi-site setups sharing a Cloudflare zone
- **Rate limiting** - Limit requests per minute to avoid API limits
- **Detailed logging** - Enable for debugging

**Note:** Automatic tag-based purging requires a Cloudflare Enterprise plan.

## Usage

### Web Interface

Navigate to **Configuration** » **Cloudflare Purge**:

| Menu Item | Path | Description |
|-----------|------|-------------|
| Credentials | `/admin/config/cloudflare-purge/credentials` | Configure API credentials |
| Auto-Purge Settings | `/admin/config/cloudflare-purge/settings` | Configure automatic purging |
| Purge by URL | `/admin/config/cloudflare-purge/purge-url` | Purge 1-30 URLs |
| Purge by Cache Tag | `/admin/config/cloudflare-purge/purge-tag` | Purge by cache tags (Enterprise) |
| Purge by Prefix | `/admin/config/cloudflare-purge/purge-prefix` | Purge by URL prefix (Enterprise) |
| Purge by Hostname | `/admin/config/cloudflare-purge/purge-hostname` | Purge by hostname (Enterprise) |
| Purge Everything | `/admin/config/cloudflare-purge/purge-everything` | Clear entire cache |
| Purge History | `/admin/config/cloudflare-purge/history` | View recent operations |

**Note:** Purge forms display a warning and the submit button is disabled until credentials are configured. Configure credentials first, then purge operations will be available.

## Permissions

Navigate to **Administration** » **People** » **Permissions** to configure:

| Permission | Machine Name | Description |
|------------|--------------|-------------|
| Administer Cloudflare Purge | `administer cloudflare purge` | Configure credentials and settings |
| Perform manual Cloudflare purge operations | `cloudflare purge` | Purge by URL, tag, prefix, hostname |
| Purge entire Cloudflare cache | `cloudflare purge everything` | Clear all cached content |
| View Cloudflare purge history | `view cloudflare purge history` | View recent purge operations |

## Drush Commands

### Purge All Cache

```bash
# Full command
drush cloudflare:purge-all

# Aliases
drush cf-purge-all
drush cfpa
```

Purges all cached content from Cloudflare.

### Purge by URL

```bash
# Single URL
drush cloudflare:purge-url https://example.com/page

# Multiple URLs (space-separated)
drush cf-purge-url https://example.com/a https://example.com/b

# Multiple URLs (comma-separated)
drush cfpu "https://example.com/a,https://example.com/b"
```

Purges specific URLs from Cloudflare cache (up to 30 per request, automatically batched).

### Purge by Cache Tag (Enterprise)

```bash
# Single tag
drush cloudflare:purge-tags node-123

# Multiple tags (space-separated)
drush cf-purge-tags node-123 taxonomy-term-45

# Multiple tags (comma-separated)
drush cfpt "product-1,product-2,product-3"
```

Purges content by cache tags (up to 30 per request).

### Purge by Prefix (Enterprise)

```bash
# Single prefix
drush cloudflare:purge-prefixes https://example.com/blog/

# Multiple prefixes
drush cf-purge-prefixes "https://example.com/products/,https://example.com/images/"

# Short alias
drush cfpp https://example.com/blog/
```

Purges all content under URL prefixes (up to 30 per request).

### Purge by Hostname (Enterprise)

```bash
# Single hostname
drush cloudflare:purge-hostnames www.example.com

# Multiple hostnames
drush cf-purge-hosts "cdn.example.com,images.example.com"

# Short alias
drush cfph cdn.example.com
```

Purges all content for specific hostnames (up to 30 per request).

### Check Status

```bash
drush cloudflare:status
drush cf-status
drush cfs
```

Shows configuration status and whether credentials are configured.

**Example Output:**

```bash
$ drush cloudflare:purge-url https://example.com/node/1
 [success] Successfully purged 1 of 1 URL(s).

$ drush cloudflare:status
 [success] Cloudflare credentials are configured.

$ drush cloudflare:purge-tags node-123 node-456
 [success] Successfully purged 2 of 2 tag(s).
```

**Drush without credentials:**

```bash
$ drush cloudflare:purge-all
 [error] Cloudflare credentials are not configured.
 [notice] Configure credentials at: /admin/config/cloudflare-purge/credentials
 [notice] Or run: drush cloudflare:status
```

## Security

This module implements several security measures:

- **CSRF Token Protection**: All purge routes use Drupal's built-in CSRF token validation
- **Confirmation Form**: "Purge Everything" requires explicit confirmation
- **Credential Validation**: Forms disabled until credentials are configured
- **Permission-Based Access**: Granular permission system controls who can purge cache
- **Guzzle HTTP Client**: Uses Drupal's secure HTTP client (Guzzle) instead of cURL
- **Settings.php Override**: Credentials can be stored outside the database
- **Key Module Integration**: Optional secure credential storage
- **Bearer Token Support**: Modern authentication with limited scope permissions
- **Input Validation**: URL, tag, prefix, and hostname validation prevents malformed requests
- **Zone ID Validation**: Validates 32-character hexadecimal format
- **Error Logging**: Failed API calls are logged to watchdog for debugging
- **Rate Limit Detection**: Detects and reports rate limit errors
- **Clean Uninstall**: Configuration deleted on module uninstall

## Troubleshooting

### "Cloudflare credentials are not configured" Error

**Problem:** Credentials are not configured or are empty.

**Solution:** 
1. Verify Zone ID and either Bearer Token or Email/API Key are entered in the configuration form
2. If using `settings.php`, verify you have a complete set of credentials
3. Check that field names match exactly: `zone_id`, `email`, `authorization`, or `bearer_token`

### Form Submit Button is Disabled

**Problem:** Cannot click the purge button.

**Solution:**
1. Configure credentials at `/admin/config/cloudflare-purge/credentials`
2. Save valid credentials
3. Button will be enabled once credentials are saved

### Incomplete Credentials Warning

**Problem:** Warning message about incomplete credentials in settings.php.

**Solution:**
You have partial credentials in `settings.php` but not a complete set. Either:

1. **Complete the configuration in settings.php:**
   - Bearer Token: Add both `zone_id` AND `bearer_token`
   - Legacy: Add `zone_id`, `email`, AND `authorization`

2. **Remove partial configuration from settings.php:**
   - Use the web form instead
   - Form will remain enabled until settings.php has a complete set

### Key Module Not Showing

**Problem:** "Secure key input" option not available.

**Solution:**
1. Install the Key module:
   ```bash
   composer require drupal/key
   drush en key
   ```
2. Clear cache: `drush cr`
3. Refresh the configuration form

### API Call Fails (Check Drupal Log)

**Problem:** HTTP request to Cloudflare API failed.

**Solution:**
1. Check Recent Log Messages: **Administration** » **Reports** » **Recent log messages**
2. Look for entries from `cloudflare_purge` channel
3. Common issues:
   - Invalid Zone ID (must be 32-character hex string)
   - Incorrect API credentials
   - Bearer Token missing **Cache Purge** permission
   - Rate limit exceeded

### Tag/Prefix/Hostname Purging Fails

**Problem:** Purge by tag, prefix, or hostname returns an error.

**Solution:**
1. These features require a Cloudflare Enterprise plan
2. Check your Cloudflare plan supports the operation
3. Verify the format is correct:
   - Tags: No spaces (e.g., `node-123`)
   - Prefixes: Full URLs (e.g., `https://example.com/blog/`)
   - Hostnames: No protocol (e.g., `cdn.example.com`)

### Auto-Purge Not Working

**Problem:** Content changes don't trigger Cloudflare purge.

**Solution:**
1. Verify auto-purge is enabled at `/admin/config/cloudflare-purge/settings`
2. Check entity types are selected
3. Tag-based purging requires Enterprise plan
4. If using queue, ensure cron is running: `drush cron`
5. Enable detailed logging to debug

### Rate Limits

Cloudflare enforces rate limits on purge requests:

- **Free Plan**: 1,000 API calls per day
- **Pro Plan**: 500 requests per hour  
- **Business Plan**: 10 requests per second
- **Enterprise Plan**: Higher limits

If you exceed the rate limit, wait and try again.

### Bearer Token Permissions

Ensure your Bearer Token has the correct permission:

1. Log into Cloudflare dashboard
2. Go to **Profile** » **API Tokens**
3. Find your token and click **Edit**
4. Verify: **Zone** » **Cache Purge** » **Purge** is enabled
5. Verify the token is applied to your specific zone

## Cloudflare API Limits

| Limit Type | Value |
|------------|-------|
| URLs per request | 30 |
| Cache tags per request | 30 |
| Prefixes per request | 30 |
| Hostnames per request | 30 |
| API calls (Free tier) | 1,000/day |

The module automatically batches larger requests when using Drush commands.

## API Documentation

For more information about Cloudflare's Purge Cache API:

- [Cloudflare API: Purge Cache](https://developers.cloudflare.com/api/operations/zone-purge)
- [Getting Started with Cloudflare API](https://developers.cloudflare.com/fundamentals/api/)
- [Cache Purge Documentation](https://developers.cloudflare.com/cache/how-to/purge-cache/)

## Maintainers

- **Alaa Haddad** - [alaahaddad](https://www.drupal.org/u/flashwebcenter)
  - Website: [alaahaddad.com](https://www.alaahaddad.com)
  - Company: [Flash Web Center](https://www.flashwebcenter.com)

## Supporting Organizations

- [Flash Web Center](https://www.flashwebcenter.com)
- [Drupal Care](https://www.drupalcare.com)

## Project Links

- **Project Page**: https://www.drupal.org/project/cloudflare_purge
- **Issue Queue**: https://www.drupal.org/project/issues/cloudflare_purge
- **Git Repository**: https://git.drupalcode.org/project/cloudflare_purge

## Legal Disclaimer

This module has **not** been built, maintained, or supported by Cloudflare, Inc.

This is an independent open-source project with no official association with Cloudflare, Inc. The module uses Cloudflare's public API under their terms of service.

Cloudflare® is a registered trademark of Cloudflare, Inc.

## License

This project is licensed under the GNU General Public License v2.0 or later.
