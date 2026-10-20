# Plan 087: the chatbot doesn't block a worker while ChatGPT answers

## Mechanism: (a), a supervised pool command

`chatbot:ai-pool` is one long-running process (supervisor, `numprocs=1`). It keeps up to `services.chatbot.ai_concurrency` (default 50) OpenAI requests in flight on one Guzzle `CurlMultiHandler` (promises, non-blocking `tick()`), and dispatches one resume job per answer onto the existing `chatbot` queue.

Why not (b), OpenAI background responses:
- It is a different API (Responses, not Chat Completions), so every AI node's request and answer shape changes.
- A webhook means a new public route, which the threat model makes high risk. Polling means a process anyway, and a poll interval adds latency on top of the answer.
- (a) keeps today's request exactly as it is, and only the waiting moves.

## Data: one new table, `chatbot_ai_requests` (create-only migration)

Each row is the **ticket** and the request:

| Columns | What they hold |
|---|---|
| `request_id` (uuid, unique), `page_id`, `psid`, `chat_id` (null for a wait-skip run), `flow_id`, `node_id`, `run_id` | The ticket |
| `page_name`, `model`, `messages` (json), `max_tokens` | The request |
| `context` (json) | input, full name, received at, AI calls so far, steps so far, so the resumed run behaves like the same run |
| `status` | `pending` → `sending` → `answered` / `failed` → `resumed` |
| `attempts`, `next_at` (429 backoff), `deadline_at` (created + `services.openai.timeout`, default 60 s), `claimed_by` / `claimed_at` | Retries, backoff, the deadline and who holds the row |
| `answer`, `error`, `http`, timestamps | The answer or the failure |

Rows older than 2 days are pruned by the sweep (they hold customer text).

Its release needs a separate yes from the owner (new table).

## Flow of one AI step (async on)

1. **Suspend.** A live chat run (or wait-skip run) reaches an AI node:
   - `FlowEngine::ai` builds the messages exactly as today;
   - it inserts the ticket row and sets the run-state field `__ai = <request_id>` on the customer;
   - it writes event `ai_pending`, and the run ends ("waiting for the AI answer").

   The cache lock is released and the worker is free.
2. **Pool.** It claims pending rows conditionally (`claimed_by`), sends them concurrently, and on each answer it:
   - writes `answered` or `failed` only if the row is still `sending` and held by this pool;
   - then dispatches `ResumeChatbotAi` with the ticket it read: request id, page, psid, chat, flow, node.

   For a mock-AI page it sends no HTTP: a timer resolves the answer after the page's latency.
3. **Resume** (`ChatbotRunner::resumeAi`, queue `chatbot`):
   - It takes the customer lock again (if it is held, the job is released for 1 s).
   - It claims the row once (`answered|failed` → `resumed`), so a duplicate job does nothing.
   - It checks the run state is unchanged: `__ai` still equals this request id, the bot is not paused, and the flow and AI node still exist. Otherwise the answer is **dropped** with event `ai_dropped` and nothing is sent.
   - It clears `__ai` and rebuilds the run. The identity comes from the chat row (page, psid), or from the ticket row for a wait-skip run.
   - It continues **at the AI node with the answer**: success port or failed port, as today.
4. **Check before sending.** Every send in a resumed run first compares the run's page_id, psid and chat_id with the job's ticket. On a mismatch:
   - nothing is sent and the run stops;
   - event `ai_mismatch` is written;
   - `Log::error` writes ids only, no text.

## Invariants → how

| Invariant | How |
|---|---|
| Ticket | The row plus the job payload. Answers are matched by `request_id` only; the pool keys promises by request id. |
| Check before sending | `FlowEngine` guard on every send of a resumed run. |
| One customer at a time | `__ai` is a DB run-state field, so it can't expire. `ChatbotRunner::handle`, `skip` and `ChatbotSequences::runStep` treat a pending `__ai` as locked: the chat job is released for 3 s, as today; the sequence step retries later. The resume takes the cache lock again and checks `__ai`. A `__ai` whose row is gone or already resumed is cleared, so a customer can't be stuck. |
| Stale answer | `__ai` changed, the bot is paused, or the flow or node changed: dropped with an event, never sent. |
| Timeouts | The HTTP timeout is the time left until `deadline_at`. The sweep fails any row past its deadline, so its resume follows the failed port. A late answer finds the row no longer `sending` and is ignored. |
| Pool crash | At start, the pool refuses to run if another pool's heartbeat (cache) is under 10 s old. It then puts its dead predecessor's `sending` rows back to `pending` (or fails them, if past the deadline). The sweep re-dispatches `answered`/`failed` rows not resumed after 30 s. The resume is claim-once, so a retry never sends twice. |
| 429 | The row goes back to `pending` with `next_at` set to Retry-After (or 2, 4, 8 … up to 30 s). The pool halves its in-flight limit and pauses new sends until then, then grows back by 1 per success. Nothing is dropped; the request's deadline still applies. |

The sweep runs in the pool every 5 s, and in the existing every-minute `chatbot:resume-skipped`, so a dead pool still ends with failed ports and doesn't leave customers stuck.

## What stays blocking

- The `services.chatbot.ai_async=false` switch: today's call, unchanged.
- Test-tab runs.
- Sequence-step runs. A step advances right after its run, so suspending it would need its own resume; that is outside this spec's measured problem.
- The mock AI also answers in blocking mode (it sleeps the latency).

## Mock AI and misroute proof

- `chatbot.mock_ai` and `chatbot.mock_ai_latency_ms` are system keys (refused in the UI, and on deactivate). They are reserved in `ChatbotFields`, so they are no column and no botfield.
- They count only through `BotcakeMock::pages()`, which already requires `botcake.mock=1` and a `99…` page ID. A real page can never get the mock.
- `botcake:stress-page --mock-ai --ai-latency=2500` sets them, inside the command's existing guards.
- The answer text is `MOCK AI for psid <psid> chat <chat_id>`.
- The mock Botcake records each `send_content` (recipient psid and text) in the cache.
- `--stats` adds "misrouted: N": recorded texts naming a psid other than the recipient, or a chat that isn't the recipient's (checked against `botcake_chats`).
- `--seed` gives each test page its own placeholder token (`mock-<page id>`). Today all test pages share one Botcake rate key, unlike real pages.

## Settings

All in `config/services.php` under `chatbot`, from env:
- `ai_async` (`CHATBOT_AI_ASYNC`, true);
- `ai_concurrency` (`CHATBOT_AI_CONCURRENCY`, 50).

`services.openai.timeout` (`OPENAI_TIMEOUT`, 60) is the deadline. `.env.example` gets the three.

## Supervisor

`deploy/supervisor/likha-chatbot-ai.conf`: one process, `php artisan chatbot:ai-pool`, autorestart, stopwaitsecs 70. Existing configs are untouched.

## Tasks

| # | Task | Tier | Side | Cases |
|---|---|---|---|---|
| 1 | Migration, `ChatbotAiQueue` (submit, claim, record, sweep, recover), engine suspend and resume, runner `resumeAi`, `__ai` as a lock in the runner, skip and sequences, mismatch guard, `ResumeChatbotAi`, the config switch | high | backend | S-087.1 out of order · S-087.2 mismatch · S-087.3 stale · S-087.4 second chat waits · S-087.5 timeout → failed port · S-087.8 `ai_async=false` blocking |
| 2 | `chatbot:ai-pool` (concurrency, 429 backoff, heartbeat, restart recovery), supervisor file | high | backend | S-087.6 restart, no double send · S-087.7 429 slows, drops nothing |
| 3 | Mock AI, the misroute record, `--mock-ai`/`--ai-latency`, `--stats` misrouted, per-page seed token | medium | backend | S-087.9 mock AI refused on a real page · S-087.10 `--stats` counts misrouted |
| 4 | Local load check (20 pages × 1,000 PSIDs, 2,000+ chats, mock AI 2,500 ms, mock Botcake), result | low | — | — |

Tests: `tests/Feature/Chatblast/ChatbotAsyncAiTest.php`, one test per case. The red runs are reported in the result.
