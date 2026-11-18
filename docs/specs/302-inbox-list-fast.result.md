# 302 result: Chatbot Inbox list fast, never stacked, never able to block the bot

Base: live 94aed869. Branch `feat/302-inbox-list-fast`.

## Plan

Base 94aed869: full suite once with `vendor/bin/phpunit` under Herd PHP before any code change; base failures listed under Evidence. A local MySQL 8.0.42 on port 3407 (data folder in the session scratchpad, deleted at the end) with invented data the size of the biggest page: 3.6M replies in 30 days (5k an hour) plus 1.2M older, 600k incoming chats, 150k customers, 30k orders, 1M other events, 450k customer fields, plus two smaller pages.

Cause (from the code): `listWithCounts` asks each table for `GROUP BY psid ... ORDER BY MAX(at) DESC LIMIT 500` over the whole window. MySQL must read every row of the page in the window (30 days of replies on the big page = millions, each a row lookup for `kind` and `psid`), group them in a temporary table and sort the groups, before the LIMIT can apply. That is the minute.

IL-1, in `App\Services\Chatbot\Inbox` only (same rows, order, counts and total):
- "Newest first, stop early": the 500 customers with the latest activity are the first 500 distinct psids met when reading the page's rows newest first. Read the existing `(page_id, received_at)` / `(page_id, sent_at)` index backwards in chunks (keyset cursor on `(at, id)`, the same row filters as today: `kind`, `active()`, the search), collect new psids per chunk, stop at GROUP_LIMIT or at the start of the window. Filter-builder conditions are psid-level, so they are checked once per new psid (on one row id each) instead of on every row.
- Then the exact `MAX(id)`, `MAX(at)` of only those psids (the `(page_id, psid, at)` index), so the preview row and the times are the same as today's.
- Everything after that (pause lookup, status sets, last rows, names) is unchanged; it already works on at most 2 x 500 customers.
- No new index unless the EXPLAIN on the seeded MySQL shows the backward range scan is not used.

IL-2:
- The list build has a budget of 8 s. While it runs, the MySQL session's `max_execution_time` is set to the time left (so every SELECT of the list, including the status sets, stops on its own), and it is put back to the previous value in a `finally`; no other query changes. Between chunks the deadline is checked too. A timeout (MySQL 3024) or the deadline becomes `InboxTooSlow`.
- `GET /chatbot/inbox/conversations` answers that with 200 `{rows: [], counts: {}, total: 0, slow: true, error: "The list is taking too long. Pick a shorter time window or fewer filters."}`, logs one warning line, and the page shows the message in the list.
- Shared cache: the answer (page + window + tab + status + search + conditions) is kept 15 s, the slow answer 60 s, so many polls and many users cost one build. A pause or a manual send on the page clears that page's cached lists (a per-page version in the key), so the Human badge and "You:" preview show at once.

IL-3, in `resources/views/chatbot/inbox.blade.php`:
- A small list loop (`inboxListLoop`, between `// inbox-list-loop:start/end` markers so a Node test can run it): the next list request is scheduled only after the previous one has answered; each request is aborted after 15 s; a hidden tab skips the request and waits for the next turn; a filter change (or any other call to load the list now) aborts the pending request and starts a new one.
- `poll()` (the open conversation) already never stacks (`S.busy`); its request is now aborted after 15 s too so one hung call cannot stop it for a minute.

Tests: `tests/Feature/Chatblast/ChatbotInboxListFastTest.php` (sqlite: same rows and order as the old query on a mixed fixture with ties, search, tabs, statuses, conditions; chunk boundaries; slow double; cache and its clearing; the loop in Node). Existing Inbox tests unchanged unless listed. MySQL evidence with a bench script kept out of the repo (numbers and EXPLAIN in this file).

Order: plan commit; MySQL seed and EXPLAIN of today's queries; Inbox scan + tests; IL-2 + tests; page loop + Node test; full suite vs base; MySQL timings after; result; push.

## What shipped

**Cause.** On a busy page, MySQL answers today's `GROUP BY psid ... ORDER BY MAX(sent_at) DESC LIMIT 500` for the 7-day and 30-day windows with the plan `ref` on `chatbot_replies_page_psid_id_index (page_id)`: it reads **every row the page ever had** (all of `chatbot_replies` for the page, not only the window), one row lookup each for `sent_at` and `kind`, then a temporary table and a filesort. On the seeded copy (4.8M replies on the big page) that is 6 minutes per call; for 24 hours it picks the time index and answers in 0.7 s. The Ordered status does not change that query (it filters after it). In production each call stopped at 60 s and the page asked again every 5 s.

**IL-1 The list answers fast** (`app/Services/Chatbot/Inbox.php`)
- `listWithCounts` keeps its signature and output. The two `GROUP BY psid` queries are replaced by `latest()`: read the page's rows newest first on the `(page_id, received_at)` / `(page_id, sent_at)` index (forced with `FORCE INDEX`, see Rulings), 1,000 rows, then 2,000, ... up to 16,000 per read, with a keyset cursor on `(time, id)`, the same row filters as today (`kind`, standby chats, search). The first 500 different psids met are exactly the 500 with the latest `MAX(time)`, so it stops there instead of grouping the whole window. Then one `GROUP BY psid` on only those 500 psids (index `(page_id, psid, time)`) gives the exact `MAX(id)` and `MAX(time)` as before, so previews and times are unchanged.
- Filter-builder conditions are all per customer, so they are checked once per new customer (on one row id) instead of on every row.
- Search: today the outgoing side is always empty when there is a search (see Rulings 1). The new code keeps that result and simply does not read `chatbot_replies` for a search.
- Status sets, pause lookup, last rows, names: unchanged (they already work on at most 2 x 500 customers).
- Shared cache in `ChatbotInboxController::conversations`: the answer for page + window + search + tab + status + conditions is kept **15 s**, so all tabs and users polling the same list cost one build. A pause/unpause or a manual reply on the page changes a per-page version in the key, so that change shows at once.
- No migration, no new index.

**IL-2 A slow list can never hold a worker for a minute**
- The list build has a budget of **8 s** (`Inbox::LIST_SECONDS`). Before each of its queries `tick()` checks the deadline and, on MySQL, sets `SET SESSION max_execution_time = <ms left>`, so every SELECT of the list (also the status sets) is stopped by MySQL itself when the time is up. In a `finally` the session goes back to `max_execution_time = DEFAULT` (the server's global value), so no other query of the request or the app changes.
- A MySQL timeout (3024, or 1317) or the deadline becomes `App\Services\Chatbot\InboxTooSlow`. Other database errors are not hidden.
- `GET /chatbot/inbox/conversations` answers that with HTTP 200 `{"rows": [], "counts": [], "total": 0, "slow": true, "error": "The list is taking too long. Pick a shorter time (24h or 7d) or fewer filters."}`, logs one warning (`Chatbot inbox list too slow`, with page, window, tab, status, whether there was a search, the number of conditions and the MySQL message), and caches that answer **60 s** so polls do not retry it every 5 s.
- The page shows the message in the list's note line, empties the rows and shows the status options without counts.

**IL-3 The page never stacks requests** (`resources/views/chatbot/inbox.blade.php`)
- `inboxListLoop` (between `// inbox-list-loop:start` and `:end`): the next list request is scheduled 5 s **after** the previous one answered (or was aborted); every request is aborted after **15 s** (`REQUEST_LIMIT`) and then shows the "taking too long" note; a hidden tab skips its turn (as before); any immediate load (filter, search, window, status, tab, page change, opening a conversation, after a reply or pause) aborts the pending request and starts a new one; an answer that arrives for a replaced request is ignored.
- The old `setInterval(() => { poll(); if (!document.hidden) loadList(); }, POLL)` is now `setInterval(poll, POLL)` plus the loop. `poll()` (the open conversation) already never stacked (`S.busy`); its request is now aborted after 15 s so one hung call cannot stop it for a minute.

### Files
- `app/Services/Chatbot/Inbox.php`: `listWithCounts` (budget, timeout mapping, session reset), `buildList` (the old body with the two `latest()` calls), `latest()`, `pageTimeIndex()`, `tick()`, `budget()`, `isTimeout()`, constants.
- `app/Services/Chatbot/InboxTooSlow.php` (new).
- `app/Http/Controllers/ChatbotInboxController.php`: cache and slow answer in `conversations`; `forgetLists()` after `send` and `pause`.
- `resources/views/chatbot/inbox.blade.php`: the loop, `fetchList`, abortable `getJson`, slow state, `poll()` timeout.
- `tests/Feature/Chatblast/ChatbotInboxListFastTest.php` (new, 7 tests).

## Evidence per done-when item

### 1. Tests

New: `tests/Feature/Chatblast/ChatbotInboxListFastTest.php` (sqlite in memory, like the other Inbox tests; Node for IL-3), 7 tests, all passing:

| Test | Story | What it shows |
|---|---|---|
| `test_IL_1_the_newest_first_list_is_the_same_as_the_grouped_query` | IL-1 E | A page with 650 customers on the outgoing side and 560 on the incoming side (over GROUP_LIMIT 500 and LIST_LIMIT 200), sheet rows, failed sends, manual replies, standby chats, rows sharing one second across chunk borders, rows older than 30 days and another page. For **189 combinations** (24h / 7d / 30d x All / Ordered / Loop 2 / Reply failed / Phone x All / Human / Bot tab x no search / name / PSID prefix, plus 6 filter-builder condition sets) the new `listWithCounts` returns **the identical array** (rows, order, every field, counts, total) as a copy of today's `GROUP BY` code; also with 7-row reads, so the cursor crosses many chunk borders. It also proves today's search drops the outgoing side (Ruling 1). |
| `test_IL_1_the_scan_stops_once_it_has_enough_customers` | IL-1 | 600 recent customers plus 6,000 older rows in the window: one read of 1,000 rows finds the 500; no `GROUP BY` over the window. |
| `test_IL_2_a_slow_list_answers_quickly_with_the_too_slow_state_and_is_not_retried_by_every_poll` | IL-2 H | Test double: every read takes 0.4 s, budget 0.3 s. The endpoint answers in well under 2 s with `{rows: [], counts: [], total: 0, slow: true, error: "The list is taking too long..."}`, logs one warning; the next poll within 60 s is the cached answer (no new build); after 61 s it builds again. |
| `test_IL_2_a_mysql_statement_timeout_becomes_the_too_slow_state` | IL-2 | A `QueryException` with MySQL code 3024 → the same slow answer. |
| `test_IL_2_other_database_errors_are_not_hidden_as_too_slow` | IL-2 | Code 1064 is thrown, not turned into "too slow". |
| `test_IL_1_the_same_list_is_built_once_per_15_seconds_and_a_pause_shows_at_once` | IL-1 cache | Second identical poll reads no chat table; another window is its own list; an unpause shows at once (Human badge gone); after 16 s the list is built again. |
| `test_IL_3_the_page_sends_the_next_list_request_only_after_the_last_one_answered` | IL-3 H | Runs the page's own `inboxListLoop` (cut from the rendered `/chatbot/inbox` HTML) in Node with a fake clock. Answers of 1 s, then 8 s, then a hung one, then a filter change, then a hidden tab: requests start at 5,000 / 11,000 / 17,000 / 30,000 / 43,000 / 63,000 / 64,000 / 70,000 / 91,000 ms, **never two at once**; the hung one is aborted at 58,000 (15 s, `timedOut`), the 63,000 one is aborted by the filter change, the hidden tab sends nothing from 70,500 to 90,000. The page uses the loop, `setInterval(poll, POLL)` only, `poll()` aborts after 15 s, and handles `data.slow`. |

Existing Inbox tests, unchanged and passing on the branch: `ChatbotInboxTest`, `ChatbotInboxActionsTest`, `ChatbotInboxBotPauseTest`, `ChatbotInboxFilterBuilderTest`, `Botcake\BotcakePsidPageIdTest` (uses `listWithCounts`): with the new file, 58 tests, 812 assertions, OK. No assertion was changed.

Full suite (`vendor/bin/phpunit`, Herd PHP 8.4.16, `-d memory_limit=4G`: the whole run peaks at 1.11 to 1.12 GB, and a first base run with the default 1 GB limit died at 88% with "Allowed memory size exhausted", on base and branch alike):

| Run | Tests | Assertions | Failures | Skipped | Time |
|---|---|---|---|---|---|
| Base 94aed869 (clean `git archive` of that commit + the same `vendor/`) | 5750 | 85981 | 3 | 6 | 22:02 |
| Branch `a0f3206b` (code as `8eae3375`) | 5757 (+7, the new file) | 86413 | 3 | 6 | 22:58 |

The same three fail on both, none new (the three that 291, 292, 297 and 298 also list):
1. `ExampleTest::test_the_application_returns_a_successful_response`
2. `Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
3. `OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_the_shared_owner_pages_render_byte_for_byte_as_at_the_base` with `/owner/private`

`php artisan view:cache` compiles all views on the branch.

### 2. Query cost before and after (seeded MySQL 8.0.42)

**Data** (invented, in a local MySQL on port 3407, 1 GB buffer pool, Windows laptop SSD): big page `652850684584967` with 3.6M replies in the last 30 days (5,000 an hour, 95% `send`, 4% `sheet`, 1% `manual`, 10% failed) plus 1.2M replies 31 to 60 days old, 150k customers, 600k incoming chats in 30 days (2% standby copies), 30k orders and 1M run events, 450k customer fields (chat status, phone, 1 in 40 paused); two other pages with 400k replies and 100k chats each, and a tiny page (100 + 100). `chatbot_replies`: 5.4M rows, 1.6 GB data + 2.2 GB indexes. Indexes exactly as the migrations make them (`SHOW INDEX` matched the list below).

Indexes used: `chatbot_replies`: `(page_id, sent_at)`, `(page_id, psid, sent_at)`, `(page_id, psid, id)`, `(psid)`, `(sent_at)`, `(flow_id, sent_at)`, `(sequence_id, sent_at)`, `(chat_id, kind, status, sent_at)`, `(client_ref)`, `(via, confirmed_status, sent_at)`. `botcake_chats`: `(page_id, received_at)`, `(page_id, psid, received_at)`, `(page_id, psid, id)`, `(received_at)`, `(message_id)`.

**Timings** of `Inbox::listWithCounts` on the big page (PHP 8.4 + Laravel against that MySQL; "before" = base 94aed869, "after" = this branch, two runs each after; same answer = same md5 of rows + counts + total):

| Case | Before | After (run 1 / run 2) | Same answer |
|---|---|---|---|
| 24h | 0.7 s | 0.9 / 0.3 s | rows, counts, total same; 3 same-second pairs swap (Ruling 2) |
| 7d | **385 s** | 0.3 / 0.4 s | same, ties as above |
| 30d | **371 s** | 1.6 / 1.6 s | same, ties as above |
| 30d + Ordered | **380 s** | 0.2 / 0.2 s | identical |
| 30d + Reply failed | (same query as 30d) | 0.3 / 0.3 s | |
| 30d + Human tab | (same) | 0.1 / 0.1 s | |
| 30d + Bot tab + Phone | (same) | 0.2 / 0.2 s | |
| 7d + search "Customer 12" | **387 s** | 0.1 / 0.4 s | identical |
| 30d + search matching 1 customer | | 1.8 / 1.8 s | |
| 30d + search matching nobody (whole window read) | | 1.6 / 1.6 s | |
| 30d, medium page (400k replies) | | 0.4 / 0.4 s | |
| 30d, tiny page | | 0.02 / 0.03 s | |
| 24h + condition Ordered = yes | | 5.9 / 5.6 s | |
| 7d + condition Ordered = yes | | 7.5 / 7.6 s | |
| 30d + condition Ordered = yes | | **8.2 s → "too slow"** | |
| 30d + condition Human | | 5.2 / 5.4 s | |
| 24h + condition matching nobody | | 6.3 / 6.3 s | |

Before, every 7d/30d case ran the same plan (below), 6 minutes here; production stopped it at 60 s. After, everything without filter-builder conditions is under 2 s; of the 1.6 s for 30d, 1.6 s is the unchanged "Reply failed" status query (`MAX(id)` of sends for the 500 listed customers, 12,000 row lookups on a table larger than this buffer pool). Conditions: see Ruling 7 (the scan must check many customers when few match; worst case ends at 8 s with the slow answer, never 60 s).

**Plans.**

Before (7d and 30d; 24h used `(page_id, sent_at)` range with temporary + filesort):
```
EXPLAIN select psid, MAX(id) AS last_id, MAX(sent_at) AS last_at from chatbot_replies
 where page_id = 652850684584967 and sent_at >= '2026-10-19 18:00:00' and kind in ('send', 'manual')
 group by psid order by last_at desc limit 500
-> Limit: 500 row(s)
  -> Sort: last_at DESC, limit input to 500 row(s) per chunk
    -> Group aggregate: max(id), max(sent_at)
      -> Filter: (sent_at >= ... and kind in ('send','manual'))
        -> Index lookup on chatbot_replies using chatbot_replies_page_psid_id_index (page_id=652850684584967)  (rows=2.71e+6)
```
That reads every row of the page in the table (4.8M here), not only the window. The `botcake_chats` query had the same shape (`ref` on `botcake_chats_page_id_psid_received_at_index`, rows 402,037, temporary + filesort).

After, the new list queries (exact SQL as Laravel sends it, from the query log; `?` values shown inline):

```sql
-- 1. Incoming, newest first (first read 1,000 rows; next reads 2,000, 4,000 ... 16,000 add the cursor line)
select `id`, `psid`, `received_at` from `botcake_chats` force index (botcake_chats_page_id_received_at_index)
 where (json_extract(`payload`, '$."_standby"') is null OR json_type(json_extract(`payload`, '$."_standby"')) = 'NULL')
   and `page_id` = '652850684584967' and `received_at` >= '2026-10-19 18:00:00'
   -- with a search: and (LOWER(name) LIKE '%ana%' ESCAPE '!' or `psid` like 'ana%')
   -- next reads:    and `received_at` <= '<last received_at>' and NOT (`received_at` = '<last received_at>' AND `id` >= <last id>)
 order by `received_at` desc, `id` desc limit 1000;
-- EXPLAIN: range on botcake_chats_page_id_received_at_index, Using index condition; Using where; Backward index scan (no filesort)

-- 2. Incoming, exact MAX for the (at most 500) customers found
select psid, MAX(`id`) AS last_id, MAX(`received_at`) AS last_at from `botcake_chats`
 where (json_extract(`payload`, '$."_standby"') is null OR json_type(json_extract(`payload`, '$."_standby"')) = 'NULL')
   and `page_id` = '652850684584967' and `received_at` >= '2026-10-19 18:00:00' and `psid` in ('<psid 1>', ..., '<psid 500>')
 group by `psid` limit 500;
-- EXPLAIN: range on botcake_chats_page_id_psid_received_at_index (rows 2,000)

-- 3. Outgoing, newest first (not run when there is a search)
select `id`, `psid`, `sent_at` from `chatbot_replies` force index (chatbot_replies_page_id_sent_at_index)
 where `page_id` = 652850684584967 and `sent_at` >= '2026-10-19 18:00:00' and `kind` in ('send', 'manual')
   -- next reads: and `sent_at` <= '<last sent_at>' and NOT (`sent_at` = '<last sent_at>' AND `id` >= <last id>)
 order by `sent_at` desc, `id` desc limit 1000;
-- EXPLAIN: range on chatbot_replies_page_id_sent_at_index, Using index condition; Using where; Backward index scan

-- 4. Outgoing, exact MAX for the customers found
select psid, MAX(`id`) AS last_id, MAX(`sent_at`) AS last_at from `chatbot_replies`
 where `page_id` = 652850684584967 and `sent_at` >= '2026-10-19 18:00:00' and `kind` in ('send', 'manual')
   and `psid` in (<psid 1>, ..., <psid 500>)
 group by `psid` limit 500;
-- EXPLAIN: range on chatbot_replies_page_id_psid_sent_at_index (rows 12,000)

-- 5. Only with filter-builder conditions: which of the new customers match (one row id each), e.g. Ordered = yes
select `psid` from `chatbot_replies` where `page_id` = 652850684584967 and `id` in (<ids>)
   and EXISTS (SELECT 1 FROM chatbot_events e WHERE e.page_id = 652850684584967 AND e.psid = chatbot_replies.psid
               AND e.type = 'order' AND e.created_at >= '2026-10-19 18:00:00');
-- EXPLAIN: PRIMARY range, then nested loop semijoin with an index lookup on chatbot_events_page_id_psid_created_at_index
```
Rows read per call, big page 30d: before ~4.8M row lookups (all of the page); after 1,000 index entries + row lookups for query 1 and 3 each, and up to ~12,000 index entries for query 4 (the 500 customers' rows in the window); everything after that is the unchanged status sets.

Without `FORCE INDEX`, MySQL chose `botcake_chats_received_at_index` / `chatbot_replies_sent_at_index` (time only, backward) for the big and the medium page (Ruling 4); with it, all three pages use `(page_id, time)`.

**For the reviewer to EXPLAIN on the server** (read-only, no data needed; replace the date with now minus 30 days):
```sql
EXPLAIN select `id`, `psid`, `sent_at` from `chatbot_replies` force index (chatbot_replies_page_id_sent_at_index) where `page_id` = 652850684584967 and `sent_at` >= NOW() - INTERVAL 30 DAY and `kind` in ('send', 'manual') order by `sent_at` desc, `id` desc limit 1000;
EXPLAIN select `id`, `psid`, `received_at` from `botcake_chats` force index (botcake_chats_page_id_received_at_index) where (json_extract(`payload`, '$."_standby"') is null OR json_type(json_extract(`payload`, '$."_standby"')) = 'NULL') and `page_id` = '652850684584967' and `received_at` >= NOW() - INTERVAL 30 DAY order by `received_at` desc, `id` desc limit 1000;
EXPLAIN select psid, MAX(`id`) AS last_id, MAX(`sent_at`) AS last_at from `chatbot_replies` where `page_id` = 652850684584967 and `sent_at` >= NOW() - INTERVAL 30 DAY and `kind` in ('send', 'manual') and `psid` in (<a few psids from the first statement's rows>) group by `psid` limit 500;
```
Expected (as here): the first two `range` with `Backward index scan` and no `Using filesort`; the third a `range` on `chatbot_replies_page_id_psid_sent_at_index` (or `..._page_psid_id_index`), rows in the thousands, not millions.

**Index:** none added. The existing `(page_id, sent_at)` and `(page_id, received_at)` serve the newest-first read and `(page_id, psid, sent_at)` / `(page_id, psid, received_at)` the exact MAX; the EXPLAINs above show no full-window read. So there is no build cost and nothing to run on the busy tables.

**IL-2 on MySQL** (same seeded server, a subclass with a 0.2 s and a 0.05 s budget, 30d + condition Ordered):
```
session before: 0
budget 0.2: InboxTooSlow after 212 ms; cause: MySQL error 3024 Query execution was interrupted, maximum statement execution time exceeded
session after: 0
budget 0.05: InboxTooSlow after 63 ms; cause: MySQL error 3024 Query execution was interrupted, maximum statement execution time exceeded
session after: 0
later SELECT SLEEP(2): 2015 ms, not stopped
```
MySQL itself stops the running list SELECT at the budget, the session limit is back to its default afterwards, and a later query of the same connection is not limited.

### 3. Branch pushed

`git push -u origin feat/302-inbox-list-fast`: the branch is on origin. Commits: `4cbeaa0f` plan, `0b5b2f05` code + tests, `8eae3375` the session-reset guard, then docs only. For the other site, cherry-pick `0b5b2f05` and `8eae3375` (app + test files only; no docs). Merge danger below.

The local MySQL (port 3407) was stopped and its data folder deleted after the measurements; it lived in the session scratchpad, never in the repo.

## Story changes

None. IL-1..IL-3 are as written; the cache (offered in IL-1 as an example) is in.

## Rulings

For the reviewer to confirm or change:

1. **Search results ignore the outgoing side, as today.** Today's code ends the search case with `$out->only($in->keys())`; `$out` is an Eloquent collection, whose `only()` picks models by their primary key (absent in a `GROUP BY` row), so it is always empty: with a search, the preview, time and dot come from the customer's last incoming message only, even when the bot answered later. I kept that exactly (same rows as today) and skip reading `chatbot_replies` for a search. The test proves the old `only()` returns nothing. If the intent (a bot reply as preview) is wanted, it is a one-line change in `buildList` (`$out = $this->latest(...)->only($in->keys()->all())` on the base collection), in a separate spec since it changes what staff see.
2. **Same-second ties.** Two customers whose latest message is in the same second: the new order puts the higher id (the newer insert) first; the old order of such a tie was whatever MySQL's filesort produced. On the seeded page 3 to 4 such neighbouring pairs in the 200 rows swap places; rows, counts and total are identical (Evidence, IL-1).
3. **The list can be up to 15 s old.** With the shared cache, a new incoming message shows in the list within 15 to 20 s instead of about 5 s. A reply or pause made in the Inbox shows at once. The open conversation's own polling (`poll()`) is not cached and still shows new messages within 5 s.
4. **`FORCE INDEX` on the scan.** Without it, MySQL chose the time-only index (`botcake_chats_received_at_index`, `chatbot_replies_sent_at_index`) for the big and medium pages, which also reads the other pages' rows; for a page with a small share of the traffic that would read many times more rows. The hint names `<table>_page_id_<time>_index`, checked with `Schema::hasIndex` once a day (cached); if the other site lacks that index, the query runs without the hint instead of failing.
5. **Session `max_execution_time` rather than a per-SELECT `/*+ MAX_EXECUTION_TIME */` hint.** The session setting also covers the `pluck()` / `distinct()` lookups of the status sets, which cannot carry a hint through the query builder; it is set to the time left before each list query and put back to `DEFAULT` in a `finally`.
6. **The slow state is HTTP 200 with `slow: true`**, not a 5xx, so the page can tell it from a server error and nginx logs do not count it as a failure; the warning line in the Laravel log counts it instead.
7. **Filter-builder conditions that match few customers** on the 30-day window of the biggest page can still run out of the 8 s (the scan must look at many customers to find 500 that match); they now answer "taking too long" in 8 s instead of holding a worker for 60 s. The stories name window, tab, status and search; conditions are not among them. Numbers in Evidence.

## Merge danger

- **What ships:** PHP and one Blade view only: `Inbox.php`, the new `InboxTooSlow.php`, `ChatbotInboxController.php`, `chatbot/inbox.blade.php`, and the new test file. No migration, no config, no route, no `.env` key, no new table or index, no queue or scheduled job.
- **Deploy:** the normal code deploy; `php artisan view:cache` (or the deploy's usual view clear) so the page script changes. Open tabs keep the old script (the 5 s `setInterval`) until they reload; they still benefit from the fast list and the server-side 8 s limit and cache.
- **Database:** read-only. The only new statement kinds are `SET SESSION max_execution_time = <n>` / `= DEFAULT` on the request's own connection, and one `information_schema.statistics` read per table per day (`Schema::hasIndex`, cached). Needs MySQL 5.7.8+ for `max_execution_time` (the server is MySQL 8).
- **Cache:** new keys `chatbot.inbox.list.<page>.<version>.<sha1>` (15 s / 60 s), `chatbot.inbox.list-version.<page>` (forever, one per page) and `chatbot.inbox.index.<index>` (1 day) in the default cache store.
- **Cherry-pick to the other site:** the two code commits (`0b5b2f05`, `8eae3375`) touch three existing files (`Inbox.php`, `ChatbotInboxController.php`, `chatbot/inbox.blade.php`) and add two (`InboxTooSlow.php`, the test); they do not depend on 301 or on any Likha-only file. Conflicts are possible only if the other line changed `Inbox::listWithCounts`, `ChatbotInboxController::conversations`/`send`/`pause` or the list part of the Inbox page script. Before relying on the hint there: `SHOW INDEX FROM botcake_chats` / `chatbot_replies` should list `botcake_chats_page_id_received_at_index` and `chatbot_replies_page_id_sent_at_index` (without them the query runs unhinted, which is correct but may pick the time-only index).
- **How to check on the server after deploy:** open the Inbox on the biggest page, 30d + Ordered; `grep "Chatbot inbox list too slow" storage/logs/laravel*.log` for any slow answers; the EXPLAIN statements in Evidence item 2.
- **Revert:** `git revert 8eae3375 0b5b2f05` (no data or schema to undo); cached entries expire on their own within a minute (the version and index keys are harmless if left).
