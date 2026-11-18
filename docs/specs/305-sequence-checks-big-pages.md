# 305 — Sequence sends keep up on the biggest pages

## Why
After 301 (8 shard processes) and 303 (lane cap 50), the busy site sends sequences on time for most pages, but two pages with a huge history fall behind (2026-11-18, ~21:00 Manila): **Home & Handy Mart** — 995 new sequence tickets in 5 minutes (3.3/s) but only 563 sent (1.9/s), backlog 3,653 and growing; **Jana Herrera** — 473 new, 354 sent. Facts measured on production: the page's Botcake rate is not the limit (all sends on that page 2.4/s against 10/s; zero 429s); ~41–47 tickets in `sending`; Botcake answers in ~2–4 s; server load 3–5 of 8; more processes (4 → 8) did not help these pages. So each ticket of these pages spends ~15–20 s between claim and finish outside the Botcake call. Most likely (not proven): the per-ticket pre-send checks (`ChatbotSequences::sendCheck` and the other queries in SequenceSendPool before the call, plus the finish/resume writes) are slow on pages whose `chatbot_replies` / chats / customer-values history is huge, and they block that page's shard process loop.

## Stories and cases
**BP-1 Find the slow step (P1).** Reproduce with a seeded page that has a huge history (millions of `chatbot_replies` rows and a big chats table for one page, plus normal pages) and profile one ticket's path: each query with its time and EXPLAIN (rows examined, index used), in the result. Name the slow queries.

**BP-2 Fix them (P1).** Make every per-ticket query on such a page use an index range that does not grow with the page's history (an index that fits, a rewritten query, or a batched read per claim round). Target: a huge-history page sends at its lane cap / Botcake rate (≥ 8 steps/s with Botcake at 3 s, cap 50) in the load test, and normal pages are not slower. Same rules as 301/303: once-only sends, per-customer order, per-page rate.
- H: the load test with the huge page: before vs after steps/s for that page, in the result.
- E: a new index on a big table is built online in MySQL 8 (ALGORITHM=INPLACE, LOCK=NONE); state its build time estimate per million rows and that the reviewer runs it off-peak.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Fix the per-ticket cost, not add more processes | More processes did not help these pages |
| Keep every per-page and per-customer rule | Botcake limits and customer order must not change |

## Constraints
- A migration only for an index the profile proves (EXPLAIN before/after in the result). Keep every comment in the language and style of the file around it. Tests next to 301/303's pool tests; the full suite adds no new failures compared with base 528326cc (list them). Keep it easy to cherry-pick onto the other site's line.

## Allowed commands
vendor/bin/phpunit (with filters) via Herd PHP; php artisan (local, with fakes); a local MySQL on a non-3306 port with invented data for EXPLAIN and the load test (delete its data folder when done); git add, git commit, git push -u origin feat/305-sequence-checks-big-pages.

## Done when
1. BP-1's profile and the named slow queries in the result; tests for BP-2 pass; 301/303's tests pass; full suite no new failures.
2. Before/after steps/s for the huge page in the load test, and normal pages unchanged.
3. The branch is pushed; merge-danger section (any migration and how to run it safely on the big table, how to revert).

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and report.
