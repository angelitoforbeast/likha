# Plan 088: Redis and Laravel Horizon for the chatbot and chatblast queues, with Delivering first

## Packages

- `predis/predis` ^3.7 and `laravel/horizon` ^5.50, as the brief decides. Composer also adds `laravel/sentinel`, a dependency of Horizon. No other package changes.
- Horizon needs `ext-pcntl` and `ext-posix`. The server has both; on this Windows machine `composer require` ran with `--ignore-platform-req` for the two.
- `horizon:install` writes `config/horizon.php` and `app/Providers/HorizonServiceProvider.php`, and registers the provider.

## Connections by env (rollback without a deploy)

- `config/queue.php` gets two keys:
  - `chatbot_connection` = `CHATBOT_QUEUE_CONNECTION`;
  - `chatblast_connection` = `CHATBLAST_QUEUE_CONNECTION`.
- Both fall back to `QUEUE_CONNECTION`, which is `database` on the server, so today's behaviour stays. The fallback keeps the test suite on `sync` and local dev on whatever it uses.
- Every job on those queues sets `onConnection(...)` in its constructor, so every dispatch follows the setting:
  - chatbot: `RunChatbotFlow`, `ResumeChatbotAi`, `ResumeChatbotWait`, `RunChatbotSequenceStep`;
  - chatblast: `SendChatblastMessage`.
- The Redis queue connection's `retry_after` becomes 400 (env `REDIS_QUEUE_RETRY_AFTER`). It must exceed the longest job timeout, 300 s; Laravel's default of 90 would run a 300 s job twice.
- `REDIS_CLIENT` defaults to `predis`, so no PHP extension is needed. phpredis can be switched on later by env.

## Delivering first

- `SendChatblastMessage` picks its queue from the message: kind `delivering` (the Delivering campaign) goes to `chatblast-high`, everything else to `chatblast`.
- Jobs already queued keep their queue. A queued row's later re-dispatch (the customer gap, Resume) picks again, and lands on the right queue.
- In Horizon's chatblast supervisor, `queue: [chatblast-high, chatblast]` with `balance: auto`. Horizon then keeps a pool per queue, `minProcesses` 1 per queue, so the minimum is the brief's 2. Delivering always has at least one worker of its own and never waits behind In transit, and auto-scaling gives the busier queue more workers.
- The database-driver fallback (old Supervisor group) needs `--queue=chatblast-high,chatblast` too, which goes in the cutover steps.

## Horizon config (`config/horizon.php`)

| Supervisor | Queues | Balance | Min / max | Timeout | Tries |
|---|---|---|---|---|---|
| chatbot | `chatbot` | auto | 2 / `HORIZON_CHATBOT_MAX` (16) | 300 | 1 |
| chatblast | `chatblast-high`, `chatblast` | auto | 1 per queue / `HORIZON_CHATBLAST_MAX` (8) | 320 | 1 |

- Connection `redis` for both, memory 256 MB, the same for every environment (`*`).
- The other queues are not listed, so Horizon never touches them.

## Dashboard

`/horizon` is CEO only, in every environment; Horizon's default lets anyone in on `local`, and that is replaced. It uses the same check as the CEO pages (`RefuseRememberedLoginUnlessCeo::isCeo`). Middleware: `web`, `auth`, `allowed_ip`. No nav link: the nav is database rows managed elsewhere, and a link would need a migration, so the result says so.

## Cache and locks

- `CACHE_STORE=redis` by env, with rollback. The code needs no change: every lock and limiter goes through the `Cache` and `RateLimiter` facades. A test on the array store (the test suite's store) proves that the chatbot customer lock and the chatblast customer lock serialise.
- The local check runs the whole load on the Redis store.

## Files for the reviewer (never run by me)

- `deploy/supervisor/likha-horizon.conf`: `php artisan horizon`, `stopwaitsecs` 330.
- `deploy/redis/likha-redis.conf.md`: the Redis settings (bind, password, appendonly, maxmemory, noeviction).
- The result lists:
  - the server steps;
  - the `.env` key names;
  - the cutover in six steps, with read-only checks between them;
  - the deploy-hook change (`horizon:terminate`);
  - the rollback.

## Tasks

| # | Task | Tier | Side | Cases |
|---|---|---|---|---|
| 1 | Packages, Horizon config, CEO gate | high (auth) | backend | S-088.3 `/horizon` 403 / 200 |
| 2 | Connection settings, `onConnection` in the five jobs, Delivering → `chatblast-high` | medium | backend | S-088.1 queue by kind · S-088.2 connection env honoured |
| 3 | The lock serialisation test | low | backend | S-088.4 the chatbot and chatblast customer locks |
| 4 | Supervisor file, Redis notes, cutover and rollback, local check (Redis and Horizon in WSL), result | low | — | — |

Tests: `tests/Feature/Chatblast/QueueRedisHorizonTest.php`.
