# Brief 179: the bot's replies always go before chatblast on a page's Botcake lane

## Why
For a page on Botcake, the bot's replies (`kind = chatbot`, priority 0) and chatblast (the ChatblastSender stepper: write → read → send_flow, three calls each) share the page's Botcake lane in app/Services/Chatbot/BotcakeSendPool.php (one lane per token, rate_per_page requests per second, perPage() tickets held per lane; priority 0 gets one extra ticket). At the 09:00 chatblast burst the lane is full of chatblast work, so customers' replies wait (Likha 2026-10-27/28: the 9 AM burst re-queued 4–8k chatblast sends, queued-to-sent averaged 449 s; chatbot sends share the path). The owner: the bot's reply must never wait for chatblast; chatblast can wait.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **Strict priority inside each lane**: whenever a lane may send its next request, a waiting bot request (priority 0 / kind chatbot) always goes before any chatblast step, including chatblast steps already queued in the lane (a chatblast job in the middle of its write→read→send_flow keeps its own order, but its next step waits behind any waiting bot request). | Reply first. |
| 2 | **Chatblast uses at most a share of the lane** (config, default 50% of rate_per_page, e.g. 1 of 2 requests/s) so a bot request arriving between two chatblast steps finds capacity at once; when no bot work is waiting, chatblast may use the rest. And when claiming tickets from the database, bot tickets are claimed before chatblast tickets for the same page (check `tick()`/claim order; priority already exists on rows). | Bot never starved by a burst. |
| 3 | Chatblast must still finish: no chatblast job waits forever (aging or the share guarantees progress); its deadline / "Not sent in time; queued again" rules stay as they are. Measure and report in the result how the 09:00 burst behaves in a simulation test (e.g. 500 chatblast jobs + 50 bot replies on one page: bot replies' wait vs before). | Chatblast can wait, not drop. |
| 4 | No change to Forbeast pages (they don't use this lane), to rates per page, the deadline rules, or chatblast content. No new tables. | Small and safe. |

## Cases (S-179)
P1: .1 a lane busy with chatblast: a bot request queued later is sent next (before remaining chatblast steps); .2 a chatblast job's own steps stay in order; .3 with no bot work, chatblast uses the full rate; with bot work waiting, chatblast uses at most its share; .4 simulation numbers (bot reply wait before vs after) in the result; .5 chatblast eventually completes under continuous bot traffic (no starvation); .6 existing BotcakeSendPool / Chatblast / Botcake suites pass.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` adds no new failures vs fe54c227 (record the baseline). Result with the config key/default, the simulation numbers, deploy notes (expected: no migration; restart the botcake-send pool / queue:restart), and that the same commits apply to Incepxion. End with "179: <done|blocked> — <one line>".
