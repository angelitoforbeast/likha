# Result: spec 215, sequence sends don't hold a worker while waiting on the send API

**Status:** done (built and tested locally; review and MySQL QA fix list applied; not pushed, not deployed)
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
- **Never twice.** The call is recorded before it goes out, only while the subscription still holds the ticket. A killed
  pool, a lost resume, a dead pool, an old process, a re-claimed job, a page move, or a job killed during a step each end
  with no resend; when unsure the step is recorded failed ("stopped after a send; not sent again") and advances.
- **A step killed mid-run.** The step job keeps its 280 s timeout. A running job is never claimed again (its marker lives
  for its timeout + 10 s). Each run of a step leaves an attempt marker (subscription, step, due) that only a run that ends
  normally removes. A re-claim that finds it does not run the step again when the earlier run may have sent (an inline call
  was going out), when a node with effects (anything but Start, Condition, Send, End and Set set/clear) comes before the
  first send, or when it already ran again once; otherwise it runs once more.
- **Late, never lost.** While the sequence pool is alive, a ticket that has not been called never fails for being late: it
  waits, and the subscription and the 24 h window are checked at send time. A dead pool (no beat for 60 s) cancels its
  uncalled tickets and the tick sends those steps inline; a step whose effects already ran is recorded instead.
- **First replies untouched.** The reply pool's claim rule, lanes and order are unchanged; it only leaves `sequence`
  tickets out. The two pools share each page's rate through `BotcakeClient::slot`.
- **Fallback.** Flag off, migration not run, the sequence pool not alive, or a reply pool from before this change: the
  step sends inline exactly as before.
- **Stale claims.** A dead job that never started is picked up again after about 2 minutes (10 with the inline path); a job
  killed while running, after its marker runs out (its timeout + 10 s); never while it is queued or running.

## Changes per file

| File | Change |
|---|---|
| `app/Services/Chatbot/SequenceSendPool.php` (new) | The sequence pool: own beat with `sequence:1` (and `stopped` on a clean exit), claims `priority = 3` only, every 200 ms, up to `sequence_concurrency`. Botcake lane per token (same `send_content`, timeout, 429-only retry with a page pause, shared slot); gateway lane per page (no client rate, no retry, `sequence_gateway_per_page` held). Before the call: provider unchanged, no reply ticket of the customer open, `sendCheck`, then the conditional `calledSequence`; a holder that left in between ends the ticket as skipped. `ForbeastWatch::afterSend` via `GatewayAfterSend` after the result is recorded. |
| `app/Services/Chatbot/BotcakeSendQueue.php` | `sequenceReady`, `sequenceAsync`, `repeatable`/`sideEffects` (allowlist), `submitSequence` (ticket and link in one transaction, 3 tries; `moved` and `released` outcomes), `replyOpen`, `calledSequence` (holder must still exist; stores via and `client_ref`), `finishSequence`, `noAnswer`, `recoverSequence`, `sweepSequence` (no lateness failure), `hasTable` memo. The sequence claim has no deadline. The reply pool's `claim` (`priority < 3`), `recover`, the pending-deadline loop and prune leave `sequence` tickets out. |
| `app/Services/Chatbot/ChatbotSequences.php` | `hasInFlight` (memoized column guard), `unlink`, `staleMinutes`; writers clear the link; `claim`/`tick` skip linked (in PHP, not in the SQL), queued or running rows; `runStep` re-stamps the claim at start (same-second safe on MySQL), running marker, attempt marker (`attemptKey`, `markSending`, `preSendEffects`), one sequence send per customer, the re-claim checks, hands off and returns `sending`, `handOffFailed`; `sendCheck`; `resumeSend`/`continueStep` (holder check, skip outcomes, `ran` stamp from the first send, `eventAt`); `settleInFlight` (sweep, dead pool, backstop that never cancels a ticket waiting on a live pool); `settleForMove` (row locks, one pass). |
| `app/Services/Chatbot/FlowEngine.php` | The send node and the AI node's "send the answer" hand off through `sequenceAsync` → `submitSequence`; an inline sequence send is marked (`markSending`) before it goes out. |
| `app/Services/Chatbot/FlowRun.php` | `$sequenceTicket`. |
| `app/Services/Chatbot/GatewayProvider.php` | Split into `request()` and a static `outcome()`, used by `send()` and the pool (no behaviour change). |
| `app/Services/Chatbot/TicketPool.php` | `beatInfo()` hook; a `stopped` beat does not block the next pool's start. |
| `app/Services/Chatbot/BotcakeSendPool.php` | Beat carries `excludes_sequence:1`; `leavesSequences()`. |
| `app/Services/Chatbot/PageTransfers.php` | `hold` and `release` call `settleForMove` first; every subscription writer clears the link. |
| `app/Jobs/RunChatbotSequenceStep.php` | Timeout 280 s in every mode; carries the earlier claim; passes its timeout to the running marker. |
| `app/Jobs/ResumeChatbotSequenceSend.php` (new) | Resume on `chatbot`, unique per ticket (`uniqueFor` 900 s), released after 1 s while the customer is locked. |
| `app/Jobs/GatewayAfterSend.php` (new) | Calls `ForbeastWatch::afterSend` with the outcome and the raw body, once per real gateway attempt. |
| `app/Console/Commands/RunSequenceSendPool.php` (new) | `chatbot:sequence-pool --max-time=3600`. |
| `app/Console/Commands/ChatbotSequenceLateness.php` (new) | `chatbot:sequence-lateness {--date=} {--page=}`, read-only. |
| `database/migrations/2026_11_15_100000_add_send_request_to_chatbot_sequence_subscriptions.php` (new) | `send_request_id` string(36) nullable, indexed; guarded. |
| `config/services.php`, `.env.example` | The three new keys below. |
| `tests/Feature/Chatblast/Sequence*Test*.php` (new) | Harness, hand-off, safety, re-run, visibility, bench. |
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
| S-215.4 … .7, .9, .11 | `SequenceHandoffTest::test_S_215_4…` … `test_S_215_11…` | pass |
| S-215.8 | `SequenceHandoffTest::test_S_215_8…`, `SequenceReRunTest::test_S_215_8_and_1b…` (killed before the send) | pass |
| S-215.10, c.5 | `SequenceHandoffTest::test_S_215_10_and_c_5…` | pass |
| S-215b.1 … b.11 | `SequenceSafetyTest::test_S_215b_*` (b.6 with 4 data sets; b.7 and b.11 together) | pass |
| S-215c.1 … c.8 | `SequenceSafetyTest::test_S_215c_*` (c.1 also the clean-restart window) | pass |
| S-215d.1 … d.6 | `SequenceSafetyTest::test_S_215d_*` (d.5 through `runStep`) | pass |
| S-215e.1 … e.3 | `SequenceHandoffTest::test_S_215_e_*` | pass |
| S-215e.4 … e.6, e.8, e.9 | `SequenceSafetyTest::test_S_215e_*` | pass |
| S-215e.7 | `SequenceBenchTest::test_S_215e_7…` | pass (see Story changes) |
| S-215f.1 … f.5 | `SequenceVisibilityTest::test_S_215f_*` | pass |

## Fix list (independent review and the MySQL 8.4 QA run on 8a2c4244)

| # | Fix | Test |
|---|---|---|
| 1 | (a) The step job timeout is 280 s in every mode; the running marker (timeout + 10 s) keeps a running job from being claimed again. (b) An attempt marker per (subscription, step, due), written before the run and removed only when the run ends normally. On a re-claim that finds it: a possible send, an effect node before the first send, or a second death → recorded "stopped after a send; not sent again" and advanced; else run again once. | `SequenceReRunTest::test_S_215_8_and_1b…` (Sheet row before the send: no second row, no send, advanced), `test_1b_a_live_run_keeps_its_marker…`, `test_1b_a_step_with_only_repeatable_nodes…` (re-run once, then recorded), `SequenceSafetyTest::test_S_215d_5…` |
| 2 | The inline send of a sequence step marks the attempt (`sending`) before the call, so a job killed during the call is never sent again. | `SequenceReRunTest::test_2_a_job_killed_during_an_inline_call…` |
| 3 | No failing deadline for an uncalled sequence ticket while the pool is alive (the claim ignores the deadline, the sweep no longer fails late tickets, the backstop skips them). A dead pool cancels and unlinks them; the tick sends them inline, or records them when effects already ran. | `SequenceReRunTest::test_3_2500_steps_on_one_page…` (all 2,500 sent at 2/s, the last after more than 15 minutes, none failed), `test_3_a_dead_pool_cancels_waiting_tickets…` |
| 4 | `settleForMove` reads the linked rows and their tickets `FOR UPDATE` in one pass: an uncalled ticket is cancelled and the row stays at its step; a called one advances. | `SequenceSafetyTest::test_S_215b_7_and_11…` (the lock itself is not observable on sqlite) |
| 5 | `submitSequence` runs its transaction with 3 tries; a final database error gives the step back to the tick (no effects yet) or records it (effects ran), never a silent loss. | `SequenceReRunTest::test_5_a_ticket_that_cannot_be_written…` |
| 6 | `calledSequence` requires the subscription to still hold the ticket and be active; otherwise no call and the ticket ends as skipped. | `SequenceReRunTest::test_6_the_call_is_recorded_only_while…` |
| 7 | A page ID change before the send holds the step 60 minutes (like bot paused), no new ticket every minute. | `SequenceReRunTest::test_7_a_page_id_change…` |
| 8 | The `ran` event of a step that ended elsewhere is inserted with its time (no re-dating by `max(id)`). | `SequenceReRunTest::test_8_the_ran_event…` |
| 9 | See "Tick query plan" below. | `SequenceReRunTest::test_9_the_tick_query…` |
| 10 | See "Queries per send" below: the ownership read before the first call and the result read in `finishSequence` were removed. | bench and all pool tests |
| 11 | Rollback written as asked (Deploy steps). | — |
| 12 | S-215b.9 matches the insert in any quote style. | `SequenceSafetyTest::test_S_215b_9…` |
| 13 | S-215d.5 goes through `runStep` (marker TTL and re-stamp observed during a running step). | `SequenceSafetyTest::test_S_215d_5…` |
| 14 | Same-second re-stamp test that runs only on MySQL. | `SequenceReRunTest::test_14_mysql_only…` (skipped on sqlite) |
| 15 | S-215.8 killed-before-send path. | as 1 |

Red runs: the new tests fail on 8a2c4244's code (1b and 2: the attempt marker is missing; 5: the step advanced with
nothing sent; 7: a new ticket instead of a hold; 9: the tick SQL had `send_request_id is null`).

### Tick query plan (item 9)

Chosen: the due query no longer mentions the column. It reads `send_request_id` in its select list and skips linked rows
in PHP; the claim's conditional update (by primary key) still requires `send_request_id IS NULL`. The SQL `WHERE` is the
base commit's again, so MySQL keeps the base plan and can never pick the `send_request_id` index for the tick. A
composite `(status, next_send_at, send_request_id)` index was the alternative; it adds an index to a hot table, and the
optimizer can still choose another one, so it is not guaranteed without `FORCE INDEX` (not portable to sqlite). The single
`send_request_id` index stays for the backstop (`IS NOT NULL`, a small set). Cost: rows in flight for longer than the
stale window are read once per tick and skipped (the `$after` cursor moves past them). Not measured on MySQL here; the
QA's EXPLAIN ANALYZE should be re-run on this head.

### Queries per send (item 10)

Per Botcake send that succeeds, in the pool's single loop:
1. claim: 1 shared `SELECT` per claim round (every 200 ms, up to 500 candidates), then per ticket 1 conditional `UPDATE` and 1 `SELECT` of the row;
2. before the call: `replyOpen` (1 `SELECT EXISTS`), `sendCheck` (subscription by key, customer values, last chat: 3 `SELECT`; sequence and page settings come from the in-process and cache memos), `calledSequence` (1 conditional `UPDATE … WHERE EXISTS`);
3. after the answer: `finishSequence` (1 `UPDATE`), the reply row (1 `INSERT`, plus 1 `INSERT` of tag rows when the send has tags), and the resume job (Redis: unique lock plus push).
About 9–10 queries and one Redis dispatch per send; removed in this round: the ownership `SELECT` before the first call
(the conditional `calledSequence` is that check) and the `SELECT` of the ticket's result in `finishSequence`. At 0.5 ms a
query that is about 5 ms of database time per send in one process; the real ceiling must be measured on the server.

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
| Head 8a2c4244 (before the fix list) | 4,028 | 74,224 | 3 | 4 |
| Head after the fix list | 4,040 | 74,286 | 2 | 5 |

- **No new failure.** The head's 2 failures are on the base list: `ExampleTest` (302) and `ItemTableFitScriptTest`
  (57 vs 45). The base's timing failures (`BigBroadcastTest::test_S_167_1`, `AstraAddressRulesTest::test_S_26_18`, both
  load-dependent) passed on this run. The extra skip is `test_14_mysql_only…` (MySQL only).
- **64 new tests:** SequenceHandoffTest 11, SequenceSafetyTest 28, SequenceReRunTest 12 (1 skipped on sqlite),
  SequenceVisibilityTest 3, SequenceBenchTest 10.
- **The 14 listed suites, all green on the head, same counts as base:** ChatbotSequenceEngineTest 22, ChatbotPriorityTest 8,
  BotcakeAsyncSendTest 23, FailedSendKeepsConversationTest 12, ChatbotSequencePagesTest 7, ChatbotAfterOrderSequenceTest 3,
  GatewaySitePagesTest 26, GatewayTypingTest 9, ForbeastAutoSwitchTest 35, ChatbotTagsTest 12, ChatbotViaTest 3,
  RealSendTimeTest 15, BotcakeFailedReasonsTest 11, QueueRedisHorizonTest 5 (1 skipped). ResponseSpeedTest (S-182.11,
  S-185.5) 31 green; PageOwnersScreenTest 52 green.

## Story changes

Accepted:
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
- **S-215e.7**: a first reply may wait up to one page rate slot behind a sequence send (0.5 s at 2/s, 0.1 s at the live
  10/s), never behind the sequence queue. The case text is corrected in `qa/stories.md`.
- **Inline fallback**: the dead-job re-pick stays 10 minutes; the 2-minute rule applies with the hand-off (S-215d.1). S-070.4a
  is left as written (it runs in inline mode).
- **S-215c.6**: counts sends (one row per send, at most one call).
- **Late, not lost**: a sequence ticket waiting on a live pool is never failed for being late (fix 3); the old 15-minute
  failure is gone.

## Deploy steps (both sites; not done, for the release)

1. Migrate by hand: `php artisan migrate --force --path=database/migrations/2026_11_15_100000_add_send_request_to_chatbot_sequence_subscriptions.php`
   (adds one nullable column and its index; until it runs nothing changes).
2. Deploy the code, `php artisan config:cache`.
3. Restart `likha-botcake-send` (supervisor), so the reply pool's beat carries `excludes_sequence`.
4. Add and start the new supervisor program on each site (Likha and Incepxion), modelled on `likha-botcake-send`:
   - name `likha-sequence-send` (Incepxion: its own prefix, as its other programs);
   - command `php artisan chatbot:sequence-pool --max-time=3600`;
   - `numprocs=1`, `autorestart=true`, `stopwaitsecs=70` (the pool drains up to 65 s), same user and log layout.
5. `php artisan horizon:terminate` as usual. Hand-off starts on its own once both beats carry their flags.

Rollback (the flag is read by long-running Horizon workers and by the pools, so all of them must reload it):
1. set `CHATBOT_SEQUENCE_HANDOFF=false`;
2. `php artisan config:cache`;
3. `php artisan horizon:terminate` (supervisor restarts Horizon);
4. restart the sequence pool (`supervisorctl restart likha-sequence-send`, and Incepxion's twin);
5. wait 15 minutes for the tickets already in flight to settle;
6. only then deploy older code, if needed (older code does not know `send_request_id`).

### Memory

Measured locally (Windows, PHP 8.4, `chatbot:sequence-pool` idle against sqlite): working set 77 MB, peak 77 MB, private
62 MB. Expect about 80–90 MB on the servers, like `botcake:send-pool`.

With the hand-off on, a step job takes milliseconds instead of about 2.9 s, so the step work needs a small fraction of
today's workers. The Horizon chatbot cap (now 18 on Incepxion) can go back down after the owner checks below pass, for
example to 12, which frees several hundred MB, more than the new process takes. Keep `minProcesses` per queue as it is
(the reply queue `chatbot-high` keeps its own workers). This repo does not change Horizon or env values.

## MySQL notes

- Not run here: there is no MySQL in this environment (tests run on sqlite). The QA run on MySQL 8.4 found the items above.
- Pre-existing on MySQL, not this spec: `BotcakeAsyncSendTest` S-089.11 fails on MySQL on the base commit too (a `LIKE` on a
  JSON column).
- To re-run on the server before release (EXPLAIN ANALYZE): the sequence pool claim (`status = 'pending' AND priority = 3`,
  the per-customer `NOT EXISTS`), expected on `(status, priority, id)` and `(customer, status, id)`; the tick due query
  (now the base query); the backstop (`send_request_id IS NOT NULL AND dispatched_at < ?`) on the `send_request_id` index.
- `test_14_mysql_only…` covers the same-second re-stamp; it is skipped on sqlite and meant for a run against MySQL.

## Owner checks (after release)

For 2 days on Incepxion (`php artisan chatbot:sequence-lateness --date=…` and `/chatbot/speed` Sequences):
average lateness under 60 s at 19:00 and 22:00, and max under 5 min. Also: no duplicate follow-ups reported, first-reply
speed on `/chatbot/speed` unchanged, and `chatbot:sequence-pool` memory.

## Review

First adversarial review (before 8a2c4244): two majors fixed (the MySQL same-second re-stamp; a clean hourly pool restart
taken for a dead pool) and seven minors. Second round (independent review and MySQL QA on 8a2c4244): the fix list above.

## Open risks

1. **A multi-send step waits once per send on the `chatbot` queue** for its resume; under a long `chatbot` queue its later
   messages are late. Step jobs are now short, which keeps that queue short.
2. **A reply and a sequence send of one customer can be in the air together** for one call (the reply never waits).
3. **`GatewayAfterSend` runs through the `chatbot` queue**, so for sequence-only gateway traffic the auto-switch reacts
   after that queue's wait; replies still call `afterSend` inline.
4. **The attempt marker lives in the cache** (Redis on the servers, 2 days). If the cache is flushed while a step job is
   dead, the re-claim falls back to the earlier check (a reply row of the step since the earlier claim), which does not
   see an inline call that went out without a row.
5. **A killed job is picked up after its marker runs out** (about 290 s), not 2 minutes; only a job that never started is
   re-picked in about 2 minutes.
6. **The pool's database time per send** (about 9–10 queries) is the ceiling of one pool process; measure it on MySQL.
7. **MySQL-only behaviour** (row locks in `settleForMove`, the same-second re-stamp, the tick plan) is checked by reading
   and by the MySQL-only test, not by the sqlite suite.
8. **AI nodes in a sequence step still run inline** (out of this spec); with the 280 s timeout a step with several slow
   AI calls can still be killed, and is then recorded, not run again (fix 1).
