# Result: spec 133, shadow-encode every page's orders from the Likha import and compare with the final order

Status: done, not pushed. Branch `feat/133-encode-shadow-all-pages` (cut from `8c7f25d6`). The hook is built and **off** (`order_encode_import_shadow` defaults to `off`). Nothing was run against real Google, OpenAI or a real queue worker.

## What shipped

| Part | What |
|---|---|
| Import hook | `ImportLikhaFromGoogleSheet::importRow` gets the sheet row number and sheet id (two optional arguments) and, right after it **creates** a `macro_output` row, calls `App\Services\OrderEncode\ImportShadow::created`. Updates never reach it. The call is wrapped in try/catch inside the service; the import's own writes are untouched |
| `ImportShadow` | switch, cap, skip list, today's counts, the dedupe against the flow hook, the `order_encodes` row (mode `import`) and the dispatch of `EncodeOrder` (a refused dispatch marks the row `failed`) |
| `Runner` | one condition: import rows are read by column order A to H (no header read from Google) |
| Compare with the final order | `order-encode:compare-final` (every 15 min), `FinalComparer`, JSON column `order_encodes.final_compare` |
| Page | `GET /order-encode/final` (`OrderEncodeFinalController`, `order-encode/final.blade.php`), tab link added to the existing `/order-encode`; `POST /order-encode/import-settings` (CEO only) |
| Docs | `docs/order-encode.md` (new section), `qa/stories.md` S-133, `TODO.md` section 133 |

Files: new `app/Services/OrderEncode/{ImportShadow,FinalComparer}.php`, `app/Console/Commands/OrderEncodeCompareFinal.php`, `app/Http/Controllers/OrderEncodeFinalController.php`, `resources/views/order-encode/final.blade.php`, `database/migrations/2026_11_05_100000_add_final_compare_to_order_encodes_table.php`, `tests/Feature/OrderEncode/{ImportShadowTest,CompareFinalTest,FinalPageTest,FinalTestCase}.php`. Changed (small): `app/Jobs/ImportLikhaFromGoogleSheet.php` (+ a property, two optional arguments, a `$made =` and 3 lines), `app/Services/OrderEncode/Runner.php` (1 condition), `app/Models/OrderEncode.php` (cast), `config/order_encode.php` (`final_give_up_hours` 72), `routes/web.php` (+3), `routes/console.php` (+5), `resources/views/order-encode/index.blade.php` (tab link).

## Settings keys (`app_settings`)

`order_encode_import_shadow` (`off` default / `on`), `order_encode_import_daily_cap` (default 2000), `order_encode_import_skip_pages` (comma list, case-insensitive, default empty), plus `order_encode_import_skipped` (JSON `{date, cap, pages}`: today's skipped counts, resets by itself on a new day). The three are saved on `/order-encode/final` by the CEO only (the other roles that can open the page see the values without the form). No history: the project has no history table for app settings (in `TODO.md`).

## Compare storage

One migration, additive and guarded: nullable JSON `order_encodes.final_compare`. Reason: the existing `compare` / `compare_state` / `sheet` columns belong to the sheet compare, which overwrites them whole for a flow shadow row, so a `final` key inside them would be wiped. `null` = not compared yet; `{state: done, fields, likha_proceed, final_proceed, status, edited, edited_flags, final: {...}}` or `{state: sheet_never_finished}`. The migration must be run by hand (`php artisan migrate --force`); until then the command and the page do nothing / 404.

## Import hook point and rules

`importRow` has `$row` (raw A to I as read) and the real sheet row number (`$actualRow`, TO WEBSITE row = All Orders row). After `MacroOutput::create(...)`: `ImportShadow::created($row, $made, $sheetId, $sheetRow)`:
1. switch off → return (zero extra writes; one `app_settings` read per import job);
2. an `order_encodes` row with the same sheet id, tab `All Orders` (case-insensitive) and row number exists (the flow hook got there first) → only set its `macro_output_id` if empty, encode nothing;
3. page in the skip list → count `pages`, return; encodes already queued today (mode `import`) ≥ cap → count `cap`, return;
4. `firstOrCreate` on the key with `input.row` = cells A to H as strings and `input.source = import`, `macro_output_id` set, then `EncodeOrder::dispatch` (queue `encode`).

## Cases

| Case | Test | Result |
|---|---|---|
| S-133.1 switch off, nothing changes | `ImportShadowTest::test_S_133_1…` | pass |
| S-133.2 on: one encode, raw A–H, key, macro id, queue | `test_S_133_2…` | pass |
| S-133.3 update encodes nothing | `test_S_133_3…` | pass |
| S-133.4 flow-hook row not encoded twice | `test_S_133_4…` | pass |
| S-133.5 cap and skip list, counted per day | `test_S_133_5…` | pass |
| S-133.6 dispatch refused / table missing never fails the import | `test_S_133_6…` | pass |
| S-133.7 import row runs by column order, no sheet read, no `macro_output` write | `test_S_133_7…` | pass |
| S-133.10 waits for the Macro import | `CompareFinalTest::test_S_133_10…` | pass |
| S-133.11 fields, PROCEED, checker, edited flags, STATUS | `test_S_133_11…` | pass |
| S-133.12 blank on one side | `test_S_133_12…` | pass |
| S-133.13 72 h, sheet never finished | `test_S_133_13…` | pass |
| S-133.14 which rows, once only | `test_S_133_14…` | pass |
| S-133.15 sheet compare left alone | `test_S_133_15…` | pass |
| S-133.16 failed import encode is `failed`, never compared | `test_S_133_16…` | pass (red first: status was `done`) |
| S-133.20 tiles, PROCEED table, per-page table, sort | `FinalPageTest::test_S_133_20…` | pass |
| S-133.21 filters | `test_S_133_21…` | pass |
| S-133.22 disagreements, steps on hover, escaping | `test_S_133_22…` | pass |
| S-133.23 switch, cap, skip list, today's counts, cost | `test_S_133_23…` | pass |
| S-133.24 CEO-only save, bad values refused | `test_S_133_24…` | pass |
| S-133.25 roles | `test_S_133_25…` | pass |

Final run: `php vendor\phpunit\phpunit\phpunit tests/Feature/OrderEncode tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake tests/Feature/NightRun`: **1488 tests, 1 error** (the earlier full run, before S-133.16, was 1487 tests, 12982 assertions; `tests/Feature/OrderEncode` alone re-run after the fix: 206 pass): `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, "file …storage\app/credentials.json does not exist" (the known local-only failure; the other 1486 pass).

Red runs: the import and compare-final tests were run before any code: S-133.4 "Failed asserting that null is identical to 1" (no `macro_output_id` set), S-133.5 "Failed asserting that 0 is identical to 2" (nothing encoded); S-133.2/.6/.7 and S-133.10–.15 errored on the missing class / migration / command (module-not-found for what was about to be created). The page tests (S-133.20–.25) were written before the controller but I did not run them red separately; they passed on their first run once the page existed (after fixing two mistakes in the tests themselves: a duplicate user per role and a private `seed()` name clash).

## Cost, queue and backlog (estimates, nothing measured here)

- Cost: about $0.003 per order (the brief's measurement; the gpt-5.2 checker calls are most of it), so about $4 to $5 per 1,500 orders; the cap of 2,000 a day bounds it near $6 a day. The page shows the estimate for the orders in the range from their stored `usage` and `config/order_encode.php` prices.
- Queue: same `encode` queue and Supervisor program, 2 workers per server. An encode is one to a few sequential GPT calls plus the local matching; I assume about 10 s per order, so 2 workers do about 12 orders a minute. A burst of 500 new rows then clears in about 40 minutes, 1,500 in about 2 hours; the steady rate (1,500 a day is about 1 a minute) is far below capacity. **No change is needed for the first days.** If the owner wants a big import (hundreds of rows) judged within 15 to 20 minutes, raise `numprocs` in `deploy/supervisor/likha-encode.conf` to 4 on that server. The import itself is not slowed: it does one `app_settings` read per job plus, per created order, one dedupe query, one count and one insert (and no GPT).
- Compare-final reads only `order_encodes` and `macro_output` (no Google), so it adds no quota use.

## Deploy checklist (by hand, after the owner's yes)

1. `php artisan migrate --force` (adds `final_compare`; nothing existing changes).
2. Nothing new for Supervisor/cron: `order-encode:compare-final` is on the existing scheduler.
3. Switch on later from `/order-encode/final` (CEO). Incepxion: same code, switch stays off until the owner says.

## Review

`skeptic-reviewer`, standard depth, on `8c7f25d6..HEAD`: one major, four minors.

- **Major, fixed (test first, S-133.16):** a failed encode of an import row was marked `done` (`Runner::fail` does that for rows already linked to `macro_output`), so compare-final scored it as "Likha would hold" and skewed the readiness numbers. Import rows now end `failed` and are never compared; the page's failed tile counts them.
- **Minors accepted in `TODO.md`:** `Schema::hasTable` and the skipped-counter read/write once per created row (cheap, switch-off path unaffected); the counter is not atomic; the page loads the whole range in PHP; role normalisation copied per controller (the project's convention).

## Story changes

None to the spec's stories (the spec brought none; S-133 was added by me from the "Done when" list).

## Accepted findings (in `TODO.md`) and suggestions for the owner

See `TODO.md` section 133. Not done on purpose (legacy rule): a rollup table for long ranges, a history of settings changes, an atomic cap.
