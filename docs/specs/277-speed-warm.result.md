# 277: /chatbot/speed warm that works, Sequences that finish, a page that never waits — result

## Summary
All six parts are built in `ResponseSpeed`, `ChatbotSpeedWarm`, `ChatbotSpeedController` and the two Speed views. No
migrations, no new indexes, no schedule change.

1. **Stamps.** `ResponseSpeed::stamp()` accepts an int or a numeric string (Redis gives numbers back as strings). Both
   stamp reads (yesterday's page, each day's Sequences) use it.
2. **Memory.** The warm sets `memory_limit` to `1024M` when the CLI limit is unlimited or lower, and gives the old limit
   back when it ends. The budget stays 3/4 of the limit (768M in the warm). The web budget is unchanged.
3. **Sequences faster, same numbers.** `stepHistory()` is gone. What `originalDue()` needs is the latest history event
   (subscribed, ran, skipped, failed) of the same customer + sequence before each run. Now:
   - The first pass over the day (unchanged query) also records, per customer + sequence, whether a history event of
     the day comes before its first run.
   - Only the customers + sequences without such an event need the history from before the day, and only its last
     event: `lastBefore()` reads, per customer, the 10 newest sequence events before the day on the
     `(page_id, psid, created_at)` index (`ORDER BY created_at DESC, id DESC LIMIT 10`), 250 customers per query
     (`UNION ALL` of derived tables). A customer whose wanted sequences are not among those 10 is read again from the
     oldest row seen (keyset on `created_at, id`), until found or the history runs out.
   - The second pass (unchanged query) carries a running "last event / the one before it" per customer + sequence, so
     the previous event of each run comes from the stream itself. The two values live in one string per key. The
     `"\n"`-joined history of the whole day per customer (`$before`) is gone.
   - The history query uses `forceIndex('chatbot_events_page_id_psid_created_at_index')` (Laravel's index hint: `FORCE
     INDEX` on MySQL, `INDEXED BY` on SQLite) when `Schema::hasIndex` finds that index; if it is missing, no hint is
     used. This is a query hint, not a new index. Without it SQLite chose `(type, created_at)` for every customer,
     which means reading all sequence history before the day for each one (30 s instead of 0.7 s on the seeded data
     below). The same planner choice could explain part of the old 150–183 s of SQL on MySQL. I could not check that
     without the live database.
4. **Final days are built once.** `ResponseSpeed::isFinal($date, $window)`: the page is final at day end +
   `MAX_WAIT_SECONDS`, Sequences at day end + `STEP_LATE_SECONDS`. Every entry carries `built_at` and `final`. A final
   day's default entry (no page, no bot) is kept 7 days (`FINAL_KEEP_SECONDS`), whether the warm or a viewer built it.
   The warm skips a final Sequences entry that is complete, and a final page entry. A not-final yesterday keeps the old
   rhythm: page every `YESTERDAY_WARM_SECONDS`, Sequences every `SEQUENCES_WARM_SECONDS`. A missing entry is rebuilt
   on the next run whatever the stamp says. Yesterday's page is never final while it is yesterday (24-hour window), so
   in practice the 7-day rule is used by yesterday's Sequences after 06:00 and by older days opened on the page.
5. **The page never waits on the default view.**
   - TTLs: today's page from the warm is 15 min (`TODAY_WARM_SECONDS`) and is rewritten every run. Yesterday's page
     is 20 min + 15 min, Sequences 30 min + 15 min (`WARM_GRACE_SECONDS` is now 900, one slow run).
   - The page shows "Numbers as of HH:MM" and the Sequences section "As of HH:MM" (Manila), from `built_at`.
   - `/chatbot/speed/sequences` (section and chart) only reads the cache for the default view of today and yesterday.
     When the entry is missing it answers at once with `sequences.building = true` and the line "Binubuo pa ang
     Sequences; lalabas sa loob ng ilang minuto." It writes nothing, and the page script loads the section again after
     a minute. The warm builds it on its next run.
   - Filtered views (page or bot) and older days still compute on request as before. The JSON now has `built_at` next
     to `filters`, `sequences` and `html`.
   - When the warm's Sequences come out too large and no complete entry exists, the too-large note is written for the
     period, so the endpoint shows "too large" (with its time) instead of "building" forever. A complete entry is
     never replaced by a too-large one. The note's text changed from "within 2 minutes" to "later".
   - Order of a run: yesterday's page (when due), today's page, then yesterday's and today's Sequences. The fast page
     parts come first, so today's page is never behind a slow Sequences build.
6. **Log line.** Still one line per run, now with each part's seconds, e.g.
   `chatbot:speed-warm warmed 2026-10-20 (3.1 s), 2026-10-21 (2.0 s), 2026-10-20 sequences (40.2 s), 2026-10-21 sequences (12.5 s) in 57.9 s`.

Cache keys were bumped because the entries changed shape: `response-speed:v5:` (page) and `response-speed-seq:v2:`
(Sequences, now `{built_at, final, sequences}`). Right after a deploy, the default Sequences show "building" until the
first warm run finishes. The page builds itself once if a viewer comes before that.

## Files changed
- `app/Services/Chatbot/ResponseSpeed.php`: stamps, TTLs, final days, `built_at`, building state, `warmDefault()` split
  into `warmPage()`/`warmSequences()`, `sequences()` + `stepEvents()` + new `lastBefore()` + `originalDue()`.
- `app/Console/Commands/ChatbotSpeedWarm.php`: 1024M limit (restored after), seconds per part in the log line.
- `app/Http/Controllers/ChatbotSpeedController.php`: the endpoint asks for the cache-only default view; passes `builtAt`.
- `resources/views/chatbot/speed.blade.php`, `resources/views/chatbot/partials/speed-sequences.blade.php`: "as of",
  building line, retry after a minute.
- Tests: new `tests/Feature/Chatblast/SpeedSequencesSameTest.php`. New S_277 tests in `SpeedPrewarmTest`.
  `SpeedPrewarmTest`, `SpeedMemoryTest` and `LoadsSpeedSequences` were updated for the new endpoint, TTLs and log line
  (see below).

## Tests

### New
- `SpeedSequencesSameTest` (5 seeds): random data per seed: 150 customers over 3 pages, 12 days, 1–2 of 4 sequences
  each. One sequence's step 3 was deleted, and one sequence is missing from the table. The data has history before the
  day, double subscribes, ran/skipped/failed, retries (failed send then sent), sends outside the push window, wrong
  sequence IDs, partial and unconfirmed sends, steps late past midnight, same-second events, duplicate events, missing
  name / sequence_id / due, ignored ops (unsubscribed), other event types with sequence-looking detail, and IDs not
  in time order. "Burst" customers have 30 other-sequence events newer than their own last one before the day. The
  test compares `sequencesOutput()` with a verbatim copy of the old algorithm (`oldSequences()` and helpers) for 3
  days (today, yesterday with late steps, two days back) × 5 filters (all, two pages, each bot): `assertSame` on the
  whole output. It also asserts that the history read ran and that a second (keyset) read happened.
- `SpeedPrewarmTest`:
  - S_277_1: a stamp stored as a numeric string is respected (no rebuild within the period); an old string stamp
    triggers the rebuild.
  - S_277_2: the warm runs with 1024M (from -1 and from 512M; 2048M is left alone), budget 768M, and the limit is given
    back.
  - S_277_4: yesterday's final Sequences are built once, not rebuilt 30 min later while the page part is, and kept 6
    days (gone after 8). A final past day opened on the page is kept a week (served from the copy 5 days later);
    filtered views stay 30 min. A not-final yesterday (03:00) is rebuilt after 30 min, not before.
  - S_277_5:
    - The default page is served from the warm entry 9 minutes later with no reads of the big tables, and shows
      "Numbers as of 12:30". The Sequences section shows "As of 12:30" and `built_at`.
    - A missing default Sequences entry (today and yesterday) answers "building" with no `chatbot_events` read and
      writes nothing; the next warm run fills it.
    - Filtered views (page, bot) and older days are still computed on request.

### Changed (behavior the spec changes)
- `LoadsSpeedSequences`: when the endpoint answers "building" (or a default chart's day is missing), it runs
  `chatbot:speed-warm` the way the scheduler would, then reads again. The screen tests that use it keep their
  assertions.
- `SpeedPrewarmTest`:
  - endpoint keys include `built_at`;
  - S_201_3 "one day per call" and the time-budget test use a page filter, since the default view no longer computes
    on request;
  - S_201_1's cold comparison computes through the service and checks that the endpoint says "building"; it also
    checks the entry is still served 14 min after the warm;
  - the log regexes now include seconds and the new order;
  - the over-budget test is now "shown as too large and does not replace a full one";
  - the snapshot test leaves out `built_at`/`final` and loads the default sections through the trait (warm).
- `SpeedMemoryTest` S_198_4: the too-large answer of the default view is retried by the warm after
  `SEQUENCES_WARM_SECONDS`, not by the endpoint after 2 minutes.
- The S_198_1 fingerprint (`2aed0348…`) and the S_201_4 snapshot (old screen numbers) pass unchanged.

### Results
Base is `065f58cf` (a clean export of it, same vendor); after is `c1a05d84`. Herd PHP 8.4.16, PHPUnit 11.5.24.

| | `--filter "ResponseSpeed|Speed"` | full suite |
|---|---|---|
| before | 107 tests, 1192 assertions, OK | 5230 tests, 3 failures, 6 skipped |
| after | 120 tests, 1465 assertions, OK | 5243 tests, 3 failures, 6 skipped |

The 3 failures are the same known ones on both sides: `ExampleTest` root response (302), `ItemTableFitScriptTest`
min width, and `SharedPagesBasePinTest` S_42_4 `/owner/private`. The 13 new tests are 5 equality seeds and 8 S_277
tests.

The first full run after the change found that the memory-limit test could not set 512M once the suite process already
held ~680 MB. The test now uses a "lower" limit above the process's current use (at least 512M, still under 1024M);
the second full run is the one above.

## Old vs new Sequences on a seeded day
Measured in the test environment (SQLite in memory, same process), output asserted identical each time. These are two
runs of each, alternating.

**Fixture A: the spec 198 full day** (`SpeedMemoryTest::seedVolume()`: 50,000 customers × subscribe + 3 steps = 150,000
step events, their sends and 40,000 chats). On top of that, each customer has 10 events of another type over the past 20
days, and every 3rd customer has 6 older sequence events 3–8 days back. In total: 810,002 `chatbot_events`, 310,002 of
them sequence. Day 2026-10-21, all bots, all pages.

| | queries | history queries | time | peak memory (above base) | due |
|---|---|---|---|---|---|
| old | 203 / 197 | 100 | 28.8 s / 31.3 s | +49.2 / +48.5 MB | 150,000 |
| new | 100 / 100 | 2 | 9.1 s / 9.7 s | +46.2 / +46.2 MB | 150,000 |

The old history read returned 310,002 rows (every sequence event of those customers up to 06:00 the next day). The new
one reads at most 10 rows for each of the ≤ 500 customers whose day has no earlier event (most customers subscribed the
same day, so their history before the day is never needed).

**Fixture B: the equality test's generator at 8,000 customers** (163,826 events, 65,304 sequence, 26,579 sends; more
customers with history days back). Day 2026-10-20, all.

| | queries | time | peak memory (above base) | due |
|---|---|---|---|---|
| old | 111 / 105 | 1.2 s / 1.1 s | +3.1 / +2.4 MB | 18,933 |
| new | 107 / 107 | 0.7 s / 0.7 s | +2.8 / +2.8 MB | 18,933 |

The measuring scripts were throwaway tests and are not committed. They call the old copy in `SpeedSequencesSameTest`
and `sequencesOutput()` on the same data, with `DB::listen` counting queries and `memory_reset_peak_usage()` around
each run.

How to read this: SQLite in memory has no network and its `cursor()` streams. On the live MySQL, PDO buffers each
query's whole result even through `cursor()`. The old history query returned every sequence event of up to 500 customers
per query (310,002 rows in total on fixture A, all of it held in memory one chunk at a time). The new one returns at most
10 rows per customer and only for customers whose day has no earlier event. So the live gains in memory and SQL time
should be larger than the in-process numbers here. I could not measure them without the live database.

## Unsure / for the owner
- **Live effect not measured.** I could not run EXPLAIN on MySQL. The forced index is the obvious plan for an equality
  on `(page_id, psid)` with `LIMIT`, but it should be checked once on the live site (the warm log line now shows the
  Sequences seconds).
- **Index name.** The hint is used only when `chatbot_events_page_id_psid_created_at_index` exists (`Schema::hasIndex`,
  one information_schema query per Sequences build). If the live index has another name, the query runs without the
  hint, and MySQL may pick the `(type, created_at)` index like SQLite did.
- **Yesterday's chart reference.** The chart of yesterday's default view needs the day before yesterday. That day is not
  warmed, so it is computed on request as before, unless a final copy from the week is still in the cache (it usually
  is, since yesterday's Sequences become final at 06:00 and are kept 7 days).
- **After a deploy** the new key versions start empty: the default Sequences say "building" until the first warm run
  ends, and the first viewer of the page waits for one build.
- **Not-final yesterday page** is still rebuilt every 20 minutes for the whole day (24-hour reply window), as the spec
  says.
