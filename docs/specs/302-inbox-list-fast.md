# 302 — Chatbot Inbox list: fast, never stacked, never able to block the bot

## Why
On 2026-11-18 the busy site answered 504 three times (15:57, 16:23, 16:51 Manila). Each time one browser had `/chatbot/inbox` open on the biggest page (652850684584967) with the 30-day window and the "Ordered" status filter. `GET /chatbot/inbox/conversations` (ChatbotInboxController::conversations → `App\Services\Chatbot\Inbox::listWithCounts`) took the full 60 s on every call, and the page asks again every 5 s (`setInterval(... loadList ..., POLL)` with POLL = 5000 in `resources/views/chatbot/inbox.blade.php`) whether or not the last call has answered. 47 such calls filled all 20 php-fpm workers, so everything else queued: 1,554 Botcake webhook calls (`POST /api/botcake/chat`, the customers' messages to the bot) took ~45 s each, delaying bot replies, and pages answered 504.

`listWithCounts` groups `botcake_chats` and `chatbot_replies` by psid over the whole window (`MAX(id)`, `MAX(received_at|sent_at)`, ORDER BY the max, LIMIT 500) and then runs the status sets (chatbot_events, chatbot_customer_values, last sends). On the biggest page, 30 days of `chatbot_replies` is millions of rows (the page sends ~5k sequence messages an hour alone).

## Stories and cases
**IL-1 The list answers fast (P1).** The conversation list for the biggest realistic page answers in **under 3 s** for every window (24h, 7d, 30d), tab, status and search, with the same rows, order, counts and total as today (or the result explains a deliberate change). Choose from the evidence (EXPLAIN on a realistic seeded MySQL is best; at least reason about the indexes on `chatbot_replies` and `botcake_chats`, listed in the result), for example: an index that serves "per page, per psid, latest" directly; reading the newest rows first and stopping once LIST_LIMIT conversations are found instead of grouping the whole window; a short shared cache (for example 15 s per page + filters) so many polls and many users cost one query.
- H: a seeded page with hundreds of thousands of replies over 30 days: the list and the Ordered filter answer within the target (state how measured; MySQL if you can, otherwise the query plan and row counts).
- E: today's results for small pages are unchanged (existing Inbox tests pass).

**IL-2 A slow query can never hold a worker for a minute (P1).** The list's queries on MySQL carry a statement time limit (for example `MAX_EXECUTION_TIME` on the SELECTs, about 8 s); past it the endpoint answers quickly with a clear "the list is taking too long, narrow the window" state that the page shows, instead of hanging. No other query in the app changes.
- H: a forced slow query (test double) → the endpoint answers within the limit with that state.

**IL-3 The page never stacks requests (P1).** The Inbox page sends the next list request only after the previous one has answered (or been aborted after a timeout of about 15 s); hidden tabs don't poll the list (as today); changing a filter aborts the pending request. The same for the page's other polling (`poll()`), if it can stack.
- H: a test (Node run of the page script, as other views' tests do, or a small JS harness) shows that a slow answer delays the next request instead of piling up.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| The list must be fast on the biggest page, not just limited | Staff use the 30-day and Ordered views |
| A slow admin request must never be able to hold a worker for a minute | The bot's webhooks share the same PHP workers |
| The page waits for its last answer before asking again | One open tab must never multiply load |

## Constraints
- A migration only for an index the evidence shows is needed (EXPLAIN in the result; say how long it would take on a big table and whether it is online in MySQL 8).
- Keep every comment in the language and style of the file around it.
- Feature tests in the existing Inbox test area. The full suite adds no new failures compared with base 94aed869 (list the base failures).
- Keep the change easy to cherry-pick onto the other site's line.

## Threat model and risk
Untrusted: the query strings (window, tab, status, q, filter conditions). Read-only page. Risk: a list that disagrees with today's, an index build that locks a busy table, a page that stops refreshing. Risk tier: medium (shared production database).

## Allowed commands
composer install; vendor/bin/phpunit and php artisan test (with filters) via Herd PHP; php artisan route:list; php artisan view:cache; node (to run the page script in tests); a local MySQL on a non-3306 port with invented data if you want real EXPLAIN plans (keep its data folder untracked and delete it when done); git add, git commit, git push -u origin feat/302-inbox-list-fast.

## Done when
1. Tests for IL-1..IL-3 pass; the existing Inbox tests pass (any changed assertion listed with its reason); the full suite adds no new failures compared with base 94aed869 (list them).
2. The result has the measured or planned query cost before and after (EXPLAIN or row counts), the exact SQL of the new list queries for the reviewer to EXPLAIN on the server, and any index with its build cost.
3. The branch is pushed; the result has a merge-danger section (what ships, any migration and how to run it safely on a busy table, how to revert).

## Out of scope
The php-fpm pool split for the bot's webhooks (the reviewer does it on the servers); other pages' polling; the Inbox's design; deploy.

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and report.
