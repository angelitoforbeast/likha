# Result 197: Mira RTS every day, checked against reality every day

Status: done. Branch `feat/197-daily-mira-rts`, based on 8705e881. Not pushed, not deployed. No migration, no new table, no new dependency.

## What shipped

1. **Daily estimate** (`App\Services\AnalystRtsDaily::run()`, command `rts:daily-estimate`).
   - Scheduled at 06:00 Manila in `routes/console.php`, with `withoutOverlapping(60)` and `onOneServer()`. It runs after `rts:learn` (04:40), so it uses that morning's p(age).
   - Range: the first day of last month to today. For every page+item from `RtsProjection::rows()` that has a projected value, the estimate is the projected RTS rounded to 0.5.
   - It writes the estimate as the Mira RTS (`analyst` / `rts_pct:<item>`, `page_settings.source = auto`) and the reason (`rts_reason:<item>`, source auto).
   - Page+items without a rate, or without matched orders, are skipped.
   - An item whose key would pass 100 characters is skipped whole (nothing partial is written). It is logged, and the other items still run.
   - The command refuses to run between 03:00 and 03:45 Manila (the night Astra step), whoever starts it.
2. **The analyst can still adjust.**
   - The analyst API now tags its rows `source = mira`.
   - A value she writes the same day at or after 06:00 stays, with her reason, until the next 06:00 run. A value written before 06:00 is replaced by the run.
   - When it keeps her value, the run still writes its day row, so the auto estimate and its inputs are kept.
3. **Daily snapshot** (no new table). Each run writes one more analyst key per page+item, `rts_day:<item>`, as JSON:
   - `d`, `from`, `to`: the day and the order range.
   - `est`, `proj`: the estimate and the unrounded projection.
   - `m`: the matched orders.
   - `fin`, `rts`, `del`, `t`: the % final, % RTS so far, % delivered and % in transit.
   - `age`: the average age of the orders in transit.
   - `acc`: the accuracy, see item 5.

   All three keys keep their history as replaced rows; nothing is updated in place. A rerun on the same day skips any page+item that already has today's `rts_day` row, so it adds no rows.
4. **No look-ahead.**
   - `RtsProjection::rows($from, $to, ?Carbon $asOf)` and `learnTable(Carbon $now, bool $asOf)`.
   - With `$asOf`:
     - only orders up to that date count;
     - each order's state is its last status log at or before that time (no J&T state if it was not yet submitted);
     - its age counts up to that time;
     - p(age) is learned only from orders that were final by then, with samples only at ages before then.
   - Without `$asOf`, behaviour is unchanged.
   - `rts:daily-estimate --as-of=YYYY-MM-DD` prints a replay and writes nothing.
   - The accuracy check compares against the estimate stored on that day, never a recomputed one.
5. **Daily accuracy**, in the same run, after the estimate. See "Accuracy definition".
   - `/owner/private` has a new column **"Mira RTS accuracy"** (`analyst_rts_acc`).
     - It is in the column catalog and visible by default, and can be hidden like the others.
     - The cell shows `7d +x.x · 14d +y.y`, and sorting and conditional formatting use the absolute 7-day error.
     - Its hover lists each of the last 7 days: the 7- and 14-day error, the estimate vs the actual, the % final, and whether the analyst had adjusted that day's value.
   - The Mira RTS hover now says "auto" for an automatic value.
   - The feed `/api/owner/rts-check` has a new top-level `accuracy` list. Each day of the last 7 has `d`, `mae_7`, `n_7`, `mae_14` and `n_14`: the mean absolute error over page+items with at least 30 matched orders in the set.
6. **Not changed:** the Set RTS, ads, the profit formulas, orders, the Projected RTS column and the analyst API contract.

## Reason text format

Taglish, 500 characters at most:

`Auto 06:00: <m> order na may J&T status (<from>–<to>). <fin>% tapos na (RTS <rts>%, delivered <del>%); <t>% nasa biyahe pa, avg <age> araw. Tantya ng final RTS: <est>%.`

Two sentences are added when they apply:
- When the projection of the last 21 days differs from the whole range by more than 3 points: ` Huling 21 araw <x>% vs buong range <y>% (<±z> pts).`
- When the Set RTS is 3 points or more from the estimate: ` Set RTS <s>%: <±g> pts ang agwat.`

Sample (benchmark fixture):
`Auto 06:00: 1888 order na may J&T status (Sep 1–Oct 29). 87.8% tapos na (RTS 26.9%, delivered 60.9%); 12.2% nasa biyahe pa, avg 8.5 araw. Tantya ng final RTS: 30.5%.`

## Accuracy definition

For N = 7 and 14, for each page+item that has an `rts_day` row from N days ago:

- **Estimate**: the Mira RTS in force at the end of that day. That is the last `rts_pct` row written that day (the analyst's if she adjusted, source kept), or else the day row's `est`.
- **Actual**: the RTS of the same order set (ts_date in that day's `from`..`to`) with today's statuses, (Returned + For Return) ÷ (Delivered + Returned + For Return).
- **% final**: finished orders ÷ matched orders.
- **Error** = estimate − actual, in points (positive = the estimate was too high).

Read a 7-day error together with its % final: returns finish later than deliveries, so the actual leans low until the set is final. Today's errors are stored in today's `rts_day.acc`.

## Runtime (decision 5)

Measured on a seeded fixture: 47 page+items, 1,500 orders a day from Sep 1 to Oct 29 (88,500 orders with status logs). Sqlite in memory, indexes on `ts_date` and `waybill_number`.

| Step | Time | Peak memory |
|---|---|---|
| 06:00 run with no history yet | 1.9 s | 74 MB |
| 06:00 run with the 7-day check | 3.3 s | 76 MB |
| 06:00 run with the 7- and 14-day checks (full run) | 4.6–5.0 s | 76 MB |
| `rts:learn`, for comparison (unchanged) | 8.5 s | not measured |
| `overall()` and `recent()` (the feed and page reads) | under 10 ms | not measured |

- The run makes four `rows()` passes: the range, the last 21 days, and the 7- and 14-day sets. Each pass uses the indexed `macro_output.ts_date` range and `from_jnts.waybill_number`.
- It writes 3 `page_settings` rows per page+item per day: about 141 a day, or 51k a year.
- MySQL was not measured (no local copy). Expect the same order of seconds.
- The schedule (06:00) is far from 03:00–03:45, and the command refuses that window.

## Deploy notes

- No migration. It needs the existing `page_settings.source` column (migration `2026_11_12_100000`, already live).
- The scheduler must run (`schedule:run`). The new entry is `rts:daily-estimate` at 06:00 Manila.
- `onOneServer()` needs a cache store with locks (database, redis or file). This is its first use in `routes/console.php`.
- Optional first run by hand after deploy: `php artisan rts:daily-estimate`. It is safe to repeat the same day.
- The accuracy column shows "—" until 7 days of runs exist (14 days for the 14-day value).
- Optional dry check: `php artisan rts:daily-estimate --as-of=2026-10-20`.

## Case table

| Case | Test | Result |
|---|---|---|
| S-197 P1.1 | `test_S_197_P1_1_the_0600_run_writes_a_mira_rts_with_an_auto_reason_for_every_page_item_in_range`, `test_S_197_P1_1_the_reason_names_the_21_day_trend_and_the_set_rts_gap_when_large`, `test_S_197_P1_1_an_item_whose_key_is_too_long_is_skipped_whole_and_the_rest_still_run` | pass |
| S-197 P1.2 | `test_S_197_P1_2_young_in_transit_orders_lift_the_estimate_above_the_rts_so_far` | pass |
| S-197 P1.3 | `test_S_197_P1_3_an_analyst_value_after_0600_survives_until_the_next_run_and_history_keeps_all` | pass |
| S-197 P1.4 | `test_S_197_P1_4_accuracy_compares_the_7_and_14_day_old_estimates_with_the_actual_rts_of_the_same_orders`, `test_S_197_P1_4_owner_private_shows_the_accuracy_column_with_the_last_7_days_in_the_hover`, `test_S_197_P1_4_an_estimate_the_analyst_adjusted_that_day_is_the_one_measured` | pass |
| S-197 P1.5 | `RtsAsOfTest::test_S_197_P1_5_as_of_a_past_date_uses_only_statuses_and_rates_known_then`, `test_S_197_P1_5_rate_table_as_of_learns_only_from_orders_final_by_then` | pass |
| S-197 P1.6 | `test_S_197_P1_6_rerunning_the_same_day_adds_no_history` | pass |
| S-197 P1.7 | full `tests/Feature` run (below) | pass (no new failures) |
| extra | `test_S_197_never_runs_during_the_night_astra_step`, `test_S_197_as_of_replay_prints_and_writes_nothing` | pass |

### Red runs

- `RtsAsOfTest`: `Call to undefined method App\Services\RtsProjection::learnTable()`, and the as-of rows were not clipped (arrays not identical).
- `AnalystRtsDailyTest` (all 7 first tests): `The command "rts:daily-estimate" does not exist`.
- The column test: `Undefined array key "analyst_rts_acc_7"`.

## Suites

- **Baseline**, `tests/Feature` on this branch before code (= 8705e881): 3,623 tests, 2 failures, 4 skipped, 92 deprecations. The two failures:
  - `ExampleTest::test_the_application_returns_a_successful_response` (302).
  - `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order` (catalog count 56 vs a stale 45).
- **Final**, `tests/Feature` after the feature commits: 3,633 tests, the same 2 failures and nothing else.
  - The Item fit test now reports 57 vs 45: the new catalog id adds one to a count that was already stale. The item table script does not list the Projected RTS or Mira RTS columns either.
- After the review fix commit: `tests/Feature/RtsProjection tests/Feature/OwnerPrivate tests/Feature/PageSettings`, 396 tests, no failures.
- **Re-pinned for the intended change only** in `SharedPagesBasePinTest`: the `/owner/private` render (new column definition, cell, hover functions) and `/owner/column-settings/owner-private` (new catalog entry).

## Review

The skeptic reviewer (standard depth) found no blockers and no majors. All three focus areas hold: no look-ahead, bounded query cost on the order tables, and history never overwritten.

Fixed after review:
- One failing item no longer stops the run. It is logged and the run goes on.
- The keep rule now checks the source (only a value typed by the analyst is kept), as the plan says.
- Added tests for the cases the reviewer found missing:
  - accuracy when the analyst adjusted that day;
  - the trend and gap sentences of the reason;
  - the too-long-key skip.

Accepted minors, with their reasons, are in `TODO.md` under "197". The main ones:
- "Same orders" is the same ts_date range read today.
- The actual leans low while a set is not final.
- A page name with double spaces does not match its stored rows.
- Some reads grow with history (about 17k rows a year).
- The `--as-of` replay runs at the end of the day, not at 06:00.

## Deviations and notes

- **Order of work:** the brief served as the go. The plan was committed before any code, and there was no separate wait for a reviewer's go.
- **The brief's learning example:** the brief's example (20% RTS / 20% delivered / 60% in transit ending at 60% RTS) is what the existing p(age) method already does. P1.2 pins it with a 0.6 item rate.
- **Items that stop selling:** a page+item with an estimate N days ago but no orders in today's range gets no accuracy that day. It has no day row today to hold the result.
- **Browser check:** skipped. The column was checked through the rendered HTML and the item-summary JSON in tests.

## Commits

`git log 8705e881..HEAD`:
1. `docs: brief and result stub for the daily RTS estimate (spec 197)`
2. `docs: plan for the daily RTS estimate and its accuracy check (spec 197)`
3. `feat: RTS projection as of a past date from status logs known then (spec 197)`
4. `feat: daily 06:00 RTS estimate per page and item with its 7- and 14-day accuracy check (spec 197)`
5. `feat: accuracy column for the daily RTS estimate on the owner page, with the last 7 days on hover (spec 197)`
6. `fix: one failing item no longer stops the daily RTS run, and only a typed value is kept until the next run (spec 197)`
7. `docs: result and stories of the daily RTS estimate (spec 197)`

197: done — Mira RTS is written every day at 06:00 from the RTS projection (no look-ahead), with an auto reason, kept history, and a daily 7/14-day accuracy column and feed line; run about 5 s on 2 months / 47 items.
