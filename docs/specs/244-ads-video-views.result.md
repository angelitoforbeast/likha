# Result 244: the Meta Ads sync brings in video views (3-second, ThruPlay, 50%)

## Plan

One task, risk tier medium (kept from the spec). Side: backend sync + one migration + the Campaigns Manager table and its CSV.

**Where the code is today (cf1dd382)**

- `Insights::FIELDS` asks the ad-level Insights API for reach, impressions, `inline_link_clicks`, spend, `actions`, results, … ; `Insights::map()` turns one answer row into `ads_manager_reports` columns. `actions` is already requested.
- `Sync` hands the mapped rows to `ProcessAdsManagerReportsUpload::importRows()`, the upload's own upsert: an explicit `putIfExists` list per column (null is never written), plus the monotonic protection (`MONOTONIC_FIELDS`).
- The `/ads_manager/report` page itself only lists upload logs, the Meta panel and data health: no per-ad numbers. The per-ad table with reach, impressions and link clicks is the Campaigns Manager (`/ads_manager/campaigns`, linked from the report page): `AdsManagerCampaignsController::data()` (`level=ads`), its CSV (`exportCsv`), the column catalog in `ads_manager/campaigns.blade.php` and the column settings catalog (`OwnerColumnSettingsController::CATALOG['campaigns']`).
- The upload's header map (`mappedHeaders`) has no video columns, so S3 leaves the upload unchanged.

**Change (smallest)**

1. Migration `2026_11_20_100000_add_video_views_to_ads_manager_reports.php`: `video_plays_3s`, `video_thruplays`, `video_p50`, unsigned int, nullable, no default, no `after()` (appended last, so MySQL 8 can add them INSTANT without rebuilding the table). `down()` drops them. Model `$fillable` gets the three.
2. `Insights::FIELDS` adds `video_thruplay_watched_actions,video_p50_watched_actions`. `map()`:
   - `video_plays_3s` ← the `video_view` entry of `actions` (Meta's "3-second video plays").
   - `video_thruplays` ← the `video_view` entry of `video_thruplay_watched_actions`.
   - `video_p50` ← the `video_view` entry of `video_p50_watched_actions`.
   A small reader takes only a list of arrays with a numeric, non-negative value; anything else (missing, a string, an object, no `video_view` entry) is null, never 0.
   `video_15_sec_watched_actions` still exists in Meta's field list, but per the decision table ThruPlay is the "15 seconds" number (what Ads Manager shows), so it is not requested.
3. `importRows` writes the three columns through `putIfExists` like the other columns (insert and update path; null leaves the stored value). They are not added to `MONOTONIC_FIELDS`, so no row that synced before can be refused because of them.
4. Display (S2): `data()` at `level=ads` sums the three per ad (null when the ad has none), and the totals row too, guarded by `Schema::hasColumn` like the controller's other optional columns (so the page keeps working between deploy and migrate). The ads CSV gets "3s plays", "ThruPlays (15s)", "50% views" after "Link Clicks" (blank when null). The table catalog gets the three after "Link clicks", shown on the Ads tab only; the column settings catalog lists them after Link clicks (a saved order gets them right after Link clicks too).

**Tests first** (new `tests/Feature/MetaAds/MetaAdsVideoViewsTest.php`, recorded made-up answers through the existing fake Graph; `MetaAdsTestCase` gets the three columns)

| Case | Type | Given / When / Then |
|---|---|---|
| S1.1 | happy | The insights request asks for `video_thruplay_watched_actions` and `video_p50_watched_actions`; a video ad's answer stores 3s plays, ThruPlays and 50% from the `video_view` entries |
| S1.2 | edge | An image ad (no video fields, no `video_view` action) and odd shapes (a string, an object, a negative or non-numeric value, only `video_play`): the three columns are null |
| S1.3 | edge | The same recorded answer gives exactly the same values in every other column with and without the video fields |
| S1.4 | happy | The update path: a stored row for the same day + ad gets the three numbers; a later answer without them keeps them |
| S1.5 | happy | The migration adds the three nullable columns and `down()` removes them |
| S2.1 | happy | `/ads_manager/campaigns/data?level=ads` returns the three sums per ad (null for an image ad) and in the totals; the ads CSV has the three headers right after Link Clicks, blank for null |
| S2.2 | happy | The Campaigns Manager page has the three columns after Link clicks; the column settings catalog lists them after Link clicks and a saved order places them there |
| S3 | negative | An uploaded export with the same day + ad leaves stored video numbers as they are (the upload has no video columns) |

**Followed as planned**, with one addition found by the full suite. `SharedPagesBasePinTest` pins the render of `/owner/private` and `/owner/column-settings/owner-private`, and both pages carry the campaigns column catalog as JSON. I dumped both renders before and after the change and diffed them. The only difference is the three new ids (and their labels) in the campaigns catalog, the order and the default-visible lists, so the pin was renewed. Earlier campaigns columns were handled the same way (commit `c76a5083`).

### API fields used and why

I checked the names against Likha's Graph version (`Graph::version()`, `services.meta_ads.graph_version`, default `v25.0`) and against Meta's references. An automated fetch of the Marketing API Insights field pages on developers.facebook.com now returns either 404 or a page without the field table. So I confirmed the names and types in two other Meta sources:
- Meta's own generated `facebook-python-business-sdk` (`adobjects/adsinsights.py`), where each field is `list<AdsActionStats>`.
- Meta's Insights breakdowns page (v27.0 examples), which names `video_thruplay_watched_actions` and `video_p50_watched_actions`.

Meta's video ads guide shows `video_view` as an `action_type` inside `actions`.

| Column | Meta field | Entry | Why |
|---|---|---|---|
| `video_plays_3s` | `actions` (already requested) | `action_type = video_view` | Meta's 3-second video plays. `video_play` (and `video_play_actions`) count plays that only started, so they are not used. |
| `video_thruplays` | `video_thruplay_watched_actions` | `video_view` | ThruPlay = 15 seconds, or to the end of a shorter video. `video_15_sec_watched_actions` is still in Meta's field list. Per the spec decision, ThruPlay is the "15 seconds" number because it is what Ads Manager shows. So the 15-second field is not requested, and the report has one 15-second number. |
| `video_p50` | `video_p50_watched_actions` | `video_view` | 50% watched. |

The only change to the request: `Insights::FIELDS` gains `,video_thruplay_watched_actions,video_p50_watched_actions`. The answers are untrusted. A value is taken only from a JSON list of arrays whose `action_type` is `video_view` and whose `value` is numeric and not negative. Anything else gives null: the field missing, a string, an object, `''`, `'many'`, `-5`, or no `video_view` entry. A real `0` stays 0.

## Done-when evidence

All runs used Herd's PHP 8.4.16 and PHPUnit 11.5.24 on this Windows machine, with the database `phpunit.xml` sets (SQLite `:memory:`).

1. **Feature tests for S1, S2 and S3.** All 19 failed at `86186f1c`, before the code: undefined key `video_plays_3s`, the fields not requested, the migration file missing.

   ```
   cmd //c "php vendor\bin\phpunit tests\Feature\MetaAds\MetaAdsVideoViewsTest.php tests\Feature\MetaAds\AdsVideoViewsTableTest.php"
   OK (19 tests, 63 assertions)

   cmd //c "php vendor\bin\phpunit tests\Feature\MetaAds"
   OK (110 tests, 491 assertions)
   ```

   - **S1** (`MetaAdsVideoViewsTest`):
     - The request asks for the fields, and a video ad stores 4210 / 1375 / 980.
     - An image ad and 8 odd shapes give null; a real 0 stays 0.
     - The same recorded answer maps every other column identically with and without the video fields: spend 250.5, reach 900, impressions 1500, link clicks 33, messages 12, purchases 2, results 12, cost per result 20.88.
     - Update path: an existing row is updated, a later answer without video keeps the numbers, and a higher ThruPlay count replaces the stored one.
   - **S3** (same file): an uploaded export for the same day and ad leaves the video numbers as they are.
   - **Migration** (`AdsVideoViewsTableTest::test_S1_5_…`):
     - It adds the three nullable columns, and running `up()` twice changes nothing.
     - `down()` drops them, keeps the rows and is safe to run twice.
     - The file has no `->default(` and no `->after(`.
   - **S2** (same class):
     - `/ads_manager/campaigns/data?level=ads` returns the sums per ad (150 / 50 / 35, null for the image ad) and the totals.
     - The ads CSV has `Link Clicks, 3s plays, ThruPlays (15s), 50% views, Welcome Msg Rate (%)`, with blank cells for null.
     - Before the migration runs, the ads level and its CSV still return 200.
     - The Campaigns Manager page has the three column defs after Link clicks, with `tab:'ads'`.
     - Column settings put them after Link clicks, also for an older saved order (`loadConfig` and `loadConfigMatrix`), and the CEO sees them by default.
   - The existing `MetaAdsSyncTest::test_S_154_4_each_column_is_filled_from_the_api_as_the_upload_would` (it checks every column of a synced row) and `MetaAdsCompareTest` pass unchanged.

2. **Full suite: no new failures.**

   ```
   cmd //c "php vendor\bin\phpunit"
   before (cf1dd382): Tests: 4737, Assertions: 78348, Failures: 2, Skipped: 6   (13:38)
   after  (c76a5083): Tests: 4756, Assertions: 78411, Failures: 2, Skipped: 6   (14:21)
   ```

   - **Same two failures in both runs.** Both are the known ones: `ExampleTest::test_the_application_returns_a_successful_response` (302) and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order` (57 vs 45). The second one counts the `owner_private` catalog, which this spec does not touch.
   - **The 19 extra tests** are the new ones.
   - **The run in between** (at `80a05698`) also failed the two `SharedPagesBasePinTest` cases. They were re-pinned, as explained under Plan.
   - **One flaky test.** One targeted run once failed `MetaAdsLoginConfigTest` "long-lived exchange fails". It passed when run alone right after, and in both full runs. It looks timing-dependent and is unrelated: it does not touch insights.

3. **Exactly one new migration:** `database/migrations/2026_11_20_100000_add_video_views_to_ads_manager_reports.php`. It adds the three columns, its `up()` is idempotent and its `down()` works.

4. **Branch** `feat/244-ads-video-views`, from `cf1dd382`, pushed to `origin` (see Head commit).

## Story changes

`qa/stories.md` has a new "Slice 244 – The Meta Ads sync brings in video views":
- S-244.1, 5 cases: the fields, the null shapes, the unchanged columns, the update path, the migration.
- S-244.2, 2 cases: the data and CSV, the page and column settings.
- S-244.3, 1 case: the upload and Compare unchanged.

Every case names its test. The "Last run" cells are left for qa.

## Files changed

- `app/Services/MetaAds/Insights.php`:
  - `FIELDS` gets the 2 video fields, and `VIDEO_VIEW` is new.
  - `map()` writes the three columns through a new `videoViews()` reader.
- `app/Jobs/ProcessAdsManagerReportsUpload.php`:
  - `upsertChunk` writes the three columns with `putIfExists` (insert and update path).
  - They are not added to `MONOTONIC_FIELDS`, so a lower video count never refuses a row that synced before.
  - The upload's header map is unchanged.
- `app/Models/AdsManagerReport.php`: `$fillable` gets the three columns.
- `database/migrations/2026_11_20_100000_add_video_views_to_ads_manager_reports.php`: new.
- `app/Http/Controllers/AdsManagerCampaignsController.php`:
  - `data()` sums the three columns at the ads level and in the totals, behind `Schema::hasColumn`.
  - The ads CSV gets three columns after Link Clicks.
- `app/Http/Controllers/OwnerColumnSettingsController.php`:
  - The campaigns catalog and the default-visible list get the three after Link clicks.
  - `PLACED_AFTER_PREVIOUS` puts them after Link clicks in a saved order too.
- `resources/views/ads_manager/campaigns.blade.php`:
  - Three column defs after Link clicks, with `tab:'ads'`.
  - `visibleCols()` drops a column whose `tab` is not the current tab.
- Tests:
  - New: `tests/Feature/MetaAds/MetaAdsVideoViewsTest.php` and `tests/Feature/MetaAds/AdsVideoViewsTableTest.php`.
  - `tests/Feature/MetaAds/MetaAdsTestCase.php`: the three columns in its hand-made table.
  - `tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php`: re-pin.
- Docs: `docs/specs/244-ads-video-views.md`, this result, `qa/stories.md`.

Not changed, on purpose:
- `Compare`: it still compares the same five numbers.
- The XLSX/CSV upload mapping: it has no video columns.
- The `/owner/private` and `/item` campaign panels: their JS column catalogs do not list the new ids, so they ignore them, and their campaign-level rows have no video sums.

## Head commit

The code head is `c76a5083`. The branch tip is the docs commit that records this result, right after it.

## For the reviewer before release

- **Release commands** (after the owner's yes), run through the usual deploy procedure:
  ```
  git pull                      # with feat/244-ads-video-views merged
  php artisan migrate --force   # the one new migration
  php artisan view:clear        # campaigns.blade.php changed (or the procedure's usual view-cache step)
  ```
- **Run the migration together with the code.** It must run before the next scheduled sync (10:00, 15:00, 18:45 Manila).
  - The page side checks `Schema::hasColumn`. The sync does not: it writes the three columns whenever Meta returns video numbers.
  - If the new code syncs before the columns exist, that account's rows fail with "unknown column".
  - Running pull and migrate in one go, as usual, avoids this.
- **What the migration costs:**
  - It runs three statements (Laravel 12 issues one per column): `ALTER TABLE ads_manager_reports ADD <col> INT UNSIGNED NULL`. Each column is nullable, has no default and goes last.
  - On MySQL 8.0.12+, InnoDB uses `ALGORITHM=INSTANT` for this by default. That is a metadata-only change with no table copy or rebuild, normally well under a second per statement whatever the row count.
  - It needs a brief metadata lock, so it waits for any long transaction on the table (for example a running upload or sync chunk). Run it outside the sync times.
  - MySQL 8.0.29+ counts each instant add toward a limit of 64 row versions per table. This migration uses 3.
  - Run `SELECT VERSION();` first. On MySQL older than 8.0.12, the same statements rebuild the table in place: INPLACE and online, but long on a big table.
- **Backfill:** none (out of scope). The next syncs fill the dates they cover. Older rows stay null, shown as "—" in the table and blank in the CSV.
- **Display:** "Empty when null" shows as the table's existing em dash "—" for empty integer cells, as Link clicks already does, and as a blank cell in the CSV.
- **Roles:** like every new campaigns column, the CEO sees the three by default. Other roles see them only after the CEO ticks them in `/owner/column-settings` (the existing opt-in rule for non-CEO roles). Ask the owner whether Marketing should see them.
- **Not verified live:**
  - No call to Meta was made.
  - The field names come from Meta's SDK field list and breakdowns page, and the shapes from the docs.
  - The first real sync after release is the live check: for one video ad and one day, ThruPlays and 3-second plays should match Ads Manager.
