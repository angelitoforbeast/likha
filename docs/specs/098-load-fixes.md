# Brief 098: cache page settings on the chat path, one Botcake rate for every send, and real failure reasons

## Why

The live stress test of 2026-10-20 (20 mock pages, all releases up to 094) gave these findings:
- At 50–100 chats per second the server's CPU hit 100%.
- **`PageSettings::get()` runs a MySQL query on every call**, with no cache. A chat reads it several times (chat key, active, token, flow_id, the botfields), and so does every flow run.
- `--stats` reported **"Max calls/s (one page): 5"**, but the 089 send pool enforces exactly 2/s per page. Other send paths still go through `BotcakeClient::throttle()`, which is not atomic:
  - sequence steps (`ChatbotSequences` → `FlowEngine` → `BotcakeOutbox::send`, which blocks);
  - Inbox manual sends;
  - customer-info checks.
  Together they burst past the rate.
- **Failure reasons show a trace ID, not the reason.** 10 live sequence sends failed with reasons like `Botcake said "AKLL2dVGFxDHsoLLbpdhBlc" (HTTP 200)`. Spec 095's `BotcakeClient::messageText()` takes the **first string found** inside a non-string `message`, which here is apparently Facebook's trace ID rather than the error text.

The owner, 2026-10-21: "if goods to at sure ka, go na to"; and, on the failures: "anong pumalya?".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Cache page settings.** Values come from a cached per-page map (all active settings of a page, plain values decrypted only in memory, never stored decrypted in the cache: cache the encrypted value and decrypt per request), in Redis or whatever the cache store is, for 10 minutes. It is forgotten on every write, deactivate or delete through `PageSettings`, and on the bulk-save and paste paths, so an edit applies at once. Also keep a short per-request memo, so one run doesn't read the cache repeatedly. Secrets: never write a decrypted token to the cache or to logs. | Less MySQL per chat. |
| **One rate for every Botcake call per page.** Every Botcake call for a page token uses one atomic per-token limiter: the send pool, blocking sends (sequence steps, Inbox, Test real-sheet aside), customer info and chatblast. Use the same `rate_per_page` setting as 089. The simplest acceptable design: the blocking paths take a slot from the same atomic Redis counter the pool uses. Sequence step sends may also go through the 089 send pool if that is simpler; say which. | Never exceed 2/s per page. |
| **Prove it.** The mock's "Max calls/s (one page)" must read **2** in a local load check that mixes chats, sequence steps and Inbox sends on the same pages. | The owner was promised 2/s. |
| **Real failure reasons.** `messageText()` prefers, in order: `message` when it is a string; then inside an array or object: `error.message`, `error_user_msg`, `error.error_user_msg`, `message.message`, `error_description`, `title`, `detail`. Only then the first string that **doesn't look like a trace ID** (skip keys `fbtrace_id`, `trace_id`, `request_id`, `x_fb_trace_id`), then compact JSON. Also keep the **raw failed response body** (capped at 1,000 characters, no token) in the failure record where one exists (`chatbot_replies.error` stays short; add the raw body to the reply's content JSON or the send ticket), so the Inbox and Replies detail can show it. | The owner asked what failed. |
| **Look back.** Write a read-only command, `chatbot:failed-reasons {--since=}`, that groups recent failed sends by the real reason, for the reviewer to run on live. Where the raw body wasn't stored, it says "unknown (before 098)". | Answer "anong pumalya" for the next ones. |

## Done when

- **Tests:**
  - cache hit, miss and forget on every write path (cell, bulk-save, paste, deactivate, setup-check writes);
  - no decrypted secret in the cache;
  - the per-token limiter shared by pool and blocking paths (fake clock: never more than N per second across paths);
  - `messageText` with Facebook-style bodies (`{"error":{"message":"(#551) …","fbtrace_id":"A…"}}`, `{"message":{"fbtrace_id":"A…","message":"…"}}`, a trace ID only);
  - the raw body is stored;
  - the failed-reasons command.
- **Local load check** (Redis, Horizon, mocks as in 089): chats, sequence steps and Inbox sends on 20 pages. Report max calls/s per page (must be 2), the MySQL query count per chat before and after (measure with the query log on one chat), and misrouted 0.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, and the full suite adds no new failures.
- Report the commits.
