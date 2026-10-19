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

4. **Optional: a slow or flaky Botcake.** These settings can be written only from the CLI.
   - `botcake.mock_latency_ms`: the delay of each fake call, default 400, max 5000.
   - `botcake.mock_error_rate`: 0 to 0.5. A send then answers HTTP 500 at that rate, so the retry and failed paths run.

Copy the key file to the tester's machine as `key.txt`, next to these scripts.

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

`botcake:stress-page` refuses any page ID that doesn't start with `99`, and any page name that has a real Botcake page ID.
