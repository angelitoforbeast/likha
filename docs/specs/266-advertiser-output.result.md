# Result 266: Ads daily output

Branch `feat/266-advertiser-output` from `462b183e`. Risk tier kept: low-medium (read-only page on live tables).

## What changed

| File | Change |
|---|---|
| `app/Http/Controllers/AdsDailyOutputController.php` | New. `index()` checks `NavAccess::allows(role, '/ads_manager/daily-output')` (403 otherwise), reads `?day=Y-m-d` (default today, Manila), runs five SELECTs, builds the tiles, the five lists, the promo per campaign and the 14-day table. Static `normalize()` / `promoFor()` are the promo matcher. |
| `resources/views/ads_manager/daily_output.blade.php` | New. `<x-layout>` page like the 265 timetable: previous day / date picker / next day (no next after today), "Huling sync" (with "hindi pa buo ang araw" on today), tiles, the 14-day table (each day a link), and one table per kind (New, Turned off, Back on, Budget up, Budget down) with page, campaign, promo, ad set, ads, budget old → new (budget lists), spend kahapon / ngayon. "Walang galaw" when a list is empty. No JS, no new assets. |
| `routes/web.php` | `GET /ads_manager/daily-output` → `ads_manager.daily_output`, next to `/ads_manager/campaigns/history` inside the same `web, auth, allowed_ip` group (guests go to `/login`). |
| `database/migrations/2026_11_25_100000_add_ads_daily_output_nav_link.php` | New data migration, a copy of the 265 one: key `ads_daily_output`, label `Ads Daily Output`, `/ads_manager/daily-output`, `fa-solid fa-calendar-day`, `ads_manager/daily-output*`, sort order max + 1; visibility CEO 1, Marketing 1, Marketing - OIC 1, Data Encoder 0, Data Encoder - OIC 0. Idempotent; `down()` removes the link and its rows. |
| `tests/Feature/AdsDailyOutput/*`, `tests/Unit/AdsDailyOutputPromoTest.php` | New: base case, page tests (S1), access tests (S2), performance tests (S3), matcher unit tests (S1.3). |

No new table, column or index.

## How the page reads the data

Definitions as in the spec, per `ad_id` (rows with an empty `ad_id` are left out; the Meta sync never writes one):
New = `ad_catalog.first_started = D`; Turned off = spend(D-1) > 0 and spend(D) <= 0 or no row; Back on = spend(D-1) <= 0 or no row, spend(D) > 0 and `first_started < D` (an ad not in `ad_catalog` is neither new nor back on: never guessed); Budget up/down = per `ad_set_id`, MAX(`ad_set_budget`) on D vs D-1, both present and different; Running = spend(D) > 0.

Lists are grouped page → campaign → ad set (natural sort). New / Off / Back on rows count the ads of that kind in the ad set and sum their spend; budget rows show the ad set's ads, spend and old → new budget.

Promo: for each campaign, the page's `page_item_settings` rows with `effective_date <= D` (latest per item wins); each `|` part of the campaign name and each item are normalized (uppercase, leading `<n> x ` dropped, spaces removed) and match when equal or one contains the other. Parts shorter than 3 characters or only digits/punctuation (`FB`, `1000`) are not matched. Exactly one distinct promo → shown; none or two different → `—`.

## The queries (MySQL, as built)

1. The day (D and D-1), one row per ad:

```sql
select ad_id, MAX(page_name) AS page_name, MAX(campaign_id) AS campaign_id, MAX(campaign_name) AS campaign_name,
       MAX(ad_set_id) AS ad_set_id, MAX(ad_set_name) AS ad_set_name,
       SUM(CASE WHEN day = :d THEN COALESCE(amount_spent_php, 0) ELSE 0 END) AS spend,
       SUM(CASE WHEN day = :d_1 THEN COALESCE(amount_spent_php, 0) ELSE 0 END) AS prev_spend,
       MAX(CASE WHEN day = :d THEN ad_set_budget END) AS budget,
       MAX(CASE WHEN day = :d_1 THEN ad_set_budget END) AS prev_budget,
       MAX(CASE WHEN day = :d THEN updated_at END) AS synced_at
from `ads_manager_reports`
where `day` between :d_1 and :d and `ad_id` <> ''
group by `ad_id`
```

2. 14-day spend moves (only the (day, ad) pairs that changed reach PHP). Each row counts as "today" for its own day and as "yesterday" for the next day; the next day is a literal `CASE day WHEN '2026-10-29' THEN '2026-10-30' ...` (14 pairs) so the same SQL runs on MySQL and SQLite:

```sql
select pd, ad_id, SUM(today) AS today, SUM(prev) AS prev from (
  (select CAST(day AS CHAR) AS pd, ad_id, COALESCE(amount_spent_php, 0) AS today, 0 AS prev
   from `ads_manager_reports` where `day` between :d_13 and :d and `ad_id` <> '')
  union all
  (select CASE day WHEN :d_14 THEN :d_13 ... WHEN :d_1 THEN :d END AS pd, ad_id, 0 AS today, COALESCE(amount_spent_php, 0) AS prev
   from `ads_manager_reports` where `day` between :d_14 and :d_1 and `ad_id` <> '')
) as `u`
group by `pd`, `ad_id`
having (SUM(prev) > 0 AND SUM(today) <= 0) OR (SUM(prev) <= 0 AND SUM(today) > 0)
```

3. 14-day budget moves, the same shape per ad set: `MAX(bt) AS budget, MAX(bp) AS prev_budget ... group by pd, ad_set_id having MAX(bt) IS NOT NULL AND MAX(bp) IS NOT NULL AND MAX(bt) <> MAX(bp)` (extra filter `ad_set_id <> ''`).

4. `ad_catalog`: `select ad_id, first_started, page_name, campaign_id, campaign_name, ad_set_id, ad_set_name from ad_catalog where (first_started between :d_13 and :d or ad_id in (:ads that started spending))` — the new ads of the 14 days and the first_started of back-on candidates (`first_started` index + `ad_id` unique index). No join to `ads_manager_reports` in SQL (no collation risk between the two tables).

5. Promo: `select page_name, item_name, promo from page_item_settings where effective_date <= :d and LOWER(TRIM(page_name)) IN (:pages in the lists) order by effective_date, id` (skipped when every list is empty).

Plus `Schema::hasTable` for the three tables and `hasColumn(page_item_settings, promo)`.

## EXPLAIN notes

- No MySQL was reachable without credentials and no server was touched, so the plan was checked on SQLite with the live indexes recreated (`ads_report_day_campaign_adset_ad_unique (day, campaign_id, ad_set_id, ad_id)`, `ads_report_adid_idx`, `ads_report_lookup_idx`, `ads_report_page_day_idx`, `ads_report_page_idx`). `test_S3_1_every_query_is_a_plain_select_and_reports_are_read_by_a_day_range` asserts that every `from ads_manager_reports` is a `SEARCH ... USING INDEX ads_report_day_campaign_adset_ad_unique (day>? AND day<?)` and never a `SCAN`:

```
SEARCH ads_manager_reports USING INDEX ads_report_day_campaign_adset_ad_unique (day>? AND day<?)
USE TEMP B-TREE FOR GROUP BY

CO-ROUTINE u
COMPOUND QUERY
LEFT-MOST SUBQUERY
SEARCH ads_manager_reports USING INDEX ads_report_day_campaign_adset_ad_unique (day>? AND day<?)
UNION ALL
SEARCH ads_manager_reports USING INDEX ads_report_day_campaign_adset_ad_unique (day>? AND day<?)
SCAN u
USE TEMP B-TREE FOR GROUP BY
```

- MySQL reasoning: `day` is the first column of the unique index, so each `day between` is a `range` read on it (2 days ≈ 1,500 rows; 14 + 14 days ≈ 2 × 10,000 rows). The union is a derived table that MySQL materializes internally and groups ("Using temporary"): that is the engine's own work table for this one SELECT, not a `CREATE TEMPORARY TABLE` or CTAS, and a plain InnoDB SELECT is a consistent read that takes no locks blocking the sync's inserts. PHP receives ~750 rows for the day and a few dozen per day for the 14-day table.

## Tests

New: `tests/Feature/AdsDailyOutput` (18 tests) and `tests/Unit/AdsDailyOutputPromoTest.php` (6 tests).

| Case | Test |
|---|---|
| S1.1 | `AdsDailyOutputPageTest::test_S1_1_today_shows_the_summary_tiles_and_the_last_sync_of_the_day` (a small 11-12 like live: 4 new incl. one with no spend yet, 4 off in 3 campaigns incl. one with no row today, 1 back on, an ad not in the catalog counted neither way, budget up 500→1000 and down 1000→800, a budget only today not counted, 9 running ads on 4 pages, last sync 18:46 from today's rows only, rows without ad_id and other days ignored), `test_S1_1_a_date_picker_and_previous_and_next_day` |
| S1.2 | `test_S1_2_each_list_is_grouped_by_page_then_campaign_with_ad_set_ads_and_spend` |
| S1.3 | `test_S1_3_the_promo_is_shown_only_on_a_unique_match_with_the_latest_effective_date` (latest ≤ D, a future promo ignored, an older day reads the older promo, two different promos → —, no item → —); `Tests\Unit\AdsDailyOutputPromoTest` (SIDEMIRROR vs "2 x SIDE MIRROR", "1000-Body Lotion - F" vs "1 x BODY LOTION", no match, two promos, `FB`/`1000` not matched) |
| S1.4 | `test_S1_4_a_14_day_table_newest_first_each_day_links_to_that_day` (14 rows 11-12 → 10-30, today's row equals the tiles, off/on/budget/new on earlier days, the first day uses 10-29 as its yesterday, 10-29's new ad left out, a link opens that day) |
| S1.5 | `test_S1_5_an_empty_day_shows_walang_galaw_in_every_list` |
| S2 | `AdsDailyOutputAccessTest` (copy of 265's): migration row and visibility, idempotent + down, CEO / Marketing / Marketing - OIC open it and see the link, Data Encoder / Data Encoder - OIC / no role get 403 and no link, guests to `/login`, access follows the nav-settings checkbox |
| S3 | `AdsDailyOutputPerformanceTest::test_S3_1_every_query_is_a_plain_select_and_reports_are_read_by_a_day_range` (5 data queries, all SELECT, same count for another day, day bounds D-1..D and 10-29..11-12, the plan above), `test_S3_2_a_month_of_live_volume_renders_in_under_two_seconds` |

S3.2 measured: 30 days × 740 ads = 22,200 `ads_manager_reports` rows (spend switching on/off more often than live), 3,000 `ad_catalog` rows, 1,980 `page_item_settings` rows (66 pages × 5 items × 6 dates). After one warm-up request the full page request (layout included) took 0.06 s on SQLite in memory on this Windows machine; the test asserts under 2 s and that every listed campaign got its promo.

Commands (Herd PHP 8.4.16, PHPUnit 11.5.24, SQLite in memory from `phpunit.xml`):

```
php vendor/bin/phpunit tests/Unit/AdsDailyOutputPromoTest.php tests/Feature/AdsDailyOutput
OK (24 tests, 354 assertions)
```

```
php vendor/bin/phpunit
Time: 18:24.964
Tests: 5052, Assertions: 80467, Failures: 3, PHPUnit Deprecations: 102, Skipped: 6.
```

The 3 failures are the known pre-existing ones at `462b183e` (none is new): `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response`, `Tests\Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`, and `Tests\Feature\OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_...` with data set `/owner/private` (same pinned-sha mismatch `5055307b…` as in result 265).

`vendor/bin/pint` was run on the controller and the unit test. The feature tests and the migration keep the 265 style they copy (pint's `php_unit_method_casing` would rename the story-named test methods).

## Not done / notes

- MySQL `EXPLAIN` itself was not run (no reachable MySQL without credentials; no server access by rule). The SQL above was compiled with the MySQL grammar to check its form and binding order.
- "Who did it" is not shown (not recorded; out of scope).
- Release: `php artisan migrate --force` runs the one new data migration (one `nav_links` row and five `nav_link_role_visibility` rows).
