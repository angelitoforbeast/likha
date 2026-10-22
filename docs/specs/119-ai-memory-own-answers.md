# Brief 119: the AI remembers its own answers, not the whole bubble the flow sent (like ChatBotBuilder)

AI steps get history (`FlowEngine::history()`): customer chats (`botcake_chats`, active only) + every message the bot sent (`chatbot_replies` kind send/sent; a Send item can be hidden with `ai: false`). In the live Main Flow, node n100 sent `{{GPT INPUT}}` + `{{Fill-Up Form}}` in one bubble, so the AI saw the form as its own words and copied it (22–36% double forms). The old ChatBotBuilder bot's AI remembers exactly its own previous answers but never copies the form appended in the same bubble. So its memory = customer messages + the AI's own outputs. Do the same.

## Decisions
| Decision | Why |
|---|---|
| **AI-output variables**: the customer fields an AI step writes (`ai` nodes' `save_to` in the flow graph that ran; e.g. `GPT INPUT`). At send time (`FlowEngine::send`, and the AI node's own direct send if it has one), for each built text message whose template contains one or more AI-output variables, store `ai_text` on that message = the values of those AI-output variables only, in template order, joined by a blank line (nothing else of the template). Never send `ai_text` to Botcake/Forbeast (strip with `BotcakeOutbox::wire`, as `ai` is today). | Exactly the AI's own words. |
| **History, assistant side**: per sent message, use `ai_text` when present; else the message's full text only if the item is explicitly marked visible (`ai: true`); else nothing. `ai: false` always hides. Rows sent before this release (no `ai_text`, no flag) keep today's rule (full text). Same rule in the flow-builder test path (`TestHistory`). | CBB behaviour; flow texts (forms, Main Flow, sequences) are not "the AI's words" unless someone opts in. |
| **Editor (Send step)**: the per-message checkbox becomes "Show this message to the AI" — for a message with an AI-output variable show a short note instead ("The AI remembers only its own answer here") and no checkbox; for other messages the checkbox is off by default and saves `ai: true` when on (keep `ai: false` readable for old graphs = off). `FlowGraph::cleanConfig` keeps `ai: true` only. | Clear and like CBB by default. |
| No migration (JSON only). | |

## Done when
- Tests: a send `{{GPT INPUT}}\n\n{{Fill-Up Form}}` in ONE message → the next AI call's history has the GPT answer text and not the form; a message without AI variables is not in history unless `ai: true`; `ai: false` hides; the AI's own previous answers appear as assistant turns in order; old rows without `ai_text`/flag keep full text; `ai_text`/`ai` never reach the Botcake/Forbeast payload; the test path follows the same rule; editor renders the note/checkbox.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes.
- Result: files changed, no migration, commits, and the exact rule as one short paragraph for the owner.
