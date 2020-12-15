# INTRODUCTION
Clear Cloudflare caches from drupal site uses Guzzle HTTP client instead of cURL

This module provides any drupal user with permissions to manually purge
Cloudflare cache.  Purge Cloudflare cache for any drupal site/domain.

How is Cloudflare Purge secure?
It uses custom CSRF token in Drupal.  It also uses Guzzle HTTP client request
for all API calls. So it's not vulnerable to CSRF exploits and avoids security
risk.

When can I use this module?

1- If your Drupal site is activated with Cloudflare through 
<a target="_blank" href="https://www.cloudflare.com/partners/">
	Certified Cloudflare Hosting Partner</a>.

2- If your Drupal site is activated with Cloudflare through the cloudflare module and the
 purge module is not installed.

# DEPENDENCIES
None

# INSTALLATION
Install as any other contributed module.

# REQUIREMENTS
1- It only works with drupal sites use cloudflare.
2- You must have Cloudflare Zone ID and Authorization.

# CONFIGURATION
Where can I enter the Zone ID and Authorization?
Go to your-site/admin/config/cloudflare-purge-form

# Note
I added an extra feature for developers/site admin who like to add Cloudflare's
credentials in settings.php and can't be changed in admin form.
The two fields will be disabled.


Copy this code in your settings.php and add your (Zone ID and Authorization)
$settings['cloudflare_purge_credentials'] = [
'zone_id' => 'insert-cf-zone-id',
'authorization' => 'insert-cf-authorization'
];

# PERMISSIONS
Drupal admin can give permissions to any drupal role to purge cached resources
from Cloudflare.

# Legal
This module has not been built, maintained or supported by CloudFlare Inc.
This is an open source project with no association with CloudFlare Inc.
The module uses their API, that's all.


* Read more about Cloudflare's API:
   https://api.cloudflare.com/#zone-purge-all-files
   https://support.cloudflare.com/hc/en-us/articles/201883834-Using-CloudFlare-and-Drupal-Five-Easy-Recommended-Steps

* Project page: https://www.drupal.org/project/cloudflare_purge

* To submit bug reports and feature suggestions, or to track changes:
   https://drupal.org/project/issues/cloudflare_purge
