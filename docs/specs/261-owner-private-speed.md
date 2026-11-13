# 261: /owner/private opens fast and "Expand all" takes seconds, not 40

## Why
Measured on 2026-11-10 on the live site, signed in, range 2026-10-11..2026-11-09, 445 page rows:
- Page HTML 0.5 s.
- `/owner/private/item-summary`: 0.3 to 1.2 s cached; 6 s cold or with refresh=1, and about 30 s once under load.
- The page requests item-summary TWICE on every load (two identical requests at the same moment).
- DOM: about 121,000 elements before expanding.
- "Expand all":
  - `/owner/private/ad-delivery` POST took 2.8 s.
  - `/ads_manager/campaigns/batch-data` POST took **32 s**, with 12.8 s to first byte.
  - Rendering took about 6 s of long tasks (the worst 3 s), and the DOM grew to about 254,000 elements.
- Server load average was about 9 at the time.

Causes found in AdsManagerCampaignsController::batchData (around line 798):
1. The page filter is `LOWER(TRIM(page_name)) = LOWER(TRIM(?))` OR-ed 445 times, and the windows query uses `LOWER(TRIM(page_name)) IN (...)`. EXPLAIN shows a day-range scan of about 64,728 rows (key ads_report_day_campaign_adset_ad_unique) instead of 518 rows through ads_report_page_day_idx (page_name, day): 0.12 s against 0.01 s per page.
2. The "latest status" subqueries (latest day per campaign_id, and per ad_set_id, over the WHOLE ads_manager_reports table of about 349k rows), joined back. One of them alone takes 5.6 s, and there are two.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | No new cache layer for batch-data. Fix the queries. | The owner chose "index first, no cache". |
| 2 | Page filter: resolve the requested names to the exact stored `page_name` values first (one small `SELECT DISTINCT page_name` within the date range, matched in PHP with the same trim and lower rule), then query with `page_name IN (...)` so ads_report_page_day_idx is used. The result must be identical to today's, including names that differ only in case or spaces. | Index use without changing what matches. |
| 3 | Latest on/off status only for the campaign_ids and ad_set_ids that appear in the main result: a second query `WHERE campaign_id IN (...)` using the (campaign_id, ad_set_id, day) index, or an equivalent. Never a full-table GROUP BY. Same "latest day" rule as today. | 5.6 s × 2 down to milliseconds. |
| 4 | Apply the same fixes to the per-page `/ads_manager/campaigns/data` path wherever it uses the same patterns, and to the windows query. | One behaviour. |
| 5 | Page load: item-summary is requested once per load. | It is a duplicate today. |
| 6 | Profile the cold item-summary (`refresh=1`) and fix its top slow queries the same way (index use, no full-table aggregates), if they exist. Report the per-query timings you found, before and after. | Cold page load 6–30 s. |
| 7 | (P2) If time allows, render the expanded campaign tables lazily: the rows near the viewport first, the rest as they scroll in or in idle slices. No feature loss. Measure the DOM count and long tasks before and after. Skip it if it is risky and say so. | The 254k DOM causes a freeze of several seconds. |
| 8 | Numbers must not change. The JSON of batch-data, data and item-summary for the same inputs must equal today's (key order aside). | Speed only. |

## Done when
- Tests:
  - batch-data returns the same rows and status for pages whose names differ only in case or trailing space, for a campaign whose latest day is off while an earlier day was on, and for a page with no rows.
  - item-summary is requested once (view or JS test).
  - Each test fails first where that makes sense.
- Full suite: no new failures against f5e5fb9f. The known 2 are ExampleTest and ItemTableFitScriptTest.
- Production evidence, read-only, in the result:
  - EXPLAIN of the old and new page filter, and of the latest-status query, with rows examined and the key used;
  - timings of the old and new SQL for the 445-page range;
  - an equality check that the old and new SQL give the same aggregate counts and sums for that range.
- The result lists the expected speed after deploy and how to measure it: the browser Network timing of batch-data and item-summary.

## Out of scope
Caching (decision 1), changing any number or column, Incepxion (a later cherry-pick), deploying.

## Budget
Medium job. At most 2 attempts per failing step, then stop and write what you tried.
