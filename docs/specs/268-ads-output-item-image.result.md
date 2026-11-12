# Result 268: Ads daily output shows the item, the right promo and the main flow picture

Branch `feat/268-ads-output-item-image` from `8e93690e` (live). Risk tier kept: low (read-only page). No new table, column, index, route or migration; same access.

## What changed

| File | Change |
|---|---|
| `app/Http/Controllers/AdsDailyOutputController.php` | The promo read (`promoItems`) became `itemSettings` (same query plus `effective_date`, so the promo can be read for D or D-1). Two new reads: `primaryItems` (daily_page_primary_item) and `pictures` (page_settings chatbot/picture). `describe()` gives each campaign its item, promo and picture; `group()` takes the day used and the moment for the picture. The 266 matcher was split: `matchedItems()` returns the matching items, `promoFor()` (kept for its unit tests) uses it. |
| `resources/views/ads_manager/daily_output.blade.php` | New columns **Picture** (56 px rounded thumbnail, `loading="lazy"`, `referrerpolicy="no-referrer"`, link to the full image in a new tab with `rel="noopener noreferrer"`; grey placeholder when none) and **Item** (with a small "(main item)" note when it came from the page's sales, "(main item, kahapon)" or "(main item, Nov 9)" when the day had no row yet). **Promo** shows the item's promo, else "walang promo na naka-set" (muted) when there is an item, else "—". |
| `tests/Feature/AdsDailyOutput/AdsDailyOutputItemTest.php` | New: S1 and S2 with the live data shape. |
| `tests/Feature/AdsDailyOutput/AdsDailyOutputTestCase.php` | Runs the real daily_page_primary_item and page_settings migrations; `primary()` and `picture()` helpers. |
| `tests/Feature/AdsDailyOutput/AdsDailyOutputPageTest.php` | 266 S1.3: the Mirror campaign matches two items (1 x and 2 x SIDE MIRROR), so its item is now the page's main item: a main-item row was added to the seed and the item asserted. |
| `tests/Feature/AdsDailyOutput/AdsDailyOutputPerformanceTest.php` | S3.1 counts 7 data queries (was 5), checks the two new queries' bounds, bindings and plans. S3.2 adds 1,980 main-item rows and 198 picture rows; its items are one name per product (`1 x ITEMi`) so every campaign has a unique match. |

## How the item, promo and picture are chosen

Per campaign in each list, with the **day used** = D for New, Back on, Budget up/down, and D-1 for Turned off:

1. **Item**: the page's `page_item_settings` items with a row `effective_date <= day used` are matched against the campaign name with the 266 matcher (each `|` part normalized: uppercase, leading `<n> x ` dropped, spaces removed; equal or one contains the other; parts under 3 characters or only digits not matched). Exactly one item matches → that item. Otherwise (none, or several) → the page's main item from `daily_page_primary_item` on the day used; if that day has no row yet (the table is filled by each encode and import and by the 03:50 refresh, so early in the day today's row can be missing), the latest earlier row within 14 days, shown with "(main item, kahapon)" or its date. No match and no main item → no item.
2. **Promo**: that item's latest `page_item_settings` row with `effective_date <= day used` (item names compared trimmed, case- and space-insensitive). No row, or the promo is `NONE`/empty (Likha's "walang promo") → "walang promo na naka-set". No item → "—".
3. **Picture**: the page's `page_settings` row (provider `chatbot`, key `picture`, not secret) in effect at the moment T: `created_at <= T` and (`replaced_at` null or `> T`); T = end of D (or now, for today) for New/Back on/budget, end of D-1 for Turned off. Only a valid `http(s)://` URL is shown; anything else, or none → grey placeholder. Printed with Blade `{{ }}` (escaped).

Live examples (as tested): Chloe Santiago new → 1 x SIDE MIRROR, walang promo na naka-set; Faye Navarro new → 1 x BODY LOTION, walang promo na naka-set; Karla Chavez back on → 1 x EGG FRYING MOLD (main item, kahapon when 11-12 has no row yet), 99+100(SF)= 199; Patricia Mercado off → 1 x REFLECTIVE STICKER (D-1), 99+100(SF)= 199.

Note on the rule: when a campaign matches several items (e.g. both "1 x SIDE MIRROR" and "2 x SIDE MIRROR") the item is the main item, as the spec says. If the main item is a different product from the one the campaign names, the page shows that main item with its "(main item)" note; 266 showed "—" for such a promo only when the matched promos differed.

## The new SQL (MySQL, as built)

```sql
-- main items: listed pages, D-14..D (D and D-1 plus the lookback when a day has no row yet)
select `ts_date`, `page_key`, `primary_item` from `daily_page_primary_item`
where `page_key` in (:pages) and `ts_date` between :d_14 and :d
order by `ts_date` desc

-- promos (the 266 read plus effective_date)
select `page_name`, `item_name`, `promo`, `effective_date` from `page_item_settings`
where `effective_date` <= :d and LOWER(TRIM(page_name)) IN (:pages)
order by `effective_date` asc, `id` asc

-- main flow pictures in effect at the end of D-1 or of D (now, for today)
select `page_name`, `value`, `created_at`, `replaced_at` from `page_settings`
where `provider` = 'chatbot' and `key` = 'picture' and `is_secret` = 0
  and LOWER(TRIM(page_name)) IN (:pages)
  and `created_at` <= :at_d and (`replaced_at` is null or `replaced_at` > :at_d_1)
order by `created_at` asc, `id` asc
```

All three run only when a list has rows, once per request. Data queries per request: 7 (was 5), the same number for any day. Plans (SQLite, asserted in S3.1): `SEARCH daily_page_primary_item USING INDEX dppi_page_date_idx (page_key=? AND ts_date>? AND ts_date<?)`; `SEARCH page_settings USING INDEX page_settings_provider_key_active_index (provider=? AND key=?)`. On MySQL the same indexes exist (`dppi_page_date_idx (page_key, ts_date)`, `(provider, key, active)`); rows read: pages × at most 15 main-item rows, and the chatbot pictures of all pages (a few hundred rows at most) filtered by page in the engine.

## Tests

| Case | Test |
|---|---|
| S1.1, S1.3 | `AdsDailyOutputItemTest::test_S1_1_and_S1_3_the_item_is_the_campaign_match_else_the_main_item_of_the_day_used` (Chloe / Faye main item of D; Karla back on with no 11-12 row → 11-11 row, "(main item, kahapon)"; Patricia off reads D-1 although she has another main item on D; a unique campaign match beats the main item; no match and no main item → none) |
| S1.2, S1.3 | `test_S1_2_and_S1_3_the_promo_is_the_promo_of_that_item` (the four live examples; latest effective_date <= day used, a later one ignored; another item's promo does not leak; "walang promo na naka-set" twice; "—" with no item; a promo cleared to `NONE` counts as not set) |
| S2 | `test_S2_the_main_flow_picture_in_effect_then_as_a_safe_thumbnail` (now for today, end of D-1 for off, end of a past day, before any picture → none; `javascript:` and another provider → placeholder; `&` printed as `&amp;`; link target `_blank`, lazy, no-referrer) |
| S3 | `AdsDailyOutputPerformanceTest` S3.1 (7 data queries, all SELECT, the same for another day, bounds, bindings and plans of the new queries) and S3.2 (a month of live volume plus main items and pictures: 0.06 s, every campaign has its promo and its current picture) |

Commands (Herd PHP 8.4.16, PHPUnit 11.5.24, SQLite in memory):

```
php vendor/bin/phpunit tests/Unit/AdsDailyOutputPromoTest.php tests/Feature/AdsDailyOutput
OK (27 tests, 589 assertions)
```

```
php vendor/bin/phpunit
Time: 18:26.559
Tests: 5055, Assertions: 80702, Failures: 3, PHPUnit Deprecations: 102, Skipped: 6.
```

The 3 failures are the known pre-existing ones: `ExampleTest`, `ItemTableFitScriptTest`, `SharedPagesBasePinTest` (`/owner/private`). `vendor/bin/pint` was run on the controller; the tests keep the 266 style (pint would rename the story-named test methods).

## Not done / notes

- MySQL `EXPLAIN` was not run (no server access by rule); plans were checked on SQLite with the live indexes from the real migrations.
- Not checked in a browser against live data; the live examples are covered by tests with the same data shape.
