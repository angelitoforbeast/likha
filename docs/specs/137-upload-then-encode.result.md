# Result 137: Upload to macro writes the order row; Encode order encodes that row and gives the flow its result

## Plan

Risk tier: medium for every group (no flow uses the new blocks until the owner adds them; the legacy block is unchanged).
Groups run in order and share `FlowEngine.php` / `FlowGraph.php`, so there is one commit per group.

New node type keys: `upload_macro` ("Upload to macro") and `encode_macro` ("Encode order"). The stored `encode_order`
type stays and is labelled "Encode order (legacy)". Its palette button stays in the page but is hidden, so S-132.1 passes
unchanged.

**G1. Upload to macro** (backend; cases .1 backend part, .2–.12, .33 upload part, .34, .35)
- `FlowGraph`: the type, ports `next` + `failed`, and its config: the A–H templates of 132 (`date, page, name, phone, input,
  shop, extracted, price`), `mode` insert|update (default insert), `if_not_found` insert|skip (default insert), and `counts_order`.
- New `App\Services\OrderEncode\Upload` (called by `FlowEngine::uploadMacro`):
  - Every upload is one `order_encodes` row: `mode` live, `status` `uploaded`, `input.upload` = insert|update,
    `input.row`, `input.node`, `input.page_id`, `input.fields` (this order's messages) and `macro_output_id`.
    This row is the "row id for this run": the Encode order of the same run finds it by run_id + psid + flow.
  - The conversation follows the 136 rule (`Conversation::build`): the lines after the customer's previous run. In update
    mode, the lines are appended to the conversation of the row's last upload or encode.
  - **Insert:** a new raw `macro_output` row with TIMESTAMP, ts_date, PAGE, fb_name, FULL NAME (the FB name), PHONE NUMBER
    (the flow's phone, normalised to 09… when valid), ITEM_NAME/COD from the shop details and price, shop details,
    all_user_input (`Encoder::allUserInput`, this order's messages), extracted_details/CXD and botcake_psid. STATUS and the
    address fields stay blank. R5 does not run here.
  - **Update:** under the 136 customer lock (short wait), `LiveOrder::find` looks for the open, final or missing row:
    - Open row: only `all_user_input`, `extracted_details` and `CXD` change (one conditional UPDATE while STATUS is blank,
      under `RowLock`), with a "Likha encode" HISTORICAL LOGS line per changed column. A column with a person's log line is
      kept.
    - Final row: nothing changes; the note "second order not accepted: row <id>" and an event; continue on next.
    - Not found: Insert or Skip (event `upload_skipped`).
  - Idempotent per run + node: a second pass returns the same row.
  - A DB error goes to the failed port, inside a transaction, so no half row is left.
  - Test tab: "Test: would insert/update", nothing written. Replies off: nothing written.
  - The page's shadow/live mode is not read.
- `counts_order` emits the `order` event only when a row was written or updated (not on skip, not-accepted or failure).

**G2. Encode order + the wait** (backend; cases .13–.32, .33 encode part)
- The wait reuses `chatbot_ai_requests` and the AI resume path (no new table). An encode ticket has `model` = `order-encode`
  and `status` = `encoding`, so the AI pool never claims or recovers it (it takes only `pending` / `sending`).
  `__ai` = the request id, so later chats of the customer are held (`ChatbotAiQueue::pending`). `deadline_at` = now +
  "Wait up to" (default 90, 10–600 s).
- `FlowEngine::encodeMacro` checks these in order and stops at the first that applies:
  - It clears the `encode_*` variables.
  - Test: sets the sample variables.
  - Replies off: skipped.
  - No upload in this run: failed, `error`.
  - A sequence-step run or a test outbox: failed (cannot wait).
  - No key or no address file: failed at once.
  - The row is already PROCEED or has a waybill: proceed yes at once, nothing changed.
  - Otherwise: it queues `EncodeOrder` for the upload's `order_encodes` row and suspends with the reason "waiting for the
    encode".
- `Runner::run` handles upload rows with its own path (`uploaded()`):
  - The 136 encode (`encode()`, improved rules, R5 counting other customers only) runs on the stored conversation.
  - New `LiveOrder::encodeRow` writes ADDRESS, PROVINCE, CITY, BARANGAY, AI ANALYZE, APP SCRIPT CHECKER, FULL NAME and
    PHONE NUMBER, and STATUS = PROCEED by the first-encode rules. It skips staff-edited fields, blank answers over filled
    ones, and rows that already have a STATUS or a waybill. It writes HISTORICAL LOGS "Likha encode" lines, under `RowLock`.
  - No `AddressQuestion`, no sheet.
- Astra, when the box is on and the `order_encode_spot_astra` kill switch is not off:
  - It runs only when the row is not PROCEED.
  - The encode's result is stored on the ticket first.
  - Then Astra runs inline in the encode job, on the same row: `AiCheckerRowRunner::run(id, 'astra', …)` as `RunSpotAstra`
    calls it, under `RowLock`, without waiting for the lock.
  - An Astra error, a busy row or a deadline means the ticket resumes with the encode's result.
- Finish: one conditional UPDATE `encoding` → `answered` | `failed` with the result JSON, then `ResumeChatbotAi`.
  `ChatbotRunner::resumeAi` also accepts `encode_macro` nodes. A resume runs exactly once (`resumed`); a flow change or a
  bot pause drops it with an event.
- Deadline: `ChatbotAiQueue::sweep` turns a late `encoding` ticket into:
  - `answered` (proceed no, from the stored encode result) when only Astra is still running;
  - otherwise `failed` (`error`).

  A late job finds the ticket finished: it still writes the row, but never resumes or changes the variables.
- A failed or exhausted job (`EncodeOrder::failed` → `Runner::fail`) on an upload row finishes the ticket as failed; the row
  stays held for staff.
- Variables on resume: `encode_province`, `encode_city`, `encode_barangay`, `encode_address`, `encode_name`,
  `encode_phone` (the row's final values), `encode_proceed` yes|no, `encode_hold_reason` and `encode_row_id`.
  The hold reason is the first that applies, in this order:
  - `address`: R2/R4/R6, the address check (or Astra's final code) not ✅, or a blank place or address;
  - `duplicate`: R5;
  - `name`: FULL NAME blank;
  - `phone`: no valid 09 phone;
  - `price`: the page/price/quantity check not ✅;
  - `other`;
  - `error`: failed.
- `BotHealth` AI-call stats skip the encode tickets.

**G3. Builder UI + publish notice + settings label** (frontend + a small backend part; .1 UI, .38, .39)
- `chatbot/editor.blade.php`:
  - Palette buttons for both blocks, with help text; the legacy button is hidden.
  - LABEL "Encode order (legacy)" for `encode_order`.
  - Ports, summaries and defaults.
  - Upload form: Mode, If not found (shown only for Update) and the A–H templates (shared with the legacy form).
  - Encode form: "Wait up to" (seconds) and the Astra checkbox. One column at 390 px.
- Publish: the JSON response carries `notice` when an Encode order has no Upload to macro before it on the path
  (non-blocking); the editor shows it.
- `/order-encode/final`: the address-sequence label adds "(legacy block only)".

**G4. Stories, docs, regressions, verification**
- `qa/stories.md` S-137 with the test per case; S-136/S-132 story changes.
- `docs/order-encode.md` gets a section on the two blocks.
- Full Done-when suite vs the baseline at b3c6c0d7.
- `skeptic-reviewer` at standard depth on the whole diff (foreground); fix loop per the kit.

## Progress

- G0: brief and plan committed.

## Files

(filled in at the end)

## Story changes

(filled in at the end)

## Evidence per case

(filled in at the end)

## Commits

(filled in at the end)

## Open points

(filled in at the end)
