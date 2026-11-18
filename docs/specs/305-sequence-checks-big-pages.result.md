# 305 result: sequence sends keep up on the biggest pages

Base: live 528326cc. Branch `feat/305-sequence-checks-big-pages`.

## Plan

1. **BP-1 profile on MySQL first.** A local MySQL 8.0.42 on port 3415 (data folder in the session scratchpad, deleted at the end), the app's migrations run against it, invented data: one huge page (millions of `chatbot_replies`, a big `botcake_chats`, `chatbot_customer_values` and `chatbot_sequence_subscriptions` history, a `botcake_send_requests` table with two days of done/resumed tickets and a pending backlog of thousands on that page) plus normal pages. Tickets of the huge page and of a normal page go through the pool's real path (claim, take, issue: replyOpen, sendCheck, calledSequence; respond: finishSequence and the reply row), every query timed (`DB::listen`) and EXPLAINed (`EXPLAIN ANALYZE` for rows examined and the index used). The claim round (candidates query with the shard filter and full pages) is profiled the same way at production-like backlog. Bench script kept out of the repo; numbers and EXPLAIN in this file.
2. **Name the slow queries** from that evidence (per query: time, rows examined, index; huge vs normal page).
3. **BP-2 fix** only what the profile names: an index that fits, a rewritten query, or one batched read per claim round, keeping every per-ticket rule (once-only send recorded before the call, per-customer order, per-page rate, the checks before a send). A migration only for an index the EXPLAIN proves, built online (`ALGORITHM=INPLACE, LOCK=NONE`), with a build-time estimate per million rows.
4. **Load test** next to 301/303's in `SequencePoolThroughputTest`: a huge-history page beside normal pages, Botcake at 3 s, lane cap 50; per-query cost from the measured MySQL times (not one flat round trip) so the history-dependent cost shows; before vs after steps/s for the huge page, normal pages unchanged.
5. Tests: BP-2 tests, 301/303's pool tests, the sequence/pool filter, then the full suite vs base 528326cc (base failures listed).
6. Result, merge danger (migration, how to run it on the big table, revert), push.

## What shipped

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
