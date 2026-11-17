# 290 result: chatbot clean-up queries that stay fast on big tables

Base: live db017387. Branch `fix/290-chatbot-sweeps-fast`.

## Investigation

### Does the pool's sweep block claiming? Yes, confirmed.
- `TicketPool::run()` (app/Services/Chatbot/TicketPool.php:126-152) is one PHP loop: `tick()` (the claim, :135), the heartbeat every 2 s (:136-139), and every 5 s `sweep()` (:140-148) called inline. While `sweep()` runs nothing else in the loop runs: no claim, no `pump()` of answers already in flight, no heartbeat, and the stop flag is only read at the top of the loop (:132), so the pool cannot even exit cleanly.
- The AI pool's `sweep()` is `ChatbotAiQueue::sweep()` (ChatbotAiPool.php:54-57). Its first statement is query 2 of the spec (ChatbotAiQueue.php:204). The send and reply pools run `BotcakeSendQueue::sweep()` (BotcakeSendPool.php:81-84; `BotcakeReplyPool extends BotcakeSendPool`, BotcakeReplyPool.php:11), whose second query is query 1 (BotcakeSendQueue.php:296). The sequence pool runs `sweepSequence()` (SequenceSendPool.php:118-121).
- Why exactly the rows created just after a (re)start were stranded: a new pool runs `start()` (TicketPool.php:74-83: heartbeat, `recover()`), then the first loop pass claims what is pending (:135), writes the heartbeat (:141) and enters the sweep (:143), which hung for 30 min to 1 h. The heartbeat counts as alive for `BEAT_FRESH_SECONDS` = 10 s (:22, `alive()` :66-71). For those 10 s `ChatbotAiQueue::async()` (ChatbotAiQueue.php:29-33) still hands new AI steps to the queue as `pending` rows. Nobody claims them (the loop is stuck), and nobody fails them at the deadline: the pool's own sweep is the stuck query, and the minute command `chatbot:resume-skipped` runs the same `ChatbotAiQueue::sweep()` first (ResumeSkippedChatbotWaits.php:29), so it hung on the same query too. After the 10 s the heartbeat is stale and new AI steps fall back to the waiting path, so only the rows of that window stay stuck. That matches "created right after a (re)start".
- Why the chat stays locked: `ChatbotAiQueue::pending()` (ChatbotAiQueue.php:106-120) is true while the customer's `__ai` row is not `resumed`, and `ChatbotRunner` returns `'locked'` (ChatbotRunner.php:43). The lock ends only when the row is failed and resumed, i.e. when a sweep finally gets through the deadline query.
- Why the queries piled up: `chatbot:resume-skipped` is scheduled every minute with `withoutOverlapping(5)` (routes/console.php:90-92). The mutex expires after 5 minutes while the command is still running, so a new copy starts every 5 minutes, each with the same slow SELECTs. A pool killed or restarted by the supervisor also leaves its SELECT running on the server (MySQL keeps executing a SELECT until it has rows to send), and the new pool starts another one.

### Why the queries are slow
All three slow sweep queries filter on a few columns and then `ORDER BY id LIMIT n`. When the optimizer thinks many rows match, it prefers walking the primary key in id order and stopping at the n-th match. The rows that really match are few and new (the end of the table), so it reads the whole table. The fix is to give every query an index that serves the filter and the order together, or to name the index so that the primary key cannot be chosen for the order.

Existing indexes (from the migrations; no new index is needed):
- `chatbot_ai_requests` (2026_10_28_100000, 2026_11_10_100000, 2026_11_15_100000): `request_id` unique; `(status, next_at)` = `chatbot_ai_requests_status_next_at_index`; `(page_id, psid)`; `created_at` = `chatbot_ai_requests_created_at_index`; `(page_name, created_at)`; `(usage_status, usage_gateway, id)`; `(key_id, created_at)`.
- `botcake_send_requests` (2026_10_29_100000, 2026_11_22_100000 / spec 251): `request_id` unique; `(status, priority, id)` = `botcake_send_requests_status_priority_id_index`; `(status, next_at)`; `(customer, status, id)`; `created_at` = `botcake_send_requests_created_at_index`; `(kind, status, updated_at)` = `botcake_send_requests_kind_status_updated_at_idx`.
- `chatbot_replies` (2026_10_23_100000, 2026_10_24_100000, 2026_10_30_100000, 2026_11_06_100000, 2026_11_11_100000): `(page_id, sent_at)` = `chatbot_replies_page_id_sent_at_index`; `psid`; `sent_at`; `(page_id, psid, id)`; `(flow_id, sent_at)`; `(sequence_id, sent_at)`; `(page_id, psid, sent_at)`; `(chat_id, kind, status, sent_at)`; `client_ref`; `(via, confirmed_status, sent_at)`.

Per query (today):
- Q1 `BotcakeSendQueue::sweep()` redispatch (BotcakeSendQueue.php:296): `kind = 'chatbot' and status in ('done','failed') and updated_at <= ? order by id limit 500`. The spec 251 index `(kind, status, updated_at)` serves the filter, but with `status IN (2 values)` it cannot return rows in id order, so the optimizer may walk the primary key instead (the 4.1M-row scan seen). If the second site never ran migration 2026_11_22, it has no usable index at all.
- Q2 `ChatbotAiQueue::sweep()` (ChatbotAiQueue.php:204, 216, 228): `status in ('pending','sending') and deadline_at <= ?`, `status = 'encode' and deadline_at <= ?`, `status in ('answered','failed') and updated_at <= ?`, each `order by id limit 500`. `(status, next_at)` covers status, but the id order again invites the primary-key walk over 2.8M rows. These statuses only hold in-flight work (a few hundred rows at most), so reading them all through `(status, next_at)` and sorting is cheap.
- The prune DELETEs in both sweeps (ChatbotAiQueue.php:237, 243, 249; BotcakeSendQueue.php:302) have the same `order by id limit 5000` shape. ChatbotAiQueue.php:243 (failed/answered older than 2 days, with the 90-day record kept) has almost no matches among 88 days of `resumed` rows, so it reads the table on every sweep. A DELETE also cannot carry a time limit.
- ChatbotAiQueue.php:246 (clearing the customer text 2 days after) already reads `created_at` in order, but once the texts are cleared it finds nothing and reads every row of its 5-day window on each sweep (about 5 days of rows, roughly 700k on the bigger site going by 2.8M rows since 2026-10-28).
- `sweepSequence()` (BotcakeSendQueue.php:524, 534, 536): `status = ? and priority = 3` matches the `(status, priority, id)` prefix, so it is already in id order. It is pinned with the index name and the time limit only.
- Claims: `ChatbotAiQueue::claim()` (ChatbotAiQueue.php:136-137) has the same shape (`status = 'pending' ... order by id limit n`, every tick) and is pinned to `(status, next_at)`. `BotcakeSendQueue::claim()` orders by `priority, id`, which `(status, priority, id)` returns in index order, so it is left unchanged.
- Q3 `BotHealth::inbound()` (BotHealth.php:575-577): `select distinct page_id ... kind = 'send' and status = 'sent' and sent_at in [week]` reads every reply row of the week (`sent_at` or `(page_id, sent_at)` range, or the whole table), only to keep the pages that also have a flow span.

## Plan

### SW-2 Time limit (new `App\Services\Chatbot\SweepLimit`)
- One limit per sweep call: `queue.pools.sweep_ms` (env `CHATBOT_SWEEP_MS`, default 10000, clamped to 100..60000). Each SELECT of the sweep is built through `SweepLimit::cap()`. On MySQL it gets `/*+ MAX_EXECUTION_TIME(ms) */` with the milliseconds still left of the sweep's limit; on other drivers (SQLite in tests) nothing is added. If the limit is already used up before a SELECT, the sweep stops there.
- Every DELETE becomes "SELECT the ids (capped, indexed) → DELETE by primary key", so nothing in a sweep reads the table without the limit.
- A timeout (MySQL 3024, MariaDB 1969, or the limit used up) ends the sweep with one `Log::warning` line and returns the counts so far. The next 5-s tick (or the next minute) tries again. Any other error is thrown as today (the pool reports it, TicketPool.php:144-146).
- So a sweep holds the pool for at most about the limit, never an hour, and a MySQL query can never outlive 10 s on the server (no pile-up even when the PHP side dies).
- `chatbot:resume-skipped`: its two sweeps are capped and its `chatbot_waits` loop is capped at 50 s, with each batch SELECT also limited, so a run ends well inside the 5-minute `withoutOverlapping` mutex. That is what makes it unable to overlap itself (the overlap came only from runs longer than the mutex). A test pins this invariant (2 × limit + 50 s < mutex).

### SW-1 Queries (same rows, same status and time rules; only order and index use change)
- `ChatbotAiQueue::sweep()` late, encode-late and redispatch: `USE INDEX (chatbot_ai_requests_status_next_at_index)`, still `order by id limit 500` (a sort over the in-flight rows only).
- AI prune (no record yet): ids by `created_at < 2 days` through `created_at`, ordered by `created_at`, 5000, then delete by id.
- AI prune failed/answered after 2 days (record): ids through `(status, next_at)`, order by id, 5000, then delete by id.
- AI text clearing: same rule (resumed/sync, 2 to 7 days old, a text still set), but read in one-hour slices of `created_at` from a cursor kept in the cache (`chatbot-ai-sweep:texts-from`). A slice that ends without 5000 matches moves the cursor to its end. The steady state reads only the few seconds that crossed the 2-day mark since the last sweep. With no cursor (first run, cache cleared) it starts 7 days back and catches up one hour per sweep.
- AI prune of the record after 90 days: ids through `created_at`, ordered by `created_at`, 5000, then delete by id.
- `ChatbotAiQueue::claim()`: `USE INDEX (chatbot_ai_requests_status_next_at_index)`, same filter and order.
- `BotcakeSendQueue::sweep()`: the dead-pool recover SELECT and the late SELECT (already on `(status, priority, id)`) get the limit. Redispatch Q1: one query per status, `kind = 'chatbot' and status = ? and updated_at <= ? order by updated_at, id limit 500` on `(kind, status, updated_at)`, which reads at most 500 index entries per status. Then the candidates of both are merged, the 500 lowest ids are dispatched in id order (the same rows and order as today whenever 500 or fewer are waiting). Prune: ids through `created_at`, ordered by `created_at`, 5000, delete by id.
- `sweepSequence()`: the same queries with `USE INDEX` and the limit.

### SW-3 Restart
No loop change is needed once the sweep is bounded. The first pass of `run()` claims first (TicketPool.php:135) and sweeps right after (`$sweepAt = 0`, :140), so rows past their deadline at start are failed and resumed in the first pass, and a timed-out sweep returns within the limit. Tests run a fresh pool's `run()` for one pass: 5 late rows are failed and resumed (customers unlocked), and a row not yet late is claimed.

### SW-4 BotHealth
`inbound()` checks the flow-span pages (filtered by `$pages`) one by one: `exists` on `page_id = ? and sent_at >= ? and sent_at < ? and kind = 'send' and status = 'sent'` through `(page_id, sent_at)`. The answer is found in the first rows of a page that had replies; only a page with no sent reply reads its own week. There are a few dozen pages, not millions of rows, and the result is the same set.

### Tests
New `tests/Feature/Chatblast/SweepFastTest.php` (SW-1 selection rules and the MySQL SQL of each changed query, SW-2 timeout and limit, SW-3 restart, the command invariant) and a BotHealth case. The spec 251 test `SendQueueSweepIndexTest` counts one redispatch query; it becomes two (one per status).

## What shipped

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
