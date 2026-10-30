# Result: spec 203

**Status:** done
**Date:** 2026-10-30
**Branch / PR:** `feat/203-mira-rts-latest-price`, cut from 8506b25c. Not pushed, no PR (pushing is not allowed for this spec).
**Preview or run link:** none (local only). Evidence is the test runs below.

## Summary

The daily Mira RTS (`rts:daily-estimate`, 06:00) now uses only the orders at each page+item's latest price. The latest price is the most common COD among the item's orders on its newest order day, so one odd order (a 2-piece order, a typo, a parallel promo) cannot set it. Orders at a whole multiple of that price (2×, 3×: multi-piece orders) count under it. This applies to the projected RTS, the in-transit share, the estimate, the reason and hover, and the 7/14-day accuracy check.

- **Too few orders.** When the latest price has fewer than 30 matched orders, there is no estimate. The run removes the earlier value from view, and the empty "—" cell's hover says "Latest price ₱X: only N orders, not enough yet". It never falls back to all prices.
- **Price in the hover.** The hover names the price: "Mira RTS 25.0% (₱199 orders only): Auto 06:00: 40 order na may J&T status sa ₱199 …".
- **History.** Old day rows are left exactly as they were. New day rows record their price.
- **Replay.** `--as-of` uses the price that was known on that day.
- **Schema.** No migration, no new table, no index.

The owner page's "Projected RTS" column and the RTS-check feed still count all prices (see Rulings).

## Case table

| Case | Test | Result |
|---|---|---|
| S-203.1.1 newest price only | `LatestPriceTest::test_S_203_1_1_the_estimate_counts_only_the_orders_at_the_newest_price` | pass |
| S-203.1.2 most common COD of the newest order day; ties by the last 3 order days, then the newer order; COD read like the owner page | `test_S_203_1_2_the_latest_price_is_the_most_common_cod_of_the_newest_order_day_with_tie_breaks` | pass |
| S-203.1.5 one ₱398 order on the newest day among ₱199s → ₱199 | `test_S_203_1_5_one_398_order_on_the_newest_day_among_199s_leaves_the_price_at_199` | pass |
| S-203.1.6 a day of ₱199×3 and ₱249×1 → ₱199 | `test_S_203_1_6_a_day_of_three_199s_and_one_249_gives_199` | pass |
| S-203.1.7 a 2-piece ₱398 (and 3-piece ₱597) order counts under ₱199 | `test_S_203_1_7_a_multi_piece_order_at_a_multiple_of_the_price_counts_under_that_price` | pass |
| S-203.1.3 another page stays separate | `test_S_203_1_3_another_page_with_the_same_item_keeps_its_own_latest_price` | pass |
| S-203.1.4 the 21-day trend compares the same price | `test_S_203_1_4_the_21_day_trend_in_the_reason_compares_the_same_price` | pass |
| S-203.2.1 too few gives "not enough yet", no fallback | `test_S_203_2_1_too_few_orders_at_the_latest_price_gives_not_enough_yet_and_no_fallback` | pass |
| S-203.2.2 analyst value after 06:00 stays on a not-enough day | `test_S_203_2_2_an_analyst_value_typed_after_0600_stays_on_a_not_enough_day` | pass |
| S-203.3.1 tooltip names the price | `test_S_203_3_1_the_tooltip_names_the_price` | pass |
| S-203.4.1 per-item p(age) still applies | `test_S_203_4_1_the_item_learned_p_age_still_applies_at_the_latest_price` | pass |
| S-203.5.1 old day rows stay; new ones record and are measured on their price | `test_S_203_5_1_old_day_rows_stay_and_new_ones_record_their_price_and_are_measured_on_it` | pass |
| S-203.5.2 a not-enough day still records accuracy | `test_S_203_5_2_a_not_enough_day_still_records_the_accuracy_of_older_estimates` | pass |
| S-203.6.1 `--as-of` uses the price known that day | `test_S_203_6_1_as_of_replay_uses_the_latest_price_known_that_day` | pass |
| S-203.7 spec-197 tests kept, suite adds no failure | `tests/Feature` | pass (see Tests) |

Red runs before the code:
- First red run: 8 of 8 failed for the missing behaviour. Five failed with `Undefined array key "price"`. S-203.3.1 failed because the reason still said "80 order …"; S-203.2.1 and S-203.4.1 failed on the values.
- After the review fixes, the tests run against the previous commit failed as expected:
  - S-203.5.2 with `Undefined array key "acc"`;
  - S-203.1.2 with `'499+50sf'` read as 49950;
  - S-203.2.1 with 5 rows instead of 4 (the item with no matched orders still got a row).
- S-203.1.4 and S-203.2.2 were added at the review. Both already passed on the previous commit; they are coverage tests.
- Most-common-COD rule (commit f1eea5a1), tests first: S-203.1.5 failed with "398 is identical to 199", and S-203.1.6 and S-203.1.7 failed on the price, count and returned arrays. The reworked S-203.1.2 already passed on the newest-order rule (its fixture gives the same answer under both rules).

## Story changes

- Stories S-203 were added to `qa/stories.md`.
  - The spec's cases are split into numbered cases.
  - Three cases were added after the review: S-203.1.4, S-203.2.2 and S-203.5.2.
  - Case S-203.1.2 also covers COD parsing.
  - After review, story .1's "the selling price of its newest order" became "the most common COD of the newest order day". Ties go to the higher count over the last 3 order days, then to the newer order, and orders at a whole multiple of the price count under it. S-203.1.2 is reworded for this, and S-203.1.5, S-203.1.6 and S-203.1.7 are added.
- Changed assertions in the spec-197 tests (`tests/Feature/RtsProjection/AnalystRtsDailyTest.php`). The intent of each test is unchanged; only the order counts were raised to the 30-order minimum the estimate now needs:
  - `test_S_197_P1_1_the_0600_run_writes…`: Item B goes from 3 R + 6 D to 10 R + 20 D. It is the same 33.33% → 33.5, with 30 orders.
  - `test_S_197_P1_1_an_item_whose_key_is_too_long…`: the long item and Item B go from 10 to 30 Returned each.
- `tests/Feature/RtsProjection/RtsProjectionTestCase.php`: `order()` and `orders()` take an optional `$cod` (default '599', as before).
- `tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php`: the `/owner/private` render pin is re-pinned (`ee118e76…` → `e0c4d13c…`), and the pin's comment says why.
  - Like every earlier re-pin, the only diff is in the Mira RTS cell: the price in its hover and the "not enough yet" hover of the "—" cell.
  - Checked: with the base Blade file and the new code, the old hash still passes.
- Conflict note: the kit wants the reviewer's "go" on the plan before code. The start prompt said to build without waiting, so the plan was committed and the build started.

## Done-when checklist

- [x] P1 cases pass: `php vendor/bin/phpunit tests/Feature/RtsProjection` → `OK (57 tests, 357 assertions)`.
- [x] The full `tests/Feature` run adds no new failures vs 8506b25c: `Tests: 3688, Assertions: 70920, Failures: 2, Skipped: 4`, only the two known base failures (`ExampleTest::test_the_application_returns_a_successful_response`, `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`).
- [x] Price-source ruling: see Rulings.
- [x] Before/after example for a seeded item: see Rulings, "Before / after".
- [x] Deploy notes: see Deploy notes.
- [x] Final line: at the end.

## How to run

- Tests: `php vendor/bin/phpunit tests/Feature/RtsProjection/LatestPriceTest.php` (PHP is Herd's `php84\php.exe`).
- Replay: `php artisan rts:daily-estimate --as-of=YYYY-MM-DD`. It prints Page, Item, **Price**, Matched, In transit %, Projected and Estimate ("not enough yet" when there are too few orders), and writes nothing.
- Run now: `php artisan rts:daily-estimate`. It prints "Written N, kept (analyst) N, not enough at the latest price N, skipped N, failed N".

## Rulings

1. **The price comes from the order's COD.** The latest price of a page+item is the most common COD among its orders on its newest order day (`ts_date`), counted among the orders the projection already reads (waybill set, `ts_date` in the range). Orders without a COD are ignored.
   - **Ties:** the higher count over the last 3 order days wins, then the price with the newer order (`ts_date`, then `macro_output.id`).
   - **Multi-piece orders:** orders whose COD is a whole multiple of that price (₱398 and ₱597 for ₱199) count under that price, for the estimate and for its accuracy check.
   - **Reading the COD:** it is rounded to whole pesos and read the way the owner page reads it on MySQL: ₱, commas and spaces removed, then the leading number.
   - **Why not the single newest order:** one odd order (a 2-piece ₱398, a typo, a parallel promo) would set the price, flip the cell to "not enough yet", and could switch between days.

   The other fields were weaker:
   - **COD per quantity:** `macro_output` has no quantity column, so this cannot be computed.
   - **The owner page's PRICE column:** this is the mode COD over the page's date range (`mode_cod`, the most common price), not the newest one. During a promo switch it still shows the old price.
   - **The item setting** (`page_item_settings.mode_cod_int` / promo): staff write it when they save a Set RTS. It can be days old and does not exist for every item.

   The COD is what the customer pays, and it sits in the same row the projection counts. Matching is exact on whole pesos (or a whole multiple): ₱199 and ₱199.00 match, ₱199 and ₱200 do not.
2. **"Enough" is `matched ≥ RtsProjection::MIN_MATCHED_FLAG` (30) at the latest price.** The code already uses this number to decide when an RTS number is worth acting on (the conditional-formatting ratio and the RTS-check feed). Before this change, the daily run's only rule was "at least 1 matched order".
   - So an item with fewer than 30 matched orders in total also gets "not enough yet" now, not only an item whose price changed.
   - The not-enough run takes the earlier value and reason out of view (`active = false`, kept as history), unless the analyst typed a value today from 06:00.
   - An item with 0 matched orders and no value showing gets no row; there is nothing to say about it.
3. **p(age) stays per page+item** (`learnTable` unchanged). It measures how J&T orders of that item resolve as they age: delivery behaviour, not price. Splitting it per price would drop most items under `MIN_ITEM_SAMPLES` (30 per age) and onto the pooled rate.
4. **Scope is the Mira RTS column** (`analyst_rts`) and everything behind it: day rows, reason, hover and accuracy. The owner page's separate "Projected RTS" column (`mira_rts`, over the page's own date range) and the RTS-check feed rows still count all prices; the spec names the Mira RTS only. The feed's `analyst_rts` and `analyst_reason` fields do show the new values.
5. **Old and new day rows.**
   - A day row written before this change has no `price`. It is measured on all prices, as when it was written.
   - A new day row is measured on its own price only. A not-enough day row is `{d, from, to, price, est: null, m, acc}`.
   - The estimate measured for a day is the value still standing at the end of that day. A value replaced or removed the same day no longer counts.
6. **The 21-day trend** in the reason uses the same price as the full range.

**Before / after** (seeded in S-203.1.1: Page A · Item A, 20 R + 20 D at ₱249 on Sep 10, then 10 R + 30 D at ₱199 on Oct 20; run Oct 23 06:00):

- Before (8506b25c): Mira RTS **37.5%**. Reason: "Auto 06:00: 80 order na may J&T status (Sep 1–Oct 23). 100% tapos na (RTS 37.5%, delivered 62.5%); 0% nasa biyahe pa. Tantya ng final RTS: 37.5%. Huling 21 araw 25% vs buong range 37.5% (-12.5 pts)."
- After: Mira RTS **25%**. Reason: "Auto 06:00: 40 order na may J&T status sa ₱199 (Sep 1–Oct 23). 100% tapos na (RTS 25%, delivered 75%); 0% nasa biyahe pa. Tantya ng final RTS: 25%." The hover reads "Mira RTS 25.0% (₱199 orders only): Auto 06:00: …". Day row: `{"d":"2026-10-23","from":"2026-09-01","to":"2026-10-23","price":199,"est":25,"proj":25,"m":40,…}`.
- With only 10 orders at ₱199: no value, and the "—" hover says "Latest price ₱199: only 10 orders, not enough yet".

## Deferred minors

- The hover text is checked in the Blade source (`' orders only)'`, `row.analyst_rts_note`) and through the JSON fields. The Alpine expression was not rendered in a browser; this session has no browser check of the page.

## Merge danger

- The change touches `RtsProjection::rows()`, which the owner page, the RTS-check feed and the conditional-formatting tests share. The all-prices path (`$price = null`) gives the same numbers: per-price buckets are summed, and the whole `tests/Feature/RtsProjection` and OwnerPrivate suites pass.
- `/owner/private` is re-pinned in `SharedPagesBasePinTest`. Any other branch that changes that page will conflict on the hash. Re-pin after merge.
- `OwnerPrivateController.php` and `owner/private.blade.php` are large shared files. This branch adds 3 lines and changes 3 lines in them.

## Deploy notes

- **No migration**, no new table, no index, no config. The query reads two more columns (`mo.id`, `mo.COD`) in the same pass over the indexed `ts_date` range.
- **The next 06:00 run picks it up.** Expect the first run to change many Mira RTS values. Items with a recent price change move to that price. Items with fewer than 30 matched orders at their latest price show "—" with the "not enough yet" hover, and their earlier value becomes history.
- **A manual run is not required.** If the change should show before the next morning, run `php artisan rts:daily-estimate` once after deploy (not between 03:00 and 03:45). Today's items that already have a day row are skipped until tomorrow, so a same-day manual run changes only items not yet run today. To see the numbers without writing, use `php artisan rts:daily-estimate --as-of=<today>`.
- The owner page needs the new Blade file. No `npm run build` is needed (inline Alpine).

## Tests

- Baseline on 8506b25c (known): 2 failures, `ExampleTest` and `ItemTableFitScriptTest`.
  - My first baseline run started while I was editing files, so it showed 2 more failures (`AstraReplayCommandTest`, which watches files, and `SharedPagesBasePinTest`).
  - Rerun on their own: `AstraReplayCommandTest` → OK (19 tests). `SharedPagesBasePinTest` with the base Blade file → OK. The pin moved only because of the Blade change, and it is re-pinned.
- `tests/Feature/RtsProjection`: `OK (57 tests, 357 assertions)`.
- Full `tests/Feature` on the final tree (f1eea5a1): `Tests: 3688, Assertions: 70920, Failures: 2, Skipped: 4`, only the two known base failures (`ExampleTest::test_the_application_returns_a_successful_response`, `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`).

## Open questions

The earlier question "is one newest order enough to set the latest price?" was decided at review: no. The fix is in commit f1eea5a1 (Ruling 1). Two choices stay as built. They are listed here for the owner:

1. **Small items lose their Mira RTS too.** The "not enough yet" rule (fewer than 30 matched orders at the latest price) applies to every item, not only to items whose price changed. Small items that used to get an estimate from 1 to 29 orders now show "—" with the note. As built: it applies to all items.
2. **The owner page's separate "Projected RTS" column and the RTS-check feed rows stay on all prices** (Ruling 4). As built: only the Mira RTS moves to the latest price.

## Process suggestions

- When a run of the full suite is used as a baseline, start it in a separate checkout or before any edits. `AstraReplayCommandTest` and the page pin pick up files changed during the run.

## Proposed tasks

- None.

## Progress

- Run 1: spec, result skeleton, plan; tests first (8 red); per-price buckets in `RtsProjection::rows()`, latest-price daily run, not-enough state, owner page fields; spec-197 tests adjusted; review (2 majors, 4 minors). Fixed: accuracy on not-enough days, COD read like MySQL, no rows for items with nothing to say, the value standing at the end of the day. Major 2 (a single newest order sets the price) is open question 1. Full suite: `Tests: 3685, Assertions: 70915, Failures: 2, Skipped: 4` — only the two known base failures, `ExampleTest::test_the_application_returns_a_successful_response` and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`.

- Run 2: the latest price is now the most common COD of the newest order day (ties: last 3 order days, then the newer order), and multi-piece orders at a whole multiple count under it. Tests first: 3 new cases red, then green. `tests/Feature/RtsProjection`: `OK (57 tests, 357 assertions)`. Full suite: `Tests: 3688, Assertions: 70920, Failures: 2, Skipped: 4`, only the two known base failures (`ExampleTest::test_the_application_returns_a_successful_response`, `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`).

203: done — the daily Mira RTS counts only the latest price's orders (most common COD of the newest order day, multiples included), says "not enough yet" below 30, and the suite adds no failures
