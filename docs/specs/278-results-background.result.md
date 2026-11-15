# 278 Chatbot Results: built in the background, fewer scans — result

Branch `fix/278-results-background` (from `feat/276-results-by-page`, d6f7757e). Not deployed, not pushed.

## What changed
- **No build in the web request.** `ChatbotResultsController` index and json read only a stored copy
  (`ChatbotResults::stored()`, key `chatbot-results:copy:v3:` + md5 of the normalized filters and compare pick).
  - Copy present: the page shows it with "as of HH:MM (Manila)" beside the To date; `results.json` returns the copy plus
    a new `built_at` key (ISO 8601). Shown even when older than its refresh period.
  - No copy: the filters go into the `chatbot-results:wanted:v3` cache list (first request time kept, max 50 entries,
    short cache lock around edits) and the request answers at once: the page shows
    "Binubuo pa ang report (mga 1–3 minuto). Kusa itong magre-reload." and reloads every 20 s; the json answers
    `202 {"status":"building"}`. The building page still shows the filter form (pages, flows, flow versions).
- **`chatbot:results-warm`** (`app/Console/Commands/ChatbotResultsWarm.php`, `ChatbotResults::warm()`), scheduled in
  `routes/console.php` as `everyMinute()->withoutOverlapping(30)->onOneServer()->runInBackground()`. Per run: the default
  view (`filters([])`) when its copy is missing or older than 15 min; then wanted entries, oldest first, at most 3; entries
  older than 30 min (or whose copy is fresh) are dropped. Copies kept 6 h (default) / 1 h (others). A failing build
  is `report()`ed, removed from wanted (the page adds it again on its next reload) and does not stop the others; the run
  then exits 1. One log line per run, e.g.
  `chatbot:results-warm built default (41.2 s), 2026-11-09..2026-11-15 page 1001 (12.1 s); 4 day pieces; still building 2026-08-15..2026-11-15 in 236.4 s`.
  A cache lock (30 min) also guards manual runs; the run raises its own memory_limit to at least 1024M. No queue is used.
- `chatbot:results` (console) builds directly through `report()` (5 min cache, key `chatbot-results:v3:`); since 278-2 it
  uses the same day pieces (building the missing ones without a time cap), so there is one implementation.
- **Amendment 278-1 (applied, the owner's decision):** a filtered view should not sit up to 1 hour old while someone
  looks at it. When a copy exists but is older than 15 minutes (`DEFAULT_STALE_SECONDS`, `ChatbotResults::isStale()`),
  the page and `results.json` still serve it at once with its as-of time AND add the request to wanted. `warm()` drops a
  wanted entry only when its copy is fresh (younger than 15 minutes), so the stale re-want is rebuilt on the next run. A
  wanted entry that is the default request keeps the 6 h lifetime. Tests S_278_4 and S_278_4b.
- **Amendment 278-2 (applied, the owner's decision, from live measurements: ~1.7M sends a day, any `tags->via` read
  unusable, the 278 grouped 7-day read killed after 90 s):** the sends- and chats-based parts are built per Manila day
  and merged, never read per range.
  - **Day pieces** (`app/Services/Chatbot/ResultsPieces.php`), cached per (day, scope), stored as
    base64(gzip(serialize)) so the database cache store takes them. Scopes: `main` = page + flow + version (funnel, By bot,
    By page, By message); the page-only `main` also holds By sequence step, By ad and chats; `vflow` = one flow version
    (Compare flow); `field` = one botfield version (Compare botfield). Contents, per page and via bucket: sent, replied and
    the customer set (psids packed `J*`, sorted); By sequence step rows (sequence, step → sent, replied); By ad: ad_id →
    packed psid set; By message: groups → sent, replied (distinct reply_id per day, additive) plus the newest
    `MEDIAN_ROWS` (reply_id, group, reply_seconds) per bucket; chats: per page and bucket packed psid sets; Compare: per
    page sent/replied/psids and the newest `MEDIAN_ROWS` (id, reply_seconds).
  - **Merge** (`ChatbotResults::build()`): sums, set unions for every distinct-customer number (per page, so memory stays
    per page), the old median rule on the merged list (newest `MEDIAN_ROWS` by id over all days and groups), the old
    By message order (sent desc, flow, node, version; NULL first) and the old 300-row limits. Orders, deliveries, the
    unsubscribe count, names, versions and setting info are still read at build time as before.
  - **Refresh rule** (`ResultsPieces::stale()`): today after 15 min; yesterday and the day before after 60 min; older days
    once (a piece built while the day was still recent is rebuilt once after it turns old). Kept 9 days for days inside
    the default 7-day window, 1 day otherwise.
  - **Warm with a time cap**: each request's missing or stale pieces are built first, within `WARM_SECONDS` (240 s) per
    run (always at least one piece); its copy is made only when all pieces exist, otherwise it stays in wanted and the
    page keeps showing "binubuo". The default view then reads about one day (today) every 15 minutes.
  - **Queries on chatbot_replies**: one day at a time on the sent_at range (since 278-3, one hour at a time), plain
    columns only (no JSON in WHERE, GROUP BY or SELECT), each read iterated with `cursor()` and aggregated in PHP. The via bucket comes from the `via` column with the old rule (a valid gateway value is
    itself; anything else, including NULL, is botcake). When the column does not exist yet (old installs, several older
    tests), the `tags` column is selected and decoded in PHP, still no JSON in SQL. By message: one `SELECT DISTINCT` of
    tag + reply plain columns per day. Chats (botcake_chats): `page_id, psid, payload` for the day, with standby,
    source and gateway read in PHP (no JSON in SQL), so I did not need to decide whether the JSON read is acceptable.
  - **Amendment 278-3 (applied, the owner's decision, from the live run of 5d37a82f: one whole-day unbuffered read killed
    at 300 s; one hour = 85k rows, ~0.5 s):** every read of chatbot_replies, chatbot_reply_tags and botcake_chats for a
    piece now runs in 1-hour windows of the day (`ResultsPieces::hours()`, like `ResponseSpeed::windows()`), as normal
    buffered queries; the unbuffered toggle is removed. Per-row PHP work checked: per row only fixed-cost steps (bucket
    from a 2-value list, array-key writes for sets, a short group-key string); no search in growing arrays, no
    concatenation into growing strings, no sorting while reading; sets are packed once at the end of the day; the median
    rows held per bot are trimmed to the newest after each hour once over 2 × MEDIAN_ROWS. Measured (SQLite + PHP, sends
    + tags + chats reads): **8.5 µs per send row on a 100k-row hour, 9.2 µs on a 10k-row hour** (flat). **Statement
    guard:** on MySQL the warm sets `SESSION max_execution_time = 60000` (MariaDB: `max_statement_time = 60`) for its
    run and restores the old value after; if it cannot be set, it logs a warning and runs anyway. A day piece is now 24
    queries per part (72 for the page-only piece, 48 with a flow filter).
  - Replaces 278's single grouped read (`sendCounts`, `chatCounts`, compiled `whereVia`), which is removed, and 278's
    by_message skip (kept only as: no median read is needed when no group has a reply, now a merge-time check).
- View: description says built in the background; sections wrapped in the building check. No redesign.

## Tests
`tests/Feature/Chatblast/ChatbotResultsBackgroundTest.php` (15 tests), with
`tests/Feature/Chatblast/OldChatbotResultsReference.php` = the base (d6f7757e) `ChatbotResults` unchanged except
namespace, class name and cache keys.
- S_278_1 **old-vs-new equality, whole output**: `assertSame` of the full build (filters, funnel, by_via, by_page,
  by_message, by_step, by_ad, versions) for 4 ranges (1 day, 3 days, 7 days, 7 days including today) × 9 filter sets
  (all, each via, page, page+via, flow, flow+version, flow+via, a page not in page settings; + incepxionbot via when set
  up) with incepxionbot off and on, and of Compare (flow versions all/forbeast/page, botfield versions all/page) for each
  range: 116 comparisons. Seed: `via` column equal to tags.via; 3 pages, botcake / forbeast / incepxionbot / no tags /
  tags without via / unknown via, failed and manual rows, rows outside the range, today and yesterday rows, 2 flows and
  versions, 2 botfield versions, 1–2 tag rows per send, sequence steps, ad ids, an unsubscribe, chats from gateway (with
  and without payload gateway), default reply, no source, standby, orders with sends, via, sequence and ad, deliveries.
- S_278_2 one day = 24 one-hour reads per part (72; 48 with a flow filter), each window exactly 3600 s, back to back,
  covering the Manila day; none with JSON or GROUP BY; a 7-day build reads 7 × 24 sends windows; 16 min later an old
  range reads nothing again, a range with today reads only today's 24; after an hour today, yesterday and the day before.
- S_278_2b **large day**: 30,000 sends (3 pages, 3 bots + NULL, 12,000 customers, tag rows, steps, ads): **72 queries
  (24 per part), peak +2.1 MB, ~0.25 s, stored piece 94.6 KB** (SQLite in memory); numbers equal the old code.
- S_278_2e (278-3) **per-row cost**: 100,000 sends in one hour (40,000 customers, tag rows, steps, ads): 8.5 µs per row,
  and 9.2 µs per row for 10,000 rows of the same hour (the cost does not grow with the row count); the statement guard
  is a no-op off MySQL.
- S_278_2c a stale piece (today, 16 min) and a missing piece (dropped from the cache) are rebuilt; the old day and
  yesterday are kept; the new send shows in the copy; the copy equals the old code.
- S_278_2d progressive fill: with a 0 s cap one piece per run, the default copy appears after its 7th piece, the page
  shows "binubuo" until then; a wanted 3-day flow range builds its 3 missing pieces over 3 runs and stays wanted until
  its copy exists; the copy equals the old code.
- S_278_3 / 4 / 4b / 5 / 6 / 7 / 8 / 9 / 10 as in 278 and 278-1 (no copy → building + wanted + no report SQL in the
  request; fresh copy served, not re-wanted; stale copy served + wanted + rebuilt; default when missing/stale; wanted
  order, max 3, 30 min drop; a failing build does not stop the others; one log line; lock; schedule; the console command
  builds directly and equals the old funnel).

Existing tests adjusted: ChatbotResultsTest S_100_12 / S_100_13 and ChatbotResultsByPageTest S_276_5 build the copy
first; the json key list ends with `built_at`; S_276_4 now checks that a day's sends are one query on plain columns and
that By page runs no query of its own. All hand-computed expectations in ChatbotResultsTest, ChatbotResultsByPageTest,
ChatbotRepliesViaTest, ForbeastStandbyHiddenTest and RunEventRowTest pass unchanged on the day-piece path (these seed
no `via` column, so they run the tags fallback).

### Query count (seeded data, default 7 days, all pages)
| | queries | each chatbot_replies read covers |
|---|---|---|
| before (d6f7757e) | 25 | 7 days (JSON `tags->via` when filtered by bot) |
| 278 | 14 | 7 days |
| 278-2, no pieces yet | 33 (7 days × 3 + 12) | 1 day, plain columns |
| 278-2, 15-minute refresh of a range with today | 16 (3 + 13) | today only |
| 278-3, no pieces yet | 516 (7 days × 72 + 12) | 1 hour, plain columns |
| 278-3, 15-minute refresh of a range with today | 85 (72 + 13) | 1 hour of today |

### Test runs
- `vendor/bin/phpunit --filter ChatbotResults`: base 21 tests OK; after 278: 31; after 278-1: 32; after 278-2:
  35 tests OK; after 278-3: **36 tests OK** (662 assertions). ForbeastStandbyHiddenTest, RunEventRowTest and
  ChatbotRepliesViaTest also pass.
- Full suite, base d6f7757e: 5235 tests, 3 failures, 6 skipped. After 278: 5245 / 3. After 278-1: 5246 / 3. After
  278-2: 5249 / 3. After 278-3: **5250 tests, 3 failures**, 6 skipped. The same 3 known failures every time: ExampleTest root response (302),
  ItemTableFitScriptTest min width, SharedPagesBasePinTest S_42_4 `/owner/private`. No new failures.

## Unsure / for the owner
- **Not measured on live MySQL after 278-3.** All equality, memory and per-row numbers are from SQLite tests. From
  the live hour measurement, a piece should take about 24 × (0.5 s sends + the tags join hour + the chats hour); the
  tags join and the chats hour were not measured live. The 60 s guard turns a bad plan into a failed build (reported,
  retried on a later run) instead of a long-held statement.
- The time cap (240 s) is checked between pieces, not inside one; a single piece of a busy day may run past it.
- **Memory on the live day** is not known: a piece holds packed psid sets (8 bytes per customer per page and bot, per
  ad, and per chat customer) and up to 200,000 median rows per bot; building it holds the day's distinct sets in PHP
  arrays. The warm raises memory_limit to at least 1024M.
- **Cache size**: pieces are compressed; on the database cache a value must fit in the `cache.value` column
  (MEDIUMTEXT, 16 MB). A very large day with many ads could approach that; not measured.
- Rows with `via` NULL that the backfill has not reached are botcake (same rule as before for rows without tags.via).
- **Ordering edge cases** may differ from MySQL's collation: By message ties on node_id compare in PHP (binary),
  MySQL compares case-insensitively; By ad drops ad ids that are only spaces (as MySQL `<> ''` does). A send with a
  sequence_id but a NULL step is counted under step 0 (the old code kept one of the two rows).
- **runInBackground() added** to the schedule line beyond the spec's chain (a run takes minutes; without it the other
  jobs of that minute wait). Same as `chatbot:speed-warm`.
- `results.json` gained a `built_at` key (additive).
- The console `chatbot:results` now shares the pieces: for today it may show up to 15 minutes old data.
- A long range (e.g. 92 days) fills over many runs (about one day's reads per piece); its wanted entry may pass 30
  minutes and be dropped, and the page re-adds it on the next reload; the pieces already built are kept.
- The warm needs `schedule:run` on the server (already true there for chatbot:speed-warm).
