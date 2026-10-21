# Brief 102: chatbot jobs are picked up at once, not after an idle sleep

## Why

Live, over the last day (295 chats with an AI step), from the chat arriving to the first reply took **6.4 s on average**:
- the chat arrives, then the flow run starts (`RunChatbotFlow` picked up): **2.3 s**;
- ChatGPT answers: 1.8 s;
- the AI answer arrives, then the flow resumes (`ResumeChatbotAi` picked up): **1.3 s**;
- the send through the send pool: about 1 s.

The two waits are idle pickup. Workers on the Redis queue (Horizon, `config/queue.php` redis connection) poll with a sleep (up to 3 s) because `block_for` is not set. The pools (`chatbot:ai-pool`, `botcake:send-pool`) also poll their tables on an interval. The owner cares most about the first AI reply.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **The Redis queue connection uses a blocking pop:** `block_for` from env `REDIS_QUEUE_BLOCK_FOR`, default 5 (seconds). Check how Horizon reads it (supervisor config or connection) and that it applies to the chatbot and chatblast queues. The predis client's `read_write_timeout` must be safely above `block_for` (e.g. 60), so blocked connections are never cut. Find where predis options are set (`config/database.php` redis options or the connection's parameters) and set it explicitly, documented. | Pickup in milliseconds, not seconds. |
| **Horizon workers' idle `sleep`** for the chatbot and chatblast supervisors goes down to 0 or 1 where block_for covers it. Use what Horizon supports; don't break the auto balancing. | Same. |
| **Pools.** Measure each pool's idle poll interval and cut it to at most 200 ms when idle, or wake them on new work (e.g. a Redis list push or pub/sub "new ticket" signal). Keep CPU low when idle: a 200 ms loop that does one cheap indexed query or one Redis call is acceptable; say which you chose and the idle CPU it costs. | The resume and send hand-offs. |
| **Rollback.** `REDIS_QUEUE_BLOCK_FOR=0` restores polling, and pool intervals come from config with env overrides. | Safe. |
| **Proof.** In a local run with Redis and Horizon (as in 088 and 089, WSL is fine), measure chat → run start and AI answer → resume, before and after, with mock AI and mock Botcake, at 1 and 10 chats per second. Report the numbers. Run the existing suites. | The owner wants the first AI reply faster. |

## Done when

- The local before and after numbers are in the result.
- A test asserts the queue config (`block_for` and `read_write_timeout` relation) and the pool interval settings.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes.
- The result lists the env keys (names only) and the server steps: Horizon terminate, and restarting the pools.
- Report the commits.
