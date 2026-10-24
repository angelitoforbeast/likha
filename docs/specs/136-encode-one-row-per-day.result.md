# Result 136: one row per customer per day through the Encode order step

## Plan

Risk tier: medium for every group (no page uses the live path yet). All backend; the only UI is two settings on
`/order-encode/final` and the order notes on `/order-encode` (Blade, escaped). Groups run in order (they share
`Runner.php` / `LiveOrder.php`), one commit per group.

Baseline at 1cf0de5d (`tests/Feature/{OrderEncode,StaffActivity,NightRun,Chatblast,PageSettings,Botcake}`): 1631 tests,
1 error, `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (the known
local-only missing Google credentials file).

**G1. One row per customer per day** (cases .1–.16, .41, .42, .45, .47–.53, .55; amend S-130.16, S-132.9, S-135.15/.16)
- `FlowHook::node()` / `queue()` also store `input.page_id` (for chat events and the sequence).
- `Runner::run()` for live orders runs under a **customer lock** (`Cache::lock('order-encode:customer:<page>:<psid>')`,
  waits up to 300 s, then the job retries): find the customer's row → link (`order_encodes.macro_output_id`) → encode →
  R5 → write. So two runs of one customer never run side by side and the second sees the first's row.
- New `LiveOrder::find()`: the customer = PAGE + `botcake_psid`; no PSID on the row (imports) = PAGE + fb_name on the order's
  ts_date. **Final** (STATUS set and not CANNOT PROCEED, or a waybill) on the order's day → not accepted: the row is not
  touched, the encode is `done` with the note "second order not accepted: row <id>" (`input.live_notes`, shown on
  /order-encode) and an `encode_order` chat event; no Astra, no sequence, no sheet. **Open** (STATUS blank) with an order
  time within 24 h of this order → linked and updated. CANNOT PROCEED rows are ignored (a new row is made).
- Order day = cell A (Manila) through `LiveOrder::timestamp()`; `ts_date` is now written by `LiveOrder` too (the DB trigger
  sets the same value in production).
- `LiveOrder::update()`: only ADDRESS, PROVINCE, CITY, BARANGAY, ITEM_NAME, COD, all_user_input, extracted_details/CXD,
  AI ANALYZE, APP SCRIPT CHECKER, STATUS (PROCEED only from the encode's own `proceed`); only while STATUS is blank (one
  conditional UPDATE); never a field with `edited_*` set or a person's line in HISTORICAL LOGS; one HISTORICAL LOGS line per
  changed field `ts|Likha encode|FIELD|OLD|NEW`. Never TIMESTAMP, ts_date, PAGE, fb_name, botcake_psid.
- R5 after the link: `Duplicates` skips the linked row, macro rows of the same PAGE + PSID, and encodes of the same
  page + PSID; the day of a linked order is the row's ts_date.
- `Runner::fail()`: an order linked to an existing row (re-encode) never writes the sheet; it ends `failed` with the note
  "re-encode failed, the row was not changed". A first encode keeps the sheet fallback.

**G2. The right conversation** (.17–.20)
- New `Conversation` (OrderEncode): the input cell may be `{{ALL USER INPUT}}` (the last 50 messages, S-093), so it holds
  earlier orders. Per run `input.read` = the raw cell. The text this order reads = the lines of this run's cell after the
  longest overlap with the cell of the customer's previous run (line suffix/prefix match); for a run linked to an open row,
  that is appended to the conversation of the row's earlier run (`input.conversation`), so a reply reads the original order
  plus the reply. Extracted details of a linked run = the earlier ones + the new ones (later labels win in `Parse`).
  The text is cut to the newest 8,000 characters (bounded GPT prompts).

**G3. Astra on the spot + the row lock** (.21–.28, .46, .54)
- New job `RunSpotAstra` (queue `astra`): after a live encode that did not PROCEED (not for not-accepted, shadow, test), when
  `order_encode_spot_astra` is on (default on). It runs `AiCheckerRowRunner::run(id, 'astra', …, source 'spot', user
  'Likha encode', only_when_status_blank)` — the night run's single-row path, Astra's prompt and rules unchanged, its
  `ai_checker_logs` row. Transient errors: up to 3 tries with backoff, then done; never left running.
- **Row lock** `RowLock` (`Cache::lock('macro-row:<id>')`): held by the spot Astra, by the encode's write of an update, and
  by `RunNightAstraRow` around its engine call (it waits; if still busy it ends `skipped` "Row busy").
- `Checker1SummaryController` / `Checker1IdleSummaryController` (if they read HISTORICAL LOGS users) skip the user
  "Likha encode".

**G4. Address question** (.29–.40)
- New `AddressQuestion`: after Astra (or after the encode when the switch is off, or when Astra errors), if the row is still not
  PROCEED and the hold is the address — encode hold R2, R4 or R6, the encode's final (address checker) not ✅, or Astra's
  final code not ✅, or PROVINCE/CITY/BARANGAY/ADDRESS blank — and not R5, subscribe once per row
  (`ChatbotSequences::subscribe(seq, page_id, psid, 'order-encode')`). `macro_output` row id in `order_encodes.input.asked`
  of any linked run = asked already. Result recorded as the `sequence` chat event (by subscribe) and in `input.live_notes`.
- App setting `order_encode_address_sequence` (empty default = no question), saved on `/order-encode/final` by the CEO only;
  an id that is not a `chatbot_sequences` row is refused.

**G5. Sheet once per day + regressions + docs** (.43, .44, .56–.58)
- Encode order step with "Also write to Google Sheet": no second sheet row when an earlier live order of the same page + PSID
  in the last 24 h already wrote one (`sheet_written`).
- Regressions: shadow Sheet step, Test tab, replies off.
- `docs/order-encode.md`, `qa/stories.md` S-136 and the amended cases.

Review: `skeptic-reviewer` (standard depth) on the whole diff after G5; fix loop per the kit.

## Progress

- G0: brief and plan committed.
- G1 done: customer lock, find (final / open / new), update with staff edits kept, not-accepted note, R5 after the link, re-encode failure without sheet. `OneRowPerDayTest` 23 green; S-130.16 test amended (the second order now meets a PROCEED row).
- G2 done: `Conversation` builds the text per order (lines after the previous run of the customer; a linked run adds to the row's earlier conversation; extracted details merged; input cut to 8,000 characters). `ConversationTest` 4 green; OrderEncode suite 296 green.
- G3 + G4 done (one commit, they share `AddressQuestion`): `RunSpotAstra` (queue astra, the night run's `AiCheckerRowRunner`, source `spot`, user `Likha encode`), `RowLock` held by it, by the encode update and by `RunNightAstraRow` ("Row busy" when still held), kill switch `order_encode_spot_astra`; `AddressQuestion` (address holds only, once per row, `order_encode_address_sequence`); "Likha encode" lines skipped by the Checker 1 summary and the notes build. `SpotAstraTest` 11, `AddressQuestionTest` 11, night-row lock test green. The G3 red run caught a real bug (the Astra closure missed `$o`). The G4 tests passed at once because the code was written first; I checked them by breaking the R5 and once-per-row guards, and each break was caught.
- G5 done: settings on `/order-encode/final` (CEO, validated), one sheet row per customer per day (`FlowHook::sheetDone`), S-136 in `qa/stories.md` with the amended S-130.16, S-132.9, S-135.15/.16, `docs/order-encode.md`. `LiveSettingsTest` and `EncodeOrderNodeTest::test_S_136_43_44` red, then green.
- Review (skeptic-reviewer, standard depth): 3 majors, all fixed in fix loop 1 (commit 307603b0):
  - `FlowHook::queue()` (the live Sheet step) did not store `page_id`.
  - `sheetDone()` skipped the sheet, and so its fallback, for a next-day order or an order after CANNOT PROCEED.
  - A re-encode could replace filled columns with blank GPT answers.

  Minors: two fixed (a failed Astra dispatch now asks the question at once; the lock times now fit inside the job timeouts); the rest are accepted in `TODO.md` (section "136").
- Re-check of fix loop 1: no majors left. Of its three new minors, one is fixed (the open-row branch of `sheetDone` keeps the 24-hour limit of `LiveOrder::find`, test step added to `test_S_136_43_44`); two test gaps are accepted in `TODO.md`.
- Full verification (below): no new failures compared with 1cf0de5d.

## Files

- **New:**
  - `app/Services/OrderEncode/{Conversation,AddressQuestion,RowLock}.php`
  - `app/Jobs/RunSpotAstra.php`
  - tests `tests/Feature/OrderEncode/{LiveRowTestCase,OneRowPerDayTest,ConversationTest,SpotAstraTest,AddressQuestionTest,LiveSettingsTest}.php`
- **Changed:**
  - `app/Services/OrderEncode/{LiveOrder,Runner,Duplicates,FlowHook,Settings,NotesBuilder}.php`
  - `app/Services/Chatbot/FlowEngine.php` (Encode order step: sheet once per order of the day)
  - `app/Jobs/RunNightAstraRow.php` (row lock around the engine call)
  - `app/Http/Controllers/{OrderEncodeController,OrderEncodeFinalController,Encoder/Checker1SummaryController}.php`
  - `resources/views/order-encode/final.blade.php`
  - `config/order_encode.php` (`customer_lock_wait` 240, `row_lock_wait` 180, `night_row_lock_wait` 200)
  - `docs/order-encode.md`, `qa/stories.md`, `TODO.md`
  - tests `EncodeOrderTest` (S-130.16 amended), `EncodeOrderNodeTest` (+2), `NotesBuildTest` (+1 variant), `NightRun/NightAstraRowJobTest` (+1)
- **Migrations:** none. `order_encodes.input` (JSON) gains the keys:
  - `page_id`, `linked`, `not_accepted`, `live_notes`
  - `astra` (`status`, `final_code`, `log_id`, `at`)
  - `asked` (`result`, `sequence`, `at`)
- **New settings** (`app_settings`, set on `/order-encode/final`, CEO only):
  - `order_encode_spot_astra`: default `on`; `off` is the kill switch.
  - `order_encode_address_sequence`: default empty, which means no question; an id must be a `chatbot_sequences` row.
- **"Address" holds** (these ask the question):
  - encode hold R2, R4 or R6;
  - the encode's address-checker final not ✅;
  - when Astra ran: Astra's final code not ✅;
  - any of PROVINCE, CITY, BARANGAY, ADDRESS blank.

  Never R5, never a row with a STATUS, never a not-accepted order. When Astra ran and its address is ✅ but the row is not PROCEED (a name, phone or price hold), no question is asked.
- **The right conversation:** `Conversation::build`. The text is the lines of this run's input cell that come after the longest line overlap with the cell the customer's previous run read (`input.row`). A run linked to an open row appends them to the conversation of the row's last encoded run (`input.fields.input`), and appends its extracted details to that run's (in `Parse`, the last value for a label wins). The result is cut to the newest 8,000 characters (extracted details to 4,000).
- **Locks:**
  - Customer lock `order-encode:customer:<md5(page|psid)>`: a cache atomic lock held for the whole live encode, so a second run waits (240 s), then the job retries.
  - Row lock `macro-row:<id>` (600 s): held by `RunSpotAstra` (never waits; released for 60 s if busy), by the encode's update (waits 180 s) and by `RunNightAstraRow` (waits 200 s, then ends the row `skipped` "Row busy").
  - The update itself is one conditional UPDATE ("STATUS still blank").

## Story changes

- Amended, as the brief asked:
  - **S-130.16:** a second order the same day meets the PROCEED row and is not accepted; the test is amended to assert this.
  - **S-132.9:** "a different run is a new order" became "a new encode linked to the customer's row of the day"; the test is unchanged.
  - **S-135.15 / S-135.16:** duplicates are other customers only; the customer's own row never counts. The tests are unchanged (import rows, no PSID).
- **S-136.30** adds "even when Astra leaves the address open" for R5, and the name/phone/price case is tested as "Astra's address ✅ but not PROCEED". The encode itself never holds for name, phone or price.
- **S-136.38** is not covered (P2); the reason is in the stories and in `TODO.md`.
- **S-136.5** checks the two rows only; Validate is unchanged and not re-tested.

## Evidence per case

The full case table with test names is in `qa/stories.md` § S-136: all 57 tested cases pass on 2026-10-24, and .38 is not run.

Fix round 1 (review):
- **S-136.4a** (added): an order with no PSID and a blank FB name made the identity condition empty, so `LiveOrder::find` matched every row of the page in the date window. It now returns no match (a new row). Test `OneRowPerDayTest::test_S_136_4a_an_order_without_psid_and_fb_name_makes_its_own_row_next_to_a_strangers_open_row`: red first ("Failed asserting that 1 is identical to 2"), then green.
- `docs/order-encode.md`: the customer-lock wait now says 240 s, as in `config/order_encode.php`.
- `tests/Feature/OrderEncode` after the fix: **323 tests, 1002 assertions, no failures**.

Final verification (`php vendor/phpunit/phpunit/phpunit tests/Feature/OrderEncode tests/Feature/StaffActivity tests/Feature/NightRun tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`), after fix loop 1 and its re-check: **1685 tests, 13762 assertions, 1 error, 1 skipped**.

Baseline at 1cf0de5d: 1631 tests, 1 error. The error, the same in both runs, is `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, the known local-only missing Google credentials file.

Red runs:
- **G1:** 14 failures and 2 errors before the code (e.g. `test_S_136_2`: "Failed asserting that 'PROCEED' is null").
- **G2:** 3 failures (e.g. `test_S_136_20`: "Failed asserting that 20004 is equal to 8000 or is less than 8000").
- **G3:** with the hook disabled, 3 failures and 5 errors; the errors exposed a real bug (`Undefined variable $o` in the Astra closure).
- **G4:** passed at once because the code came first. I checked the tests by breaking the R5 guard and the once-per-row guard; both breaks failed tests.
- **G5:** `test_S_136_40` "Failed asserting that two arrays are identical"; `test_S_136_43_44` "Failed asserting that 2 is identical to 1".
- **Fix loop 1:** the three new tests failed first (e.g. "Undefined array key page_id"; ADDRESS '' instead of 'Blk 9 Lot 1 Purok 2').

One test-isolation leak was found and fixed: `AiCheckerRowRunner` caches the ai_checker_logs `detail` columns in a static, so the new base class now migrates them too. Without that, 24 NightRun tests failed when run after OrderEncode.

## Commits

- 1ce6aff1 docs: brief and plan
- 0c30d231 feat: one macro_output row per customer per day (G1)
- ac94b6df feat: each live encode reads only its own order's messages (G2)
- 9d868c7a feat: Astra on the spot and the address question (G3 + G4)
- 46a30147 feat: settings, sheet once per day, stories and docs (G5)
- 307603b0 fix: review fix loop 1
- (last) fix: the open-row sheet check keeps the 24-hour limit; result and TODO

## Open points

- The deploy needs no migration. The `astra` worker that already runs for the night run also takes `RunSpotAstra`. The cache store must support atomic locks (redis or database); the array and file stores lock only within one server.
- The owner sets up the address sequence and picks it on `/order-encode/final`. Until then, an address hold is left for staff with a note.
- When a customer's open row is PROCEEDed by staff and the customer writes again the same day, the message is "not accepted" (a note and a chat event only, no customer message, as decided).
- A reply's phone number never reaches the row: PHONE NUMBER is not in the update list (as decided).
