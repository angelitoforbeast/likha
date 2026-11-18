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

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
