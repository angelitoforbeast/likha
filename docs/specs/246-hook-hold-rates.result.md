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

## Done-when evidence

## Story changes

## Files changed

## Head commit

## For the reviewer before release
