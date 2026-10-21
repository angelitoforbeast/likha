# Brief 095: a Botcake refusal whose "message" is not a string no longer throws

## Why

Live, at 17:19:06 and 17:21:05 on 2026-10-20, `ErrorException: Array to string conversion at app/Services/Chatbot/BotcakeOutbox.php:51`. The trace:

```
BotcakeOutbox::outcome (51)
BotcakeOutbox::send (36)
FlowEngine (234, 139, 59)
ChatbotSequences (294)
RunChatbotSequenceStep (31)
```

`outcome()` does `trim((string) ($body['message'] ?? ''))`. Botcake sometimes answers a failed send with `message` as an array or object, so the cast throws. The send should be logged as `failed` with a reason, but the job crashes instead, and a sequence step may be retried by the tick.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| Wherever Botcake response text is read for an error reason, turn a non-string `message` into readable text: a string as is; an array or object as compact JSON or the first string inside it; anything else empty. Cap it at 150 characters, as now. | Never throw on Botcake's shape. |
| Check every similar spot: `BotcakeOutbox::outcome`, the 089 send pool's chatblast and chatbot result handling, `ChatblastSender` reason building, `BotcakeFlowNames`, and `ChatblastFlowInfo` error text. Grep for `(string) ($body['message']` and similar `['message']` casts. Fix them all the same way, through one small helper. | Same bug class. |
| Tests: `outcome()` with `message` as a string, an array, a nested array, an object, null, or missing. Each gives `failed` with a sensible reason and no exception. One sequence-step test where the faked Botcake returns `{"success":false,"message":{"error":"x"}}` logs `failed` and doesn't crash the job. | Regression. |

## Done when

- The tests pass.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes.
- Report the commits.
