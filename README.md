# Cloudflare Purge

Manually purge cached resources from Cloudflare directly from your Drupal site.

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

Use this module when you need to manually purge Cloudflare cache and:

- You're using Cloudflare through a [Certified Cloudflare Hosting Partner](https://www.cloudflare.com/partners) but don't have direct access to the Cloudflare dashboard
- You need a simple, lightweight solution without additional dependencies
- You want command-line control via Drush commands
- You can't use the full Purge module ecosystem

## Features

- **Purge Everything** - Clear all cached content from Cloudflare
- **Purge by URL** - Clear specific URLs or resources
- **Bearer Token Authentication** - Modern, secure API authentication (recommended)
- **Legacy API Key Authentication** - Support for Email + API Key method
- **Settings.php Override** - Store credentials securely in settings.php
- **Drush 13 Commands** - Command-line cache purging with modern PHP attributes
- **CSRF Protection** - Built-in security for all routes
- **Permissions System** - Granular access control
- **Free Tier Compatible** - Works with Cloudflare's free plan

## Requirements

- **Drupal**: 10.2+ or 11+
- **PHP**: 8.1 or higher
- **Cloudflare Account**: Free tier or higher
- **Cloudflare API Access**: Zone ID and either:
  - Bearer Token with Cache Purge permission (recommended), OR
  - Global API Key + Account Email

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

1. Navigate to **Administration** » **Configuration** » **Cloudflare Purge** » **Credentials**  
   (`/admin/config/cloudflare-purge/credentials`)

2. Choose your authentication method:

#### Option A: Bearer Token (Recommended)

This is the most secure method with granular permissions.

1. Check **"Use Bearer Token"**
2. Enter your **Zone ID**
3. Enter your **Bearer Token**
4. Save configuration

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

#### Option B: Global API Key (Legacy)

Less secure, grants full account access. Use only if Bearer Token is not available.

1. Uncheck **"Use Bearer Token"**
2. Enter your **Zone ID**
3. Enter your **Email** (Cloudflare account email)
4. Enter your **Authorization** (Global API Key)
5. Save configuration

**Finding Your Credentials:**

- **Zone ID**: Found in your Cloudflare dashboard under the domain overview page (right sidebar)
- **Global API Key**: Profile » API Tokens » View Global API Key

### Via settings.php (Recommended for Production)

For enhanced security, store credentials in `settings.php` to prevent them from being changed via the UI:

```php
// Bearer Token method (recommended)
$settings['cloudflare_purge_credentials'] = [
  'use_bearer_token' => TRUE,
  'zone_id' => 'your-zone-id-here',
  'bearer_token' => 'your-bearer-token-here',
];

// OR legacy API Key method
$settings['cloudflare_purge_credentials'] = [
  'use_bearer_token' => FALSE,
  'zone_id' => 'your-zone-id-here',
  'email' => 'your-email@example.com',
  'authorization' => 'your-global-api-key-here',
];
```

When credentials are set in `settings.php`, the corresponding form fields will be disabled in the admin UI.

## Usage

### Web Interface

#### Purge Everything

Navigate to **Administration** » **Configuration** » **Cloudflare Purge** » **Purge Everything**  
(`/admin/config/cloudflare-purge/purge-all`)

Click the link to purge all cached content from Cloudflare.

**Warning:** This will clear ALL cached files, including static assets. Your origin server will need to serve previously cached content again.

#### Purge by URL

Navigate to **Administration** » **Configuration** » **Cloudflare Purge** » **Purge by URL**  
(`/admin/config/cloudflare-purge/purge-url`)

Enter a fully qualified URL to purge:
- `https://example.com`
- `https://example.com/foo`
- `https://example.com/bar.jpg`

## Permissions

Navigate to **Administration** » **People** » **Permissions** to configure:

- **Purge Cloudflare Cache** (`cloudflare purge`)  
  Allows users to purge cache via UI and view purge pages
  
- **Administer Cloudflare Purge** (`administer cloudflare purge`)  
  Allows users to configure Cloudflare credentials (Zone ID, tokens, etc.)

## Drush Commands

### Purge All Cache

```bash
# Full command
drush cloudflare:purge-all

# Alias
drush cf-purge-all

# Short alias
drush cfpa
```

Purges all cached content from Cloudflare.

### Purge Specific URL

```bash
# Full command
drush cloudflare:purge-url https://example.com/page

# Alias
drush cf-purge-url https://example.com/image.jpg

# Short alias
drush cfpu https://example.com/path/to/file.css
```

Purges a specific URL from Cloudflare cache.

**Example Output:**

```bash
$ drush cloudflare:purge-url https://example.com/node/1
 [success] Successfully purged URL from Cloudflare cache: https://example.com/node/1
```

## Security

This module implements several security measures:

- **CSRF Token Protection**: All purge routes use Drupal's built-in CSRF token validation
- **Permission-Based Access**: Granular permission system controls who can purge cache
- **Guzzle HTTP Client**: Uses Drupal's secure HTTP client (Guzzle) instead of cURL
- **Settings.php Override**: Credentials can be stored outside the database
- **Bearer Token Support**: Modern authentication with limited scope permissions
- **Input Validation**: URL validation prevents malformed purge requests
- **Error Logging**: Failed API calls are logged to watchdog for debugging

## Troubleshooting

### "Please insert Cloudflare credentials" Error

**Problem:** Credentials are not configured or are empty.

**Solution:** 
1. Verify Zone ID, Email/API Key or Bearer Token are entered in the configuration form
2. If using `settings.php`, verify the array keys match exactly: `zone_id`, `email`, `authorization`, or `bearer_token`

### API Call Fails (Check Drupal Log)

**Problem:** HTTP request to Cloudflare API failed.

**Solution:**
1. Check Recent Log Messages: **Administration** » **Reports** » **Recent log messages**
2. Look for entries from `cloudflare_purge` channel
3. Common issues:
   - Invalid Zone ID
   - Incorrect API credentials
   - Bearer Token missing **Cache Purge** permission
   - Rate limit exceeded (Free: 5 requests/minute)

### Rate Limits

Cloudflare enforces rate limits on purge requests:

- **Free Plan**: 5 requests per minute
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
