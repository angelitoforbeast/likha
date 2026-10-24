# Result 144: the encoder no longer holds possible duplicates

## What shipped
- **Setting** `app_settings.order_encode_duplicate_hold`, values `on` / `off`, default **`off`** (unset or anything other than `on` counts as off). It is `Settings::DUPLICATE_HOLD` / `Settings::duplicateHold()`, next to `order_encode_rules`. It is shown on `/order-encode/final` ("Duplicate hold: on|off" plus a select in the CEO form). Only the CEO can save it (other roles get a 404 and don't see the select), and values other than on/off are refused.
- **R5** (`Runner::duplicates`): when the setting is off, a found duplicate never holds. The order keeps its PROCEED and the step log gets `duplicates_seen` (status `OK`, `ids` = the same ids the old reason listed, `e<id>` for an encode not yet in macro_output). When it is on, R5 holds as before ("Possible duplicate: <ids>", `rule_hold HOLD:R5`). Every mode goes through this one place: live, Encode order flow blocks, the legacy block, import/shadow and backtests. `Duplicates` is used nowhere else.
- **Astra**: with the setting on, an R5 hold means the encode had PROCEED and only R5 held it, so it is always R5-only. That hold now queues no Astra:
  - `AddressQuestion::afterEncode` (legacy block) does not queue `RunSpotAstra`. The address question already skipped R5.
  - `FlowEncode::afterEncode` (Encode order step) does not queue `RunEncodeAstra`.
- `FlowEncode::holdReason` still maps R5 to `duplicate` for when the setting is on. It is unchanged.
- Docs: `docs/order-encode.md` (the R5 row, Astra on the spot, the settings table and the Encode order Astra line).

## Files
- `app/Services/OrderEncode/Settings.php`, `Runner.php`, `AddressQuestion.php`, `FlowEncode.php`
- `app/Http/Controllers/OrderEncodeFinalController.php`, `resources/views/order-encode/final.blade.php`
- `tests/Feature/OrderEncode/RulesTest.php`, `AddressQuestionTest.php`, `EncodeMacroTest.php`, `LiveSettingsTest.php`, `OneRowPerDayTest.php`
- `docs/order-encode.md`, `qa/stories.md`, `docs/specs/144-no-duplicate-hold.md`, this file

## Case table
| Case | Test | Result |
|---|---|---|
| S-144.1 | `RulesTest::test_S_144_1_with_the_hold_off_a_duplicate_proceeds_and_the_steps_note_its_ids` | pass |
| S-144.2 | `AddressQuestionTest::test_S_144_2_an_r5_only_hold_queues_no_spot_astra` | pass |
| S-144.3 | `EncodeMacroTest::test_S_144_3_another_customers_order_proceeds_unless_the_hold_is_on_and_an_r5_hold_queues_no_astra` (off and on) | pass |
| S-144.4 | `LiveSettingsTest::test_S_144_4_the_duplicate_hold_is_off_by_default_and_only_the_ceo_switches_it` | pass |
| S-135.15/.16/.18/.36, S-136.8, S-136.30, S-137.17 | existing tests, now with the setting `on` | pass |

Red runs, before the code:
- `test_S_144_1`: "Failed asserting that two arrays are identical" (the order was held instead of PROCEED).
- `test_S_144_3` "the hold is off (default)": the order status was `''` instead of `PROCEED`.
- The other cases: "Undefined constant Settings::DUPLICATE_HOLD" / "undefined method duplicateHold()", which is the setting about to be created.

## Suite
`phpunit tests/Feature/OrderEncode tests/Feature/NightRun tests/Feature/Chatblast`: 1512 tests, 1 skipped, 1 error.

The error is `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`: `storage/app/credentials.json does not exist` (a Google credentials file missing in this worktree). It errors the same way on the base commit, so this work adds no new failures.

## Story changes
Each case below now holds only with `order_encode_duplicate_hold` = `on`. The tests set the setting to `on`, and the story rows carry "(amended by S-144 …)". The reason: the default no longer holds duplicates, and Validate catches them.
- S-135.15, S-135.16, S-135.18: R5 holds and its reason.
- S-135.36: a held live order is written TO FIX (the test uses an R5 hold).
- S-136.8: another PSID with the same phone is held by R5.
- S-136.30: an R5 hold asks nothing (now also: no Astra is queued, S-144.2).
- S-137.17: the R5 table gives `duplicate`.

New: S-144.1 to S-144.4.

## Notes
- No new dependencies and no migration (the setting is an `app_settings` row, read with the existing default fallback).
- No asset build is needed: Blade only, with existing classes.
- Not done (out of scope): Validate, and the deploy.

## Commits
- `docs: spec 144 no duplicate hold`
- `fix: the encode no longer holds a possible duplicate unless order_encode_duplicate_hold is on; no Astra for an R5-only hold (spec 144)`
- `docs: result for spec 144`

144: done. The encode no longer holds possible duplicates (setting `order_encode_duplicate_hold`, default off, CEO-only on /order-encode/final; the ids are noted as `duplicates_seen`), and an R5-only hold queues no Astra.
