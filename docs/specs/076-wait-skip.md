New task: spec 076, small to medium, about 1.5–2 hours.

**Branch.** In this worktree, run `git checkout -b feat/076-wait-skip main`. Main is 2b65a6cd, live on Likha with specs 069 and 074/075.

**Rules**
- Never push. Never touch remote hosts. Never call the real APIs.
- No attribution lines.
- Save this brief as `docs/specs/076-wait-skip.md` in your first commit, and the result as `docs/specs/076-wait-skip.result.md`.
- Run a review and a short browser check of the editor form before the result.

# Brief 076: "wait for reply" can skip after a set time

## Why

In the flow editor, a Send message step with "wait for reply" waits forever. The customer value `__wait` stays set, and the next message resumes the run. The owner wants CBB's option: when the customer does not reply within a set time, the flow moves on through a **Skipped** output (2026-10-19: "palagyan naman ng option to skip yung user input sa flow, magsskip pag certain time").

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **The config.** The send step's config gets `skip_after: {amount, unit: minutes\|hours}`. It is optional and empty by default. The longest allowed is 23 hours, because a send after the timeout must still be inside Messenger's 24-hour window. With `wait` on and `skip_after` set, the step has two ports: `reply` and `skipped`. Update `FlowGraph::ports`, `cleanConfig` and the editor form. | CBB's "Skipped" output. |
| **The runtime.** When a run parks at such a step, it records a deadline, e.g. `last customer message time + skip_after`.<br>• A scheduled command, `chatbot:resume-skipped` (every minute, `withoutOverlapping`), finds the waits past their deadline and resumes each one through `skipped`. It uses the same per-customer lock and order as `ChatbotRunner`.<br>• A reply before the deadline resumes through `reply` as now, and cancels the deadline.<br>• A wait whose step or flow changed, or a page that is Off or has no flow, is dropped quietly, with an event.<br>• Store the deadlines so the tick query is indexed and bounded for about 1M chats/day. A small additive table `chatbot_waits` (page_id, psid, flow_id, node_id, due_at; index on due_at; unique on page_id + psid) is fine. Keep `__wait` in sync. | Timed skip without scanning customer values. |
| **24-hour guard.** Before sending anything on a skipped resume, check that the customer's last message is under 24 hours old. If it isn't, log a skipped event and send nothing. | Facebook rejects sends outside the window. |
| **Events and inbox.** Log events "Waiting for reply at step nX (skips after 2 h)" and "No reply in 2 h → skipped at step nX". They show in the inbox's executed actions. | 074 view. |
| **Draft/publish.** It must work with spec 075's draft/publish: live runs use the published graph only. | — |

## Done when

- **Tests:**
  - A reply before the deadline goes through `reply`, and the deadline is removed.
  - With no reply, the tick resumes through `skipped`, once only, even with two ticks running.
  - A changed flow or a removed step drops the wait.
  - Outside 24h, nothing is sent and the event is logged.
  - Ports are validated: `skipped` is only valid when `skip_after` is set; the 23h maximum holds.
  - The editor saves and loads the setting.
- `php artisan test tests/Feature/Chatblast` passes. The full suite adds no new failures (the known ones are ExampleTest and NightRun ImportStartTest).
- Report the commits, the migration, the scheduler line, the test lines and the review findings.
