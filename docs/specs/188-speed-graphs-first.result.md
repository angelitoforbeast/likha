# Result 188: Speed screen shows the graphs; tables are collapsed by default

Status: done. Branch `feat/188-speed-graphs-first`, based on e919bec5 (live). The numbers, queries, cache and access are unchanged. The amendment adds two display fields to the chart data, `shown` and `top`.

## What changed on `/chatbot/speed`
Each of the four sections now shows these, from top to bottom:

1. **The heading.** Same text as before.
2. **One summary line with the day's key numbers** (decision 2). The numbers come from the Day row of the section's table.
   - Reply time: `Day: 225 messages · 225 answered · avg 44.1 s · median 30 s · p90 91 s · max 492 s` (evidence fixture).
   - With AI vs no AI: `Day: with AI n answered · avg · median · p90 · max; no AI n answered · avg · median · p90 · max; AI time avg · p90 · max`.
   - Replies pushed → sent at Meta: `Day: n pushed (Forbeast) · n measured · avg · median · p90 · max · n not confirmed yet · n Botcake (no Meta time)`.
   - Sequences: `Day: n due · n sent · n failed · n skipped; scheduled → pushed avg · p90 · max; pushed → sent at Meta: n steps · avg · p90 · max`.
3. **The chart.** Same partial and same data, with amendment 188-1 applied (below).
4. **The table, in a closed `<details>`.**
   - The label reads "Show table", and "Hide table" while the table is open.
   - The table's explanation notes are inside the `<details>` too.
   - The Sequences section has a second closed `<details>`, "Show slowest steps", for the slowest-steps list.

**Open or closed state.** Each browser remembers which tables are open:
- It is stored in `localStorage` under the key `chatbot-speed-table:<table>` with the value `open`. Closing a table removes its key.
- Reads and writes are inside `try/catch`. In a private window, or with storage blocked, every table just stays closed.

**Not changed or not added:**
- The optional "Show all tables" toggle was not added.
- Filters and day navigation are unchanged.
- The table markup was moved, not edited. On the same fixture, the rendered tables and notes are byte-for-byte the same as e919bec5's (see Evidence).

## Amendment 188-1
Saved verbatim:

> Amendment 188-1 (the owner, 2026-10-29: "yes average"; asked whether average is right and whether the tables stay as they are — they do). Save it verbatim in the result under "Amendment 188-1" and list what you applied.
>
> | # | Change | Why |
> |---|---|---|
> | 1 | **Average is the only series visible by default** on every chart (for chart 2: the With AI and No AI averages; for charts 3 and 4: the average). Median, p90, max and AI time stay in the legend but start hidden (Chart.js `hidden: true`); clicking the legend shows them. The previous-day dotted line of the average stays visible. | The owner reads the average to spot something pulling the times up. |
> | 2 | **Dynamic y-axis**: top of the axis = about 3× the day's typical hourly average (e.g. the median of the 24 hourly averages, previous day's if today has too few hours), at least 10 s, rounded up to a clean number. A value above the top is drawn at the top edge (clamped) with its real value shown as a small label and in the tooltip. Recomputed when series are toggled on is not needed; keep it per chart from the average. | One spike (e.g. 363 s) must not flatten the other hours; no fixed limit line. |
> | 3 | **Tables unchanged** (same columns and numbers), only collapsed by default as in the brief. | Owner: table as is. |
>
> Cases: .6 default render has only the average series visible (others hidden in the legend); .7 an hour with avg 363 s on a day whose typical average is 6 s is clamped to the axis top with a "363 s" label, and the axis top is ≥ 10 s.

**What was applied:**

1. **Only the average shows at first.**
   - `ResponseSpeed::charts()` gives each chart a `shown` list:
     - reply, push and seq: `['avg']`;
     - ai: `['with_ai_avg', 'no_ai_avg']`.
   - The chart script sets `hidden: !c.shown.includes(key)` on each line and on its previous-day line.
   - Median, p90, max and AI time are struck through in the legend; clicking an entry shows its line.
   - The previous day's dotted average stays visible.
2. **The y-axis top adapts to the day.**
   - `ResponseSpeed::axisTop()` computes it per chart, from that chart's average series only:
     - it takes the median of the hours that have an average;
     - when today has fewer than 3 such hours, it uses the previous day's averages if that day has more;
     - the top is 3 × that median, at least 10 s, rounded up to a clean number (1, 1.5, 2, 2.5, 3, 4, 5, 6, 8 × 10ⁿ).
   - The chart sets `y.max` to this top.
   - A value above the top is drawn at the top edge as a larger triangle. Its real value is printed as a small label above the edge (a second line in the same hour is labelled just below it), and the tooltip shows the real value.
   - The top is not recomputed when other lines are switched on; their values above the top are clamped and labelled the same way.
   - The right axis of chart 3 (max) keeps its own scale, is not clamped, and now shows only while Max is switched on (`display: 'auto'`).
3. **Tables unchanged.** The rendered tables are identical to e919bec5's (see Evidence).

## Case table (S-188)
| Case | Test | Result |
|---|---|---|
| S-188.1 (P1) The four charts render. Every table (the 4 section tables and the slowest steps) is in a `<details>` with no `open`, and no table is outside one. Each section runs heading → summary → chart → table. localStorage is used inside try/catch. | `test_S_188_1_the_charts_show_and_every_table_is_in_a_closed_details` | pass |
| S-188.2 (P1) Each summary line shows its table's Day row. Reply: "Day: 4 messages · 3 answered · avg 32.3 s · median 20 s · p90 72 s · max 72 s". The AI, push and sequences lines equal their Day rows. | `test_S_188_2_the_summary_line_shows_each_tables_day_totals` | pass |
| S-188.3 (P1) Opening a table shows the same table as before, with its notes. | `test_S_188_3_opening_a_table_shows_the_same_table_with_its_notes`, the unchanged S-182/S-185 table tests, and the render comparison with e919bec5 (tables and notes identical) | pass |
| S-188.4 (P1) The existing ResponseSpeedTest and Chatblast suites pass. | `tests/Feature/Chatblast`: 1363 tests, 0 failures, 0 errors, 1 skipped (as at baseline) | pass |
| S-188.5 (P2) At 390 px the charts are readable and the page does not scroll sideways. | Headless Chrome at 390 px, with tables closed and with all open: `scrollWidth` 390, no element sticks out, canvases 332 × 256 (chart 2: 332 × 320) | pass |
| S-188.6 (P1, 188-1) By default only the average lines are visible; the other lines are hidden in the legend. | `test_S_188_6_only_the_average_series_are_shown_by_default` (the `shown` lists and the script wiring). Headless Chrome visible lines: reply "Average" and "Average (2026-10-20)"; ai "With AI avg", "No AI avg" and their 2026-10-20 lines; push "Average" and its 2026-10-20 line; seq "Average" and its 2026-10-20 line | pass |
| S-188.7 (P1, 188-1) With a typical average of 6 s, a 363 s hour is clamped to the axis top with a "363 s" label, and the top is ≥ 10 s. | `test_S_188_7_the_axis_top_is_about_three_times_the_typical_average_and_a_spike_is_clamped`: 6 s typical with a 363 s hour gives 20; the previous day is used when today has fewer than 3 hours; the minimum is 10; 123 rounds up to 150; the screen fixture gives 80. Headless Chrome, push chart: typical about 3 s, axis top 10 s; 16:00 = 363 s is drawn at 10 with the label "363 s", and the tooltip reads "Average: 363 s" | pass |

**Red runs:**
- **Slice 1:** before the view change, S-188.1, S-188.2 and the updated S-185.9 failed on their assertions. S-188.3 errored on the missing `<details>` (undefined index `hours`).
- **Amendment:** S-188.6 errored with "Undefined array key shown". S-188.7 errored with "Call to undefined method ResponseSpeed::axisTop()".

All of these pass after the change.

## Story changes
- **S-185.9** said each chart "sits under its table". Decision 1 of this brief puts the chart first:
  - The case now reads "sits with its table (above it since S-188.1; under it before)".
  - `test_S_185_9…` is renamed `…_sits_with_its_table_and_fits_a_phone` and checks chart-then-table order.
  - The phone-width part is unchanged.
- **Notes inside the `<details>`.** The tables' explanation notes went into the `<details>` with their table, for two reasons:
  - they explain the table's columns;
  - left outside, they would keep a long block of text between the charts.
- **S-188.6 and S-188.7** were added for amendment 188-1.

## Tests
- `tests/Feature/Chatblast/ResponseSpeedTest.php`: 31 tests, 382 assertions, OK. At e919bec5 it had 26 tests, all passing.
- `tests/Feature/Chatblast`: 1363 tests, 0 failures, 0 errors, 1 skipped, 52 PHPUnit deprecations (`chatblast-188-final.txt`).
  - After slice 1: 1361 tests, 0 failures.
  - Baseline at e919bec5: 1358 tests, 1 skipped, 52 deprecations, and no failures of its own. That run reported one failure, `test_S_185_9`, only because the view was edited while it ran. `ResponseSpeedTest` alone on e919bec5: 26 tests, OK.

## Evidence
A throwaway test (not committed) rendered the spec 185 fixture: day 2026-10-21 18:30, reference 2026-10-20. One change to that fixture: every Forbeast reply in the 16:00 hour takes 363 s to Meta, to test amendment 188-1. The pages were opened in headless Chrome through the DevTools protocol.

The files are in `C:\Users\Forbeast\AppData\Local\Temp\claude\C--mira-mira-office\1332ecd3-ded0-44d9-8ce1-615fd84c5036\scratchpad\evidence\`:
- `speed-188.html`: the rendered page.
- `speed-188-desktop.png`: the full page at 1280 px, tables closed. `speed-188-desktop-1.png` … `-4.png` are the four charts.
- `speed-188-390.png`: the full page at 390 px (mobile emulation), tables closed. `speed-188-390-1.png` … `-4.png` are the four charts.
- `speed-188-390-open.png` and `speed-188-desktop-open.png`: every table open.
- `speed-188-desktop.txt`, `speed-188-390.txt`, `speed-188-390-open.txt`, `speed-188-desktop-open.txt`, one per render. Each records:
  - the page width (`scrollWidth`) and any element sticking out;
  - the state of each `<details>`;
  - per chart: the axis top, the visible lines, and the clamped points.
  - At both widths the axis tops are reply 150, ai 200, push 10 and seq 80.
  - Clamped points: reply 16:00 = 185.7 s, ai With AI 16:00 = 250.3 s, push 11:00 = 69.5 s and 16:00 = 363 s.
- `speed-188-persist.txt`, recorded in order:
  1. First load: all tables closed, labelled "Show table".
  2. Click "Show table" on the push section: it opens, reads "Hide table", and its state is stored.
  3. Reload: it is still open.
  4. Close it and reload: it is closed and storage is empty.
  5. Push 16:00 tooltip: "Average: 363 s", drawn at 10 with axis max 10.
- `speed-188-e919.html`: the same fixture rendered with e919bec5's views.
- `speed-188-tables.txt`: the comparison of the two renders: 5 tables and 4 notes, both identical.

## Deploy notes
- **Files:** two views (`resources/views/chatbot/speed.blade.php`, plus a comment in `resources/views/chatbot/partials/speed-chart.blade.php`) and `app/Services/Chatbot/ResponseSpeed.php`.
- **ResponseSpeed.php:** `charts()` adds `shown` and `top`, and there is a new static `axisTop()`. `charts()` runs after the cached day, so the cache key and the cached shape are unchanged.
- **Nothing else to run:** no migration, no config, no asset build. The styles are inline, and the layout uses the Tailwind CDN.
- **After deploy:**
  1. Run `php artisan view:clear` if compiled views are cached.
  2. Open `/chatbot/speed`. Each chart should show only its average lines, with the summary line above it and a closed "Show table" below it.
- **Spec 187:** no overlap with its files (order encode).

## Files
- Changed:
  - `resources/views/chatbot/speed.blade.php`
  - `resources/views/chatbot/partials/speed-chart.blade.php`
  - `app/Services/Chatbot/ResponseSpeed.php`
  - `tests/Feature/Chatblast/ResponseSpeedTest.php`
  - `qa/stories.md`

188: done — Speed shows each section's summary and its average-only chart (adaptive axis, spikes clamped and labelled), with every table in a closed, remembered "Show table".
