<?php

declare(strict_types=1);

namespace Drupal\cloudflare_purge\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * The status-report rows, for Drupal 11.3 and later.
 *
 * WHY THIS EXISTS. `cloudflare_purge_requirements()` now carries
 * `#[LegacyRequirementsHook]`, which stops Drupal 11.3+ calling it. That
 * attribute is required — without it Drupal 12 tries to resolve the function
 * NAME through the class resolver and breaks the next module install on the
 * site with `Class "cloudflare_purge_requirements" does not exist`. But on its
 * own it would silently delete every row this module contributes to
 * /admin/reports/status. This class puts them back through the replacement
 * hook.
 *
 * WHY IT DELEGATES INSTEAD OF MOVING THE BODY. The legacy function is ~130
 * lines of credential, zone and queue checks. Copying it here would create a
 * second copy to drift, and rewriting it would risk changing behaviour in code
 * this change has no reason to touch. Core stops CALLING the function; it does
 * not stop this module calling it. One copy of the logic, reached two ways.
 *
 * The Project Update Bot's rector patch (#3604239) rewrote the severities in
 * that function but did **not** add the attribute or this class, so applying
 * that patch alone leaves the site broken. Measured 2026-09-06 on 12.0-dev.
 */
class CloudflarePurgeRequirementsHooks {

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The runtime requirement rows.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    // The legacy implementation lives in the .install file, which core does
    // not load for this hook — it is loaded for hook_requirements, and that is
    // exactly the hook no longer being called here.
    \Drupal::moduleHandler()->loadInclude('cloudflare_purge', 'install');

    if (!function_exists('cloudflare_purge_requirements')) {
      return [];
    }

    return cloudflare_purge_requirements('runtime');
  }

}
