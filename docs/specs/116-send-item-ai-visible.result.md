# Result 116: Visible to AI per Send message

## What shipped
- Editor: each message of a Send step has a "Visible to AI" checkbox (checked by default), in the message's row under the Type / Text fields (`resources/views/chatbot/editor.blade.php`, Send form). Unchecking stores `ai: false` on the item; checked removes the key. `FlowGraph::cleanConfig` keeps `ai: false` only, so save/publish keeps it.
- Engine: `FlowEngine::send` copies `ai: false` onto the built message; it is saved with the sent record (`chatbot_replies.content`) and in `$run->sends`.
- History: `TestHistory::text` skips messages with `ai === false`; live `history()` now uses it too, so live and the test path follow one rule. A fully hidden send gives empty text and is dropped. Old rows (no flag) stay visible.
- Wire: `BotcakeOutbox::wire()` strips `ai` at the three sinks: `BotcakeProvider::send`, `GatewayProvider::send`, and `BotcakeSendPool` (send_content payload). The queued ticket payload still carries the flag so the record keeps it.

## Files changed
app/Services/Chatbot: FlowGraph, FlowEngine, TestHistory, BotcakeOutbox, BotcakeProvider, GatewayProvider, BotcakeSendPool; resources/views/chatbot/editor.blade.php; tests/Feature/Chatblast/ChatbotSendItemAiVisibleTest.php.

## No migration. Commits
Spec saved; feat commit (code + tests).

## Case table
| Case | Test | Result |
|---|---|---|
| flag kept only when unchecked | test_the_ai_flag_is_kept_by_the_graph_cleaner_only_when_unchecked | pass |
| hidden item and hidden-only send left out; old rows stay | test_history_leaves_out_hidden_messages_and_a_fully_hidden_send_adds_nothing | pass |
| test path same rule, record keeps flag | test_test_path_follows_the_same_rule_and_the_send_records_the_flag | pass |
| no `ai` key on the wire | test_the_payload_to_botcake_and_the_gateway_has_no_ai_key (tests `wire()` directly, not each HTTP sink) | pass |
| editor checkbox | test_the_editor_has_the_checkbox_per_message (string render check) | pass |

Red run: first failure was `BotcakeOutbox::wire()` undefined / "Visible to AI" missing.
Suite: `phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` → all pass after the test fix (899 tests; the one failure in the first run was my own test's reply timestamps, fixed).

## Notes
- Wire stripping is covered at the helper, not per HTTP sink; the three call sites are one-line uses of it.
- Sequence steps use the same `send()`, so they get the flag too.
- Suggestion: none.
