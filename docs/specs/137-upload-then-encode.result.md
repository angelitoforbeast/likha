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

The main session wrote the code itself, test-first, one commit per group, and ran `skeptic-reviewer` (standard depth) once
on the whole diff. The kit's developer subagents were not used: the main session already had the code context, and the
risk tier is medium.

- **Baseline** at b3c6c0d7, on the Done-when folders: 1689 tests, 1 error, 1 skipped. The error is
  `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, the known local-only
  missing Google credentials file.
- **G0:** brief and plan committed.
- **G1 (Upload to macro):**
  - Red: 16 errors, "InvalidArgumentException: Node n2 has an unknown type", before any code.
  - Green: `UploadMacroTest` 16 tests; OrderEncode + Chatblast 1079 tests, no failures.
- **G2 (Encode order + the wait):**
  - Red: 39 tests, 30 errors (`Class "App\Services\OrderEncode\FlowEncode" not found`, unknown node type `encode_macro`) and
    7 failures.
  - Green: `EncodeMacroTest` 39 tests; OrderEncode + Chatblast + NightRun 1444 tests, 1 error (the known one).
  - Two mutation checks were each caught: the sweep always failing the late ticket (caught by the "Astra slower" test), and
    `finish` without the `encoding` guard (caught by S-137.23).
- **G3 (builder):**
  - Red: 3 failures (palette text, the publish notice, the settings label).
  - Green: `UploadEncodeBuilderTest` 9 tests. `EncodeOrderNodeTest` (S-132, unchanged) passes, and so do the Chatbot
    editor and publish tests.
  - The editor script passes `node --check`.
  - No `npm run build` was needed: the editor script is inline Blade, and every new class already existed in the built CSS.
- **G4:** S-137 stories, docs and `TODO.md`.
- **Review:** no blocker and no major. Two minors fixed test-first (red first, then green):
  - an encode ticket could resume on an AI step of an edited flow;
  - a row deleted during the wait resumed on next.

  One missing test was added: the AI pool never takes an encode ticket. The other minors are accepted in `TODO.md` (§ 137).
  The report is in the main checkout under `.claude/137-logs/review-1.md`, which is not committed.
- **One local incident:** a script left by another session in the shared Temp folder (`fx.php`) ran once by mistake. It added
  duplicate lines to `ChatbotFlowController.php` in this worktree. I saw it in `git status` before any commit and restored the
  file from my commit; nothing foreign was committed. From then on, my scripts live under `.claude/137-logs/scripts/`.

## Files

- **New:**
  - `app/Services/OrderEncode/Upload.php`: Upload to macro.
  - `app/Services/OrderEncode/FlowEncode.php`: Encode order, the wait, the variables and the hold reason.
  - The tests `tests/Feature/OrderEncode/{UploadEncodeTestCase,UploadMacroTest,EncodeMacroTest,UploadEncodeBuilderTest}.php`.
- **Changed:**
  - `app/Services/Chatbot/FlowGraph.php`: types, ports, config validation and `publishNotice`.
  - `FlowEngine.php`: `uploadMacro`, `encodeMacro`, the "waiting for the encode" reason, and `no_order` for counts_order.
  - `FlowRun.php`: `$encodePending`.
  - `ChatbotRunner.php`: `resumeAi` picks the node type by the ticket's model.
  - `ChatbotAiQueue.php`: the sweep handles late `encoding` tickets.
  - `ChatbotEvents.php`: texts for `upload_macro` and `upload_skipped`.
  - `BotHealth.php`: AI-call stats skip encode tickets.
  - `app/Services/OrderEncode/LiveOrder.php`: `insertRaw`, `appendRaw`, `encodeRow`, `chatName`, and `write()` extracted from
    `update()` without a behaviour change.
  - `app/Services/OrderEncode/Runner.php`: the `uploaded()` path, and `fail()` for upload rows.
  - `app/Http/Controllers/ChatbotFlowController.php`: `notice` in the publish JSON.
  - `resources/views/chatbot/editor.blade.php`: palette, labels, ports, summaries, defaults, the two forms and the publish notice.
  - `resources/views/order-encode/final.blade.php`: "(legacy block only)".
  - `docs/order-encode.md`, `qa/stories.md` and `TODO.md`.
- **Not changed:** Astra (`AiCheckerRowRunner`, `AstraEncoder`, prompts and rules), `RunSpotAstra`, `AddressQuestion`, Validate,
  MacroChecker, the imports, and every existing test.
- **Migrations:** none.
  - The wait reuses `chatbot_ai_requests`: `model` = `order-encode`, `status` = `encoding` (8 characters; the column takes 12),
    and `context.row_id`.
  - Upload rows are `order_encodes` rows with `status` = `uploaded` (8 of 10 characters). `input` gains `upload`
    (insert|update), `fields`, `linked`, `not_accepted`, `encode` (`request`, `node`, `astra`) and `astra`.

### Node types, settings, variables

- **`upload_macro` "Upload to macro":**
  - Ports `next` and `failed`.
  - Config: `date, page, name, phone, input, shop, extracted, price` (templates of up to 2,000 characters each), `mode`
    `insert`|`update` (default `insert`), `if_not_found` `insert`|`skip` (default `insert`) and `counts_order`.
  - Events: `upload_macro` (inserted, updated, not_accepted, already, failed, test, skipped) and `upload_skipped`.
- **`encode_macro` "Encode order":**
  - Ports `next` and `failed`.
  - Config: `wait`, seconds, kept in 10–600 (default 120 since amendment 137-1, before 90; a value that is not a number gives
    the default).
  - Config: `astra`, a boolean (default true).
  - Events: `encode_pending`, and `encode_macro` (proceed, held, failed, final, already, test, skipped).
- **`encode_order`** (legacy) is unchanged. Its label is "Encode order (legacy)" and its palette button is hidden.
- **Variables** are customer fields. They are cleared at the start of every pass of Encode order and set on the resume, and
  `{{encode_hold_reason}}` works in templates and the Condition.
  - `encode_province`, `encode_city`, `encode_barangay`, `encode_address`, `encode_name`, `encode_phone`: the row's final values.
  - `encode_proceed`: `yes` or `no`.
  - `encode_hold_reason`: one of the reasons below; empty when proceed is yes.
  - `encode_row_id`.
- **Hold reasons**, the first that applies wins (`FlowEncode::holdReason`):
  - `address`: R2/R4/R6, the address check not ✅ (or Astra's final code not ✅, when Astra ran), or a blank place or address;
  - `duplicate`: R5;
  - `name`: FULL NAME is blank;
  - `phone`: no valid 09 number;
  - `price`: the page/price/quantity check is not ✅;
  - `other`;
  - `error`: failed, timed out, or could not be queued.
- **Test tab:** `encode_proceed = no`, `encode_hold_reason = address`.

### Suspend/resume and the deadline

- **Suspending:**
  - `FlowEncode::start` inserts the ticket (`encoding`, `deadline_at` = now + wait).
  - It marks the upload `queued` and sets `__ai` before it dispatches `EncodeOrder`.
  - It sets `aiPending` + `encodePending`; the run ends with "waiting for the encode".
  - Later chats of the customer return `locked` (`ChatbotAiQueue::pending`), as for the AI step.
- **The job:**
  - It encodes the stored conversation and writes the row under `RowLock`, then (when needed) runs Astra inline.
  - Before Astra, it stores the encode result in `answer`.
  - `finish()` moves the ticket from `encoding` to `answered` or `failed` in one conditional UPDATE, then dispatches
    `ResumeChatbotAi`. `ChatbotRunner::resume` runs the run once (`resumed`), under the customer lock.
- **Deadline:** `ChatbotAiQueue::sweep` runs every minute (`chatbot:resume-skipped`, and in the AI pool).
  - A late `encoding` ticket becomes `answered` with the stored encode result when only Astra was still running; the run
    continues on next.
  - Otherwise it becomes `failed`, and the run continues on failed with `error`.
  - A later `finish()` finds no `encoding` ticket and does nothing: the row is updated, the run is not.
- **The AI pool** never touches these tickets: `claim` takes only `pending` and `recover` only `sending` (tested).
- **Stale resumes:** a changed flow, a node that is no longer an Encode order, or a paused bot drop the resume with an
  `ai_dropped` event.
- **Sequence steps:** the step cannot wait there (the resume runs on the page's main flow), so it goes to failed.

### Phone and name rule as built (`LiveOrder::encodeRow`)

- **Phone:** `OrderPhone::forOrder(the encode's phone, the conversation, the extracted details)`, which returns a 09XXXXXXXXX
  number or ''. A valid number that differs from PHONE NUMBER replaces it; '' keeps the uploaded phone.
- **Name:** the encode's name, Unicode NFC with spaces collapsed, replaces FULL NAME when it differs (case-insensitive) and is
  not worse. Worse means blank, under 2 or over 100 characters, with a digit, without a letter, unknown / n/a / na / none /
  null / not provided, or a shorter part of the current name.
- **Both:**
  - They are skipped when `edited_full_name` / `edited_phone_number` is set or a person's HISTORICAL LOGS line names the column.
  - Each change gets a `ts|Likha encode|COLUMN|old|new` line.
  - Nothing is written when the row has a STATUS or a waybill.

## Story changes

- **S-132.1:** the legacy button stays in the page but is hidden (`hidden`); stored steps show "Encode order (legacy)". The test
  is unchanged.
- **S-136.29 to S-136.40** (the address question): these now apply to the legacy step only. /order-encode/final says "legacy
  block only", and the new steps never call `AddressQuestion`. The tests are unchanged.
- **Added cases:**
  - S-137.14 "the AI pool never takes an encode ticket";
  - S-137.22 "a row deleted while waiting resumes on failed";
  - S-137.32 "the step became an AI step".
- **S-137.8** is tested as one upload after the other plus a held customer lock, not with two real processes. The lock and the
  conditional UPDATE are the guarantee.
- **S-137.15** tests the invalid phone and the missing name in one table row.
- **S-137.20** also covers the settings label.
- **S-137.39** (P2, 390 px) was not run: no browser in this session. The forms use the legacy form's grid (one column below
  640 px).

## Evidence per case

Every case with its test is in `qa/stories.md` § S-137. All P1 cases and every P2 except .39 pass (the runs are listed below).

| Group | Cases | Tests | Result |
|---|---|---|---|
| Upload to macro | .2–.12, .33, .34, .35 | `UploadMacroTest` (16) | pass |
| Encode order | .13–.33 | `EncodeMacroTest` (42) | pass |
| Builder, publish, label | .1, .20 (label), .38 | `UploadEncodeBuilderTest` (9) | pass |
| Legacy and regressions | .34 (S-130.1), .36, .37 | the S-130/S-132/S-136 and NightRun suites, unchanged | pass |
| 390 px | .39 (P2) | — | not run |

Final verification (Done-when folders: OrderEncode, NightRun, StaffActivity, Chatblast, PageSettings, Botcake) on 0791eab8:
**1756 tests, 13982 assertions, 1 error, 1 skipped.** The baseline at b3c6c0d7 was 1689 tests with 1 error, so this adds no
new failure: there are 67 new tests, and no existing test was edited. The one error is the same in both runs:
`NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, the known local-only missing
Google credentials file.

## Commits

- f1b848ab docs: spec 137 brief and plan
- 3e00c0db feat: Upload to macro flow step writes the raw order row, insert or update (spec 137 G1)
- 2eb71916 feat: Encode order flow step encodes the uploaded row and the flow waits for its result (spec 137 G2)
- 88f6f38b feat: builder palette, forms and publish notice for Upload to macro and Encode order (spec 137 G3)
- 25e8b4c4 docs: stories S-137 and the Upload to macro / Encode order section (spec 137)
- 7b53688c refactor: set the encode wait mark before queueing the job
- 0791eab8 fix: encode tickets resume only on Encode order steps, a deleted row resumes on failed; review minors (spec 137)
- (last) docs: result for spec 137

## Open points

- **No migration and no new worker program.** Since amendment 137-1 the existing `likha-encode` and `likha-astra` workers must
  also listen to `encode-live` and `astra-live` (see "Amendment 137-1" below). The cache store must support atomic locks, as for
  136.
- **Upload to macro writes no address fields.** The 132 A–H templates have no address cells, so ADDRESS, PROVINCE, CITY and
  BARANGAY stay blank until Encode order. Safest choice; templates for them could be added if the owner wants.
- **COD on Insert** comes from the "PRICE: ₱…" in the shop details (as in the Likha import), else from the Price (H) cell.
- **Two Encode order steps after one upload in the same run:** the second does not encode again; it gives the row's current
  values ("Already encoded in this run").
- **Astra's HTTP timeout stays 120 s, as on the spot path.** Even with the new default wait of 120 s, a slow Astra means the
  flow goes on with the encode's result, and Astra may still PROCEED the row later. That is the brief's rule.
- **Replies switched off:** both steps write and queue nothing. Encode order then goes on next (as the legacy step does), with
  the variables cleared.
- **Not done locally:** no MySQL or pgsql run (only sqlite), and no browser check (none in this session).

## Amendment 137-1

The amendment as received (verbatim):

| Change | Who decided, why |
|---|---|
| **Express lane for customer orders.** Jobs for a waiting customer go to their own queue, which workers always take first: the legacy block's live encode and every new Encode order encode go on queue `encode-live`, and the Astra run of the new Encode order and the legacy `RunSpotAstra` go on `astra-live`. Shadow, import, backtest and the night run stay on `encode` / `astra`. Put the queue names in `config/order_encode.php` (not hard-coded in several places). Do not change the server: in the result, write the exact worker command change the deploy must make (e.g. `queue:work database --queue=encode-live,encode …` for the likha-encode programs, `--queue=astra-live,astra …` for likha-astra), and say what happens to jobs on the new queues if the deploy forgets that change (they must not be lost; state it). | The owner, 2026-10-24, after hearing that customer encodes wait behind the shadow and import encodes (3 minutes on average in the last 3 days): "Tapos dun sa live order …", then the express lane explained and accepted ("basta paganahin mo muna"). Live data: shadow encodes averaged 188 s queue+run, Astra 26 s average, 89 s max. |
| **Default "Wait up to" = 120 s** for new Encode order blocks (max stays 600). | The reviewer's decision: Astra alone can take up to ~90 s; 90 s left too little room once the encode itself and the queue pickup are added. |

Tests: a live encode and an Encode order encode are pushed on `encode-live`, their Astra on `astra-live`; shadow/import/backtest/night jobs stay on the old queues; the default wait is 120. Re-run `tests/Feature/OrderEncode tests/Feature/NightRun` (no new failures). Update the result (Files, Evidence, Open points) and end with one status line "137-1: <done|blocked> — <one line>".

### What was built

- **Queue names** are in `config/order_encode.php` → `queues`: `encode`, `encode_live` (`encode-live`), `astra` and `astra_live`
  (`astra-live`). The jobs read them from there; the old constants are only the fallback.
- **`encode-live`** (`EncodeOrder` with `live = true`) takes:
  - the legacy step's live encode (`FlowHook::node`);
  - the live Sheet step's encode (`FlowHook::queue`, mode `live`; also an order of a waiting customer);
  - every Encode order encode (`FlowEncode::start`).
- **`encode`** stays for shadow (`FlowHook::queue`, mode `shadow`) and the import (`ImportShadow`). The backtest runs the encoder
  inline, with no queue.
- **`astra-live`** takes the legacy `RunSpotAstra` (only ever dispatched for live orders) and the new **`RunEncodeAstra`**.
  - Before this amendment, the Astra of Encode order ran inline in the encode job. It now has its own one-try job, as the
    amendment asks, so an encode worker is not held while Astra runs.
  - The encode result is still stored on the wait first. An Astra failure, a crash (`failed()`) or a dispatch error continues on
    next with the encode's result, and so does a slow Astra (through the sweep).
- **`astra`** stays for the night run (`RunNightAstraRow`, which now also reads its queue name from the config).
- **Default "Wait up to" is 120 s** (`FlowGraph::ENCODE_WAIT`, and the editor's defaults); the range stays 10–600.

### Deploy: the worker command change (not done here; the server was not touched)

The repo copies are updated: `deploy/supervisor/likha-encode.conf`, and the `likha-astra` block in `docs/night-run.md`.

- **likha-encode** (`/etc/supervisor/conf.d/likha-encode.conf`), the `command=` line:
  - from `php /var/www/likha/artisan queue:work database --queue=encode --sleep=3 --tries=2 --timeout=600 --max-time=3600`
  - to `php /var/www/likha/artisan queue:work database --queue=encode-live,encode --sleep=3 --tries=2 --timeout=600 --max-time=3600`
- **likha-astra**, the `command=` line:
  - from `php /var/www/likha/artisan queue:work database --queue=astra --sleep=3 --tries=3 --timeout=540 --max-time=3600`
  - to `php /var/www/likha/artisan queue:work database --queue=astra-live,astra --sleep=3 --tries=3 --timeout=540 --max-time=3600`
- Then run `sudo supervisorctl reread && sudo supervisorctl update`, and `sudo supervisorctl restart likha-encode:* likha-astra:*`
  after the code deploy.
- The order of the names matters: Laravel's worker always takes a job from the first queue that has one, so `encode-live` /
  `astra-live` go first. No migration is needed; the `jobs` table holds any queue name.

**If the deploy forgets the worker change:** the jobs on `encode-live` / `astra-live` are not lost. They stay in the `jobs` table
(database connection, no expiry: `EncodeOrder`, `RunSpotAstra` and `RunEncodeAstra` have no `retryUntil`) until a worker that
listens to those queues starts; then they run in order. Until then:
- **Legacy live orders** (the Encode order (legacy) step and live Sheet steps) get no macro_output row: the order waits in
  `order_encodes` as `queued`.
- **Legacy spot Astra** waits as well.
- **A flow at a new Encode order step** reaches its deadline. The sweep resumes it on failed with `encode_hold_reason = error`,
  so the customer is never stuck. The row was already saved by Upload to macro and stays for staff, and the late encode still
  fills the row when it finally runs.
- **Check after the deploy:**
  `SELECT queue, COUNT(*) FROM jobs WHERE queue IN ('encode-live','astra-live') GROUP BY queue;` should stay near 0.

### Files (137-1)

- **New:**
  - `app/Jobs/RunEncodeAstra.php`;
  - `tests/Feature/OrderEncode/ExpressLaneTest.php`.
- **Changed:**
  - `config/order_encode.php` (`queues`);
  - `app/Jobs/EncodeOrder.php` (`$live`);
  - `app/Jobs/RunSpotAstra.php` (`astra-live`; the unused `QUEUE` constant was removed);
  - `app/Jobs/RunNightAstraRow.php` (the queue name from the config, still `astra`);
  - `app/Services/OrderEncode/FlowHook.php` and `FlowEncode.php` (`astraStep`, `afterAstra`, and `RunEncodeAstra` instead of
    inline Astra);
  - `app/Services/Chatbot/FlowGraph.php` (120 s);
  - `resources/views/chatbot/editor.blade.php` (120 s);
  - `deploy/supervisor/likha-encode.conf`, `docs/order-encode.md`, `docs/night-run.md` and `qa/stories.md` (S-137.40–.43);
  - the tests `EncodeMacroTest` (its helper also runs `RunEncodeAstra`), `UploadEncodeTestCase` (fakes it),
    `UploadEncodeBuilderTest` (120) and `EncodeOrderNodeTest::test_S_132_3` (the legacy live encode is now expected on
    `encode-live`; the amendment requires this change).
- **Also:** the code comments that cited the spec or amendment number were removed (the hard rule: comments never cite an
  id). Test names keep their case ids.

### Evidence (137-1)

- **Red first:** `ExpressLaneTest` had 5 failures (e.g. "The expected [App\Jobs\EncodeOrder] job was not pushed",
  "Failed asserting that null is identical to Array" for the config), and `UploadEncodeBuilderTest` had 2 (the 90 → 120 default).
  The shadow case passed at once, as it should: shadow stays on `encode`.
- **Then green:**
  - `ExpressLaneTest`: 6 tests (S-137.40–.43);
  - the whole of `tests/Feature/OrderEncode`;
  - `EncodeOrderNodeTest::test_S_132_3` with the amended queue.
- **Re-run of `tests/Feature/OrderEncode tests/Feature/NightRun`:** **722 tests, 6967 assertions, 1 error.** The error is the known local-only `NightRunImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (missing Google credentials), as in the baseline; no new failures.

### Open points (137-1)

- **Deploy:** the worker command change above, by the person who deploys, after the owner's yes.
- **Stuck jobs:** if `encode-live` jobs pile up, the new step's customers get `error` after the wait (never stuck), but legacy
  live orders wait for their row.
- **Astra in its own job adds one queue pickup** (a worker sleeps up to 3 s), inside the 120 s wait.
