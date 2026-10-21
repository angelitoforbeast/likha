Medium task: spec 086, about 1.5–2 hours. 082 is merged to main.

**Branch.** In this worktree, run `git checkout -b feat/086-mock-botcake main`. Use the newest `main` available.

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/086-mock-botcake.md` and the result as `docs/specs/086-mock-botcake.result.md`.
- Budget: 2 attempts per failing step, then stop and report.

# Brief 086: a fake Botcake for test pages, so a stress test can run chat in → flow → reply out

## Why

On 2026-10-20 a stress test sent chats to `/api/botcake/chat` for a test page. It used a fake page ID and fake PSIDs, and had no real Botcake token.
- **Intake** held 100 chats per second for 5 minutes. All 33,298 were saved, at 0.15 s on average and about 83% CPU.
- **The flow run failed.** The test page had the Tech Flask flow ("Main Flow (from CBB)"), and every run stopped at the first send node (n10, "The page has no Botcake token."). A send that fails without a failed port stops the run, so the AI node never ran.

The owner wants the real scenario: one chat in, at least one reply out, ChatGPT included.

Test pages can't use the real Botcake: their customers are fake, and real calls would hit Botcake's rate limits on the shared API. So test pages need a fake Botcake inside Likha.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **In process, no HTTP.** A page marked as a mock page never calls botcake.io. `BotcakeClient` (the single place every Botcake call goes through) answers its calls itself: it sleeps for a set delay and returns a Botcake-shaped answer. There is no mock web route, because one would use the same 5 PHP-FPM children as real traffic and distort the test. | The workers spend realistic time per call; the web tier is untouched. |
| **Which pages.** A page is a mock page only when its page_settings has `botcake.mock` = `1` **and** its `botcake.page_id` starts with `99` (the test range). Only a CLI command can write the flag, never the UI. A page with a real page ID is never mocked, even with the flag. `BotcakeClient` checks this per call, from the page ID in the path, using a cached set of mock page IDs. | A real page can never be faked by mistake. |
| **Calls to fake, with Botcake's real shapes** (see `BotcakeClient`, `BotcakeFlowNames`, `ChatblastFlowInfo` and the docs in the repo if present): `send_content` returns success; `customer_fields` (write) is stored per page and PSID in cache, and read-back returns it; `send_flow` returns success; `customers/{psid}/info` returns found; `flows` returns one flow named after the 082 setup flow name (or `UPDATE SHIPPING STATUS 2026`); `custom_fields` returns `message`; `message_templates` returns an APPROVED `post_order` with `{{1}}` in BODY; `flows/{id}/statistics` returns counts that grow with each mock `send_flow`; `customers` lists nothing. Any other call returns a 404-shaped answer and is logged once. | Chatbot and chatblast both run unchanged against it. |
| **Delay and errors** come from settings on the same page: `botcake.mock_latency_ms` (default 400, max 5000) and `botcake.mock_error_rate` (0–0.5, default 0). An error answer is HTTP 500 for a send, so the existing retry and failed paths run. | The test can mimic a slow or flaky Botcake. |
| **Counts.** Every mock call increments a cache counter per page and call kind. | The tester can compare chats in and replies out. |
| **One command for the whole harness:** `botcake:stress-page`. The options below are for page `ZZ STRESS TEST` (page ID `990000000000001`) unless `--page`/`--page-id` are given. | Today's harness was ad-hoc scripts run on the server; it becomes code with tests. |
| `--seed={n}` | Creates the page: Botcake page ID, a random `chat.key` (written only to a file path given by `--key-file`, mode 600, never printed), the dummy token `mock`, `botcake.mock=1`, and `n` fake PSIDs (`99` + 14 digits) in `botcake_psids` with `source=stress_test`. |
| `--flow-from={page}` | Copies that page's active `chatbot` settings (botfields, `flow_id`) to the test page and turns it On. |
| `--stats` | Prints chats in, flow runs, replies sent/failed, AI ok/failed, mock calls by kind, jobs waiting on the `chatbot` queue, and the average and p95 time from chat `received_at` to the first reply `sent_at` (by `chat_id`). |
| `--cleanup` | Deletes every row of the test page from `botcake_chats`, `botcake_psids` (`stress_test`), `chatbot_events`, `chatbot_customer_values`, `chatbot_replies`, `chatbot_sequence_subscriptions`, `chatbot_waits`, `botcake_messages` and `page_settings`, then clears the caches and the counters. It prints the counts and refuses any page ID that doesn't start with `99`. | Repeatable and safe. |
| **Never on a real page.** Every option of `botcake:stress-page` refuses a page ID that doesn't start with `99`, and refuses a page name that already has a non-test Botcake page ID. | — |
| **Commit the k6 scripts** under `tests/stress/`. The repo doesn't run them; they are reference for the tester. Three scripts: `intake.js` (steps to 100/s), `sustained.js` (100/s for 5 minutes) and `flow.js` (10, 20, 30, 50, 100/s for 30 s each, a pool of 1,000 PSIDs, short Taglish messages). Each reads the key from `./key.txt` and the base URL from `__ENV.BASE`. Add a README with how to run them, and say that the tester's IP must first be allowed with `botcake:chat-test-ip`. | — |

## Done when

- Tests:
  - a mock page's `send_content`, field write and read-back, `send_flow`, customer info, `flows` and `statistics` make no HTTP request (`Http::fake` asserts nothing is sent) and return the expected shape;
  - a page with a real page ID and the flag still calls the real host (faked HTTP);
  - the latency setting is applied (fake clock);
  - the error rate gives a 500 that leads to a failed reply;
  - a full chat goes through the endpoint and the flow to a "sent" reply on a mock page, with the AI node faked;
  - `--seed`, `--flow-from`, `--stats` and `--cleanup` work, and each refuses a real page ID.
- `vendor/bin/phpunit tests/Feature/Chatblast` passes (or `php artisan test`, where an env file exists).
- Report the commits and the files changed.

## Out of scope

- Seeding fake orders for a chatblast "out" test. Comes later, using the same mock.
- Any change to real pages or real Botcake calls.
- Changing the number of workers.
