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
- **Cherry-pick to the other site:** the code commit touches only the four app files and adds two new files; it does not depend on 301 or on any Likha-only file. Conflicts are possible only if the other line changed `Inbox::listWithCounts`, `ChatbotInboxController::conversations`/`send`/`pause` or the list part of the Inbox page script. Before relying on the hint there: `SHOW INDEX FROM botcake_chats` / `chatbot_replies` should list `botcake_chats_page_id_received_at_index` and `chatbot_replies_page_id_sent_at_index` (without them the query runs unhinted, which is correct but may pick the time-only index).
- **How to check on the server after deploy:** open the Inbox on the biggest page, 30d + Ordered; `grep "Chatbot inbox list too slow" storage/logs/laravel*.log` for any slow answers; the EXPLAIN statements in Evidence item 2.
- **Revert:** `git revert` of the code commit (no data or schema to undo); cached entries expire on their own within a minute (the version and index keys are harmless if left).
