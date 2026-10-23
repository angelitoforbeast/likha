# Result 121: orders without a Facebook name

## What shipped

| File | Change |
|---|---|
| `app/Services/OrderPhone.php` (new) | The one phone helper. `numbers()` finds the distinct PH mobiles in a text (09…, 9…, +639…, 639…, spaces/dashes/dots between digits), `extract()` returns the number only when exactly one is found, `forOrder(D, E, G)` = D normalized (`BotcakeCustomerMatcher::normPhone`), else the single number in E, else in G. Two different numbers in E stop the search (G is not tried). Prices and short numbers never match (10 digits starting with 9 required, not part of a longer digit run). |
| `app/Services/OrderIdentity.php` (new) | `find()` = the existing-order key shared by both imports; `fbNameFromInput()`; the flag text constant. |
| `app/Jobs/ImportLikhaFromGoogleSheet.php` | Uses `OrderIdentity::find`; nameless rows get the helper phone and the flag. |
| `app/Jobs/ImportMacroFromGoogleSheet.php` | Uses `OrderIdentity::find` and `fbNameFromInput`; counts nameless/no-phone/unmatched rows and writes the count into the run item message. |
| `app/Services/BotcakeCustomerMatcher.php` | PSID fallback by phone for nameless orders (see below). `writePsid()` extracted from the loop unchanged. |
| `app/Services/Chatbot/FlowGraph.php`, `FlowEngine.php` | New condition rule `has_phone`, label "has a phone number". The editor builds its rule list from `FlowGraph::RULES`, so no Blade/JS change. No live flow edited. |
| Tests | `tests/Feature/Orders/OrderPhoneTest.php`, `OrderImportKeyTest.php`, `tests/Feature/Botcake/NamelessOrderPsidTest.php`, 4 rows added to the rules table in `tests/Feature/Chatblast/ChatbotEngineTest.php`. |

No migration.

## Flag column

`macro_output."AI ANALYZE"` = `No name / no phone`, written on insert only. It is an existing free-text column, shown and exported with the order, and not used as a filter or gate (unlike `APP SCRIPT CHECKER`, whose blank/✅ values drive the checker and its filters, so a custom value there would make the auto-checker skip the order). The address encoder later overwrites AI ANALYZE with its own one-line summary; by then the address is encoded, so the flag has done its job.

## Exact keys per case

| Case | Likha import | Macro import |
|---|---|---|
| fb_name present | TIMESTAMP + PAGE + fb_name (unchanged) | same (unchanged) |
| fb_name blank, phone found | TIMESTAMP + PAGE + phone, phone = `forOrder(D, E, G)`; stored phone compared normalized (stored value may have spaces or +63) | same, phone = `forOrder(PHONE NUMBER, all_user_input, none)` (the Macro columns have no G) |
| fb_name blank, no phone | no lookup: always a new row, `AI ANALYZE` = `No name / no phone` | exact TIMESTAMP + PAGE + identical `all_user_input`; no match = new row, no existing row touched, count added to the run item message |

The stored `PHONE NUMBER` of a nameless Likha row is the helper phone. Named rows keep the old `/09\d{9}/` on D, so named orders are unchanged.

PSID fallback (nameless order, no PSID, no name match, no Botcake-customer phone match, phone present): page → Botcake `page_id` via `BotcakeChat::pageMap()`; (1) `chatbot_customer_values` key `gpt phone number` of that page, normalized, exactly one PSID wins; two or more stops (no fall to chats); (2) `botcake_chats` of the page, `active()` only, received within ordered date -3 / +2 days, message contains the phone (pre-filter `LIKE` on the last 4 digits, exact check in PHP), exactly one PSID wins. Written through the same guarded UPDATE as before (never replaces an existing PSID, `botcake_ad_id` once via COALESCE); counted as `by_phone`, otherwise `no_match`.

## Things the code showed

- The Macro import read the FB NAME with `FB\s*NAME:\s*(.*?)\r?\n`; with a blank name `\s*` swallowed the newline and the **next line** became the "name", so a nameless order never matched its Likha row (and created a duplicate). Now `[ \t]*`; named results are identical.
- `has_phone` uses `extract()`: a value with two different numbers is not "has a phone number" (same rule as the imports).

## Case table

| Case | Test | Result |
|---|---|---|
| Phone helper: forms, two numbers, prices, short, D/E/G order | `OrderPhoneTest` (13 forms + order test) | pass |
| Named orders unchanged | `S_121_1_1` | pass |
| Nameless, different phones → two rows | `S_121_1_2` | pass |
| Nameless, same phone → one row | `S_121_1_3` | pass |
| Phone from customer text when D blank | `S_121_1_4` | pass |
| No name, no phone → two rows + flag | `S_121_1_5` | pass |
| Macro nameless matches by phone | `S_121_2_1` | pass |
| Macro no phone: only identical text; different text touches nothing | `S_121_2_2` | pass |
| Macro named unchanged; blank name is not the next line | `S_121_2_3` | pass |
| PSID via customer values (+ ad id) | `S_121_3_1` | pass |
| PSID via chat text, standby chats ignored | `S_121_3_2` | pass |
| Ambiguous → none (values and chats) | `S_121_3_3` | pass |
| Existing PSID and named orders untouched | `S_121_3_4` | pass |
| Other page's customer not a candidate | `S_121_3_5` | pass |
| `has_phone` true for "09123456789", "+63 912 345 6789"; false for "₱999 po", "1999" | `ChatbotEngineTest::test_S_069_E3_every_condition_rule` rows | pass |

Red runs: `OrderPhoneTest` / `OrderImportKeyTest` first failed with `Class "App\Services\OrderPhone" not found` and, for the Likha cases, `'inserted' expected, 'updated' actual` (second nameless order merged); `has_phone` rows failed `false is identical to true`; `NamelessOrderPsidTest` failed with `null` PSID where `111` / `333` was expected.

## Not covered / limits

- The Macro job's row loop runs only inside `handle()` (Google API), so the Macro cases are tested through `OrderIdentity::find` and `fbNameFromInput`, which the loop calls. The run-item message with the count is not under test.
- Chat-text matching needs the phone's last 4 digits written contiguously in the message; "0912 345 67 89" style splits are not found (safe: no link).

## Suite

`vendor/bin/phpunit tests/Feature/Botcake tests/Feature/NightRun tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Orders`: 1298 tests, 12333 assertions, 1 error, 1 skipped.

The one error is local-only and pre-existing: `NightRunImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` runs `ImportMacroFromGoogleSheet::handle()` up to `Google_Client::setAuthConfig(storage/app/credentials.json)`, and that file does not exist on this machine. It stops before any code this work changed.

## Commits

- `docs: spec 121 orders without a Facebook name`
- `fix: orders without a Facebook name keep their own row in the Likha and Macro imports (spec 121)`
- `feat: has a phone number condition rule for chatbot flows (spec 121)`
- `fix: orders without a Facebook name get their PSID by phone from customer values or chat text (spec 121)`
- `docs: result for spec 121` (this file)
