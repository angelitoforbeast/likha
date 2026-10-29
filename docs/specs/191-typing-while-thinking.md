# Brief 191: show "typing…" while the bot is thinking

## Why
Customers wait while the bot runs its flow and the AI (1–10 s) and see nothing; "typing…" only shows after the answer is ready, when Forbeast adds a pause before each message. Forbeast (its spec 028) adds a sender-action call so a site can show "typing…" at the right moment, and then sends the first message without a pause.

## The Forbeast contract (spec 028)
`POST {gateway}/api/v1/pages/{page_id}/typing` with JSON `{"psid": "<customer psid>"}`, same authentication as the send API Likha already uses. Forbeast sends mark_seen + typing_on to Meta at once, deduplicates per customer for about 20 s, and ignores Meta errors. 2xx = accepted; any error must be ignored by Likha. If the final path or body differs when Forbeast 028 lands, the reviewer will send a one-line amendment; keep the call in one small method so it is a one-place change.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | When a customer message on a **gateway (Forbeast) page whose bot is On** starts a bot run that will answer (not an ignored attachment, not a test run, not a page/customer the bot skips), Likha calls the typing endpoint **once at the start of the run**, before the flow/AI work — fire-and-forget with a short timeout (≤ 1.5 s connect+total), never blocking or failing the run, at most once per customer per 15 s (cache). | "Typing…" while thinking. |
| 2 | Not for Botcake pages (Botcake has its own behaviour), not for sequences/chatblast sends (no customer waiting). A config switch turns it off (default on). Log a count of calls and failures (no text). | Scope. |
| 3 | No change to flows, sending, or anything else. | Safe. |

## Cases (S-191)
P1: .1 a customer message on a gateway page with the bot On → exactly one typing call with that page and psid, before the run's first AI/flow step; .2 two messages from the same customer within 15 s → one call; .3 Botcake page, bot Off page, ignored attachment, test run, sequence send → no call; .4 the endpoint times out or returns 500 → the run continues and the reply is sent as before; .5 the switch off → no call; .6 existing Chatblast/Botcake suites pass.

## Done when
P1 pass; `tests/Feature/Chatblast tests/Feature/Botcake` add no new failures vs fa8c71ee (record the baseline). Result with the config key, where the call is made, deploy notes (deploy only after Forbeast 028 is live; harmless before because errors are ignored — say so if true), and that it applies to Incepxion. End with "191: <done|blocked> — <one line>".
