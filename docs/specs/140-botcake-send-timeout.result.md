# Result 140: chatbot replies wait up to 30 s for Botcake

Branch `fix/140-botcake-send-timeout`, cut from `e2a0fe98` (live Likha main). Not pushed.

## What changed

| File | Lines | Change |
|---|---|---|
| `config/services.php` | 126–127 | New `services.botcake.send_timeout` = `(int) env('BOTCAKE_SEND_TIMEOUT', 30)`. |
| `.env.example` | 155 | `BOTCAKE_SEND_TIMEOUT=30` (nothing to set on the servers: the default is 30). |
| `app/Services/Chatbot/BotcakeProvider.php` | 20, 25–30 | The waiting send uses `shared(sha1($token), self::timeout())` instead of `15`. New `BotcakeProvider::timeout()`: the config value, 30 when unset or 0. |
| `app/Services/Chatbot/BotcakeSendPool.php` | 295–297 | The pool's Guzzle request timeout is `BotcakeProvider::timeout()` for `kind = chatbot` tickets; chatblast tickets keep `self::TIMEOUT` (15). |
| `tests/Feature/Chatblast/BotcakeAsyncSendTest.php` | 65, 590–650 | The fake pool handler records the request `timeout` option; two new tests (below). |

## Every timeout in the chatbot reply send path

| Where | Old | New | Notes |
|---|---|---|---|
| Waiting send (`BotcakeProvider` → `BotcakeClient::shared`), used when `async_sends` is off or no pool is alive | 15 s | **30 s** (`send_timeout`) | Connect timeout stays 10 s (`BotcakeClient::call`). Only 429 is retried for `send_content` (`onlyRateLimit`); no retry on timeout added. |
| Pool request (`BotcakeSendPool::issue`, Guzzle `timeout`) for chatbot tickets | 15 s | **30 s** (`send_timeout`) | `connect_timeout` stays 10. Same retry rule as before (429 only for chatbot). |
| Pool request for chatblast tickets (`BotcakeSendPool::TIMEOUT`) | 15 s | 15 s | Unchanged, as the brief asks. |
| Inbox human reply (`BotcakeProvider` with `$once`, a web request) | 15 s | 15 s | `once(15)` overrides the shared timeout; unchanged because it is a person's reply inside a web request, not a chatbot reply. |
| Ticket deadline `BotcakeSendQueue::CHATBOT_SECONDS` | 600 s | 600 s | Applies to `pending` tickets only (claim filter and sweep); a claimed (`sending`) ticket is never failed by it. |
| Dead-pool recovery (`DEAD_POOL_SECONDS` 60, `TicketPool::BEAT_FRESH_SECONDS` 10) | — | unchanged | Only fails a called `sending` ticket when the pool's heartbeat is stale. The pool beats every 2 s from its loop, and requests run in curl multi (non-blocking), so a 30 s request does not stop the heartbeat. |
| Pool drain on stop (`TicketPool::run`) | 65 s | 65 s | Longer than 30 s: a stopping pool still waits for an in-flight 30 s call. |
| Supervisor `likha-botcake-send` `stopwaitsecs` | 70 | 70 | Longer than the 65 s drain. **No server worker change needed.** |
| Waiting-send jobs `RunChatbotFlow`, `RunChatbotSequenceStep`, `ResumeChatbot*` `$timeout` | 280 s | 280 s | One send now waits up to 30 s instead of 15; several sends in one run still fit in practice. Only relevant on the fallback path (async off or no pool). |

## Tests (case table)

| Case | Test | Result |
|---|---|---|
| S-140.1 | `test_S_140_1_a_chatbot_reply_waits_up_to_send_timeout_for_botcake_and_chatblast_keeps_15_s`: pool chatbot send uses 30 by default, 45 when the config says 45; the waiting send uses 45 / 30 (unset); chatblast's three steps stay at 15. | pass (red first: `the pool: 30 s by default … -0 => 30, +0 => 15`) |
| S-140.2 | `test_S_140_2_a_chatbot_send_still_waiting_on_botcake_at_29_s_is_not_failed_and_its_answer_counts`: with a call open for 29 s of a live pool (heartbeat, sweep, ticks each second) the ticket stays `sending` with no reply row; the late answer is recorded as sent and the run continues. | pass (characterisation: the claim/sweep path already allowed it) |

Runs:
- `tests/Feature/Chatblast/BotcakeAsyncSendTest.php`: 23 tests, 191 assertions, OK.
- `phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/OrderEncode`: **1233 tests, 6856 assertions, OK** (1 skipped, 62 PHPUnit deprecations; no failures). No new failures.
- `php -l` clean on the four changed PHP files.

## Story changes

None (the brief brought no stories; the two cases above are the brief's "done when").

## Server worker change

None needed (see the supervisor row above). After deploy, `php artisan config:cache` (as in any deploy) picks up the new config key; the default 30 applies without setting `BOTCAKE_SEND_TIMEOUT`. The running `botcake:send-pool` must be restarted to load the new code (supervisor restart of `likha-botcake-send`, part of the usual deploy).

## Commits

- `0aadb226` docs: spec 140, chatbot reply send timeout to Botcake
- `ccba27b6` fix: chatbot replies wait up to 30 s for Botcake, configurable as services.botcake.send_timeout (spec 140)
- this result commit

140: done — chatbot reply sends (pool and waiting path) now wait 30 s for Botcake via `services.botcake.send_timeout`; chatblast and Inbox keep 15 s; no server change needed.
