# Result 088: Redis and Laravel Horizon for the chatbot and chatblast queues, with Delivering first

Branch `feat/088-redis-horizon`, cut from `feat/087-async-chatbot-ai` at `6510076c`. Nothing was pushed, and nothing on a server was touched.

## Commits

| Commit | What |
|---|---|
| `779abf34` | docs: the brief (verbatim) and the plan |
| `6dfef126` | feat: the packages, the connections by env, Delivering to `chatblast-high`, the Horizon config, the CEO-only dashboard, the Supervisor and Redis files, the tests |
| `fcc91721` | fix: the review's findings: `chatblast-high` only off the database driver, the cache data on its own Redis instance, the snapshot schedule, more tests |
| (this commit) | fix: the chatblast pools' minimum per queue; docs: result, stories, TODO |

## What changed

| Area | Change |
|---|---|
| Packages | `predis/predis` ^3.7 and `laravel/horizon` ^5.50; Composer also added `laravel/sentinel` (a Horizon dependency). No other package changed version. Horizon needs `ext-pcntl` and `ext-posix`: the server has both. On this Windows machine `composer require` ran with `--ignore-platform-req` for the two, so `composer install` on Windows needs the same flags. |
| `config/queue.php` | `chatbot_connection` (`CHATBOT_QUEUE_CONNECTION`) and `chatblast_connection` (`CHATBLAST_QUEUE_CONNECTION`). Unset, they follow `QUEUE_CONNECTION`, which is `database` on the server, so deploying changes nothing. The Redis queue's `retry_after` is 400 (`REDIS_QUEUE_RETRY_AFTER`): longer than the longest job (300 s). Laravel's default of 90 would run a job twice. |
| The jobs | Every job on these queues sets its connection in the constructor, so every dispatch follows the setting, including delayed re-dispatches, Resume, and the AI pool's resumes and sweep. Chatbot: `RunChatbotFlow`, `ResumeChatbotAi`, `ResumeChatbotWait`, `RunChatbotSequenceStep`. Chatblast: `SendChatblastMessage`. |
| Delivering first | `SendChatblastMessage` puts a message of kind `delivering` on queue `chatblast-high` and everything else on `chatblast`, **only when the chatblast connection is not `database`**. On `database`, the setting before the cutover or after a rollback, everything stays on `chatblast`, the only queue the old worker group listens to. Jobs already queued keep their queue. |
| `config/horizon.php` | Two supervisors, in every environment (`*`). **chatbot**: queue `chatbot`, `balance: auto`, min 2, max `HORIZON_CHATBOT_MAX` (16), timeout 300, tries 1. **chatblast**: queues `chatblast-high` and `chatblast`, `balance: auto`, min 2 per queue, max `HORIZON_CHATBLAST_MAX` (8), timeout 320, tries 1. Memory 256 MB per worker; each worker restarts hourly (`maxTime`). Other queues aren't listed, so Horizon never touches them. |
| Dashboard | `/horizon` is CEO only in every environment (`HorizonServiceProvider::authorization` uses `RefuseRememberedLoginUnlessCeo::isCeo`, as the CEO pages do). Horizon's default, which opens the dashboard to everyone on `local`, is replaced. Middleware: `web`, `auth`, `allowed_ip`. A guest is sent to the login page; a non-CEO gets 403 on the page and on `/horizon/api/*`. **No "Queues" link**: the nav is database rows, and a link would need a migration (see TODO). |
| Cache and locks | No code change: every lock and limiter goes through `Cache` and `RateLimiter`. For `CACHE_STORE=redis` the cache **data** may live on its own Redis instance (`REDIS_CACHE_HOST`, `REDIS_CACHE_PORT`, `REDIS_CACHE_PASSWORD`). The cache **locks** stay on the queue instance (`lock_connection` `default`), which never evicts. `REDIS_CLIENT` defaults to `predis`. |
| Scheduler | `horizon:snapshot` every 5 minutes, only while a queue is on Redis (dashboard metrics). |
| Files for the server | `deploy/supervisor/likha-horizon.conf`, `deploy/redis/likha-redis.conf` (instance 1: queue and locks), `deploy/redis/likha-redis-cache.conf` (instance 2: cache data). |
| Tests | `tests/Feature/Chatblast/QueueRedisHorizonTest.php` (new). Two older tests (`ChatblastPageTest`, `ChatblastSendTest`) expected their Delivering rows on `chatblast`; they now expect `chatblast-high`, since the test queue isn't `database`. |

### How "Delivering first" works, and what it doesn't do

- With `balance: auto`, Horizon keeps **one pool of workers per queue** and sizes each pool by its own backlog. `minProcesses` counts per queue in this mode, so min 2 means `chatblast-high` always has at least 2 workers of its own. A Delivering message never waits behind In transit messages, and a big Delivering backlog gets more workers.
- It is **not strict priority**: In transit keeps getting workers while Delivering is queued. Strict "high first" for every worker needs `balance: false`, which in Horizon means a fixed number of workers (no scaling). That contradicts "balance auto", so I didn't do it. It's a one-line config change if the owner prefers it.
- I tried listing the queues as one string (`chatblast-high,chatblast`) to get one pool where each worker takes high first. Horizon splits that string into the same two pools, so it changed nothing.
- In the first local run, min 1 per queue gave Delivering a single worker, and it finished no earlier than the In transit backlog queued a minute before it. With min 2 it finished about two minutes earlier (local check below).

## Tests

`tests/Feature/Chatblast/QueueRedisHorizonTest.php`: 4 tests and 5 runs (S-088.4 has two data sets). All pass; the Redis data set skips when no Redis server runs.

| Case | Test | Result |
|---|---|---|
| S-088.1 Delivering → `chatblast-high`, In transit → `chatblast`; on the database driver both → `chatblast`; Horizon's chatblast queues and min 2 per queue; every timeout under `retry_after` | `test_S_088_1…` | ✔ pass |
| S-088.2 the connection env is honoured for both queues (all five jobs) and falls back to the default | `test_S_088_2…` | ✔ pass |
| S-088.3 `/horizon`: guest → login, non-CEO → 403 (also its API, also on `APP_ENV=local`), CEO → 200 | `test_S_088_3…` | ✔ pass |
| S-088.4 the chatbot customer lock and the chatblast customer lock serialise, on the array store and on a real Redis | `test_S_088_4…` ×2 | ✔ pass (array). Redis: ✔ pass against the local Redis in WSL (`REDIS_PASSWORD=… phpunit --filter S_088_4`); skipped in the normal run |

**Red run (before the code):**
- S-088.1 failed: Delivering was pushed on `chatblast`.
- S-088.2 failed: "Undefined array key `chatbot_connection`".
- S-088.3 failed: the CEO got 403 from Horizon's empty email list.
- S-088.4 (array) passed: it pins today's lock behaviour.

**Suites:**
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 692 tests, all pass (1 skip, the Redis data set).
- Full suite: 2047 tests, only the two known failures (`ExampleTest`, `NightRun\ImportStartTest`). No new failure.

## Local check (my machine, not the server)

Setup:
- **Redis was run locally**: Redis 8.0 in the Ubuntu WSL on this machine, started with `deploy/redis/likha-redis.conf` (password set, `appendonly yes`, `maxmemory 1gb`, `noeviction`, bound to 127.0.0.1). Read back: `appendonly yes`, `maxmemory 1073741824`, `maxmemory-policy noeviction`; a connection without the password got `NOAUTH`.
- Horizon (`php artisan horizon`) and `chatbot:ai-pool` ran in the same WSL (PHP 8.5; the server has 8.4), against PostgreSQL 18 there, on a copy of the branch.
- The queues and the cache were on Redis (`CHATBOT_QUEUE_CONNECTION=redis`, `CHATBLAST_QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`).
- 20 mock pages (086), mock AI at 2,500 ms (087), mock Botcake at 200 ms per call.
- The second Redis instance for cache data was not run locally; cache and queue shared one instance here.

The load, in one go:
- 1,700 In transit chatblast sends queued at once;
- then 2,000 mock chats at 20 per second for 100 s;
- 60 s after the In transit backlog, 300 Delivering sends.

The chatblast sends were test rows, so nothing real was called (no J&T track, no campaign checks). They take the same queue by kind and make the same three fake-Botcake calls as a real send: write the field, read it back, send the flow.

**Final config (run 2, min 2 per queue):**

| Result | Number |
|---|---|
| Chatbot: chats → intro / AI reply | 2,000 → 2,000 / 2,000; chat → intro avg 6.1 s (p95 7 s), chat → AI reply avg 14.9 s (p95 17 s); 0 failed sends; 2,000 AI tickets resumed |
| **misrouted** | **0** (`botcake:stress-page --stats --all`, 2,000 mock-AI replies) |
| Chatblast | 2,000 sent (1,700 In transit, 300 Delivering), 0 failed |
| **Delivering first** | Delivering, queued 60 s **after** the In transit backlog: queued → sent avg **103 s**, max 201 s; all 300 sent by 15:04:20. In transit: avg 194 s, max 379 s; **541 of the In transit queued earlier were still unsent when the last Delivering was sent** (the last In transit went at 15:06:17). |

**Horizon scaling** (worker processes per pool, sampled every 5 s, shown every 30 s):

```
time      chatbot  chatblast-high  chatblast | queued: chatbot  chatblast-high  chatblast
14:59:56     2          2              2      |            0         0               3     idle (minimums)
15:00:26     6          2              6      |          110         0            1578     chats start → scales up
15:00:56    16          2              6      |          232         0            1451     chatbot at its max of 16
15:01:26    16          2              6      |          259       263            1323     Delivering queued
15:02:26     6          2              6      |            0       176            1068     chats done → chatbot scales down
15:02:56     2          2              6      |            0       130             928     chatbot back to 2
15:04:26     2          2              6      |            0         0             513     Delivering done
15:06:26     2          2              3      |            0         0               0     chatblast scales down
15:06:56     2          2              2      |            0         0               0     all back to the minimums
```

**Run 1, the first config (min 1 per queue)**, same load:
- Delivering had a single worker throughout: avg 172 s, max 309 s.
- It finished at 14:56:49, together with the last In transit (14:56:48). No In transit queued earlier was still unsent at that point.
- Chatbot: the same 2 → 16 → 2 scaling, AI reply avg 15.3 s, misrouted 0.

This run is why the minimum is now 2 per queue.

**Not run locally:**
- the two-instance Redis split;
- the server's Supervisor;
- the cutover between drivers with live traffic (only described below).

The WSL packages (PHP 8.5, Redis, PostgreSQL) stay installed on this machine; the services are stopped.

## Server steps (for the reviewer, after the owner's yes)

### `.env` keys (names only; the owner or the deployer sets the values and never prints the passwords)

| Key | Value | When |
|---|---|---|
| `REDIS_CLIENT` | `predis` (**set it explicitly**: an env copied from the old `.env.example` says `phpredis`, and that extension isn't installed) | step 2 |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | `127.0.0.1`, `6379`, the instance-1 password | step 2 |
| `REDIS_CACHE_HOST`, `REDIS_CACHE_PORT`, `REDIS_CACHE_PASSWORD` | `127.0.0.1`, `6380`, the instance-2 password | step 2 |
| `REDIS_QUEUE_RETRY_AFTER` | `400` (optional: it is the default) | step 2 |
| `HORIZON_CHATBOT_MAX`, `HORIZON_CHATBLAST_MAX` | `16`, `8` (optional: the defaults) | step 2 |
| `CHATBOT_QUEUE_CONNECTION`, `CHATBLAST_QUEUE_CONNECTION` | `database` now; `redis` at step 4 | steps 2, 4 |
| `CACHE_STORE` | `database` now; `redis` at step 6 | step 6 |

### Cutover, in order, with read-only checks

0. **Before anything:** take a backup of the env file. Run `php -m | grep -E 'pcntl|posix'` (both must show).
1. **Deploy the code** (env unchanged). It changes nothing: everything stays on `database` and `chatblast`.
   - Run `composer install --no-dev` (it adds predis and Horizon).
   - **Change the deploy hook now:** next to `php artisan queue:restart`, add `php artisan horizon:terminate`. It is harmless while Horizon isn't running. The procedure file is outside the repo.
   - Check: `php artisan about | grep -i -E 'queue|cache'` shows database for both. `php artisan tinker --execute="echo config('queue.chatbot_connection'), ' ', config('queue.chatblast_connection');"` prints `database database`.
2. **Install and secure Redis; set the env.**
   - `sudo apt-get install -y redis-server`. Redis 6 or newer: check `redis-server --version`.
   - **Instance 1** (queue and locks, port 6379): put the lines of `deploy/redis/likha-redis.conf` into `/etc/redis/redis.conf`, with its own password, then `sudo systemctl restart redis-server`.
   - **Instance 2** (cache data, port 6380, evicts): `deploy/redis/likha-redis-cache.conf`, as its header describes (`redis-server@cache`, or Supervisor if the template is missing).
   - Set the env keys of step 2, then `php artisan config:cache`.
   - Checks:
     - `redis-cli -a "$REDIS_PASSWORD" --no-auth-warning config get maxmemory-policy` → `noeviction`;
     - `… config get appendonly` → `yes`;
     - `redis-cli ping` without a password → `NOAUTH`;
     - `ss -ltnp | grep -E '6379|6380'` shows `127.0.0.1` only;
     - `php artisan tinker --execute="Cache::store('redis')->put('t',1,10); echo Cache::store('redis')->get('t');"` → `1`.
3. **Start Horizon** with the old groups still running.
   - `sudo cp deploy/supervisor/likha-horizon.conf /etc/supervisor/conf.d/`, `sudo supervisorctl reread && sudo supervisorctl update`.
   - Check: `sudo supervisorctl status likha-horizon` → RUNNING; `php artisan horizon:status` → running. `/horizon` (as the CEO) shows the two supervisors with 2 + (2 + 2) workers and empty queues. Nothing is on Redis yet.
4. **Switch the two queues:** `CHATBOT_QUEUE_CONNECTION=redis`, `CHATBLAST_QUEUE_CONNECTION=redis`, then:
   - `php artisan config:cache`;
   - `php artisan queue:restart`: every database worker (all groups) reloads the config, so new dispatches from jobs also go to Redis;
   - `sudo supervisorctl restart likha-chatbot-ai`: the AI pool dispatches resumes;
   - `php artisan horizon:terminate`, which Supervisor restarts.

   Checks:
   - New chats and sends show up in `/horizon` (Recent jobs).
   - The database rows of those queues only go down: `select queue, count(*) from jobs where queue in ('chatbot','chatblast') group by queue;`, repeated a minute later.
5. **Let the old groups drain, then remove them.**
   - Repeat the query above until both counts are 0.
   - Then `sudo supervisorctl stop likha-chatbot likha-chatblast`, and **repeat the query once more**: a job that released itself (`release(3)`) or re-dispatched just before the stop lands back on `database`.
   - If it isn't 0: `php artisan queue:work database --queue=chatbot,chatblast --stop-when-empty --timeout=320`.
   - Then remove the two groups' files from `/etc/supervisor/conf.d/`, `sudo supervisorctl reread && sudo supervisorctl update`.
   - Check: the query → 0 rows; `sudo supervisorctl status` no longer lists the two groups; astra, default and the J&T groups are still RUNNING.
6. **Switch the cache:** `CACHE_STORE=redis`, then:
   - `php artisan config:cache`;
   - `php artisan queue:restart`;
   - `php artisan horizon:terminate`;
   - `sudo supervisorctl restart likha-chatbot-ai`.

   Do it **outside the chatblast build and send times**: a lock taken on the database store just before the switch isn't seen on Redis. That covers the build lock and the customer lock for the few seconds until every process has restarted.

   Checks:
   - `php artisan tinker --execute="echo config('cache.default');"` → `redis`;
   - `redis-cli -p 6380 -a "$REDIS_CACHE_PASSWORD" --no-auth-warning dbsize` grows;
   - the `cache` table stops growing (`select count(*) from cache;` twice);
   - a chatbot chat on a real page still gets its reply;
   - `/chatblast` and `/page-settings` load.

Sessions stay as they are (no change).

### Rollback (env only, no deploy)

- **Cache:** set `CACHE_STORE=database`, then `config:cache`, `queue:restart`, `horizon:terminate`, and restart `likha-chatbot-ai`.
- **Queues:**
  1. Put the two old Supervisor groups back exactly as before (`--queue=chatbot` and `--queue=chatblast` on `database`) and start them. On `database` every chatblast job, Delivering included, goes to `chatblast`, so they need no other queue.
  2. Set `CHATBOT_QUEUE_CONNECTION=database` and `CHATBLAST_QUEUE_CONNECTION=database`, then `config:cache`, `queue:restart`, restart `likha-chatbot-ai`.
  3. Keep Horizon running until `/horizon` shows both Redis queues at 0, then `sudo supervisorctl stop likha-horizon`.
- Nothing is lost:
  - jobs already on Redis are finished by Horizon;
  - new jobs go to `database`;
  - a chatbot run happens once (`__last_chat`), and a chatblast send once (the `queued → sending` claim).

## Review

`skeptic-reviewer`, adversarial depth (opus), on `779abf34..6dfef126`: **two majors, both fixed in `fcc91721`.**

1. **The code deploy was not a no-op.** Delivering went to `chatblast-high` even on the database driver, where no worker listens to that queue.
   - Fixed: `chatblast-high` is used only off `database`.
   - Test: S-088.1 (the database case).
2. **The queue and the cache shared one 1 GB noeviction Redis.** The cache has `Cache::forever` keys that are versioned and orphaned on `/owner/private`; filling it would stop all queue writes and locks.
   - Fixed: the cache data may go to a second instance with `allkeys-lru` (`REDIS_CACHE_*`), while locks stay on the noeviction instance.

Minors fixed:
- the `bind` line now works on Redis 6.0;
- `REDIS_CLIENT=predis` is set explicitly in step 2;
- the cutover names every restart (the AI pool, `queue:restart`, `horizon:terminate`), the re-check after stopping the old groups, and the build-lock window;
- `horizon:snapshot` is scheduled;
- new tests for the `local`-environment gate, the supervisor queues and the timeouts under `retry_after`.

Accepted with reasons in `TODO.md`:
- the extra query per dispatch;
- no nav link;
- the cutover windows (operator steps);
- S-088.4 on the array store tests the store more than the code;
- phpredis left for later.

The review's claim that "chatblast-high always has a worker" held. The local check then showed that one worker isn't enough for "first", hence min 2 per queue in the last commit. That change came after the review and wasn't re-reviewed: it is a config value, covered by S-088.1.

## Story changes

Added the section S-088 (4 cases) to `qa/stories.md`. The brief had no stories, so they come from its "Done when" list.

## Notes

- **Deviation from the plan:** the plan said min 1 per queue for chatblast; the local check showed Delivering then doesn't finish first, so it is 2 per queue. In Horizon's per-queue meaning that is the brief's "min 2"; in total workers it means at least 4 for chatblast.
- **Delivering is not strict priority**, as explained above. If the owner wants strict high-first, the alternative is `balance: false` with a fixed worker count.
- **087 and Redis:** `chatbot:ai-pool` stays its own Supervisor program (unchanged). Its heartbeat and the customer locks move with `CACHE_STORE`, hence the restarts in steps 4 and 6.
- **Windows dev machines:** `composer install` needs `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` (Horizon can't run on Windows anyway).
- No browser check: the only new screen is Horizon's own dashboard, covered by S-088.3.
- Conflict between the brief and `CLAUDE.local.md`: none.
