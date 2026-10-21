# Plan 076: "wait for reply" can skip after a set time

Risk: medium (engine and a background job on trusted paths; the editor form sits behind the existing graph validation).

## Design left open by the spec

- **Deadline** = the time the run parks + `skip_after`. The run is started by the customer's message, so this is that message's time plus queue lag.
- **`chatbot_waits`**: id, page_id, psid, flow_id, node_id, due_at, created_at; unique(page_id, psid), index(due_at).
  - Written with an upsert when a live run parks at a wait with `skip_after`.
  - Deleted whenever `__wait` is cleared (in `CustomerFields::clear`, so a reply, a resume and "Clear all fields" all cancel it), and when a run parks at a wait without `skip_after`.
  - Test runs never write it.
- **Tick** (`chatbot:resume-skipped`, every minute, `withoutOverlapping`): up to 500 due rows per batch by `due_at`, for at most 50 s. Per row, `ChatbotRunner::skip()`:
  1. takes the same `chatbot-run:page:psid` lock (held by someone else: leave the row for a later tick);
  2. leaves the row when a chat of the customer is not run yet (the reply wins);
  3. claims the row with one `DELETE … WHERE id = ? AND due_at <= now` (once only, even with two ticks);
  4. checks that `__wait` is still that flow and step;
  5. checks the page (On, a flow, not paused), the published graph (same flow, the step still a wait with `skip_after`) and the 24-hour window, dropping the wait with a "skipped" event when one fails;
  6. runs the engine from the step's `skipped` port.
- **Config**: `skip_after` appears in the cleaned config only when set, so published graphs without it stay identical and spec 075's draft-equals-published check is not upset.

## Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | Config and ports, migration, engine park/resume, tick command and schedule, events | backend | medium | S-076.1–.7 |
| 2 | Editor form (amount + unit), ports and summary in the editor JS | frontend | medium | S-076.8 |
