# Spec 207: Connections tests and the Check column without the wait

## Why
Staff setting up pages on /chatbot/pages (Incepxion, same code) wait a few seconds for the Connections modal to fill, and 5–10 s for "Save and test", "Test" and "Fix chat block". Measured on the live server on 2026-10-31: `ChatbotConnections::info()` 14 ms (3 queries), `listedPage()` 9 ms, one `ChatbotPageCheck::check()` 30–63 ms, a Botcake API call 170–380 ms (keywords 252, flows 170, testToken 376). So the server's own work is fast; the time goes to:
1. Botcake calls made one after another: Test reads token and fields, the Default Reply state, keywords and flows in series; Fix chat block does a takeover read, writes the content, publishes, then reads info again; each call also waits for the page's send pacing (`BotcakeClient::MIN_INTERVAL` 0.5 s, `services.botcake.rate_per_page` 2/s), which it shares with the page's live bot and chatblast sends.
2. The Check column: every load of /chatbot/pages fires one `GET /chatbot/check` per page (117 pages, 3 at a time, `queueChecks` in resources/views/chatbot/index.blade.php), and the check cache key includes the flow, sequence and step tables' global MAX(updated_at)/COUNT, so editing any flow makes all 117 recompute. With 124 checks in two minutes, the browser's connections fill and the Connections request waits its turn (nginx was HTTP/1.1 until 2026-10-31; HTTP/2 is now on, which removes the 6-connection limit but not the 117 requests).
Since 2026-10-31 the nginx access log on Incepxion carries `rt=<request time> urt=<upstream time>` at the end of each line, so the before/after can be measured per route.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Test and Save and test run their independent Botcake READS concurrently (e.g. `Http::pool`), with today's timeouts and retry rules; the stored results (token_status, tested_at, chat_block, keywords, fields) and the JSON the modal gets are exactly as today. | Reads do not depend on each other. |
| 2 | Writes stay in order (Default Reply content, then publish), never concurrent with each other. Fix chat block reuses the Default Reply read it already made instead of reading it twice; the reply's `connections` info stays DB-only. | Order matters for writes; no double reads. |
| 3 | Staff setup calls (Test, Save and test, Fix chat block, On/Off) do not wait on the page's send pacing: they use their own rate key (still at most one burst of the reads above per action) so a busy page's sends do not slow setup, and setup does not take the sends' slots. 429 from Botcake is still retried as today. | The pacing exists for sends, not for one staff click. |
| 4 | The Check column comes in ONE request: /chatbot/data (or a new batch endpoint) returns each listed page's last cached check with its time; only pages with no cached result, or whose own inputs changed, are re-checked, at most 2 at a time, visible rows first. "Check again" on a row still forces that row. | 117 requests per page load is the main source of the queue. |
| 5 | A page's check cache key depends on that page's own inputs (its settings, its flow and the sequences and steps that flow uses), not on global MAX/COUNT of the flow tables, so editing one flow only re-checks the pages that use it. | One edit should not invalidate every page. |
| 6 | Results shown are the same as today's checks (same messages, same severity); only when and how often they are computed changes. | Behaviour, not content. |

## Stories / cases (S-207)
P1:
- .1 Test on a page with Http::fake where every Botcake call takes 500 ms: the action finishes in under 1.2 s (today about 2.5 s or more with pacing), and stores the same statuses as before (compare to a run of today's code on the same fakes).
- .2 Save and test and Fix chat block with the same fakes: writes happen in order (content before publish), the Default Reply is read once per action, the result JSON equals today's.
- .3 With the page's send rate key busy (a send just made), a Test does not wait for it.
- .4 A 429 on one read is retried as today; a failed read gives today's message; the other reads' results are still stored.
- .5 Loading /chatbot/pages with 117 listed pages and warm caches makes 1 request for the check results (no per-page /chatbot/check calls); with 3 stale pages it makes exactly 3 check calls, at most 2 at a time.
- .6 Editing flow A re-checks only the pages that use flow A; pages on flow B keep their cached check.
- .7 A page's check result after the change equals today's for the same data (regression on a few seeded pages with errors and without).
P2: .8 "Check again" still forces one row.

## Threat model and risk
Trusted: signed-in staff. External: Botcake API (rate limits, 429, timeouts). Risk tier: medium (setup of live chat routing; a wrong Check hides a real problem). Run a skeptic reviewer on decisions 3 and 5.

## Done when
P1 pass with tests (failing first where timing or call counts can show it); tests/Feature no new failures vs the base; the result gives before/after timings from the tests and the exact routes to compare in the nginx log after deploy (`rt=` on /chatbot/connections/*, /chatbot/check, /chatbot/data); deploy notes; the final line.

## Out of scope
Botcake send pacing for bot replies and chatblast, chatblast, the Botcake API itself, nginx, the Clear and confirm work of spec 206, deploying.

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
