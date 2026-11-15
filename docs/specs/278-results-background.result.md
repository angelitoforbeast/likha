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
  older than 30 min (or that already have a copy) are dropped. Copies kept 6 h (default) / 1 h (others). A failing build
  is `report()`ed, removed from wanted (the page adds it again on its next reload) and does not stop the others; the run
  then exits 1. One log line per run, e.g.
  `chatbot:results-warm built default (84.2 s), 2026-11-09..2026-11-15 page 1001 (12.1 s); dropped 1 wanted in 96.4 s`.
  A cache lock (30 min) also guards manual runs. No queue is used.
- **Fewer scans, same numbers.** `sendCounts()` is ONE grouped read of the sends (GROUP BY page_id) that returns, per
  page, sent / distinct psid / replied for all sends and for each via bucket (conditional `SUM(CASE…)` and
  `COUNT(DISTINCT CASE … THEN psid END)`), where each bucket condition is compiled from the existing `whereVia()` itself
  (`compileWheres` + bindings), so the bucket rules are exactly the old WHERE rules (old rows without a via tag, or with
  an unknown via, count as botcake). Funnel, By bot and By page all read from it. `chatCounts()` does the same for the
  chats (one grouped read of botcake_chats, all + per bot). by_step, by_ad and compare are unchanged.
- **by_message:** numbers unchanged. The second join (median rows) is skipped when no returned group has a reply
  (`reply_seconds` is only ever written together with `replied_at`). It is not merged into the first read: the median
  uses the newest 200,000 tagged rows across all groups, which one grouped SQL read cannot give without pulling every
  tag row into PHP.
- `chatbot:results` still builds directly through `report()` (5 min cache); the cache key is now `chatbot-results:v3:`.
- View: description now says built in the background; sections wrapped in the building check. No redesign.

## Why the grouped read uses conditional columns, not GROUP BY page_id, via
Grouping by (page_id, via bucket) would count a customer reached by two bots on the same page twice in the funnel and
By page "customers" (they are distinct page+psid). Per-page conditional COUNT DISTINCT gives the exact old numbers in
one scan. The seeded data has such customers (psid 11 on page 1001, psid 21 on page 1002) and the equality test covers it.

## Tests
New `tests/Feature/Chatblast/ChatbotResultsBackgroundTest.php` (10 tests):
- S_278_1 old-vs-new equality: `OldResultsReference` (a copy of the pre-278 funnel / By bot / By page / chats code in the
  test) vs the new build, identical (`assertSame`) for funnel, by_via, by_page and chats over 9 filter sets (all, each via,
  page, page+via, flow, flow+version, flow+via, a page not in page settings) with incepxionbot both off and on (10 sets).
  Seed: 3 pages (one not in page settings), botcake / forbeast / incepxionbot / no tags / tags without via / unknown via,
  failed and manual rows, out-of-range rows, 2 flows and versions, chats from gateway (with and without payload gateway),
  default reply, no source, standby, and orders with and without via, sequence steps, deliveries.
- S_278_2 query count (below).
- S_278_3 no copy: page 200 with the building text and 20 s reload, json 202 building, wanted holds the normalized
  request with its first time (a repeat does not reset it), and none of the build's SQL appears in the request query log.
- S_278_4 with a copy (50 min old): page shows "as of HH:MM (Manila)" next to the date filter, json equals the copy plus
  built_at, nothing added to wanted, none of the build's SQL in the request log.
- S_278_5 warm builds the default when missing, skips at 14 min, rebuilds at 16 min; keep times 1 h / 6 h.
- S_278_6 wanted: oldest first, 3 per run, entry older than 30 min dropped, entry with a copy dropped, the rest next run;
  the stored copy equals a direct `output()` of the same filters.
- S_278_7 a failing build (page 1002) is reported, the default and the other wanted still build, exit 1, one warning line.
- S_278_8 one log line per run; a held lock skips the run.
- S_278_9 schedule registered: `* * * * *`, withoutOverlapping 30, onOneServer.
- S_278_10 `chatbot:results` still builds directly and adds nothing to wanted.

Existing tests adjusted: ChatbotResultsTest S_100_12 / S_100_13 and ChatbotResultsByPageTest S_276_5 build the copy
first (the web no longer builds); the json key list now ends with `built_at` (removed before the web-vs-CLI equality);
S_276_4 now checks that `sendCounts()` is one grouped query and `byPage()` runs none.

### Query count of a build (seeded data, default 7 days, all pages)
| | 2 bots | 3 bots (incepxionbot set up) |
|---|---|---|
| before (measured on d6f7757e) | 25 | 29 |
| after | 14 | 14 |

Funnel + By bot + By page + chats went from 13 queries (17 with 3 bots) to 2, and no longer grows per bot. On the live
site this removes the 3 funnel queries repeated per bot (by_via 58.6 s), the separate By page read and the per-bot chats.

### Test runs
- `vendor/bin/phpunit --filter ChatbotResults`: before 21 tests OK (97 assertions); after **31 tests OK** (253 assertions).
  Also passing: ForbeastStandbyHiddenTest, RunEventRowTest, ChatbotRepliesViaTest, SpeedPrewarmTest (58 tests together).
- Full suite, base d6f7757e: 5235 tests, 3 failures, 6 skipped. After: **5245 tests, 3 failures**, 6 skipped. The same 3
  known failures both times: ExampleTest root response (302), ItemTableFitScriptTest min width, SharedPagesBasePinTest
  S_42_4 `/owner/private`. No new failures.

## Unsure / for the owner
- **runInBackground() added** to the schedule line beyond the spec's chain: a run takes minutes, and without it
  `schedule:run` would hold every later job of that minute (forbeast-watch, broadcast-feed, ai-load) until it finished.
  Same as `chatbot:speed-warm`. Remove it if the owner wants the exact chain.
- `results.json` gained a `built_at` key (additive). Consumers that check the exact key list would see it.
- The single grouped read carries 3 COUNT DISTINCT per bot plus the total in one pass; it was proved equal on SQLite
  only (tests); the MySQL time of this one query on the live data was not measured (no server access in this job).
- A non-default copy is not refreshed while it exists (per spec): it is shown up to 1 h old, then rebuilt on the next view.
- The warm needs `schedule:run` on the server (already true there for chatbot:speed-warm).
- by_message still runs its two joins whenever a group has a reply (see above).
