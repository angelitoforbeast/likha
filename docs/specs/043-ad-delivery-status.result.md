# Result 043: J&T delivery status and RTS% per campaign, ad set and ad in `/owner/private`

## What shipped

Commits (branch `feat/043-ad-delivery-status`, from `develop` at 26d5d4f; not pushed):

- `docs: spec 043 ad delivery status`
- `docs: plan and stories for spec 043`
- `feat: J&T delivery counts per ad, ad set and campaign from one grouped query, sharing the page row's per-waybill flags (spec 043)`
- `feat: read-only /owner/private/ad-delivery endpoint for the expand, same roles as /owner/private (spec 043)`
- `feat: five delivery columns in the campaigns column config, visible to every role of /owner/private until the config is saved again (spec 043)`
- `feat: Delivered, In transit, For return, Returned and RTS% on campaign, ad set and ad rows of the /owner/private expand, with a Walang ad id line (spec 043)`
- `fix: delivery label shows the range actually fetched and no longer claims to match the page row, Walang ad id shows for pages without campaign rows, page key trims spaces like SQL, route line restored (spec 043)`
- `docs: accepted findings of spec 043 in TODO`
- `fix: the expand partial shared with /item reaches the delivery helpers through $data, so /item shows nothing new and throws nothing; /item render pins updated for the partial (spec 043)`
- `docs: result for spec 043` (this file)
- `chore: merge develop (spec 042) into spec 043` (amendment 1)
- `feat: Total ng page (ayon sa order) line in each expanded page, after the campaigns and Walang ad id, from the same query (spec 043 amendment 1)`
- `docs: result for spec 043 amendment 1`

## Amendment 1 (2026-10-17)

The amendment is saved, never committed, in the main checkout as `handoff/043-ad-delivery-status/AMENDMENT-1.md`.

- **Keep the order-based count as built.** No code change: orders placed in the filter range, with their J&T status by waybill. The header says "orders <start> → <end>, J&T sa waybill". It does not claim to be the page row's sender-based 60-day RTS%. A comment in the partial says the same.
- **"Total ng page (ayon sa order)" line.**
  - In each expanded page it comes after the campaign rows and the "Walang ad id" line, with the same five columns, in bold on a grey band.
  - Its values are `page` from the same grouped query. That is the sum of every waybill bucket, so campaigns + "Walang ad id" = Total exactly, and RTS% = (returned + for return) / shipped is recomputed from the sums.
  - A page with no campaign rows shows it as a text line under "Walang ad id", inside the "Walang campaigns…" message.
  - On `/item`, which includes the same partial, it calls through `$data.adDeliveryTotal`, so nothing shows there.
  - Code: `adDeliveryTotal()` and `adDeliveryTotalText()` in `private.blade.php`. The two text lines share `_adDeliveryText()`.
  - Test: `S-043.10` (`AdDeliveryStatusTest::test_S_043_10_total_of_the_page_is_campaigns_plus_walang_ad_id`) uses 3 campaigns, 2 orders without an ad id and 1 with an unknown ad id. Total = (8 shipped, 4 delivered, 1 in transit, 1 for return, 2 returned), RTS% 37.5, all computed by hand. It also checks that campaigns + "Walang ad id" = Total for each of the five counts.
  - S-043.9 checks that the screen has the line, after "Walang ad id", in the table and in the empty-page text, and that it is guarded by `$data`.
  - Red run: S-043.9 `Failed asserting that '<!doctype html>…' contains "Total ng page (ayon sa order)"`. S-043.10 passed at once: the service already returned `page`.
- **Merged `develop` (59b725c, spec 042).**
  - The only conflict was in `TODO.md`, where both sides had appended a section. I kept both: 042 first, then 043.
  - `routes/web.php` merged by itself, with both sides' routes kept.
  - The merge changed no pinned render: `/owner/private` and the eight `/item` hashes were identical before and after it.
  - The new Total line then changed them. I re-pinned all of them after diffing every render: the only lines that differ are the Total line's markup and functions.
- **Story:** added S-043.10 to `qa/stories.md`.

No migration, no new table, nothing writes. Plan: `docs/plans/043-ad-delivery-status.md`.

### Where the numbers come from

- **`App\Services\AdDeliveryStatus::forPages($pages, $start, $end)`**. It makes two queries, however many pages and ads are involved.
  1. **The status counts: one grouped query.**
     - It reads `macro_output mo JOIN from_jnts j ON j.waybill_number = mo.waybill`, with these filters:
       - `mo.ts_date BETWEEN ? AND ?`;
       - `LOWER(TRIM(PAGE)) IN (?, …)`;
       - the waybill is not empty.
     - First it groups per page + waybill. The four flags come from `MAX(...)` over the waybill's J&T rows. The ad id is `MAX(NULLIF(TRIM(botcake_ad_id), ''))`.
     - Then it groups per page + ad id: shipped = `COUNT(*)` (waybills); delivered, in transit, for return and returned = `SUM` of the flags.
     - A waybill counts once per page, as with `COUNT(DISTINCT waybill)` in `data()`. A waybill that has orders with two different ad ids goes to the higher id.
  2. **The ad → ad set / campaign mapping.**
     - The query is `ads_manager_reports WHERE ad_id IN (…) GROUP BY ad_id, ad_set_id, campaign_id` with `MAX(day)`.
     - When an ad has more than one combination, the one with the latest `day` wins.
- **The flag SQL is reused, not copied.** `AdDeliveryStatus::jntFlagsSql($driver)` holds the four `is_delivered / is_returned / is_for_return / is_in_transit` expressions. `OwnerPrivateController::data()` now calls it in both its MySQL and its Postgres `_jnt_agg` statement. I checked that the SQL generated after the change is whitespace-identical to the old inline text for both drivers. `data()`'s numbers are unchanged.
- **Roll-up, in PHP:**
  - ad: the counts of its id;
  - ad set: the sum over its ads;
  - campaign: the sum over its ads, whatever page name the campaign row has in `ads_manager_reports`, listed under the order's page.
  - RTS% at every level = (returned + for return) / shipped × 100, recomputed from the sums.
- **"Walang ad id"**: waybills whose ad id is null or empty, or is not found in `ads_manager_reports`. `page` = the sum of everything, so campaigns + "Walang ad id" = `page` exactly (test S-043.5).

### Endpoint

`GET /owner/private/ad-delivery?start_date=&end_date=&pages[]=` (`OwnerPrivateController::adDelivery`):

- **Access:** `checkAccess()`, so CEO, Marketing - OIC and Marketing get 200, other roles get 404 and guests get 401 from the auth middleware.
- **Validation:** dates must be `Y-m-d` with end ≥ start; `pages` must be a list of 1–500 strings of at most 255 characters each.
- **Response:** `{ok, by_page: {<name as sent>: {campaigns: {id: {name, counts}}, adsets: {id: counts}, ads: {id: counts}, no_ad, page}}}`. Each `counts` = shipped, delivered, in_transit, for_return, returned, rts_pct.

### Screen

- **New columns.** In the `/owner/private` expand, campaign, ad set and ad rows get Delivered, In transit, For return, Returned and RTS% (`ad_delivered`, `ad_in_transit`, `ad_for_return`, `ad_returned`, `ad_rts_pct`).
- **Requests.**
  - Expanding a page fetches the delivery map next to the campaigns request.
  - "Expand all" makes one request for all its pages.
  - Ad sets and ads take their values from the map that is already loaded, with no extra request.
  - If the request fails, the columns show "—" and the expand still works.
- **Header line.** "· Delivered / In transit / For return / Returned / RTS%: orders Oct 1 → Oct 15, J&T sa waybill". It shows the range the numbers were fetched for. That is the filter range, not the expand's spend window (`anchor_first_date` → end date).
- **Delivery-only campaigns.** Campaigns that have orders in range but are not in the expand list (no spend in the window, or listed under another page name) are added as rows with the five numbers only, no chevron and "Off". Without them the sum would not be complete.
- **"Walang ad id".** A line under the campaigns shows the same five columns. A page with no campaign rows shows it as one line of text under the "Walang campaigns…" message.
- **Column system.** The five columns are in the `campaigns` catalog, labelled "… (/owner/private)", and in `DEFAULT_VISIBLE`. Order, hide, per-role visibility and conditional formatting work as for the other columns.
  - A saved config from before this change doesn't list them in its `order`. While that is so, `VISIBLE_TO_ROLES_UNTIL_SAVED` adds them to Marketing's and Marketing - OIC's saved visible lists, so every role of `/owner/private` sees them by default.
  - Once the settings page is saved again, each role's saved list decides.
  - For the CEO they are visible because they are not in the saved `hidden` list.

### Other screens

- **`/ads_manager/campaigns` and `/item`:** they use the same `campaigns` column config, but each renders only the ids in its own JS column list. The new ids are not in those lists, so the columns do not appear there (asserted in S-043.9). Their code and queries are unchanged, so they are not slower. Their endpoints (`AdsManagerCampaignsController`) are untouched.
- **`/item` also includes the expand partial** (`owner._private_expand_inline`, in `_table_suppliers`, `_table_old` and `_il_expand`).
  - Its Alpine component has no `adDelivery*` helpers, so the partial calls them through `$data.<name>`. On `/item` the label is empty, there is no "Walang ad id" line, no request is made and no error is thrown.
  - The full suite caught this (`SuppliersGroupTest` pins the `/item` renders). Its eight hashes were re-pinned after diffing every render: the only lines that differ are the partial's own.
- **`/owner/column-settings/campaigns`:** lists the five columns with "(/owner/private)" in the label.

### Speed

- **Queries:** two per request: one grouped status query, one mapping query. No query runs per row, ad set or ad (S-043.6, 40 ads on 3 pages).
- **Timing on a larger test set** (sqlite in memory, throwaway test that isn't committed: 30,000 orders on 30 pages, 52,500 J&T rows, 200 ads × 20 days of report rows, the same indexes as production):

  | Pages in the request | Time | Queries | Shipped counted |
  |---|---|---|---|
  | 1 | 7 ms | 2 (5 ms + 0 ms) | 1,000 |
  | 30 | 45 ms | 2 (44 ms + 1 ms) | 15,000 |

  MySQL timing on production data was not measured: there is no server access from here.

### Indexes

All are present; no migration was added.

| Index | Covers |
|---|---|
| `macro_output_botcake_ad_id_idx` | `macro_output.botcake_ad_id` |
| `macro_output.waybill` | `->index()` in `2025_08_04_000129` |
| `macro_output_ts_date_page_idx` | `macro_output (ts_date, PAGE)` |
| `from_jnts_waybill_number_index` | `from_jnts.waybill_number` |
| `ads_report_adid_idx` | `ads_manager_reports.ad_id` |

The query filters on `LOWER(TRIM(PAGE))`, exactly as `data()` does, so it uses the `ts_date` part of the index and then the waybill indexes for the join.

## Case table

Command: `php.bat vendor/bin/phpunit --filter AdDelivery` (and `SharedPagesBasePinTest`).

| Case | Test | Result |
|---|---|---|
| S-043.1 | `AdDeliveryStatusTest::test_S_043_1_an_ad_counts_its_orders_by_jnt_status_with_rts_pct` | pass |
| S-043.2 | `AdDeliveryStatusTest::test_S_043_2_a_waybill_not_in_jnt_or_no_waybill_counts_nowhere` | pass |
| S-043.3 | `AdDeliveryStatusTest::test_S_043_3_ad_sets_and_campaigns_roll_up_their_ads`, `…_an_ad_follows_its_latest_report_row` | pass, pass |
| S-043.4 | `AdDeliveryStatusTest::test_S_043_4_orders_outside_the_range_or_of_another_page_do_not_count` | pass |
| S-043.5 | `AdDeliveryStatusTest::test_S_043_5_orders_without_a_known_ad_id_land_in_walang_ad_id_and_everything_adds_up` | pass |
| S-043.6 | `AdDeliveryStatusTest::test_S_043_6_one_grouped_query_however_many_ads`, `…_no_pages_means_no_query` | pass, pass |
| S-043.7 | `AdDeliveryEndpointTest::test_S_043_7_roles_of_owner_private_get_the_counts` (CEO, Marketing - OIC, Marketing), `…_other_roles_get_404` (incl. guest 401), `…_a_bad_query_is_422` (9 data sets) | pass (13 runs) |
| S-043.8 | `AdDeliveryColumnsTest::test_S_043_8_new_columns_start_visible_for_every_role_of_owner_private`, `…_nothing_saved_shows_them_to_the_ceo` | pass, pass |
| S-043.9 | `AdDeliveryColumnsTest::test_S_043_9_owner_private_lists_the_columns_and_loads_the_delivery_map_on_expand` | pass |
| S-043.10 | `AdDeliveryStatusTest::test_S_043_10_total_of_the_page_is_campaigns_plus_walang_ad_id`, plus the Total assertions in `AdDeliveryColumnsTest::test_S_043_9…` | pass |
| (pins) | `SharedPagesBasePinTest` (2), `SuppliersGroupTest` (/item renders), re-pinned; every diff checked to be only this change | pass |

Red runs:
- S-043.1–S-043.6: `Error: Class "App\Services\AdDeliveryStatus" not found`.
- S-043.7: `Expected response status code [200] but received 404` (no route yet).
- S-043.8: `Failed asserting that two arrays are identical` (ids not in the catalog).
- S-043.9: `Failed asserting that '<!doctype html>…' matches PCRE pattern "/\{ id:'ad_delivered',/"`.
- The 422 data sets added in the fix loop passed at once: the validation was already there.

## Suite

**After amendment 1 (merge of develop 59b725c and the Total line):** `php.bat vendor/bin/phpunit` gives `Tests: 1343, Assertions: 16907, Errors: 1, Failures: 1, PHPUnit Deprecations: 5, Skipped: 3`. The two that don't pass are again only the known `ExampleTest` (failure) and `ImportStartTest` (error). The 19 tests added by 042 pass.
`php.bat vendor/bin/phpunit` on the final tree: `Tests: 1324, Assertions: 16694, Errors: 1, Failures: 1, PHPUnit Deprecations: 4, Skipped: 3`. The two that don't pass are the known ones:
- `ExampleTest::test_the_application_returns_a_successful_response` (failure);
- `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (error, fresh worktree).

All tests under `tests/Feature/OwnerPrivate` pass: 184 tests, 1443 assertions. `php -l` is clean on every changed PHP file.

## Story changes

- **Stories added.** The brief had none, so I wrote section "Slice 043" (S-043.1–S-043.9) in `qa/stories.md` from the brief's Tests list. `story-writer` was not run, and the stories were not confirmed before the code: the start prompt asked for one run and an answer in the final message.
- **S-043.2 differs from the brief.** The brief says an order whose waybill is not yet in J&T "counts as shipped only, as on the page row". In `data()` the page row inner-joins `_jnt_agg`, so such an order is not shipped and counts nowhere. The breakdown's Hold column counts those orders. The case follows the page row: it counts nowhere.

## Review

`skeptic-reviewer`, at adversarial depth for the route and standard depth for the rest, one fix loop.

- **Major (spec), sent to the reviewer, not fixed in code.** The visible `/owner/private` page row is not the row these numbers add up to. See "What the brief got wrong". Until the owner decides, the label no longer claims "gaya ng page row".
- **Major (legacy rule): fixed.** A whitespace-only change to the `item-summary` route line is undone.
- **Minors fixed:**
  - the "Walang ad id" line now shows for a page without campaign rows;
  - the page key trims spaces only, like SQL;
  - the label shows the fetched range;
  - new tests: 422 for keyed `pages`, 501 pages and a 256-character name; 401 for a guest; one waybill with two ad ids.
- **Minors accepted, with reasons in `TODO.md`:**
  - no test against the real `data()` row (`data()` can't run on sqlite);
  - `partial_date` is ignored;
  - a MySQL accent-insensitive page key can return a different spelling;
  - `PAGE` is written by hand on Postgres;
  - any date range is accepted for up to 500 pages;
  - an expand already loaded keeps its numbers after a date change;
  - delivery-only rows show "Off".
- **Re-check of the fix commit:** all fixes right, no new blocker or major.
- **Found after the review by the full suite:** the shared partial broke on `/item`. Fixed in the last `fix:` commit, which the reviewer did not re-check: a three-binding guard plus a test line in S-043.9.
- **Browser check:** skipped. There is no running app with the server's data here, and the session has no browser tools wired to it.
  - Instead, the inline script of the rendered page compiled, and its merge, label and "Walang ad id" functions were run in node with a sample map. The results were as expected.
  - Check on the server: expand a page with ad ids, expand a campaign and an ad set, use "Expand all", and hide a column in column settings for Marketing.
- **Build:** `npm run build` was not run, because no Vite asset changed (Blade and inline script only).

## What the brief got wrong

1. **The visible page row is not `data()`.**
   - `/owner/private` loads `/owner/private/item-summary` (`itemSummary()`). No view fetches `/owner/private/data`.
   - The page row's RTS% / Del% / Transit% come from `itemSummary()`. It counts J&T rows matched to the page by sender (`page_sender_mappings`), over the 60 days before the date, per item + COD, with other status rules.
   - Those rows carry no ad id, so they cannot be split per ad.
   - The new columns use `data()`'s rules, as the brief says: orders in the filter range, J&T by waybill, distinct waybills.
   - So campaigns + "Walang ad id" add up exactly to `data()`'s page numbers, but not to the RTS% the owner sees on the page row.
   - **Decided by the owner (amendment 1):** keep the order-based count, and add a "Total ng page (ayon sa order)" line. Done.
2. **"A waybill not yet in J&T counts as shipped only, as on the page row":** on the page row (`data()`) it is not shipped at all. See S-043.2.
3. **"The page's date range from the filter":** the expand's spend metrics use a different window (`anchor_first_date` → end date). The delivery columns use the filter range, and the header says so. The two ranges can differ within one row.
4. **The spec says to send the plan and wait for "go"; this run did not wait.** The start prompt asked for one run with a plan file and commits per part.
5. **The brief's live example** (one campaign: 321 orders, 153 delivered, 83 returned, 18 for return, 37 in transit, 30 not yet in J&T) was not re-checked: there is no server access.

## Conflicts between the spec and CLAUDE.local.md

- **Stories:** CLAUDE.local.md wants stories confirmed before code. They were not; see "Story changes".
- **Plan gate:** CLAUDE.local.md wants a reviewer "go" on the plan before code. It wasn't asked for; see point 4 above.
