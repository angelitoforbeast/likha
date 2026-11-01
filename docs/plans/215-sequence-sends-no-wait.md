# Plan 215: sequence sends don't hold a worker while waiting on the send API

## What the code does today (checked on the base commit)

- `ChatbotSequences::tick()` claims due rows (`BATCH` 500, `SECONDS` 50) with a conditional update of `dispatched_at`,
  skips a row whose `RunChatbotSequenceStep` is still queued (its unique lock is held), and re-claims a row whose
  `dispatched_at` is older than `REDISPATCH_MINUTES` (10).
- `RunChatbotSequenceStep` (queue `chatbot`, timeout 280, `ShouldBeUniqueUntilProcessing`, `uniqueFor` 3 h) calls
  `runStep()`, which takes the customer lock `chatbot-run:<page>:<psid>`, checks the claim, the sequence, the page
  (set up, On), bot paused and the 24 h window, then runs the step's flow from Start with `startedBy`,
  `sequenceId` and `sequenceStep` set, writes the `sequence ran` event (name, step, sequence_id, due), stores the
  run's events and calls `advance()`.
- Every send inside that run is inline: `FlowEngine::send` hands off only when `BotcakeSendQueue::async()` is true,
  and `async()` requires `startedBy === null` and a Botcake page. So a sequence send is
  `BotcakeOutbox::send` → `BotcakeProvider` (`send_content`, 429 retried 2/4/8 s or Retry-After up to 10 s, 5xx and
  HTTP 0 not retried, booked on the page's shared rate slot) or `GatewayProvider` (one POST, no retry,
  `messaging_type` UPDATE, `client_ref`, then `ForbeastWatch::afterSend`). The worker waits the whole time.
- `afterSend` skips the "wait for reply" of a sequence step (`startedBy !== null`), and `retrySend` (the one resend
  of a 5xx / HTTP 0) is only for runs where `async()` is true, so sequences never get it.
- The send pool (`botcake:send-pool`, `BotcakeSendPool` on `TicketPool`) knows two classes: bot replies
  (`kind` chatbot, priority 0) and chatblast (priority 1, 2), each with up to `send_concurrency` (100) held and
  `perPage()` (6 at 2/s) held per page lane. One request per page per `1 / rate_per_page` s, shared through
  `BotcakeClient::slot()` with every blocking sender. Per-customer order: a ticket is claimed only when no earlier
  ticket of the same `customer` is pending or sending. `called_at` is written before the call; `recover()` turns a
  called-but-unanswered ticket into failed "Botcake did not answer (HTTP 0)" and a not-yet-called one back to
  pending. `finishChatbot()` writes the `chatbot_replies` row and dispatches `ResumeChatbotSend` on `chatbot-high`.
- `ChatbotRunner::resume()` rebuilds the run from the page's current flow (not the step's flow), with no
  `startedBy`, `sequenceId` or `sequenceStep`, and drops the result unless the customer's `__ai` field holds the
  ticket mark. For a sequence step it would drop every result as "the flow changed" or lose the sequence context.
  So the sequence path needs its own resume.
- `/chatbot/speed` matches a `sequence ran` event to the step's reply rows sent in the 600 s before the event
  (`STEP_PUSH_SECONDS`), so the `ran` event must be written after the last send of the step is recorded.
- `/chatbot/speed` Sequences already shows avg, p90 and max of scheduled → pushed (and pushed → Meta) per hour
  (`speed-seq-cells.blade.php`); S-215f.1 is a pin test, not new UI.
- `PageTransfers::release()` ends `kind = chatbot` tickets of a page that moves away; `hold()` turns active
  subscriptions into `held` and clears `dispatched_at`; `advance()` already records the step on a held row.
- S-070.4a pins the 10-minute re-claim (`ChatbotSequenceEngineTest`): it changes with this spec (Story changes).

## Choice: option (a), the send pool carries the sequence step

A sequence send becomes a ticket of its own kind (`sequence`) in `botcake_send_requests`, sent by the same pool for
both providers, and the step resumes from the result in a job on the `chatbot` queue.

Why (a) and not (b), a job that sends many steps concurrently:

| Decision | (a) pool ticket | (b) concurrent job |
|---|---|---|
| One behaviour for every provider | the pool sends Botcake and gateway tickets the same way | possible, but a second sender |
| Never twice; unsure = failed | `called_at` before the call and `recover()` already exist and are tested (S-089.7) | a killed job loses which calls went out; needs new markers |
| Same failure semantics | the pool already reads Botcake answers with `BotcakeOutbox::outcome` and the same retry rule (429 only) | to rebuild |
| Pointer moves only on a known result | the resume job advances | the job advances, but a kill mid-batch leaves partial batches |
| Send-time checks | checked by the pool just before `called()`, the same place it checks bot paused today | checked per call inside the job |
| Inline fallback | the same `enabled()` gate that replies use, plus a flag | its own gate |
| First replies not slowed | own class and capacity in the pool; resumes on `chatbot`, never `chatbot-high` | a long job on `chatbot` holds a worker per batch |
| Per-page rate | the pool's lanes, shared with blocking senders through `slot()` | must share the same slots |
| Rollback flag | one config key | one config key |

(b) still holds one worker per batch while it waits and needs its own crash recovery; (a) reuses the parts that
already run the replies at 50–100 chats a second.

## Design

### Hand-off (the step job)

- New config `services.chatbot.sequence_handoff` (env `CHATBOT_SEQUENCE_HANDOFF`, default `true`).
- `BotcakeSendQueue::sequenceAsync(FlowRun $run, Outbox $outbox)`: live run, `sequenceTicket` set (below),
  `BotcakeOutbox` and replies On, the flag On, `enabled()` (async sends On, pool alive, table there) and the new
  column there. Same answer for Botcake and gateway pages. Anything false: the send runs inline as today.
- `FlowRun::$sequenceTicket` (`subscription_id`, `claim`, `step`, `due`, `name`, `sequence_id`): set by `runStep()`
  and by the resume, so the engine knows the run belongs to a claimed step.
- `FlowEngine::send` and the AI node's "send the answer": after the `async()` branch, the same branch for
  `sequenceAsync()` → `BotcakeSendQueue::submitSequence()` → `sendPending`, suspend. Tags are computed before the
  hand-off exactly as now, so the row gets the same sequence id, step and tags. `retrySend` stays off for
  sequences (it checks `async()`), so no new resend.
- `submitSequence()`: `kind` sequence, priority 3, `customer` = the reply customer key (`chatbot:<page>:<psid>`),
  `chat_id` null, flow and node, payload `{messages, tags}` (same JSON as replies), context = the reply context plus
  the `sequence` block and `side_effects` (true when the run already passed an AI, Sheet, Encode or Upload node).
  Deadline 3 h (a sequence ticket waits in a long queue without failing; the 24 h check at send time stops a stale
  one). It does not set the customer's `__ai` mark: a customer's reply must not wait for a sequence resume that sits
  behind the `chatbot` queue.
- `runStep()` after the engine: when `sendPending`, it stores the run's events, sets
  `chatbot_sequence_subscriptions.send_request_id` = the ticket (conditional on the claim), and returns `sending`.
  No `ran` event, no advance. The customer lock is released at once.
- A customer with another sequence ticket open (pending, sending, done, failed) is treated like a held lock: retry
  in 1 minute (`LOCKED_RETRY_MINUTES`), so one customer has at most one sequence send at a time.

### Schema

One nullable column on an existing table, no new table: `chatbot_sequence_subscriptions.send_request_id`
(string 36, the ticket in flight). Migration guarded with `Schema::hasColumn`; until it runs, `sequenceAsync()` is
false and everything stays inline. Rejected alternatives: a join on the ticket table in the tick (concat of page and
PSID differs per database, and every in-flight row older than 2 minutes would be read again on every tick), a
`sending` subscription status (every reader of the status would change: unsubscribe, exit on reply, transfers,
screens), and `next_send_at = null` while in flight (the screens and the transfer copy read it).

### The pool

- Three classes instead of two: replies (`chatbot`), sequence steps (`sequence`), chatblast. Each has its own
  `send_concurrency` and `perPage()` held per lane, so 2,000 sequence tickets never take a reply's place or a
  chatblast's place. `tick()` claims replies and chatblast with the existing call, which now leaves `sequence` out,
  then sequence tickets with a second call of the same `claim()` filtered to `sequence` (its own `n`, full pages).
- Per-customer order: a sequence ticket is claimed only when the customer has no earlier open ticket and no ticket
  `sending`. A reply ticket ignores the customer's earlier sequence tickets that are still `pending`, but waits for one
  that is `sending`: a reply waits behind at most one sequence send.
- Lane order inside a page: reply first (as now), then sequence (rank 0), then chatblast; a chatblast waiting more
  than 10 s outranks a sequence (rank -1, as now). Sequence sends count as "bot busy", so chatblast keeps its
  `chatblast_share` of the page's rate and is never starved, and sequences are never starved by chatblast.
- The provider is chosen when the ticket is taken (`ChatbotProvider::name`): Botcake → the token lane, the same
  `send_content` request, timeout, retry rule (`onlyRateLimit`, 429 only, page pause) and slot booking as a reply;
  gateway → a lane per page with no Botcake slot and no client-side retry, the same POST as `GatewayProvider`
  (`/v1/pages/<id>/messages`, Bearer key from `ForbeastPageSetup` or the gateway key, `messaging_type` UPDATE,
  `client_ref` made at the call, gateway timeout), then `GatewayProvider` outcome and `ForbeastWatch::afterSend()`
  in the pool. Not set up: failed with `GatewayProvider::notSetUp()`, no call. Botcake page with no token: failed
  `NO_TOKEN`, no call (as inline).
- Just before `called()` (first attempt), the send-time check for a sequence ticket, in one place
  (`ChatbotSequences::sendCheck`): the subscription is still active, still holds this ticket and is not held for a
  transfer; the sequence is active; the page is On and set up; the provider is still the one chosen (else back to
  pending, taken again on the new provider); replies On; bot not paused; 24 h window open (the same "last message"
  rule as `runStep`). A failed check means no call and a `skipped` result with the reason.
- `finishSequence()` (mirror of `finishChatbot`): conditional on the holder, writes the `chatbot_replies` row with
  `BotcakeOutbox::record` exactly as the inline path (same error text, http, raw, tags, `via` of the provider used,
  `client_ref`), for a real attempt only (sent or failed), and dispatches `ResumeChatbotSequenceSend`.
- `called()` also stores the provider and `client_ref` on the ticket, so `recover()` can record a killed call as
  "Botcake did not answer (HTTP 0)" or "Gateway did not answer (HTTP 0)" with the right via and ref (the gateway's
  send status can still confirm it). `recover()` and `sweep()` treat `sequence` like `chatbot`: not called → pending;
  called → failed, resume; past deadline → failed "The send pool did not send it in time", resume; done or failed
  not resumed → resume again.

### Resume (the result of a sequence send)

- New job `ResumeChatbotSequenceSend` on the `chatbot` queue (never `chatbot-high`), `ShouldBeUniqueUntilProcessing`
  by request id, so the sweep's re-dispatch never piles copies behind a long queue; released after 1 s when the
  customer lock is held (like `ResumeChatbotSend`).
- `ChatbotSequences::resumeSend(ticket)`: takes the customer lock, marks the ticket `resumed` (conditional, so a
  second resume does nothing), then:
  - subscription no longer holds this ticket (exit on reply, unsubscribed, transfer released): the result is kept
    (row already written), a `sequence ran` event if something was sent, nothing more is sent, no advance;
  - a `skipped` result with nothing sent yet in this step and no side effects: the subscription goes back exactly as
    `runStep` would have left it at the start (page off → paused; bot paused → held 60 min; sequence paused →
    released; outside 24 h → unsubscribed; held for a transfer → not advanced, so the new site sends it once), with
    the same events, and `send_request_id` cleared;
  - a `skipped` result after an earlier send or a side effect: `skipped` event with the reason, advance (never
    resend what went out);
  - otherwise the run is rebuilt from the step's own flow (`BotConfigCache::flow`), with `startedBy`, `sequenceId`,
    `sequenceStep`, `sequenceTicket`, ad group, run id, step count and AI count from the ticket, and continues from
    the send node with the result (`sendResult`), so earlier nodes (AI, Sheet) never run again. The flow deleted,
    unpublished or changed at that node: `failed` event "the flow changed", advance.
  - When the run hands off its next send: `send_request_id` moves to the new ticket, no advance. When it ends: the
    `sequence ran` event (name, step, sequence_id, due), stamped at the time the last result was recorded so
    `/chatbot/speed` still finds its rows within 600 s however late the resume job runs, then `advance()`.
- `advance()` and `complete()` gain a guard: the update also requires `send_request_id` = this ticket (resume) or the
  claim (`runStep`), and clear `send_request_id`. A duplicate or late resume updates 0 rows and does nothing.

### Tick and stale claims

- `tick()` and `claim()` skip rows with `send_request_id` set: a step in flight is never claimed or dispatched again,
  however long the queue.
- A row with `dispatched_at` older than 2 minutes (`STALE_MINUTES`, replaces `REDISPATCH_MINUTES` 10), no job queued
  (unique lock free, as now) and no send in flight is claimed again: a dead job is re-picked in about 2 minutes. A
  queued job's row is still never re-stamped.
- Lost in flight: rows with `send_request_id` set and `dispatched_at` older than 2 minutes whose ticket is `resumed`
  or gone (the resume died mid-run) and whose customer lock is free → `failed` event "stopped after a send; not sent
  again" and advance. An open ticket (pending, sending, done, failed) is left to the pool, the sweep and the resume.
- The re-claimed step checks first whether the earlier claim of the same step already sent something (a reply row
  of this sequence and step since the earlier claim): then it advances with `failed` "stopped after a send; not sent
  again" instead of running again.
- `runStep()` returns: every early return that leaves the row claimed either releases it or leaves it to a newer
  claim that exists. On reading, the claim-mismatch `gone` (line 276) can leave a row stamped by a newer claim whose
  job never runs (a dispatch that never reached the queue, a job killed before it started); with the 2-minute stale
  rule nothing stays stuck longer than that. A test per early return pins it. If the reviewer had another return in
  mind, I add it to that test.

### Visibility

- `php artisan chatbot:sequence-lateness {--date=} {--page=}` (read-only): per hour, steps sent, due but not sent,
  oldest overdue, average lateness (due → sent at Meta, or due → pushed for Botcake, the speed screen's rule), and
  sequence tickets pending in the pool. Empty hour: blank, no error.
- `/chatbot/speed`: no UI change (S-215f.1 already shown). Lateness keeps using the reply row's `sent_at`, which the
  pool writes when the answer comes back, not at hand-off (S-215f.3).

### Other files

- `PageTransfers::release()`: also ends `sequence` tickets of a page that moves away.
- `GatewayProvider::outcome()` becomes a public static (no behaviour change) so the pool reads gateway answers the
  same way.
- `.env.example`: `CHATBOT_SEQUENCE_HANDOFF=true`.
- Not touched: `ForbeastWatch.php` (only called), chatblast logic (its claim and steps unchanged; it only gets a
  third class next to it), Horizon config and env values.

## Risks

1. **Customer order and reply speed in the pool** (highest). The claim rule changes for the first time since spec
   089. Covered by S-215b.4, S-215e.7 and the existing BotcakeAsyncSendTest, ChatbotPriorityTest.
2. **Double send through a recovery path** (recover, sweep, lost-in-flight, re-claimed step). Each path has a test
   that counts calls and rows (S-215c.2–6, S-215d.1–3).
3. **The `chatbot` queue backlog delays resumes**: a multi-send step takes one queue wait per send. The step still
   finishes; lateness of the second message grows with that queue. Mitigation: step jobs are now fast, and the
   resume job is unique. Owner check after release.
4. **Send-time skip re-runs the step from Start** only when nothing was sent and no AI/Sheet/Encode/Upload node ran;
   a Set node before the first send runs again (idempotent values).
5. **AI nodes in a sequence step still run inline** (`ChatbotAiQueue::async` needs `startedBy === null`); a step with
   an AI node still holds a worker for the AI call. Out of this spec (send API only); listed as an open risk.
6. **Inline fallback keeps today's gap**: a job killed during an inline call can still send twice, as today, now
   re-picked after 2 minutes instead of 10. The re-claim check catches it when the row was written.
7. **Gateway pages now send from the pool** at up to `perPage()` in flight per page with no client-side rate (the
   gateway queues and rates per page, as today). If the gateway pushes back, the per-page cap is the knob.
8. **Migration by hand**: until the column is added the flag has no effect (inline).

## Tasks

| # | Task | Side | Risk | Cases | Parallel-safe |
|---|---|---|---|---|---|
| 1 | Characterization tests for today's inline path (rows, texts, bytes, events, Speed fields) used as the "same as today" reference | backend | high | S-215.3–.6, .9, .11 (reference) | no |
| 2 | Column, flag, `sequenceTicket`, `sequenceAsync` / `submitSequence`, hand-off in `FlowEngine::send` and the AI send, `runStep` hand-off, one sequence send per customer | backend | high | S-215.2, .7, .8, .10, S-215b.8, S-215c.5 | no |
| 3 | Pool: sequence class, claim and lane order, providers (Botcake, gateway), send-time check, `finishSequence`, `called` provider/ref, recover, sweep, `PageTransfers::release` | backend | high | S-215.4, .5, .9, S-215b.4, .6, .7, S-215c.1–3, S-215e.1–6, .8, .9 | no |
| 4 | `ResumeChatbotSequenceSend` and `resumeSend`, `advance` guard, skip outcomes | backend | high | S-215.3, .6, .11, S-215b.1, .2, .5, .6, S-215c.4, .6 | no |
| 5 | Tick: in-flight exclusion, 2-minute stale rule, lost in flight, re-claim check, `runStep` returns | backend | high | S-215b.2, .3, S-215d.1–4 | no |
| 6 | Bench and control (fake handler and clock, simulated workers), first-reply comparison | test | high | S-215.1, the Done-when bench, S-215e.7 | after 2–5 |
| 7 | `chatbot:sequence-lateness` and the Speed pin test | backend | low | S-215f.1–4 | yes (own files) |
| 8 | Skeptic review (adversarial), fix loop, full suite, result | — | — | all | — |

One developer; tasks 2–5 share `ChatbotSequences`, `BotcakeSendQueue` and `BotcakeSendPool`, so they run in order.

## Tests

- New file `tests/Feature/Chatblast/SequenceHandoffTest.php` (cases S-215 to S-215e) on the BotcakeAsyncSendTest
  harness: the pool with a fake Guzzle handler that returns promises, the `BotcakeClock` fake, `Queue::fake()` for
  step and resume jobs run by hand, `Http::fake` for the inline reference. Gateway cases use a gateway configured in
  config with a fake handler; ForbeastWatch is observed through its effects (no edit).
- Bench (Done when): 600 due steps on 40 pages, 4 simulated workers each taking the measured job time, answers
  after 2.9 s of virtual time, pool ticks every 5 ms of virtual time: all 600 sent within 60 virtual seconds, peak
  in flight ≥ 40, gaps per Botcake page ≥ `1 / rate_per_page`. Control: the same fixture with the flag off, each
  worker busy 2.9 s per step: slower than 60 s. S-215.1 is the same harness at 300 steps, 20 pages.
- S-215e.7: 2,000 sequence tickets in flight, one first reply arrives: its send goes out within the same time as
  on the same fixture with the flag off (old code), measured in virtual time.
- `tests/Feature/Chatblast/SequenceLatenessCommandTest.php` for S-215f.2–4; S-215f.1 pins the existing columns.
- S-070.4a updated for the 2-minute rule (story change); every other listed suite unchanged and green.
- Full `php artisan test` before and after; no new failures.

## Story changes (proposed, for the reviewer)

- **S-215f.1**: the hourly avg, p90 and max of scheduled → pushed already exist on `/chatbot/speed`; the case becomes
  "still shown, and filled from sends that went through the pool".
- **S-215.5**: "exactly one row per attempt" reads as one row per send: a 429 is waited out inside the send (as
  inline today) and writes no row of its own; 5xx and HTTP 0 are one failed row, no resend.
- **S-215.11**: the reply row fields are identical; the Inbox shows the step's run in two run lines (the hand-off and
  "resumed after send"), as replies sent through the pool already do.
- **S-215b.6**: the outcome of each failed send-time check is spelled out: nothing sent yet → as at the start of the
  step today (paused, held 60 min, released, unsubscribed); something already sent → skipped event, advance.
- **S-215b.8**: plus: a customer with another sequence send still in flight is also retried in 1 minute.
- **S-215c.2**: the text is "Botcake did not answer (HTTP 0)" or "Gateway did not answer (HTTP 0)" by provider.
- **S-215e.4**: the provider is fixed when the pool takes the ticket; a switch before the call puts it back to
  pending and it goes once on the new provider; after the call nothing changes.
- **S-215e.5**: the 429 back-off is the Botcake lane's; gateway sends keep no client-side retry (as today).
- **S-070.4a** (existing): "older than 10 minutes" becomes "older than about 2 minutes with no job queued and no send
  in flight", per the spec's decision.

## Base test counts (commit 96080bfe)

BASE_COUNTS_PLACEHOLDER
