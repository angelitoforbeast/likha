# Result 198: the Speed page must not run out of memory late in the day

Status: done. Branch `fix/198-speed-memory`, based on 5b99fef7 (live). The page looks the same. Every number on it is identical to the old code's (checked with a fingerprint of all view data on a large fixture). The cache key is unchanged.

## What changed (`App\Services\Chatbot\ResponseSpeed`)
- **sequences()** keeps no rows. It reads the day's sequence events twice:
  - The reads are per hour window, ordered by `created_at, id`, and select only `id, page_id, psid, detail, created_at`. Each `detail` is decoded once per read and nothing is kept.
  - **Pass 1** collects the pages, PSIDs and sequence names, plus each customer + sequence's first run of the day. It also returns the highest event ID it read.
  - **Pass 2** counts, using only events up to that ID. The old code read the events once, before the sends. A step written while the page reads the sends is therefore left for the next load, as before. Without this cap it would be counted as "no message".
- **stepHistory()** streams each 500-PSID chunk with `cursor()`. It keeps history only for customer + sequence pairs that have a step that day. Before that day's first run, it keeps only the last event. Each pair's history is one `"\n"`-joined string.
- **stepDelays()** is split out of stepHistory() unchanged.
- **stepSends()** stores each send as 25 packed bytes (`pack('JJJC')`: sent_at, sequence ID, Meta time, flags) in one string per page:PSID:step. It reads per hour window in the same `(sent_at, id)` order. **stepSend()** walks those bytes with the same rules as before: the first send in the 600 s before the run, and the first delivered send for Meta and Botcake.
- **Slowest 20:** a min-heap of 20 entries keyed `[delay, pushed, read order]` replaces the list of one string row per sent step (tens of thousands). Strings are built only for the 20 that remain. Ties are broken by read order (`created_at, id`), which is what the old unordered read returned on the `created_at` index.
- **The chats and replies reads in build()** stay `cursor()` with scalar lists, but they now go per hour window. The MySQL driver buffers a whole cursor result in PHP memory, so before this the full day of chats, with their payload JSON, sat in memory at once. The `(sent_at, id)` order of the replies across windows is the same. `pageSeconds()` (problems banner) uses the same reader, so its output is unchanged.
- **Section cap:** the Sequences section runs inside `try/catch (Throwable)`:
  - **Budget:** 3/4 of `memory_limit` (`memory_get_usage(true)`), checked after every hour window and history chunk. Going over it throws.
  - **On any failure:** the error goes to `report()`. The section shows only the note "Sequences section too large to compute now. The other sections are complete; this one tries again within 2 minutes." The rest of the page renders.
  - **Cache:** such a day is kept 2 minutes instead of 30. The other numbers are cached as before.
- **View** (`chatbot/speed.blade.php`): an `@if` around the Sequences section for the note. Nothing else changed.

## Peak memory and time (large fixture, sqlite, Windows/Herd PHP 8.4)
The fixture has:
- 150,000 sequence events due that day (50,000 customers × 3 steps, each subscribed first; every 10th also has a run the day before; every 50th has no sequence_id);
- 132,000+ step sends;
- 40,000 chats with realistic payloads, and 36,000 replies.

| | Old code (5b99fef7) | New code |
|---|---|---|
| Peak (`memory_get_peak_usage`, test alone) | **635.7 MB** (needed `memory_limit=4G`; dies at 512 MB like the server) | **149.6 MB** |
| Request's own peak (peak − 58.1 MB before the request) | 577.9 MB | 91.5 MB |
| Request time (day + previous day, rendered) | 24.5–30.9 s | 24.6–29.2 s |
| Fingerprint of all numbers | `2aed0348c883fd4283b20bf8e706a451` | same |

The time is mostly PHP in the per-step due computation, which this change did not touch. Database time was about 5 s of it.

## Case table (S-198)
| Case | Test (`tests/Feature/Chatblast/SpeedMemoryTest.php` unless noted) | Result |
|---|---|---|
| S-198.1 (P1) | `test_S_198_1_a_late_day_volume_renders_under_192_mb_with_the_same_numbers`: the totals (150,000 due, 126,142 sent, 11,703 failed, 7,500 skipped, 4,655 no message; 40,000 messages, 36,000 answered), the top slowest row, and the md5 of every number on the screen, all as the old code gave them; the request's own peak is under 128 MB. `test_S_198_1_a_step_written_while_the_page_reads_is_not_counted_half` (added after review) | pass |
| S-198.2 (P1) | `test_S_198_2_the_slowest_20_are_the_same_rows_in_the_same_order_as_before`: 34 steps with ties on delay and pushed time; the expected order was captured from the old code | pass |
| S-198.3 (P1) | `ResponseSpeedTest.php` (31 tests) and `MetaReplySpeedTest.php` (15 tests), unchanged files | pass |
| S-198.4 (P1) | `test_S_198_4_a_sequences_section_over_its_budget_or_failing_leaves_the_rest_of_the_page`: budget 0, and an exception inside the section. Both give 200, the note, no sequences tables, the other tables and the charts; on a past day it is computed again after 2 minutes | pass |
| S-198.5 (P1) | `tests/Feature/Chatblast`: 1447 tests OK (baseline 1443 plus the 4 new), the same 1 skipped and 52 deprecations | pass |

**Red runs:**
- S-198.1 on the old code: "request peak 577.9 MB … Failed asserting that 605965416 is less than 134217728".
- S-198.4 on the old code: the note was missing from the page.
- The snapshot test failed on 71aec1e4, before the review fix: due and sent were 4 and 4, expected 3 and 3. It passes on the old code.
- S-198.2 and the fingerprint are characterisations captured from the old code, so they passed there by construction.

## Tests
- **Baseline at 5b99fef7**, `tests/Feature/Chatblast`: OK, 1443 tests, 1 skipped, 52 PHPUnit deprecations.
- **After the first slice (71aec1e4):** 1446 tests, 1 failure. It was S-198.1's absolute 192 MB check: in the full-suite process the peak was 342.8 MB, because the process already carries about 250 MB from earlier tests. The test now measures the request's own peak (under 128 MB; 192 MB minus the 58 MB framework baseline seen when the test runs alone).
- **Final (a9aba87c):** OK, 1447 tests, 50,521 assertions, 1 skipped, the same 52 PHPUnit deprecations. No new failures.

## Review
Skeptic reviewer, standard depth, on 5b99fef7..71aec1e4:
- **Major (fixed in a9aba87c):** pass 2 re-read the live table after the sends, so on today's page a step written in between could be counted without its send ("no message") or its history. Pass 2 now stops at pass 1's highest event ID, and a test covers it.
- **Minors fixed:**
  - the budget uses `memory_get_usage(true)`;
  - the fixture now covers earlier runs (only the last one before today is kept) and events without sequence_id.
- **Minors accepted in `TODO.md`:**
  - tie order under a Page filter on MySQL;
  - the budget cannot stop every fatal;
  - any section error shows the "too large" note;
  - an empty previous-day reference line;
  - the events are read twice;
  - MySQL buffering not measured.
- **Checked equivalent by the reviewer:**
  - history compaction;
  - packed sends;
  - window boundaries;
  - reply order;
  - the heap versus the old stable sort;
  - pushed as an int (Asia/Manila);
  - numeric names;
  - cache behaviour;
  - `pageSeconds`.

**Re-check of a9aba87c:** M1 fixed, no new major. One more minor is accepted in `TODO.md`: an event dated an hour earlier but inserted between two window reads can still be counted, and only its due fallback differs. The old code was re-run on the widened fixture; it gave the same fingerprint `2aed0348…`, which is the value the test asserts.

## Deploy notes
- Code and view only: no migration, no config, no new env.
- Run `php artisan view:clear` after deploy (the speed view changed).
- **Cache:** the key stays `response-speed:v2`, because a computed day has the same shape. The new failure shape (`too_large`) is never in an old entry. Old entries stay readable; nothing to flush.
- **After deploy:** open `/chatbot/speed` late in the evening, and watch the log for "the sequences section is over its memory budget".

## Story changes
- Added S-198 (.1–.5) to `qa/stories.md` from the brief.
- **S-198.1 changed:** it now measures the request's own peak (under 128 MB) instead of the process's. When the test runs alone, the absolute peak is 149.6 MB, under 192 MB. After review it also gained "a step written while the page reads the sends is left for the next load".

198: done — the Speed page computes the 150k-step day in 149.6 MB peak instead of 635.7 MB, with identical numbers, and shows a note instead of a 500 if Sequences ever go over budget.
