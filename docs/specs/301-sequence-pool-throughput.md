# 301 — Sequence sends keep up on a busy site, and the speed chart shows the real delay

## Why
On the busy site, sequence steps reach customers 15–50 minutes after they are due, all day; staff noticed a first step meant for 5 minutes arriving after 15–30. Measured on 2026-11-18 (read-only, production):

- `chatbot:sequence-lateness`: average lateness per due hour 10–50 minutes (00:00 42 min, 11:00 22 min, 14:00 21 min), max up to 2 h 10 min; at 16:00, ~29,000 steps due but unsent and ~32,000 waiting in the pool. The smaller site (same code, ~12k steps an hour) is 5–11 s late.
- 3,000 latest sequence tickets in `botcake_send_requests` (16:11–16:16): created → claimed avg **1,300 s** (p90 1,345), claimed → called avg 3.9 s (p90 9, max 43), called → done avg 4.8 s (p90 8), and Meta confirms 1.4 s after the push. Throughput ≈ 10.7 tickets/s (≈ 38–40k an hour), below the due rate in busy hours, so the queue grows all day.
- `chatbot:sequence-pool` runs as **one supervisor process** (it refuses to start beside another live pool), `services.botcake.sequence_concurrency` = 250, `rate_per_page` = 10, lane cap 30; 122 Botcake tokens (one lane per page; the busiest page needs ~1.5 sends/s). Five samples of tickets in `sending`: 50, 40, 191, 201, 170, many of them not yet called. Server load 2 of 8 cores; the send to Meta and Botcake's answer are not the limit. The limit is inside the pool process: it does not keep its 250 slots full.

On `/chatbot/speed`, "Sequences by hour due" charts only **pushed → sent at Meta** (1–2 s), so the chart looks healthy while the real delay (scheduled → pushed, avg 1,139.7 s that day) is only in a line of text.

## Stories and cases
**SQ-1 Find the limit (P1).** Before changing anything, profile the pool on a realistic load with fakes (thousands of due tickets over ~120 pages, a fake Botcake that answers in ~5 s): measure where a ticket's time goes (claim, the pre-send checks such as ChatbotSequences::sendCheck, DB reads and writes per ticket, the event loop) and how many slots stay filled. Record the numbers in the result and name the limit with evidence.

**SQ-2 Keep up (P1).** Change the pool so the busy site sends at least **2.5× today's rate** (target ≥ 27 tickets/s ≈ 100k an hour) with the same per-page rules: the per-token Botcake rate (`rate_per_page`, shared with the bot's replies via BotcakeClient::slot), the lane cap, the gateway per-page cap, 429 handling, the order of steps per customer (never two steps to one customer at once, never out of order), the checks before a send, and "recorded before the call goes out" (no double send after a crash). Pick the design from SQ-1's evidence, for example removing the per-ticket blocking work from the loop, batching the DB reads and writes, or several pool processes each owning a fixed share of the pages (then no two processes may ever hold the same page or customer). Settings live in config/env with today's behaviour as the default where it matters to the smaller site.
- H: the load test above sends ≥ 2.5× the base rate, slots stay near full, and every step still goes out once.
- N: a crash or a stop mid-run sends nothing twice (test), as today.
- E: two processes (if you choose them) never claim the same ticket, page or customer (test).
- E: the smaller site's behaviour and the bot's first replies (botcake:send-pool / reply-pool) are not slowed (state how you checked).

**SQ-3 The chart shows the real delay (P1).** In "Sequences by hour due" on `/chatbot/speed`, the main chart shows **due → sent** per due hour (average and p90; due → sent at Meta when confirmed, otherwise due → pushed, say which line is which), with the previous day dotted, as the other charts do. Pushed → sent at Meta stays available (a second line, a toggle or the table). The summary line keeps its numbers.
- H: on seeded data where steps go out 20 minutes late, the chart's average for that hour is ~1,200 s, not 1–2 s.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Fix the sending rate first, inside the existing pool and ticket table | The delay is time waiting in our queue, not Botcake or Meta |
| Keep every per-page limit and the one-step-at-a-time-per-customer rule | Botcake limits and customer order must not change |
| Chart the time from due to sent | That is the delay staff and customers feel |

## Constraints
- No new table. A migration only for an index the profiling proves is needed (EXPLAIN in the result).
- If more processes are needed, say exactly what the supervisor program needs (numprocs, command) for the reviewer to apply; don't add config files for servers.
- Keep every comment in the language and style of the file around it.
- Tests in the existing chatbot pool and speed test areas. The full suite adds no new failures compared with base 2940b58b (list the base failures).
- Keep the change easy to cherry-pick onto the other site's line.

## Threat model and risk
Trusted: our own queue rows. Risk: a double send or out-of-order steps to customers, a page exceeding Botcake's rate (429s, page paused), the bot's first replies slowed. Risk tier: medium-high (production messaging at volume); tests must pin no double sends and per-customer order.

## Allowed commands
composer install; vendor/bin/phpunit and php artisan test (with filters) via Herd PHP; php artisan (local, with fakes) for load tests; php artisan route:list; php artisan view:cache; node (if the speed page script needs a test run); git add, git commit, git push -u origin feat/301-sequence-pool-throughput.

## Done when
1. SQ-1's profile numbers and the named limit are in the result.
2. A load test with fakes shows ≥ 2.5× the base rate with slots near full; tests pin once-only sends after a crash, per-customer order, per-page rate and (if used) no shared page between processes. The full suite adds no new failures compared with base 2940b58b (list them).
3. The speed chart shows due → sent per due hour on seeded data (HTML or Node-rendered evidence).
4. The branch is pushed; the result has a merge-danger section: what ships, the settings and supervisor changes the reviewer must make on each site, how to roll back.

## Out of scope
Why some sequence sends fail (separate check); the bot's reply pool; Botcake's own limits; deploy and server changes (the reviewer does them).

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and report.
