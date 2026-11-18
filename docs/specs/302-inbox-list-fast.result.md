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

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
