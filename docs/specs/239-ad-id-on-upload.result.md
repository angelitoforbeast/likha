# Result 239: the order gets its ad id when the flow uploads it

Status: done. Branch `feat/239-ad-id-on-upload`, cut from `0b9af7b3`. Not pushed, not deployed. No migration (the column `macro_output.botcake_ad_id` exists since 2026-10-17).

## What shipped

One slice (risk tier medium), commits `feat: the order gets its ad id when the flow uploads it (spec 239)` and `fix: read the ad id only for a {{ad_id}} mention or an old upload step (spec 239)`:

- **Shared query (decision 1).** `BotcakeCustomerMatcher` now has `adQuery()` (the page/PSID/source/non-empty rule, unchanged), `AD_PICK` (the UPDATE's choice: `api` first, then newest id), `EMPTY_AD`, `adPageId()`, `adOf()` (one ad for a page + PSID) and `fillAds()` (the never-replace UPDATE, or a count with dry run). `matchPage()`'s ad_filled block calls `adQuery` and `fillAds`; its selection and result are the same as before.
- **`{{ad_id}}` (decisions 1, 2).** `FlowRun::vars()` adds `ad_id` through `adOf(page name, PSID, run page id)` once per run, only when the graph or botfields contain a `{{ad_id}}` mention, or an Upload to macro step has no `ad_id` key. Listed in the editor's system variables with the tip "Built in: Ad ID (the ad that brought the customer)" (`ChatbotFlowController::RUN_VARS`); also known to `ChatbotTags` and the page check as a system variable.
- **Upload to macro (decisions 3, 4).** Editor: field "Ad ID (I)" after Price (H), default `{{ad_id}}` for new steps; an old step shows `{{ad_id}}`. `FlowGraph::clean` keeps the key only when present. `FlowEngine::uploadMacro` interpolates the field (missing key = `{{ad_id}}`), `Upload::adId()` accepts digits 1–64 only; anything else non-empty adds `ad_note` "Ad ID not saved: not an ad id (digits only, up to 64)" to the `upload_macro` event (shown in the chat line). Insert: `LiveOrder::insertRaw` sets `botcake_ad_id`. Update: after the append, `UPDATE … WHERE id = ? AND (botcake_ad_id IS NULL OR = '')`, inside the existing row lock and transaction.
- **Command (decision 6).** `orders:fill-ad-id {--date=} {--page=} {--dry-run}` (`app/Console/Commands/FillOrderAdIds.php`): orders of that ts_date with a PSID and no ad id, per page (all pages of the day, or `--page`), chunks of 1,000 by id through `fillAds`. Prints `<date>[ (dry run: nothing written)]: checked N, filled N, no ad found N`. Default date: today, Manila.
- Nightly schedule, the matcher's PSID matching and Botcake are unchanged (decision 7).

### Paths covered (decision 5)

| Path that creates or fills a macro_output row from a flow | Ad id |
|---|---|
| Upload to macro, Insert (and Update with no open row → Insert) → `LiveOrder::insertRaw` | the step's Ad ID field |
| Upload to macro, Update onto the open row → `Upload::run` | fills only when empty |
| Encode order (legacy) step and the live Sheet step → `Runner` → `LiveOrder::create` | `adOf` with the run's page id (that path has no Ad ID field) |
| Encode order (new, `encode_macro`) | creates no row (fails without one); not changed |
| Runner's linked re-encode of an open row (`LiveOrder::update`) | not changed; the nightly matcher fills it |

### Files changed

`app/Services/BotcakeCustomerMatcher.php`, `app/Services/Chatbot/FlowRun.php`, `app/Services/Chatbot/FlowEngine.php`, `app/Services/Chatbot/FlowGraph.php`, `app/Services/Chatbot/ChatbotEvents.php`, `app/Services/Chatbot/ChatbotTags.php`, `app/Http/Controllers/ChatbotFlowController.php`, `app/Services/OrderEncode/Upload.php`, `app/Services/OrderEncode/LiveOrder.php`, `app/Console/Commands/FillOrderAdIds.php` (new), `resources/views/chatbot/editor.blade.php`, `tests/Feature/OrderEncode/AdIdOnUploadTest.php` (new), `tests/Feature/Chatblast/ChatbotVariableGroupsTest.php` (system list gets `ad_id`), `qa/stories.md`, `docs/order-encode.md`, `TODO.md`.

### Backfill today's orders

```
php artisan orders:fill-ad-id --date=2026-11-06 --dry-run
php artisan orders:fill-ad-id --date=2026-11-06
```

## Case table

Command: `php.bat vendor/bin/phpunit tests/Feature/OrderEncode/AdIdOnUploadTest.php` → `OK (14 tests, 31 assertions)` (4 PHPUnit deprecations, from the existing `@dataProvider` doc comments).

| Case | Test (`AdIdOnUploadTest::`) | Result |
|---|---|---|
| S-239.1.1 | `test_S_239_1_1_a_new_row_gets_the_ad_of_the_customers_chat_verify_row` (Upload Insert; legacy Encode order / live Sheet) | pass |
| S-239.1.2 | `test_S_239_1_2_the_ad_of_another_psid_or_another_page_is_not_written` (another psid; another page) | pass |
| S-239.1.3 | `test_S_239_1_3_a_row_of_another_page_id_is_not_used_and_a_row_without_page_id_matches_by_name` | pass |
| S-239.1.4 | `test_S_239_1_4_the_ad_id_field_decides_what_is_written` (no key; cleared; `{{ad_id}}x`; `abc`) | pass |
| S-239.1.5 | `test_S_239_1_5_update_fills_the_ad_id_only_when_the_open_row_has_none` (has one; has none) | pass |
| S-239.1.6 | `test_S_239_1_6_ad_id_interpolates_in_a_send_and_an_unmentioned_flow_reads_no_botcake_psids` | pass |
| S-239.1.7 | `test_S_239_1_7_fill_ad_id_dry_run_changes_nothing_and_a_real_run_fills_only_empty_ones` | pass |
| S-239.1.8 | `test_S_239_1_8_the_nightly_matcher_keeps_the_uploaded_ad_id` | pass |

Red runs (before the code): S-239.1.1/.3/.4/.5/.8 `Failed asserting that null is identical to '120250000000000001'`; S-239.1.4 note `Failed asserting that '' contains "not an ad id"`; S-239.1.6 `Failed asserting that two strings are identical`; S-239.1.7 `CommandNotFoundException: The command "orders:fill-ad-id" does not exist`; legacy path of S-239.1.1 `Failed asserting that null is identical to '120250000000000001'`; the cleared-step read of S-239.1.6 (review fix) `Failed asserting that 2 is identical to 0`.

## Existing tests and full suite

- `tests/Feature/OrderEncode` + `tests/Feature/Botcake` (Upload to macro, OrderEncode, EncodeMacro FlowRun order vars, BotcakeCustomerMatcher): `OK (655 tests, 2047 assertions)`.
- `tests/Feature/Chatblast/ChatbotVariableGroupsTest.php`: `OK (4 tests, 27 assertions)`.
- Full suite `php.bat vendor/bin/phpunit`: `Tests: 4670, Assertions: 78053, Failures: 2, Skipped: 6`. The two red tests are the known `ExampleTest::test_the_application_returns_a_successful_response` and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`. No new failures.
- Kit hooks `node --test test/hooks/*.test.mjs`: 1026 pass, 5 fail. All 5 are the format-on-edit symlink tests failing with `EPERM … symlink` (Windows without symlink rights); untouched by this work.
- Lint: `php.bat -l` on every changed PHP file: no syntax errors. No `npm run build` needed (the editor JS is inline in the Blade view).

## Review

`skeptic-reviewer`, standard depth, on `d5b264ac..0fdbe44b`: no blocker or major. It checked binding order, mysql/pgsql, matchPage unchanged, the never-replace guard on every path, and the digits guard. Minors:
1. A new step's `"ad_id"` key made the lazy check read even when the field was cleared: **fixed** (the check now looks for a `{{ad_id}}` mention), test added to S-239.1.6.
2. The `$row` handed to Forbeast after the Update fill holds the old empty ad id: accepted in `TODO.md` (the Forbeast payload carries no ad id).
3. No test for an invalid `--date`, and the old-step lazy read is proven only via S-239.1.4: accepted in `TODO.md` (trusted path; same behaviour).

## Story changes

- S-239.1.1 also covers the row made by the legacy Encode order step and the live Sheet step (decision 5 asks for every flow path that creates a row; a table variant of the same case).
- S-239.1.4's Then names the event text "Ad ID not saved: not an ad id".

## Notes

- `{{ad_id}}` uses the run's page id (the chat's Facebook page id); when it is empty, the page's `botcake.page_id` setting, as the matcher does. The command and the matcher use the setting.
- The work was done by the main session directly (bounded change, no plan stop per the start prompt), test-first per case.
- Browser check: not run (no browser tools used in this session); the editor change is one field and a hint line.
- No conflict between the spec and CLAUDE.local.md.
