# Result 185: Speed screen charts

The Speed screen (`/chatbot/speed`) now has four charts. Each one sits under its table, and each also shows the previous day's lines as a reference (amendment 185-1):

1. **Reply time by hour:** average (new), median and p90.
2. **With AI vs no AI by hour:** the With AI and No AI averages (solid), their medians (dashed) and the AI time average (thin).
3. **Replies pushed → sent at Meta by hour (new measure):** average, p90 and max. Max is on a second axis on the right, and its value is in the tooltip.
4. **Sequences by hour due, pushed → sent at Meta:** average and p90.

A new table, **"Replies: pushed → sent at Meta, by hour"**, sits under the With AI table. Chart 3 is drawn from it.

All the charts:
- follow the day, Page and Bot filters;
- use the same Chart.js CDN script as before;
- show the hour (`10:00–10:59`) and every value in seconds in the tooltip, plus the counts behind the point for the day and the previous day.

Hours with no number, and hours not reached yet, are gaps, not zeros.

## Definitions
- **Chart series.** `ResponseSpeed::charts()` builds them from the same rows the tables print, so a chart cannot disagree with its table. Each series has 24 values, and `null` is a gap. Chart.js draws it with `spanGaps: false`. The old chart used `spanGaps: true`, so its lines crossed empty hours.
- **Previous day (reference).** Viewing day D draws D-1 with the same Page and Bot filters, so "Yesterday" is compared with the day before. D-1 comes from the same cached computation (`ResponseSpeed::day()`), under the same cache key as viewing D-1 itself, so there is no new query shape. Its lines are thin, lighter and dotted, and labelled with the date, for example "Average (2026-10-20)". Clicking a legend entry hides or shows its line. D-1 is a finished day, so all 24 hours are drawn.
- **Pushed (replies).** `chatbot_replies.sent_at` of the bot's reply.
  - **Why this column:** `BotcakeOutbox::record()` writes it right after the provider call returns, and for Forbeast that is when the gateway accepted the message (HTTP 2xx, `GatewayProvider::send`). It is the moment Likha handed the reply to Forbeast.
  - **Other columns considered:** there is no separate queued time. The `accepted_at` in Forbeast's `send_status` event is not stored. `RealSendTime::lags()` uses the same `sent_at` → `confirmed_at` pair.
  - **Limit:** the gateway's own accept time (the POST round trip, at most the 10 s timeout) falls before this point, so it is counted in the reply time and not in pushed → Meta. See `TODO.md`.
- **Sent at Meta.** `confirmed_at`, the sent time Forbeast reports in `send_status` (`SendStatus::receive`), when `confirmed_status` is `sent` or `partial`.
- **Rows.** `kind = send`, `status` `sent` or `partial`, `sequence_id` null (sequence steps are in the Sequences section), and `sent_at` on that day. The hour is the pushed hour (Manila).
  - Replies Meta refused (`send_status` failed turns the status into `failed`) have no sent time and are not counted.
  - Test sends and failed sends are not counted.
- **Columns.**
  - **Pushed (Forbeast):** rows with `via = forbeast`.
  - **Measured:** pushed rows with a confirmed sent time. Average (1 decimal), median, p90 and max of `confirmed_at − sent_at` in seconds (nearest rank, the same functions as Bot Health; negative values are clamped to 0).
  - **Not confirmed yet:** pushed rows with no `send_status`.
  - **Botcake (no Meta time):** rows on any other `via`, counted apart. Botcake sends at once and reports no separate Meta time.
  - The table has a Day total row. Hours after the current hour (today) are blank.
  - **Bot filter:** Forbeast keeps the Forbeast columns and sets Botcake to 0. Botcake keeps only the Botcake count.
- **Chart 4.** The Sequences table's existing "Pushed → sent at Meta" average and p90 (spec 182-1), with its step count in the tooltip.

## Queries, indexes and time
- **No new query and no new index.** The pushed → Meta numbers are counted inside the existing `replies()` read on `chatbot_replies`:
  - It reads `sent_at` in [day, day + 2) with `kind`, `status`, `sequence_id`, and an optional `page_id`.
  - It uses index `sent_at`, or `(page_id, sent_at)` when a page is set.
  - A closure sees every row before the per-customer filter, and only `via` was added to its columns. That read now also runs on a day with no on-bot chats.
- **The previous day** is one more `build()` for D-1. It is cached under the same key that viewing D-1 uses (30 minutes, since D-1 is a past day).
- **Cache key** is now `response-speed:v2:…`. Entries cached by the earlier screen lack `push`, and reading them after deploy would fail, so the new key skips them. A test covers this.
- **Measured** with a throwaway test (not committed) on sqlite in memory, PHP 8.4. The fixture has two days, each with 35,000 chats on 40 pages and 30,000 replies; half of the replies are Forbeast and 90 % of those are confirmed. All runs below are cold, with nothing cached:

| Code | All pages | One page |
|---|---|---|
| 36013979 (before) | 0.30 s, +43 MB | 0.02 s |
| 0b5511ac (stage 1: D and D-1) | 0.72 s, +44 MB | 0.04 s |
| e3467b7a (stage 2: + pushed → Meta) | 0.92 s and 1.14 s on two runs, +44 MB | 0.05–0.06 s |

  - A cold Today view builds two days. After that, D-1 stays cached for 30 minutes, so a Today refresh rebuilds only today.
  - MySQL is not measured. Open `/chatbot/speed` once after deploy and note the time.

## Evidence (fixture day 2026-10-21 18:30, reference 2026-10-20)
The files were rendered by a throwaway test (not committed) and opened in headless Chrome through the DevTools protocol at 1280 px, and at 390 px with mobile emulation. They are in `C:\Users\Forbeast\AppData\Local\Temp\claude\C--mira-mira-office\1332ecd3-ded0-44d9-8ce1-615fd84c5036\scratchpad\evidence\`:
- `speed-185.html`: the rendered page.
- `speed-185-desktop.png`: the full page at 1280 px.
- `speed-185-desktop-1.png` … `-4.png`: charts 1–4.
- `speed-185-390.png`: the full page at 390 px.
- `speed-185-390-1.png` … `-4.png`: charts 1–4 at 390 px.

At 390 px:
- The page width is 390 (`scrollWidth` 390) and no element sticks out. The tables scroll inside their cards.
- The canvases are 332 × 256, and chart 2 is 332 × 320 for its longer legend.
- The charts were readable, so none is collapsed.
- In the fixture, p90 equals max in most hours (6 replies an hour), so chart 3's red Max line lies under p90 there.

## Files
- **Changed:**
  - `app/Services/Chatbot/ResponseSpeed.php`: `day()` (cache), `charts()`, `push` in `build()` through the `replies()` closure, and `pushRow()`.
  - `resources/views/chatbot/speed.blade.php`: each chart moved under its table; the new push section; one Chart.js renderer for the four charts.
- **New:**
  - `resources/views/chatbot/partials/speed-chart.blade.php`
  - `resources/views/chatbot/partials/speed-push-cells.blade.php`
- **Tests, stories and accepted minors:** `tests/Feature/Chatblast/ResponseSpeedTest.php`, `qa/stories.md` (S-185) and `TODO.md` (185 minors).
- **Unchanged (decision 6):** sending, flows, Bot Health and the other screens. The existing numbers stay the same, and all S-182 tests pass unchanged.

## Case table
| Case | Test (`tests/Feature/Chatblast/ResponseSpeedTest.php`) | Result |
|---|---|---|
| S-185.1 | `test_S_185_1_chart_1_has_average_median_and_p90_equal_to_the_hour_table` | pass: series equal the table; 46, 46 and 72 s at 10:00; gaps at 03:00 and 13:00 (red first: `Undefined array key "reply"`) |
| S-185.2 | `test_S_185_2_chart_2_with_ai_and_no_ai_series_equal_the_ai_table` | pass: 72, 20, 72, 20 and 48 s at 10:00 (red first) |
| S-185.3 | `test_S_185_3_replies_pushed_to_sent_at_meta_by_hour_with_botcake_apart` | pass: 10:00 row has pushed 3, measured 2, average 16.5 s, max 30 s, not confirmed 1, Botcake 1; sequence, test, failed and other-day rows excluded (red first: `Undefined array key "push"`) |
| S-185.3 | `test_S_185_3_a_push_just_before_midnight_counts_in_hour_23_and_the_bot_filter_keeps_its_own_column` | pass (added after review) |
| S-185.4 | `test_S_185_4_chart_3_series_equal_the_push_table` | pass: 16.5, 30 and 30; the reference max is 300 s (red first) |
| S-185.5 | `test_S_185_5_chart_4_matches_the_sequences_pushed_to_meta_columns` | pass: 62.5 and 120 s (red first) |
| S-185.6 | `test_S_185_6_the_page_bot_and_day_filters_change_all_charts` | pass: Page, Bot and day each change all four charts, and each still equals its tables (red first) |
| S-185.7 | `test_S_185_7_access_as_before` | pass: 403 for Data Encoder; a CEO sees four canvases (red first: no canvases) |
| S-185.8 | `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` (includes the Speed tests) | pass: see counts below |
| S-185.9 | `test_S_185_9_each_chart_sits_under_its_table_and_fits_a_phone` + browser render at 390 px | pass: markup test (red first), and the 390 px screenshots above |
| S-185.10 | `test_S_185_10_each_chart_has_the_previous_day_as_reference` | pass: D-1 series equal D-1's tables for all four charts; Yesterday compares with 10-19; the Page filter carries over (red first: `Undefined array key "prev_date"`) |
| (cache) | `test_an_entry_cached_by_the_earlier_screen_is_not_read` | pass (added after review; red with the old key: `Undefined array key "push"`) |

**Suite counts:**
- **Baseline at 36013979** (only the brief committed): OK, 1581 tests (1 skipped), 67 PHPUnit deprecations.
- **Stage 1 (0b5511ac):** OK, 1588 tests (1 skipped).
- **Stage 2 (e3467b7a):** OK, 1590 tests (1 skipped).
- **Final (after the review fixes):** OK, 1592 tests (1 skipped, the same 67 PHPUnit deprecations). No new failures.

## Story changes
- Added S-185 (.1–.10) to `qa/stories.md` from the brief's cases and amendment 185-1. S-185.10 is the amendment case.
- S-185.3 got a second test after review: midnight, and the Bot filter's Botcake column.

## Review
Skeptic reviewer, standard depth, on a828fbf2..e3467b7a. No blockers and no majors.
- **Fixed:**
  - A cached entry from the earlier screen would have caused a 500 for up to 30 minutes after deploy. The cache key is now versioned, and a test covers it.
  - Missing tests: a push across midnight, and the Bot filter's Botcake column.
- **Accepted in `TODO.md`:**
  - Pushed is when the gateway answered, not when the POST started.
  - A null `via` counts as Botcake, and the screen has three Bot-filter rules.
  - The confirmed rule is written three times.
  - The 0 s clamp on clock skew.
  - Two days are built on a cold Today view.
  - A few thin branches are untested.
- The reviewer's 390 px legend concern was checked in the browser render: chart 2 keeps a 332 × 320 canvas with a wrapped legend and stays readable.

## Conflicts
The project kit asks for a plan and the reviewer's "go" before code. The brief asked for a direct start with commits per stage, so I followed the brief.

## Deploy notes
- No migration, no env, no new table, no queue change, and no `npm run build` (Blade and the CDN Chart.js only). Deploy the code, then run `php artisan view:cache` if views are cached.
- The cache key changed (`response-speed:v2:`), so old Speed entries are simply ignored and expire on their own.
- After deploy, open `/chatbot/speed` (all pages, today) once and note the cold time. The first view builds today and yesterday.
- **Incepxion:** the same commits apply unchanged. The code is shared (the same tables, Speed screen and Chatbot nav), and none of it is Likha-only.

## Amendment 185-1

Verbatim:

> Amendment 185-1 (the owner, 2026-10-29: "kita din dapat yung graph nung kahapon para may reference"). Save it verbatim in the result under "Amendment 185-1" and list what you applied. | # | Change | Why | |---|---|---| | 1 | Every chart also shows the **previous day's** same series as reference: thin, lighter or dashed lines labelled "(yesterday)" or the previous date, for all 24 hours, on the same axes. Viewing day D compares to D-1 (so "Yesterday" compares to the day before). The legend can toggle them. Use the same cached computation for D-1 (no extra query shape). | The owner wants a reference to see whether an hour is slower than usual. | | 2 | Order of work: charts 1, 2 and 4 (from existing numbers) plus the yesterday lines first, committed as their own stage; then the new pushed → sent at Meta measure and chart 3. | So the simpler charts can ship first if needed. | Case S-185.10 (P1): viewing a fixture day D, each chart has D-1's series equal to D-1's table values.

### What I applied
1. **Previous-day lines on all four charts.** They are thin (1 px), lighter (40 % opacity) and dotted, labelled with the previous date, drawn for all 24 hours on the same axes (chart 3's max reference is on the right axis like its max), and toggled from the legend. D-1 comes from `ResponseSpeed::day()`, the same cached `build()` as viewing D-1, with the same filters. The tooltip footer gives the counts for both days. S-185.10 tests every chart's D-1 series against D-1's tables, and that Yesterday compares with the day before.
2. **Order of work.** Stage 1 was charts 1, 2 and 4 plus the previous-day lines, in 0b5511ac (1588 tests green, reported right away). Stage 2 was the pushed → sent at Meta measure, its table and chart 3, in e3467b7a.

185: done — the Speed screen has four charts under their tables (reply time with average, With AI vs No AI, the new replies pushed → sent at Meta table and chart, and sequences pushed → sent at Meta), each with the previous day as a dotted reference; no new query, table or migration.
