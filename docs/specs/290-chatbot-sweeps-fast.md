# 290 — Chatbot clean-up queries that stay fast on big tables

## Why
On the bigger site (botcake_send_requests 4.1M rows, chatbot_ai_requests 2.8M, chatbot_replies 21.4M, chatbot_events 67M), three SELECTs ran for 1 to 2.7 hours on 2026-11-17 and piled up, one new copy every few minutes:
1. `select * from botcake_send_requests where kind = 'chatbot' and status in ('done','failed') and updated_at <= ? order by id asc limit 500` — BotcakeSendQueue::sweep(), the redispatch loop (about line 296). 25 copies were running, the oldest 4,756 s.
2. `select * from chatbot_ai_requests where status in ('pending','sending') and deadline_at <= ? ...` — ChatbotAiQueue::sweep() (about line 204), seen running 1,648 to 1,898 s. The same sweep has a second query for answered/failed rows past REDISPATCH_SECONDS (about line 228), also `order by id`.
3. `select distinct page_id from chatbot_replies where kind = 'send' and status = 'sent' and sent_at >= ? and sent_at < ?` over a week — BotHealth::inbound() (about line 575), running 9,794 s.

Likely shape of the problem: with `ORDER BY id LIMIT 500`, MySQL walks the primary key hoping to find matches early and ends up reading the whole table. The same tables are 3 to 6 times smaller on Likha, so Likha is fine today but will hit it as it grows.

What it broke: while the sweeps hung, AI requests created right after an AI worker (re)start were never claimed and never failed. The customer's chat stayed locked (ChatbotAiQueue keeps the customer's `__ai` marker while the row is pending; ChatbotRunner.php about line 43 returns 'locked'), so the customer got no reply. 101 of 625 chats in 18:55–18:59 went unanswered after a release; 99 more rows got stuck at 20:07 (the AI worker exited by itself and restarted) and 20:34. All 99 were failed and resumed by the app's own sweep at 20:56, once the long queries were killed. The owner's pages (e.g. /owner/private) were also slow while the database was busy. Hypothesis to confirm or refute in the investigation: the pool (TicketPool, about line 143) runs sweep() in its loop, so a sweep that takes an hour blocks the AI pool from claiming anything, and rows created just after a start expire unclaimed.

## Stories and cases
**SW-1 Clean-up queries use an index (P1).** Every query in ChatbotAiQueue::sweep(), BotcakeSendQueue::sweep() (including the sequence loop, about line 524) and ChatbotAiQueue/BotcakeSendQueue claim paths you find with the same `order by id` + range shape reads only about its LIMIT rows through an index, never the whole table.
- H: the same rows are picked as today (same status and time rules); only the order and index use change. Tests pin the selection rules.
- E: an index the query needs does not exist → add it in a migration (see Constraints for the big-table rule).
- Evidence: list the exact SQL each changed query emits (with `toRawSql()` or the query log in a test) and the index it is meant to use, so the reviewer can EXPLAIN it on the server.

**SW-2 A slow query can never pile up or block the pools (P1).** Each clean-up SELECT carries a MySQL time limit (the `/*+ MAX_EXECUTION_TIME(n) */` optimizer hint, about 10 s, configurable); when it hits the limit, the sweep logs one warning line and returns, and the next tick tries again. A sweep never blocks the pool from claiming new work for longer than that limit. The scheduled command that also sweeps (ResumeSkippedChatbotWaits) cannot overlap itself.
- H: a normal sweep runs as today.
- N: the query times out (simulate by throwing the MySQL timeout error from a fake) → warning logged, pool keeps claiming, no crash.
- On SQLite (tests) the hint must not break the query: apply it only on MySQL.

**SW-3 A restart never leaves chats locked (P1).** After the AI worker starts, its rows that were pending or sending past their deadline are failed and resumed (the same steps sweep() takes) within one tick, before or right after the first claim, and a slow sweep cannot delay the first claim (SW-2).
- H: 5 pending rows past deadline at start → all 5 failed and resumed on the first tick; their customers are no longer locked.
- N: a row not yet past its deadline → left alone and claimed normally.

**SW-4 BotHealth's answered-pages query (P1).** BotHealth::inbound() gets the set of pages that had a sent reply in the range without scanning the week of chatbot_replies: e.g. per page in flowSpans an EXISTS / LIMIT 1 query on an existing index, or another way that uses an index. Same result as today; test it.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Fix the queries in code; no timeout raise, no hardware change | The owner's rule: fix it in code |
| Same code for both sites (the second site gets this commit cherry-picked) | Both sites run this code |
| Behaviour stays the same: same rows picked, same statuses, same resume path | Only speed and safety change |

## Constraints
- Big-table rule for migrations: the second site's deploy runs `migrate` during the release. No new index on chatbot_replies or chatbot_events (21M and 67M rows). A new index on chatbot_ai_requests or botcake_send_requests is allowed if needed, created with `ALGORITHM=INPLACE, LOCK=NONE` on MySQL (raw statement guarded by driver; plain Schema on SQLite), and the result estimates its run time for 3–4M rows. Prefer changing the query to use an index that already exists (list the existing indexes from the migrations).
- Keep every comment in the language and style of the file around it.
- Feature/unit tests for each story; the full suite adds no new failures compared with base db017387 (list the base failures).

## Threat model and risk
No untrusted input involved; the risk is a wrong selection (rows skipped or picked twice) or a migration that locks a big table during a release. Risk tier: medium (live message flow on both sites).

## Allowed commands
composer install; php artisan test (with filters) and vendor/bin/phpunit via Herd PHP; php artisan route:list; git add, git commit, git push -u origin fix/290-chatbot-sweeps-fast.

## Done when
1. Tests for SW-1..SW-4 pass, including the timeout case (SW-2) and the restart case (SW-3); the full suite adds no new failures compared with base db017387 (list the base failures).
2. The result lists each changed query's SQL and the index it should use, plus any migration with its estimated run time on 3–4M rows.
3. The investigation section says whether the pool really runs sweep() in a way that blocks claiming, with file:line evidence.
4. The branch is pushed; the result has evidence and a merge-danger section (what ships, migrations, how to revert).

## Out of scope
Pruning old rows; any other slow page; server changes; deploy (the reviewer does it).

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and report.
