# Plan 215: sequence sends don't hold a worker while waiting on the send API

Revision 2: after the adversarial review. A second pool process for sequence tickets; the reply pool only learns to
leave them alone. Each blocking review item is answered in "Review items" with its fix and test.

## What the code does today (checked on the base commit)

- `ChatbotSequences::tick()` claims due rows (`BATCH` 500, `SECONDS` 50) with a conditional update of `dispatched_at`
  (the pre-queue claim time, lines 182 and 230), skips a row whose `RunChatbotSequenceStep` is still queued (its unique
  lock is held), and re-claims a row whose `dispatched_at` is older than `REDISPATCH_MINUTES` (10).
- `RunChatbotSequenceStep` (queue `chatbot`, timeout 280, `ShouldBeUniqueUntilProcessing`, `uniqueFor` 3 h; the unique
  lock is released when the job starts, lines 15-25) calls `runStep()`, which takes the customer lock
  `chatbot-run:<page>:<psid>`, checks the claim, the sequence, the page (set up, On), bot paused and the 24 h window,
  then runs the step's flow from Start with `startedBy`, `sequenceId` and `sequenceStep` set, writes the
  `sequence ran` event (name, step, sequence_id, due), stores the run's events and calls `advance()`.
- Every send inside that run is inline: `FlowEngine::send` hands off only when `BotcakeSendQueue::async()` is true,
  and `async()` requires `startedBy === null` and a Botcake page. A sequence send is `BotcakeOutbox::send` →
  `BotcakeProvider` (`send_content`, 429 retried 2/4/8 s or Retry-After up to 10 s, 5xx and HTTP 0 not retried,
  booked on the page's shared rate slot) or `GatewayProvider` (one POST, no retry, no client-side rate,
  `messaging_type` UPDATE, `client_ref`, then `ForbeastWatch::afterSend`). The worker waits the whole time.
- `afterSend` skips the "wait for reply" of a sequence step (`startedBy !== null`); `retrySend` (one resend of a 5xx /
  HTTP 0) is only for runs where `async()` is true, so sequences never get it.
- The reply pool (`botcake:send-pool`, `BotcakeSendPool` on `TicketPool`): two classes, bot replies (priority 0) and
  chatblast (1, 2); `send_concurrency` (100) held per class; `perPage()` = `max(2, ceil(3 / interval))` held per lane
  (lines 140-143), so 6 at 2/s and 30 at 10/s. Lanes book through `BotcakeClient::slot()`, shared with every
  blocking sender. Per-customer order on `customer`. `claim()`, `recover()` and the pending-deadline loop of
  `sweep()` read every kind; `take()` treats any kind other than `chatbot` as chatblast (lines 179, 197-210).
- `ChatbotRunner::resume()` rebuilds the run from the page's current flow, with no `startedBy`, `sequenceId` or
  `sequenceStep`, and drops the result unless `__ai` holds the ticket mark: the sequence path needs its own resume.
- `/chatbot/speed` matches a `sequence ran` event to the step's reply rows sent in the 600 s before the event
  (ResponseSpeed.php:925-934), and already shows avg, p90 and max of scheduled → pushed per hour.
- Page moves: `hold()` turns active rows into `held` (line 456), `hold:false` back to active (462), `import` writes
  held rows (378), `activate` (516), `release()` ends the rest; the export (153-170) sends each row at its
  `current_step`, so a step that went out here but was not advanced yet would be sent again on the new site.
- `rate_per_page`: the code default is 2; the live value on both sites is reported as 10/s (not readable from here:
  `.env` is off limits). Everything below reads the config, never a constant.
- S-070.4a pins the 10-minute re-claim (`ChatbotSequenceEngineTest`): it changes with this spec.

## Choice

Option (a) as before (the send pool carries the step), with the reviewer's ruling: **a second pool process,
`chatbot:sequence-pool`, dedicated to `sequence` tickets**, with its own beat and capability flag. The reply pool's
claim rule, lanes and order are not changed; it only gets a filter that leaves `sequence` tickets out of `claim()`,
`recover()` and `sweep()` (today they read every kind). The per-page rate stays shared through `BotcakeClient::slot`.
A dead sequence pool cannot stop replies, and a dead reply pool cannot stop sequences.

One point found while checking it (not a reason against it): with today's per-customer rule
(BotcakeSendQueue.php:128-129), a `sequence` ticket with the reply customer key would make a reply wait in the reply
pool. So sequence tickets use their own key `sequence:<page>:<psid>`, and the "one at a time" between a reply and a
sequence send is enforced on the sequence side only (story change for S-215b.4 below).

## Design

### Hand-off (the step job)

- Config `services.chatbot.sequence_handoff` (env `CHATBOT_SEQUENCE_HANDOFF`, default `true`).
- `BotcakeSendQueue::sequenceAsync(FlowRun $run, Outbox $outbox)` is true only when all hold: live run with
  `sequenceTicket` set, `BotcakeOutbox` and replies On, the flag On, the column there (memoized `hasColumn`), the
  sequence pool alive with capability `sequence:1` in its beat, and the reply pool either not alive or alive with
  capability `excludes_sequence:1` in its beat (an old reply pool would take a `sequence` ticket as chatblast). Same
  answer for Botcake and gateway pages. False: the send runs inline as today.
- `FlowRun::$sequenceTicket` (`subscription_id`, `stamp`, `step`, `due`, `name`, `sequence_id`, `first_sent_at`).
- `FlowEngine::send` and the AI node's "send the answer": after the `async()` branch, the same branch for
  `sequenceAsync()` → `submitSequence()` → `sendPending`, suspend. Tags are computed before the hand-off exactly as
  now. `retrySend` stays off for sequences (it checks `async()`).
- `submitSequence()` in one transaction: insert the ticket (`kind` sequence, priority 3, customer
  `sequence:<page>:<psid>`, chat null, flow, node, payload `{messages, tags}`, context = the reply context plus the
  `sequence` block and `side_effects`, deadline 15 min) and the conditional update of the subscription
  (`send_request_id` = ticket, `dispatched_at` = now, where id, status active, `current_step` = step,
  `dispatched_at` = the job's stamp and `send_request_id` is null or the previous ticket). Update 0 rows → rollback,
  no ticket, the step stops (`gone`). The pool never sees a ticket whose row is not linked.
- No `__ai` mark: a reply never waits for a sequence resume.
- `runStep()` after the engine: `sendPending` → store the run's events, return `sending`; no `ran`, no advance. The
  customer lock is released at once.
- A customer with another sequence ticket open → retry in 1 minute (one sequence send per customer at a time).

### Schema

One migration on the existing table, no new table: `chatbot_sequence_subscriptions.send_request_id` (string 36,
nullable) and an index on it. Every reader (tick, claim, advance, the holder checks, the sweeps) goes through one
memoized `ChatbotSequences::hasInFlight()` (`hasColumn`, cached per process once true). Column missing: the tick and
the step run exactly as today (no filter, no write), and `sequenceAsync()` is false.

### The sequence pool (`SequenceSendPool`, command `chatbot:sequence-pool --max-time=3600`)

- Its own `TicketPool` subclass and beat key `sequence-send-pool:beat`, beat payload with `sequence:1`. It claims
  only `priority = 3` rows (index `status, priority, id` already exists), at most every 200 ms, up to
  `services.botcake.sequence_concurrency` (new, default 100) held.
- Lanes:
  - **Botcake page**: lane per token, same `send_content` request, `BotcakeProvider::timeout()`, retry rule
    (`retryWait`, `onlyRateLimit`, 429 pauses that page), slot booked through `BotcakeClient::slot()` like the reply
    pool, so both pools and every blocking sender share one rate per page. Held per lane = `perPage()` from the live
    `rate_per_page` (30 at 10/s, 6 at 2/s): at 2.9 s latency that is just above the rate, so the rate, not the cap,
    is the limit.
  - **Gateway page**: lane per page, no inherited interval, no slot, no client-side retry (as `GatewayProvider`
    today, lines 12-13), held per page = new `services.chatbot.sequence_gateway_per_page` (default 30, the cap that
    stands in for today's worker count).
- The provider is chosen when the ticket is taken. Just before `called()` the send-time check
  (`ChatbotSequences::sendCheck`): holder (`send_request_id` = ticket, status active, `current_step` = step), sequence
  active, page On and set up, provider unchanged (else back to pending, taken again on the new provider), replies
  On, bot not paused, 24 h window open, and no reply ticket of the customer pending or sending in the reply pool
  (else wait 1 s, `later()`). A failed check: no call, a `skipped` result with the reason.
- The gateway request and answer come from `GatewayProvider` itself: it is split into `request()` (url, key, body,
  timeout) and a static `outcome()`, used by its own `send()` and by the pool (no copied POST).
  `ForbeastWatch::afterSend()` is called once per real attempt with the outcome and the raw body. Because its
  `switch()` path runs reruns synchronously (ForbeastWatch.php:392-433, 494-535, 763-810), the pool does not call it
  in its loop: it dispatches `GatewayAfterSend` (queue `chatbot`, carries page, outcome, body, gateway), which calls
  it. The build measures both: the time of the call in the loop, and the delay through the queue.
- `called()` stores provider and `client_ref` on the ticket. `finishSequence()`: conditional on the holder, writes
  the `chatbot_replies` row with `BotcakeOutbox::record` exactly as inline (error text, http, raw, tags, `via` of the
  provider used, `client_ref`), for a real attempt only, carries `first_sent_at`, and dispatches
  `ResumeChatbotSequenceSend`.
- Its `recover()` and `sweep()` read only `sequence` tickets: not called → pending; called and no answer → failed
  "Botcake did not answer (HTTP 0)" or "Gateway did not answer (HTTP 0)", resume; done or failed not resumed →
  resume again (sequence job, per kind); past the deadline → failed "The send pool did not send it in time", resume.
  Pruning of sequence tickets: same 2 days, in chunks of 5,000 per sweep (about 0.3M rows a day).

### The reply pool (minimal)

- `claim()`, `recover()`, the pending-deadline loop of `sweep()` and the prune of `BotcakeSendPool` leave
  `kind = sequence` out. Its beat carries `excludes_sequence:1`. Nothing else changes.

### Resume

- `ResumeChatbotSequenceSend` on `chatbot` (never `chatbot-high`), `ShouldBeUniqueUntilProcessing` by request id with
  `uniqueFor` 900 s, released after 1 s while the customer lock is held.
- `ChatbotSequences::resumeSend(ticket)`: customer lock, conditional `resumed`, then:
  - holder check fails (exit on reply, unsubscribe, re-subscribe, page move): the row is already written; a `ran`
    event if something was sent; nothing more, no advance;
  - `skipped` with nothing sent yet in the step and `side_effects` false: the row goes back exactly as `runStep` would
    leave it (page off → paused, bot paused → held 60 min, sequence paused → released, outside 24 h → unsubscribed,
    page held → not advanced), same events, `send_request_id` cleared;
  - `skipped` after a send or with side effects: `skipped` event, advance;
  - otherwise the run is rebuilt from the step's own flow (`BotConfigCache::flow`) with the sequence context, ad
    group, run id, step and AI counts, and continues from the send node with `sendResult`. Flow deleted, unpublished
    or changed at that node: `failed` "the flow changed", advance. Next send handed off: the same transaction as
    `submitSequence` moves `send_request_id` to the new ticket. Run ended: `ran` event, then `advance()`.
- `side_effects` is an allowlist: false only while every node passed so far is `start`, `condition`, `send`, `end`, or
  `set` with `set`/`clear` ops only. Anything else (append, clear all, AI, clear AI, Sheet, Encode, Upload, Encode
  macro, Subscribe, any node type added later) makes it true.
- `ran` stamp: `first_sent_at` travels in the context through every ticket of the step; the event's time is
  `max(first_sent_at, min(last_result_at, first_sent_at + 590 s))`, so every send of the step that fits in
  ResponseSpeed's 600 s window is matched, and the first one (the one that defines lateness) always is.
- `advance()` and `complete()`: the update also requires the holder (`send_request_id` = ticket, status active,
  `current_step`) or, from `runStep`, the job's stamp; and clears `send_request_id`. A late or second resume
  updates 0 rows.

### Tick, stale claims, backstops

- Re-stamp at start: `runStep()` first sets `dispatched_at` = now where `dispatched_at` = the job's claim (conditional;
  0 rows → `gone`), and sets a running marker (cache `chatbot-seq-running:<id>`, TTL = job timeout + 10 s, released in
  `finally`). The job uses that new stamp from then on.
- Job timeout: 100 s in hand-off mode (below the 2-minute stale window); the inline fallback keeps 280 s and the
  10-minute window. The job reads the mode when it is dispatched; the tick uses the window of the current mode, and
  the marker covers a flip in between.
- Stale rule: a row is claimed again when `dispatched_at` is older than the window, its unique lock is free (not
  queued), the running marker is absent and `send_request_id` is null. A dead job is re-picked in about 2 minutes; a
  queued job's row is never re-stamped; a running job is never re-claimed.
- Re-claimed step: if the earlier stamp of the same step wrote a reply row of this sequence and step, advance with
  `failed` "stopped after a send; not sent again" instead of running again.
- Backstop (in the tick, small set via the new index): rows with `send_request_id` set and `dispatched_at` older than
  the deadline + 5 min (20 min), whatever the ticket's status: ticket called (or done/failed) → resolved through the
  resume rules, no resend; not called → cancelled, row freed (re-run inline only when `side_effects` is false,
  otherwise `failed`, advance); ticket gone → `failed` "stopped after a send; not sent again", advance. Same for a
  `resumed` ticket whose resume died (lock free).
- Dead sequence pool: past `DEAD_POOL_SECONDS` without its beat, the scheduled tick cancels the `sequence` tickets that
  were not called and frees their rows (with `sequenceAsync()` false, the step goes inline on its next claim; side
  effects → `failed`, advance). Called ones are recovered as above when a pool starts, or by the backstop.
- `runStep()` returns: a test per early return pins that none leaves a row claimed with no job, no marker and no
  ticket for longer than the window. Candidate on reading: the claim-mismatch `gone` (line 276) when the newer
  claim's job never runs.

### Every writer of `send_request_id`

Cleared by: `subscribe` (new row and restart, lines 83-84, 91), `unsubscribe` (129-130), `resumePaused` (211-212),
`complete`, `advance`, PageTransfers `hold` (456), `hold:false` (462), `import` (378), `activate` (516) and `release`.
The holder check everywhere is `send_request_id` + status active + `current_step`.

### Page moves

`ChatbotSequences::settleForMove(pageId)`, called first inside the `hold()` and `release()` transactions:
1. cancel the page's `sequence` tickets not yet called (`called_at` null, conditional, so a call that wins the race
   counts as called), clear their rows' `send_request_id`: the step stays at N and goes once on the new site;
2. for rows whose ticket was called: advance them now (pointer to N+1, `send_request_id` cleared), so the export
   sends N+1; the result, when it comes, writes its row and its `ran` event and advances nothing.

### Visibility

- `php artisan chatbot:sequence-lateness {--date=} {--page=}` (read-only): per hour, steps sent, due but not sent,
  oldest overdue, average lateness (the speed screen's rule: due → sent at Meta, Botcake due → pushed), sequence
  tickets pending. Empty hour: blank, no error.
- `/chatbot/speed` unchanged.

### Other files

- `config/services.php`, `.env.example`: `CHATBOT_SEQUENCE_HANDOFF=true`, `BOTCAKE_SEQUENCE_CONCURRENCY=100`,
  `CHATBOT_SEQUENCE_GATEWAY_PER_PAGE=30`.
- Not touched: `ForbeastWatch.php` (only called), chatblast logic, Horizon config and env values.

### Deploy step (for the result, not a repo file)

Supervisor program `likha-sequence-send` (and the Incepxion twin): command
`php artisan chatbot:sequence-pool --max-time=3600`, `numprocs=1`, `autorestart=true`, `stopwaitsecs=70` (the pool
drains up to 65 s), same user and log layout as `likha-botcake-send`. Order: migrate, deploy, restart
`likha-botcake-send` (new beat), start `likha-sequence-send`. Hand-off starts only when both beats carry their flags.
Rollback: `CHATBOT_SEQUENCE_HANDOFF=false` + `config:cache` first, wait 15 min for in-flight tickets to settle, then
an older deploy (old code does not know `send_request_id`).

## Review items

| # | Item | Fix | Test |
|---|---|---|---|
| 1 | 2-minute rule re-claims a running job | re-stamp at start, running marker, timeout 100 s < window | S-215d.5: a job running 110 s of virtual time is not re-claimed; killed at 100 s → re-picked once after the window |
| 2 | Pool takes a ticket before the row is linked | ticket insert + conditional link in one transaction (also for the next ticket) | S-215b.9: a pool tick between insert and link sees nothing; link 0 rows → no ticket |
| 3 | Writers must clear `send_request_id` | the list above; holder = id + active + step | S-215b.10: re-subscribe while in flight → old result advances nothing, step 1 sent once; one assertion per writer |
| 4 | Page move resends step N | `settleForMove` in `hold` and `release` | S-215b.11: a transfer with a called ticket exports N+1; with an uncalled one exports N; one send in total |
| 5 | Old pool takes `sequence` tickets | both beat flags gate `sequenceAsync`; reply pool filters `sequence`; 20-minute backstop | S-215c.7: old reply beat → inline; a ticket left `done` without send by a foreign process is resolved, no resend if called |
| 6 | Migration not run | memoized `hasInFlight()` on every reader | S-215d.6: tick, claim, step and advance on a table without the column behave as base |
| 7 | Dead pool | tick cancels uncalled tickets past `DEAD_POOL_SECONDS`; deadline 15 min | S-215c.8: pool dead → uncalled steps go inline once; called ones are failed, not resent |
| 8 | Per-page throughput | live `rate_per_page`, `perPage()` from it, gateway lanes with their own cap | bench at 2/s and 10/s, even and skewed mix (below) |
| 9 | `ran` stamp for multi-send steps | `first_sent_at` in context, stamp rule above | S-215f.5: a 3-send step whose sends span 400 s counts as sent with the first send's lateness; span 700 s still matches the first |

## Risks

1. **Double send through a recovery path** (recover, sweep, backstop, dead pool, re-claimed step, page move). Each
   path has a test that counts calls and rows.
2. **The `chatbot` queue backlog delays resumes**: one queue wait per send of a multi-send step. Resume jobs are
   unique, step jobs are short. Owner check after release.
3. **Reply and sequence sends of one customer can be in the air together** for one call (the reply side does not
   wait, by the owner's rule). Order on Messenger is then the provider's.
4. **`GatewayAfterSend` delays the auto-switch** by the `chatbot` queue wait for sequence-only traffic; replies still
   call `afterSend` inline. Measured in the build.
5. **AI nodes inside a sequence step still run inline** (out of this spec, send API only).
6. **Inline fallback keeps today's gap** (a kill during an inline call may resend), now bounded by the re-claim
   check when the row was written.
7. **Mixed versions during deploy**: covered by the two beat flags; a rollback must turn the flag off first.
8. **Migration by hand**: until it runs, nothing changes.

## Tasks

| # | Task | Side | Risk | Cases | Parallel-safe |
|---|---|---|---|---|---|
| 1 | Characterization of today's inline path (rows, texts, bytes, events, Speed fields), the reference for "as today" | backend | high | S-215.3–.6, .9, .11 | no |
| 2 | Column + index, memoized guard, flag, `sequenceTicket`, `sequenceAsync`, transactional `submitSequence`, hand-off in send and AI send, `runStep` re-stamp, marker, timeout, one send per customer | backend | high | S-215.2, .7, .8, .10, S-215b.8, .9, S-215c.5, S-215d.5, .6 | no |
| 3 | `SequenceSendPool`, command, beats and flags, lanes, `GatewayProvider` split, `GatewayAfterSend`, send-time check, `finishSequence`, recover, sweep, prune; reply pool filter | backend | high | S-215.4, .5, .9, S-215b.4, .6, S-215c.1–3, .7, S-215e.1–6, .8, .9 | no |
| 4 | `ResumeChatbotSequenceSend`, `resumeSend`, `advance` guard, skip outcomes, allowlist, `ran` stamp, every writer clears the link | backend | high | S-215.3, .6, .11, S-215b.1, .2, .5, .10, S-215c.4, .6, S-215f.5 | no |
| 5 | Tick: link filter, stale rule, re-claim check, backstop, dead pool; `settleForMove` in `hold` / `release` | backend | high | S-215b.3, .7, .11, S-215c.8, S-215d.1–4 | no |
| 6 | Bench and controls, first-reply comparison | test | high | S-215.1, bench, S-215e.7 | after 2–5 |
| 7 | `chatbot:sequence-lateness`, Speed pin test | backend | low | S-215f.1–4 | yes |
| 8 | Skeptic review, fix loop, full suite, MySQL EXPLAIN of the new claim and tick queries, result | — | — | all | — |

## Tests

- `tests/Feature/Chatblast/SequenceHandoffTest.php` on the BotcakeAsyncSendTest harness (fake Guzzle handler with
  promises, `BotcakeClock` fake, `Queue::fake()` with jobs run by hand, `Http::fake` for the inline reference). Gateway
  cases use a configured gateway and the fake handler; ForbeastWatch is observed through its effects.
- Bench (Done when), 4 simulated workers at the measured job time, answers after 2.9 s of virtual time, pool ticks
  every 5 ms of virtual time:
  - even mix: 600 due steps on 40 pages, at `rate_per_page` 2 and 10: all sent within 60 virtual seconds, peak in
    flight ≥ 40, gaps per Botcake page ≥ `1 / rate_per_page`;
  - skewed mix: one minute of 17k an hour (about 285 steps), top page 6.5 %, then a long tail over 60 pages, at 2
    and 10: the same bounds, and the top page's own lateness reported;
  - control: the same fixtures with the flag off, each worker busy 2.9 s per step: slower than the bound.
- S-215e.7: 2,000 sequence tickets in flight; one first reply's send goes out no later than on the same fixture with
  the flag off.
- S-215c.6 counts sends: reply rows plus failures equal the sends due.
- `SequenceLatenessCommandTest` for S-215f.2–4; S-215f.1 pins the existing columns.
- S-070.4a updated; every other listed suite unchanged and green; full suite before and after, no new failures.

## Story changes

Accepted by the reviewer:
- **S-215f.1**: already shown on `/chatbot/speed`; the case pins it, filled from pool sends.
- **S-215.5**: one row per send; a 429 is waited out inside the send with no row of its own.
- **S-215.11**: the row fields are identical; the Inbox shows the step's run in two run lines (hand-off, then "resumed
  after send"), as replies through the pool already do.
- **S-215b.6**: send-time check outcomes: nothing sent yet and no side effects → as at the start of the step today;
  otherwise skipped event, advance.
- **S-215b.8**: plus: a customer with another sequence send in flight is retried in 1 minute.
- **S-215c.2**: "Botcake did not answer (HTTP 0)" or "Gateway did not answer (HTTP 0)" by provider.
- **S-215e.4**: provider fixed when the pool takes the ticket; a switch before the call → back to pending, once on
  the new provider.
- **S-215e.5**: the 429 back-off is the Botcake lane's; gateway sends keep no client-side retry.
- **S-070.4a**: "older than 10 minutes" → "older than about 2 minutes from the job's start, with no job queued, not
  running and no send in flight".

New in this revision (for the reviewer):
- **S-215b.4**: a sequence send never starts while the customer has a reply ticket pending or sending; a reply never
  waits for a sequence send; the two may be in the air together for one call.
- New cases: S-215b.9 (link in one transaction), S-215b.10 (re-subscribe while in flight), S-215b.11 (transfer with a
  called ticket), S-215c.7 (mixed versions), S-215c.8 (pool dead past the deadline), S-215d.5 (running job not
  re-claimed), S-215d.6 (migration not run), S-215f.5 (`ran` stamp for multi-send steps). Added to `qa/stories.md`.

## Base test counts (commit 96080bfe)

Full `vendor/bin/phpunit` (sqlite in memory, 21 min): 3,976 tests, 43,465 assertions, 4 failures, 4 skipped.

| Failure on base | Note |
|---|---|
| `BigBroadcastTest::test_S_167_1…` ("all 5,000 handed to the pool at once": 2,133) | timing under full-suite load; the class passes alone (10/10) on the same commit |
| `ExampleTest::test_the_application_returns_a_successful_response` (302) | known |
| `ItemTableFitScriptTest::test_every_catalog_column_id…` (57 vs 45) | known |
| `AstraAddressRulesTest::test_S_26_18…` (25.97 < 25.0) | timing bound under load |

The listed suites on base, all green: ChatbotSequenceEngineTest 22, ChatbotPriorityTest 8, BotcakeAsyncSendTest 23,
FailedSendKeepsConversationTest 12, ChatbotSequencePagesTest 7, ChatbotAfterOrderSequenceTest 3,
GatewaySitePagesTest 26, GatewayTypingTest 9, ForbeastAutoSwitchTest 35, ChatbotTagsTest 12, ChatbotViaTest 3,
RealSendTimeTest 15, BotcakeFailedReasonsTest 11, QueueRedisHorizonTest 5 (1 skipped).
