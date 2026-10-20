Large task: spec 088, about 3–4 hours. Start it after 087 is committed.

**Branch.** Run `git checkout -b feat/088-redis-horizon feat/087-async-chatbot-ai`. It builds on 087, which isn't released yet, so if 087 changes, rebase.

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/088-redis-horizon.md`, the plan as `docs/plans/088-redis-horizon.md`, and the result as `docs/specs/088-redis-horizon.result.md`.
- Budget: 2 attempts per failing step.
- Everything that changes the server is written as steps and files for the reviewer, never run by you: installing Redis and the PHP extension, `.env` values, and Supervisor files.

# Brief 088: Redis and Laravel Horizon for the chatbot and chatblast queues, with Delivering first

## Why

- **Every queue runs on the `database` driver, and cache and locks sit in MySQL** (`CACHE_STORE` unset, so it is `database`).
  - On 2026-10-20 the stress test hit one deadlock on `insert ignore into cache`.
  - Worker counts are set by hand in Supervisor. Chatblast had 1 worker until 13:00 and is 4 now; chatbot is 2, and was 16 during the test.
- **Delivering messages wait behind In transit ones.** One customer's Delivering row was made at 10:00 and was still queued at 13:16, behind In transit rows queued at 09:01.
- **Who asked:** the owner, and the developer he consulted ("pacheck mo pm2 or horizon"; "ilagay sa redis para mabilis ung basa").

## Server facts (2026-10-20)

- PHP 8.4.16, with `pcntl` and `posix`. **No `redis` extension, and no Redis server.** Laravel 12.
- Supervisor groups, all running as www-data:

| Group | Queue | Processes | Notes |
|---|---|---|---|
| likha-chatblast | `chatblast` | 4 | timeout 320, tries 1 |
| likha-chatbot | `chatbot` | 2 | timeout 300, tries 1 |
| likha-queue-astra | `astra` | 2 | |
| likha-queue-default | `default` | 1 | 1024M |
| likha-queue-jnt-v2-parse | `jnt_v2_parse` | 5 | `--once`, 2048M |
| likha-queue-jnt-v2-load | `jnt_v2_load` | 1 | |
| likha-queue-jnt-v2-consolidate | `jnt_v2_consolidate` | 1 | 7200 s |

- 087 adds `chatbot:ai-pool`, a long-running command. Read its result.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Scope of the move.** Only the `chatbot` and `chatblast` queues move to Redis under Horizon. `astra`, `default` and the J&T queues stay on `database` with their Supervisor groups untouched: they have special memory limits, timeouts and `--once`. A later spec can move them. | Small blast radius. |
| **Rollback without a deploy.** Each moved queue's connection comes from env: `CHATBOT_QUEUE_CONNECTION` and `CHATBLAST_QUEUE_CONNECTION`, both defaulting to `database`. The code is safe to deploy before Redis exists; the switch is an env change. Every place that dispatches to those queues uses the setting. | — |
| **Cache and locks** move to Redis by env (`CACHE_STORE=redis`), with the same rollback. This covers the rate limiters, customer and run locks, mock lists and page maps. Check that every `Cache::lock` and `RateLimiter` still works on Redis (tests with the array or redis store, whichever the repo's test setup allows). Sessions stay as they are. | This removes the cache-table deadlock. |
| **Client.** Use `predis/predis` (Composer, no PHP extension), so no extension install is needed. Mention phpredis as an optional later speed-up. | Fewer server changes. |
| **Horizon** (`laravel/horizon`) manages the `chatbot` and `chatblast` workers. | — |
| *Supervisors in the Horizon config* | Two: one for chatbot (`balance: auto`, min 2, max 16) and one for chatblast (`balance: auto`, min 2, max 8). Timeouts and tries are the same as today's Supervisor commands. Maxes come from env. |
| *Delivering first* | Chatblast Delivering jobs go to queue `chatblast-high`, which the chatblast supervisor processes before `chatblast`. Today's queued rows are unaffected. |
| *Dashboard* | `/horizon`, open only to the CEO role, the same way other CEO pages are gated. Add a "Queues" link only where it fits the existing nav, or none. |
| *Process* | One Supervisor program, `php artisan horizon`, replaces the two groups `likha-chatblast` and `likha-chatbot`. Write it as `deploy/supervisor/likha-horizon.conf` in the repo. `chatbot:ai-pool` from 087 stays its own Supervisor program. |
| *Deploys* | The deploy hook needs `php artisan horizon:terminate` where it runs `queue:restart` today. Say so in the result; the procedure file lives outside the repo. |
| **Redis server setup, written for the reviewer:** install `redis-server`, bind to 127.0.0.1 only, set a password (stored in `.env` by the owner or deployer, never printed), turn on `appendonly yes` so queued jobs survive a restart, and set `maxmemory 1gb` with `maxmemory-policy noeviction`. List the exact `.env` keys. | Queued jobs must never be evicted. |
| **Cutover steps for the reviewer, in order:** (1) deploy the code with the env still on `database`, which is a no-op; (2) install Redis and set `.env`; (3) start Horizon, with the old chatbot and chatblast groups still running; (4) switch `CHATBLAST_QUEUE_CONNECTION` and `CHATBOT_QUEUE_CONNECTION` to `redis` and clear the config cache; (5) let the old groups drain the database rows for those queues, then stop and remove them; (6) switch `CACHE_STORE`. Rollback: reverse the env and restart the old groups. Include the read-only checks between steps, such as the queue sizes on both drivers. | No job is lost or run twice during the move. |

## Done when

- Tests:
  - a Delivering job goes to `chatblast-high` and an In transit job to `chatblast`;
  - the connection env is honoured for both queues;
  - `/horizon` gives 403 for a non-CEO and 200 for the CEO;
  - the chatbot customer lock and chatblast customer lock still serialise on the test cache store;
  - existing suites pass.
- **Local check, written up in the result with numbers:** run Redis locally if possible (WSL or Docker on this machine; otherwise say so). Start Horizon, push 2,000 mock chats (087's mock AI and 086's mock Botcake) and 2,000 mock chatblast sends. Show Horizon scaling the workers up and down, Delivering jobs finishing before In transit ones queued earlier, and misrouted = 0.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, and the full suite adds no new failures.
- The result lists every server step, every `.env` key (names only), the Supervisor file, the cutover with checks, and the rollback.

## Out of scope

- Moving `astra`, `default` and the J&T queues.
- Docker.
- PM2.
- Changing the chatblast send steps (payload or read-back), which is a separate spec.
