# Result: spec 215, sequence sends don't hold a worker while waiting on the send API

**Status:** done (built and tested locally; review and MySQL QA fix lists of rounds 2 and 3 applied; not pushed, not deployed)
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
- **A step killed mid-run.** The step job keeps its 280 s timeout. A running job is never claimed again: its row says so
  (the `attempt` column, started time) and, as a speed-up only, a cache marker for its timeout + 10 s. Each run of a step
  writes its attempt in the subscription row (step, due, count, "a call went out", start), and only a run that ends
  normally clears it; a cleared cache (a deploy's `optimize:clear`) changes nothing. A re-claim that finds it does not run the step again when the earlier run may have sent (an inline call
  was going out: marked right before the HTTP request, not while waiting for the page slot), when a node with effects
  (anything but Start, Condition, Send, End and Set set/clear) comes before the first send, or when it already ran again
  once; otherwise it runs once more. The reasons are recorded apart: "stopped after a send; not sent again" and "stopped
  before the send; not run again".
- **Late, never lost.** While the sequence pool is alive, a ticket that has not been called never fails for being late: it
  waits, and the subscription and the 24 h window are checked at send time. A call in the air is never failed while the
  pool that made it is alive (the backstop ages a call by the ticket's own `called_at`, and only for a pool that is gone).
  A dead pool (no beat for 60 s), or one that beats without progress for 5 minutes while tickets wait (logged as a
  warning), has its uncalled tickets cancelled, and the tick sends those steps inline; a step whose effects already ran
  is recorded instead.
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
| `app/Services/Chatbot/FlowEngine.php` | The send node and the AI node's "send the answer" hand off through `sequenceAsync` → `submitSequence`; an inline sequence send is marked through `SequenceAttempt::around`, fired by the client right before the HTTP request. |
| `app/Services/Chatbot/SequenceAttempt.php` (new) | The one-shot "before the request" hook for the inline send of a sequence step. |
| `app/Services/BotcakeClient.php`, `GatewayProvider::send` | Call `SequenceAttempt::beforeCall()` right before the HTTP request (no effect outside a sequence step). |
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
| `database/migrations/2026_11_15_100000_add_send_request_to_chatbot_sequence_subscriptions.php` (new) | `send_request_id` string(36) nullable, indexed, and `attempt` string(80) nullable; each guarded. |
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

### Queries per send (item 10; measured in round 3, item O: 11 statements in the pool and 10 in the resume job)

Per Botcake send that succeeds, in the pool's single loop:
1. claim: 1 shared `SELECT` per claim round (every 200 ms, up to 500 candidates), then per ticket 1 conditional `UPDATE` and 1 `SELECT` of the row;
2. before the call: `replyOpen` (1 `SELECT EXISTS`), `sendCheck` (subscription by key, customer values, last chat: 3 `SELECT`; sequence and page settings come from the in-process and cache memos), `calledSequence` (1 conditional `UPDATE … WHERE EXISTS`);
3. after the answer: `finishSequence` (1 `UPDATE`), the reply row (1 `INSERT`, plus 1 `INSERT` of tag rows when the send has tags), and the resume job (Redis: unique lock plus push).
About 9–10 queries and one Redis dispatch per send; removed in this round: the ownership `SELECT` before the first call
(the conditional `calledSequence` is that check) and the `SELECT` of the ticket's result in `finishSequence`. At 0.5 ms a
query that is about 5 ms of database time per send in one process; the real ceiling must be measured on the server.

## Round 3 fix list (second review and the MySQL 8.4 QA on 363ac71b)

| # | Fix | Test |
|---|---|---|
| A | The backstop never touches a ticket held by the live sequence pool. A call held by a pool that is gone (or stalled) is failed "no answer" only when its own `called_at` is older than `CALL_SECONDS` (300 s: the 30 s call and the 429 waits), never by the subscription's link time. Linked rows whose ticket is resumed or gone are the only ones the 20-minute backstop ends. | `SequenceReRunTest::test_3_2500…` now answers after 2.9 s and runs past 20 minutes: all 2,500 sent, none failed, no "did not answer" (red on 363ac71b: 2,494 sent, 6 live calls failed) |
| B | The per-attempt state is in the row: `chatbot_sequence_subscriptions.attempt` = "step\|due\|count\|call went out\|start". Written when the step starts (conditional), marked when an inline call goes out, cleared when the run ends normally; a run in progress (start within the job timeout + 10 s) is never claimed or run again, whatever the cache holds. The cache keeps only the short running marker as a speed-up. | `SequenceDurabilityTest::test_B_a_cache_cleared_mid_run…` (hand-off: the AI node runs once, one send) and `test_B_a_cache_cleared_during_an_inline_call…` (one send) (red on 363ac71b: the AI ran again, 131 sends) |
| C | The inline send is marked by the client, right before the HTTP request (`SequenceAttempt`, called in `BotcakeClient::call` and `GatewayProvider::send`), not before the slot wait and the checks. | `test_C_the_inline_send_is_marked_at_the_request…` (not marked during the slot wait, marked at the request) |
| D | A step killed before its send is recorded "stopped before the send; not run again"; after a send (or a possible send) "stopped after a send; not sent again". The dead-pool cancel of a step with effects uses the first reason. | `SequenceReRunTest` tests 1b and 3 |
| E | The dead-pool cancel re-reads the beat and leaves tickets held by the pool that owns the current fresh beat (a pool that just started). | `test_E_the_dead_pool_cancel_leaves_tickets…` |
| F | The beat carries `progress_at` (a claim, a finished send, or nothing waiting). A fresh beat with no progress for 5 minutes while tickets wait is a stalled pool: no new hand-off to it, its waiting tickets are cancelled and sent inline by the tick, and a warning line `sequence send pool: no progress for 5 minutes; …` is logged for the log checks. | `test_F_a_pool_that_beats_but_does_not_progress…` |
| G | `PageTransfers::hold` and `release` run their transactions with 3 tries on deadlock. | none on sqlite (deadlocks are not reproducible there); by reading |
| H | `ResumeChatbotSequenceSend` timeout 280 s. | `test_H_and_I…` |
| I | The running marker's key carries the site (a hash of `app.url`), so two sites on one Redis never share it; `config/cache.php`'s default prefix comes from `APP_NAME` and may be the same on both. The state that matters is in rows now (B). | `test_H_and_I…` |
| J | Rollback step 5 waits until nothing is in flight, with the read-only check (see Deploy steps). | — |
| K | A step held because the page ID changed is recorded ("the page ID changed; stopped") and stops (status error) once its first such hold is more than 24 hours old. | `test_K_a_page_id_changed_for_24_hours…` |
| L | `test_5` creates its failing trigger in the form of the driver (MySQL `SIGNAL SQLSTATE '45000'`, sqlite `RAISE(ABORT)`). | `SequenceReRunTest::test_5…` |
| M | The backstop reads linked rows ordered by the indexed `send_request_id` with a `NOT EXISTS` on the ticket's unique `request_id`; the resume sweep and the prune read per status with `status = ? AND priority = 3` first, on the `(status, priority, id)` index. Both portable. | `test_M_the_backstop_and_the_resume_sweep_read_through_their_indexes` (red on 363ac71b: `order by id`) |
| N | Sequence tickets were already pruned by `sweepSequence` (resumed, failed, done; a skipped send is `done`, a cancelled one `resumed`); now per status on the index, in chunks of 5,000. | `test_N_old_sequence_tickets_are_pruned…` |
| O | Measured with `DB::listen` around the pool alone, after one warm-up send (the per-process schema checks of the reply columns run once in a pool's life): **11 statements per send in the pool** (claim `SELECT` + `UPDATE` + row `SELECT`, `replyOpen`, subscription, customer values, last chat, `calledSequence`, `finishSequence`, reply `INSERT`, tag rows `INSERT`) and **10 in the resume job**. The first send of a process adds 9 schema reads. Ceilings asserted: 12 and 12. | `test_O_the_pool_runs_a_stated_number_of_queries_per_send` |

## Round 4 (minors, on 7e99db3f)

| # | Fix | Test |
|---|---|---|
| 1 | Without the new columns (migration not run) the attempt guard is kept in the cache (`chatbot-seq-attempt:<site>:<id>`, same format, same rules), so a job killed during an inline call is not sent again: at least as safe as base. With the columns, the row is used, as before. | `SequenceDurabilityTest::test_R4_1_without_the_column…` (marked during the call, cleared at the end; a left-over "call went out" is not sent again) |
| 2 | `unlink()` (used by the page transfer's hold, release, import and activate, and by subscribe, unsubscribe, resume-paused, advance and complete) clears only `send_request_id`. `attempt` is cleared only by the step's own end. | `test_R4_2_a_page_hold_during_a_running_step_leaves_its_attempt` |
| 3 | The idle pool asks "is anything waiting?" only when its last progress is older than 60 s (`IDLE_CHECK_SECONDS`), not on every claim round. | `test_R4_3_an_idle_pool_reads_for_waiting_tickets_at_most_once_a_minute` (0 reads in 20 rounds, 1 after a minute) |
| 4 | The "no progress" warning is logged once per stall (a second key remembers the stall it was logged for). | `test_R4_4_and_5…` (two ticks, one line) |
| 5 | The sequence pool's beat key and stalled key carry the site (`ChatbotSequences::site()`, a hash of `app.url`), like the running marker and the attempt fallback. `TicketPool` reads the beat through `beatKey()` (the reply and AI pools keep their constant). | `test_R4_4_and_5…` (another `app.url` sees neither the beat nor the stall) |
| 6 | The prune runs at most once a minute (`Cache::add` per site). It finds the newest id older than `KEEP_DAYS` once (through the `created_at` index), then deletes `status = ? AND priority = 3 AND id <= cutoff` per status, in chunks of 5,000, through `(status, priority, id)`. Portable. | `test_N_and_6_old_sequence_tickets_are_pruned_up_to_an_id_cutoff_at_most_once_a_minute` |
| 7 | Deploy step 1 rewritten: both columns and the index, a quiet hour, `lock_wait_timeout = 5` in the migration on MySQL, and the note for a local round 2 migration. | — |
| 8 | The step job's comment names the attempt in the subscription row, not the removed cache constant. | — |

Run on sqlite after round 4: the 6 Sequence classes (Durability 14, Handoff 11, Safety 28, ReRun 12 with 1 skipped,
Visibility 3, Bench 10), the 14 listed suites, PageOwnersScreenTest, and the other suites on the shared pool base
(`TicketPool`: BigBroadcast, BotLaneShare, BotRepliesBeforeBlasts, BotcakeAsyncSend, the AI pool and provider suites):
all green. The full suite was not re-run for round 4 (only these files changed; the `TicketPool` change is a method that
returns the same constant for the other pools).

## Release check (on dee40807)

| # | Fix | Test |
|---|---|---|
| 1 | `test_N_and_6` matched the prune query with `"id" <= ?`, which MySQL writes with backticks. It now strips both quote styles before matching. The other new tests were checked: the backstop and sweep shape tests and S-215b.9 already accept both styles; no other quote-dependent match is left. | `SequenceDurabilityTest::test_N_and_6…` (sqlite green; the failing line was the only MySQL failure) |
| 2 | The migration's `down()` also sets `SET SESSION lock_wait_timeout = 5` on MySQL. | by reading (MySQL only) |

Run on sqlite after the release check: the 6 Sequence classes (Durability 14, Handoff 11, Safety 28, ReRun 12 with
1 skipped, Visibility 3, Bench 10), all green.

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
| Head after round 2 (363ac71b) | 4,040 | 74,286 | 2 | 5 |
| Head after round 3 | 4,050 | 44,333 | 3 | 5 |

- **No new failure.** The round 3 head's 3 failures are all on the base list: `ExampleTest` (302), `ItemTableFitScriptTest`
  (57 vs 45), and `BigBroadcastTest::test_S_167_1` (the same timing assertion as on base, "all 5,000 handed to the pool at
  once": 4,508; the class passes alone, 10/10, 30,077 assertions, on this head). That test stops at its first assertion
  under load, which is why the assertion total is lower on this run. `AstraAddressRulesTest::test_S_26_18` passed. The
  extra skip is `test_14_mysql_only…` (MySQL only).
- **74 new tests:** SequenceHandoffTest 11, SequenceSafetyTest 28, SequenceReRunTest 12 (1 skipped on sqlite),
  SequenceDurabilityTest 10, SequenceVisibilityTest 3, SequenceBenchTest 10. All green on sqlite.
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

1. Migrate by hand, at a quiet hour (not 08:00–23:00 Manila):
   `php artisan migrate --force --path=database/migrations/2026_11_15_100000_add_send_request_to_chatbot_sequence_subscriptions.php`.
   It adds two nullable columns to `chatbot_sequence_subscriptions`, `send_request_id` (string 36, with an index) and
   `attempt` (string 80), each only when missing; no row changes. On MySQL it sets `SET SESSION lock_wait_timeout = 5`
   first (its `down()` too), so a long transaction on the table makes it fail fast instead of blocking the table; run it again a few minutes
   later if it does. Until it runs nothing changes (the hand-off stays off, and the step keeps its cache-based guard).
   Anyone who ran the round 2 version of this migration locally (only `send_request_id`) must roll it back
   (`php artisan migrate:rollback --path=…`) and run it again; the guarded version also adds the missing column on its own.
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
5. wait until nothing is in flight: the read-only check below returns 0 and 0 (the pool sends what was handed off, and
   the resume jobs clear the links):
   `SELECT (SELECT COUNT(*) FROM botcake_send_requests WHERE kind = 'sequence' AND status IN ('pending','sending')) AS tickets, (SELECT COUNT(*) FROM chatbot_sequence_subscriptions WHERE send_request_id IS NOT NULL) AS linked;`
   (or `php artisan chatbot:sequence-lateness`, column "Pool pending", all blank);
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
4. **The customer lock is still a cache lock** (as for replies today). A cleared cache no longer repeats a step (its state
   is in the row), but for the moment of the clear a reply run and a sequence step of one customer can overlap.
5. **A killed job is picked up after its row's run time runs out** (about 290 s), not 2 minutes; only a job that never
   started is re-picked in about 2 minutes.
6. **The pool's database time per send** (11 statements in the pool, 10 in the resume job, measured on sqlite) is the
   ceiling of one pool process; measure it on MySQL.
9. **A stalled pool is remembered in the cache** until it progresses again (advisory: if that is lost, the next tick
   detects the stall again).
7. **MySQL-only behaviour** (row locks in `settleForMove`, the same-second re-stamp, the tick plan) is checked by reading
   and by the MySQL-only test, not by the sqlite suite.
8. **AI nodes in a sequence step still run inline** (out of this spec); with the 280 s timeout a step with several slow
   AI calls can still be killed, and is then recorded, not run again (fix 1).
10. **The prune's per-status select on MySQL** can pick the `(status, next_at)` index with a filesort when a large old
    backlog is retained (70–190 ms measured in the release check). Accepted: it runs at most once a minute and drains
    5,000 per status per pass; the idle case is 0.1–5 ms. No `forceIndex` (not portable).
