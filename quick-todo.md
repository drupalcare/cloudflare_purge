# Two quick drupal.org actions (web UI only, ~3 minutes total)

Everything else is on the D12 list or its own issue. Nothing below blocks anyone.

---

## 1. Add a warning to the 3.1.2 release notes (~2 min)

Edit the 3.1.2 release node and put this at the top of the notes:

> **⚠️ Do not use 3.1.2 on Drupal 10 or Drupal 11.0–11.1.** This release is fatal
> on those versions: `Interface "Drupal\Core\Cache\CacheTagsPurgeInterface" not
> found`, which takes the site down on cache rebuild. Update to
> [3.1.3](https://www.drupal.org/project/cloudflare_purge/releases/3.1.3). See
> [#3614126](https://www.drupal.org/project/cloudflare_purge/issues/3614126).

Optionally also unpublish 3.1.2. Composer already resolves to 3.1.3, so this
mainly helps anyone pinned to 3.1.2 or reading the release history while
debugging.

---

## 2. Fix two fields on #3614126 (~1 min)

- **Priority:** Normal → **Critical**
- **Version:** 3.1.2 → **3.1.x-dev**

Purely for the record now that it is fixed and released, but a fatal on a
supported core version should read as Critical in the issue history.

---

# New issue to file: phpstan has been red since before 3.1.2

Not urgent, no user impact, but it should not live inside the D12 list. It is
unrelated, and bundling it means it only gets done when D12 does.

**Title:** `phpstan job has been failing since before 3.1.2 and is hidden by allow_failure`

**Category:** Task · **Priority:** Normal · **Version:** 3.1.x-dev

**Issue summary:**

> The `phpstan` CI job has been red since at least 18 July 2026, failing on
> pipeline 893847 (3.1.x-dev), on the 3.1.2 release pipeline 902463, and still on
> the 3.1.3 release pipelines on 20 August 2026. The DA GitLab template marks it
> `allow_failure: true`, so the pipeline reports green regardless and the failure
> is invisible.
>
> 20 errors at level 5, none in the cache-purge paths:
>
> * `src/Form/*`: 11
> * `src/Plugin/QueueWorker/CloudflarePurgeQueueWorker.php`: 5, all
>   `dependencySerializationTraitProperty.unsupportedPrivateProperty`
> * `src/CloudflarePurgeApi.php`: 2
>
> Proposed resolution: fix the errors that are real, and add narrowly-scoped
> `ignoreErrors` entries with a written reason for the ones that are not. Once
> the job is genuinely clean, decide whether to keep it advisory or make it gate
> the pipeline.
>
> Worth doing because this is the same failure mode as #3614126 one layer up: a
> check that is present, named in the pipeline, and reporting nothing that anyone
> acts on.
