# Result 107: deleting a promo (or an RTS) on /owner/private always works and leaves a clean row

Branch `fix/107-promo-delete`, cut from `main` at 50d3e1f1. Not pushed.

## What changed

- **Delete (`OwnerPrivateController::deleteItemSetting`).** Deleting promo writes `'NONE'` (never NULL), so a row that still has an RTS no longer fails on the NOT NULL column. A row is removed only when nothing is left: RTS NULL, promo NULL / `''` / `'NONE'` (case-insensitive) and an empty comment; otherwise it stays with its remaining values. The same rule applies after deleting RTS. Log rows: RTS delete `old_rts_pct` → NULL as before; promo delete `old_promo` → `new_promo = 'NONE'`. Deleting a promo that is already none writes no log row (same as S-104.2 for an empty RTS).
- **Readable errors.** The RTS/promo delete runs in a try/catch: on any failure it logs the exception server-side and returns 500 `{ok:false, message:"Hindi nabura ang PROMO. Subukan ulit; …"}` with no SQL.
- **Save.** An RTS-only save with no earlier promo to carry writes `'NONE'` instead of `''`.
- **Reads.** Every place that reads `page_item_settings.promo` maps NULL to `'NONE'` (was `''`, which made a NULL row invisible and let an older promo show through).
- **UI (`/owner/private/breakdown`, the only caller of the delete route).** A green notice "Nabura ang Promo sa 2026-10-05." after a successful delete, a red notice on failure (the server's message for 422 and `{ok:false}`, a generic "Hindi nabura ang … Subukan ulit." for anything else, e.g. the framework's "Server Error"), and a network-error notice. The confirm text for promo now says what happens: NONE when the row still has RTS, back to the earlier value when it doesn't.

### Behaviour note for the owner

Because "no promo" is now `'NONE'` and readers treat a `'NONE'` row as an explicit "walang promo" anchor (as they always did for typed NONE), **deleting a promo on a row that still has an RTS now ends the promo from that date** instead of letting an older promo continue. Deleting a promo on a row with no RTS and no comment still removes the row, so the older promo comes back as before. This follows the decision "one meaning for no promo"; it was not reopened.

## Every place that writes or reads `promo` (`app/Http/Controllers/OwnerPrivateController.php`, line numbers after this change)

| Line | Write / read | What |
|---|---|---|
| 2055 | read | itemSummary per-pair list: `promo ?? 'NONE'` (changed) |
| 2073 | read | itemSummary exact map: `promo ?? 'NONE'` (changed) |
| 2101, 2108 | read | itemSummary lenient pick: a row with promo ≠ `''` is the promo row (unchanged) |
| 2714 | read | itemSummary payload `promo` (unchanged; now `'NONE'` for a legacy NULL) |
| 2805 | write (validation) | save: promo `required|min:1` for scope all/promo (unchanged; an empty typed promo is rejected) |
| 2851 | read | save: old promo for the log, `?? 'NONE'` (changed) |
| 2881–2882 | read | save: carry-forward of the last promo, NULL counts as `'NONE'` (changed) |
| 2945–2947 | write | save: `$promoToSave`, typed promo or carried promo, `'NONE'` when nothing to carry (changed from `''`) |
| 2997, 3009 | write | save: insert/update and the apply-through cascade with `$promoToSave` (unchanged) |
| 3090–3092, 3112 | write | save: log `old_promo` / `new_promo` and the JSON response (unchanged) |
| 3188–3208 | write | delete: promo → `'NONE'`, row removal rule, log `old_promo → 'NONE'` (changed) |
| 3279–3280 | read | edit-logs "changes only" filter on old/new promo (unchanged) |
| 3497 | read | breakdownPage index: `promo ?? 'NONE'` (changed) |
| 3534–3539, 3788 | read | breakdownPage resolver and cell payload (unchanged) |
| 4049 | read | breakdown matrix settings list: NULL → `'NONE'` (changed) |
| 4089, 4364 | read | matrix promo resolver and payload (unchanged) |

Views that display promo (unchanged): `resources/views/owner/private.blade.php:1058` and the item tables show `'NONE'` / `-` as "—"; `resources/views/owner/edit_logs.blade.php:229` treats NULL / `''` / NONE / `-` as empty and shows a delete row as "old → deleted"; `resources/views/owner/private-breakdown.blade.php:176` shows the raw value (so `NONE` reads as "NONE" there). `database/migrations/2026_05_20_140000_add_promo_to_page_item_settings.php` defines the column (NOT NULL, default `'NONE'`). No other file in `app/` reads or writes `page_item_settings.promo`.

## Case table

Run: `vendor/bin/phpunit --filter 'S_107|S_104' tests/Feature/OwnerPrivate` → `OK (19 tests, 110 assertions)`.

| Case | Test | Result |
|---|---|---|
| S-107.1 | `ItemSettingsLogTest::test_S_107_1_2_delete_promo_writes_none_or_removes_the_empty_row_and_logs_it` (S-107.1 row with RTS) | Pass |
| S-107.2 | same test, sets "no RTS, no comment: row removed" and "no RTS, with comment: kept, NONE" | Pass |
| S-107.3 | `ItemSettingsLogTest::test_S_107_3_delete_rts_on_a_row_with_promo_keeps_the_row_with_rts_null` | Pass |
| S-107.4 | `ItemSettingsLogTest::test_S_107_4_deleting_both_removes_the_row` (rts then promo, promo then rts, rts on promo NONE) | Pass |
| S-107.5 | `ItemSettingsLogTest::test_S_107_5_saving_rts_with_no_promo_anywhere_writes_none` | Pass |
| S-107.6 | `ItemSummaryProfitMTest::test_S_107_6_a_legacy_null_promo_reads_as_none_and_ends_the_earlier_promo` | Pass |
| S-107.7 | `ItemSettingsLogTest::test_S_107_7_a_failed_delete_gives_a_readable_message_without_sql` (server side); the notice itself is not browser-checked | Pass |
| S-104.1 | `test_S_104_1…` promo set now uses the row with RTS and asserts `new_promo = 'NONE'` | Pass |
| S-104.2–7 | unchanged | Pass |

Red runs before the fix (first failure line each):

- S-107.1 / S-104.1 promo: `SQLSTATE[23000]: … NOT NULL constraint failed: page_item_settings.promo`
- S-107.2 with comment: `Failed asserting that null is identical to Array … 'promo' => 'NONE', 'comment' => 'x'` (row was removed)
- S-107.4 promo then rts: `NOT NULL constraint failed: page_item_settings.promo`; rts on promo NONE: `Failed asserting that Array … is null` (row kept)
- S-107.5: `Failed asserting that two strings are identical. -'NONE' +''`
- S-107.6: `Failed asserting that two strings are identical. -'NONE' +'B1T1'`
- S-107.7: `Unable to find JSON: [{"ok": false}] within response JSON: [{"message": "Server Error"}]`

## Suites

- `vendor/bin/phpunit tests/Feature/OwnerPrivate tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` → `Tests: 1048, Assertions: 7173, PHPUnit Deprecations: 50, Skipped: 1` (no failures).
- Full suite → `Tests: 2223, Assertions: 22278, Errors: 1, Failures: 2, Skipped: 4`. None is new:
  - `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (error) and `ExampleTest::test_the_application_returns_a_successful_response` fail the same way on `main` 50d3e1f1.
  - `NightRun\AstraAddressRulesTest::test_S_26_18_…` failed once in the full run but passes alone and in `tests/Feature/NightRun` on this branch (twice) and on `main`; it touches nothing in this change (order/timing flake).
- `php -l app/Http/Controllers/OwnerPrivateController.php`: no syntax errors. No asset build needed (the breakdown page uses the Tailwind and Alpine CDNs).

## Browser check

Not run: no browser tools in this session. The notice markup and JS are small (`resources/views/owner/private-breakdown.blade.php`); worth a look at /owner/private/breakdown before release: delete a promo on a row with RTS → green notice, cell shows NONE.

## Story changes

- Added section S-107 to `qa/stories.md` (the spec brought cases in "Done when" but no story section); cases S-107.1–7 map one-to-one to its list, plus S-107.7 for the UI decision.
- S-104.1 (test only): the promo set now uses a row with RTS and expects `new_promo = 'NONE'`, as the spec asked. The S-104.1 story text ("old value → NULL") is unchanged in `qa/stories.md`; for promo it is now old → `'NONE'`, shown as "deleted".

## Review

skeptic-reviewer, standard depth (medium tier), on a359c1e3: no blocker, no major; 4 minors. It ran the two changed test files (green). The edit-logs check it did not do is covered by the S-107.1 test, which asserts "B1T1 → deleted" on the page.

## Accepted findings

All in `TODO.md` under spec 107:

- The breakdown matrix list returns `'NONE'` instead of null for a legacy NULL promo. This is the "NULL reads as NONE" decision.
- Deleting a promo that is already NONE or `''` writes no log row, the same as S-104.2.
- An RTS-only save with nothing to carry writes `'NONE'`, which ends an older promo added later with an earlier date. This is the "one meaning" decision.
- The reviewer could not judge the red runs; they are listed above under the case table.

## Suggestions for the owner (not done; out of scope)

- The breakdown promo cell shows a literal "NONE" while the main page shows "—" for the same value.
- On the breakdown, the "Delete effective date" button stays visible on a promo anchor that is already NONE; deleting it again is a no-op (green notice, nothing changes).
- `''` promo rows written before this change still read as "unset" (inherit the older promo), unlike `'NONE'`. Converting them would be a data migration; not done (no schema or data change asked).

## Conflicts

None between the spec and CLAUDE.local.md.

## Commits

- `abec889e` docs: brief for promo delete (spec 107)
- `a359c1e3` fix: deleting a promo writes NONE, keeps rows that still hold values, readable delete errors (spec 107)
- (this result and the stories, in the docs commit that follows)

## Merge danger

Low. One controller and one view; no migration, no new route. The only visible behaviour change beyond the fix is the note above: deleting a promo on a row that still has an RTS ends the promo from that date.
