# Brief 073: botfields hold long prompts

## Why

The owner's AI prompts are long. In his product sheet, Prompt 1 is 8,036 characters, Prompt 2 is 14,113 and Prompt 3 is 13,262. `/chatbot/pages` refuses botfield values over `ChatbotFields::MAX_VALUE` = 10000, so Prompt 2 and 3 cannot be stored.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| Raise `ChatbotFields::MAX_VALUE` to 50000. `page_settings.value` is TEXT, which holds about 64 KB, so 50,000 characters of mostly ASCII fit. Also check whether TEXT is in bytes: 50,000 multi-byte characters can exceed 65,535 bytes. If so, either cap at a safe byte length (60,000 bytes) with a clear message, or keep 50,000 characters and add a byte check. Pick the one that never truncates silently, and say which. | Long prompts. |
| Check every place a botfield value passes through, and make sure no other cap is below 50,000 or the byte limit: <br>• PageSettings validation, the cell endpoint, the request input limits and the modal editor's `maxlength`; <br>• the engine: the AI step's expanded system prompt, `FlowGraph::MAX_PROMPT` (it applies to the template text only, not to the expanded value — confirm), and the `ChatbotVars` expansion. | A silent cut would break the prompt. |
| No other change. | Small. |

## Done when

- A test stores a 14,113-character botfield and reads it back unchanged.
- A test shows the AI step receives the full expanded prompt (Http::fake for OpenAI, asserting the request body).
- A test shows a value over the limit gives 422 with a clear message.
- `php artisan test tests/Feature/Chatblast` passes.
- Report the commits and the limit you chose.
