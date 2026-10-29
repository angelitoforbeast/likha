# Brief 196: the Live page becomes a TV scoreboard — input → process → output → orders, per bot

## Why
/chatbot/live is shown on a TV in the office. The owner finds it ugly: nothing reads from across the room, the activity is tiny dots, there is no story from customer message to reply to order, and it doesn't show which bot carried the traffic. He approved comp A ("Scoreboard") and asked for the screen split into input, process, output, with Bot 1 (Forbeast) and Bot 2 (Incepxionbot) separate.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **Layout and look of comp A**: black scoreboard, four huge numbers on top (Replies today, Avg reply time, Orders today, Active chats), the stage grid INPUT → PROCESS → OUTPUT → ORDERS with one row per bot (Bot 1 Forbeast, Bot 2 Incepxionbot, Botcake), the pulsing core with AI answers and follow-ups, the big gold orders number with the last order, a 9-line calm "Latest" stream, rails with a dot per message. Hide the app's banners/navigation on this page (a "TV mode" layout) with a small exit link. Works at 1920×1080 and 390 px; honours reduced motion. | Readable from across the room. |
| 2 | **Real data only** (no fake numbers): per-bot OUTPUT = confirmed replies grouped by `chatbot_replies.via` (forbeast / incepxionbot / botcake). Per-bot INPUT = inbound messages by the gateway that delivered them: mark every inbound chat with its source (Forbeast, Incepxionbot, Botcake) at receive time from now on (a key in the existing payload or the smallest column; no new table) and count today's; rows received before the marker existed count by the page's provider at that time if knowable, else under "unknown" (shown small). Avg reply time = today's average from Forbeast's Meta reply-speed (MetaReplySpeed, cached; "—" when not linked or down). Active chats = distinct customers who messaged in the last 5 minutes. Orders today and last order (page and time; amount if the order has one stored, else omit). Stream events get the bot name. | Honest numbers. |
| 3 | **Load:** the page polls every 5 s; all counters come from one cached endpoint (cache 5 s, shared by all viewers) using indexed date ranges; no full-day scans per poll (keep running totals or incremental counts since the last poll); state the measured query time on a realistic day (about 40k messages, 60k replies, 75k follow-ups). | It runs all day on a TV. |
| 4 | Small fix on the Speed page while here: in the "Reply time at Meta" section rename "by a person" to "by another app or a person" with a hint that Botcake replies count there. | Owner was confused by the label. |
| 5 | Applies to Incepxion (Bot 1 absent there → its row shows "not linked"). No change to sending or flows. | Same code. |

## Cases (S-196)
P1: .1 the four big numbers and per-bot input/output show today's real counts from fixtures; .2 replies via incepxionbot land in Bot 2's row; .3 inbound chats are marked with their source from now on and counted per bot; .4 the poll endpoint answers from cache within 5 s windows and its query time is stated; .5 Forbeast down → Avg reply time shows "—", the rest works; .6 TV mode hides navigation and banners; .7 390 px no sideways scroll; .8 the Speed label is renamed; .9 existing suites pass.

## Done when
P1 pass; `tests/Feature/Chatblast` adds no new failures vs 8705e881 (record the baseline). Result with screenshots at 1920×1080 and 390 px using fixture data, the query measurements, deploy notes. End with "196: <done|blocked> — <one line>".
