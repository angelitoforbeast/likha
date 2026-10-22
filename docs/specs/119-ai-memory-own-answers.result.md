# Result 119: the AI remembers its own answers

**Status:** done, green. No migration (JSON only).

## Files changed
- `app/Services/Chatbot/FlowEngine.php`: `send()` builds `ai_text` / `ai` marks per message (new `aiText()`); the AI step's own direct send records `ai_text` = its answer.
- `app/Services/Chatbot/TestHistory.php`: `text()` is the single history rule (used by live and test path).
- `app/Services/Chatbot/BotcakeOutbox.php`: `wire()` strips `ai_text` too.
- `app/Services/Chatbot/FlowGraph.php`: `cleanConfig` keeps `ai: true` only.
- `resources/views/chatbot/editor.blade.php`: "Show this message to the AI" checkbox, or the note when the message has an AI-output variable (switches live as the text is typed).
- Tests: `ChatbotSendItemAiVisibleTest` rewritten for the new rule; four existing tests that compare stored message arrays now expect the `ai` / `ai_text` marks.

## Rule (for the owner)
The AI remembers the customer's messages and its own answers. When the bot sends a message that contains an AI answer variable (the field an AI step saves to, e.g. `{{GPT INPUT}}`), only the AI's answer part is remembered, so a form in the same bubble is never copied. Any other message the bot sends (forms, Main Flow texts, sequences) is not remembered unless "Show this message to the AI" is ticked on it. Messages sent before this release keep being remembered in full.

## Case table
| Case | Test | Result |
|---|---|---|
| answer + form in one bubble → only the answer in history | `test_one_bubble_with_the_ai_answer_and_a_form_...` (test path, `ai: false` legacy hides, `ai: true` shows, wire has no marks) | pass |
| live history: own answers in order, flow text hidden, `ai: true` shown, old rows full text | `test_live_history_has_ai_answers_as_assistant_turns_...` | pass |
| payload has no `ai` / `ai_text` | `test_the_payload_to_botcake_and_the_gateway_has_no_ai_keys` | pass |
| graph cleaner keeps `ai: true` only | `test_the_ai_flag_is_kept_by_the_graph_cleaner_only_when_checked` | pass |
| editor has checkbox and note | `test_the_editor_has_the_checkbox_and_the_note` | pass |

`vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 936 tests, OK.

## Notes
- Red-first was not recorded: the tests were written after the code in this run (no red output to report).
- The editor change is JS in a Blade view, not browser-checked; only the rendered strings are tested. `npm run build` is not needed (inline script).
- Suggestion: new rows now carry `ai: false` on every non-AI message, so the stored `content` of `chatbot_replies` differs from before (readers of that column that compare exact arrays would notice; none found in the app).
