# Brief 070: `/chatbot/sequences`, timed follow-ups (drip) for chatbot customers

## Why

The owner's CBB flows subscribe customers to drip sequences, e.g. "PREORDER SEQUENCE", and his botfields hold `Seq 1..20` and `Seq Img 1..20` for each page's product. He wants sequences in our own chatbot, in almost the same format as TeleSMS's SMS sequences. Our send path is Botcake `send_content`, which works only within Messenger's 24-hour window after the customer's last message.

## Stories

- **S-070.1:** As the owner, I see a **Sequences** tab in the /chatbot nav, placed after Flow.
  - **The list** shows: name, number of steps, enrolled (active) count, completed count, and Active or Paused. It has "+ New sequence".
- **S-070.2:** **The editor**, like TeleSMS's:
  - name, description, Active;
  - **exit when the customer replies** (on by default);
  - **send hours** (from/to, Manila; default 08:00–21:00);
  - **when outside the 24-hour window**: skip the step and continue (the default), or stop the sequence.
  - **A timeline of steps**, each with: a delay amount and unit (minutes, hours or days), a counted-from note ("after the previous step", or "after subscribe" for step 1), the **flow to run**, a dropdown of flows that runs the *published* graph from its Start, and Active. Steps can be moved up or down and deleted. At most 50 steps.
- **S-070.3:** **Subscribing a customer:**
  - from a flow, with a new step type **Sequence**: subscribe or unsubscribe, and which sequence (or "all" for unsubscribe);
  - by hand in the **Inbox** right panel, in a "Subscribed sequences" section: list the customer's subscriptions with status and next send, and Subscribe / Unsubscribe (CEO, or the inbox reply roles).

  Subscribing again after an unsubscribe restarts from step 1. While a subscription is active, subscribing again does nothing.
- **S-070.4:** **Sending.**
  - A tick, `chatbot:sequence-tick`, runs every minute through the scheduler. It claims the due active subscriptions with a row lock and a `dispatched_at` stamp, and queues one job per subscription on the `chatbot` queue.
  - The job re-checks everything, then runs the step's flow for that customer through the same engine and outbox: send_content, the per-customer lock, events, and replies log.
  - It then advances the pointer and sets `next_send_at` for the next step, using the send hours. A time outside the hours moves to the next window start.
  - When there are no more steps, the subscription is **completed**.
- **S-070.5:** **Guards before a step runs:**
  - The customer's last message is under 24 hours old. If not, use the sequence's outside-window rule, and log the event "Step 3 skipped: outside the 24-hour window".
  - The page is On. If it is Off, **pause** the subscription and resume it when the page is On again.
  - The bot is not paused for this customer. If it is, hold the step and re-check in 1 hour.
  - The step's flow exists and is published. If not, log an error event and skip the step.
- **S-070.6:** **Exit on reply.** When "exit when the customer replies" is on, any new incoming chat from the customer on that page sets their active subscriptions on that page to `unsubscribed` (exit_reason `reply`). Do this in the chat intake, after the chat is stored. The bot's own flow still runs for that chat as usual.
- **S-070.7:** **Per-sequence subscribers page:** a list with customer, page, status, current step, next send, last sent, enrolled via, and exit reason. Filters: status and page. Bounded queries.
- **S-070.8:** **Logging.** Every step run, skip or error writes `chatbot_events`, so it shows in the inbox's executed actions, and its sends go to `chatbot_replies` as now.
  - New event texts: "Subscribed to sequence X", "Unsubscribed from X (reply)", "Sequence X step 2 ran", "… skipped (outside 24h)", "… completed".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **New tables:**<br>• `chatbot_sequences` (name unique, description, active, exit_on_reply, send_from, send_to, outside_window `skip\|stop`, timestamps);<br>• `chatbot_sequence_steps` (sequence_id, position, delay_amount, delay_unit, flow_id, active);<br>• `chatbot_sequence_subscriptions` (sequence_id, page_id BIGINT, psid BIGINT, status `active\|paused\|completed\|unsubscribed\|error`, current_step, next_send_at, dispatched_at, last_sent_at, enrolled_via `flow\|inbox\|other`, enrolled_by, exit_reason, timestamps).<br>Unique (sequence_id, page_id, psid). Indexes: (status, next_send_at) and (page_id, psid). | The TeleSMS model, sized for volume. |
| **A step runs a published flow.** That way it can use the Seq N / Seq Img N botfields and any steps (message, AI, sheet, set field). | Reuse the engine. |
| **Recovery.** A subscription with `dispatched_at` older than 10 minutes and not advanced is re-queued. A job only advances after the step's run finished or was skipped. A failed run logs an error event and advances, so it never loops forever, and it says so in Error logs. | Close the TeleSMS gaps. |
| **The send window and delays use Asia/Manila.** | Business hours. |
| **No new send path.** Use `BotcakeOutbox` and `CHATBOT_REPLIES_ENABLED` as the emergency stop. | One path. |
| **English UI.** Sticky headers, no page-level horizontal scroll, row details in modals. | House rules. |
| **Likha only.** | Scope. |

## Done when

- **Tests (fakes):**
  - subscribe from a flow step and from the inbox;
  - the tick claims once even when two ticks run;
  - delays and the send window (including crossing midnight);
  - outside 24h with both skip and stop;
  - page Off pauses and On resumes;
  - bot pause holds;
  - exit on reply;
  - completion;
  - recovery of a stale `dispatched_at`;
  - a missing or unpublished flow;
  - events written;
  - access checks: 302 signed out, 403 without access.
- `php artisan test tests/Feature/Chatblast` passes. The full suite adds no new failures (the known ones are ExampleTest and NightRun ImportStartTest).
- **Browser check** at 1366 and 390 px:
  - create a sequence with 3 steps;
  - subscribe a test customer from the inbox;
  - see the subscribers page and the events.

  Put screenshots in `docs/specs/070-chatbot-sequences/`.

## Report back

Report the commits, the migrations, the scheduler line, the routes, the test lines with the suite result, the review findings, the screenshots, and anything this brief got wrong.
