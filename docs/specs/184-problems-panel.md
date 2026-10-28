# Brief 184: one clickable problems panel per page

## Why
The red banners on every Chatbot/Chatblast screen list problems as one long line ("⚠ Angela Lim: No flow named … +22 more — open Page settings"). The owner wants to click it and see, per page, everything that is wrong — setup errors, slowness, and incidents like the 2026-10-28 Forbeast freeze — in one place.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **One banner, collapsed by default:** "⚠ N pages with problems (U urgent)" — replaces the separate failing-pages and setup banners (keep their data sources). Click → it expands in place (no page change) into a list of pages, most urgent first; each page row lists **all** its current problems as short lines with a severity. Collapsing remembers per browser (localStorage, wrapped in try/catch). | Owner: click to see per page what's wrong. |
| 2 | **Problem kinds and severity** (reuse existing computations; no new detectors except where noted): 🔴 failing sends / restricted / permission (169); 🔴 customers not answered in 5 min (183); 🟠 watcher events in the last 6 h: moved to Botcake (reason), held, back on Forbeast (175/183) — show with time; 🟠 slow: the page's median first-reply time in the last 60 min ≥ 3× its own median of the previous 7 days at the same hour, or ≥ 60 s (from 182's computation, config); 🟠 double replies in the last 60 min (183); 🟡 setup X (171) — urgent (🟡→🟠) only for pages chatblast sends to, otherwise ⚪ "not sending, setup incomplete"; ⚪ **no chats**: a page whose bot is On that had ≥ 20 chats/hour on average in the same hour over the last 7 days and has 0 chats in the last 30 min (new, cheap query, cached). | All problems in one place, ordered by impact. |
| 3 | **Each line has a link** to where it is fixed or inspected: Page settings (filtered to the page), Inbox (filtered to the page / those customers), Health (page anchor), Speed (page filter), the page's auto_switch history. | Act from the panel. |
| 4 | Cached ≤ 60 s; visible to the roles that see the Chatbot/Chatblast menu (same as today). The collapsed line must stay one line at 390 px. When there are no problems, no banner. | Cheap and quiet. |
| 5 | No change to the detectors' thresholds (except the new slow/no-chats rules above, in config), sending, flows or the watcher. No new tables. | Safe. |

## Cases (S-184)
P1: .1 collapsed line counts pages and urgent ones; click expands the per-page list ordered by severity; .2 a page with a setup X, 4 unanswered customers and a watcher move shows all three lines with their links; .3 setup X on a page not sent by chatblast shows ⚪ not 🟠; .4 slow rule: median 70 s in the last hour vs usual 4 s → 🟠 "slow"; .5 no-chats rule fires only for pages with the usual volume at that hour and bot On; .6 no problems → no banner; .7 non-chatbot roles see nothing; .8 existing suites pass. P2: .9 390 px.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` adds no new failures vs the base merge (record the baseline). Result with the severity rules, config keys, deploy notes, and that it applies to Incepxion. End with "184: <done|blocked> — <one line>".
