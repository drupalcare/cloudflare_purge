# Drupal 12 — work needed

**Status: NOT DONE.** Analysed 2026-08-05 against Drupal `12.0-dev`, deliberately
left for later. The `^12` in `cloudflare_purge.info.yml` is a declaration only —
**the module does not currently work on Drupal 12.**

---

## What breaks

Enabling this module on Drupal 12 fatals:

```
In ClassResolver.php line 32:
  Class "cloudflare_purge_requirements" does not exist.
```

**Drupal 12 removed procedural `hook_requirements()`.** Drupal 11.2 deprecated it
in favour of two replacements, and 12 dropped the old form entirely. Because the
hook system now resolves implementations through the class resolver, the function
name `cloudflare_purge_requirements` is interpreted as a *class name*, which does
not exist — hence the error.

**This is worse than one broken module.** Once it is enabled, the failure happens
during container build, so *every subsequent* `drush en` on that site fails too,
with an error naming this module. It is easy to misread as a fault in whatever
module you were actually installing.

## What the hook currently does

`cloudflare_purge.install`, `cloudflare_purge_requirements()`, lines **64–197**
(~133 lines).

**Runtime phase only.** Line 67 returns immediately for any other phase:

```php
if ($phase !== 'runtime') {
  return $requirements;
}
```

It reports three status keys on `/admin/reports/status`:

| Key | Reports |
|---|---|
| `cloudflare_purge` | credentials present, and where they are stored (settings.php / Key module / config) |
| `cloudflare_purge_key` | whether the Key module is in use |
| `cloudflare_purge_auto` | auto-purge on/off |

Nothing here affects purging behaviour. These are advisory status rows only.

## The fix

Because the hook is runtime-only, this is a **one-to-one rename with no logic
change**: delete the phase guard and rename the function.

```php
// Before
function cloudflare_purge_requirements(string $phase): array {
  $requirements = [];
  if ($phase !== 'runtime') {
    return $requirements;
  }
  …
}

// After
function cloudflare_purge_runtime_requirements(): array {
  $requirements = [];
  …
}
```

**CORRECTED 2026-09-06 — the sentence that stood here was false, and it was
the only part of this file that mattered.** It read: *"`REQUIREMENT_OK` /
`REQUIREMENT_WARNING` / `REQUIREMENT_INFO` / `REQUIREMENT_ERROR` still exist in
Drupal 12."*

**They do not.** Verified against `12.0-dev`: there is no
`define('REQUIREMENT_ERROR', ...)` anywhere in core. Reading one raises a fatal
`Undefined constant` from inside the hook while the module is being enabled —
measured on the sibling module `acquia_purge_varnish`, which has the identical
usage and could not be installed on Drupal 12 at all.

So **the body is NOT untouched**: every severity has to change. The replacement
is the enum `\Drupal\Core\Extension\Requirement\RequirementSeverity`, added
in Drupal 11.2. Since this module also declares `^10 || ^11`, the two cannot
both be written literally — see `acquia_purge_varnish`'s
`_acquia_purge_varnish_severity()` helper, which picks between them with
`enum_exists()`.

*An integer literal is not a way around it.* The constants and the enum share
their values (-1/0/1/2), so `2` looks portable; it is not.
`RequirementSeverity::maxSeverityFromRequirements()` reads
`$requirement['severity']->value`, so an int fatals on a property read instead.

**And check the install-phase guard separately.** On Drupal 12, install-phase
requirements no longer come from the procedural hook at all — core reads them
from a class in `src/Install/Requirements/` implementing
`InstallRequirementsInterface`. Any row here that is meant to *block* an
install stops blocking silently until that class exists.

**Estimated effort: no longer ~15 minutes** — the severity rewrite and the
install-phase class are both real work. Budget it like the sibling module.

## The catch: supported core range

`core_version_requirement` is `^10 || ^11 || ^12`, and no single approach covers
that range:

| Hook | D10 | D11.0–11.1 | D11.2+ | D12 |
|---|---|---|---|---|
| `hook_requirements()` | ✅ | ✅ | deprecated | **removed** |
| `hook_runtime_requirements()` | ❌ | ❌ | ✅ | ✅ |

Three options:

1. **Both, version-guarded** — add the new hook, keep the old one wrapped in
   `version_compare(\Drupal::VERSION, '11.2', '<')`. Full range works; the
   guard stays until D10/D11.1 support is dropped.
2. **Narrow to `^11.2 || ^12`** *(recommended for this module)* — new hook only,
   cleanest code. A CloudFlare purge module for Drupal 10 is a thin audience.
3. **New hook only, keep the wide range** — status rows silently stop appearing
   on D10/D11.0/D11.1. Cosmetic, but a silent regression. Not recommended.

## Testing when it is done

- `/admin/reports/status` on **D11** and **D12**: the three rows appear once
  each, with the same severities as today.
- Toggle credentials between settings.php, the Key module and the config form —
  the "storage method" text should still be correct.
- Enable a second module afterwards on D12, to confirm the cascade failure above
  is gone.
- **Needs a real environment:** verify against live CloudFlare credentials. Local
  testing only ever exercises placeholder values.

## Related

`acquia_purge_varnish` has the identical Drupal 12 problem, but its hook uses two
phases and includes an install-time blocker, so it is a harder split. See its own
`d12-work-needed.md`.
