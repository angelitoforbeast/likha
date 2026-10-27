# Brief 167: a big broadcast goes out in one run without churn or lost sends

## Why
On 2026-10-27 at 09:00 Manila (incepxion.pro) the chatblast auto-run "In transit" queued 9,406 messages across 83 pages in ~2 minutes. At the per-page Botcake rate (then 2/s; now `BOTCAKE_RATE_PER_PAGE=10`) the queue took ~28 min to drain. `BotcakeSendQueue::sweep` failed 15,534 chatblast tickets "Not sent in time; queued again" (deadline CHATBLAST_SECONDS = 240, shorter than the customer lock of 300 s) and re-dispatched them — 7,612 messages retried 1–5 times, never reaching Botcake (no double sends), but adding load and delay. Then Botcake, overloaded, answered HTTP 500 on ~500 messages at the "write custom field" step (nothing sent) and 15 "custom field could not be read"; 117 got an error after send_flow ("not sure it was sent"). The owner does NOT want broadcasts split into smaller runs; the whole run should go out, just without churn and with safe retries.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | No requeue churn: a chatblast message waiting in the send queue is not failed and re-dispatched just because it waited. Take the per-customer chatblast lock (and start any deadline) when the pool actually claims/starts the message, not when it is queued — or otherwise make waiting in line cost nothing (no lock held, no deadline running) while preserving one-at-a-time per customer and per-customer order. Keep a long safety deadline only for truly stuck rows (e.g. pool dead), in hours, not minutes. Read BotcakeSendQueue/BotcakeSendPool/ChatblastSender/SendChatblastMessage and keep their exactly-once guarantees (conditional status moves, called_at/send_flow never repeated after a crash). | Owner: no splitting; no wasted retries. |
| 2 | Safe automatic retry: when Botcake fails a chatblast step BEFORE send_flow was called (write custom field / read back: HTTP 5xx, 0/timeout, 429), retry that step with backoff (e.g. 15 s, 60 s, 180 s; 3 tries), then fail as today. Never retry once send_flow was called (the "not sure it was sent" path stays as is). Bot replies (kind chatbot) keep today's behaviour. | Nothing was sent, so a retry can't double-send. |
| 3 | Spec 165's priority stays: bot replies always ahead of chatblast. The per-page rate stays the setting (`services.botcake.rate_per_page`). No change to campaign schedules, statuses, message content or sequence logic. | Owner. |
| 4 | Log counts only (no customer data); add per-run counters a staff screen or log can show: queued, sent, retried (by step), failed (by reason). | Debugging stays easy. |

## Cases (S-167)
P1: .1 5,000 chatblast messages on 50 pages queued at once (fake clock/HTTP at 10/s per page) → all sent, zero "Not sent in time", zero tickets failed for waiting, per-customer one at a time and in order; .2 a field-write 500 then success on retry → sent once (one send_flow call); 3 consecutive 500s → failed with the same reason as today; .3 an error after send_flow → never retried (one call), marked as today; .4 bot replies during the big run still sent first (165's tests pass); .5 a dead pool (no heartbeat) → stuck rows still recovered by the existing recover path; .6 existing Chatblast/Botcake suites pass.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Unit` adds no new failures vs e7b09402. Result with deploy notes (migration if any; restart send pool + horizon) and that the same commits apply to Incepxion. End with "167: <done|blocked> — <one line>".

## Out of scope
Splitting or rescheduling campaigns; ForbeastWatch (spec 166); resending today's failed messages; deploying.
