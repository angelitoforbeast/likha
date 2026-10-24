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

(filled in as the groups land)

## Files

## Story changes

## Evidence per case

## Commits

## Open points
