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

## Done-when evidence

## Story changes

## Files changed

## Head commit

## For the reviewer before release
