# Brief 083: the chat endpoint never rate-limits Botcake's IP (`docs/specs/083-chat-limit-botcake-ip.md`)

## Why

A stress test on 2026-10-20 sent 24,949 chats to `/api/botcake/chat` from one IP, at up to 100 per second.
- The server stored the accepted ones at up to 100 per second.
- The `botcake-chat` limiter refused 15,179 of them with 429. It allows 1,200 per minute per IP (`services.botcake.chat_rate`) and resets each minute.
- Every real Botcake chat for all 44 pages comes from one IP (203.171.22.6, already in `services.botcake.chat_ips`). Above about 20 chats per second, real customer chats would be refused and lost.
- One request failed with a deadlock on `insert ignore into cache`. That is the limiter's database-cache write under load.

The owner: no limit for Botcake; other IPs keep a limit; a tester's IP must be allowed during a test.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| A request whose `REMOTE_ADDR` is in `services.botcake.chat_ips` skips the `botcake-chat` limiter completely. It does no limiter cache write at all, because those writes deadlocked. All other checks are unchanged: key, page, bot Off, PSID. | The owner. |
| Every other IP keeps the per-IP limit of `chat_rate` per minute, as today. | Flood protection. |
| **Test allowlist without .env.** A command `botcake:chat-test-ip {ip} {--hours=2} {--remove} {--list}` puts an IP on a temporary allowlist kept in the cache with an expiry (max 12 hours). An IP on it skips the limiter the same way. `--list` shows each IP and its expiry. The endpoint never reads the request body to decide this. | Tests are run without editing .env, and a forgotten entry expires by itself. |
| Tests: a listed IP sends more than `chat_rate` in a minute and all are accepted. An unlisted IP gets 429 after `chat_rate`. A test IP is accepted until it expires, then is limited. | — |

## Done when

- `php artisan test tests/Feature/Chatblast` passes, plus any test file you add.
- Report the commits.
