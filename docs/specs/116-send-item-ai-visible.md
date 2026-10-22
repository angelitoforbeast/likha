# Brief 116: each message of a Send step has "Visible to AI" (default on)

The AI steps of a flow get the last N messages of the conversation as history (`FlowEngine::history()`): the customer's chats (`botcake_chats`) and every message the bot sent (`chatbot_replies`, kind `send`, status `sent`), joined into one assistant message per send. In the live Main Flow, node n100 sends two items in one Send step: `{{GPT INPUT}}` (the AI's answer) and `{{Fill-Up Form}}` (a fixed form). Because the AI sees the fixed form as "its own" earlier answer, it copies it, and 22–28% of n100 replies now carry the form twice. The owner wants to choose per message what the AI sees.

## Decisions
| Decision | Why |
|---|---|
| **Per item of a Send step, a checkbox "Visible to AI"**, stored in the item config (e.g. `ai: false` when unchecked; missing = visible). Shown in the flow editor next to each item of the Send step, in English. | The owner chooses per message. |
| **Default on**: existing flows and new items behave exactly as today. | No change until someone unchecks. |
| **History skips hidden items**: when building AI history (live and the flow-builder test path `TestHistory` / `$run->sends`), a message whose item was unchecked is left out; if every message of a send is hidden, that send adds nothing. Visible messages of the same send stay. | The AI sees only what it should. |
| **Store the flag with the sent record**: mark hidden messages in what is saved to `chatbot_replies.content` (e.g. `"ai": false` on that message) so history can filter later. **Never send the flag to Botcake or Forbeast**: strip it before the payload (check `BotcakeSendQueue::submitChatbot`, `Outbox`, the Forbeast/gateway path). Old rows have no flag = visible. | History is read from the saved rows. |
| Images already add no text to history; unchanged. Other nodes that send (wait-for-reply prompts, AI direct sends at FlowEngine ~353/~399) stay visible; only Send-step items get the checkbox. | Lean. |
| No migration (JSON fields only). | |

## Done when
- Tests: a send with `{{GPT INPUT}}` visible and Fill-Up Form unchecked → the next AI call's history contains the AI text and not the form text; an unchecked-only send adds nothing; items without the flag stay in history (old flows, old rows); the payload to Botcake/Forbeast has no `ai` key; the test path (flow builder test) follows the same rule.
- The flow editor shows the checkbox per item, checked by default, and saving/publishing keeps it (test or a quick render check of the editor view/JS).
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes (plus any chatbot test folder you touch).
- Result: files changed, no migration, commits, and where the checkbox is in the editor.
