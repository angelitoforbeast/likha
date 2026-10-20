Large task: spec 089, about 4–6 hours.

**Branch.** In this worktree, run `git checkout -b feat/089-async-botcake-sends main`. `main` is d7dfb2c6, which is 088 (Redis + Horizon) and is being cut over live today.

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/089-async-botcake-sends.md`, the plan as `docs/plans/089-async-botcake-sends.md` (commit it before the code), and the result as `docs/specs/089-async-botcake-sends.result.md`.
- Budget: 2 attempts per failing step.
- A create-only migration is allowed if needed. Its release needs the owner's separate yes, so say so.
- Server changes (Supervisor files, env) are files and steps for the reviewer.

# Brief 089: Botcake sends don't block a worker either

## Why

After 087 (async AI) and 088 (Redis + Horizon), the in-and-out stress test on live Likha (20 mock pages, mock AI at 2.5 s, mock Botcake at 400 ms, 16 chatbot workers) gave:

| Chats per second | Intro avg | AI reply avg |
|---|---|---|
| 10 | 0.5 s | 3.5 s |
| 20 | 0.9 s | 3.6 s |
| 30 | 4.5 s | 10.7 s |
| 50 | 15.6 s | 36 s |
| 100 | 24.7 s | 54 s |

- CPU reached 99% at 50–100/s.
- 087's local check found that, with AI off the workers, the next limit is **workers blocked on Botcake sends**: the 400 ms call, plus the per-page throttle sleep in `BotcakeClient::throttle()` (`MIN_INTERVAL` 0.5 s per token), plus database work.
- Chatblast has the same problem. Each message is 3 blocking calls (write field, read back, `send_flow`) of about 2–3 s in all, so 1 worker sent about 1,465 per hour and 4 workers about 91 per minute.

The owner, 2026-10-20: "hindi pa ba async yun botcake?". He wants sends handed off too, with the same guards as 087 ("baka response sa ibang user yung maisend").

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Same pattern as 087, for Botcake calls.** A supervised pool process (e.g. `botcake:send-pool`) keeps many Botcake requests in flight concurrently. Workers hand a send off and are freed. Your plan picks the details. Reuse 087's ticket, heartbeat, claim and resume machinery where it fits, rather than building a second copy. | Proven pattern; one model to maintain. |
| **Chatbot send node.** The run hands the `send_content` off and suspends, like the AI step, so a failed send still follows the node's failed port. It resumes on the result. The ticket carries page_id, psid, chat_id, run and node, plus a request id. The 087 invariants hold: matched by ticket only, a check before sending, one customer at a time, stale results dropped, no double send after a crash. | Same semantics as today: failure ports still work. |
| **Chatblast message.** The job hands the whole 3-step sequence (write field → read back → `send_flow`) to the pool as one ticket and is freed. The pool runs the 3 calls in order for that message, with the same step recording (`steps` JSON), statuses (`sent`, `failed`, `field_mismatch`, `unknown`) and `send_flow` retry rules as `ChatblastSender` today. The customer lock and the 20 s customer gap still apply. | Chatblast throughput. |
| **Order per customer.** A customer's sends go out in the order they were handed off. For example, the chatbot intro (n10) before the later AI reply (n100), and never two chatblast messages to the same customer at once. Different customers and pages run concurrently. | Conversation order. |
| **Per-page rate.** The per-page limit becomes a setting, `services.botcake.rate_per_page`, default 2 per second, the same as today's `MIN_INTERVAL`. The pool enforces it atomically per token, so concurrent sends never burst past it. This fixes today's non-atomic throttle in the pool path. On a 429 the pool backs off for that page only. | Botcake's limit is per page; protecting real pages matters. |
| **Mock.** The 086 mock Botcake answers inside the pool path too, at its latency, and keeps recording PSID and text for the misroute count. | Testable at volume. |
| **Fallback switch.** `services.botcake.async_sends` (env `BOTCAKE_ASYNC_SENDS`, default true) applies only while a live send pool exists. When it is off, sends block as today. | Instant rollback. |
| **The Supervisor file** goes in the repo (`deploy/supervisor/likha-botcake-send.conf`), with the server steps in the result. Under Horizon, the resume jobs use the existing chatbot and chatblast queues and connections from 088. | — |

## Done when

- **Tests (with fakes):**
  - answers that arrive out of order go to their own customer;
  - the mismatch guard;
  - the per-customer order (intro before the AI reply);
  - the per-page rate is never exceeded under concurrency (a fake clock and counter);
  - 429 backs off for that page only;
  - a chatblast message's 3 steps run in order, with the same statuses and steps as today (`field_mismatch` included);
  - no double send after a pool restart;
  - `async_sends=false` blocks as today;
  - the failed send port still works.
- **Local load check:** Redis plus Horizon, as in 088's local setup. 2,000 chats over 20 mock pages, mock AI at 2,500 ms, mock Botcake at 400 ms, at 20, 50 and 100 per second. Plus 2,000 chatblast sends over 20 mock pages. Report:
  - intro and AI reply avg/p95 per rate;
  - chatblast messages per minute;
  - **misrouted = 0**;
  - the per-page rate observed (max requests per second per page);
  - CPU.

  Say which numbers come from your machine.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, and the full suite adds no new failures.
- **The result lists:** the design, any migration, the Supervisor file, the server steps, the live test steps and the rollback.

## Out of scope

- Raising the per-page rate above 2/s. That is a separate decision after watching 429s.
- The `send_flow` payload idea (skipping the field write).
- Moving the other queues.
