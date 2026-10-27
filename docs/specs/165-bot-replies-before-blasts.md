# Brief 165: a big broadcast never slows the bot's replies

## Why
On 2026-10-27 at 09:00–09:24 and 10:00–10:02 (incepxion.pro), the hourly chatblast auto-run queued thousands of `botcake_send_requests` (kind chatblast, priority 1–2) across ~83 pages. Bot replies (kind chatbot, priority 0) on those pages then took up to 25–47 s instead of 2–3 s, for a few minutes. Staff noticed the bot "losing" its replies.

Cause, read in the code: `BotcakeSendPool` picks per page lane the ready job with the best rank, where `rank = waited > 10 s ? -1 : priority` (app/Services/Chatbot/BotcakeSendPool.php ~221–230). During a flood every chatblast job has waited > 10 s, so all of them get rank -1 and go ahead of a fresh bot reply (rank 0). The per-page rate (`services.botcake.rate_per_page`, 2/s, shared by every sender of that page) then makes the reply wait behind the whole backlog of that page. Also each page holds at most `perPage()` tickets in the pool (spec 089), and the claim query (`BotcakeSendQueue` ~106–121, `orderBy('t.priority')`) may leave a page "full" of chatblast tickets so a new bot reply isn't even claimed.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | A bot reply (priority 0) is always the next send of its page when one is ready: the ">10 s waited goes first" promotion only reorders jobs **within the same priority** (it keeps chatblast's per-customer lock fairness), never above priority 0. | Owner: fix it; replies must not wait behind broadcasts. |
| 2 | A ready bot reply for a page whose pool slots are all held by chatblast tickets is still claimed: reserve at least one claim slot per page for priority 0 (or let priority 0 bypass the per-page cap by one). In-flight chatblast calls are not interrupted. | Same. |
| 3 | The per-page rate stays 2/s and is still shared (no new limit, no Botcake burst). Broadcasts get slower by the replies' share only. | Botcake's per-page limit protects the pages. |
| 4 | No change to chatblast deadlines, retries ("Not sent in time; queued again"), sequences or the chatbot flow. | Small, safe fix. |

## Cases (S-165)
P1: .1 a page lane with 200 chatblast jobs all waited > 10 s plus one fresh bot reply → the bot reply is the next send (fake clock/HTTP), within one rate interval; .2 among chatblast jobs the > 10 s promotion still applies (an older priority-2 job beats a newer priority-1 one only as today — keep today's chatblast order unchanged; write the test from today's behaviour first); .3 a page whose pool share is full of chatblast tickets still gets its new bot reply claimed and sent first; .4 the per-page rate is never exceeded (sends spaced ≥ 1/rate s) in .1 and .3; .5 a benchmark-style test: 1,000 chatblast tickets on 5 pages + 1 reply per second per page for 60 s (fake clock) → every reply sent ≤ 1 s after it was ready, chatblast all eventually sent; .6 existing Botcake/Chatblast/Chatbot suites pass.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Unit` adds no new failures vs 87cb0e1e. Result: the change in a few lines, deploy notes (no migration; restart botcake:send-pool), and that the same commits apply to Incepxion. End with "165: <done|blocked> — <one line>".

## Out of scope
Raising the per-page rate; resending failed broadcast messages; Botcake HTTP 500s; deploying.
