# Plan 089: Botcake sends don't block a worker either

## Shape: 087's pattern, one more pool

- **`botcake:send-pool`** is one long-running process (Supervisor, `numprocs=1`). It keeps up to `services.botcake.send_concurrency` (default 100) Botcake requests in flight on one curl-multi handler.
- **Reused from 087:**
  - **The pool skeleton.** The heartbeat, refuse-if-alive, run loop with graceful drain, curl-multi tick and mock timers move into a base class, `TicketPool`. `ChatbotAiPool` and `BotcakeSendPool` both extend it.
  - **The customer's pending mark** (`__ai`). It now also names a send ticket (`send:<request_id>`), so 087's "one customer at a time" (newer chats wait, wait-skips and sequence steps hold, leftovers cleared) covers sends unchanged.
  - **The resume path.** `ChatbotRunner::resumeAi` becomes one shared resume for AI and send tickets: lock again, claim once, stale check, rebuild the run, ticket guard on every send.
  - **The engine's ticket guard** (`FlowEngine::mismatch`).
  - **The sweep hook** in `chatbot:resume-skipped`.
- **Not reused:** the AI ticket table. A send ticket has different columns: kind, a customer key for ordering, a chatblast message, the lock owner, when the request was made. A new table keeps 087's table unchanged (it is live).

## Data: one new table, `botcake_send_requests` (create-only migration)

| Column | What it holds |
|---|---|
| `request_id` (uuid), `kind` (`chatbot` / `chatblast`), `customer` | `customer` is the ordering key: page and PSID for chatbot, the chatblast customer lock key for chatblast |
| `page_id`, `page_name`, `psid`, `chat_id`, `flow_id`, `node_id`, `run_id` | The chatbot ticket |
| `message_id`, `lock_owner` | The chatblast ticket |
| `payload` (json) | Chatbot: the messages. Chatblast: page id, flow id, field id. **No token in the database**: the pool reads it from page settings by `page_name` |
| `context` (json) | The run context, as in 087 |
| `status` | `pending` → `sending` → `done` / `failed` → `resumed` (chatbot); `pending` → `sending` → `done` (chatblast) |
| `attempts`, `next_at`, `deadline_at`, `claimed_by`, `claimed_at` | Retries, backoff, the deadline and who holds the row |
| `called_at` | Set right before the request goes out: the no-double-send key |
| `result` (json), `error`, `http`, timestamps | The outcome |

Pruned after 2 days. Its release needs a separate yes from the owner.

## Chatbot send node and the AI step's send

- **Hand-off.** In `FlowEngine::send`, and in the AI step when "send the answer" is on, when `BotcakeSendQueue::async($run, $outbox)` holds, the messages go to a ticket. That needs:
  - the switch on;
  - a live send pool (heartbeat);
  - a live run (not Test, not a sequence step);
  - the live outbox, with replies switched on;
  - the page has a token;
  - the table exists.

  The customer is marked `__ai = send:<id>`, the run suspends ("sending"), and the worker is free.
- **The pool sends and records.** Before the call:
  - the ticket's PSID must equal the request's;
  - the chat (when there is one) must belong to that page and PSID in `botcake_chats`.

  Otherwise nothing is sent: `send_mismatch` event, ERROR log with ids only. The outcome is read as `BotcakeOutbox` reads it today, and written as the same `chatbot_replies` row (sent / failed, HTTP, reason). Then it queues `ResumeChatbotSend` on the chatbot queue and connection (088).
- **The resume** continues **at the send node with the result**:
  - a failed send takes the failed port, or stops the run, as today;
  - a sent message continues on "continue", or waits for a reply when the node waits;
  - the AI step continues on "success", as today.

  A stale result (the bot paused, the mark changed, the flow changed) stops the run with `send_dropped`; the message itself was already sent and logged.
- **Order:** a run suspends on each send, so its intro goes out and is confirmed before the run reaches the AI step and its reply. A customer's newer chat waits on the mark.

## Chatblast message

- The job does all of today's checks up to the moment it calls the three steps, inside the customer lock and after the gap check. Then it hands one ticket to the pool (message id, page id, flow, field, the **lock owner**) and returns **without** releasing the lock or setting the gap.
- **The three steps become one generator** in `ChatblastSender` (`stepper()`). It yields each request and receives its answer, with exactly today's step recording, statuses (`sent`, `failed`, `field_mismatch`, `unknown`), token-invalid handling and test_ok marking. The blocking path drives it with `BotcakeClient::call`; the pool drives it asynchronously. Both run the same code, so the statuses and steps are the same by construction.
- **At the end** the pool sets the 20 s customer gap and releases the customer lock (`Cache::restoreLock(key, owner)`).
- **If the pool is down**, a pending chatblast ticket past its deadline goes back to `queued` with its job queued again; the job then sends blocking, because no live pool means no async.

## Per-page rate and 429

- One lane per token (`sha1(token)`), with the next allowed send time kept in the pool's own memory. The pool is one process, so this is atomic: interval = 1 / `services.botcake.rate_per_page` (default 2).
- The pool also reads and writes the existing shared key `botcake-rate:<sha1>`, so blocking sends elsewhere (setup check, sequences) and the pool keep out of each other's way.
- `BotcakeClient::MIN_INTERVAL` becomes the same setting.
- **429:** that lane pauses for Retry-After (or 2, 4, 8 s, capped at 10, as the shared client does) and the request is retried there. Other pages keep going.
- **Retries** mirror `BotcakeClient::call` exactly, through one shared helper:
  - up to 3;
  - 429 / 5xx / no answer for the field write and read;
  - 429 only for `send_content` and `send_flow`.

## Crash safety (no double send)

- `called_at` is written before a chatbot request goes out; for chatblast, the steps are saved before each call (as today).
- **Pool start**, for a dead pool's `sending` tickets:
  - **not called yet** → back to `pending` (sent once by the new pool);
  - **chatbot, called** → `failed` with "Botcake did not answer (HTTP 0)" (today's meaning of a lost call), then resumed through the failed port; **never re-sent**;
  - **chatblast, flow step started** → message `unknown` (today's `killed()` rule);
  - **chatblast, flow step not started** → the steps start again (the write and read are safe to repeat).
- **The sweep** (pool every 5 s, plus the every-minute tick):
  - fails pending tickets past their deadline (chatbot → failed port);
  - puts chatblast tickets back on the queue;
  - re-queues lost resumes.

## Mock

`BotcakeMock::answer()` runs inside the pool with a non-sleeping clock, and its answer is delivered by a timer after the page's latency. Recording, error rate and misroute count are unchanged.

## Switch

`services.botcake.async_sends` (env `BOTCAKE_ASYNC_SENDS`, default true), and only while a send pool is alive. Off, or no pool: blocking, as today. `.env.example` gets the new keys (`BOTCAKE_ASYNC_SENDS`, `BOTCAKE_RATE_PER_PAGE`, `BOTCAKE_SEND_CONCURRENCY`).

## Tasks

| # | Task | Tier | Side | Cases |
|---|---|---|---|---|
| 1 | Migration, `BotcakeSendQueue`, `TicketPool` base (`ChatbotAiPool` moved onto it), `BotcakeSendPool` (lanes, rate, 429, retries, mock), `botcake:send-pool`, Supervisor file, sweep | high | backend | S-089.4 rate never exceeded · S-089.5 429 per page · S-089.7 no double send after restart |
| 2 | Chatbot hand-off and resume (engine, runner, `ResumeChatbotSend`, the send guard) | high | backend | S-089.1 out of order · S-089.2 mismatch · S-089.3 intro before the AI reply · S-089.9 failed port |
| 3 | Chatblast generator, hand-off, lock and gap release | high | backend | S-089.6 three steps, same statuses and steps (`field_mismatch`) |
| 4 | Switch and fallback | medium | backend | S-089.8 `async_sends=false` blocks |
| 5 | Local load check (WSL Redis + Horizon + both pools), result | low | — | — |

Tests: `tests/Feature/Chatblast/BotcakeAsyncSendTest.php`.
