# Result 102: chatbot jobs are picked up at once, not after an idle sleep

Branch `fix/102-queue-pickup`, cut from `main` (52c214aa). Not pushed.

## What changed

- **Blocking pop on the Redis queue** (`config/queue.php`, redis connection).
  - `block_for` comes from `REDIS_QUEUE_BLOCK_FOR`, default 5 s.
  - 0 or a negative value gives `null`, so polling works as before. A plain 0 would be wrong: Laravel's `RedisQueue` blocks whenever `block_for` is non-null, and `blpop` with a timeout of 0 waits forever.
  - How Horizon reads it: the setting is on the queue connection, not on the supervisor. Horizon's workers are `queue:work redis` processes that build the `RedisQueue` from this connection, so it applies to every supervisor on the `redis` connection: chatbot, and chatblast (both `chatblast-high` and `chatblast`).
  - Dispatching is unchanged (push only). Only a worker's pop blocks.
- **Predis read-write timeout** (`config/database.php`, the `default` Redis connection that the queue uses).
  - `read_write_timeout` comes from `REDIS_READ_WRITE_TIMEOUT`, default 60 s.
  - Predis reads it from the connection array as a connection parameter (`PredisConnector` passes that array to `new Client`).
  - 60 is also predis's effective value today, because PHP's `default_socket_timeout` is 60. So it pins today's behaviour, above `block_for`, and shortens no other call on that connection (cache locks poll; they don't block).
- **Horizon idle sleep** (`config/horizon.php`, chatbot and chatblast supervisors).
  - `sleep` is 0 when `block_for` > 0, because the `blpop` does the waiting. With polling it is 3 s, Horizon's default.
  - A paused worker still sleeps 1 s, because Laravel's worker uses 1 s when `sleep` is 0.
  - Auto balancing is unchanged. With balance `auto` each process works one queue, so a block on one queue never delays the other. S-102.2 pins that (see TODO for other balance modes).
- **Pools** (`TicketPool::run`, used by both `chatbot:ai-pool` and `botcake:send-pool`).
  - Measured first: the idle interval was already **200 ms** (5 ms while busy), hard-coded.
  - I chose to keep the 200 ms poll and not add a wake signal. The interval now comes from `config('queue.pools.idle_ms|busy_ms')`, with env overrides; the defaults are unchanged.
  - The idle cost is about **0.35% of one core per pool** (measured below). The poll adds 100 ms on average.
  - The real wait in both hand-offs was the Horizon pickup *after* each pool: `ResumeChatbotAi` after the AI pool, `ResumeChatbotSend` after the send pool. The blocking pop removes it.
- `.env.example` lists the four new keys.

## Env keys (names only)

- `REDIS_QUEUE_BLOCK_FOR` (default 5; 0 = polling, the rollback)
- `REDIS_READ_WRITE_TIMEOUT` (default 60; keep it well above the block time)
- `CHATBOT_POOL_IDLE_MS` (default 200)
- `CHATBOT_POOL_BUSY_MS` (default 5)

None needs to be set on the server: the defaults are the intended values.

## Server steps (for the reviewer; nothing here was run on a server)

1. Deploy the code. There is no migration.
2. `php artisan config:cache` (if the server caches config).
3. `php artisan horizon:terminate`. Supervisor starts Horizon again, and the workers come up blocking with sleep 0.
4. `sudo supervisorctl restart likha-chatbot-ai likha-botcake-send`, so both pools read the new config. Their behaviour is unchanged at the default values.
5. Check: `php artisan horizon:status` shows running. In Horizon's dashboard, a chatbot job's wait should be about 0 s when the queue is idle.

**Rollback:** set `REDIS_QUEUE_BLOCK_FOR=0`, then repeat steps 2 and 3. Workers poll with a 3 s sleep, as before. The pools take `CHATBOT_POOL_IDLE_MS` and `CHATBOT_POOL_BUSY_MS` after step 4.

## Local before and after (my machine, not the server)

**Setup** (098's local setup):
- Ubuntu WSL with PHP 8.5, PostgreSQL 18 and Redis 8 on their own ports, config from process environment variables only (no env file).
- A copy of this branch, with Horizon (chatbot 2–16 workers), `chatbot:ai-pool` and `botcake:send-pool` running.
- 20 mock pages: mock Botcake at 400 ms per call, mock AI at 500 ms, the async AI step on.
- The flow: intro send, then an AI step that sends its answer. Each chat therefore makes 4 Horizon jobs: `RunChatbotFlow`, `ResumeChatbotAi` and 2 × `ResumeChatbotSend`.
- Chats were inserted and dispatched the way the chat webhook does it.
- **Before** is the same code with `REDIS_QUEUE_BLOCK_FOR=0`. That is exactly `main`'s queue behaviour: `block_for` null, sleep 3, the pools at 200/5 ms. So it also proves the rollback.
- **Pickup** is Horizon's own job record: `reserved_at − created_at`, per job.
  - `RunChatbotFlow` = chat arrives → run starts.
  - `ResumeChatbotAi` = AI answer arrives → flow resumes.
  - `ResumeChatbotSend` = the send pool's result → the run continues.

| Load | Code | Chat → run start (`RunChatbotFlow`) avg / p95 | AI answer → resume (`ResumeChatbotAi`) avg / p95 | Send result → resume (`ResumeChatbotSend`) avg / p95 | Chat → first reply avg | Chat → last (AI) reply avg / p95 |
|---|---|---|---|---|---|---|
| 60 chats at 1/s | before | 1,285 ms / 2,375 ms | 2,390 ms / 2,481 ms | 2,483 ms / 2,575 ms | 2.0 s | 8.0 s / 9.0 s |
| 60 chats at 1/s | **after** | **1 ms / 1 ms** | **1 ms / 1 ms** | **1 ms / 1 ms** | **1.0 s** | **2.0 s / 2.0 s** |
| 300 chats at 10/s | before | 1,510 ms / 2,777 ms | 2,443 ms / 2,599 ms | 2,073 ms / 2,557 ms | 2.8 s | 8.6 s / 10.0 s |
| 300 chats at 10/s | **after** | **1 ms / 1 ms** (max 10) | **1 ms / 1 ms** (max 7) | **0 ms / 1 ms** | **0.4 s** | **1.4 s / 2.0 s** |

- Every run: all replies sent, 0 failed, all AI tickets resumed, **misrouted 0**.
- The "chat → reply" columns come from `botcake:stress-page --stats`, which counts in whole seconds. The pickup columns are in ms.
- What is left of the reply time is the work itself: 2 mock Botcake calls at 400 ms, the mock AI at 500 ms, and the 200 ms pool tick.
- On the server, the live 2.3 s and 1.3 s waits in the brief should drop the same way. The 1 s send-pool step also loses its `ResumeChatbotSend` pickup, so all three idle waits go.

**Idle CPU**, 20 s with no work, summed over the processes alive at both ends:

| Process | before | after |
|---|---|---|
| Horizon workers (6) | 0.10–0.20% of one core | 0.05% of one core |
| `chatbot:ai-pool` | 0.35% | 0.35% |
| `botcake:send-pool` | 0.30–0.35% | 0.30–0.35% |

## Case table

| Case | Test | Result |
|---|---|---|
| S-102.1 | `test_S_102_1_redis_queue_blocks_and_the_predis_timeout_is_safely_above_it` | pass |
| S-102.2 | `test_S_102_2_horizon_chatbot_and_chatblast_do_not_sleep_when_the_pop_blocks` | pass |
| S-102.3 | `test_S_102_3_block_for_zero_restores_polling` (0 and -1) | pass |
| S-102.4 | `test_S_102_4_pool_intervals_come_from_config_with_env_overrides` | pass |

`vendor/bin/phpunit tests/Feature/Chatblast/QueuePickupConfigTest.php`: `OK (4 tests, 22 assertions)`.

**Red runs:**
- All 4 tests ran against the unchanged config:
  - S-102.1: `Failed asserting that null is identical to 5.`
  - S-102.2 and S-102.3: `Undefined array key "sleep"`.
  - S-102.4: `Undefined array key "pools"`.
- The -1 data set of S-102.3 fails without the `max(0, …)` clamp in `queue.php`.

**Suites:** `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes: 836 tests, all pass (1 skip, 088's Redis data set), with 50 PHPUnit deprecations as before. Run again on the final tree: 836 tests, 5,537 assertions, all pass (1 skip).

## Story changes

The spec brought no stories. I wrote S-102.1–4 myself from the "Decisions" and "Done when" lists and did not run `story-writer` for this config-only task. They need the reviewer's confirmation with the PR.

## Review

`skeptic-reviewer`, standard depth (medium risk), on `32d6297e..c71ea05b`: **no blockers or majors.** It checked against the vendor code that:
- Horizon passes `sleep` to its workers;
- `block_for` reaches the queue;
- `read_write_timeout` reaches predis;
- `horizon:terminate`, `maxTime` and pause are each delayed by at most 5 s.

Minors:
- **Fixed (`9f68b91d`):** S-102.1 read the machine's env (`REDIS_CLIENT`, the timeout). It now clears them and tests the defaults.
- **Not a defect:** "a negative `REDIS_QUEUE_BLOCK_FOR` gives sleep 0 in Horizon". `(int) '-1' > 0` is false, so it gives 3; the -1 data set of S-102.3 proves it. Unchanged.
- **Accepted, in `TODO.md` (102):**
  - nothing checks the timeout against the block time when both are set by hand;
  - `CHATBOT_POOL_IDLE_MS=0` busy-loops;
  - `block_for` would delay later queues under another balance mode;
  - the pools poll instead of waking on a signal.

There is no browser check: nothing in the UI changed.

## Commits

- `32d6297e` docs: spec 102 queue pickup
- `c71ea05b` fix: blocking pop on the Redis queue, no Horizon idle sleep, pool intervals from config (spec 102)
- `05e9be4e` docs: stories for queue pickup (spec 102)
- `9f68b91d` test: queue pickup config reads defaults, not the machine env, and pins a negative block_for (spec 102)
- `b40626f3` docs: accepted findings for queue pickup (spec 102)
- this result (docs)

## Merge danger

Low. The change is config only plus one method in `TicketPool`, and the pools' defaults are unchanged.
- The behaviour change happens when Horizon restarts on the new config (step 3).
- Until the pools restart (step 4), they run the old code, which has the same 200/5 ms.
- If a supervisor is ever changed to several queues per process, lower or zero `REDIS_QUEUE_BLOCK_FOR` (see TODO).
