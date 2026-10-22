# Brief 108: a new customer's first reply never waits behind sequence steps, and a slow queue never fills itself with duplicate sequence jobs

## Why

A live stress test on 2026-10-21 (20 mock pages, 10→100 chats/s) showed two faults besides CPU:
1. **One FIFO lane.** `RunChatbotFlow` (a new chat), `ResumeChatbotAi`, `ResumeChatbotSend` and `RunChatbotSequenceStep` all go to the Redis queue `chatbot` (`RunChatbotFlow::QUEUE`). When thousands of sequence steps came due, real customers' first replies on live pages waited 2–8 minutes behind them.
2. **Duplicate sequence jobs.** `ChatbotSequences::tick()` (every minute) re-dispatches a subscription whose `dispatched_at` is older than `REDISPATCH_MINUTES = 10`, meant for a job that died. When the queue is just slow, the job is still waiting, so every tick adds another copy: the queue grew past 20,000 after the test traffic had stopped. The step job ignores stale stamps, so customers got no double texts, but the copies took the workers.

The owner, 2026-10-21: the same workers must handle it ("dapat kayanin").

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Same workers, priority order.** Put `RunChatbotFlow`, `ResumeChatbotAi` and `ResumeChatbotSend` (the reply path) on a new queue `chatbot-high`; `RunChatbotSequenceStep` stays on `chatbot`. The Horizon chatbot supervisor works `chatbot-high,chatbot` in that order (check `config/horizon.php`, the auto-balancing and the `queue` list; also the plain `queue:work` fallback when CHATBOT_QUEUE_CONNECTION is database, so both drivers honour the order). No new server, no extra processes. | First replies first, with the capacity we have. |
| **No duplicates while waiting.** A sequence step job for a subscription is never queued twice: e.g. make `RunChatbotSequenceStep` unique per subscription while queued (`ShouldBeUnique`/`uniqueId` with a lock that lasts until it starts, and a `uniqueFor` longer than the worst backlog), or mark it claimed in Redis until it runs. The 10-minute redispatch then only re-queues a step whose job is truly gone (no lock). Your plan states which. | Stop the self-feeding backlog. |
| **Jobs already queued at deploy** on `chatbot` keep working (the reply-path classes change queue for new dispatches only; nothing is lost). Say how the release drains old ones. | Safe deploy. |
| **Proof (local, Redis + Horizon, mock AI/Botcake as in 088/102):** with 5,000 sequence steps due at once plus new chats at 5/s, a new chat's first reply starts in under 2 s; and after 15 minutes of a slow queue the number of `RunChatbotSequenceStep` jobs per subscription is ≤ 1. Before/after numbers in the result. | The owner wants it shown. |

## Done when

- Tests: each reply-path job dispatches to `chatbot-high`, the step job to `chatbot`; Horizon config lists `chatbot-high` before `chatbot` for the chatbot supervisor; a second dispatch of the same subscription's step while one is queued is dropped; the redispatch still re-queues a step whose job died (no lock); stale-stamp behaviour unchanged.
- The local proof numbers above.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, plus the new tests; the full suite adds no new failures.
- The result lists the env keys (names only) and the server steps (horizon:terminate, pool restarts), no migration expected, and the commits.
