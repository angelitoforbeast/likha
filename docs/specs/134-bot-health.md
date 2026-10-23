# Brief 134: a bot health dashboard

## Why
The owner wants to see at a glance whether the Likha bot is healthy: how fast it answers (average, max), how many replies failed and why, how the AI calls behave — today vs yesterday, and the last 7 days as graphs. Today these numbers only exist as ad-hoc SQL.

## Definitions (use exactly these; they match the numbers already reported to the owner)
- **Inbound message** = a `botcake_chats` row (`received_at`, `page_id`, `page_name`, `psid`) on a page that the bot answered at least once in the range.
- **First reply time** of an inbound message = `MIN(chatbot_replies.sent_at)` over rows with `chat_id` = that `botcake_chats.id`, `kind` = 'send', `status` = 'sent', minus `received_at`, in seconds. Messages with no such reply count as **not answered** (shown separately; do not put them into the time stats). All timestamps in these tables are Manila time already (app timezone Asia/Manila).
- **Replies** = `chatbot_replies` with `kind` = 'send': sent = `status` 'sent', failed = anything else. Failure **reasons** grouped from `error`/`http`: "Customer not reachable (#551 / isn't available)", "Botcake error (HTTP 5xx)", "Outside 24-hour window (#10)", "No answer from Botcake (HTTP 0 / timeout)", "Other". Show the failure rate **with and without** "Customer not reachable" (that one is not our fault).
- **AI calls** = `chatbot_ai_requests`: count, failed (error not empty or status failed), time = `answered_at - created_at` (avg, p90, max), retried (`attempts` > 1).
- **Orders** = `chatbot_events` type 'order'; **sheet writes failed** = `chatbot_replies` kind 'sheet' status 'failed'.
- **Queue now** = rows waiting in the queues the bot uses (Horizon/redis queues `chatbot-high`, `chatbot`; plus `encode` on the database queue) — show counts if readable, else omit with a note.

## Decisions (do not reopen unless something is actually broken)
| Decision | Why |
|---|---|
| **Page `/chatbot/health`** (same access as `/chatbot/pages`), linked from the chatbot section's navigation. Filters: page (all / one), range (default last 7 days, max 31). English UI. | One place to check health. |
| **Top: today vs yesterday tiles** for: inbound messages, answered %, first reply avg / median / p90 / max, replies sent, failed (% with and without "customer not reachable"), AI calls / failed / avg / max time, orders, sheet writes failed, queue now. Each tile shows the change vs yesterday. | The owner's "yesterday and today". |
| **Health light** per tile and one overall (green / amber / red) from thresholds stored in `app_settings` (key `bot_health_thresholds`, JSON) and **editable on the page** (CEO): defaults — reply p90 > 15 s amber, > 60 s red; max reply > 120 s amber; failed % without customer-not-reachable > 3 % amber, > 10 % red; AI failed > 2 % amber, > 10 % red; AI p90 > 10 s amber; answered % < 98 % amber, < 90 % red; sheet writes failed > 0 amber; queue now > 200 amber. Show the rule next to each light. | The owner wants every input of a decision shown and editable. |
| **Graphs (last N days, one point per day):** (1) first reply time avg, p90, max (lines); (2) replies sent vs failed stacked by reason (bars); (3) AI calls and AI avg/max time; (4) inbound messages and orders. **Today by hour** chart for reply time and failures. Use the chart library the project already uses (look for Chart.js or similar in the views); no new CDN if one exists. | "naka-graph". |
| **Slow list:** the 20 slowest first replies in the range (time, page, seconds, node, whether the customer sent several messages within 10 s before it — the known cause of waits) with a link to the conversation in the existing Inbox if there is one. **Failure list:** latest 20 failed sends with reason. No customer message text on the page beyond what the Inbox already shows. | Find the cause quickly. |
| **Performance:** one query per metric family per load, grouped by day (no per-row queries); cache the computed payload 5 minutes per (page, range); must load in under 2 s on ~10,000 inbound messages per week. If it can't without an index or a rollup, add the index in an additive migration (say which) — no rollup table in this spec. | Fast enough to open often. |
| Optional JSON endpoint `GET /api/chatbot/health?from&to&page` behind the existing events key (`CHATBOT_EVENTS_KEY`, same check as `/api/chatbot/provider-events`) returning the same numbers, so the analyst can read them. | Same numbers for the analyst. |

## Done when
- Tests: first reply time per the definition (incl. not answered excluded), percentiles, failure reason grouping and both failure rates, AI stats, today-vs-yesterday deltas, health lights from thresholds and the threshold save (CEO only, validated JSON), page filter, range cap, the JSON endpoint's key check, the page renders with charts' data embedded and escapes page names.
- `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` plus your new tests pass.
- Result: files, routes, the thresholds JSON shape, any migration (index) and why, query timings you measured on a seeded dataset, commits.

## Out of scope
Alerts/messages (Messenger) — later; changing the bot; deploy.
