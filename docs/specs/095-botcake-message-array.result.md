# Result 095: a Botcake refusal whose "message" is not a string no longer throws

Branch `fix/095-botcake-message-array`, cut from `release/090b`. Not pushed.

## What changed

- `BotcakeClient::messageText(mixed $body): string` is the one helper. It returns the `message` string trimmed. For an array or object it returns the first non-empty string inside (depth first), or compact JSON when there is no string. For anything else (null, a number, missing, a body that isn't an array) it returns `''`. Each caller keeps its own cap: 150 in the outbox and the inbox, 120 in the chat block, as before.
- These four casts now use the helper:
  - `BotcakeOutbox::outcome`: the live crash. The 089 send pool's chatbot results go through this same `outcome()`.
  - `ChatbotInboxController` manual reply: the same cast, copied.
  - `BotcakeChatBlock::error`: the same cast.
  - `BotcakeClient::customerInfo`: the "not found customer" check. An array `message` threw here too.
- The other places in the spec were checked and need no change:
  - Send pool, chatblast side: results go to the `ChatblastSender` stepper, which never reads `message`. It stores the whole body through `trim()`, which already JSON-encodes arrays.
  - `ChatblastSender` reasons are built from HTTP codes and field checks only.
  - `BotcakeFlowNames::call` and `ChatblastFlowInfo` error text are fixed strings (`Botcake: refused (success:false)`, `Botcake: HTTP n`) and don't read `message`.
- The other `['message']` casts in `app/` (Boardroom providers, OpenAI, Google) don't parse Botcake responses, so they are out of scope.

## Case table

| Case | Test | Result |
|---|---|---|
| S-095.1 | `BotcakeMessageShapeTest::test_S_095_1_a_refusal_of_any_message_shape_is_failed_with_a_readable_reason` (9 data sets: string, array, nested array, object, no string inside, null, missing, number, long string capped) | Pass |
| S-095.2 | `ChatbotSequenceEngineTest::test_S_095_2_a_refused_step_whose_message_is_an_object_is_logged_failed_and_the_job_does_not_crash` | Pass |

Red runs before the fix:
- S-095.1: 4 errors `ErrorException: Array to string conversion` at `BotcakeOutbox.php:51` (array, nested, object, no string inside), and 1 failure: a number gave `Botcake said "42"` instead of empty.
- S-095.2: no `chatbot_replies` row was written (expected `failed`, `Botcake said "x" (HTTP 200)`).

## Verification

- `php -l` on the 6 changed PHP files: no syntax errors.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: **OK, 746 tests, 5090 assertions**, 1 skipped (`QueueRedisHorizonTest` S-088.4, redis data set: no Redis server on this machine, as before), 47 PHPUnit deprecations (already there before this change).
- The full `artisan test` suite was not run; the spec asks for these three folders.

## Story changes

- Added S-095 (2 cases) to `qa/stories.md`. The spec's list of `message` shapes is S-095.1. Two data sets were added to it: an array with no string inside (to pin the compact-JSON fallback) and a number (to pin "anything else empty").

## Notes

- In S-095.2, a step whose send fails still logs "step 1 ran" and moves on, the same as any failed send node before this change (S-089.9). The spec asks only that the job doesn't crash, so this behaviour was left as it is.
- The process (plan gate, reviewer) was shortened for this bounded fix because the brief said to start now. No skeptic review and no browser check were run (no UI changed).
- Conflicts between the spec and CLAUDE.local.md: none.
