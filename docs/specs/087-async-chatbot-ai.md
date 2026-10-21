Large task: spec 087, about 3–5 hours.

**Branch.** In this worktree, run `git checkout -b feat/087-async-chatbot-ai main`. `main` is 94ced731 and is live (082–086 included).

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/087-async-chatbot-ai.md`, your plan as `docs/plans/087-async-chatbot-ai.md` (commit it before the code), and the result as `docs/specs/087-async-chatbot-ai.result.md`.
- Budget: 2 attempts per failing step; at the cap, stop and report.
- If the design needs a new table, it may add one (a create-only migration, no change to existing rows). Say so in the result: its release needs a separate yes from the owner.

# Brief 087: the chatbot doesn't block a worker while ChatGPT answers

## Why

The in-and-out stress test of 2026-10-20 ran on live Likha with a mock Botcake (spec 086), real ChatGPT and the Tech Flask flow, using 16 chatbot workers.

| Chats per second | Intro reply (no AI) | ChatGPT reply |
|---|---|---|
| 10 | 1.0 s | 2.5 s |
| 20 | 9.4 s | 12.8 s (max 38 s) |
| 30 | 51 s | 61 s (max 125 s) |

- All 2,047 chats got a reply: 0 failed and 1,162 AI calls OK.
- CPU was only about 50%.
- The ceiling is about 10 replies per second. Each `RunChatbotFlow` job holds a worker for the whole run, and the AI step (`ChatbotAi::complete`, a blocking HTTP call) waits about 2–3 s.

The owner, 2026-10-20: "dapat hindi naghihintay ng sagot ng chatgpt … ibabato yung tanong, babalikan na lang sagot". His worry: "baka mali maipadala sa user, baka response sa ibang user yung maisend".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **The AI step becomes asynchronous.** When a run reaches an AI node, it hands the request off and the worker is freed at once. Something concurrent sends many AI requests at the same time and continues each run when its own answer arrives. **Your plan picks the mechanism.** Two options to consider: (a) a supervised long-running command (e.g. `chatbot:ai-pool`) that keeps up to N requests in flight with concurrent HTTP (Guzzle promises or `Http::pool`) and dispatches a resume job per answer; (b) OpenAI's background responses, polled or with a webhook. Prefer (a) unless the plan shows (b) is simpler and as fast. The run already suspends and resumes at wait nodes (`__wait`, `chatbot_waits`, `ChatbotRunner::skip`), so reuse that pattern where it fits. | The owner: hand the question off, collect the answer later. |
| **Botcake sends stay synchronous in this spec.** They take about 0.5–1 s and are limited per page. Measure them and report whether they become the next limit. | One step at a time. |
| **The reply always goes to the customer who asked** (the owner's worry), by these invariants. Each one needs a test. | — |
| *Ticket* | Every AI request carries its own ticket: page_id, psid, chat_id, run or node identity, and a request id. Answers are matched only by the ticket, never by arrival order or by position in a list. |
| *Check before sending* | Before the send that follows an AI node, check that the resumed run's page_id and psid equal the ticket's, and the chat_id too where it applies. On a mismatch: nothing is sent, the run stops, an event `ai_mismatch` is written, and an ERROR log line appears without any message text. |
| *One customer at a time* | The existing lock per page and PSID still holds across the suspend. While a customer's AI answer is pending, their newer chat waits, as today. The lock can't expire while the answer is still awaited: either extend it, or make the resume take it again and check that the run state is unchanged. |
| *A stale answer* | If the run moved on while the answer was in flight (for example a human paused the bot, or the wait-skip timer fired), the answer is dropped with an event. It is never sent. |
| *Timeouts and failures* | An AI request with no answer after `services.openai` timeout (or 60 s) follows the node's failed port, as today. A crash of the pool process must not lose requests: on restart, pending requests are retried or failed, never duplicated into two sends. Use idempotency per request id. |
| **The concurrency limit is a setting** (`services.chatbot.ai_concurrency`, default 50), plus an OpenAI 429 backoff that slows the pool and drops nothing. | The OpenAI rate limit is shared with Astra. |
| **Mock AI for test pages only.** Extend the 086 mock: a page with `botcake.mock = 1` and a `99…` page ID may also set `chatbot.mock_ai = 1` (CLI `botcake:stress-page --mock-ai --ai-latency=2500`). Its AI answer is the text `MOCK AI for psid <psid> chat <chat_id>` after the given delay, with no OpenAI call. Real pages can never use it. | Testing correctness at volume without cost, and proving zero misroutes. |
| **Proof of zero misroutes.** The mock Botcake records, per `send_content`, the PSID it was sent to and the text. `botcake:stress-page --stats` adds "misrouted: N": AI replies whose text names a different psid or chat than the recipient. It must be 0. | The owner's worry, measured. |
| **Supervisor, if a new long-running process is added:** write its config as a file in the repo, for example `deploy/supervisor/likha-chatbot-ai.conf`, and describe the server step in the result. The reviewer applies it with the owner's yes. Don't change the existing worker configs. | Server changes go through the owner. |
| **Fallback switch.** `services.chatbot.ai_async` (env, default true). When false, the AI step runs blocking, as today. | Instant rollback without a deploy. |

## Done when

- **Tests (feature, with fakes):**
  - answers that arrive out of order (the last request answered first) each go to their own psid;
  - a forced ticket mismatch sends nothing and writes `ai_mismatch`;
  - a stale answer is dropped;
  - a customer's second chat waits for the pending first;
  - a timeout follows the failed port;
  - a pool restart with pending requests gives no double send;
  - a 429 slows down and drops nothing;
  - `ai_async=false` runs blocking;
  - mock AI is refused on a real page;
  - `--stats` counts misrouted.
- **Local load check:** start the pool and N queue workers against sqlite or MySQL, with the mock AI at 2,500 ms and the mock Botcake. Push at least 2,000 chats across 20 test pages, a pool of 1,000 PSIDs each. Report replies per second, average and p95 chat-to-reply time, and **misrouted = 0**. Say clearly which numbers come from your machine.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, and the full suite adds no new failures.
- **The result says:** the mechanism chosen and why; any migration; the new process and its supervisor file; the exact live test steps for the reviewer (20 mock pages, mock AI, steps 10/20/30/50/100 per second); and the rollback.

## Out of scope

- Making Botcake sends async.
- Redis or Horizon (a later spec).
- Chatblast.
- Changing the number of existing workers.
