# Result 112: `{{replies_via}}` — the flow knows which bot carried the chat

## What shipped

- **Variable** `{{replies_via}}` = `Botcake` or `Forbeast`. `ChatbotProvider::via()` decides from the chat that started the run (payload source `gateway` → Forbeast, any other chat → Botcake); a run with no chat (sequence step, resumed wait, Test, preview) falls back to the page's provider. `FlowRun::via()` memoises it (one chat read per run) and `FlowRun::vars()` exposes it, so it works in send texts, AI prompts, conditions, sheet columns and Set field. Listed under System variables with a one-line tip (`ChatbotFlowController::RUN_VARS` / `RUN_VAR_INFO`); known to the content check and to the tag collector (`ChatbotTags::RUN_VARS`), so it is not mistaken for a customer field.
- **Recorded:** `via` (`botcake` / `forbeast`) goes into the reply's existing `tags` JSON (bot sends and Inbox manual replies) and into the order event's `detail`.
- **Results** (`/chatbot/results`, `/chatbot/results.json`, `chatbot:results --via=`): "Replies via" filter (Both / Botcake / Forbeast; applied to chats, sends, by-message tags, by-ad and orders) and a new `by_via` block + "By bot" table (chats, sent, replied %, orders, delivered) that always shows both bots. Rows without `via` (before this change) count as Botcake.

## Migration

None. `via` lives in the existing nullable `tags` JSON and `chatbot_events.detail`.

## Case table

| Case | Test | Result |
|---|---|---|
| S-112.1 gateway chat → Forbeast, other chat → Botcake | `test_S_112_1_…` | pass |
| S-112.2 no chat → page provider; chat wins over provider | `test_S_112_2_…` | pass |
| S-112.3 fills a send text and a sheet column | `test_S_112_3_…` | pass |
| S-112.4 in the system variable list | `test_S_112_4_…` + `ChatbotVariableGroupsTest` S-097.1 | pass |
| S-112.5 tags and order event carry it | `test_S_112_5_…` | pass |
| S-112.6 results filter and per-provider split | `test_S_112_6_…` | pass |

`vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: OK, 894 tests, 6289 assertions, 0 failures.

Existing tests touched: `ChatbotVariableGroupsTest` (system list gains `replies_via`) and `ChatbotResultsTest` S-100.12 (JSON keys gain `by_via`).

## Notes for the owner

- The order event's `via` comes from the run that reached the order node, so an order placed in a run resumed without a chat (e.g. after a timed wait) follows the page's provider.
- Story changes: none (the spec brought no `qa/stories.md` entries).
