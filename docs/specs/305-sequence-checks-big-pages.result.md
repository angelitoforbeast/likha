# 305 result: sequence sends keep up on the biggest pages

Base: live 528326cc. Branch `feat/305-sequence-checks-big-pages`.

## Plan

1. **BP-1 profile on MySQL first.** A local MySQL 8.0.42 on port 3415 (data folder in the session scratchpad, deleted at the end), the app's migrations run against it, invented data: one huge page (millions of `chatbot_replies`, a big `botcake_chats`, `chatbot_customer_values` and `chatbot_sequence_subscriptions` history, a `botcake_send_requests` table with two days of done/resumed tickets and a pending backlog of thousands on that page) plus normal pages. Tickets of the huge page and of a normal page go through the pool's real path (claim, take, issue: replyOpen, sendCheck, calledSequence; respond: finishSequence and the reply row), every query timed (`DB::listen`) and EXPLAINed (`EXPLAIN ANALYZE` for rows examined and the index used). The claim round (candidates query with the shard filter and full pages) is profiled the same way at production-like backlog. Bench script kept out of the repo; numbers and EXPLAIN in this file.
2. **Name the slow queries** from that evidence (per query: time, rows examined, index; huge vs normal page).
3. **BP-2 fix** only what the profile names: an index that fits, a rewritten query, or one batched read per claim round, keeping every per-ticket rule (once-only send recorded before the call, per-customer order, per-page rate, the checks before a send). A migration only for an index the EXPLAIN proves, built online (`ALGORITHM=INPLACE, LOCK=NONE`), with a build-time estimate per million rows.
4. **Load test** next to 301/303's in `SequencePoolThroughputTest`: a huge-history page beside normal pages, Botcake at 3 s, lane cap 50; per-query cost from the measured MySQL times (not one flat round trip) so the history-dependent cost shows; before vs after steps/s for the huge page, normal pages unchanged.
5. Tests: BP-2 tests, 301/303's pool tests, the sequence/pool filter, then the full suite vs base 528326cc (base failures listed).
6. Result, merge danger (migration, how to run it on the big table, revert), push.

Change to the plan, after the profile: the per-query MySQL times on the seeded copy were all 0.2–7 ms, so a cost model built from them would not show production's limit. The load test therefore keeps 301's cost model (every query costs one production round trip, `QUERY_MS` = 9 ms) and adds two things: the clock moves during each query (not only at the end of a pool round), and a second, real-time load test runs the real pool against the seeded MySQL with an added delay per query (bench script, outside the repo).

## What shipped

Commits on `feat/305-sequence-checks-big-pages`:

- `98afa21e` docs: spec 305 and result skeleton
- `3c40bac6` docs: 305 plan
- `3dcb9344` feat(sequences): the check before a sequence send is one read on bounded indexes, one claim round is a few queries, and a busy page's lane takes its turn when its interval passes (305)
- `31650075` test(sequences): the big-page load test has a backlog longer than one claim's candidate list (305)
- (this result commit)

No migration, no new table, no setting, no config or env change. Files: `ChatbotSequences.php`, `CustomerReach.php`, `BotcakeSendQueue.php`, `SequenceSendPool.php`, `tests/Feature/Chatblast/SequencePoolThroughputTest.php`.

1. **The latest-chat check reads one row.** `ChatbotSequences::lastChat()` (used by `sendCheck` and by `runStep`) reads the customer's newest non-standby chat with `USE INDEX (botcake_chats_page_psid_id_index)`, that is `(page_id, psid, id)` backwards, stopping at the first real chat. The hint goes through the existing `SweepLimit::useIndex` helper: it is added only when the index exists (one `Schema::hasIndex` per process), so a database without the index runs the old query instead of failing. The index already exists on both sites' code line (migration `2026_10_24_100000_add_inbox_indexes`, for the inbox).
2. **The check before a send is one round trip.** `ChatbotSequences::sendCheck($row, $replyOpen = false)` reads everything it needs in one `SELECT` made of scalar subqueries (`sendFacts`): the subscription (PRIMARY), the customer's three marks `__bot_paused`, `__last_message_at`, `__unreachable` (unique `(page_id, psid, key)`), the latest chat (item 1) and, when `$replyOpen`, whether a bot reply to the customer is pending or sending (`(customer, status, id)`). Before: replyOpen, subscription, all customer values, the unreachable mark, the latest chat = 5 round trips. Every check, its order and its reason text are unchanged; the pool calls `sendCheck($row, true)` and treats the new code `reply_open` exactly as the old `replyOpen()` branch (back to pending for 1 s). `CustomerReach::reason()` is `skipReason()`'s parsing for a value already read (`skipReason()` itself is unchanged for its other callers).
3. **One claim round is a few queries.** `BotcakeSendQueue::claimSequence()` (sequence pool only): the same candidate query as `claim()` (now shared as `candidates()`), the pool's `room()` decides per candidate and counts what it accepted in this round, then one conditional `UPDATE … WHERE id IN (…) AND status = 'pending'` and, at the end, one `SELECT … WHERE claimed_by = ? AND status = 'sending' AND id IN (…)`. Before: an `UPDATE` and a `SELECT` per ticket. `claim()` (reply pool, chatblast, send pool) runs the same SQL as before.
4. **A long backlog of one page no longer hides the other pages of its process.** In the same round, when a candidate is refused because its lane is full, its page is left out of a second (at most third) candidate read, but only when the first read was cut by its limit. Before: when the big page's lane had one free place at claim time, the `limit (max − held) × 5` candidates were all the big page's oldest tickets; one was taken, the rest refused, and every other page of that process waited for a round where the big lane was exactly full (see BP-2 evidence: the normal pages of the big page's process got 0 sends in 45 s on MySQL).
5. **A busy lane gets its turn when its interval passes.** `lanes()` repeats its pass over the lanes while the previous pass sent something, for up to `CLAIM_EVERY` (0.2 s). Before: one call per lane per pass, two passes per round, so a page's rate was at most 2 ÷ (the round time of everything else in its process).
6. **The next call's check is done just before its turn.** When a Botcake lane's next turn is less than `CHECK_AHEAD` (50 ms, at most half the interval) away, the first ready ticket's read check (item 2) runs now; at the turn only the conditional "called" write and the call remain. A check older than `CHECK_FRESH` (0.5 s, for example after the slot was taken by a bot reply or a 429 pause) is done again. Retries (429) and ImageFlow steps keep the old order. The spacing between two calls of a page is still at least 1 / `rate_per_page`, measured on the call times, and "called" is still written right before each call.

## Evidence per done-when item

### 1. BP-1 profile and the named slow queries; tests

**Seeded MySQL** (8.0.42, port 3415, 1 GB buffer pool, laptop SSD; all 355 migrations; invented data):

| table | huge page "Big Page" | 15 normal pages |
|---|---|---|
| `botcake_chats` | 2.3M rows over 87 days, 300k customers, 2 % standby copies; 900 customers with ~206 chats each, the others ~6 | 40k + 3k each |
| `chatbot_replies` | 4M over 60 days | 60k each |
| `chatbot_customer_values` | 300k customers × 3 keys + 6k unreachable marks | 8k × 3 each |
| `chatbot_sequence_subscriptions` | 400k | 8k each |
| `botcake_send_requests` | 1.5M resumed tickets of two days (both kinds), then 3,600 pending + 50 sending sequence tickets of the huge page, then 30 pending per normal page | |

Indexes exactly as the migrations make them (checked with `information_schema.statistics`).

**One ticket through today's path (base 528326cc), warm, per query** (ms; huge page vs normal page; `EXPLAIN ANALYZE` rows):

| step | query | index used | rows read, huge / normal | ms, huge / normal |
|---|---|---|---|---|
| claim | candidates (one per round) | `status_priority_id` lookup, then `page_id % 8`, `next_at`, page filters per row | until `limit` filled; **4,041** (the whole pending backlog of all shares) when the big page's lane is full; **3,656** for a normal page whose tickets sit behind the big page's backlog | 5.6 / 4.8 |
| claim | `UPDATE … WHERE id = ? AND status = 'pending'` | PRIMARY | 1 | 0.8 / 0.9 |
| claim | `SELECT * … WHERE id = ?` | PRIMARY | 1 | 0.4 / 0.4 |
| issue | replyOpen | `customer_status_id` covering range | 0 | 0.35 / 0.38 |
| sendCheck | subscription | PRIMARY | 1 | 0.46 / 0.29 |
| sendCheck | all customer values | `page_id_psid_key_unique` | 3 / 3 | 0.43 / 0.42 |
| sendCheck | unreachable mark | unique, const | 0–1 | 0.23 / 0.30 |
| sendCheck | **latest chat** | **`page_id_psid_received_at`** ref, then sort by id | **every chat of the customer: 206 for a long history, 6 for a short one** | 1.3 (cold: avg 2.9, max 50.5) / 1.1 |
| issue | `calledSequence` (EXISTS subscription) | PRIMARY + `send_request_id` | 1 | 1.0 / 0.7 |
| finish | ticket `UPDATE` | PRIMARY | 1 | 1.2 / 1.0 |
| finish | `chatbot_replies` INSERT (10 secondary indexes) | | | 1.5 / 0.9 |

That is **~10.4 blocking round trips per ticket** (301's count), each one cheap on this machine. Everything except two queries reads a bounded number of rows.

**Named slow queries (rows read grow with history or backlog):**

1. **The latest-chat check of `sendCheck`** (also in `runStep`): `select * from botcake_chats where <not standby> and page_id = ? and psid = ? order by id desc limit 1`. MySQL picks `(page_id, psid, received_at)` (tie in cost with `(page_id, psid, id)`, the optimizer trace shows `index_provides_order: false`, `plan_changed: false`), reads **every chat the customer ever had on the page** (each a row lookup for the JSON standby filter, `select *` with payload) and sorts them. It grows with the customer's history, which is longest on the biggest pages. EXPLAIN ANALYZE, a 206-chat customer:
   ```
   -> Limit: 1 row(s)  (cost=225 rows=1) (actual time=0.345..0.345 rows=1 loops=1)
       -> Sort: botcake_chats.id DESC, limit input to 1 row(s) per chunk  (cost=225 rows=206)
           -> Filter: (json_extract(payload,'$."_standby"') is null or ...)  (actual rows=206)
               -> Index lookup on botcake_chats using botcake_chats_page_id_psid_received_at_index (page_id=..., psid=...)  (actual rows=206)
   ```
   With `USE INDEX (botcake_chats_page_psid_id_index)`, the same customer:
   ```
   -> Limit: 1 row(s)  (actual time=0.0597..0.0598 rows=1 loops=1)
       -> Filter: (json_extract(payload,'$."_standby"') is null or ...)  (actual rows=1)
           -> Index lookup on botcake_chats using botcake_chats_page_psid_id_index (page_id=..., psid=...) (reverse)  (actual rows=1)
   ```
   Timing, 40 fresh customers each after a cold restart of MySQL: long histories avg 2.94 ms / max 50.5 ms today vs 0.85 / 7.6 ms with the index; short histories 1.25 / 9.6 vs 0.81 / 7.4. On this laptop the table sits in memory; on a server where `botcake_chats` (up to ~1M chats a day, kept 90 days) does not, each of those rows is a random read.
2. **The claim's candidate read** (`BotcakeSendQueue::claim`, every 0.2 s in every process): it walks `(status, priority, id)` over the pending sequence tickets and filters `page_id % N`, `next_at`, `page_name NOT IN (full pages)` per row after a row lookup. When the big page's lane is full, it reads the **whole pending backlog of all shares** (4,041 rows here, 5.6 ms) to find the few tickets of the other pages. It grows with the backlog, not with history. Not changed by this spec (see Rulings 4).

**What actually limits a big page (found with the real-time bench, below):** not one slow query but the count of blocking round trips times the round trip, combined with two rules of the pool loop: (a) a Botcake lane sends at most one call per pass and a round has two passes, so a page's rate is capped at 2 ÷ (the round time of everything its process does); (b) the claim's candidate list fills with the big page's oldest backlog whenever its lane has one free place, which starves the other pages of that process. At +9 ms per query the base gives the big page **3.67 steps/s** (production: 1.9), and at +0/+3 ms the normal pages of the same process get **0 sends in 45 s**.

**Tests for BP-2** (in `tests/Feature/Chatblast/SequencePoolThroughputTest.php`, next to 301/303's):

- `test_BP_2_H_a_huge_page_sends_at_its_rate_beside_normal_pages_and_every_step_once_in_order` (the load test, below): big page ≥ 8 and ≤ 10 steps/s; every normal-page step due in the window sent, due → called avg < 1 s, max < 3 s; drained: every step once, the 20 two-ticket customers in order, every page's calls ≥ 1 / rate_per_page apart, one process per page.
- `test_BP_2_the_check_before_a_send_is_one_query_with_the_same_answers`: one DB query for `sendCheck($row, true)`; `reply_open` first (before `bot_paused`); without `$replyOpen` the old answer; unreachable mark (and an expired one does not stop the send); a newer standby chat is ignored (`outside_24h`), a newer moved `__last_message_at` counts; `not_holder`, `held`.
- `test_BP_2_the_last_chat_is_read_newest_first_on_the_page_psid_id_index`: no hint without the index (the query runs as before); with the 2026_10_24 migration the hint is `USE INDEX`, compiled for MySQL as ``from `botcake_chats` use index (botcake_chats_page_psid_id_index)``; a 300-chat customer's check uses the newest real chat.
- `test_BP_2_one_claim_round_is_a_few_queries_and_a_long_backlog_does_not_hide_other_pages`: 300 big-page tickets ahead of 3 of other pages, the big lane with room for one: one round claims 1 + 3 in 5 queries (2 candidate reads, 2 updates, 1 read); a ticket taken by another process in between is not returned; nothing claimed twice.

Runs (Herd PHP 8.4.16):

| run | tests | result |
|---|---|---|
| `--filter BP_2 SequencePoolThroughputTest.php` | 4 | OK |
| filter `Sequence\|SendPool\|SendQueue\|ReplyPool\|Pool\|Sweep\|CustomerReach\|GatewayRoute\|LaneTickets\|BotRepliesBeforeBlasts\|BotcakeAsyncSend` (all of 301's and 303's pool tests, at `3dcb9344`) | 358 | OK (1 skipped, as at 301/303) |
| full suite, branch at `31650075` (`-d memory_limit=4G`) | 5,865 | 3 failures, 6 skipped, 0 errors |
| the 3 failing tests on base 528326cc code (the four app files and the throughput test checked out from 528326cc, then restored) | 4 | the same 3 failures |

The 3 failures are the base failures that 301, 303 and 304 listed: `ExampleTest::test_the_application_returns_a_successful_response`, `Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`, and `OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_…` with `/owner/private`. **No new failures.**

The local MySQL (port 3415) was stopped and its data folder deleted after the measurements. It lived in the session scratchpad, never in the repo.

### 2. Before/after steps/s for the huge page; normal pages

**Load test in the repo** (sqlite, virtual time, 301's cost model: every query one production round trip; the clock moves during each query): one process holding the big page (1,200 due tickets, 20 chats per customer, 20 customers with two tickets) and 14 normal pages getting 4 new due steps a second for 60 s; Botcake answers 3 s after each call; lane cap row 50; rate 10/s. Big page steps/s in seconds 10–60; normal pages: due → called. "Before" = the same test with the four app files of base 528326cc checked out (then restored).

| per query | big page before | big page after | normal pages before (due → called avg / max) | after |
|---|---|---|---|---|
| 9 ms (301's calibration) | 4.60 | **8.14** | 1.20 s / 3.49 s | **0.19 s / 0.34 s** |
| 3 ms (`SEQ_QUERY_MS=3`) | 7.70 | **9.42** | 5.04 s / 20.49 s | **0.12 s / 0.24 s** |

Queries per ticket: 10.5 before, 5.2 after (claim 1.15, check 1, called 1, finish 2). 301's profile fixture (one pool, 120 pages, 250 slots, Botcake 5 s): 10.6 → 23.5 tickets/s at 9 ms, 10.2 → 4.4 queries per ticket.

**Real-time load test on the seeded MySQL** (bench script outside the repo): the real `SequenceSendPool` (one process, all 16 pages) in real time against the seeded copy above, a fake Botcake answering 3 s after each call, lane cap 50, rate 10, the 3,600-ticket backlog of the big page, the normal pages getting 4 new due steps a second; an added delay per query (a busy wait) stands in for the production round trip. 45 s, measured from second 15.

| added per query | big page before | big page after | normal pages before | normal pages after |
|---|---|---|---|---|
| +9 ms | 3.67 | **7.43** | 4.20/s (keeps up) | 4.00/s (keeps up) |
| +3 ms | 7.37 | **8.83** | **0.00/s (starved)** | 4.00/s |
| +0 ms | 9.03 | **9.03** | **0.00/s (starved)** | 4.00/s |

Queries per call: ~10.9 before, ~5.9 after. At +9 ms the real bench adds the real PHP and MySQL time on top of the 9 ms, so it is a little below the repo test (7.43 vs 8.14).

### 3. Pushed; merge danger

Branch pushed to `origin/feat/305-sequence-checks-big-pages` (see the report). Merge danger below.

## Story changes

- **BP-2 E (index build time):** no new index. The profile names the latest-chat check, and the index it needs, `botcake_chats (page_id, psid, id)`, already exists (migration `2026_10_24_100000_add_inbox_indexes`). So there is no build to estimate. The only thing to check is that the index exists on each site (Merge danger).
- **301's `test_SQ_1_profile_one_pool_as_today`** pinned the base calibration (10.7 ± 2 tickets/s, > 9 queries per ticket). With this change the same fixture gives 23.5 tickets/s at 4.4 queries per ticket, so it now asserts > 1.8 × 10.7 and < 5 queries per ticket (comment says why). Every other 301/303 test is unchanged and passes.
- The test harness gained a configurable Botcake latency and an opt-in "time moves during each query" mode (`timeFlows`); 301/303's tests run with the old mode.

## Rulings

1. **Not one slow query, so the fix is the per-ticket round trips and the loop's rules.** On the seeded copy every per-ticket query is an index lookup of a few rows except the latest-chat check (rows = the customer's whole chat history) and the claim's candidate read (rows = the pending backlog). The latest-chat check gets the index that bounds it. The rest of the cost is the count of blocking round trips (10.4 per ticket) and the two loop rules (one call per lane per pass; the candidate list filled by one page's backlog), and those are what the real-time bench shows limiting the big page. More processes cannot fix either rule inside one page's process, which fits "4 → 8 did not help".
2. **`USE INDEX`, not `FORCE INDEX`, and only when the index exists.** With only `(page_id, psid, id)` allowed, MySQL reads it backwards and stops at the first real chat (EXPLAIN above). The existing `SweepLimit::useIndex` helper checks the index once per process, so the other site or a database without the index runs today's query.
3. **The check is fresh at the call.** The read check runs at most 50 ms before the lane's turn (redone if older than 0.5 s), and the conditional "called" write (the subscription still active and holding this ticket) stays right before each call, so a customer who replied in between still stops the send. "Called" is never written ahead: a crash can never leave a ticket marked called long before its call.
4. **The claim's candidate read is left as it is.** It reads the pending backlog of all shares when the big lane is full (4,041 rows, 5.6 ms here, once per round per process). An index cannot serve the share filter (`page_id % N`) or the full-page exclusion, and the profile does not show it as the limit. It is one query per round, not per ticket. If the backlog ever reaches tens of thousands again, a per-share index (for example `status, priority, page_id, id` with the share's page IDs listed) would be the next step, with EXPLAIN first.
5. **Same rules as 301/303:** once-only send (the conditional claim UPDATE, "called" before the call, recovery without resend: unchanged), per-customer order (the candidate query's NOT EXISTS, unchanged; the 20 two-ticket customers in the load test), per-page rate (`BotcakeClient::slot` and `next = call time + interval`, unchanged; asserted on every page's call times), lane cap and gateway cap (`room()`, now counting this round's picks too).

## Merge danger

**What ships:** the sequence pool's per-ticket work drops from ~10 to ~5 blocking queries (one read for every check, a few queries per claim round), a page with a long backlog no longer hides the other pages of its process, a busy Botcake lane gets its turn as soon as its interval passes, and the latest-chat check reads one row through `(page_id, psid, id)`. `runStep` (the sequence job) uses the same latest-chat read. No migration, no new table, no settings, no `.env`, no `config:cache`, no supervisor change. The reply pool, chatblast and `botcake:send-pool` run the same SQL as before (`claim()` unchanged; only its candidate query moved into `candidates()`).

**Check on each site before or after deploy (read-only):**

```
SHOW INDEX FROM botcake_chats WHERE Key_name = 'botcake_chats_page_psid_id_index';
```

It should list `page_id, psid, id`. If it is missing on a site, the code still works (no hint, today's plan), and nothing breaks; adding it there is the 2026_10_24 migration, a long ALTER on a big `botcake_chats`, at a quiet hour, as spec 074 said.

**What to watch after deploy:** `chatbot:sequence-lateness` and `botcake_send_requests` in `sending` per big page (should reach the lane cap 50 in busy hours, with steps/s near the page's rate); no Botcake 429s (the per-page rate rule is unchanged, but the big pages will now actually use it); DB load from the pool should fall (about half the queries per send).

**Cherry-pick** (Incepxion's line, after 301's and 303's commits): `3dcb9344`, `31650075`. They touch `ChatbotSequences.php` (`sendCheck`, `lastChat`, `lastMessageAt`, `runStep`'s latest-chat line), `CustomerReach.php`, `BotcakeSendQueue.php` (`claim` split, `claimSequence`), `SequenceSendPool.php` (`tick`, `room`, `lanes`, `issue`) and the throughput test. If Incepxion's `sendCheck` or `issue()` differs from Likha's, the conflict is in those two methods.

**Deploy note:** a running `chatbot:sequence-pool` process keeps the code it started with until it exits (`--max-time` 3600 s, then supervisor starts it again). To use the new code at once, restart that supervisor program after the deploy (a clean stop drains its calls, `stopwaitsecs` 70).

**Roll back:** revert `31650075` and `3dcb9344` (or deploy the previous commit) and restart the `chatbot:sequence-pool` program the same way. Nothing in the database or settings changes, so there is nothing else to undo.
