# Result: 124 Mira RTS colours from Conditional Formatting

Branch `feat/124-rts-check-conditions` (from 6dedc344). Not pushed. No migration. No new dependency.

## Commits
1. `docs: spec 124 rts-check conditions`
2. `feat: projected RTS colour and rts-check level come from the saved conditional formatting (spec 124)`
3. `docs: spec 124 result`

## Files
- `app/Services/RtsProjection.php`: new `expectedError()` and `gapRatio()`. `rows()` now returns `error_pts` (combined error), `gap_abs` and `gap_ratio`; `flag` and `FLAG_POINTS` are gone. `MIN_MATCHED_FLAG` (30) stays as the gate for the ratio and the feed.
- `app/Services/ColFormatRules.php` (new): small PHP evaluator of the saved rules.
- `app/Http/Controllers/OwnerColumnSettingsController.php`: five catalog ids and the one-time seed.
- `app/Http/Controllers/OwnerPrivateController.php`: the new row fields; `projected_rts_flag` removed.
- `app/Http/Controllers/OwnerRtsCheckController.php`: `error_pts`, `gap_ratio`, `level`; `flag` = level red.
- `resources/views/owner/private.blade.php`: the hard-coded red style on the Mira RTS cell is removed (the td already applies `cellFormatStyle`). Column defs and cells added for the new ids. The tooltip now says "Inaasahang error".
- Tests: new `MiraRtsConditionsTest`; `RtsCheckFeedTest` and `ProjectedRtsCellTest` updated; `SharedPagesBasePinTest` re-pinned for both pages (new cells and columns, catalog entries, seeded rules in the page data).

## Catalog ids (owner_private, hidden by default, not in DEFAULT_VISIBLE)
- `rts_gap_pts`: Set − Mira, signed, from the rounded Mira RTS.
- `rts_gap_abs`.
- `mira_rts_error_pts` = sqrt(e_age² + (100·sqrt(p(1−p)/matched))²), with e_age 3 / 1 / 0.5 for a mean in-transit age of ≤4 / ≤6 / older.
- `rts_gap_ratio` = abs ÷ error.
- `mira_rts_matched`.

The Mira RTS column itself keeps its catalog id **`mira_rts`** (row field `mira_rts_pct`). The rules are keyed on `mira_rts` because CF rules are keyed by catalog id, not by row field. The new row fields are named like their ids, so `compare_col` works.

## Seeded rules (app_settings `owner_private_col_format`)
```json
{"groups":[{"cols":["mira_rts"],"rules":[
 {"op":">","value":2.0,"bg":"#fecaca","color":"#b91c1c","bold":true,"label":"Gap > 2× error","compare_col":"rts_gap_ratio","active_state":"any"},
 {"op":">","value":1.0,"bg":"#fde68a","color":"#92400e","bold":false,"label":"Gap > 1× error","compare_col":"rts_gap_ratio","active_state":"any"}]}]}
```
- They are added on the first `loadColFormat('owner_private')` (page load, column settings, or the feed) when no group already targets `mira_rts`.
- A marker row `owner_private_col_format_mira_rts_seeded` stops them coming back after the owner deletes them.
- A legacy-shape setting is left alone, and saved rules are never overwritten.

## What the CF engine could not do
The engine has no AND. A rule is one condition (own value or one `compare_col`), first hit wins, and formula thresholds only allow digits and `+ - * / %` (no comparison, no max). So:
- **matched ≥ 30** is folded into the data. `rts_gap_ratio` is null below 30 matched orders and a null never matches a rule, so the result is the same as the brief's condition.
- **rts_gap_abs ≥ 5** for red is **not enforced**. Red is `ratio > 2` only. At 30 orders the error is already ≥ 9 pts, so red needs a gap of at least 18. The floor only bites on large samples (about 1,000+ orders, where the error is ~2–3 pts and 2× error is under 5 pts). A first rule on `rts_gap_abs` < 5 is not equivalent, because it would also hide amber. Suggestion for later: an `and` list per rule in the engine, if the floor matters.

## How the feed evaluates them
- `/api/owner/rts-check` loads `loadColFormat('owner_private')['byCol']['mira_rts']` (the same saved rules the page uses) and runs `ColFormatRules::firstHit` on each row. The ids are as above, and `mira_rts` is the unrounded projected RTS.
- Level comes from the bg of the first hit: hue near 0° (<20° or ≥340°) is `red`, any other colour is `yellow`, no hit is `none`. A rule the owner colours red-ish counts as red whatever its label.
- Limits: ops `> >= = <= <` and `is_null` / `is_not_null`. Thresholds are numeric or a `ref` to another id of the same row. **Formula thresholds are skipped** and `active_state` is ignored.
- Rows with fewer than 30 matched orders stay out of the feed.
- Sort: red, yellow, none, then |gap|, then matched.
- New fields: `error_pts`, `gap_ratio`, `level`. `flag` = level red.

## Case table
The spec has no stories, so this follows the done-when list.

| Done when | Test | Result |
|---|---|---|
| New columns in the catalog, hidden; values correct; error grows with fewer orders and earlier age | `MiraRtsConditionsTest::test_catalog_has_the_new_columns_hidden_by_default`, `test_expected_error_grows_with_fewer_orders_and_earlier_age`, `ProjectedRtsCellTest::test_row_carries_projected_rts_and_the_gap_columns_the_conditional_formatting_reads` | pass |
| Seed adds two rules once, never overwrites | `test_seed_adds_the_default_group_once_and_never_overwrites_saved_rules`, `test_seed_skips_when_the_owner_already_has_a_rule_for_the_column` | pass |
| Defaults give amber / red | `test_feed_level_follows_the_default_rules` | pass |
| Changing a saved rule changes page data and feed level | `test_changing_a_saved_rule_changes_the_page_data_and_the_feed_level` | pass |
| Evaluator | `test_evaluator_supports_numeric_thresholds_refs_and_null_ops_and_skips_formulas` | pass |
| No hard-coded colour on the Mira RTS cell | `ProjectedRtsCellTest::test_page_renders_the_mira_rts_column_button_and_profit_column` (no `projected_rts_flag` in the page) | pass |

First red lines: "Call to undefined method RtsProjection::expectedError()", "Class App\Services\ColFormatRules not found", "Undefined array key mira_rts" (seed), "Undefined array key level" (feed).

`phpunit tests/Feature/RtsProjection tests/Feature/OwnerPrivate tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 1172 tests. Before the re-pin only the two `SharedPagesBasePinTest` hashes failed, as intended. After the re-pin the same run is green: 1172 tests, 8288 assertions, 1 skipped (already skipped before), 0 failures.

## Not done / for the owner
- `npm run build` not run (only Blade changed, no JS/CSS assets). No browser check (no browser session).
- Production `owner_private_col_format` gets the seed on its first read after deploy; there is no migration to run.
