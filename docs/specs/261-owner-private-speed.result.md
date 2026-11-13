# 261: /owner/private opens fast and "Expand all" takes seconds, not 40 — result

Branch `feat/261-owner-private-speed`, from f5e5fb9f (live code).

## What changed

| File | What |
|---|---|
| app/Http/Controllers/AdsManagerCampaignsController.php:827 | batchData: the requested names are resolved to the stored `page_name` values first (`storedPageNames`), and each page with rows becomes `(page_name IN (...) AND day BETWEEN start AND end)`. Pages with no stored name drop out; when none is left the main query is skipped (an empty OR would match everything). |
| app/Http/Controllers/AdsManagerCampaignsController.php:850–885 | batchData: the latest-status subqueries (`ls`, `asls`) only cover the campaign_ids and ad_set_ids of the filtered rows (`idsIn`), and skip rows dated before the earliest page start (`onlyLatestCandidates`). Same "latest day" rule and SQL otherwise. |
| app/Http/Controllers/AdsManagerCampaignsController.php:964 | batchData windows query: `page_name IN (all resolved names)` instead of `LOWER(TRIM(page_name)) IN (...)`. |
| app/Http/Controllers/AdsManagerCampaignsController.php:1199, :1363, :1419, :1520, :1621, :1751 | data() (per-page path, all three levels and its windows query): the same page filter and the same restricted latest-status subqueries (built per level from the level's filtered rows). |
| app/Http/Controllers/AdsManagerCampaignsController.php:2269–2345 | Helpers `storedPageNames`, `idsIn`, `pageKey`, `earliestDay`, `onlyLatestCandidates`. |
| app/Http/Controllers/OwnerPrivateController.php:193 | `whereDayIn()`: `col >= from AND col < to+1` in front of the old `DATE(col) BETWEEN from AND to` (which stays), so the column's index is used. |
| app/Http/Controllers/OwnerPrivateController.php:1918, :1968, :1984, :2346 | itemSummary: statRows and pagesSeen (`macro_output.ts_date`, only when the column exists), the ad spend per day (`ads_manager_reports.day`) and the J&T stats (`from_jnts.submission_time`) use it. |
| resources/views/owner/private.blade.php:2 | `x-init="init()"` removed from `<html>`: Alpine 3 already calls the x-data object's `init()`, so it ran twice and sent two identical item-summary requests. Comment above `init()` (:3788). |
| tests/Feature/OwnerPrivate/OwnerPrivateSpeedTest.php | New, 5 tests (below). |
| tests/Feature/OwnerPrivate/ItemSummaryOnceTest.php | New, 1 test. |
| tests/Feature/OwnerPrivate/DayRangeFilterTest.php | New, 1 test. |
| tests/Feature/OwnerPrivate/ItemSummaryProfitMTest.php | 1 new test (`test_S_261_the_date_filters_range_on_the_indexed_columns`). |
| tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php:18 | Re-pinned /owner/private: the only render diff is the removed attribute and the two comment lines. |

No migration, no new index, no cache (decision 1).

## How the page filter stays identical (decision 2)

- One query: `SELECT page_name FROM ads_manager_reports GROUP BY page_name HAVING MAX(day) >= <earliest start, or end-6 for the windows>`. On production it is a loose index scan (`Using index for group-by`, 111 rows) and takes 0.03 s.
- Matched in PHP with `mb_strtolower(trim($name, ' '))` on both sides (MySQL TRIM strips spaces only). Every stored variant of a name (case, leading or trailing spaces) is put in the `IN` list for that page, so the same rows match on sqlite (binary) and MySQL.
- On MySQL the column is `utf8mb4_unicode_ci`, so `IN` also matches case, accent and trailing-space variants of each listed name. The one theoretical gap: a requested name that equals a stored name only by the collation's accent folding (e.g. request "Cafe", stored only "Café"), which PHP lowercasing does not fold. Production has no such pairs among the ads page names today (116 binary-distinct names = 116 collation-distinct names); the request names come from the order side, which I did not compare.

## Latest status (decision 3)

- `ls` = `campaign_id IN (campaigns in the filtered rows)`, `asls` = `ad_set_id IN (ad sets in the filtered rows)`, both with `(day >= earliest start OR day IS NULL)`.
- Why the day bound keeps the rule exact: every entity in the result has a filtered row with `day >= start`, so its latest `COALESCE(day, reporting_starts)` is at least that day; a row with a non-null `day` before it can never be on the latest day. Rows with no `day` stay in (their day comes from `reporting_starts`).
- The ad-set status filters by `ad_set_id` (not by campaign), so an ad set seen under two campaigns would still be exact. Production has 0 such ad sets.

## Production evidence (read-only)

Host 72.62.79.215, `mysql likha`, 2026-11-10 12:39–12:59, load average 3.5–8.5. Plain SELECT / EXPLAIN, one at a time, `max_execution_time=30000`. The request was rebuilt in SQL: range 2026-10-11..2026-11-09, 445 page names (the 85 stored names with rows in the range plus 360 names with no rows), every start 2026-10-11 (the live page sends each page's `anchor_first_date`, which can be earlier). Timings include the mysql client (`SELECT 1` = 0.01–0.02 s). Scripts: the two `.sh` files listed at the end.

### EXPLAIN

| Query | Key | Rows (estimate) |
|---|---|---|
| Old page filter (445 × `LOWER(TRIM(page_name)) = LOWER(TRIM(?)) AND day BETWEEN`) | `ads_report_day_campaign_adset_ad_unique`, range on day, then filter | 64,728 |
| New page filter (`page_name IN (...) AND day BETWEEN` per page) | `ads_report_page_day_idx`, range | 40,142 |
| Name lookup (`GROUP BY page_name HAVING MAX(day) >= ?`) | `ads_report_page_day_idx`, Using index for group-by | 111 |
| Old latest campaign status | inner GROUP BY: full index scan `ads_report_lookup_idx` 349,221, joined back by ref (derived 35,317,741) | 349,221 |
| New latest campaign status | `ads_report_day_campaign_adset_ad_unique` range (day >= start), `campaign_id IN` by index condition (derived 3,088,003) | 76,341 |
| Old latest ad set status | full table scans (type ALL) twice, hash join (derived 1,219,553,154) | 349,221 × 2 |
| New latest ad set status | `ads_report_day_campaign_adset_ad_unique` range, `ad_set_id IN` by index condition, filtered 50% (derived 14,118,995) | 75,151 |
| Old windows (`LOWER(TRIM(page_name)) IN`) | `ads_report_day_campaign_adset_ad_unique` range | 9,414 |
| New windows (`page_name IN`) | `ads_report_page_day_idx` range | 5,419 |
| data(), one page, old (`LOWER(TRIM(page_name)) = ?`, COALESCE day range) | none, type ALL | 349,221 |
| data(), one page, new (`page_name IN (?)`) | `ads_report_page_idx` ref | 48,480 |

### Timings, batch-data SQL (445 pages)

| Query | Old | New |
|---|---|---|
| Page filter alone (count and sums) | 1.76 s, 0.55 s (two runs) | 0.47 s, 0.34 s |
| Name lookup | — | 0.03 s |
| Campaign / ad set ids of the filtered rows | — | 0.37–0.63 s (campaigns), 0.27–0.32 s (ad sets); the code fetches both in one query |
| Latest campaign status | 5.40 s, 3.39 s, 2.82 s | 0.50 s, 0.53 s, 0.40 s |
| Latest ad set status | 2.28 s, 2.46 s, 1.83 s | 0.41 s (by ad_set_id) |
| **Main query (rows, sums, status, active_subcount)** | **5.76 s** | **1.69 s, 1.59 s** |
| Windows query | 0.065 s | 0.057 s |
| One page, data() filter | 0.22 s | 0.08 s |

So the batch-data SQL went from about 6 s to about 2.3 s at load 6 (name lookup + id query + main + windows). The 12.8 s first byte measured at load 9 should become about 3–4 s.

### Equality (decision 8)

Same input, old SQL vs new SQL, compared by counts, sums and a CRC32 checksum over every output column (no names printed):

| | Old | New |
|---|---|---|
| Filtered rows: count, spend, purchases, impressions, campaigns, ad sets | 33808, 2685619.53, 49447, 37096793, 1191, 1259 | same |
| Main query: rows, spend, messages, purchases, impressions, reach, link_clicks, sum(is_on), sum(active_subcount), checksum | 466, 2685619.53, 350407, 49445, 37096793, 32496974, 916391, 80, 80, 999948325880 | same |
| Windows: rows, spend today, spend 7d, purchases 7d, checksum | 641, 119286.32, 582268.80, 13815, 1385196528978 | same |
| Status: campaigns on (of those in the result) | 83 of 1191 | same; the old full-table version had 111 of 3045 over all campaigns |
| One page data(): count, spend | 6764, 74817.87 | same |

## Cold item-summary (decision 6)

`performance_schema` on production only holds my own digests, not the app's (the app's statements are not instrumented), so I rebuilt the item-summary queries from the code and timed each one for the same range (2026-10-11..2026-11-09; J&T window = end − 60 .. end − 1).

| Query (OwnerPrivateController) | Before | After | Key before → after | Equal |
|---|---|---|---|---|
| J&T stats: from_jnts ⋈ page_sender_mappings, `DATE(submission_time) BETWEEN` (:2346) | **28.03 s**, 10.98 s | **1.48 s**, 0.83 s | ALL 870,597 + hash join → `idx_from_jnts_submission_time` range 178,460 | 268 groups, 103,939 orders, checksum same |
| statRows: macro_output by date, page, item, `DATE(ts_date) BETWEEN` (:1918) | 1.48 s | 0.43 s | ALL 956,907 → `macro_output_ts_date_index` range 80,976 | 1645 groups, 51,210 orders, checksum same |
| pagesSeen: macro_output pages (:1968) | 1.01 s | 0.09 s | full index scan 956,907 → `macro_output_ts_date_page_idx` range 102,330 | 80 pages, checksum same |
| Ad spend per day: `DATE(day) BETWEEN` (:1984) | 0.38 s | 0.22 s | ALL 349,221 → `ads_report_day_campaign_adset_ad_unique` range 64,680 | 1997 groups, 2685619.53, checksum same |
| RtsProjection rows (macro_output ⋈ from_jnts by waybill), unchanged | 0.68 s | — | already indexed | — |
| daily_page_primary_item anchor / range, unchanged | 0.01 s each | — | indexed | — |

The cold path was dominated by the J&T join (a full scan of 1.4 GB, 11–28 s depending on load). The SQL of a cold item-summary goes from about 13–31 s to about 2–3 s. PHP work after the queries is not measured here.

## Lazy rendering (decision 7, P2): skipped

Not done. The expanded tables are Alpine `x-for` templates bound to `expandedPages[page].campaigns` with sorting, conditional formatting, ad set and ad drill-down, delivery columns and the fit-to-width script reading the DOM; rendering them in slices touches all of that, and there is no JS harness here to prove no feature is lost. Without production data in a browser I could not measure DOM count and long tasks before and after either. It should be its own spec with a browser check.

## Tests

- New, OwnerPrivateSpeedTest (5): names that differ only in case or spaces ("Page Uno", "page uno ", " PAGE UNO" requested as "PAGE UNO") give the same rows, sums, status and 7-day CPP; the latest day decides on or off (c3 on earlier, off on its latest day on another page; c4 off in range but on after the end date; c5 on before the start; c6 off on a later row that has no `day`, only `reporting_starts`), plus `active_subcount`; a page with no rows gets an empty bucket, alone and next to another page with its own start; the per-page data() endpoint gives the same status and sums (campaigns and ad sets levels); and the SQL shape for batch-data and data() at all three levels: no `LOWER(TRIM(page_name))` and no `FROM ads_manager_reports GROUP BY` without a WHERE.
- New, ItemSummaryOnceTest (1): `<html>` has `x-data="privateUI()"` and no `x-init`, and `init()` loads once.
- New, DayRangeFilterTest (1): `whereDayIn` keeps exactly the rows of `DATE(col) BETWEEN` on a date and a datetime column at the range edges (23:59:59 the day before, 00:00 of the start, 23:59:59 of the end, 00:00 the day after, NULL).
- New in ItemSummaryProfitMTest (1): a refresh=1 item-summary filters macro_output and ads_manager_reports with the column range.
- Fail first: the SQL-shape test, the item-summary-once test, the item-summary range test and DayRangeFilterTest failed on the old code; the four result tests passed on the old code (they pin today's numbers) and still pass.
- tests/Feature/OwnerPrivate: 261 tests, all pass.
- Full suite on this branch (d7fa368e): 4945 tests, 2 failures, the two known ones (ExampleTest::test_the_application_returns_a_successful_response, ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order), 6 skipped. No new failures against f5e5fb9f (its known 2; this branch adds 8 tests). The base suite was not re-run here.

## After deploy: expected speed and how to measure

Open /owner/private with the range 2026-10-11..2026-11-09, DevTools → Network:

- `item-summary`: one request per load (was two). Cached: unchanged (0.3–1.2 s). Cold (the Refresh button, `refresh=1`): expect about 3–5 s instead of 6–30 s.
- "Expand all" → `batch-data` (POST): expect a Waiting for server response (TTFB) of about 3–4 s at load 9 (was 12.8 s), total under 10 s (was 32 s). The rest of the 32 s was download and main-thread time; check the Timing tab.
- `ad-delivery` (POST, 2.8 s) is not changed.
- Rendering after "Expand all" (about 6 s of long tasks, 254k DOM) is not changed (decision 7 skipped).

## Not done / notes

- Decision 7 (lazy rendering) skipped, see above.
- /item (resources/views/item/index.blade.php:2) has the same `x-init="init()"` and so also loads item-summary twice. Not changed (outside /owner/private).
- The same `DATE(col) BETWEEN` and `LOWER(TRIM(page_name))` patterns are elsewhere in OwnerPrivateController (breakdown and daily paths, :653, :699, :743, :3889, :4348, :4961–5169) and in AdsManagerCampaignsController::historyData (:180). Not changed; outside this spec.
- Measurement scripts (read-only): C:\Users\Forbeast\AppData\Local\Temp\claude\2e760ef270dbe960\07319e29-2afc-45d1-9b8d-9083b1ed643e\scratchpad\m261\gen.sh (batch-data) and C:\Users\Forbeast\AppData\Local\Temp\claude\2e760ef270dbe960\07319e29-2afc-45d1-9b8d-9083b1ed643e\scratchpad\m261\is.sh (item-summary).

## Final sha

The tip of the branch is the commit that adds this file; the code is at d7fa368e.
