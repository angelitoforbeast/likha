# Result 168: a send Botcake refuses doesn't restart the customer's conversation

Branch `fix/168-failed-send-keeps-conversation`, cut from 29be5f6a (specs 165 + 166 on top of live 87cb0e1e). Not pushed, not deployed.

## What shipped

- **One retry (decision 1)**, `FlowEngine::retrySend`. It runs when a chatbot send resumed from botcake:send-pool failed with HTTP 5xx, or with "no answer" (HTTP 0). It applies to a send node and to an AI step's answer send.
  - It makes a new ticket with `BotcakeSendQueue::submitChatbot`, so the 165 priority and the page rate apply. The ticket has `next_at` = now + 3 s and `context.retry = 1`, both written in one DB transaction, so the pool can't claim it early.
  - The customer and ticket check (`mismatch`) runs before the retry.
  - Not retried:
    - errors with a Facebook code or "restrict" (#551, #10, #2022…)
    - 4xx
    - a 200 with `success:false`
    - "the send pool did not send it in time"
    - a pool exception
    - a ticket that is already a retry
    - a send when the pool is gone
  - Each retry writes the event `send_retry`, shown in the Inbox as "Send at step n120 retried after HTTP 500". The failed attempt stays a failed row in chatbot_replies, as before, so the Inbox shows the failed reply, the retry event and the sent reply.
  - `ChatbotRunner::resumeSend` now passes the ticket's `http`, `tags` and `retried` to the engine. The retry keeps the send's tags.
- **The run continues (decision 2)**, `FlowEngine::afterSend`. A send that still fails (or isn't retryable) no longer ends the run.
  - The path note is "Send failed: …; continued". The run goes on the `continue` edge, or, for a wait=true send, parks the wait at that node.
  - Send nodes have no `failed` port (`FlowGraph::ports`), so the "node with a failed edge" case is the AI, sheet and encode steps. Those are unchanged.
  - This also applies to the sync path (Test tab, sequences, Forbeast gateway pages), which gets no retry. A sequence step still never parks a wait.
- **Decision 3:** no change to flow content, sequences, chatblast or error classes. BotcakeSendPool, BotcakeSendQueue and ChatblastSender are untouched (spec 167 is changing them).

Files:
- `app/Services/Chatbot/FlowEngine.php`
- `app/Services/Chatbot/ChatbotRunner.php`
- `app/Services/Chatbot/ChatbotEvents.php` (the event text)
- tests
- `qa/stories.md`
- `TODO.md`

## Case table

| Case | Test | Result |
|---|---|---|
| S-168.1 | `FailedSendKeepsConversationTest::test_S_168_1_a_send_refused_with_5xx_or_no_answer_is_sent_once_more_after_3_s_and_the_run_goes_on` (500, 502, no answer) | pass |
| S-168.2 | `test_S_168_2_a_send_that_fails_twice_goes_on_to_the_next_wait_and_the_next_message_resumes_there` | pass |
| S-168.3 | `test_S_168_3_a_wait_send_that_fails_twice_parks_the_wait_at_itself` | pass |
| S-168.4 | `test_S_168_4_a_facebook_error_or_4xx_is_not_retried_and_the_run_still_goes_on` (#551 on 500, #10 on 200, 400, 404) | pass |
| S-168.5 | `test_S_168_5_a_node_with_a_failed_edge_still_follows_it_and_its_send_gets_the_one_retry` | pass |
| S-168.6 | `test_S_168_6_the_reported_path_reaches_the_wait_after_a_text_fails_twice` (n118 ok, n119 ok, n120 500×2 → n10 parked, "magkano po?" resumes) | pass |
| S-168.7 | full run below | pass |

Red run before the code: all 11 failed because the behaviour was missing.
- Retry cases: "Failed asserting that actual size 1 matches expected size 2" (no retry call).
- .4: "Failed asserting that null is identical to 'yes'" (the run stopped).

## Test runs

`php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Unit`:
- On 29be5f6a (baseline): OK, 1451 tests, 15900 assertions, 1 skipped, 0 failures (deprecation notices only).
- On this branch: OK, 1462 tests, 15988 assertions, 1 skipped, 0 failures. No new failures; there are 11 new tests.

## Story changes

- Added S-168 (from the spec's cases) to `qa/stories.md`.
- Four existing tests pinned "a failed send stops the run", which decision 2 changes. They now assert the decided behaviour:
  - `BotcakeAsyncSendTest::test_S_089_9…`: 500 → one retry → 500 → the run goes on. The AI answer send also gets its retry.
  - `BotcakeAsyncSendTest::test_S_089_11…`: a send the pool never sent in time is not retried and the run goes on.
  - `ChatbotEngineTest::test_S_069_E10…`: renamed `…_is_noted_and_the_run_goes_on`.
  - `ChatbotSendingTest::test_S_069_S3…`: renamed `…_is_logged_and_the_run_goes_on_…`.

## Review

`skeptic-reviewer`, standard depth, on 052e4a15..388cae85: no blockers or majors.
- One legacy minor (a dropped space) was fixed in 653239bb.
- The other minors and the untested paths are accepted with reasons in `TODO.md`:
  - The HTTP 0 retry also covers `recover()` after the pool died mid-call, and its docblock in the 167-frozen file is now stale.
  - `counts_order` now counts on a failed send that continues.
  - Customers Facebook can't reach get the later sends too.

## Conflicts and notes

- No conflict between CLAUDE.local.md and the spec.
- The spec was saved verbatim. It names the reporter of the case path ("Mike's path").
- No browser check: no screen changed (the Inbox already renders events by type).

## Deploy notes

- No migration and no config change. The retry uses the existing `next_at` and `context` columns of `botcake_send_requests`.
- Deploy with the queue workers and the botcake:send-pool restarted, so `ResumeChatbotSend` and the engine run the new code.
- Same commits apply to Incepxion (the same codebase).
- Merge order with spec 167: this branch doesn't touch its files. If 167 changes `finishChatbot`'s result shape (`http`), check that `ChatbotRunner::resumeSend` still reads `result.http`.

168: done — a refused bot send (5xx or no answer) is retried once after 3 s, and a send that still fails no longer ends the run, so the next wait parks and the customer's next message resumes there instead of at the opening.
