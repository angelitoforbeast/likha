# 301 result: sequence pool throughput and the due → sent chart

Base: live 2940b58b. Branch `feat/301-sequence-pool-throughput`.

## Plan

Base 2940b58b: full suite once with `vendor/bin/phpunit` under Herd PHP (base failures listed under Evidence).

1. **SQ-1 profile first** (`tests/Feature/Chatblast/SequencePoolThroughputTest.php`): 120 pages (one token each, six busier pages), thousands of due sequence tickets already in `botcake_send_requests` with their subscriptions and chats, a fake Botcake that answers 5 s after the call, the pool in virtual time. Each pool round costs its real time (PHP + sqlite) + the number of queries × one production round trip (calibrated so one pool at 250 slots gives today's ~10.7 tickets/s) + the loop's pause (`queue.pools.busy_ms`). Every query is classified (claim, pre-send checks, called, finish). Several pools each keep their own clock, as separate processes do.
2. **Design from the profile**: every ticket costs ~10 blocking DB round trips inside the one event loop (2 to claim, 5 pre-send checks, 1 "called", 2 to finish), so the loop, not the slots, Botcake or Meta, is the limit. Change: run the pool as **N processes, each owning a fixed share of the pages** (`page_id % N`), with every per-ticket rule unchanged inside a process. New setting `services.botcake.sequence_pools` (`BOTCAKE_SEQUENCE_POOLS`, default 1 = exactly today) and `chatbot:sequence-pool --shard=K`.
   - claim, recover and the "pending?" check filter on the share; the sweep runs in share 0 only;
   - share 0 keeps today's beat key; shares 1..N-1 have their own; a process refuses to start beside a live pool of another N (no overlap while N changes) or of its own share;
   - `capable()` needs every share alive (else steps go inline, as with a dead pool today); the minute tick (`settleInFlight`) treats the live owners as a set and cleans up only a dead share's pages.
3. Tests: load test ≥ 2.5× with slots near full and every step once; crash mid-run; two processes never share a ticket, page or customer; per-customer order; per-page rate; start refusals; tick with a dead share.
4. **SQ-3**: `ResponseSpeed::sequences()` gains `due_to_sent` per hour and for the day (Meta time when confirmed, else pushed; Botcake = pushed); chart `seq` series `avg`/`p90` = due → sent, `meta_avg`/`meta_p90` = pushed → sent at Meta (in the legend); cache key `v3`; table gains a Due → sent group; summary line keeps its numbers.
5. Full suite vs base, result, push.

## What shipped

Commits on `feat/301-sequence-pool-throughput` (after the two docs commits):

- `d44e717e` feat(sequences): sequence pool in N processes, each with a fixed share of the pages
- `5fc2d206` feat(speed): Sequences chart shows due → sent per due hour
- `90f013ff` fix(sequences): a dead share's recovery never takes back what a live process holds
- `08f314f1` feat(sequences): number of sequence pools from the app_settings row chatbot.sequence_pools (review follow-up)

**Sequence pool (SQ-1, SQ-2)**

- **The number of processes, N** (`SequenceSendPool::pools()`): the `app_settings` row `chatbot.sequence_pools` first, read with the app's existing helper **`App\Models\AppSetting::get()`** (the same table and helper as `hold_snapshot_time`, `jnt_pickup_days_offset`, Bot Health's settings); no row (or an empty value) → `services.botcake.sequence_pools` = `env('BOTCAKE_SEQUENCE_POOLS', 1)`; then 1. Any value outside 1..8 (0, 9, -2, "abc", "2.5") → 1. The resolved value is cached for 60 s in the shared cache (`sequence-send-pool:pools:<site>`), so every reader sees the same N and a changed row takes effect within a minute, without `config:cache`. Readers: the command's `--shard` check and its start refusal (`new SequenceSendPool()`), the hand-off's "all shares running" check (`capable()`), the minute tick's shares and dead-share clean-up (`settleInFlight`, `owners()`). N = 1 is today's single process, byte for byte the same SQL, beat key and beat content.
- A running process checks the count at most every 5 s (`poolsChanged()`); when it differs from the N it started with, it stops cleanly (drains its calls) and supervisor starts it again with the new N. A process whose `--shard` is outside the new N exits with an error after 10 s (supervisor keeps retrying it, harmlessly, until numprocs matches).
- `chatbot:sequence-pool --shard=K` (default 0). A share outside 0..N-1 exits with an error after 10 s (as the "another pool is running" refusal does).
- `SequenceSendPool`: `shard` and `pools` fixed for the life of the process. Claim (`BotcakeSendQueue::claim(..., $part)`), recover (`recoverSequence($owner, $part)`) and the progress check (`sequencePending($part)`) only see rows with `page_id % N = K`. Every page, therefore every customer (`sequence:<page_id>:<psid>`) and every ticket of that customer, belongs to exactly one process; inside the process nothing changed: lane per token, `BotcakeClient::slot` (shared with the bot's replies through the cache), lane cap, gateway per-page cap, 429 pause, `ChatbotSequences::sendCheck`, reply-open wait, `calledSequence` before the call, recovery without resend.
- Share 0 keeps the beat key `sequence-send-pool:beat:<site>`; share K > 0 uses `…:<site>:K`. The beat carries `pools` and `shard` only when N > 1. `start()` refuses when its own share is live (as today), when the share is outside the count, or when any live beat (0..15) belongs to a pool with another N (e.g. the old single pool still draining after the change): two processes can never hold the same page.
- The sweep (whole table) runs in share 0 only, plus the minute tick as before.
- `capable()` (hand-off to the pool) needs every share alive, flagged, not stopped and not stalled, with the same N. A missing share means steps send inline, which is today's behaviour with a dead pool.
- `ChatbotSequences::settleInFlight()`: per share; a dead or stalled share cancels and recovers only its own pages; the "called long ago, no answer" clean-up and the cancel skip every live owner; a dead share's recovery never touches tickets of a live process (the old single pool during the change).
- `TicketPool`: `start/beat/stop` use `ownBeatKey()` (default `static::beatKey()`, so the reply pool and AI pool are unchanged).

**Speed chart (SQ-3)**

- `ResponseSpeed::sequences()`: every hour and the day gain `due_to_sent` {n, avg, p90, max}: sent at Meta − due when Meta confirmed the send, otherwise pushed − due (Botcake sends at once, so pushed is its sent time). Same steps as "Sent" with a due time.
- Chart `seq`: `avg`, `p90` = due → sent (solid, "Due → sent avg / p90"); `meta_avg`, `meta_p90` = pushed → sent at Meta (dashed, in the legend, hidden at first). Tooltip counts "steps" (due → sent) and "at Meta". The previous day is dotted as on every other chart. As on the other charts (spec 188-1), only the average is shown at first; the axis top is cut from it.
- Title: "Sequences by hour due: due → sent, average and p90 (sent at Meta when confirmed, else pushed)". The table gains a "Due → sent" group (Steps, Average, p90, Max) before "Scheduled → pushed"; the note under the table explains it. The summary line is unchanged.
- `SEQUENCES_KEY` v2 → v3, so no cached entry of the old shape is read after deploy.

## Evidence per done-when item

### 1. SQ-1 profile numbers and the named limit

Run: `SEQ_PROFILE=1 vendor/bin/phpunit tests/Feature/Chatblast/SequencePoolThroughputTest.php` (prints the profile). Fixture: 120 pages, 4,000 due tickets (six pages 4× busier), fake Botcake answering 5 s after the call, rate_per_page 10, lane 3 s (cap 30), 250 slots, `queue.pools.busy_ms` 5.

Calibration of one production round trip (one pool, 250 slots, tickets/s in the 50 s window):

| round trip | 6 ms | 8 ms | 9 ms |
|---|---|---|---|
| tickets/s | 14.4 | 11.6 | **10.6** (production 10.7) |

At 9 ms (the test's `QUERY_MS`), one pool as today:

```
[one pool, 250 slots] 10.6 tickets/s (528 calls in the window), slots avg 216 (max 250), 59 ticks, 851 ms per tick, 10.2 queries per ticket
    check: customer values  2.00 per ticket   (CustomerFields BOT_PAUSED + CustomerReach::skipReason)
    finish: ticket          1.03 per ticket   (finishSequence UPDATE)
    finish: reply row       1.03 per ticket   (chatbot_replies INSERT)
    called (before the call)1.00 per ticket   (calledSequence UPDATE … WHERE EXISTS subscription)
    check: last chat        1.00 per ticket   (botcake_chats, 24-hour window)
    check: reply open       1.00 per ticket   (BotcakeSendQueue::replyOpen)
    check: subscription     1.00 per ticket   (sendCheck)
    claim: read row         1.00 per ticket   (SELECT after each claim UPDATE)
    claim: update           1.00 per ticket   (conditional UPDATE per candidate)
    claim: candidates       0.11 per ticket   (one candidate query per round)
    claimed → called avg 13.96 s, called → done avg 5.40 s
```

Where a ticket's time goes: ~10 synchronous round trips (2 claim, 5 pre-send checks, 1 "called", 2 finish), each blocking the single event loop: ≈ 93 ms of blocked loop per ticket, i.e. ≈ 10.7 tickets/s whatever the slot count. A pool round takes ~0.85 s, so each lane gets its turn (one request per lane per `lanes()` pass) about twice a second instead of ten times; tickets sit claimed in their lane (claimed → called 14 s here, 3.9 s in production where fewer slots were held) and answers wait for the next `pump()`. Botcake (5 s fake) and Meta are not the limit; CPU in the loop is small (sqlite time per class is 15-30 ms for the whole window).

**Named limit: the per-ticket blocking DB work inside the one `chatbot:sequence-pool` process.** Its 250 slots cannot help: the loop, not the slots, is the bottleneck. (In the fixture the slots stay near full, 216/250; production samples showed 40-200 in `sending`. Both fit the same limit: throughput = 1 / time per ticket in the loop. The one-trip cost is a calibration, Redis lock and queue pushes are folded into it; it is not measured on production.)

### 2. Load test ≥ 2.5× with slots near full; once-only, order, rate, no shared page; full suite

Same fixture and cost model, `test_SQ_2_H_four_processes_send_at_least_2_5x_the_base_rate_with_slots_near_full_and_every_step_once_in_order` (6,000 customers + 60 customers with two tickets, 40 s window):

| processes | tickets/s | × base | slots avg per process |
|---|---|---|---|
| 1 (base, same run) | 10.3 | 1.0 | 214 / 250 |
| 2 | 20.7 | 2.0 | 191 / 250 |
| 3 | 32.6 | 3.1 | 213 / 250 |
| **4** | **42.8** | **4.1** | **220 / 250** |

(2 and 3 measured with a one-off local change of the count, not committed.) The test asserts ≥ 2.5× the base and ≥ 27 tickets/s with 4 processes, slots ≥ 80 % full, then drains every ticket and asserts:

- every step called exactly once and every ticket sent (`assertOnceInOrderAtRate`);
- per-customer order: a customer's second ticket is called only after the first one's answer (60 customers);
- per-page rate: consecutive calls of every page ≥ 1 / rate_per_page apart across all processes;
- each page is called by one process only.

Other tests (all in `SequencePoolThroughputTest`):

- `test_SQ_2_E_two_processes_never_claim_the_same_ticket_page_or_customer`: two processes over 40 pages; no page or customer ever held by two owners; every owner holds only its share (`page_id % 2`); drained once and in order; the claim itself from both shares at once is disjoint and covers all 300 tickets.
- `test_SQ_2_N_a_crash_or_stop_mid_run_sends_nothing_twice_and_does_not_touch_the_other_process`: process 1 dies mid-run with calls in the air; process 0 runs on alone for 70 s and the minute tick runs; process 0's calls are untouched; a new process 1 starts (recover: the dead one's called tickets fail without resend, uncalled go back); drained: no step called twice, order kept.
- `test_SQ_2_a_process_refuses_to_start_beside_a_live_pool_of_another_count_or_outside_the_count`: N=1 keeps today's beat key; with N=3 a share refuses beside the live single pool, `capable()` is false until all three run, a share outside the count or twice refuses, another count refuses while these run, one stopped share makes `capable()` false.
- `test_SQ_2_while_the_count_changes_the_tick_never_takes_back_what_the_old_single_pool_still_holds`: fails without the fix in `90f013ff` (checked), passes with it.
- `test_SQ_2_the_minute_tick_leaves_a_live_process_calls_alone_and_cleans_up_a_dead_share_only`.
- `test_SQ_2_the_pool_count_comes_from_the_app_setting_then_the_config_then_1`: row missing → config (3); empty row → config; row 4 → 4 (also what `new SequenceSendPool()` uses for the `--shard` check); rows 0, 9, -2, abc, 2.5, 100 → 1; row 8 → 8; config 12 with no row → 1.
- `test_SQ_2_a_changed_row_reaches_every_reader_within_a_minute_and_a_running_process_notices`: row 2 → `capable()` needs both shares; the row changed to 3 is still 2 for everyone until the 60 s cache ends, then 3 (hand-off waits for three shares); a running process of the old count sees the change; a new share of 3 refuses while the processes of 2 are live; once those stop, three shares start; a process started with the old count (2, or the single pool) refuses beside the live processes of the new count; a share outside the count refuses.
- All the multi-process tests set N through the `app_settings` row (as on the servers).
- The existing pool and sequence tests (handoff, safety, durability, bench, gateway route, reply pool, sweeps) run unchanged and pass: they use the default N=1.

**E: the smaller site and the bot's first replies.** With the default N=1 the code path is today's: no `page_id %` filter in any query, the same beat key and beat content (no `pools` key), the same recover and sweep; the whole existing sequence/pool test set passes without edits. The reply pools (`botcake:send-pool` / reply-pool) are not changed (`TicketPool::ownBeatKey()` returns their same key; `claim()` gets one optional trailing argument, default null). A page's total Botcake rate is still one shared `BotcakeClient::slot` per token, and each page sits in one sequence process, so a page never gets more sequence requests than today's rule allows, and `SequenceBenchTest::test_S_215e_7_a_first_reply_on_a_busy_page_is_not_slower_than_with_the_old_code` passes. What does grow on the busy site: about 4× the sequence pool's DB queries per second (≈ 10 per ticket, ~430/s at 42 tickets/s instead of ~107/s), on a server at 2 of 8 cores.

No migration: no new index was needed (the share filter is evaluated on the rows the claim already reads through `(status, priority, id)`), so no EXPLAIN.

**Full suite** (`vendor/bin/phpunit`, Herd PHP 8.4, other workers' suites running on the same machine):

| run | tests | errors | failures | skipped |
|---|---|---|---|---|
| base 2940b58b | 5,679 | 2 | 5 | 6 |
| branch (at `90f013ff`) | 5,687 | 0 | 5 | 6 |
| after `08f314f1`: `SequencePoolThroughputTest` | 9 | 0 | 0 | 0 |
| after `08f314f1`: filter Sequence, SendPool, SendQueue, ReplyPool, Pool, Sweep, CustomerReach, GatewayRoute | 325 | 0 | 0 | 1 |

Base failures: SpeedMemoryTest S_198_1 (error), SpeedPrewarmTest S_277_5 missing entry (error), SpeedPrewarmTest S_277_4 and S_277_5 default page (failures), ExampleTest (302), ItemTableFitScriptTest drop order, SharedPagesBasePinTest `/owner/private`.

Branch failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest `/owner/private` (all three as at base), plus BigBroadcastTest S_167_1 ("all 5,000 handed to the pool at once": 3,131) and AstraAddressRulesTest S_26_18 (28.1 s against a 25 s limit). Both are timing-bound and **pass when run alone on the branch** (`--filter 'test_S_167_1_5000_messages|test_S_26_18_over_every_pair'`: OK, 2 tests); the full run shared the machine with other workers' suites. The base's four speed failures pass on the branch (and in the branch's filtered speed run: 62 tests OK). No new failure that reproduces.

### 3. The speed chart shows due → sent per due hour on seeded data

- `ResponseSpeedTest::test_S_301_SQ_3_steps_sent_20_minutes_late_chart_about_1200_s_not_the_1_to_2_s_at_meta`: four steps at 11:00 pushed 20 minutes after due (three on Forbeast with Meta 1-2 s later, one Botcake): hour 11 `due_to_sent` = {n 4, avg 1,201.3, p90 1,202, max 1,202}; the chart's average for 11:00 is ~1,200 s; the Meta line (meta_avg) is 1.7 s.
- `test_S_185_5_chart_4_matches_the_sequences_due_to_sent_and_pushed_to_meta_columns`: the chart series equal the table columns; title on the page.
- Rendered evidence (seeded day: 52 steps 08:00-16:00 sent 20-21 minutes late, Meta 1-2 s after the push; previous day 1-3 minutes late), the real page script (`charts` + `draw` from `speed.blade.php`) with Chart.js in a browser:
  - chart JSON: hour 08 due → sent avg 1,229.5 s, p90 1,254 s; pushed → Meta 1.5 s; previous day 121 s (dotted);
  - table row 12:00: Due → sent 6 · 1,232.8 s · 1,262 s · 1,262 s | Scheduled → pushed 6 · 1,231.3 s … | Pushed → sent at Meta 6 · 1.5 s · 2 s · 2 s;
  - summary line unchanged: "Day: 52 due · 52 sent · 0 failed · 0 skipped; scheduled → pushed avg 1,231.1 s · … pushed → sent at Meta: 52 steps · avg 1.5 s · p90 2 s · max 2 s".
  - Files (outside the repo, in the worker's scratchpad `301-ev/`): `301-seq-chart.jpg` (screenshot at 1100 px), `301-seq-chart.html`, `301-seq-chart.json`, `301-seq-section.html`.

### 4. Pushed; merge danger below

Branch pushed to `origin/feat/301-sequence-pool-throughput` (see the report).

## Story changes

- SQ-3: "average and p90": both are on the chart; as on every other chart of the page (spec 188-1) only the average is shown at first and p90 is one click in the legend. Changing it is one line (`$shown` in `ResponseSpeed::charts`).
- Tests changed because the chart changed (old expectation was pushed → Meta as the main line): `ResponseSpeedTest` S_182_11 (adds due_to_sent), S_185_5 (renamed: due → sent and Meta series), S_185_6 (Forbeast p90 at 10:00 is now 180 = due → sent), S_185_10 (previous day avg 31 s due → sent, 30 s at Meta). The old-code comparisons (`SpeedSequencesSameTest`, `SpeedPrewarmTest` S_201_4, `SpeedMemoryTest` S_198_1 fingerprint) compare every old number unchanged: `due_to_sent` is stripped and the chart is mapped back to the old shape (`LoadsSpeedSequences::withoutDueToSent`, `seqChartAsOld`).

## Rulings

1. **Design: N processes with a fixed share of pages, not batching the per-ticket work.** Batching (claim in one UPDATE, the five checks as IN queries, "called" per batch) would cut ~10 round trips to ~4 per ticket but rewrites the checks and the "recorded before the call" write, the parts that guard double sends and order. Sharding leaves every per-ticket rule as it is and scales with N (4.1× at N=4 in the load test).
2. **Share by `page_id % N`, not by token.** A customer key contains the page ID, so a customer can never be in two shares. Production has one token per page (122 tokens); if two pages ever share a token across shares, the per-token rate still holds (the slot is shared in the cache) but each process applies the lane cap (held tickets) separately.
3. `sequence_concurrency` stays per process (4 × 250 slots at N=4).
4. Due → sent uses the Meta time when confirmed, else the pushed time (unconfirmed gateway steps and Botcake).
5. **N comes from `app_settings` `chatbot.sequence_pools`** through `App\Models\AppSetting::get()` (the app's existing site-setting helper), then `BOTCAKE_SEQUENCE_POOLS`, then 1; outside 1..8 → 1; one shared 60 s cache for every reader. A running process restarts itself (clean stop) when the count changes, so the row alone switches the processes; supervisor's numprocs must match it.
6. Base failures (below) are load and environment failures of the base itself; the speed ones (SpeedPrewarm S_277_4/S_277_5, SpeedMemory S_198_1) pass when the machine is quieter, as they did on this branch's filtered runs.

## Merge danger

**What ships:** the pool can run as N processes (off by default: N=1 is today; N from the `app_settings` row `chatbot.sequence_pools`), the minute tick and hand-off understand N shares, and the Sequences chart on `/chatbot/speed` shows due → sent. No migration, no new table. Files: `SequenceSendPool`, `TicketPool` (shared base of the reply and AI pools: only the beat key now comes from an overridable method with the same default), `BotcakeSendQueue` (optional `$part` on `claim`, `recoverSequence`, `sequencePending`), `ChatbotSequences::settleInFlight/cancel`, `RunSequenceSendPool`, `config/services.php`, `ResponseSpeed`, three speed views, tests. Cherry-picks as four commits (`d44e717e`, `5fc2d206`, `90f013ff`, `08f314f1`); none touches 297/298 files.

**Both sites (Incepxion and Likha) run the same setup: 4 processes.** No `.env` edit and no `config:cache` are needed; `BOTCAKE_SEQUENCE_POOLS` stays unset (it is only the fallback when the row is missing).

1. Deploy the code as usual. With no row, N = 1 and the running single pool behaves as today. The first load of today's/yesterday's Sequences rebuilds once (cache key v3; "building" for a moment, the warm job fills it).
2. Supervisor, the existing `chatbot:sequence-pool` program:
   ```
   command=php /path/to/artisan chatbot:sequence-pool --shard=%(process_num)s
   process_name=%(program_name)s_%(process_num)02d
   numprocs=4
   numprocs_start=0
   stopwaitsecs=70
   ```
   (keep its user, directory, autorestart and log settings; `stopwaitsecs` ≥ 65 lets a stopping process finish its calls), then `supervisorctl reread && supervisorctl update`. While the row is still missing, process 0 runs as today's single pool and processes 1-3 exit after 10 s with "outside the number of sequence pools" (supervisor retries them; harmless).
3. Set the row to 4, in the app's database (`app_settings`, unique `key`):
   ```
   php artisan tinker --execute="App\Models\AppSetting::set('chatbot.sequence_pools', 4);"
   ```
   or in SQL:
   ```
   INSERT INTO app_settings (`key`, value, created_at, updated_at) VALUES ('chatbot.sequence_pools', '4', NOW(), NOW())
     ON DUPLICATE KEY UPDATE value = '4', updated_at = NOW();
   ```
4. Within a minute (cache 60 s, checked every 5 s): process 0 sees the new count and stops cleanly (drains up to 65 s); supervisor starts it again as share 0 of 4, and processes 1-3 start on their next retry once the old single pool has stopped (a process never starts beside a live pool of another count). Until all four run, `capable()` is false and sequence steps send inline (slower jobs, as with a dead pool today); nothing is sent twice and nothing the old pool holds is taken back. About 1-2 minutes in all.
5. Check: `supervisorctl status` shows 4 processes running; `chatbot:sequence-lateness` and the Sequences chart (now due → sent) fall over the next hour; `botcake_send_requests` in `sending` near 4 × 250 in busy hours (concurrency stays 250 per process); watch DB load (≈ 4× the sequence pool's queries on Incepxion).

Order matters only for speed: setting the row before the supervisor change also works, but steps send inline until supervisor runs all four processes.

**Roll back:** set the row back to 1 (`App\Models\AppSetting::set('chatbot.sequence_pools', 1)`, or the SQL above with '1'); within a minute every process stops cleanly and process 0 comes back as the single pool. Then supervisor `numprocs=1` (command with `--shard=0`, or the old command) and `supervisorctl reread && supervisorctl update`. The code can stay (N=1 is today). Reverting the code also brings the chart back (cache key v2 again).

**Settled by the reviewer:** p90 stays one click in the legend; shares by page stay.
