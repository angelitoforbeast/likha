# Result: spec 215, sequence sends don't hold a worker while waiting on the send API

**Status:** done (built and tested locally; not pushed, not deployed)
**Date:** 2026-11-02
**Branch / PR:** `feat/215-sequence-sends-no-wait`, cut from 96080bfe. Not pushed, no PR.
**Plan:** `docs/plans/215-sequence-sends-no-wait.md` (revision 2, accepted).

## Summary

- **One behaviour for every provider.** A sequence step no longer waits on the send API inside a Horizon worker. Each send
  of the step becomes a `sequence` ticket in `botcake_send_requests` and the job ends (measured under 0.5 s). A new,
  separate process, `chatbot:sequence-pool`, sends Botcake and gateway tickets the same way. A new job on the `chatbot`
  queue (never `chatbot-high`), `ResumeChatbotSequenceSend`, continues the step from the send node with the result, and
  advances the pointer only then.
- **In flight is a row value.** `chatbot_sequence_subscriptions.send_request_id` (one nullable column, indexed) links a step
  to its ticket. The tick never claims a linked row. Every writer of a subscription clears it.
- **Never twice.** The call is recorded before it goes out. A killed pool, a lost resume, a dead pool, an old process, a
  re-claimed job or a page move each end with no resend; when unsure the step is recorded failed and advances.
- **First replies untouched.** The reply pool's claim rule, lanes and order are unchanged; it only leaves `sequence`
  tickets out. The two pools share each page's rate through `BotcakeClient::slot`.
- **Fallback.** Flag off, migration not run, the sequence pool not alive, or a reply pool from before this change: the
  step sends inline exactly as before.
- **Stale claims.** A dead job is picked up again after about 2 minutes (10 with the inline path), never while it is queued
  or running.

## Changes per file

| File | Change |
|---|---|
| `app/Services/Chatbot/SequenceSendPool.php` (new) | The sequence pool: own beat with `sequence:1` (and `stopped` on a clean exit), claims `priority = 3` only, every 200 ms, up to `sequence_concurrency`. Botcake lane per token (same `send_content`, timeout, 429-only retry with a page pause, shared slot); gateway lane per page (no client rate, no retry, `sequence_gateway_per_page` held). Before the call: provider unchanged, no reply ticket of the customer open, `sendCheck`. `ForbeastWatch::afterSend` via `GatewayAfterSend` after the result is recorded. |
| `app/Services/Chatbot/BotcakeSendQueue.php` | `sequenceReady`, `sequenceAsync`, `sideEffects` (allowlist), `submitSequence` (ticket and link in one transaction), `replyOpen`, `calledSequence` (stores via and `client_ref`), `finishSequence`, `noAnswer`, `recoverSequence`, `sweepSequence`, `hasTable` memo. The reply pool's `claim` (`priority < 3`), `recover`, the pending-deadline loop and prune leave `sequence` tickets out. |
| `app/Services/Chatbot/ChatbotSequences.php` | `hasInFlight` (memoized column guard), `unlink`, `staleMinutes`; `subscribe`, `unsubscribe`, `resumePaused`, `advance`, `complete` clear the link; `claim`/`tick` skip linked, queued or running rows; `runStep` re-stamps the claim at start, sets a running marker, one sequence send per customer, the re-claim check, hands off and returns `sending`; `sendCheck`; `resumeSend`/`continueStep` (holder check, skip outcomes, `ran` stamp from the first send); `settleInFlight` (sweep, dead pool, backstop); `settleForMove`; `earlier()`. |
| `app/Services/Chatbot/FlowEngine.php` | The send node and the AI node's "send the answer" hand off through `sequenceAsync` → `submitSequence`; a link that fails stops the run with nothing sent. |
| `app/Services/Chatbot/FlowRun.php` | `$sequenceTicket`. |
| `app/Services/Chatbot/GatewayProvider.php` | Split into `request()` and a static `outcome()`, used by `send()` and the pool (no behaviour change). |
| `app/Services/Chatbot/TicketPool.php` | `beatInfo()` hook; a `stopped` beat does not block the next pool's start. |
| `app/Services/Chatbot/BotcakeSendPool.php` | Beat carries `excludes_sequence:1`; `leavesSequences()`. |
| `app/Services/Chatbot/PageTransfers.php` | `hold` and `release` call `settleForMove` first; every subscription writer clears the link. |
| `app/Jobs/RunChatbotSequenceStep.php` | Timeout 100 s when the hand-off is ready (280 s inline); carries the earlier claim; passes its timeout to the running marker. |
| `app/Jobs/ResumeChatbotSequenceSend.php` (new) | Resume on `chatbot`, unique per ticket (`uniqueFor` 900 s), released after 1 s while the customer is locked. |
| `app/Jobs/GatewayAfterSend.php` (new) | Calls `ForbeastWatch::afterSend` with the outcome and the raw body, once per real gateway attempt. |
| `app/Console/Commands/RunSequenceSendPool.php` (new) | `chatbot:sequence-pool --max-time=3600`. |
| `app/Console/Commands/ChatbotSequenceLateness.php` (new) | `chatbot:sequence-lateness {--date=} {--page=}`, read-only. |
| `database/migrations/2026_11_15_100000_add_send_request_to_chatbot_sequence_subscriptions.php` (new) | `send_request_id` string(36) nullable, indexed; guarded. |
| `config/services.php`, `.env.example` | The three new keys below. |
| `tests/Feature/Chatblast/Sequence*Test*.php` (new) | Harness, hand-off, safety, visibility, bench. |
| `docs/specs/215-…md`, `docs/plans/215-…md`, `qa/stories.md` | Spec, plan, stories with test names and status. |

## Config keys

| Key | Env | Default |
|---|---|---|
| `services.chatbot.sequence_handoff` | `CHATBOT_SEQUENCE_HANDOFF` | `true` (the rollback switch) |
| `services.botcake.sequence_concurrency` | `BOTCAKE_SEQUENCE_CONCURRENCY` | `100` |
| `services.chatbot.sequence_gateway_per_page` | `CHATBOT_SEQUENCE_GATEWAY_PER_PAGE` | `30` |

## Case table

All 50 S-215 cases pass; the test of each is in `qa/stories.md` (section S-215).

| Case | Test | Result |
|---|---|---|
| S-215.1 | `SequenceBenchTest::test_S_215_1…` | pass |
| S-215.2, .3 | `SequenceHandoffTest::test_S_215_2_and_3…` | pass |
| S-215.4 … .9, .11 | `SequenceHandoffTest::test_S_215_4…` … `test_S_215_11…` | pass |
| S-215.10, c.5 | `SequenceHandoffTest::test_S_215_10_and_c_5…` | pass |
| S-215b.1 … b.11 | `SequenceSafetyTest::test_S_215b_*` (b.6 with 4 data sets; b.7 and b.11 together) | pass |
| S-215c.1 … c.8 | `SequenceSafetyTest::test_S_215c_*` (c.1 also the clean-restart window) | pass |
| S-215d.1 … d.6 | `SequenceSafetyTest::test_S_215d_*` | pass |
| S-215e.1 … e.3 | `SequenceHandoffTest::test_S_215_e_*` | pass |
| S-215e.4 … e.6, e.8, e.9 | `SequenceSafetyTest::test_S_215e_*` | pass |
| S-215e.7 | `SequenceBenchTest::test_S_215e_7…` | pass (see Story changes) |
| S-215f.1 … f.5 | `SequenceVisibilityTest::test_S_215f_*` | pass |

## Bench (Done when)

Fake handler and clocks, 4 simulated workers at each job's measured time, the API answering after 2.9 s, the pool every
10 ms of virtual time. Every step done, one call and one sent row per step, gaps per Botcake page ≥ `1 / rate_per_page`.

| Fixture | Rate per page | Finished (virtual) | Peak in flight | Control: old inline path, done in 60 s |
|---|---|---|---|---|
| 600 steps, 40 pages, even | 2/s | 27.8 s | 100 | 84 of 600 |
| 600 steps, 40 pages, even | 10/s | 23.3 s | 100 | 84 of 600 |
| 285 steps, 60 pages, top page 6.5 % (19), long tail | 2/s | 12.6 s | 100 | 84 of 285 |
| 285 steps, 60 pages, top page 6.5 % (19), long tail | 10/s | 10.2 s | 100 | 84 of 285 |
| 300 steps, 20 pages (S-215.1) | 2/s | ≤ 60 s | ≥ 40 | — |

The control (flag off) is the old path: each worker busy 2.9 s per send plus its page slot wait; it is far over the bound.
Peak 100 is the `sequence_concurrency` cap. First reply with 2,000 steps in flight (S-215e.7): 0.51 s, one page slot at
2/s; the old code on the same fixture 0.01 s (it hardly used the page's rate).

## Test counts

Full `vendor/bin/phpunit` (sqlite in memory):

| | Tests | Assertions | Failures | Skipped |
|---|---|---|---|---|
| Base 96080bfe | 3,976 | 43,465 | 4 | 4 |
| Head | 4,028 | 74,224 | 3 | 4 |

- **No new failure.** The head's 3 failures are all on the base list: `ExampleTest` (302), `ItemTableFitScriptTest`
  (57 vs 45), `AstraAddressRulesTest::test_S_26_18` (timing bound). The base's fourth, `BigBroadcastTest::test_S_167_1`
  (timing under full-suite load), passed on the head run and passes alone on both.
- **52 new tests:** SequenceHandoffTest 11, SequenceSafetyTest 28, SequenceVisibilityTest 3, SequenceBenchTest 10.
- **The 14 listed suites, all green on the head, same counts as base:** ChatbotSequenceEngineTest 22, ChatbotPriorityTest 8,
  BotcakeAsyncSendTest 23, FailedSendKeepsConversationTest 12, ChatbotSequencePagesTest 7, ChatbotAfterOrderSequenceTest 3,
  GatewaySitePagesTest 26, GatewayTypingTest 9, ForbeastAutoSwitchTest 35, ChatbotTagsTest 12, ChatbotViaTest 3,
  RealSendTimeTest 15, BotcakeFailedReasonsTest 11, QueueRedisHorizonTest 5 (1 skipped). ResponseSpeedTest (S-182.11,
  S-185.5) 31 green.

## Story changes

Accepted with the plan:
- **S-215f.1**: the hourly avg, p90 and max were already on `/chatbot/speed`; the case pins them, filled from pool sends.
- **S-215.5**: one row per send; a 429 is waited out inside the send with no row of its own.
- **S-215.11**: the row fields are identical; the Inbox shows the step's run in two run lines (hand-off, then "resumed after
  send"), as replies through the pool already do.
- **S-215b.6**: nothing sent yet and no side effects → as at the start of the step today (paused, held 60 min, released,
  unsubscribed); otherwise a skipped event and the step advances.
- **S-215b.8**: plus: a customer with another sequence send in flight is retried in 1 minute.
- **S-215c.2**: "Botcake did not answer (HTTP 0)" or "Gateway did not answer (HTTP 0)" by provider.
- **S-215e.4**: the provider is fixed when the pool takes the ticket; a switch before the call puts it back to pending and
  it goes once on the new provider.
- **S-215e.5**: the 429 back-off is the Botcake lane's; gateway sends keep no client-side retry.
- **S-215b.4**: a sequence send never starts while the customer has a reply ticket pending or sending; a reply never waits
  for a sequence send; the two may be in the air together for one call.

New in the build:
- **S-215e.7**: "not slower than the old code" cannot hold literally: the old path was so slow it hardly used a page's
  rate, so a reply on that fixture waited about 0. With the sequences now sending at the page's rate, a reply waits at most
  the one slot already taken on its page (0.5 s at 2/s, 0.1 s at 10/s), never behind the sequence queue. The case text is
  corrected in `qa/stories.md`.
- **S-070.4a** (existing): left as written. It runs without the new column (inline mode), where the 10-minute rule still
  holds; the 2-minute rule of the hand-off mode is S-215d.1. The plan's proposed edit of S-070.4a was not needed.
- **S-215c.6**: counts sends (one row per send, at most one call), as the review asked.

## Deploy steps (both sites; not done, for the release)

1. Migrate by hand: `php artisan migrate --force --path=database/migrations/2026_11_15_100000_add_send_request_to_chatbot_sequence_subscriptions.php`
   (adds one nullable column and its index; until it runs nothing changes).
2. Deploy the code, `php artisan config:cache`.
3. Restart `likha-botcake-send` (supervisor), so the reply pool's beat carries `excludes_sequence`.
4. Add and start the new supervisor program on each site (Likha and Incepxion), modelled on `likha-botcake-send`:
   - name `likha-sequence-send` (Incepxion: its own prefix, as its other programs);
   - command `php artisan chatbot:sequence-pool --max-time=3600`;
   - `numprocs=1`, `autorestart=true`, `stopwaitsecs=70` (the pool drains up to 65 s), same user and log layout.
5. Hand-off starts on its own once both beats carry their flags; Horizon restarts as usual (`horizon:terminate`).

Rollback: set `CHATBOT_SEQUENCE_HANDOFF=false`, `php artisan config:cache`, wait 15 minutes for in-flight tickets to settle
(the ticket deadline), then deploy older code if needed (older code does not know `send_request_id`).

### Memory

Measured locally (Windows, PHP 8.4, `chatbot:sequence-pool` idle against sqlite): working set 77 MB, peak 77 MB, private
62 MB. Expect about 80–90 MB on the servers, like `botcake:send-pool`.

With the hand-off on, a step job takes milliseconds instead of about 2.9 s, so the step work needs a small fraction of
today's workers. The Horizon chatbot cap (now 18 on Incepxion) can go back down after the owner checks below pass, for
example to 12, which frees several hundred MB, more than the new process takes. Keep `minProcesses` per queue as it is
(the reply queue `chatbot-high` keeps its own workers). This repo does not change Horizon or env values.

## MySQL EXPLAIN

Not run: there is no MySQL here (tests run on sqlite). To run on the server before release:

- the sequence pool claim: `EXPLAIN SELECT t.id, t.request_id, t.page_name, t.kind, t.priority FROM botcake_send_requests t WHERE t.status = 'pending' AND (t.next_at IS NULL OR t.next_at <= NOW()) AND t.deadline_at > NOW() AND t.priority = 3 AND NOT EXISTS (SELECT 1 FROM botcake_send_requests o WHERE o.customer = t.customer AND o.id < t.id AND o.status IN ('pending','sending')) ORDER BY t.priority, t.id LIMIT 500;`
  expected: range on `(status, priority, id)`, the subquery on `(customer, status, id)`;
- the tick: the existing due query plus `s.send_request_id IS NULL` (filter on rows already read through the existing order);
- the backstop: `WHERE send_request_id IS NOT NULL AND dispatched_at < ?` on the new `send_request_id` index.

## Owner checks (after release)

For 2 days on Incepxion (`php artisan chatbot:sequence-lateness --date=…` and `/chatbot/speed` Sequences):
average lateness under 60 s at 19:00 and 22:00, and max under 5 min. Also: no duplicate follow-ups reported, first-reply
speed on `/chatbot/speed` unchanged, and `chatbot:sequence-pool` memory.

## Review

An adversarial review of the diff found two majors, both fixed with tests where sqlite allows:
1. On MySQL the claim re-stamp in the same second as the claim changes no row (MySQL counts changed rows) and the step
   would never run: the re-stamp now only checks the claim when the second is the same. Not reproducible on sqlite.
2. The seconds between a clean hourly pool exit and the next pool looked like a dead pool and cancelled tickets: a clean
   exit now leaves a `stopped` beat (no new hand-off, not dead), the next pool starts at once, and the dead-pool path only
   touches tickets idle for 60 s (`test_S_215c_1_the_seconds…`).
Minors fixed: the `ran` stamp gets 1 s of slack; the running marker uses the job's own timeout; a failed `GatewayAfterSend`
dispatch no longer turns a delivered send into a failed row; the backstop dispatches resume jobs instead of running them in
the tick; the schema check for the ticket table is memoized; the resume sweep filters on the sequence priority; a step
abandoned by the backstop uses its own step (no skipped step).

## Open risks

1. **Steps with slow inline work before the first send** (for example two AI nodes, OpenAI timeout 60 s) can pass the
   100 s hand-off timeout; the job is killed, the row is picked up again after 2 minutes and the nodes before the send run
   again (the send itself is not duplicated). AI nodes in sequence steps still run inline (out of this spec).
2. **A multi-send step waits once per send on the `chatbot` queue** for its resume; under a long `chatbot` queue its later
   messages are late. Step jobs are now short, which keeps that queue short.
3. **A reply and a sequence send of one customer can be in the air together** for one call (the reply never waits).
4. **`GatewayAfterSend` runs through the `chatbot` queue**, so for sequence-only gateway traffic the auto-switch reacts
   after that queue's wait; replies still call `afterSend` inline.
5. **The inline fallback keeps today's gap**: a job killed during an inline call can still send twice; the re-claim check
   catches it when the reply row was written.
6. **MySQL-only behaviour** is covered by reading, not by tests (sqlite counts matched rows); the EXPLAIN is still to run.
7. **Mixed versions**: covered by the two beat flags; a rollback must turn the flag off first.
