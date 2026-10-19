# Plan 070: chatbot sequences

## Design details the brief leaves open

### Data

- **One migration** (`2026_10_26_100000_create_chatbot_sequence_tables`) creates the three tables from the brief.
- **Steps:** `position` runs 1..n.
- **Subscriptions:**
  - `current_step` is the position of the next step to run.
  - `exit_reason` also holds the reason a row is paused or errored (`page off`, `sequence deleted`, …), so the subscribers page shows why.
- **Extra index** `(sequence_id, status)`, for the list's counts.

### Editor

- The editor saves the whole sequence as **one JSON post**. Steps are replaced and renumbered 1..n inside a transaction.
- The page never reloads between "+ Add step", ↑, ↓ and ✕, so unsaved edits are never lost (a TeleSMS gap).
- **Known limit:** a reorder renumbers steps, so an in-flight subscription runs whatever step is now at its position.

### Delays and the send window

- **Inactive steps are passed over:** they neither run nor count toward delays.
- **Delay base:** step 1 counts from subscribe; each later step counts from when the previous step actually ran.
- **The window** (Manila, the app timezone), applied with `from`/`to`:
  - **from < to:** a time before `from` moves to `from` the same day; a time at or after `to` moves to `from` the next day.
  - **from > to** (crosses midnight, e.g. 20:00–02:00): allowed when `time ≥ from` or `time < to`; otherwise it moves to `from` the same day.
  - **from = to:** any time is allowed.

### Subscribing

- **Restarts from step 1:** after an unsubscribe, a completion or an error.
- **Does nothing:** while the subscription is `active` or `paused`.
- **Is refused, with a note in the run:** when the sequence is paused (inactive) or has no active steps.

### The tick: `chatbot:sequence-tick`, every minute, `withoutOverlapping(5)`

1. **Resume:** rows that are `paused` because their page was Off become `active` again once their page is On. Distinct pages, bounded.
2. **Pick due rows:** `active`, sequence active, `next_send_at <= now`, and `dispatched_at` null or older than 10 minutes. Up to 500 per batch, under `lockForUpdate`.
3. **Claim each row** with a conditional update: `where id = ? and (dispatched_at null or < cutoff)` setting `dispatched_at = now`. Only a row whose update changed 1 row is queued, so two ticks never claim the same row, even where `lockForUpdate` is a no-op (sqlite).
4. **Queue** one `RunChatbotSequenceStep` job per claimed row, on the `chatbot` queue.

### The job: `ChatbotSequences::runStep`

**Under the per-customer lock** `chatbot-run:{page}:{psid}`. If the lock is busy, the row is retried in 1 minute (`dispatched_at` is cleared).

**Checks, in order:**
1. **The row:** if it is no longer active, stop.
2. **The sequence:** deleted → `error`; inactive → release the row, and the tick ignores it until the sequence is active again.
3. **The step:** if there is no active step at or after `current_step`, the row is `completed` and an event is written.
4. **The page:** unknown → `error`; Off → `paused`, with an event.
5. **The bot:** paused for this customer → the step is held 1 hour, with an event.
6. **The 24-hour window:** the customer's last chat on that page must be under 24 hours old. If not:
   - `skip` → the step is skipped with an event and the pointer advances;
   - `stop` → the row becomes `unsubscribed (outside 24h)`, with an event.
7. **The flow:** missing or never published → an error event, then advance.

**The run:**
- `FlowRun` carries a new `fromStart` flag. With it, `FlowEngine::run` starts at Start and does not read or clear the customer's `__wait`, so a sequence step never steals or resumes a wait from the page's main flow.
- `{{last_input}}` is empty.
- Sends go through `BotcakeOutbox`, the emergency stop included.
- The run's events are stored as for any run.
- An exception becomes an error event, then advance, so a step never loops.

**Advance:** sets `last_sent_at`, moves the pointer to the next active step, and computes `next_send_at = window(now + delay)`. With no step left, the row is `completed`.

### Exit on reply

- **Where:** in `BotcakeChatController`, right after the chat is stored. The bot's run for that chat is queued as before.
- **What:** active or paused rows of that page and customer, in sequences with `exit_on_reply`, become `unsubscribed (reply)`, with one event each.
- **Cost:** one indexed lookup on `(page_id, psid)`, wrapped so it can never fail the intake.

### Events

- Type `sequence` with `detail.op`: subscribed, already, refused, unsubscribed, ran, skipped, held, paused, stopped, failed, completed.
- **Texts:**
  - "Subscribed to sequence X"
  - "Unsubscribed from X (reply)"
  - "Sequence X step 2 ran"
  - "Sequence X step 3 skipped (outside 24h)"
  - "Sequence X completed"
  - "Sequence X step 2 failed: …"
- Failed and error events appear in the inbox's Error logs.

### Flow step

- New type `sequence`, config `{action: subscribe|unsubscribe, sequence_id: id | 0 = all (unsubscribe only)}`, port `next`.
- In Test runs (`$run->test`) it changes nothing and records "would subscribe…".

### Access

- **Everything** sits behind `/chatbot` (auth) plus NavAccess `/chatblast` (403 otherwise), like flows.
- **Inbox Subscribe / Unsubscribe** need `canSend` (the CEO or the reply roles).

## Tasks

| # | Task | Side | Risk | Cases |
|---|---|---|---|---|
| 1 | Tables, `ChatbotSequences` service (window, subscribe, unsubscribe, exit on reply, claim, runStep), tick, job, scheduler, event texts | backend | high (sends to customers on a timer) | S-070.3 (service), .4, .5, .6, .8 |
| 2 | `sequence` flow step: graph, engine, editor form | backend + frontend | medium | S-070.3 (flow) |
| 3 | Sequences pages: list, editor, subscribers, nav | backend + frontend | medium | S-070.1, .2, .7, access |
| 4 | Inbox panel: "Subscribed sequences", plus the subscribe endpoint | backend + frontend | medium | S-070.3 (inbox) |
| 5 | Review, browser check, result | — | — | all |

The tasks share files (the events, the engine), so they run one after another, not in parallel.
