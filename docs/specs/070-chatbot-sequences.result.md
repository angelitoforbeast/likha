# Result 070: `/chatbot/sequences`, timed follow-ups (drip) for chatbot customers

The work was not pushed, and no remote host was touched. Tests use fake HTTP only. Nothing called the real Botcake, OpenAI or Google. The plan is in `docs/plans/070-chatbot-sequences.md`; the stories are S-070 in `qa/stories.md`.

## Commits

| Commit | What |
|---|---|
| `fd91d834` | docs: the brief, verbatim |
| `efb7a236` | docs: plan and stories |
| `b0e6100e` | feat: the tables, the `ChatbotSequences` service, the minute tick, the step job, exit on reply and the event texts. It also fixes the inbox Error logs crashing (500) when a customer has failed steps but no failed sends, a bug on main that the new test hit |
| `736e2de0` | feat: the **Sequence** flow step (engine, graph, editor form) |
| `e7697c64` | feat: the Sequences tab, list, editor, subscribers page, and "Subscribed sequences" in the inbox |
| `24886222` | fix (browser check): an icon for sequence events; the step's flow picker takes a full row on phones |
| `3a1df659` | fix (review loop 1): jobs check their own claim and due time; step flows never wait; post-run writes keep a concurrent exit; more tests |
| (last) | docs: this result, TODO, stories, screenshots |

## Migration (run by hand on deploy)

`2026_10_26_100000_create_chatbot_sequence_tables` creates:
- `chatbot_sequences`: name unique (120), description, active, exit_on_reply, send_from and send_to (`HH:MM`, default 08:00–21:00), outside_window `skip|stop`.
- `chatbot_sequence_steps`: sequence_id (cascade on delete), position, delay_amount, delay_unit, flow_id (set to null when the flow is deleted), active.
- `chatbot_sequence_subscriptions`: the columns from the brief.
  - Unique: (sequence_id, page_id, psid).
  - Indexes: (status, next_send_at) and (page_id, psid), plus **(sequence_id, status)** for the list's counts.

Every table is guarded by `Schema::hasTable`. The down migration drops all three.

## Scheduler line (`routes/console.php`)

```php
Schedule::command('chatbot:sequence-tick')
    ->everyMinute()
    ->withoutOverlapping(5);
```

The jobs (`RunChatbotSequenceStep`) run on the existing `chatbot` queue worker.

## Routes

All are in the `/chatbot` group (web, auth, allowed_ip). Each controller then checks NavAccess `/chatblast`.

| Method | Path | Name | Who |
|---|---|---|---|
| GET | `/chatbot/sequences` | `chatbot.sequences.index` | Chatblast access |
| POST | `/chatbot/sequences` | `chatbot.sequences.store` (30/min) | Chatblast access |
| GET | `/chatbot/sequences/{id}` | `chatbot.sequences.edit` | Chatblast access |
| PUT | `/chatbot/sequences/{id}` | `chatbot.sequences.update` (60/min, JSON) | Chatblast access |
| GET | `/chatbot/sequences/{id}/subscribers` | `chatbot.sequences.subscribers` (120/min) | Chatblast access |
| POST | `/chatbot/inbox/sequence` | `chatbot.inbox.sequence` (60/min) | Chatblast access plus the CEO or an inbox reply role |

Signed out → 302 to `/login`; no access → 403. S-070.1 tests both on every route.

## How it works

### Subscribing

- **From a flow:** the new Sequence step, with "Subscribe to a sequence" or "Unsubscribe" (one sequence, or all).
- **From the Inbox panel:** "Subscribed sequences" shows the status, step and next send for each subscription, with Subscribe and Unsubscribe buttons for the reply roles.
- **Restarting:** subscribing again restarts at step 1 after an unsubscribe, a completion or an error. While the subscription is active or paused, it does nothing ("Already in sequence X").
- **Refusals:** a paused sequence, or one with no active steps, refuses with an event.
- **The Test tab** changes nothing; it only notes "Test: would subscribe …".

### Timing

- **Delays:** step 1 counts from subscribe; each later step counts from when the previous step ran. Inactive steps are passed over.
- **Send window (Manila):** a time before `from` moves to `from`; a time at or after `to` moves to `from` the next day.
- **A window that crosses midnight** (e.g. 20:00–02:00) is open from `from` to midnight and from midnight to `to`. Equal from/to is open all day.

### The tick and the job

- **Each minute,** the tick resumes the subscriptions paused for "page off" whose page is On again. It then claims each due active row of an active sequence with a conditional update of `dispatched_at`, and queues one job per claimed row with that claim stamp.
- **One claim per row:** two ticks never claim the same row, and a claim older than 10 minutes is claimed again.
- **The job, under the customer's lock (`chatbot-run:{page}:{psid}`):**
  1. It does nothing if a newer claim has replaced its own, or if the step isn't due yet.
  2. It applies the guards, in this order:
     - **page Off:** the subscription is paused, and the tick resumes it when the page is On;
     - **bot paused for the customer:** the step is held 1 hour;
     - **24-hour window:** "skip" skips the step and continues; "stop" unsubscribes with reason "outside 24h";
     - **flow missing or never published:** an error event, then advance.
  3. It runs the flow's published graph from Start through the same engine and `BotcakeOutbox`, so the emergency stop applies, sends are logged in `chatbot_replies`, and events go to `chatbot_events`.
  4. It advances. When no steps are left, the subscription is completed.
- **A failed run** (an exception) is an error event plus an advance, so a step never loops. It shows in the inbox's Error logs.
- **A step never touches the main flow's wait:**
  - It never resumes or clears the customer's `__wait`.
  - A send that waits for a reply, inside a step flow, sends and ends the run.

### Exit on reply

- In the chat intake, right after the chat is stored, active or paused rows of that page and customer in sequences with exit on reply become `unsubscribed (reply)`.
- The bot's run for that chat is queued as before.
- This step never fails the intake. It is skipped until the tables exist.

### Events

Type `sequence`, shown in the inbox's executed actions with the ↻ icon:
- "Subscribed to sequence X"
- "Unsubscribed from X (reply)"
- "Sequence X step 2 ran"
- "Sequence X step 3 skipped (outside 24h)"
- "Sequence X step 1 held (bot paused)"
- "Sequence X paused (page off)"
- "Sequence X stopped (outside 24h)"
- "Sequence X step 2 failed: the flow is not published"
- "Sequence X completed"

The step's own run also starts with "Flow Y started (sequence X step 2)".

## Case table

Tests are in `tests/Feature/Chatblast/ChatbotSequenceEngineTest.php` (engine) and `tests/Feature/Chatblast/ChatbotSequencePagesTest.php` (pages, flow step, inbox). The last run is on `3a1df659`.

| Case | Test | Result |
|---|---|---|
| S-070.1 list, nav, + New sequence, 302/403 on every route | `test_S_070_1_the_list_shows_steps_enrolled_completed_and_status_behind_chatblast_access` | pass |
| S-070.2 editor save, renumbering, 422 cases (name, caps, times, unit, flow, 51 steps) | `test_S_070_2_the_editor_saves_the_settings_and_the_steps_in_order` | pass |
| S-070.3a flow step subscribe / already / unsubscribe all / restart; editor; Test tab | `test_S_070_3a_a_sequence_step_in_a_flow_subscribes_and_unsubscribes` | pass |
| S-070.3b inbox list, subscribe and unsubscribe, 422, 403 for non-reply roles | `test_S_070_3b_the_inbox_lists_the_customers_sequences_and_allowed_users_subscribe_and_unsubscribe` | pass |
| S-070.4a claimed once (two ticks, two claims), stale re-queue, overtaken and not-due jobs send nothing | `test_S_070_4a_each_due_row_is_queued_once_on_the_chatbot_queue_and_a_stale_claim_again` | pass |
| S-070.4b three steps, published graph, inactive step skipped, completion, events, the main wait kept | `test_S_070_4b_three_steps_run_their_published_flows_in_order_and_the_subscription_completes` | pass |
| S-070.4c window and delays, including crossing midnight (11 data sets) | `test_S_070_4c_the_next_send_is_the_delay_moved_into_the_send_window` | pass |
| S-070.5a outside 24h: skip and stop | `test_S_070_5a_outside_24_hours_the_step_is_skipped_or_the_sequence_stops` | pass |
| S-070.5b page Off pauses, On resumes | `test_S_070_5b_a_page_that_is_off_pauses_the_subscription_until_it_is_on_again` | pass |
| S-070.5c bot pause holds 1 hour | `test_S_070_5c_with_the_bot_paused_for_the_customer_the_step_is_held_for_an_hour` | pass |
| S-070.5d unpublished, deleted flow, engine exception → error event, Error logs, advance | `test_S_070_5d_a_missing_or_unpublished_flow_or_a_failed_run_is_an_error_and_the_pointer_moves_on` | pass |
| S-070.5e busy customer retried, unknown page, refused subscribe | `test_S_070_5e_a_busy_customer_is_retried_in_a_minute_an_unknown_page_is_an_error_and_a_paused_sequence_refuses` | pass |
| S-070.6 exit on reply through the real intake; other page and exit-off kept; bot still answers | `test_S_070_6_a_reply_exits_the_sequences_of_that_page_that_exit_on_reply_and_the_bot_still_answers` | pass |
| S-070.7 subscribers: filters, 100 per page and Older, bounded queries | `test_S_070_7_the_subscribers_page_filters_by_status_and_page_with_bounded_queries` | pass |
| S-069 T1 and S-074.5 nav (updated: Sequences after Flow) | `ChatbotTabsTest`, `ChatbotInboxTest` | pass |

**Red runs, test first:**
- The engine (S-070.4c and the rest) and the flow step (S-070.3a) failed first because their classes and migration didn't exist yet.
- The editor's Sequence button (S-070.3a) failed on a missing `data-add="sequence"`.
- The review fixes:
  - S-070.4a: the stale job sent a message;
  - S-070.4b: the main timer was replaced by the step's wait.

The 5e cases and the caps passed at once; they pin behaviour that was already right.

## Full suite

On `3a1df659`, run alone: **2 failed, 175 passed, 1823 passed with warnings** (20795 assertions, 326 s).

The two failures are the known ones named in the brief, so this work adds no new failure:
- `ExampleTest` ("the application returns a successful response");
- `NightRun\ImportStartTest` ("macro job final write applies only while the run is still active").

`tests/Feature/Chatblast` passes on its own: 415 tests, 2709 assertions (the yellow lines are PHPUnit deprecation notices that were already there).

## Browser check

Headless Chrome over CDP against a scratch app:
- `php artisan serve` on 127.0.0.1:8070, with a scratch sqlite file outside the repo;
- `CHATBOT_REPLIES_ENABLED=false`, so the tick's run logged the send as skipped and nothing reached Botcake;
- fake tokens, no OpenAI key, `QUEUE_CONNECTION=sync`.

The check ran twice, the second time on the final code (`3a1df659`), with the same results.

| Check | Result |
|---|---|
| Nav | Flow, **Sequences** (current), Page settings, Chats, Inbox, Replies, Test, Chatblast ✔ |
| Create a sequence | "+ New sequence" with a name opens the editor ✔ |
| 3 steps | 0 minutes / 2 hours / 1 day, three flows; ↑ and ↓ reorder without losing edits; "Unsaved changes." → "Saved 14:34:30."; after a reload the 3 steps are the same ✔ |
| Empty name | "The name field is required."; nothing saved ✔ |
| List | PREORDER SEQUENCE · 3 steps · 0 enrolled · 0 completed · Active ✔ |
| Flow editor | the Sequence step form (Subscribe / Unsubscribe, the sequences, "All sequences" for unsubscribe); the node summary "Unsubscribe from PREORDER SEQUENCE" ✔ |
| Inbox, subscribe | "Subscribed sequences" → PREORDER SEQUENCE active, Step 1 · next 2:34 PM ✔ |
| Tick | `chatbot:sequence-tick` → "1 queued"; the thread shows ↻ Subscribed…, ▶ Flow Seq 1 follow-up started (sequence PREORDER SEQUENCE step 1), Skipped: replies off, ↻ Sequence PREORDER SEQUENCE step 1 ran; the panel shows Step 2 · next 4:34 PM ✔ |
| Subscribers | the row with name, page, active, step 2, next send, last sent, inbox; the detail modal; the old sequence's 8 rows and the status + page filter ✔ |
| Inbox, unsubscribe | the row becomes "unsubscribed" ✔ |
| Phone 390 | list, editor, subscribers and the inbox panel at 390 = 390 (no sideways scroll) ✔ |
| Console and HTTP | no errors; the only 4xx was the deliberate empty-name save (422) ✔ |

**Found and fixed in `24886222`:**
- On phones, the step's flow picker was squeezed to a few letters.
- Sequence events had no icon in the thread.

Screenshots are in `docs/specs/070-chatbot-sequences/`:
- `sequences-list.png`, `sequence-editor.png`, `flow-sequence-step.png`;
- `inbox-subscribed-sequences.png`, `inbox-sequence-events.png`;
- `sequence-subscribers.png`, `sequence-subscriber-detail.png`;
- `phone-sequence-list.png`, `phone-sequence-editor.png`, `phone-sequence-subscribers.png`, `phone-inbox-sequences.png`.

The scratch server was stopped. Its files (`%TEMP%\cb070`) were left in place, because the guard blocks deletion.

## Review

`skeptic-reviewer`, adversarial depth on opus, on `0d574830..e7697c64`.

**First pass: 2 majors with their missing tests. Both fixed in `3a1df659`, test first:**
1. **A re-queued duplicate job ran the next step early.** With a queue backlog over 10 minutes, the tick re-claims the row; the first job then advances, and the second sent the next step at once, even outside the send hours. Each job now carries its claim stamp; a job whose stamp was replaced, or whose step is not due, does nothing.
2. **A step flow with "wait for reply" overwrote the main flow's wait and its timer,** so a customer in the middle of an order lost it. A sequence step now sends and ends at a waiting send, and never writes `__wait` or `chatbot_waits`.

**Minors fixed:**
- post-run status writes only apply while the subscription is still active, so a reply mid-step stays "unsubscribed (reply)";
- exit on reply is guarded by `hasTable` and wrapped twice, so it never floods the logs or fails the intake before the migration;
- a deleted sequence logs an error event;
- the inbox refuses a PSID over 19 digits;
- an unneeded docblock edit was reverted;
- tests were added for the busy lock, the unknown page, a refused subscribe and the input caps.

**Re-check of `3a1df659`:** both majors hold, with no new blocker or major.

**Accepted in `TODO.md` (section "070"), with reasons:**
- the tick walks overdue rows of paused sequences;
- `resumePaused` is capped at 500 pages;
- an hourly "held" event;
- replies made while a page is Off don't exit (the intake drops them);
- no test for an intake DB failure;
- "Clear all fields" in a step flow clears the main wait;
- edits move in-flight customers by position;
- "ran" with replies off;
- a 19-digit PSID above PHP's integer limit;
- re-subscribing during a running step.

## Story changes

- **S-069 T1 and S-074.5 (nav):** Sequences now follows Flow. Reason: the brief adds the tab.
- **S-070.4a:** adds the overtaken and not-due jobs. Reason: review major 1.
- **S-070.4b:** adds a step flow that waits. Reason: review major 2.
- **S-070.5e (new):** the busy lock, the unknown page and a refused subscribe. Reason: review, missing tests.
- **S-070.2:** adds the caps (name 121, description 1001, delay 1000). Reason: review.

## What the brief got wrong, or left open

- **"Claims … with a row lock":** the tick has no `lockForUpdate`. Each row is claimed with a conditional `UPDATE … WHERE dispatched_at IS NULL OR < now−10 min`. That update locks the row while it writes, and only one tick or job can win it. A select `FOR UPDATE` over the batch added nothing, except lock waits for the intake's exit-on-reply updates.
- **The skipped-event text:** S-070.5 says "Step 3 skipped: outside the 24-hour window"; S-070.8 says "… skipped (outside 24h)". The S-070.8 form is used: "Sequence X step 3 skipped (outside 24h)".
- **Subscribing after a completion or an error** also restarts at step 1. The brief only names "after an unsubscribe".
- **Subscribing to a paused sequence** (or one with no active steps) is refused with an event, as in TeleSMS. The brief says nothing.
- **A step flow that waits for a reply** can't wait inside a sequence: the customer's reply belongs to the page's main flow. Waiting there would overwrite its wait, which the review showed loses orders.
- **`{{last_input}}` in a step flow is empty:** a step is not a reply to a message.
- **"Last sent" counts the run:** with replies switched off (the emergency stop), the step still "ran" and the pointer advances.
- **Editing a sequence's steps:** steps are renumbered on save, so an in-flight customer runs whatever step is now at their position.
- **No delete for sequences** (the brief lists none): pausing a sequence (Active off) stops all its sends. The tick ignores its rows until it is active again, and they then continue where they were.
- **Who edits:** anyone with Chatblast access, as for flows. The brief's "as the owner" wasn't made CEO-only.

## Release steps (for whoever deploys)

1. Run `php artisan migrate --force`, which runs the migration above. It creates three new tables, so it is fast.
2. Keep the scheduler (`schedule:run` each minute) and the `chatbot` queue worker running; the tick only queues jobs.
3. No new settings or keys.

## Merge danger

- **Shared files, with small, obvious conflicts for any branch that touches them:**
  - `FlowEngine` (a new step type; sequence runs start at Start and never wait);
  - `FlowGraph` (TYPES, ports, config);
  - `BotcakeChatController` (exit on reply after the chat is stored);
  - `Inbox` (panel sequences, Error logs);
  - `ChatbotInboxController` (the sequence endpoint);
  - `ChatbotFlowController::graph` (the sequence list for the editor);
  - the editor and inbox views;
  - the nav.
- **Order:** until the migration runs, the tick and exit on reply do nothing, and the Sequences pages fail. Run the migration with the deploy.
