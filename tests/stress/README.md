# Stress scripts (k6)

Reference scripts for the tester. They are not run by the test suite.

They send chats to `/api/botcake/chat` for the stress-test page `ZZ STRESS TEST` (page ID `990000000000001`). That page runs against the **fake Botcake** inside Likha (`App\Services\BotcakeMock`), so no reply reaches Botcake or a real customer. The page's chatbot flow still runs, including its AI (ChatGPT) steps.

| Script | What it does |
|---|---|
| `intake.js` | Steps the rate up to 100 chats per second (intake only). |
| `sustained.js` | 100 chats per second for 5 minutes. |
| `flow.js` | 10, 20, 30, 50 and 100 chats per second for 30 s each, from a pool of 1,000 customers. |

## Before a test (on the server)

1. **Allow the tester's IP.** Other IPs are limited to `BOTCAKE_CHAT_RATE` per minute.

   ```
   php artisan botcake:chat-test-ip <tester-ip> --hours=3
   ```

2. **Create the page** with fake customers. Seed at least as many as the scripts' pool (10,000 for intake and sustained, 1,000 for flow): the scripts send exactly those PSIDs, so no chat needs a verify call. The chat key is written only to the key file (mode 600) and is never printed.

   ```
   php artisan botcake:stress-page --seed=10000 --key-file=/home/<you>/stress-key.txt
   ```

3. **Give it a flow.** This copies a real page's chatbot settings (the flow and its botfields) and turns the test page On. The real page is not changed.

   ```
   php artisan botcake:stress-page --flow-from="Tech Flask"
   ```

   **Check the flow first.** A Google Sheet step in it really appends a row to that sheet for every test chat. Copy a flow without one, or remove the step from a copy of the flow.

4. **Optional: a slow or flaky Botcake.**

   ```
   php artisan botcake:stress-page --latency=400 --error-rate=0.1
   ```

   - `--latency` is the delay of each fake call in ms. Default 400, max 5000.
   - `--error-rate` runs from 0 to 0.5. A send then answers HTTP 500 at that rate, so the retry and failed paths run.
   - Only the CLI can set these; the page-settings screen refuses them.

5. **Optional: a fake AI** (no OpenAI call or cost). Each AI step answers `MOCK AI for psid <psid> chat <chat_id>` after the delay, so `--stats` can count replies that reached the wrong customer ("misrouted", which must be 0). `--no-mock-ai` goes back to OpenAI.

   ```
   php artisan botcake:stress-page --mock-ai --ai-latency=2500
   ```

   The AI steps run in `chatbot:ai-pool` (supervisor). Without a live pool they wait for the answer in the worker, as before.

Copy the key file to the tester's machine as `key.txt`, next to these scripts. For several test pages, use `--page="…" --page-id=99…` on every command, one key file per page, and `--stats --all` for all of them with totals.

## Run

```
BASE=https://likhaaitech.com k6 run tests/stress/intake.js
BASE=https://likhaaitech.com k6 run tests/stress/sustained.js
BASE=https://likhaaitech.com k6 run tests/stress/flow.js
```

You can set `PAGE_ID` and `PAGE_NAME` for another test page. Its ID must start with `99`.

## Watch and clean up (on the server)

```
php artisan botcake:stress-page --stats     # chats in, runs, replies, AI, fake Botcake calls, chatbot queue, time to the first reply
php artisan botcake:stress-page --cleanup   # deletes every row of the test page and clears its caches
```

`botcake:stress-page` refuses any page ID that doesn't start with `99`, and any page name that has (or had) a real Botcake page ID. Apart from `--seed` on a new name, it works only on a page that is already a stress-test page.

The fake Botcake keeps its fields, statistics and call counters in the app's cache. On the database cache that is a few small queries per fake call, which a real Botcake call doesn't make. It's a small load, but keep it in mind when reading the numbers.
