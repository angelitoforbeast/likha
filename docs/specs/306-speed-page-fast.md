# 306 — /chatbot/speed opens in under 2 seconds, and can never hold a worker for a minute

## Why
On the busy site, `GET /chatbot/speed` (the main page, `App\Services\Chatbot\ResponseSpeed`) takes 7–27 s when it works and 60 s (nginx 504) several times a day (2026-11-18: 08:56, 09:41, 13:18 UTC). It computes the day's reply times, volume, Forbeast/Botcake rows and charts live from `chatbot_replies` and the chats tables (millions of rows a day). The Sequences part (`/chatbot/speed/sequences`, its own endpoint since an earlier spec) answers in ~0.03 s and is not the problem. Each slow load holds one of the 20 staff PHP workers for up to a minute.

## Stories and cases
**SP-1 Find the slow parts (P1).** Profile the main page on a realistic seeded MySQL (a busy page day: ~1M replies, ~300k chats) and list each query/section with its time and EXPLAIN, in the result.

**SP-2 Fast page (P1).** The main page answers in **under 2 s** for today and any past day, with the same numbers it shows today (or the result explains each deliberate change). Choose from SP-1's evidence; the expected shape: the heavy per-hour aggregates are computed by a scheduled job every few minutes (today) and once for closed past days, stored in the cache or an existing table (no new table unless unavoidable; record), and the page reads them; the page shows "as of HH:MM" and refreshes itself like the Sequences part. If a day's figures are not ready yet, the page shows a short "computing, refresh in a minute" state instead of computing live. Filters the page has today (page, bot, date) keep working (state how each is served: precomputed per filter, or computed fast from the precomputed hourly rows).
- H: seeded busy day → page < 2 s, numbers equal the old code's on the same data (test pins equality on a smaller fixture).

**SP-3 Never a minute (P1).** Any live query the page still runs carries the 302-style time budget (session `max_execution_time`, reset in finally), and a timeout answers quickly with a clear message instead of a 504.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Precompute, don't compute on page load | The page reads millions of rows; a page view must not |
| Same numbers as today | Staff compare days |
| A slow query can never hold a worker for a minute | Shared workers |

## Constraints
- No new scheduled job heavier than today's page load, and none running more than every 5 minutes; say its cost. A migration only if unavoidable (ruling).
- Keep every comment in the language and style of the file around it. Tests in the existing speed test area; the full suite adds no new failures compared with base 528326cc (list them).
- Keep it easy to cherry-pick onto the other site's line.

## Allowed commands
vendor/bin/phpunit (with filters) via Herd PHP; php artisan (local) for the job; a local MySQL on a non-3306 port with invented data for EXPLAIN (delete its data folder when done); node if the page script needs a test; git add, git commit, git push -u origin feat/306-speed-page-fast.

## Done when
1. SP-1's profile in the result; tests for SP-2/SP-3 pass; full suite no new failures.
2. The result lists the scheduled job, its cost, cache keys, and what the reviewer must check on the server.
3. The branch is pushed; merge-danger section (what ships, how to revert).

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and report.
