# 306 result: /chatbot/speed opens in under 2 seconds, and can never hold a worker for a minute

Base: live 528326cc. Branch `feat/306-speed-page-fast`.

## Plan

Base 528326cc: full suite once (clean `git archive` of that commit + the same `vendor/`, Herd PHP, `-d memory_limit=4G`) before any code change. A local MySQL 8.0.42 on port 3408 (data folder in the session scratchpad, deleted at the end) with invented data the size of a busy day: 120 pages (one with 35% of the traffic, half on a gateway, half on Botcake, 10 without a flow), ~300k chats and ~1M replies per full day (bot replies with some failed tries, ~600k sequence steps, manual replies), AI requests, chatblast rows; 2026-11-16 and 2026-11-17 full, 2026-11-18 until 15:00.

SP-1: time `ResponseSpeed::output()` of the base code on that MySQL (today, today + big page, + small page, + bot, a past day, a past day + big page), every query grouped by its shape with its time and EXPLAIN; PHP time apart.

What the code already shows: the default view of today and yesterday is pre-built by `chatbot:speed-warm` every 2 minutes, but every other view (a page, a bot, any older day, and the previous day each chart needs) is computed in the request: one full read of the day's chats and replies (plus the next 24 h of replies), the AI requests, and the "Sequence steps" volume, whose page filter turns an index-only count into one row lookup per sequence step of the day. A cache miss of the default view does the same.

SP-2 (expected shape, to be confirmed by SP-1):
- One pass per day computes every filter at once. The day's chats, replies and AI requests are read once (the same queries as today's default warm, no page filter); every chat and reply is added to the buckets of its page and bot, so the job writes the finished numbers of the default view, each bot, each page and each page + bot (same `build` arithmetic, so the same numbers). Stored in the cache (no table): one entry per day and page (all bot variants) plus an index entry per day (built_at, final, the pages written, a blank for a page without data); the Not sent lists per filter as today.
- Sequence steps per page: counted per hour (`sequence_id IN (...)` on the `(sequence_id, sent_at)` index, grouped by page); a closed hour is counted once and kept, so a run reads only the current hour's steps.
- Chatblast per page: the same day read grouped by `page_name` too.
- `chatbot:speed-warm` (the existing job, every 2 minutes) runs it: today every run, yesterday every 20 minutes until it is final, the day before yesterday once, and up to 2 days that pages asked for (a "wanted" list like `/chatbot/results`, spec 278). Its Sequences part is unchanged.
- The page (`/chatbot/speed`, and `not-sent`) only reads the cache: the day and the previous day (for the charts) present → the numbers with "as of HH:MM"; missing → a short "computing, this page refreshes in a minute" state that adds the day(s) to the wanted list and reloads itself after a minute. No live computing of the main numbers.

SP-3: the page's remaining queries (page list, cache store) run under a 302-style session `max_execution_time` (reset to DEFAULT in `finally`); a MySQL timeout answers at once with a "taking too long" state. The Sequences endpoint's live computing (filtered views, already limited to 20 s between hourly reads) also gets the session limit for the time left, so one slow SELECT stops at the budget.

Tests (new file in `tests/Feature/Chatblast`): every filter (each page, an unknown page, each bot, page + bot, today, yesterday, an older day) gives exactly the same output, charts and Not sent list as a frozen copy of the base `ResponseSpeed` on a mixed fixture; the page reads no chat or reply table; computing state and wanted list; closed sequence hours read once; the timeout states. Existing speed tests: warm first where they read the page (listed in Evidence).

Order: plan commit; base suite; MySQL seed and SP-1 profile; one-pass build + storage + warm; page and computing state; SP-3; tests; existing tests; full suite vs base; MySQL timings after; result; push.

## What shipped

**Cause (SP-1, below).** Only the default view of today and yesterday was pre-built. Every other view (a page, a bot, any older day, and the previous day that each chart needs) was computed in the request, and every view computes **two** full days (the day and its previous day for the dotted line). One day is a read of all the day's chats (JSON payload decoded per row), all bot replies of the day and the next 24 h, the AI requests, and the "Sequence steps" volume; most of the time is PHP over those rows, and a page filter turns the sequence count into one row lookup per sequence step of the day. On the seeded busy day: 8–25 s per view; on the busy site, with more data and 20 workers sharing the server, the 7–27 s and the 60 s 504s.

**SP-2 The page answers from what the warm built** (`app/Services/Chatbot/ResponseSpeed.php`)
- `buildDay($date)`: one pass over the day (the same chat, reply and AI queries as the old default view, no page filter). Every chat is added to four buckets (all pages / its page × all bots / its bot), every reply the same way by its `via`, with the same arithmetic as the old `build()` (moved into `buildAll()` + `assemble()`). The result is the finished numbers of **every filter**: all pages and each page (every page in the page list or with any data that day), each with bot = all, Botcake, Forbeast and every other bot of `ChatbotConnections::bots()`. A page id without any data that day gets the day's "blank" (exactly what the old code computes for it). The Not sent lists are built per filter the same way.
- Sequence steps per page: `sequenceCounts()` counts per hour with `sequence_id IN (<the ids in chatbot_sequences>)` on the `(sequence_id, sent_at)` index, grouped by `page_id` and hour. An hour closed for 10 minutes (`SEQ_HOUR_SETTLE`) is counted once and kept 7 days, so a run of today reads only the current hour's steps. Chatblast: the same one-day query with `page_name` added to the `GROUP BY`.
- Storage: cache only (no table, no migration). Per day: `response-speed-day:v1:<date>` (built_at, final, the pages written, the blank), then per page `response-speed:v6:<date>:<page or ''>` (all bot variants) and `response-speed-not-sent:v2:<date>:<page or ''>`; the per-page values are `serialize` + `gzcompress` + base64 (a full day of every filter: 17 MB plain, 1.5 MB stored; base64 so a database cache store's text column takes it).
- `chatbot:speed-warm` (the existing job, schedule unchanged: every 2 minutes, `withoutOverlapping(10)`, `onOneServer`, background): yesterday when due (every 20 min until final), today every run, the day before yesterday once (or again when its entry is not final yet, i.e. right after midnight), up to 2 days the page asked for (`response-speed:wanted:v1`, oldest ask first; asks older than 30 minutes dropped), then the Sequences part as before. Its log line lists each day with its seconds.
- The page (`output()`) and the Not sent list (`notSentOutput()`) only read those entries. The day or its previous day missing → `computing`: the page shows only the navigation, the filters and "Computing the numbers for <date>. This page refreshes in a minute." and reloads itself after 60 s (as the Sequences part does while it is building); the missing days go on the wanted list. "Numbers as of HH:MM" is shown as before (built_at of the entry).
- How each filter is served: date, page, bot and page + bot are all **precomputed per filter** by the same pass; nothing is computed from hourly rows at read time (medians and p90 need the raw seconds).

**SP-3 Never a minute**
- `ResponseSpeed::withBudget()`: on MySQL `SET SESSION max_execution_time = <ms>` before, `= DEFAULT` in a `finally`. `ChatbotSpeedController` runs the page (`index`, including the page list), `not-sent` and `sequences` under `PAGE_SECONDS` = 5 s per SELECT. A MySQL timeout (3024 / 1317) answers at once: the page with "The numbers are taking too long to load. This page tries again in a minute." (HTTP 200, reload after 60 s), the two JSON endpoints with `{slow: true, html}`; one warning line `Chatbot speed too slow` (part, MySQL message). Other database errors are not hidden.
- The Sequences endpoint still computes filtered views live (unchanged, its own spec); its existing 20 s budget (checked between hourly reads) now also sets the session limit to the time left before each read, so one slow SELECT stops at the budget and the section answers "too large" as on any other failure; the limit is put back in `finally`.

### Files
- `app/Services/Chatbot/ResponseSpeed.php`: `output`, `stored`, `dates`, `want`/`wanted`/`editWanted`, `withBudget`/`isTimeout`, `buildDay`, `notSentOutput`, `warmDefault`/`warmPage`/`warmWanted`, `buildAll`/`assemble`/`notSentList`/`sequenceCounts`/`chatblastCounts`/`volume`, `squeeze`/`unsqueeze`, the Sequences time limit.
- `app/Http/Controllers/ChatbotSpeedController.php`: budget, computing and slow states.
- `resources/views/chatbot/speed.blade.php`, `resources/views/chatbot/partials/speed-not-sent.blade.php`: computing / slow states, reload, the Sequences script's slow answer.
- Tests: `tests/Feature/Chatblast/SpeedPrecomputedTest.php` (new, 9 tests), `tests/Feature/Chatblast/Support/BaseResponseSpeed.php` (the base 528326cc `ResponseSpeed`, only namespace and class name changed, as the "old code" reference), and the existing speed tests (listed below).

## Evidence per done-when item

### 1. SP-1 profile, tests, full suite

**Seeded MySQL** (8.0.42, port 3408, 1 GB buffer pool, laptop SSD; invented data): 120 pages (page 001 has 35% of the traffic, odd pages on the gateway, 10 pages without a flow), per full day ~300k chats (3% attachments ignored, 1% ad opens, 2% standby copies, 0.5% doubled) and ~1M replies (~400k bot replies with 3% failed first tries, 600k sequence steps over 200 sequences, 30k manual), 15% of chats with an AI request, 40k chatblast rows. 2026-11-16 and 2026-11-17 full, 2026-11-18 until 15:00 ("now"). Totals: `botcake_chats` 756,724, `chatbot_replies` 2,543,292, `chatbot_ai_requests` 103,960, `botcake_messages` 105,000. Indexes exactly as the migrations make them.

**SP-1: the base code, `ResponseSpeed::output()` (one page view: the day + its previous day), cache empty.** Time per query shape (MySQL) and the PHP time spent on that query's rows (the time until the next query):

| View | Total | MySQL | PHP |
|---|---|---|---|
| today, all pages | 14.4 s | 4.1 s | 10.3 s |
| today, big page | 8.1 s | 4.9 s | 3.3 s |
| today, small page | 2.3 s | 2.2 s | 0.1 s |
| today, bot Forbeast | 11.3 s | 3.2 s | 8.1 s |
| 2026-11-17, all pages | 19.3 s | 5.5 s | 13.8 s |
| 2026-11-17, big page | 12.7 s | 7.7 s | 4.9 s |

Sections (2026-11-17, all pages / big page):

| Section (query) | Queries | MySQL | PHP after | EXPLAIN |
|---|---|---|---|---|
| bot replies of the day + 24 h, per hour (`kind = send`, status sent/partial/failed, `sequence_id IS NULL`) | 96 | 3.01 / 3.18 s | 6.83 / 2.46 s | `range` on `chatbot_replies_sequence_id_sent_at_index` (all pages, ~21k rows per hour), `range` on `chatbot_replies_page_id_sent_at_index` (page) |
| chats of the day, per hour (with `payload`) | 48 | 1.35 / 0.55 s | 2.01 / 0.69 s | `range` on `botcake_chats_received_at_index` (~15k rows per hour; the page filter is a `Using where` on the same range) |
| AI requests (then the matching loop and the hour rows in PHP) | 2 | 0.07 / 0.12 s | 4.61 / 1.57 s | `ALL` (103k rows) / `ref` on `chatbot_ai_requests_page_id_psid_index` |
| volume: sequence steps, one query per sequence | 400 | 0.79 / **3.69 s** | 0.05 / 0.08 s | `range` on `chatbot_replies_sequence_id_sent_at_index`: `Using index` without a page; with a page `Using index condition; Using where` = one row lookup per step of the day (2.1 s even for a page with 490 messages) |
| volume: chatblast | 2 | 0.20 / 0.18 s | 0.28 / 0.10 s | `ref` on `botcake_messages_status_created_at_index` (52k rows) |
| page settings, information_schema | 73 / 13 | 0.04 s | 0.05 s | small |

Named cost: two full days per view, mostly PHP over every chat and reply of the day; and the sequence volume's row lookups for any page filter.

**After (this branch), same data:**

| | Time |
|---|---|
| `buildDay` today (15:00) | 6.2 s first, 5.4 s again (closed sequence hours from the cache: 2 count reads instead of 16) |
| `buildDay` 2026-11-17 / 2026-11-16 (full days) | 15.2 s / 18.3 s, peak 464 / 538 MB |
| page `output()` + render of `chatbot.speed`, 18 filters (today and 2026-11-17 × all, big page, small page, unknown page, Forbeast, Botcake, big page + Botcake, page 2 + Forbeast, page 2 + Botcake) | **10–55 ms**, 0 queries |
| same answer as the base code for those 18 filters | **yes, all 18** (`===` on the whole output: numbers, charts, dates, built_at) |

New query shapes (EXPLAIN on the seeded MySQL):
```sql
-- sequence steps per page, one hour (a closed hour once, then kept)
select page_id, substr(sent_at, 12, 2) as h, count(*) as n from chatbot_replies
 where sequence_id in (<ids of chatbot_sequences>) and sent_at >= '2026-11-17 10:00:00' and sent_at < '2026-11-17 11:00:00'
 group by page_id, substr(sent_at, 12, 2);
-- range on chatbot_replies_sequence_id_sent_at_index, rows 29,495, Using index condition; Using temporary (0.15 s for a busy hour)
-- chatblast per page name (the old query + page_name in the GROUP BY)
select page_name, substr(sent_at, 12, 2) as h, kind, count(*) as n from botcake_messages
 where is_test = 0 and status = 'sent' and sent_at >= '2026-11-17 00:00:00' and sent_at < '2026-11-18 00:00:00'
 group by page_name, substr(sent_at, 12, 2), kind;
-- ref on botcake_messages_status_created_at_index, rows 52,294 (as before)
```
The chat, reply and AI queries are the old default view's, unchanged.

**SP-3 on MySQL** (same server):
```
session before: 0
the slow SELECT without a budget: 409 ms
budget 0.2 s: stopped after 210 ms; isTimeout=yes; MySQL error 3024
session after: 0
budget 1: finished
session after: 0
the same SELECT afterwards, same connection: 361 ms (not limited)
live Sequences, 0.5 s budget, 200k events: 542 ms, too_large=yes, session after: 0
```

**Tests.** New `tests/Feature/Chatblast/SpeedPrecomputedTest.php` (SQLite, like the other speed tests), 9 tests, all passing:

| Test | Story | What it shows |
|---|---|---|
| `test_SP_2_every_filter_gives_exactly_the_numbers_charts_and_not_sent_list_of_the_old_code` | SP-2 H (equality) | Three bots, six pages (gateway, Botcake, no flow, not in the page list, chatblast only, Incepxionbot), three days of chats, replies, failed tries, AI, standby / ignored / ad-open / doubled chats, sequence steps (also of a deleted sequence), manual replies, chatblast (with a page, without one, test and failed rows). For **96 filters** (3 days × 8 pages incl. an unknown one × 4 bot choices) `output()` is `assertSame` to the base code's `output()` (every number, chart, date and built_at), and `notSentOutput()` too for the whole day, hour 10 and hour 23. Today is built twice first, so the cached closed sequence hours are in the comparison. |
| `test_SP_2_the_page_and_its_lists_read_no_chat_or_reply_table_and_answer_at_once` | SP-2 | After the warm, 6 filters: the page and the Not sent list read none of the six big tables, under 2 s, "Numbers as of 12:30". |
| `test_SP_2_a_day_not_built_yet_shows_computing_asks_the_warm_and_appears_after_its_run` | SP-2 | A day not built: no big read, the computing line, the 60 s reload, no charts, navigation kept; the day and its previous day on the wanted list; the Not sent list says computing; one warm run builds them; the page then shows the numbers. |
| `test_SP_2_the_warm_builds_at_most_two_asked_days_a_run_oldest_ask_first_and_drops_stale_asks` | SP-2 / cost | 4 asked days → 2 per run, oldest ask first; asks older than 30 min are dropped. |
| `test_SP_2_a_closed_sequence_hour_is_counted_once_and_kept` | SP-2 / cost | 12:30: 13 count reads, then 1 per run; a past day 24 then 0; 13:05 and 13:11 around the 10-minute settle; a new step in the current hour is counted by the next run. |
| `test_SP_2_the_cache_entries_are_compressed_and_a_broken_one_reads_as_computing` | SP-2 | Base64 text entries; the pages written; a broken entry is "computing", not an error. |
| `test_SP_3_a_timed_out_read_answers_at_once_with_the_too_slow_state` | SP-3 H | A MySQL 3024 from the page, the Not sent list and the Sequences endpoint: 200 with the too-slow state / `{slow: true}`, within 2 s, one warning each; 1317 also counts, 1064 does not; the page script retries the slow Sequences answer after a minute. |
| `test_SP_3_other_database_errors_are_not_hidden_as_too_slow` | SP-3 | 1064 is thrown. |
| `test_SP_3_the_budget_wraps_the_reads_and_is_put_back_after_an_error` | SP-3 | The closure's result and error pass through; no MySQL statement on SQLite (the MySQL run is above). |

Existing speed tests changed (all passing; every change is "build the day first, as the warm does" unless said):
- `LoadsSpeedSequences`: new helpers `warmSpeed()`, `speedGet()`, `speedJson()`; `speedPage()` warms first.
- `ResponseSpeedTest`: `out()` warms; GETs of the page go through `speedGet()`; `test_S_182_5_messages_refused...` and `test_an_entry_cached_by_the_earlier_screen_is_not_read` warm first; `test_today_is_cached_two_minutes_and_past_days_longer` is now `test_S_306_today_shows_the_numbers_of_the_last_warm_run_for_every_filter` (a new chat shows after the next warm run, not after the 2-minute cache; the 30-minute past-day cache constant is no longer asserted).
- `MetaReplySpeedTest`, `SpeedMemoryTest`, `AdOpenReadersTest`, `SpeedNotSentTest`: GETs / `output()` warm first (`SpeedNotSentTest`'s "no new read" check still uses a plain `getJson`).
- `SpeedPrewarmTest`: the cache keys (`DAY_KEY`, `stored()`), the warm's log line and the days it reads now include the day before yesterday; `test_S_201_2...` asserts that the filtered page reads no big table (it used to read `botcake_chats`); `test_S_201_1_the_warm_run_fills...` compares the cold page's charts with the base code (the cold page is now "computing"); `test_S_201_5_a_failing_part...`: the page shows "computing" and the next run builds it (it used to compute on demand); `test_S_277_4_a_final_past_day...`: the page asks, the warm builds the day once, and the page **with a filter** is served from the same build (it used to be computed and kept 30 minutes).

Full suite:

| Run | Tests | Assertions | Failures | Skipped | Time |
|---|---|---|---|---|---|
| Base 528326cc (clean `git archive` of that commit + the same `vendor/`) | 5861 | 103238 | 3 | 6 | 22:30 |
| Branch `6d42038c` | 5870 (+9, the new file) | 103751 | 3 | 6 | 20:33 |

Herd PHP 8.4.16, `vendor/bin/phpunit -d memory_limit=4G`. The same three fail on both, none new (the three that 302 and 304 also list):
1. `ExampleTest::test_the_application_returns_a_successful_response`
2. `Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
3. `OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_the_shared_owner_pages_render_byte_for_byte_as_at_the_base` with `/owner/private`

`php artisan view:cache` compiles all views on the branch.

### 2. Scheduled job, cost, cache keys, server checks

- **Job:** the existing `chatbot:speed-warm` (no schedule change). **Cost** on the seeded MySQL, `warmDefault()`, base vs branch:

| Run | Base | Branch |
|---|---|---|
| first run (no cache; branch also builds the day before yesterday) | 21.6 s, 811 queries (9.6 s MySQL), 352 MB | 39.2 s, 556 queries (12.8 s MySQL), 462 MB |
| 2 min later (today only) | 3.7 s, 281 queries (1.1 s MySQL) | 4.7 s, 83 queries (0.7 s MySQL) |
| 20 min later (yesterday + today) | 12.8 s, 623 queries (3.5 s MySQL) | 16.7 s, 225 queries (2.6 s MySQL) |

  Every run reads the same chat, reply and AI rows as the old default warm; MySQL time is lower (no per-sequence day count), PHP is ~1–4 s higher (every filter). A run with asked days adds one full-day build per day (12–18 s here), at most 2 per run, which equals one page view of a past day before this change (two full days). Peak memory 478 MB, under the job's 1024M.
- **Cache keys** (default cache store): `response-speed-day:v1:<date>`, `response-speed:v6:<date>:<page id or empty>`, `response-speed-not-sent:v2:<date>:<page id or empty>` (today 15 min, yesterday 35 min, final days 7 days); `response-speed-seq-hour:v1:<start>|<end>` (closed hours, 7 days); `response-speed:wanted:v1` (60 min) and the lock `response-speed:wanted-lock`. Unchanged: the Sequences keys, the warm stamps, the warm lock. Size: ~1.5–2 MB per day of every filter on this data, so about 15–20 MB for the 7 kept days, today and yesterday. The old `response-speed:v5:*` / `response-speed-not-sent:v1:*` keys are no longer read and expire on their own.
- **For the reviewer to check on the server** (after deploy):
  1. `grep "chatbot:speed-warm" storage/logs/laravel*.log | tail`: each run's seconds per day; today should stay well under 2 minutes (if a run takes longer than the interval, the next is skipped as today, and the page shows the last build with its "as of").
  2. Memory of that run (the line has no memory; `ps`/`top` during a run, or check that no "Allowed memory size" appears) against its 1024M.
  3. The cache store's size (Redis `INFO memory` on the cache instance, maxmemory 512 MB with LRU in `deploy/redis/likha-redis-cache.conf`; or the `cache` table).
  4. Open `/chatbot/speed` with no filter, a page, a bot, and an old date: the old date shows "computing" and fills within ~2 minutes.
  5. `grep "Chatbot speed too slow" storage/logs/laravel*.log`: should stay empty.
  6. EXPLAIN of the sequence count query above (with the real ids) on the busy site: a `range` on `chatbot_replies_sequence_id_sent_at_index`.

### 3. Branch pushed

`git push -u origin feat/306-speed-page-fast`. Code commits: `b271cc27` (service, controller, views, existing tests), `6d42038c` (new tests, the computing line's wording); the rest are docs. The local MySQL (port 3408) was stopped and its data folder deleted after the measurements; it and the bench scripts lived in the session scratchpad, never in the repo.

## Story changes

None. SP-1..SP-3 as written; the filters are served precomputed per filter (SP-2 offered both).

## Rulings

For the reviewer to confirm or change:

1. **The existing warm job does the work** (every 2 minutes, as today) rather than a new job; no new schedule entry. Its steady run is about 1 s longer than before (PHP for every filter), its 20-minute run about 4 s longer, plus one full day once a day (the day before yesterday) and up to two asked days per run.
2. **Cache, no table, no migration.** Values compressed (17 MB → 1.5 MB per day here).
3. **Every past day of every filter is kept 7 days once final** (the old code kept a filtered past day 30 minutes and recomputed it per view). After 7 days a day is "computing" again for a minute.
4. **Numbers of filtered views are up to one warm interval old** (2 minutes + the run), as the default view already was; before, a filtered view was computed fresh (with a 2-minute cache). The "as of" line shows it.
5. **The whole page waits for both days** (the day and its previous day for the dotted lines); a missing previous day shows "computing" too, not a page without reference lines.
6. **Sequence step counts of a closed hour are kept**: a sequence deleted after an hour was counted stays in that hour's count (the old code dropped it at view time); a step recorded more than 10 minutes after its hour ended with a `sent_at` inside that hour would be missed. Both are rare; the settle time is `SEQ_HOUR_SETTLE`.
7. **Chatblast per page matches the page name exactly in PHP**; the old SQL `page_name = ?` used the column's collation (case-insensitive on MySQL). Same numbers unless a chatblast row's `page_name` differs from the page's name only in letter case or trailing spaces.
8. **The page does not reload itself while it shows numbers** (only the computing and too-slow states reload after a minute, like the Sequences part while building); staff reload or move days as before.
9. **The Meta section is unchanged**: an HTTP call to Forbeast with its 5 s cap and 2-minute cache, not a database read; when Forbeast is slow it can still add up to ~5 s to a page load (not measurable here: no Forbeast link locally).
10. **The Sequences endpoint keeps computing filtered views live** (spec 306 says it is not the problem); it now also has the MySQL session limit for its 20 s budget.
11. **Budget values:** 5 s per SELECT for the page and its endpoints (`PAGE_SECONDS`); the slow state is HTTP 200, like 302.

## Merge danger

- **What ships:** PHP and two Blade views only: `ResponseSpeed.php`, `ChatbotSpeedController.php`, `chatbot/speed.blade.php`, `chatbot/partials/speed-not-sent.blade.php`, and tests (one new file, the reference copy of the base service, edits to the existing speed tests). No migration, no table, no index, no config, no `.env` key, no route, no schedule change.
- **Right after deploy:** the page reads new cache keys, so every view shows "Computing the numbers ... refreshes in a minute" until the first `chatbot:speed-warm` run after the deploy has finished (at most ~2 minutes plus that run; the first run also builds the day before yesterday, ~40 s on the seeded data, longer on the busy site). Old dates fill as they are opened (2 per run). `php artisan view:cache` (or the deploy's usual view clear) so the page script changes. Open tabs keep the old script until they reload.
- **Load:** the page itself no longer reads `botcake_chats`, `chatbot_replies`, `chatbot_ai_requests`, `chatbot_events` or `botcake_messages` (the Sequences endpoint's filtered views still do, as before). The warm run reads what it read before, plus one full day once a day and up to 2 asked days per run (numbers in Evidence 2). New statements: `SET SESSION max_execution_time = <n>` / `= DEFAULT` on the request's own connection (MySQL 5.7.8+; the servers are MySQL 8).
- **Cache:** keys listed in Evidence 2, ~15–20 MB in all on the seeded data; check the cache store's memory on the busy site after a day (Redis cache instance: maxmemory 512 MB, LRU).
- **Cherry-pick to the other site:** `b271cc27` and `6d42038c`. They touch the four app/view files above and the speed tests; no dependency on 301–305 or on a Likha-only file. Conflicts only if the other line changed `ResponseSpeed` (build, volume, warm, Not sent), `ChatbotSpeedController` or the two views. **Important:** `tests/Feature/Chatblast/Support/BaseResponseSpeed.php` must be that line's own `ResponseSpeed.php` from before the pick (copy it, change only the namespace and class name as in this file), so the equality test compares against that site's old code.
- **How to check on the server:** Evidence 2, the six checks.
- **Revert:** `git revert 6d42038c b271cc27` (no data or schema to undo). The new keys expire on their own (at most 7 days, harmless if left); the old code's keys are rebuilt by its warm on the next run.
