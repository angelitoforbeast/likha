# 275 Replied backfill: same 48 hours, a fraction of the work

## Why
`chatbot:replied-backfill` (routes/console.php, `hourlyAt(7)->withoutOverlapping(30)`) calls `App\Services\Chatbot\ChatbotTags::backfill()`. It walks every `chatbot_replies` row from the last 48 h (`BACKFILL_HOURS`) with `replied_at` null, `kind` in (send, manual), `status` = sent, in `chunkById(1000)`, and for EACH row runs one `BotcakeChat::active()->whereNull('payload->_ad_open')` query for that customer's first chat after `sent_at` within `REPLY_HOURS` (24 h), then updates the row. On the larger site there are many hundreds of thousands of such rows each hour (mostly chatblast/sequence sends nobody answers), the run lasts over 35 minutes, and for the first ~5 minutes after :07 every hour the database load slows the bot's live replies to customers from ~1.2 s to 10–14 s on every page. On the smaller site the same scan covers about 562,000 rows.

In 48 h the larger site has ~469,000 chats from ~141,000 customers; the smaller ~205,000 chats from ~67,000 customers. Only a customer who chatted can have a replied send.

## Decisions already made (do not reopen)
| Decision | Reason |
|---|---|
| Keep the 48-hour window (`BACKFILL_HOURS = 48`) | The owner: the 48-hour backfill is still right. |
| Keep the schedule exactly: hourly at :07, `withoutOverlapping(30)` | The owner: don't move the job. |
| Result must be identical to today's backfill | It feeds the response rate and speed on Chatbot Results. |
| Plain reads, then updates by primary key only. No `UPDATE ... JOIN`/subquery over `botcake_chats`, no `CREATE TABLE AS SELECT`, no temp tables | A big locking statement on these live tables once blocked app inserts. |

## What to build
Rewrite `ChatbotTags::backfill()` (keep its signature, return value = rows filled, and the `self::ready()` guard) to start from customers instead of sends:
1. Read the distinct customers `(page_id, psid)` with an active, non-ad-open chat (same filters as today: `BotcakeChat::active()` and `whereNull('payload->' . ChatbotInbound::AD_OPEN)`) received in the last 48 h. Stream them in chunks (about 500–1000 customers per chunk; keyset or `lazy` style so memory stays flat).
2. Per chunk, with plain SELECTs:
   a. the candidate sends of those customers: `chatbot_replies` with `sent_at >= now - 48h`, `replied_at` null, `kind` in (send, manual), `status` = sent (columns id, page_id, psid, sent_at);
   b. those customers' qualifying chats received after the earliest candidate `sent_at` (page_id, psid, received_at), ordered by received_at.
3. In PHP, for each candidate send pick the first chat of the same page_id and psid with `received_at > sent_at` and `received_at <= sent_at + REPLY_HOURS`, exactly like today's per-row query. Skip sends with none.
4. Update only the matched sends, by id, keeping `->whereNull('replied_at')`, setting `replied_at` and `reply_seconds` exactly as today (`max(0, received - sent)` seconds). Batching the updates is fine as long as each statement touches rows by primary key only.

Watch the column types: `chatbot_replies.page_id` and `botcake_chats.page_id` / `psid` may differ (today's code casts the page id to string for the chats query). Make every WHERE ... IN compare like types so the existing indexes are used: `botcake_chats (page_id, psid, received_at)` and `(received_at)`; `chatbot_replies (page_id, psid, sent_at)`. Read the migrations to confirm the types, and run `EXPLAIN` on your queries in the test database to show the index is used. Put the EXPLAIN output in the result.

Leave the live path alone (`MarkChatbotReplied` / the other method above `backfill()` in ChatbotTags). Change nothing else.

## Done when
- `php artisan test --filter=ChatbotTags` passes, and the full suite adds no new failures (run the full suite before and after your change and compare; report both).
- New tests in `tests/Feature/Chatblast/ChatbotTagsTest.php` (or next to it) prove the new backfill gives the same `replied_at`/`reply_seconds` as the old rule for: a chat after the send within 24 h (filled, earliest chat wins); a chat after 24 h (not filled); a chat before the send (not filled); an ad-open chat (not filled); a standby (non-active) chat (not filled); the same psid on another page (not filled); a row already replied (unchanged); kinds other than send/manual and status other than sent (untouched); a send older than 48 h (untouched); two sends for one customer before one chat (both filled, as today).
- An equivalence test: seed a few hundred random sends and chats, run the OLD algorithm (copy it into the test as a reference) and the new one on identical copies, and assert identical results.
- A local timing comparison on seeded data (e.g. 50,000 unreplied sends, 5,000 customers who chatted): old vs new runtime and query count, in the result.
- `git log` on your branch shows conventional commits with no attribution lines.

## Out of scope
Schedule, window, the live replied job, any other command, migrations or new indexes (if you believe an index is truly needed, stop and say why in the report instead of adding it).

## Allowed
Any local command in your worktree: composer/php artisan/test, git add/commit on `fix/275-backfill-fast`. No network deploys, no ssh, no push, no gh, no `.env` reading. Budget: medium job; at most 2 attempts at any failing step, then stop and report what you tried.

## Report back
In your final message: the commit hashes, the before/after full-suite counts, the equivalence test result, the local timing numbers, the EXPLAIN summary, and anything you were unsure about.
