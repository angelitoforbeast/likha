# Result: spec 201 the Speed page opens fast

**Status:** done
**Date:** 2026-10-30
**Branch / PR:** `feat/201-speed-prewarm` (worktree `.claude/worktrees/speed-201`), cut from 8506b25c. Not pushed and no PR, because the start prompt forbids pushing.
**Preview or run link:** none (no deploy); see How to run for a local run.

## Summary
- **Sequences load after the page.**
  - `/chatbot/speed` no longer computes Sequences. It renders every other section from its own cache entry.
  - The page script then calls `GET /chatbot/speed/sequences` twice: first the section, then `part=chart` for its chart. The section markup is unchanged; it moved into `partials/speed-sequences.blade.php`.
  - Each call does at most one day of Sequences work.
  - The section shows a loading line while it loads, and an error line with Retry if a call fails.
  - Sequences have their own cache, with the same TTLs as before.
- **The default view is pre-warmed.**
  - `chatbot:speed-warm` runs every 2 minutes: without overlapping, on one server, in the background, and with its own lock.
  - Every run rebuilds today's default view (no page, no bot): the page entry, the Not sent list and Sequences.
  - It rebuilds yesterday's view when its last warm is 20 minutes old, and right after midnight.
  - It writes to the keys the page reads, with TTL = the page TTL + 180 s, so an entry never expires between two runs.
  - A failing part is reported and not written, so the page builds it on demand as before.
  - Each run logs one line with its seconds.
- **A past day or a filtered view is bounded.**
  - The page request computes two days without Sequences. Locally on the spec 198 volume that is 1.3 s cold, against 57 s before.
  - A web Sequences call has a 20 s time budget, next to the spec 198 memory budget. Past either one it answers the existing "too large to compute now" note (200) instead of hitting php-fpm's 30 s limit.
  - The warm run (console) has no time limit, so the default views are always computed in full.
- **Amendment 1.**
  - A doubled chat (same page, PSID and received time) counts as one message.
  - Averages are untouched; nothing is trimmed.
  - New "Not sent" figures, per hour and per day, following the page and bot filters:
    - **Never sent:** a Main Flow or AI send failed, and no reply went through within 24 h.
    - **Sent late:** a reply went through after one or more failed sends, with how many and their average wait.
  - They show as three new columns in the hours table and as red bars on the reply chart (right axis).
  - A "Not sent list" box loads `GET /chatbot/speed/not-sent` when it is opened, and again on an hour change. The list is newest first, with time, page, bot, never or late, failed tries, wait, Meta code/subcode and an inbox link. It holds no customer text and no error text.
  - All of it comes from the same hourly reads; there is no per-message query.

## Progress
- Run 1 (2026-10-30): spec, plan and a base-code snapshot committed. The endpoint, warm command and schedule were built test-first, then amendment 1 (dedupe, Not sent, list). A review pass found 1 major and 7 minors: the major and 3 minors were fixed, 2 minors were noted or ruled on, and 2 were deferred. Measured before and after; full suite run. Status: done.

## Case table
| Case | Test | Result |
|---|---|---|
| S-201.1 the warm run fills the default view; opening it reads no big table | `SpeedPrewarmTest::test_S_201_1_the_warm_run_fills_the_default_view_so_opening_it_reads_no_big_table` | pass |
| S-201.1 today on every run, yesterday when due, midnight | `SpeedPrewarmTest::test_S_201_1_today_is_rebuilt_every_run_and_yesterday_only_when_due` | pass |
| S-201.1 schedule entry | `SpeedPrewarmTest::test_S_201_1_the_schedule_runs_the_warm_every_two_minutes_once_at_a_time` | pass |
| S-201.2 page without Sequences; loading line, error line, Retry; endpoint section and chart | `SpeedPrewarmTest::test_S_201_2_the_page_does_not_wait_for_sequences_and_loads_them_after` | pass |
| S-201.2 endpoint access and query cleaning | `SpeedPrewarmTest::test_S_201_2_the_endpoint_has_the_page_access_and_cleans_its_query` | pass |
| S-201.3 one day of Sequences per call | `SpeedPrewarmTest::test_S_201_3_each_sequences_call_computes_at_most_one_day` | pass |
| S-201.3 over the time budget, answers too large in time | `SpeedPrewarmTest::test_S_201_3_a_sequences_call_over_its_time_budget_answers_too_large_in_time` | pass |
| S-201.3 over the memory budget or failing, answers too large and retries after 2 min | `SpeedMemoryTest::test_S_198_4_a_sequences_section_over_its_budget_or_failing_leaves_the_rest_of_the_page` (now on the endpoint) | pass |
| S-201.4 same numbers as the base code, six filter sets | `SpeedPrewarmTest::test_S_201_4_the_page_and_the_sequences_endpoint_give_the_numbers_of_the_old_screen` | pass |
| S-201.4 same numbers on the spec 198 volume (fingerprint) | `SpeedMemoryTest::test_S_198_1_a_late_day_volume_renders_under_192_mb_with_the_same_numbers` | pass |
| S-201.4 Not sent from the same hourly reads | `SpeedNotSentTest::test_S_201_4_the_not_sent_counts_come_from_the_same_hourly_reads` | pass |
| S-201.5 lock held, so skipped with one line | `SpeedPrewarmTest::test_S_201_5_a_run_while_the_last_one_is_going_skips_and_logs_one_line` | pass |
| S-201.5 one line per run with its time | `SpeedPrewarmTest::test_S_201_5_each_run_logs_one_line_with_its_time` | pass |
| S-201.5 a failing part is not written and the page builds it on demand | `SpeedPrewarmTest::test_S_201_5_a_failing_part_is_reported_not_written_and_the_page_builds_it_on_demand` | pass |
| S-201.5 an over-budget Sequences answer is not written by the warm run | `SpeedPrewarmTest::test_S_201_5_a_sequences_answer_over_the_budget_is_not_written_by_the_warm_run` | pass |
| S-201.6 measurements | see Tests | done |
| S-201.7 a doubled chat counts once | `SpeedNotSentTest::test_S_201_7_a_chat_stored_twice_counts_as_one_message` | pass |
| S-201.8 never sent vs sent late, filters, chart, table | `SpeedNotSentTest::test_S_201_8_not_sent_splits_never_answered_from_answered_after_a_failure` | pass |
| S-201.9 the list: contents, order, hour/page/bot/day scope, access | `SpeedNotSentTest::test_S_201_9_the_not_sent_list_newest_first_with_page_bot_error_attempts_and_inbox_link` | pass |
| S-201.9 the list loads when opened, from the cache | `SpeedNotSentTest::test_S_201_9_the_page_loads_the_list_when_it_is_opened` | pass |
| S-201.10 full suite, no new failures | `tests/Feature` | pass: 3,691 tests, only the 2 known failures (`ExampleTest`, `ItemTableFitScriptTest`) |

Red runs before the code, first failure line of each:
- S-201.4: `all: page — Failed asserting that two arrays are identical` (the page still carried `sequences`).
- Warm tests: `Command "chatbot:speed-warm" is not defined`, and no schedule event found.
- Amendment tests: `Undefined array key "not_sent"`; `404` on `/chatbot/speed/not-sent`; the doubled 10:00 message was counted twice.
- Review follow-ups: the same-second retry was not counted as late; the warm wrote `too_large` and exited 1; `Undefined constant ResponseSpeed::SEQUENCE_SECONDS`.

The S-201.2 and S-201.3 endpoint tests were written right after the endpoint code, not before it. They passed on their first run.

## Story changes
- New cases from amendment 1: S-201.7 to S-201.9 and the Not sent part of S-201.4.
- One more S-201.3 case: the time budget. It was added after the local measurement showed that one cold day of Sequences on the spec 198 volume takes 42–46 s locally.
- Existing cases moved to the endpoint, because Sequences left the page:
  - `ResponseSpeedTest`: S-182.11, S-185.5/.6/.7/.9/.10 and S-188.1/.2/.3/.6/.7.
  - `MetaReplySpeedTest`: S-193.5.
  - These now read the Sequences HTML, numbers and chart from the endpoint, through `tests/Feature/Chatblast/LoadsSpeedSequences.php`. That helper puts the endpoint's HTML where the page's loading line is, as the script does. Their assertions are unchanged.
- `SpeedMemoryTest`, S-198.1/.2/.4: moved to the endpoint the same way.
  - The S-198.1 fingerprint (`2aed0348…`) is unchanged. Only the new `not_sent` keys are removed before hashing; they are all zero in that fixture.
  - S-198.4 now checks the note in the endpoint's HTML, and the 2-minute retry on the endpoint.
- The hours table gains three columns at its end. Tests that read the first cells of a row are unaffected.

## Done-when checklist
- [x] P1 cases pass with tests (case table above).
  - Targeted run: `ResponseSpeedTest SpeedMemoryTest MetaReplySpeedTest SpeedPrewarmTest SpeedNotSentTest ProblemsPanelTest` gives `Tests: 78, Assertions: 957, PHPUnit Deprecations: 1`.
  - No failures. The one deprecation is in `BotcakeMockTest` and is unrelated.
- [x] The full `tests/Feature` run adds no new failures vs 8506b25c: 3,691 tests, 71,150 assertions, 2 failures (the known `ExampleTest` and `ItemTableFitScriptTest`), 4 skipped, 92 PHPUnit deprecations.
- [x] Measurements: see Tests.
- [x] Scheduler entry: see Deploy notes.
- [x] Deploy notes: below.
- [x] Final line: at the end.

## How to run
- **Tests:** `"/c/Users/Forbeast/.config/herd/bin/php84/php.exe" -d memory_limit=1G vendor/bin/phpunit tests/Feature/Chatblast/SpeedPrewarmTest.php tests/Feature/Chatblast/SpeedNotSentTest.php`, plus the other Speed files listed above. `SpeedMemoryTest` needs about 200 MB.
- **Warm by hand:** `php artisan chatbot:speed-warm`. It prints and logs one line, for example `chatbot:speed-warm warmed 2026-10-20, 2026-10-20 sequences, 2026-10-21, 2026-10-21 sequences in 11.5 s`.
- **Measurements:** throwaway test classes in the session scratchpad, not committed: `measure/SpeedMeasureTest.php`, `measure/SpeedProfileTest.php` and `measure/bootstrap-before.php`.
  - They extend `SpeedMemoryTest` to reuse its fixture.
  - The "before" run uses the 8506b25c `ResponseSpeed`, loaded by a bootstrap ahead of Composer.

## Rulings
- **The kit's workflow vs the start prompt.** The project's `CLAUDE.local.md` asks for the reviewer's "go" on the plan and for `/ship` (push and PR). The start prompt says to build without waiting for a go, with no push and no deploy. I followed the start prompt: the plan was committed and the build went on, and nothing was pushed. I note it here because the kit asks for conflicts to be noted.
- **No browser check.** The worktree has no served site with data, and `.env` is off limits.
  - The page script was checked with `node --check` on the extracted script: OK.
  - Its parts are asserted in the HTML: loading line, error line, Retry wiring, `part=chart`, the hour change and the chart's `c.not_sent`.
- **The doubled-chat rule also applies to the problems banner.** `pageSeconds()` feeds the banner's "slow" check. The amendment says "in ResponseSpeed", and the banner's measure is defined as the Speed screen's, so the two stay one measure. Asked under Open questions.
- **Failed tries are not in the day's summary line.** They are kept per message, in the list and in the data. Summed over messages, one failed send before a burst of messages would be counted once per message.
- **The stored list is capped at the newest 200 per hour**, with full per-hour counts, so the cache entry stays small on a day of mass failures. The box shows the newest 200 of the day or hour.

## Deferred minors
- The page entry, the Not sent list and Sequences are separate cache entries. If one is rebuilt between the page load and the later calls, the list or Sequences can be a minute newer than the page's numbers. This is cosmetic, and it does not happen on the warmed default view.
- When a warm part fails, `report()` adds the usual error log entry, on top of the run's one line.

## Merge danger
- `routes/web.php`: two routes are added after `/speed`, next to the `/live` routes that the parallel spec 200 worktree edits. Expect a small adjacent-line conflict; keep both sides.
- `qa/stories.md`: S-201 is appended at the end, where spec 200 also appends. Keep both sections.
- `routes/console.php`: one entry, added before the content-check entry.
- `resources/views/chatbot/speed.blade.php`, `partials/speed-cells.blade.php`, `ResponseSpeed.php` and `ChatbotSpeedController.php` are touched only by this spec.

## Deploy notes
- **No migration.**
- **Scheduler entry.** The existing `schedule:run` cron picks it up, on Likha and on Incepxion:
  ```php
  Schedule::command('chatbot:speed-warm')
      ->everyTwoMinutes()
      ->withoutOverlapping(10)
      ->onOneServer()
      ->runInBackground();
  ```
  `onOneServer` and the command's own lock need a cache store with locks. Production uses Redis, which has them.
- **Cache keys.**
  - The page entry moves from `response-speed:v2:<md5>` to `response-speed:v3:<md5>`, because its shape changed: `sequences` is gone and `not_sent` is new. The old entries are ignored and expire within 30 minutes.
  - New keys: `response-speed-seq:v1:<md5>`, `response-speed-not-sent:v1:<md5>`, `response-speed:warmed:<date>`, and the lock `response-speed:warm`.
  - The first default view after deploy is cold until the first warm run, which is at most 2 minutes away.
- **CLI memory.** If the CLI memory_limit is unlimited (-1), the command sets 512M for its run, so the 3/4 Sequences budget holds. If the CLI limit is lower than php-fpm's, the warm run may find Sequences "too large". It does not write that answer, so the endpoint computes Sequences within its own budget.
- **No npm build.** The scripts are inline in the Blade view, and Chart.js is unchanged.
- **After deploy,** watch the `chatbot:speed-warm` log lines. Their seconds are the production cold cost of the default view: today's on every run, plus yesterday's every 20 minutes.

## Tests
**Full suite.**
- Baseline on 8506b25c: 3,674 tests. It failed in `ExampleTest` and `ItemTableFitScriptTest`, the 2 known failures.
- The baseline run also showed 2 `SpeedMemoryTest` failures. My source edits, made while it was running, caused them; both pass on the branch.
- Final: 3,691 tests, 71,150 assertions, 2 failures (the known `ExampleTest` and `ItemTableFitScriptTest`), 4 skipped, 92 PHPUnit deprecations.

**Measurements (S-201.6).**
- Setup: local, Windows, PHP 8.4 CLI without OPcache, sqlite in memory.
- Data: the spec 198 volume: 150,000 sequence events (50,000 customers × 3 steps) with their sends, and 40,000 chats with replies, all on 2026-10-21.
- "Past" means the page is opened on 2026-10-22 08:00; "today" means it is opened on 2026-10-21 23:30.
- Peak is the call's own peak memory.

| View | Call | Before, cold | After, cold | Before, warm | After, warm |
|---|---|---|---|---|---|
| past day | page | 57.1 s, 494 queries, 90.1 MB (everything, Sequences included) | 1.33 s, 162 queries, 46.5 MB | 0 s, 0 queries | 0 s, 0 queries |
| past day | Sequences section | (in the page) | 45.7 s, 196 queries, 48.7 MB | — | 0 s, 0 queries |
| past day | Sequences chart (previous day) | (in the page) | 8.3 s, 136 queries, 12.3 MB | — | 0 s, 0 queries |
| today | page | 59.2 s, 488 queries, 89.4 MB | 1.39 s, 156 queries, 45.9 MB | 0 s, 0 queries | 0 s, 0 queries |
| today | Sequences section | (in the page) | 42.5 s, 196 queries, 48.5 MB | — | 0 s, 0 queries |
| today | Sequences chart | (in the page) | 8.0 s, 136 queries, 12.3 MB | — | 0 s, 0 queries |
| today, after one `chatbot:speed-warm` run (53.7 s, 488 queries, 48.8 MB) | page, section and chart | — | 0 s, 0 queries each | — | — |

**Where a cold Sequences day goes locally.** One section call took 40.5 s:

| Part | Time |
|---|---|
| Reading the customers' history (`stepHistory`: `chatbot_events` type=sequence, `page_id IN`, `psid IN` in chunks of 500) | 27.2 s |
| The two hourly passes over the day's sequence events | 12.0 s |
| The step sends | 1.1 s |

Local times are slower than production: production's cold build of today was 11.5 s for both days, against 59 s here. The shape matches production's: Sequences are most of the cost, and history is most of Sequences.

**How a past day is bounded in production.**
- The page request does no Sequences work.
- Each Sequences call computes at most one day.
- A web call stops at 20 s with the "too large" note, before php-fpm's 30 s.
- The default today and yesterday views never wait: the warm run computes them in the console, without a time limit.

A non-default view of a very busy past day can still show "too large" for Sequences when one day's history read takes over 20 s. The index under Proposed tasks is the real fix for that.

## Open questions
1. **How long does one cold day of Sequences take in production?** I can't tell from here. The spec's figures (about 16 s of history and about 8 s of hourly passes on a past day) may cover one day or two. If one day, a busy past day outside the default view will show "Sequences too large" after 20 s instead of the numbers. The first days of `chatbot:speed-warm` log lines will show the real seconds. Should the index under Proposed tasks be planned for a night window?
2. **Should the problems banner keep counting doubled chats?** The doubled-chat rule now also applies to the banner's "slow" check (`pageSeconds()`), so the banner and the Speed page keep one measure. Is that wanted?

## Process suggestions
- Don't edit sources in a worktree while its baseline suite is running. PHPUnit loads application classes lazily, so later test files see the new code; here that gave two false baseline failures, which I re-checked against the known baseline.

## Proposed tasks
- **An index for the Sequences history read.** This is a night-only ALTER and needs the owner.
  - The slow query: `select page_id, psid, detail, created_at from chatbot_events where type = 'sequence' and page_id in (…~30) and psid in (…500) and created_at < ? order by created_at, id`. It runs once per 500 customers of the day.
  - The only index that serves it is `(page_id, psid, created_at)`. With it, MySQL reads every event of each customer, of every type, keeps the sequence ones, then filesorts.
  - Candidates: `(type, page_id, psid, created_at)` or `(page_id, psid, type, created_at)`.
  - I have no production access, so there is no EXPLAIN here. To check, run `EXPLAIN FORMAT=JSON` on that query for one chunk of yesterday's customers, and compare `rows_examined_per_scan` with the number of sequence events those customers have.
- **Without an index: a resumable history read.** The Sequences call would compute in slices across calls and cache the partial history. That removes the "too large" case without a schema change, at the cost of more code.

201: done — the page opens without Sequences (1.3 s vs 57 s cold locally); Sequences and the Not sent list load after it from their own endpoints; the default view is rebuilt every 2 minutes; doubled chats count once
