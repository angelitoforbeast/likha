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

## Files

## Story changes

## Evidence per case

## Commits

## Open points
