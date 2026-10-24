# Result 143: Encode order runs in the background; flows read the result through order_* variables

## Plan

Risk tier: medium (a change to an existing block behind the owner's flows; no new route or input surface). Groups run in
order and share `FlowEngine.php` / `FlowEncode.php`, so they are not parallel-safe; one commit per group.

**G1. Encode order never waits** (backend; cases .1, .2, .3, .4, .5, .10, .13, .14)
- `FlowEncode::start` no longer suspends: no ticket in `chatbot_ai_requests`, no `__ai`, no `encodePending`; it queues
  `EncodeOrder` (express lane `encode-live`) and returns port `next` at once, in any run (main flow or sequence step).
- Target row: the upload of this run; otherwise the customer's open row (`LiveOrder::find` with page + PSID, STATUS blank,
  24 h, crossing midnight); otherwise `failed` with the note "No order row to encode", nothing queued.
  The open-row fallback queues the latest still-unencoded upload of that row, or a new `order_encodes` row (`input.upload` =
  `encode`) that copies the conversation of the row's latest encode or upload; a row without one is read from its own
  columns.
- Idempotent: a row whose encode is queued, running, or whose Astra is still pending is not queued again (note, next).
- After the encode, the Astra job runs as in 137 (box on and kill switch not off, only when not PROCEED). No resume.
  `Runner::fail` on an upload row only marks it failed.
- Removed: the "Wait up to" setting (an old `wait` key is dropped by `FlowGraph::clean`), the `encode_*` variables, the
  resume, the `encodePending` reason. A ticket left in `encoding` by the old code at deploy time is still swept by
  `ChatbotAiQueue::sweep` and resumed; `encodeMacro` then just continues on next without encoding again.
- Test tab: note "Test: would encode", nothing queued, no variables set.

**G2. order_* variables** (backend; cases .6, .7, .8, .9, .12)
- New `App\Services\OrderEncode\OrderVars::read(page name, psid)`: the newest `macro_output` row with that PAGE and
  `botcake_psid` whose `ts_date` is within the last 7 days (the newest of the last 24 h is that same row whenever there is
  one), and its latest live `order_encodes` row.
- `FlowRun::vars()` adds them (memoised per run; Upload to macro and Encode order clear the memo), so Condition, Send, AI
  prompts and every `{{...}}` see them. No logging of their values.
- `order_hold_reason`: blank when the row is final (PROCEED/waybill) or not encoded yet; else `FlowEncode::holdReason` with
  the encode's outputs (and the stored Astra result), or, for a row without encode outputs, the rule parsed from AI ANALYZE
  ("HOLD R5: …") and APP SCRIPT CHECKER.
- `order_encode_state`: none (no encode row, or only an upload) / queued / running (job started, or Astra pending) / done /
  failed.

**G3. Builder** (frontend + controller; cases .10 UI, .11)
- Variable picker: an "Order" group with the 11 names and one-line help (controller `variableGroups` + editor `VAR_GROUPS`).
- Encode order form: no "Wait up to"; help text says it runs in the background and the result is read later with order_*.
  Summary and palette tooltip updated. Publish notice softened to a hint (the open-row fallback exists).

**G4. Stories, docs, verification**: S-143 in `qa/stories.md`, S-137 story changes, `docs/order-encode.md`, the Done-when
suite vs f84669dd, `skeptic-reviewer` (standard depth, foreground) on the whole diff.

## Progress

The main session wrote the code itself, test-first, and ran `skeptic-reviewer` (standard depth, foreground) once on the whole diff
plus one scoped re-check. The developer subagents were not used: the main session already had the code context, and the tier is medium.

- **Baseline** at f84669dd on the Done-when folders: 1794 tests, 14157 assertions, 1 error, 1 skipped. The error is
  `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, the known local-only missing
  Google credentials file.
- **G1 + G2 (one commit, c471de28).** They share `FlowEncode`, `FlowEngine` and the rewritten `EncodeMacroTest`, so they landed as one
  commit instead of two.
  - Red: `EncodeMacroTest` 54 tests, 16 errors and 11 failures, all for missing behaviour. The first failure was
    `Undefined array key "order_encode_state"`; the main-flow test failed because the run still suspended.
  - Green: `EncodeMacroTest` 54 tests. OrderEncode + Chatblast: 1180 tests, with 6 expected failures in the 137 wait/config tests.
    Those were updated (`ExpressLaneTest` deadline, `UploadEncodeBuilderTest` wait range; see Story changes), and the run then passed.
- **G3 (ee45856a).**
  - Red: 3 failures (the palette text, the publish hint, and the missing Order group).
  - Green: `UploadEncodeBuilderTest` 9 tests, Chatblast 771 tests.
- **G4 (9e75b521):** S-143 stories, the story changes, and `docs/order-encode.md`.
- **Review (standard depth):**
  - Two majors:
    - `order_*` reads ran on every chatbot run against the unindexed `order_encodes.macro_output_id`;
    - a lost job left a row "running" forever, so it could never be queued again.
  - Fix loop 1 (7bfe730e), test-first. Both new tests were red before the fix:
    - "Failed asserting that an array does not have the key 'order status'";
    - the stuck state was not `failed`.
  - After the fix, `order_*` are read only in flows whose steps or botfields name one, and a queued/running encode that has not moved
    for 30 minutes counts as `failed`.
  - The re-check closed both majors with no new finding.
  - Minors: the stale comment was fixed. The rest are accepted in `TODO.md` § 143.
- **Browser check:** not run. This session has no browser tools; the editor change is text, one picker group and one removed field.
- **`npm run build`:** not run in this worktree (no `node_modules`). The editor script is inline Blade. The new picker colours
  `bg-cyan-100`, `text-cyan-900` and `hover:bg-cyan-200` get compiled by the deploy's build; `text-cyan-900` was already used by 137's form.

## Files

- **New:** `app/Services/OrderEncode/OrderVars.php` (the `order_*` variables, `state()`, `named()`).
- **Changed:**
  - `app/Services/OrderEncode/FlowEncode.php`: `start` no longer waits. It handles the target row, `busy`, `orderFor`, `started` and
    `afterEncode` (only queues Astra), and `final` is now public.
  - `app/Services/OrderEncode/LiveOrder.php`: `openRow`.
  - `app/Services/OrderEncode/Runner.php`: marks the encode started; `fail` no longer resumes a run.
  - `app/Services/Chatbot/FlowEngine.php`: `encodeMacro` never suspends; the order_* memo is reset after Upload and Encode.
  - `app/Services/Chatbot/FlowRun.php`: `vars()` adds `order_*` when the flow names one.
  - `app/Services/Chatbot/FlowGraph.php`: no `wait`, and a softer publish hint.
  - `app/Http/Controllers/ChatbotFlowController.php`: the "order" picker group with help.
  - `app/Jobs/RunEncodeAstra.php`: comment only.
  - `resources/views/chatbot/editor.blade.php`: help, summary, defaults, the Order group, and no "Wait up to".
  - Tests: `EncodeMacroTest` (rewritten for S-143 plus the kept S-137 cases), `ExpressLaneTest` and `UploadEncodeBuilderTest`.
  - Docs: `docs/order-encode.md`, `qa/stories.md` and `TODO.md`.
- **Removed pieces:**
  - the wait/suspend path: the ticket insert, `__ai`, `FlowRun::$encodePending`, the "waiting for the encode" reason,
    `FlowEncode::resumed`/`finish`/`result`, and the Test-tab sample variables;
  - the "Wait up to" setting (`FlowGraph::ENCODE_WAIT*`; an old `wait` key is dropped on save and ignored on run);
  - the `encode_*` variables (`FlowEncode::VARS`, `clearVars`, `setVars`).
  - Kept on purpose: `FlowEncode::MODEL`/`STATUS`, the sweep of late `encoding` tickets and `resumeAi` for them. A ticket left from
    before the deploy still ends cleanly; `encodeMacro` then just continues on next.
- **Migrations:** none. `order_encodes` gains `input.upload` = `encode` (the open-row fallback) and `input.encode.started_at` /
  `astra_queued`.

### Target-row rule (as built)

1. The upload of this run (`Upload::ofRun`).
2. Otherwise the customer's open row (`LiveOrder::openRow`): same PAGE (run page name) and `botcake_psid`, STATUS and waybill blank,
   TIMESTAMP within 24 h of now (crossing midnight), the newest.
3. Otherwise failed with the note "No order row to encode"; nothing is queued.

Then:
- a final row (PROCEED, courier status or waybill) means next, untouched;
- a row with an encode queued or running, its Astra included (`FlowEncode::busy`), means next, not queued again;
- no key or no address file means failed;
- otherwise `EncodeOrder` goes on `encode-live`.

The open-row fallback encodes that row's last un-encoded upload. Otherwise it uses a new `order_encodes` row carrying the conversation of
the row's last encode or upload; with none, it reads the row's own columns.

### order_* variables

Source: the newest `macro_output` row with PAGE = page name, `botcake_psid` = PSID and `ts_date` within 7 days (the newest of 24 h is
that row whenever there is one). They are read once per run, again after Upload or Encode, and only in flows whose steps or botfields
name one. Values are never logged.

- `order_row_id`, `order_status` (STATUS), `order_checker` (APP SCRIPT CHECKER).
- `order_province`, `order_city`, `order_barangay`, `order_address`, `order_name`, `order_phone`: the row's columns.
- `order_hold_reason`:
  - blank when the row is final or not encoded yet;
  - otherwise `FlowEncode::holdReason` on the row's last done encode (with its stored Astra result);
  - for a row without one, from "HOLD Rn" in AI ANALYZE and APP SCRIPT CHECKER.
- `order_encode_state`, from the latest live `order_encodes` row of that row:
  - none (no row, or an upload not encoded yet);
  - queued;
  - running (job started, or Astra queued and not finished);
  - done;
  - failed (also when queued or running has not moved for 30 min).

## Story changes

All are listed in `qa/stories.md` § S-143 "Story changes". In short:
- S-137.13 became the open-row fallback.
- Retired with the wait: S-137.14, .20, .22, .23, .24, .25, .26, .27, .32, .43.
- Kept, now checked through the row and `order_*`: S-137.17, .18, .21, .29, .30, .31.
- Renumbered: .19 → S-143.14, .28 → S-143.5, .33 → S-143.13.
- S-137.38 became a softer hint.
- Two cases came from the review: S-143.8 (30-minute cap) and S-143.9 (no query when unused).

Spec vs this file: no conflict.

## Evidence per case

| Case | Test (`EncodeMacroTest` unless named) | Result |
|---|---|---|
| S-143.1 | `test_S_143_1_main_flow_continues_at_once…`, `ExpressLaneTest::test_137_1_the_new_encode…` | pass |
| S-143.2 | `test_S_143_2_a_sequence_step_uploads_and_encodes_without_waiting` | pass |
| S-143.3 | `test_S_143_3_without_an_upload…`, `test_S_143_3_with_no_order_row…` (4 rows) | pass |
| S-143.4 | `test_S_143_4_the_same_row_is_not_queued_twice…` | pass |
| S-143.5 | `test_S_143_5_a_final_row…` (2 rows), `test_S_143_5_a_staff_edited…` | pass |
| S-143.6 | `test_S_143_6_a_sequence_flows_condition…` (4 rows), `test_S_143_6_a_row_without_a_flow_encode…` (5 rows) | pass |
| S-143.7 | `test_S_143_7_order_variables_fill_a_send_template` | pass |
| S-143.8 | `test_S_143_8_…queued_to_running_to_done`, `…running_while_the_encode_job_runs`, `…a_failed_encode_job…`, `…stuck_for_30_minutes…` | pass |
| S-143.9 | `test_S_143_9_with_no_row…`, `…carry_the_rows_values`, `…a_flow_that_never_names…` | pass |
| S-143.10 | `test_S_143_10_an_old_config_with_wait…`, `UploadEncodeBuilderTest::test_S_137_1_block_configs_are_validated` | pass |
| S-143.11 | `UploadEncodeBuilderTest::test_S_143_11…`, `test_S_137_1_the_palette…` | pass |
| S-143.12 (P2) | `test_S_143_12_a_2359_row_is_read_at_0005`, `test_S_143_12_a_row_older_than_7_days…` | pass |
| S-143.13 (P2) | `test_S_143_13_the_test_tab_queues_nothing` | pass |
| S-143.14 (P2) | `test_S_143_14_no_astra_when_the_box_or_the_kill_switch_is_off` (2 rows) | pass |

Final verification on 7bfe730e (Done-when folders: OrderEncode, NightRun, Chatblast, StaffActivity, PageSettings, Botcake):
**1807 tests, 14171 assertions, 1 error, 1 skipped.**

The baseline at f84669dd was 1794 tests with the same 1 error, so this adds no new failure. The error is
`NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, the known local-only missing Google
credentials file.

The Astra and NightRun tests are unchanged. Only these 137 tests were edited: `EncodeMacroTest` (rewritten), the deadline line in
`ExpressLaneTest`, and the wait rows in `UploadEncodeBuilderTest` (see Story changes).

143: done — Encode order now queues in the background in any flow (main or sequence step) and never waits; it falls back to the customer's open row; flows read the result through the order_* variables.

## Commits

- d9d69970 docs: spec 143 brief and plan
- c471de28 feat: encode order queues in the background and flows read order_* variables (spec 143)
- ee45856a feat: builder shows the Order variables and the background Encode order help (spec 143)
- 9e75b521 docs: S-143 stories and the background Encode order in the order-encode doc (spec 143)
- 7bfe730e fix: order variables read only when a flow names them; a lost encode counts as failed after 30 minutes (spec 143)
- the commit of this result

## Open points

- **PAGE matching.** `order_*` and the open-row fallback match `macro_output.PAGE` to the run's page name, the value Upload to macro
  writes with the usual `{{page_name}}` template. A flow whose Upload template writes another page text would not be found by `order_*`.
- **No index on `order_encodes.macro_output_id`.** Only flows that name an `order_*` variable query it. Adding the index is the owner's
  call for a deploy (TODO § 143).
- **Stale values during a re-encode.** While a new encode is queued or running, `order_status` / `order_hold_reason` still show the
  previous result. Flows that need the fresh one should check `order_encode_state` = `done` first (documented).
- **Leftover wait tickets.** The sweep/resume code for `order-encode` tickets can be removed once production has none left.
- Not deployed; no remote hosts, OpenAI or Botcake were touched.
