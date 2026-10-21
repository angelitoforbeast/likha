# Result 108: chatbot reply priority and no duplicate sequence jobs

## What shipped

**Reply path on its own queue.**
- `RunChatbotFlow`, `ResumeChatbotAi` and `ResumeChatbotSend` now go to `chatbot-high` (new constant `RunChatbotFlow::HIGH_QUEUE`), on both drivers.
- `RunChatbotSequenceStep`, `ResumeChatbotWait` and `MarkChatbotReplied` stay on `chatbot`. The brief names only the three reply-path jobs.
- `RunChatbotFlow`'s `release(3)` goes back to the queue the job came from, so a locked run stays on `chatbot-high`.

**Horizon.**
- The chatbot supervisor's `queue` is now `['chatbot-high', 'chatbot']`, still with `balance => 'auto'`.
- Horizon makes one worker pool per queue. `minProcesses` (2) applies per queue, and `maxProcesses` (16, `HORIZON_CHATBOT_MAX`) caps the whole supervisor as before.
- So the reply path always has 2 workers that never pop a sequence step, and the auto-balancer moves the rest to whichever queue is behind. No new process group and no higher cap. The idle floor goes from 2 to 4 workers, the same way chatblast already runs.
- **Why not one pool working `chatbot-high,chatbot` strictly in order (`balance => false`):** with blocking pop (`REDIS_QUEUE_BLOCK_FOR=5`), a worker blocks up to 5 s on an empty `chatbot-high` before it looks at `chatbot`. Sequence throughput would collapse, and a new chat could wait up to 5 s while workers block on `chatbot`. The 102 TODO already records this limit.

**Database driver.** The plain worker must run `queue:work database --queue=chatbot-high,chatbot`. That driver doesn't block, so the list order is a strict priority. Nothing runs on the database driver today; it is the rollback path.

**One queued step per subscription.**
- `RunChatbotSequenceStep` is `ShouldBeUniqueUntilProcessing`, with `uniqueId` = subscription id and `uniqueFor` = 3 h.
- Laravel takes the lock at dispatch and drops a second dispatch while it is held. It releases the lock just before `handle`.
- `ChatbotSequences::claim()` probes that lock and skips a row whose job still waits. It neither restamps `dispatched_at` (which would turn the waiting job stale) nor dispatches.
- A job that died after it started has no lock, so the 10-minute redispatch still queues it again.
- The `dispatched_at` stale-stamp check in `runStep` is unchanged.

**Tick paging (found in the local proof).** After the change above, rows skipped because their job waits still matched the tick's query. A full batch of them made `tick()` re-read the same 500 rows for its whole 50 s budget, and it never reached the due rows behind them. The tick now pages forward on `(next_send_at, id)`.

## Jobs queued at deploy

- **Reply-path jobs already on `chatbot`:** they keep running in the `chatbot` pool, which still exists. A released `RunChatbotFlow` returns to `chatbot`. Nothing is lost; they drain with the normal backlog.
- **Step jobs queued before the deploy:** they have no unique lock. The first tick after the deploy may queue one more copy of a step whose claim is older than 10 minutes. The older copy's claim is then stale and does nothing. From then on there is at most one per subscription.
- **`chatbot:ai-pool` and `botcake:send-pool`** dispatch to `chatbot` until they restart (step 3 below). Those jobs still run, in the `chatbot` pool.

## Deploy steps (nothing here was run on a server)

1. Deploy the code. **No migration.**
2. `php artisan config:cache`, then `php artisan horizon:terminate`. Supervisor restarts Horizon with the two pools.
3. `sudo supervisorctl restart likha-chatbot-ai likha-botcake-send`, so the pools dispatch resumes to `chatbot-high`.
4. Check: `/horizon` shows the chatbot supervisor with a `chatbot-high` pool and a `chatbot` pool. A new chat's `RunChatbotFlow` appears under `chatbot-high`.

**Env keys (names only; none new):** `CHATBOT_QUEUE_CONNECTION`, `HORIZON_CHATBOT_MAX`, `REDIS_QUEUE_BLOCK_FOR`, `CACHE_STORE` (the unique lock lives in the default cache store; on Redis that is the noeviction `lock_connection`).

**Merge danger:**
- If the chatbot queue is ever rolled back to `CHATBOT_QUEUE_CONNECTION=database`, its worker must listen with `--queue=chatbot-high,chatbot`. The old `--queue=chatbot` command in the 069, 087 and 088 docs would leave every first reply unprocessed.
- A queue flush or `horizon:clear` leaves step locks behind for up to 3 h (TODO 108 has the manual remedy).

## Local proof (my machine, not the server)

**Setup:**
- 102's setup: Ubuntu WSL, PHP 8.5, a throwaway PostgreSQL 18 and Redis 8, config from process environment only (no env file).
- `main` (50d3e1f1) and this branch run as two copies, each with its own database and Redis.
- 20 mock pages: mock Botcake at 400 ms per call, mock AI at 500 ms, async AI and sends on, 2 calls/s per page.
- The flow: intro send, then an AI step that sends its answer.
- Sequence: one step that sends one text. 5,000 subscriptions are due at once, each customer inside the 24 h window.

**1. 5,000 steps queued at once, then 300 new chats at 5/s (Horizon, `chatbot:ai-pool`, `botcake:send-pool` running).**
- Pickup is Horizon's own record per job: `reserved_at − pushedAt`. `RunChatbotFlow` pickup is the moment the new chat's run (and its first reply) starts.

| Code | Chat → run start (`RunChatbotFlow`) avg / p95 / max | `ResumeChatbotAi` avg | `ResumeChatbotSend` avg | Step backlog drained after the chats |
|---|---|---|---|---|
| `main` | 104,803 ms / 131,479 ms / 134,368 ms | 1 ms | 1 ms | +80 s |
| **branch** | **1 ms / 1 ms / 19 ms** | 1 ms | 1 ms | +115 s |

- Branch, chat → first sent reply: avg 0.5 s, p95 1.0 s. Chat → last (AI) reply: avg 1.5 s, p95 2.0 s. These come from `botcake:stress-page --stats`, in whole seconds.
- Those reply figures also include the 300 chats left in the reused database from the 102 run. On `main` the same figures were avg 54.7 s and p95 130 s, which is the mix of 300 slow and 300 old chats. The pickup column covers only this run's jobs.
- Both runs: 0 failed replies, 0 failed AI, 0 misrouted, no errors in the Horizon or pool logs.
- The steps take 35 s longer to drain on the branch, because the replies take workers and page-rate slots first. That is the intended trade.
- The branch run reported "Max calls/s (one page)" as 3 against the 2/s rate; `main` reported 2. This change doesn't touch the rate code. Recorded here, not investigated.

**2. Slow queue: 5,000 steps due, no workers at all (the slowest possible queue), the tick every minute for 16 minutes.**

| Code | Step jobs per subscription, minutes 0–10 | Minutes 11–16 | Jobs waiting at minute 16 | Tick time, minutes 11–16 |
|---|---|---|---|---|
| `main` | 1 | **2** (5,000 more at minute 11, and again every 10 minutes) | 10,000 | 0.2 s |
| branch before the paging fix | 1 | 1 | 5,000 | **50.2 s** (full budget, every minute) |
| **branch, final** | 1 | **1** | **5,000** | 0.4–1.1 s |

## Case table

The brief's done-when list is the case list (no stories came with it):

| Case | Test | Result |
|---|---|---|
| S-108.1 reply path on `chatbot-high`, the rest on `chatbot` (redis and database) | `ChatbotPriorityTest::test_S_108_1_the_reply_path_goes_to_chatbot_high_and_the_rest_stays_on_chatbot` | pass |
| S-108.2 Horizon: `chatbot-high` before `chatbot`, balance auto, min ≥ 2, cap 16 | `test_S_108_2_horizon_works_chatbot_high_first_with_its_own_workers_inside_the_same_cap` | pass |
| S-108.3 second dispatch of the same subscription's step is dropped | `test_S_108_3_a_second_dispatch_of_the_same_subscriptions_step_while_one_is_queued_is_dropped` | pass |
| S-108.4 the tick leaves a waiting job's row alone (claimed 11 min ago, or unclaimed) | `test_S_108_4_the_tick_leaves_a_subscription_alone_while_its_job_still_waits` | pass |
| S-108.5 a job that died after it started is queued again after 10 min | `test_S_108_5_a_job_that_died_after_it_started_is_queued_again_after_10_minutes` (real sync lifecycle) | pass |
| S-108.6 a full batch of waiting jobs doesn't hide due rows behind it | `test_S_108_6_a_full_batch_of_waiting_jobs_does_not_hide_the_due_rows_behind_it` | pass |
| stale stamp unchanged | `ChatbotSequenceEngineTest::test_S_070_4a_…` (the stale job sends nothing) | pass |

**Red runs:**
- S-108.1–5: 7 failures before any code. S-108.1/2/3 failed with `Failed asserting that two arrays are identical`, S-108.4 with `3 is identical to 1`, and S-108.5 with `true is false` (the lock assertion).
- S-108.6: `Failed asserting that 0 is identical to 1` after a 50.5 s spin.

**Existing tests changed** (they pinned the old behaviour):
- `QueueRedisHorizonTest` S-088.1 and S-088.2: the queue names.
- `ChatbotSendingTest` S-069.S4: `RunChatbotFlow` is now on `chatbot-high`.
- `ChatbotSequenceEngineTest` S-070.4a: it now releases the faked jobs' locks, as Laravel does when a job starts, before simulating the dead-job redispatch.

**Suites:**
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 844 tests, all pass (1 skip, 088's Redis data set; 50 PHPUnit deprecations as before).
- Full suite: 2,220 tests. The same two failures as in 103 and 104, none new:
  - `ExampleTest` (`/` redirects to login);
  - `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (missing local `storage/app/credentials.json`).

## Review

`skeptic-reviewer`, standard depth (medium risk).
- On `aec2c59d..d1314dd5`: no blockers or majors. Minors, accepted in `TODO.md` (108): a lost queued job holds its subscription up to 3 h; `--stats` counts only `chatbot` on the database driver; no Horizon wait alert for `chatbot-high`. Its database-fallback note is in the merge danger above.
- The tick spin was found by the local proof, not the review. It was fixed test-first in `8a0dfa2d`, and the re-check found no blockers or majors. Its minor (S-108.6's wall-clock bound) is accepted: the margin is 25 s against a sub-second run.

## Notes

- The brief's text is saved without the two customer names in its "Why", because a committed spec doesn't name people.
- `story-writer` was not run. The brief's done-when list served as the cases, and the reviewer confirms them with the PR.
- `botcake:stress-page --stats` was not changed (TODO 108).

## Commits

- `aec2c59d` docs: brief for chatbot reply priority and sequence dedupe (spec 108)
- `d1314dd5` fix: reply path on chatbot-high with its own Horizon pool, one queued sequence step per subscription (spec 108)
- `8a0dfa2d` fix: sequence tick pages past rows whose step job still waits instead of re-reading them (spec 108)
- this commit: docs: result, proof numbers and accepted findings (spec 108)
