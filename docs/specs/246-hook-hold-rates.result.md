# Result 246: Hook Rate, Hold Rate and Hold Rate 50% per ad, and the Ads Manager upload requires the video columns

## Plan

One task, risk tier medium (kept from the spec). No migration: the rates are computed from the three stored video columns of spec 244.

**Where the code is today (52f85bbb)**

- `AdsManagerCampaignsController::data()` at `level=ads` sums `video_plays_3s`, `video_thruplays`, `video_p50` per ad and in the totals (behind `Schema::hasColumn`), and computes the other rates (`welcome_msg_rate`, `conversion_rate`) in SQL from summed numbers. Sorting is server-side through a `$sortable` list per level. The ads CSV has the video columns after "Link Clicks".
- `OwnerColumnSettingsController::CATALOG['campaigns']` is the one catalog for the Campaigns Manager and the inline campaigns / ad sets / ads expand on `/owner/private`. `PLACED_AFTER_PREVIOUS` puts new ids right after the column before them in an older saved order.
- `/owner/private` (`owner/private.blade.php` `defaultCampaignsCols()`, rendered by `owner/_private_expand_inline.blade.php`) has its own JS catalog, the same at all three levels; it does not list the video columns.
- The upload (`ProcessAdsManagerReportsUpload`) matches headers after lower-casing and collapsing spaces (`mappedHeaders`, `buildHeaderIndex`), checks `requiredHeaders()` before any row is read and fails the upload log with "Missing required column(s): ...". This importer strips no suffixes. `upsertChunk` already writes the three video columns with `putIfExists` (null never overwrites).

**Change (smallest)**

1. Rates (S1): one SQL helper gives `hook_rate`, `hold_rate`, `hold_rate_50` as percentages from summed numbers, used in the ads-level select and in the totals select. Each rate's divisor is summed over the rows that carry its numerator, so days stored before the video sync (null, no backfill) do not dilute the rate; when every row has video numbers this is exactly sum(numerator) / sum(divisor). Divisor 0 or null, or numerator null, gives null ("—" in the table, blank in the CSV). The three ids join the ads-level `$sortable` list. The ads CSV gets "Hook Rate (%)", "Hold Rate (%)", "Hold Rate 50% (%)" after "50% views".
2. Catalog (S2): three entries after `video_p50` in `CATALOG['campaigns']`, in `DEFAULT_VISIBLE` (the CEO sees them by default; other roles only when ticked, the existing opt-in rule) and in `PLACED_AFTER_PREVIOUS`. Campaigns Manager: three `type:'percent'` sortable defs with `tab:'ads'` after the video columns. `/owner/private`: the three defs in `defaultCampaignsCols()` with `tab:'ads'`; the ads-level table asks for its level's columns so they show only on ads rows.
3. Upload (S3): the three headers are required and mapped. Accepted (after lower-casing and collapsing spaces): "3-second video plays" (also the pre-2019 name "3-second video views"), "ThruPlays" (also "ThruPlay"), "Video plays at 50%" (also the older "Video watches at 50%"). The first present alias is read; the cell is a non-negative count or null (blank, "—", text).

**Tests first** (new `tests/Feature/MetaAds/AdsHookHoldRatesTest.php` and `tests/Feature/MetaAds/AdsUploadVideoColumnsTest.php`)

| Case | Type | Given / When / Then |
|---|---|---|
| S1.1 | happy | ads level returns the three rates per ad from summed numbers, and in the totals (not an average of the ad rates) |
| S1.2 | edge | 0 impressions / 0 or null 3s plays / null numerator give null, never 0 or an error; pre-sync null days do not dilute |
| S1.3 | happy | sorting by each rate at the ads level |
| S1.4 | happy | the ads CSV has the three after "50% views", one decimal, blank for null |
| S2.1 | happy | the Campaigns Manager and /owner/private catalogs have the three percent columns after the video columns, ads only |
| S2.2 | happy | column settings: after `video_p50`, also in an older saved order (with and without the video ids); CEO sees them by default; Marketing only when ticked |
| S3.1 | happy | an upload with the three columns fills the three columns; blank / "—" / text cells are null and keep a synced number |
| S3.2 | negative | a file missing any one (or all) of the three fails with "Missing required column(s): ..." naming exactly those, and writes nothing |
| S3.3 | edge | header variants (case, spacing, the older names) are accepted |

Existing upload tests build files without the video columns; they get the three columns added (blank cells) so they keep testing what they tested.

**Followed as planned**, plus amendment 1 (below) and the re-pins found by the full suite (see Done-when 2).

## Amendments

Amendment 246-1, saved verbatim as received:

> Amendment 246-1 (part of spec 246 from now on; save it verbatim in the result file under "Amendments" and list the changes there).
>
> | Change | Who decided, why |
> |---|---|
> | S3 clarified: the upload that must require the three video columns is the one on `/ads_manager/report` (`AdsManagerReportController::store` and the job it queues). If that page's upload path differs from `ProcessAdsManagerReportsUpload`, the requirement goes on that path; make sure the page shows the missing-column message to the user who uploaded. Also update any help text on that page that lists the required export columns. | The owner, 2026-11-08: uploads on /ads_manager/report must require the added columns. |
> | S4 (P2, new): on the same page, the "Ad accounts to sync" section (`resources/views/ads_manager/_meta_ads.blade.php`, form id `meta-accounts`) is collapsed by default: a single line "Ad accounts to sync (N of M checked) ▸" that expands on click (a `<details>`/`<summary>` or the page's existing toggle pattern), with the checkboxes and Save inside. Nothing about what is saved or synced changes. It stays open after a save that returned an error, so the user sees the error. | The owner: the dropdown should not always be shown. |
> | Done-when 1 now also covers S4 (a feature test of the collapsed markup and the count). | Same. |
>
> Everything else in spec 246 is unchanged. Report as before when done.

What changed for it:

- **S3 path:** `AdsManagerReportController::store` queues exactly `ProcessAdsManagerReportsUpload`, so the requirement already sits on that path. The page's uploads table shows each log's `error_message`, and so does `/ads_manager/report/status`, which the page polls. So the uploader sees "Missing required column(s): 3-second video plays, ThruPlays, Video plays at 50%". A new feature test posts an XLSX through the page and checks both places.
- **Help text:** the page has no help text listing the required export columns, so there was nothing to update.
- **S4:**
  - `_meta_ads.blade.php` wraps the accounts form in `<details id="meta-accounts-section">`. Its `<summary>` is "Ad accounts to sync (N of M checked) ▸", and the caret turns when open. The form keeps `id="meta-accounts"`, its checkboxes, the read-only note and Save.
  - The section opens itself after a save that came back with an error:
    - A refused unknown account: `MetaAdsController::accounts` now also flashes `meta_accounts_error`.
    - A validation error on `accounts` / `accounts.*`: that error is now shown inside the section.
  - What is saved or synced is unchanged.

## Done-when evidence

All runs used Herd's PHP 8.4.16 and PHPUnit 11.5.24 on this Windows machine, with the database `phpunit.xml` sets (SQLite `:memory:`).

**How the tests were run (important).**

- This worktree's `vendor/` is a junction to the main checkout's `vendor/`. Composer's optimized classmap therefore loads `App\`, `Database\` and `Tests\` from `C:\mira\mira\projects\likha`, and `Application::inferBasePath()` takes that folder too.
- So a plain `php vendor\bin\phpunit` here tests the main checkout's code, views and migrations, not this branch. I found this while debugging the first upload test.
- Every run below uses a run-only shim that is not committed: `C:\Users\Forbeast\AppData\Local\Temp\claude\2e760ef270dbe960\07319e29-2afc-45d1-9b8d-9083b1ed643e\scratchpad\wt246-bootstrap.php`. It sets `APP_BASE_PATH` to the worktree and points the classmap and PSR-4 entries for those namespaces at the worktree. It works like the spec 245 shim.
- I checked the shim two ways:
  - Reflection shows the job, the controllers, `Tests\TestCase` and `inferBasePath()` resolving to `C:\mira\mira\projects\likha-wt246`.
  - At `d9c419bd`, the shimmed renders of the two pinned owner pages give exactly the committed pin hashes.
- **Re-run:** my first full baseline and my first red run were made without the shim, so they tested the other checkout. Both were re-run with it, and only the shimmed numbers are reported here.

Command form: `cmd //c "php vendor\bin\phpunit --colors=never --bootstrap C:\Users\Forbeast\AppData\Local\Temp\claude\2e760ef270dbe960\07319e29-2afc-45d1-9b8d-9083b1ed643e\scratchpad\wt246-bootstrap.php <paths>"` from the worktree.

1. **Feature tests prove every case of S1–S4.**

   Red first, at `56393afd` (the tests committed, no code yet):
   ```
   phpunit ... tests\Feature\MetaAds\AdsHookHoldRatesTest.php tests\Feature\MetaAds\AdsUploadVideoColumnsTest.php tests\Feature\MetaAds\AdsReportPageVideoColumnsTest.php tests\Feature\MetaAds\MetaAdsVideoViewsTest.php
   Tests: 40, Assertions: 79, Errors: 2, Failures: 25.
   ```
   Every new case failed except the existing spec 244 ones. Two of the red failures came from test bugs that were fixed with the code:
   - A second CEO user was created in one test.
   - A `+` on the query array kept `level=ads`.

   The page upload tests first posted a CSV. They now post an XLSX, the format Ads Manager exports. See "For the reviewer" for the CSV reader.

   Green on the branch:
   ```
   phpunit ... (the same four files)      OK (40 tests, 166 assertions)
   phpunit ... tests\Feature\MetaAds       OK (137 tests, 627 assertions)
   ```
   - **S1** (`AdsHookHoldRatesTest`):
     - **Per-ad rates:** a1 = 7.5% / 33.3% / 23.3%. a4 = 20% / 20% / 10%: its pre-sync null day does not dilute the rates. a5 = 25% / — / 25% (no ThruPlays).
     - **Totals:** 13.64% / 28.0% / 21.1%, computed from the summed numbers. An average of the ad hold rates would give 26.67.
     - **Null cases:** an image ad, 0 impressions with 0 plays, and the totals of image ads only all give null.
     - **Sorting:** each rate sorts both ways. The campaign and ad set levels are unchanged and ignore a rate sort.
     - **CSV:** "Hook Rate (%)", "Hold Rate (%)", "Hold Rate 50% (%)" come right after "50% views", with one decimal and blank for null.
   - **S2** (same file):
     - The Campaigns Manager has three sortable `percent` columns after the video columns, with `tab:'ads'`.
     - `/owner/private` lists them after Link clicks with `tab:'ads'`. `visibleCampaignsCols(level)` leaves them out of the campaign and ad set tables, and the ads table passes `'ads'`.
     - **Column settings:** the three come after "50% views". The CEO sees them by default. Marketing and Marketing - OIC see them only when ticked.
     - **Older saved orders** get them right after the video columns, whether or not the order already has the video ids (`loadConfig` and `loadConfigMatrix`).
   - **S3** (`AdsUploadVideoColumnsTest`, `AdsReportPageVideoColumnsTest`, `MetaAdsVideoViewsTest`):
     - **Filling:** the columns fill as 4210 / 1375 / 980 (`"4,210"` accepted), and a real 0 stays 0.
     - **Bad cells:** blank, "—", "n/a" and "-5" cells are null and keep the stored numbers.
     - **Missing columns:** a file missing each one alone, all three, or one together with Link clicks fails with exactly the missing names. Nothing is written to `ads_manager_reports` or `ad_campaign_creatives`.
     - **Variants:** upper case, extra spaces, title case and the older names are accepted.
     - **Through the page:** the uploader sees the message in the uploads table and in `/status`.
     - **Upload and sync together:** a later sync without video keeps the uploaded numbers, and an upload with blank cells keeps the synced ones.
   - **S4** (`AdsReportPageVideoColumnsTest`):
     - The section is collapsed, reads "Ad accounts to sync (2 of 5 checked) ▸", and holds the 5 checkboxes and Save.
     - It is open after a refused save and after a validation error, with the error inside it.
     - It is closed after a good save, which shows "(1 of 5 checked)", and the saved selection is unchanged.

2. **Full suite: no new failures.**
   ```
   cmd //c "php vendor\bin\phpunit --bootstrap ...\wt246-bootstrap.php"
   before (d9c419bd = 52f85bbb + docs only): Tests: 4756, Assertions: 78411, Failures: 2, Skipped: 6   (13:04)
   after  (1c766e81):                        Tests: 4783, Assertions: 78547, Failures: 2, Skipped: 6   (13:03)
   ```
   - **The same two known failures in both runs, with the same messages:** `ExampleTest::test_the_application_returns_a_successful_response` and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order` (57 vs 45, the `owner_private` catalog, which this spec does not touch).
   - **The 27 extra tests** are the new ones.
   - **Re-pins found by the targeted runs:**
     - `SharedPagesBasePinTest` (`/owner/private`, `/owner/column-settings/owner-private`): I dumped both renders at the base and on the branch and diffed them. The only differences are the three rate ids and labels in the campaigns catalog, order and default-visible JSON, the three column defs, `visibleCampaignsCols(level)` with its filter, and `'ads'` in the two ads-table loops of the expand partial. New pins: `19d9a00e…` and `abf864d1…`.
     - `SuppliersGroupTest` (`/item`, which includes the same expand partial): for all 8 pinned renders, replacing `visibleCampaignsCols('ads')` with `visibleCampaignsCols()` gives back exactly the old hash. That is 4 places per default render and 2 per old-layout render. `/item`'s own catalog has no column with a `tab`, so nothing changes there. All 8 were re-pinned.

3. **No migration.** The rates are computed in SQL from the stored `video_plays_3s`, `video_thruplays`, `video_p50` (spec 244's migration, already live) and `impressions`.

4. **Branch** `feat/246-hook-hold-rates`, from `52f85bbb`, pushed to `origin` (see Head commit).

### Accepted upload header names

Headers are matched after lower-casing, trimming and collapsing runs of spaces. That is what this importer already does for every column: it strips no currency or attribution suffix.

| Required column (name in the message) | Accepted headers | Stored in |
|---|---|---|
| 3-second video plays | "3-second video plays", "3-second video views" (Meta's name before 2019) | `video_plays_3s` |
| ThruPlays | "ThruPlays", "ThruPlay" | `video_thruplays` |
| Video plays at 50% | "Video plays at 50%", "Video watches at 50%" (older name) | `video_p50` |

**Sources:**
- An automated fetch of Meta's own Ads Manager help pages did not return the column glossary.
- So the current names ("3-second video plays", "ThruPlays", "Video plays at 50%") come from the spec, matched against third-party references of Meta's export columns and metric glossaries. Those references also give the 2019 rename of "3-Second Video Views".
- The older names are accepted so that a saved report layout from before the renames is not refused.

When a file carries two aliases of the same column, the first in the list above is read.

## Story changes

`qa/stories.md`:
- S-244.3.1 now names the renamed test, and says that since spec 246 the upload carries the three columns.
- New "Slice 246 – Hook Rate, Hold Rate and Hold Rate 50% per ad; the upload requires the video columns":
  - S-246.1: 4 cases (rates, null cases, sort, CSV).
  - S-246.2: 2 cases (both catalogs; column settings and roles).
  - S-246.3: 5 cases (fill, missing, variants, upload with sync, through the page).
  - S-246.4: 2 cases (collapsed line, open after an error).

Every case names its test. The "Last run" cells are left for qa.

## Files changed

- `app/Http/Controllers/AdsManagerCampaignsController.php`:
  - `videoRatesSql()` and `videoRates()` are new.
  - The ads level and the totals select the three rates when the video columns exist, and the ads-level `$sortable` list gets them.
  - The ads CSV gets three columns after "50% views".
- `app/Http/Controllers/OwnerColumnSettingsController.php`: the campaigns catalog, `DEFAULT_VISIBLE['campaigns']` and `PLACED_AFTER_PREVIOUS` get `hook_rate`, `hold_rate` and `hold_rate_50` after `video_p50`.
- `app/Jobs/ProcessAdsManagerReportsUpload.php`:
  - `VIDEO_HEADERS` is new and is added to `requiredHeaders()`.
  - `normalizeRow` reads the first present alias.
  - `toCount()` is new: a non-negative count or null.
  - The comment on the video `putIfExists` lines is updated.
- `app/Http/Controllers/MetaAdsController.php`: a refused accounts save also flashes `meta_accounts_error`.
- `resources/views/ads_manager/campaigns.blade.php`: three column defs.
- `resources/views/owner/private.blade.php`: three column defs, and `visibleCampaignsCols(level)` with the `tab` filter.
- `resources/views/owner/_private_expand_inline.blade.php`: the ads table header and cells call `visibleCampaignsCols('ads')`.
- `resources/views/ads_manager/_meta_ads.blade.php`: the collapsed `<details>` section, its count, and the accounts error inside it.
- Tests:
  - New: `tests/Feature/MetaAds/AdsHookHoldRatesTest.php`, `AdsUploadVideoColumnsTest.php`, `AdsReportPageVideoColumnsTest.php`.
  - `MetaAdsVideoViewsTest.php`: an upload helper with the video columns, and the upload-then-sync case.
  - `AdsDataRefusalTest.php` and `AdsManagerUploadCharacterizationTest.php`: the three headers, with blank cells.
  - `AdsVideoViewsTableTest.php`: the CSV and page neighbours of the video columns are now the rates.
  - Re-pins: `OwnerPrivate/SharedPagesBasePinTest.php` and `Item/SuppliersGroupTest.php`.
- Docs: `docs/specs/246-hook-hold-rates.md`, this result, `qa/stories.md`.

Not changed:
- The sync (`Insights`, `Sync`).
- `Compare`.
- The `/item` campaigns catalog.
- The table schema.

## Head commit

The code head is `1c766e81`. The branch tip is the docs commit that records this result, right after it.

## For the reviewer before release

- **Tell the team before the release:** from now on, every Ads Manager export uploaded on `/ads_manager/report` must include "3-second video plays", "ThruPlays" and "Video plays at 50%". Without them, the upload fails (nothing written) with "Missing required column(s): …" in the uploads table. A saved Ads Manager report layout needs those three columns added once.
- **Release commands** (after the owner's yes), through the usual deploy procedure: `git pull` with the branch merged, then `php artisan view:clear` (four Blade views changed). No migration.
- **Rate divisor (a judgement inside "computed from summed numbers"):**
  - Each rate divides by the divisor summed only over the rows that have the numerator. Spec 244 does not backfill, so rows from before the sync have null video numbers. Counting their impressions would make every Hook Rate whose range starts before the release look far too low.
  - When all rows in range have video numbers, the result is exactly the sum of the numerator divided by the sum of the divisor.
  - As a result, the totals row's Hook Rate can differ from "3s plays ÷ Impressions" read off the same totals row, when the range includes pre-sync days.
- **/owner/private:** only the three rates were added to its ads rows, as S1 asks. Its expand still has no 3s plays / ThruPlays / 50% views columns (spec 244 did not add them there). Ask the owner whether he wants those too.
- **Roles:** the CEO sees the three by default. Marketing and Marketing - OIC see them only after the CEO ticks them in `/owner/column-settings/campaigns`.
- **Found, not fixed (out of scope):** a `.csv` upload on `/ads_manager/report` fails in `createReader()`. OpenSpout v4's CSV reader has no `setFieldDelimiter()`, so the job throws "Call to undefined method". The upload log ends "failed" with no message, and with a sync queue it is a 500. XLSX uploads work. This was already the case before this spec. Worth its own small spec if the team ever uploads CSV.
- **Test runs need the shim:** in this worktree layout (vendor junction), plain `phpunit` tests another checkout's code. See Done-when.
- **Not verified live:** no browser check of the collapsed section or the new columns, and no real Ads Manager export file. The first real upload after release is the live check of the header names.
