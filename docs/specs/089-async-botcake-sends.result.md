# Result 089: Botcake sends don't block a worker either

Branch `feat/089-async-botcake-sends`, cut from `main` at `d7dfb2c6` (087 + 088). Nothing was pushed, and nothing on a server was touched.

## Commits

| Commit | What |
|---|---|
| `5a3d042b` | docs: the brief (verbatim) and the plan |
| `3c8a02c4` | feat: send tickets, `botcake:send-pool`, chatbot hand-off and resume, the chatblast steps in the pool, the switch, the Supervisor file, tests |
| `a092cf3b` | chore: the mock Botcake records each page's busiest second, for `--stats` |
| `697876dd` | fix: review loop 1 (a per-page share of the pool, ownership before every call, "send_flow called" only when it goes out, the pause and emergency stop at send time, tests) |
| `39702347` | fix: review loop 2 (full pages left out of the claim query; a waiting chatblast message gets its turn after 10 s) |
| `05765b12` | fix: load-check findings (the interval runs from the moment a call goes out; Delivering keeps its head start in the pool) |
| (this commit) | docs: result, stories, TODO |

## Design

087's pattern, one more pool. **`botcake:send-pool`** is one long-running process (Supervisor). It keeps up to `BOTCAKE_SEND_CONCURRENCY` (100) tickets in flight on one curl-multi handler. Workers hand off and are free at once.

**Reused from 087, not copied:**
- **`TicketPool`.** The pool skeleton (heartbeat, refuse-if-alive, recovery at start, curl-multi tick, timers, run loop with graceful drain) is now a base class. `ChatbotAiPool` and `BotcakeSendPool` both extend it; 087's 12 tests pass unchanged.
- **The customer's pending mark** (`__ai`). It now also names a send ticket (`send:<id>`), so 087's "one customer at a time" covers sends unchanged: newer chats wait, wait-skips and sequence steps hold, leftovers clear.
- **The resume.** `ChatbotRunner::resumeAi` and the new `resumeSend` share one `resume()`: lock again, claim once, stale check, rebuild the run, the ticket guard on every send.
- **The sweep** in the every-minute `chatbot:resume-skipped`, and `BotcakeClient`'s retry rule, now `BotcakeClient::retryWait()` and used by both paths.

**Chatbot send node** (and the AI step's "send the answer"):
- **Hand-off.** It needs:
  - `BOTCAKE_ASYNC_SENDS` on;
  - a live send pool;
  - a live run (not Test, not a sequence step);
  - replies switched on;
  - the page has a token.

  The messages go to a ticket (page_id, psid, chat_id, flow, node, run, request id) and the run suspends.
- **Before the call,** the pool checks:
  - that it still holds the ticket;
  - the page ID in settings;
  - that the chat belongs to that page and PSID (`botcake_chats`);
  - on the first attempt, the human pause and the emergency stop.

  A mismatch means nothing is sent: event `send_mismatch` and an ERROR log line with ids only.
- **The result** is read exactly as `BotcakeOutbox` reads it today, and written as the same `chatbot_replies` row. Then `ResumeChatbotSend` (chatbot queue and connection, 088) continues the run at that node:
  - **a sent message:** "continue", or "wait for reply" when the node waits;
  - **a failed send:** the send node has no failed port in the editor, so the run stops, as before;
  - **the AI step:** goes on "success", as before.

**Chatblast message:**
- **Hand-off.** The job makes all of today's checks, takes the customer lock, checks the 20 s gap, then hands the message to the pool with the lock's owner. It returns without releasing the lock.
- **One generator for both paths.** `ChatblastSender::stepper()` holds the three steps, with exactly today's step recording, statuses, reasons, token-invalid handling and test_ok marking. The blocking path drives it with `BotcakeClient::call`; the pool drives it asynchronously, so both run the same code. At the end the pool sets the gap and releases the lock.

**Order per customer:**
- A chatbot run suspends on every send, so its intro is confirmed before the run reaches the AI step and its reply.
- The pool takes only each customer's oldest unfinished ticket (an anti-join in the claim).
- Chatblast also keeps its customer lock.

**Per-page rate:**
- One lane per page token. A call goes out only when 1 / `BOTCAKE_RATE_PER_PAGE` (2 → 0.5 s) has passed since the lane's last call went out.
- The time is taken right before each call, so the gap is exact. One process decides, so it is atomic.
- The pool also reads and writes `BotcakeClient`'s shared key `botcake-rate:<sha1>`, so blocking callers (setup check, sequences) and the pool keep out of each other's way.
- A 429 pauses only that lane, for Retry-After (or 2, 4, 8 s, capped at 10).
- `BotcakeClient`'s own interval is the same setting now.

**Fairness:**
- Each page holds at most `perPage()` tickets in the pool (about 3 s of sends, 6 at 2/s). Pages already full are left out of the claim query.
- The order is chatbot, then Delivering, then other chatblast.
- Inside a lane, a chatblast message that has waited more than 10 s goes first, because it holds a customer lock.

**Crash safety:**
- `called_at` is set right before a chatbot `send_content`, and when a chatblast `send_flow` actually goes out.
- When a new pool starts:
  - **never called** → sent once;
  - **chatbot, called** → "Botcake did not answer (HTTP 0)", then the failed path; never re-sent;
  - **chatblast, send_flow out** → `unknown` (today's rule for a killed job); never re-sent;
  - **chatblast, send_flow not out** → the three steps run again (the write and read are safe to repeat).
- The sweep counts a pool as dead only after 60 s without a heartbeat. The pool beats before each sweep, and checks it still holds a ticket before every call, so a slow pool is never taken over while it sends.
- With no pool:
  - a chatbot ticket past 10 minutes takes the failed path;
  - a chatblast ticket past 240 s goes back to the queue (lock released, no gap), and its job sends it the blocking way.

**Mock:** `BotcakeMock::answer()` runs inside the pool with a non-sleeping clock, and its answer arrives by timer after the page's latency. It still records PSID and text for the misroute count, and now each page's busiest second (`--stats`: "Max calls/s (one page)").

## Migration (new table: its release needs a separate yes from the owner)

`database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php`: create-only, guarded with `Schema::hasTable`.
- It holds **no token**: the pool reads the token from page settings.
- Rows are pruned after 2 days.
- Without the table, or without a live pool, every send blocks as before.

## Supervisor file and server steps (for the reviewer, after the owner's yes)

`deploy/supervisor/likha-botcake-send.conf`: `php artisan botcake:send-pool --max-time=3600`, `numprocs=1`, `stopwaitsecs` 70. Existing programs, Horizon (088) and `likha-chatbot-ai` (087) are unchanged.

1. Deploy the code; the env keys are optional, with defaults `BOTCAKE_ASYNC_SENDS=true`, `BOTCAKE_RATE_PER_PAGE=2`, `BOTCAKE_SEND_CONCURRENCY=100`. Until step 3 nothing changes, because without a live pool every send blocks.
2. `php artisan migrate --force` (the new table).
3. `sudo cp deploy/supervisor/likha-botcake-send.conf /etc/supervisor/conf.d/`, then `sudo supervisorctl reread && sudo supervisorctl update`.
   - Check: `sudo supervisorctl status likha-botcake-send` → RUNNING.
   - Check: `select status, count(*) from botcake_send_requests group by status;` shows rows moving to `resumed` (chatbot) and `done` (chatblast).
4. `php artisan horizon:terminate` and `sudo supervisorctl restart likha-chatbot-ai`, so every process has the new code.
5. Deploy hook: next to `horizon:terminate`, add `sudo supervisorctl restart likha-botcake-send`.

## Rollback

- **Instant:** set `BOTCAKE_ASYNC_SENDS=false` in the env file, then `php artisan config:cache`, `php artisan horizon:terminate`, and restart `likha-chatbot-ai`. Sends block in the worker as before. The pool finishes the tickets it already holds.
- **Or:** `sudo supervisorctl stop likha-botcake-send`. Within 10 s new sends block, and the minute tick ends the tickets left: chatbot through the failed path after 10 minutes, chatblast back to the queue after 240 s.
- The table can stay.

## Tests

`tests/Feature/Chatblast/BotcakeAsyncSendTest.php`: 14 tests and 19 runs, all pass.

| Case | Test | Result |
|---|---|---|
| S-089.1 results out of order each continue their own customer's run | `test_S_089_1…` | ✔ pass |
| S-089.2 the mismatch guard: nothing sent, `send_mismatch`, log without text, customer not stuck | `test_S_089_2…` | ✔ pass |
| S-089.3 the intro goes out and is confirmed before the AI step and its reply | `test_S_089_3…` | ✔ pass |
| S-089.4 never more than 2/s per page (times taken by the fake Botcake), other pages concurrent | `test_S_089_4…` | ✔ pass |
| S-089.5 a 429 backs off that page only | `test_S_089_5…` | ✔ pass |
| S-089.6 chatblast in the pool gives the same status, reason and steps as the blocking path: sent, field_mismatch, unknown, failed, token invalid; lock held, then gap set and lock released | `test_S_089_6…` ×5 | ✔ pass |
| S-089.7 a pool restart never sends twice; the old pool never calls again | `test_S_089_7…` | ✔ pass |
| S-089.8 `async_sends=false`, or no live pool, blocks as before | `test_S_089_8…` | ✔ pass |
| S-089.9 a failed send takes the failed path it took before (the run stops at a send node; a 5xx on send_content is not retried; the AI step still goes on "success") | `test_S_089_9…` | ✔ pass |
| S-089.10 a chatblast message cut by a restart is `unknown` only if send_flow went out; otherwise its steps run again once | `test_S_089_10…` | ✔ pass |
| S-089.11 the sweep: chatblast back to the queue, chatbot to the failed path after 10 minutes, a lost resume resumed once | `test_S_089_11…` | ✔ pass |
| S-089.12 a queued send checks the pause and the emergency stop before it goes out | `test_S_089_12…` ×2 | ✔ pass |
| S-089.13 one page's chatblast or chatbot backlog never holds up another page | `test_S_089_13…` | ✔ pass |
| S-089.14 Delivering goes before In transit handed off earlier | `test_S_089_14…` | ✔ pass |

**Red run:**
- The first 10 tests against `main`'s code (a scratch checkout): 9 failed and S-089.8 passed (it pins today's blocking path). New classes such as the pool came from the branch through Composer's autoloader. So that run shows `main`'s engine, runner and sender lack the behaviour; it doesn't test the pool alone.
- Later cases were each shown red before their fix: S-089.13's starvation case with the claim-query exclusion removed, S-089.7's zombie case before the ownership check, and S-089.14 before the priority.

**Suites:**
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 711 tests, all pass (1 skip, 088's Redis data set).
- Full suite: 2066 tests, only the two known failures (`ExampleTest`, `NightRun\ImportStartTest`).

## Local load check (my machine, not the server)

Setup (088's local setup):
- Redis (the repo's config) and PostgreSQL in the Ubuntu WSL on this machine (PHP 8.5; the server has 8.4).
- Horizon (chatbot 2–16 workers), `chatbot:ai-pool` and `botcake:send-pool`.
- 20 mock pages × 1,000 PSIDs, mock AI at 2,500 ms, mock Botcake at **400 ms**.
- A flow of intro send → AI step that sends its answer, so two Botcake sends per chat.
- The chatblast sends were test rows: the same queue routing, the same three calls; no J&T track or campaign checks, so nothing real is called.
- CPU is the busy share of the whole WSL machine (24 logical cores), sampled every 5 s.

| Load | Intro avg / p95 | AI reply avg / p95 (max) | misrouted | Max calls/s, one page | CPU avg / max |
|---|---|---|---|---|---|
| 2,000 chats at 20/s | 5.5 s / 11 s | 16.2 s / 26 s (45 s) | **0** | **2** | 4% / 7% |
| 2,000 chats at 50/s | 17.2 s / 34 s | 53.2 s / 77 s (118 s) | **0** | **2** | 4% / 7% |
| 2,000 chats at 100/s | 22.8 s / 44 s | 66.9 s / 91 s (106 s) | **0** | **2** | 6% / 15% |

- Every chat got both replies at every rate, with 0 failed sends.
- **The chat numbers are bounded by the per-page rate, by design.** 20 pages × 2/s is 40 sends/s, and each chat needs 2 sends, so the ceiling is 20 chats/s, whatever the workers or CPU.
- At 20/s the system runs at that ceiling, and from 50/s up the backlog grows and replies wait their turn. The live numbers in the brief (0.9 s at 20/s) came from the old throttle, which wasn't atomic and let pages burst past 2/s.
- Raising the rate is out of scope here: it's for the owner after watching 429s, and at 3/s per page the same 20 pages would do 30 chats/s.
- The workers are no longer the limit: CPU stays under 15% at 100/s, against 99% live.

**Chatblast, 2,000 messages** (1,700 In transit, then 300 Delivering), alone:
- **609 messages per minute.** All sent, none failed, misrouted 0, no page above 2 calls/s.
- **The ceiling here is also the rate:** 20 pages × 2 calls/s ÷ 3 calls per message = 800 per minute at most, before the customer gap and the lock.
- **The live figure was about 91 per minute** with 4 workers.
- **Delivering first:** Delivering averaged 26 s queued-to-sent, In transit 91 s. Of the 1,316 In transit messages queued before the first Delivering, only 3 were still unsent when the last Delivering went out.

The two runs before the last fixes showed two problems, both fixed in `05765b12` and gone in the numbers above:
- at 20/s one page reached 3 calls in one second, because the interval was measured from the scheduling decision, not from the call;
- Delivering lost its head start inside the pool (avg 137 s against 72 s).

## Live test steps for the reviewer (mock pages; no real customer, no OpenAI cost)

With 087's 20 mock pages, mock AI on, after the server steps above:
1. `php artisan botcake:stress-page --page="ZZ STRESS NN" --latency=400`, for each page.
2. Run k6 at **10, 20, 50 and 100 chats per second**, about 2,000 chats each, waiting for the drain between steps. Then run `php artisan botcake:stress-page --stats --all` and read:
   - "Chat to first reply" (intro), "Chat to last reply" (AI reply);
   - **`misrouted: 0`**;
   - **"Max calls/s (one page)": must be 2**.
3. For chatblast, queue test messages on the mock pages from `/chatblast` (Send test), or a seeded batch.
   - Read messages per minute: `select count(*), min(queued_at), max(sent_at) from botcake_messages where page_name like 'ZZ STRESS%' and status = 'sent';`.
   - Read the order: Delivering against In transit.
4. Watch `select status, count(*) from botcake_send_requests group by status;` and `sudo supervisorctl status likha-botcake-send`.
5. Clean up with `--cleanup` per page (it does not delete the send tickets, which are pruned after 2 days).

## Review

`skeptic-reviewer`, adversarial depth (opus), on `5a3d042b..3c8a02c4`: **1 blocker, 4 majors**. Two fix loops (the cap); the re-check after loop 2 found **no blockers or majors left**.

- **Blocker:** one page's chatblast campaign could take every pool slot and stop chatbot replies on all pages.
  - Loop 1: a per-page share, chatbot first, a 10-minute chatbot deadline.
  - Loop 2: full pages left out of the claim query, after the re-check found a fixed SQL window could still hide another page.
- **Major:** a slow but live pool could be recovered while still sending (a double send_flow or a wrong step answer). Fixed by the 60 s dead-pool rule, a beat before each sweep, ownership checked before every call, and held ids skipped.
- **Major:** a queued send_flow counted as "called", so a restart marked unsent messages `unknown`. Fixed: `called_at` is set when send_flow actually goes out.
- **Major:** queued sends ignored the human pause and the emergency stop. Fixed: both are checked right before the send.
- **Major (tests):** restart, sweep, chatblast statuses in the pool, the stale pool. All added.

Minors fixed:
- finishers with no owner match only `pending`;
- an invalid token is refused in the pool;
- `killed()` leaves a message the pool holds alone;
- `sentAt` is capped;
- S-089.4 uses the fake Botcake's own times;
- the pause check reads through `CustomerFields`.

Accepted with reasons in `TODO.md`:
- the 10-minute chatbot deadline (a product call);
- pages sharing a token;
- a token change making a second lane for a moment;
- blocking senders still racing each other;
- the duplicated base URL and timeout;
- no test for the mock timer;
- `Schema::hasTable` per send.

The load-check fixes in `05765b12` came after the review and were not re-reviewed. Each has a test (S-089.14) or is measured above ("Max calls/s" = 2 in every run).

## Story changes

Added the section S-089 (13 cases; S-089.14 is in the test file) to `qa/stories.md`. The brief had no stories, so they come from its "Done when" list and the review.

## Notes

- **A send node has no failed port** in the editor (`FlowGraph::ports`). "The failed send port still works" therefore means a failed send stops the run, as it did before; S-089.9 pins that.
- **Chatbot replies under a backlog now wait their turn at exactly 2/s per page**, instead of bursting. That protects the real pages, and the live reply times at 30/s and up will look slower than with the old throttle.
- **Out of scope, untouched:** the per-page rate stays 2/s, the send_flow payload idea, the other queues.
- No browser check: no screen changed.
- Conflict between the brief and `CLAUDE.local.md`: none.
