# Brief 168: a send Botcake refuses doesn't restart the customer's conversation

## Why
2026-10-27 (incepxion.pro, page Goods & More Outlet, customer psid …3178): Main Flow n1→…→n118 image ✅ → n119 text ✅ → n120 text ✗ "Botcake refused it (HTTP 500)". n118/n119/n120 (and n100) have only a `continue` edge; FlowEngine (~84–88) stops a run at a node with no `failed` port, so n10 (send with wait=true) never parked the wait; `resumePoint` (~124–143) then found no wait and the customer's next message ("magkano po?") started a new run at n1 — the opening picture again. Botcake 500s ran at ~3.5% (Incepxion) / ~5% (Likha) of bot sends from 11:02 today, across 12+ pages; ~30 + ~28 customers had a later message start a new run. Chatbot sends are retried only on 429 today (BotcakeClient retryWait, `onlyRateLimit`), never on 5xx/0, because a repeated send_content could deliver twice.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | One automatic retry for a chatbot send that ends HTTP 5xx or 0/no answer: re-send the same node's content once after ~3 s (a new send ticket the normal way, so 165's priority and the page rate apply). Not for 4xx/Facebook errors (#551, #10, #2022, restricted…) — those fail as today. Record the retry in chatbot_replies/events (e.g. "retried after HTTP 500") so the Inbox shows it. | Owner: fix it; a rare double message beats a missing one. |
| 2 | If the send still fails (or isn't retryable), a node with no `failed` edge no longer ends the run: the run continues on its `continue` edge as if the send was done (the reply row stays failed, visible in the Inbox), so later nodes run and a wait=true send parks the wait. A node that HAS a `failed` edge keeps using it. Exception: if the failed node itself is a wait=true send, park the wait anyway (the customer's next message continues there). | The conversation continues instead of restarting at the opening. |
| 3 | No change to flows' content, sequences, chatblast, or which errors count as customer errors. | Small, safe fix. |

## Cases (S-168)
P1: .1 a send gets 500 then 200 on retry → one visible message, run continues normally; .2 500 twice → reply failed, the run continues to the next node, and the next wait=true send parks the wait; the customer's next message resumes there (not n1); .3 a wait=true send that fails twice → wait parked at it; next message resumes; .4 #551 / #10 / 4xx → not retried, behaviour as today except the run continues per .2 (if a node has no failed edge); .5 a node with a `failed` edge → still follows it, no retry change beyond .1; .6 Mike's path (n118 ok, n119 ok, n120 500×2) → n10 reached and parked; .7 existing Chatbot/Botcake/Chatblast suites pass.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Unit` adds no new failures vs 29be5f6a. Result with deploy notes (no migration expected) and that the same commits apply to Incepxion. End with "168: <done|blocked> — <one line>".

## Out of scope
BotcakeSendPool/Queue internals (spec 167); flow edits; deploying.
