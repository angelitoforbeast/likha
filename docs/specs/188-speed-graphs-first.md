# Brief 188: Speed screen shows the graphs; tables are collapsed by default

## Why
The owner reads `/chatbot/speed` by the graphs. Today each section is a big table with its chart under it (resources/views/chatbot/speed.blade.php, partial chatbot/partials/speed-chart.blade.php, spec 185). He wants only the graphs visible, the tables hidden or expandable, hidden by default.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Each section shows its **heading and its chart first**; the section's table (and the slowest-sequences list) sits in a collapsed `<details>` with a summary like "Show table" / "Hide table", **closed by default**. | Owner: graphs only, tables expandable, hidden by default. |
| 2 | One small summary line under each chart heading with the day's key numbers from the table's totals row (e.g. "Day: 17,653 answered · avg 11.1 s · median 5 s · p90 15 s · max 13,340 s"), so the totals are visible without opening the table. | The numbers that matter without the table. |
| 3 | Open/closed state remembered per browser (localStorage wrapped in try/catch); a "Show all tables" toggle at the top is optional. Filters, day navigation and the charts are unchanged. | Convenience. |
| 4 | No change to numbers, queries, cache or access. | UI only. |

## Cases (S-188)
P1: .1 the page renders the four charts with every table inside a closed `<details>` (no `open` attribute) by default; .2 the summary line shows the totals row's values for each section; .3 opening a table shows the same table as before; .4 existing ResponseSpeedTest and Chatblast suites pass; P2: .5 390 px: charts readable, no horizontal page scroll.

## Done when
P1 pass; `tests/Feature/Chatblast` adds no new failures vs e919bec5. Result with a screenshot or rendered HTML evidence (desktop and 390 px) and deploy notes. End with "188: <done|blocked> — <one line>".
