# Result 087: the chatbot doesn't block a worker while ChatGPT answers

Branch `feat/087-async-chatbot-ai`, cut from `main` at `94ced731`. Nothing was pushed.

## Commits

| Commit | What |
|---|---|
| `37a7ce9a` | docs: the brief (verbatim) and the plan |
| `4b582385` | feat: tickets, `chatbot:ai-pool`, resume per answer, the mismatch guard, mock AI, misroute stats, the supervisor file, tests |
| `d5a36b3d` | fix: an existing bug the load check found. The AI step crashed for a brand-new customer who had already got a reply in the same run (see "Extra fix") |
| `3eabea91` | fix: the review's findings: async only with a live pool, a pool slot freed on any error, one shared OpenAI payload, more test cases, the S-087 stories |
| (this commit) | docs: result, TODO, S-077.4 story |

## Mechanism chosen: (a), a supervised pool command

`chatbot:ai-pool` is one long-running process. It keeps up to `services.chatbot.ai_concurrency` (default 50) OpenAI requests in flight on one Guzzle curl-multi handler, without blocking, and queues one `ResumeChatbotAi` job per answer on the existing `chatbot` queue.

Why not (b), OpenAI background responses:
- It is a different API (Responses), so every AI step's request and answer would change.
- A webhook is a new public route (high risk); polling needs a process anyway and adds the poll interval to every answer.
- (a) sends exactly today's request; only the waiting moved.

**How one AI step runs now:**
1. **The run hands the request off.** When a live chat run (or a wait-skip run) reaches an AI step, it writes a **ticket** (a row in `chatbot_ai_requests`: request_id, page_id, psid, chat_id, flow, node, run, the messages) and marks the customer with the run-state field `__ai = <request_id>`. It writes the event "AI step n → waiting for the answer" and ends. The worker is free at once.
2. **The pool sends and matches.** It claims pending tickets conditionally, sends them concurrently, and matches each answer only by its own request (one closure per request), never by order. It writes the answer only if the ticket is still `sending` and held by this pool, then queues the resume.
3. **The resume continues the run.** It takes the customer's lock again and claims the ticket once (`answered|failed → resumed`), so a duplicate job does nothing. It checks the run state is unchanged, then continues **at the AI step with the answer**: the success or failed port, as before.

**The invariants, each with a test:**

| Invariant | How | Test |
|---|---|---|
| Ticket | Every request is its own row and job payload; answers are matched by `request_id` only | S-087.1 (three customers, answered last-first, each reply to its own PSID) |
| Check before sending | The resumed run's customer comes from the chat row (or the ticket row for a wait-skip run). Before the answer is saved and before every send, page_id, psid and chat_id must equal the job's ticket. On a mismatch: nothing saved or sent, the run stops, event `ai_mismatch`, an ERROR log line with ids only | S-087.2 |
| One customer at a time | `__ai` is a database field, so it can't expire. While it is pending, a newer chat returns "locked" (its job comes back in 3 s, as today), and so does a wait-skip or a sequence step. If one job runs two queued chats and the first hands off to AI, the second waits. The resume takes the cache lock again and checks `__ai` still names its request. A mark whose request is gone or already resumed is cleared, so no customer gets stuck | S-087.4 |
| Stale answer | `__ai` changed, the bot is paused, or the page's flow or AI step changed: the answer is dropped with event `ai_dropped`, never sent | S-087.3 (3 cases) |
| Timeouts and failures | The deadline is `services.openai.timeout` (new, default 60 s). The HTTP timeout is the time left. The sweep (in the pool every 5 s, and in the existing every-minute `chatbot:resume-skipped`, so it works even with the pool down) fails tickets past the deadline, so the failed port runs. A late answer is ignored; a connection error takes the failed port at once | S-087.5 |
| Pool crash | A new pool starts only once the old one's heartbeat is over 10 s old. It then puts the dead pool's `sending` tickets back to `pending`. The dead pool's late answers are ignored. An answer whose resume job was lost is queued again by the sweep after 30 s. The resume runs once | S-087.6 |
| 429 | The ticket goes back to `pending` with Retry-After (or 2, 4, 8 … up to 30 s). The pool halves its in-flight limit and sends nothing new until then, then grows back by one per answer. Nothing is lost; past the timeout the failed port runs (see TODO) | S-087.7 |
| Fallback | `services.chatbot.ai_async=false` (env `CHATBOT_AI_ASYNC`) restores the blocking call exactly. Also blocking: when no pool is alive (heartbeat), for Test-tab runs and for sequence-step runs | S-087.8 |
| Mock AI on test pages only | `chatbot.mock_ai` counts only through `BotcakeMock::pages()`, which requires `botcake.mock=1` and a `99…` page ID. The keys are system keys (refused in the UI and on deactivate) and reserved in `ChatbotFields` (no column, no botfield). The CLI refuses real pages | S-087.9 |
| Misroute proof | The mock Botcake records each `send_content` (the PSID it went to and the text). `--stats` reports "misrouted: N": fake-AI replies naming another PSID, or a chat that isn't the recipient's (checked in `botcake_chats`) | S-087.10 |

## Migration (new table: its release needs a separate yes from the owner)

`database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php`: create-only. It changes no existing row and is guarded with `Schema::hasTable`.
- Rows older than 2 days are pruned by the sweep, because they hold customer text.
- Without the migration (or without a live pool) the AI step simply waits, as before.

## New process and its supervisor file

`deploy/supervisor/likha-chatbot-ai.conf`: `php artisan chatbot:ai-pool --max-time=3600`, `numprocs=1`, autorestart, stopwaitsecs 70. A second pool refuses to start. The existing worker configs are unchanged.

**Server steps (the reviewer, after the owner's yes), in this order:**
1. Deploy the code.
2. `php artisan migrate --force` (the new table).
3. `sudo cp deploy/supervisor/likha-chatbot-ai.conf /etc/supervisor/conf.d/`, then `sudo supervisorctl reread`, `sudo supervisorctl update`, and `sudo supervisorctl status likha-chatbot-ai` should show RUNNING. Check the `command` path and `user` against the existing worker configs first (I assumed `/var/www/likha` and `www-data`, as in `database/_supervisor_reference`).
4. `sudo supervisorctl restart` the chatbot workers, so they load the new code.

Until step 3, the AI step stays blocking (no live pool), so the order can't strand customers. On every later deploy: `sudo supervisorctl restart likha-chatbot-ai`.

Optional env (in `.env.example`): `CHATBOT_AI_ASYNC=true`, `CHATBOT_AI_CONCURRENCY=50`, `OPENAI_TIMEOUT=60`. The defaults apply without them.

## Rollback

- **Instant, no deploy:** set `CHATBOT_AI_ASYNC=false` in the server's env file, then `php artisan config:cache` and restart the chatbot workers. The AI step waits again, as before. Tickets already pending are still answered by the pool, or failed by the minute tick after 60 s.
- **Or:** `sudo supervisorctl stop likha-chatbot-ai`. Within 10 s new AI steps run blocking, and pending tickets fail after the timeout (the failed port).
- The table can stay; it is unused when async is off.

## Tests

`tests/Feature/Chatblast/ChatbotAsyncAiTest.php`: 12 tests (S-087.3 has 3 data sets), all pass.

| Case | Test | Result |
|---|---|---|
| S-087.1 out of order, each to its own PSID | `test_S_087_1…` | ✔ pass |
| S-087.2 mismatch sends nothing, `ai_mismatch`, log without text | `test_S_087_2…` | ✔ pass |
| S-087.3 stale answer dropped (bot paused, moved on, flow changed) | `test_S_087_3…` ×3 | ✔ pass |
| S-087.4 the second chat waits for the pending first | `test_S_087_4…` | ✔ pass |
| S-087.5 timeout → failed port; late answer ignored; request error → failed port | `test_S_087_5…` | ✔ pass |
| S-087.6 pool restart: no double send | `test_S_087_6…` | ✔ pass |
| S-087.7 429 slows down, drops nothing | `test_S_087_7…` | ✔ pass |
| S-087.8 `ai_async=false` (or no pool) runs blocking | `test_S_087_8…` | ✔ pass |
| S-087.9 mock AI refused on a real page | `test_S_087_9…` | ✔ pass |
| S-087.10 `--stats` counts misrouted | `test_S_087_10…` | ✔ pass |
| S-077.4 (extra fix) a new customer's AI step after an intro | `ChatbotAiHistoryTest::test_S_077_4…` | ✔ pass |

**Red runs:**
- The new tests run against `main`'s code (a scratch checkout): 10 of 11 failed because the behaviour was missing, e.g. "Undefined constant `CustomerFields::AI`" and "The `--mock-ai` option does not exist". S-087.8 passed: it pins today's blocking behaviour.
- With the runner's "a queued second chat waits" line disabled, S-087.4 failed (`'done'` instead of `'locked'`).
- S-077.4 failed on `main` with `Call to a member function getKey() on array`.

Code and tests of task 1–3 landed in one commit; the red run above was done afterwards against `main`. That is a deviation from slice-by-slice TDD.

**Suites:**
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 687 tests, all pass.
- Full suite: 2042 tests, only the two known failures (`ExampleTest`, `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`). No new failure.

## Local load check (all numbers from my machine, not the server)

Setup:
- Windows 11, 24 logical cores, PHP 8.4, and a throwaway PostgreSQL 16 container (database queue and database cache, as on the server). The container is removed.
- `chatbot:ai-pool` plus **16** `queue:work --queue=chatbot` workers.
- 20 test pages (`--seed=1000` each), mock AI at **2,500 ms**, mock Botcake at the default 400 ms per call.
- A Tech-Flask-like flow: an intro send, then an AI step that sends its answer (two Botcake sends per chat).
- A driver stored chats and queued `RunChatbotFlow`, exactly as `/api/botcake/chat` does: a random page, and a random PSID from its 1,000.

| Chats per second (chats) | AI replies per second | Chat → intro avg / p95 | Chat → AI reply avg / p95 (max) | misrouted |
|---|---|---|---|---|
| **Blocking baseline** (no pool), 10/s (2,000) | 4.6 | 117.5 s / 223 s | 120.5 s / 226 s (238 s) | 0 |
| 10/s (2,000) | 9.9 (keeps up) | 0.7 s / 1 s | **3.8 s / 4 s** (7 s) | **0** |
| 20/s (2,000) | 13.1 | 15.8 s / 29 s | 36.1 s / 55 s (88 s) | **0** |
| 30/s (2,100) | 13.0 | 26.0 s / 49 s | 60.5 s / 84 s (129 s) | **0** |
| 50/s (2,000) | 13.3 | 31.2 s / 58 s | 77.7 s / 104 s (133 s) | **0** |
| 30/s, Botcake latency 0 (2,100) | 18.4 (capped at exactly 20/s) | 0.9 s / 1 s | 21.5 s / 38 s | **0** |

- In every run all chats got both replies: 0 failed sends, 0 failed jobs, every ticket `resumed`.
- Replies per second = AI replies ÷ (first chat → last AI reply).
- The baseline happened by accident, and it is useful. A restarted pool refused to start because the killed pool's heartbeat still looked fresh, which is the design. With no live pool, the AI step ran blocking, the old way, on the same machine and load.

**Are Botcake sends the next limit? Yes.**
- With the AI step out of the workers, the chatbot's ceiling on this machine is about **26 sends/s** (13 chats/s with two sends each). The 16 workers are busy with blocking Botcake sends: the 400 ms call, plus the per-page throttle sleep, plus the database work. After intake stops, the AI replies alone run at about 27/s.
- With 0 ms Botcake latency the ceiling becomes exactly **2 sends per second per page**, the `BotcakeClient` throttle per token (here 20 pages → 40 sends/s → 20 chats/s).
- So on the server: AI no longer limits. Next comes worker time on Botcake sends (16 workers ÷ about 0.6 s per send), then 2 sends/s per page. Making sends async, or more workers, is a later spec.

## Live test steps for the reviewer (mock Botcake, mock AI, no OpenAI cost)

On the server, after the deploy steps above:
1. Allow the tester's IP: `php artisan botcake:chat-test-ip <tester-ip> --hours=3`.
2. Create 20 test pages, one key file each. Seeding writes each page a placeholder token of its own (`mock-<page id>`), so the Botcake rate is per page as with real pages:
   ```
   for i in $(seq -w 1 20); do
     php artisan botcake:stress-page --page="ZZ STRESS $i" --page-id=99000000000$(printf %04d $((10#$i))) --seed=1000 --key-file=/home/<you>/stress-key-$i.txt
     php artisan botcake:stress-page --page="ZZ STRESS $i" --flow-from="Tech Flask"
     php artisan botcake:stress-page --page="ZZ STRESS $i" --mock-ai --ai-latency=2500
   done
   ```
   As the README says, check first that the copied flow has no Google Sheet step.
3. Point k6 at each page's key and page ID (`tests/stress/common.js`: `PAGE_ID`, `PAGE_NAME`, `key.txt`), split across the 20 pages. Run steps of **10, 20, 30, 50 and 100 chats per second**, for about 2,000 chats each, for example `k6 run --env BASE=https://likhaaitech.com tests/stress/flow.js` with the rate per step. Wait until "AI waiting" and "Jobs waiting" reach 0 between steps.
4. After each step: `php artisan botcake:stress-page --stats --all`. Read "Chat to first reply", "Chat to last reply" (the AI reply), "AI waiting", "Mock AI replies" and **`misrouted: 0`**. Also check `sudo supervisorctl status likha-chatbot-ai`.
5. Optional, the AI part with real ChatGPT (costs money): `--no-mock-ai` on one page.
6. Clean up: `php artisan botcake:stress-page --page="ZZ STRESS NN" --cleanup` for each page (it now also deletes the page's AI tickets), and delete the key files.

## Extra fix outside the brief (please accept or send back)

The load check found an existing bug on `main`, from spec 077. The AI step's history merged an empty Eloquent collection (a customer with no earlier chat) with the reply arrays, and threw `Call to a member function getKey() on array`.
- It hit every brand-new customer whose flow sends anything (e.g. the intro) before an AI step with history over 0, and history defaults to 100.
- The engine caught the error and took the failed port (or stopped the run) **without writing an `ai` event**, so the "AI failed" counts never showed it.
- Fix: one `->toBase()` in `FlowEngine::history`, with test S-077.4. Commit `d5a36b3d`.

## Review

`skeptic-reviewer`, adversarial depth (opus), on `37a7ce9a..4b582385`: **no blocker and no major.** It traced the late answer, the sweep against the pool, two pools after a crash, and duplicate resume jobs; each ends in one resume and one send.

Minors fixed in `3eabea91`:
- Async no longer turns on just because the table exists; it needs a live pool. Before, the time between the migration and the supervisor step would have sent every customer to the failed port.
- A pool slot is freed even if an answer's handling throws.
- curl `select_timeout` is 0.01 s, so a quiet connection doesn't stall new sends for 1 s.
- One shared OpenAI payload for the blocking and pool paths.
- `orderBy` on the pruning delete.
- The send record keeps 2,000 characters.
- The S-087 stories were added to `qa/stories.md`.
- Missing tests added: the flow-changed stale case, a request error → failed port, the sweep re-queueing a lost resume once, and a leftover `__ai` mark never holding a customer.

Accepted with reasons in `TODO.md`:
- 429 past the timeout ends on the failed port.
- The misroute count covers ticket → resume → send, not the HTTP pairing, which S-087.1 covers.
- Three small missing tests.
- Duplicate resume jobs under overload (harmless).
- Two pools with separate caches (only extra OpenAI cost).
- "Flow runs" counts the resumed part.
- The fake Botcake's cache writes.

No fix loop was needed.

## Story changes

- Added the section S-087 (10 cases) to `qa/stories.md`; the brief had none, so they come from its "Done when" list.
- Added S-077.4 (the extra fix).

## Other changes and notes

- **Changed 086 behaviour:** `--seed` writes the token `mock-<page id>` instead of `mock`, so each test page has its own Botcake rate key like a real page; before, all test pages shared one 2-per-second throttle. `BotcakeMock::pages()` gains an `ai` value. Two 086 test lines were updated for this.
- `botcake:stress-page` gains:
  - `--mock-ai`, `--ai-latency`, `--no-mock-ai`;
  - `--stats --all` (every test page, with totals);
  - "AI waiting", "Chat to last reply", "Mock AI replies" and "misrouted: N".
- The Inbox shows the new events: "AI step n → waiting for the answer", "AI answer at step n dropped: …" and "… stopped: it belongs to another customer (nothing sent)".
- **Not async:** sequence-step runs (a step advances right after its run, so suspending needs its own resume) and Test-tab runs.
- No browser check: there is no new screen.
- Conflict between the brief and `CLAUDE.local.md`: none.
