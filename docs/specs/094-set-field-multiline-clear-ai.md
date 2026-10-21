# Brief 094: Set field takes multi-line values, and a "Clear AI memory" step

## Why

**Set field values are single-line.** The live flow "Main Flow (from CBB)" has a Set field step, n109, with "all user input = {{ALL USER INPUT}},{{last_input}}". The owner wants a line break between the parts instead of a comma, but the value input can't take one. The owner, 2026-10-20: "gusto ko ng may breakline, di ako makapagbreakline e".

**No way to clear AI memory.** An AI step's history is the customer's last N messages and the bot's sends (`FlowEngine::history()`, from `botcake_chats` and `chatbot_replies`). Chatbot Builder has a flow action, "Delete Message History", that makes the AI forget the conversation so far. Likha has none. The owner: "wala pa yung clear ai history sa user no? yung makakalimutan yung pinaguusapan".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Multi-line Set field value.** The Set field step's value input becomes a textarea (auto-grow, about 2–8 rows) in the editor. A stored value keeps its `\n`. The node card on the canvas shows line breaks (`white-space: pre-line`), clamped to 3 lines. Variables still interpolate per line. Old single-line values are unchanged. | The owner. |
| **A new step type, "Clear AI memory"** (`clear_ai`), in the Add bar next to AI. It has one output, "next". When a run passes it, it stores a per-customer marker: a reserved customer field, e.g. `__ai_since`, holding the current time and the current chat id. | CBB parity. |
| **What the marker does.** From then on, the AI history for that customer on that page (`FlowEngine::history()`) and the built-in `{{ALL USER INPUT}}` (spec 093, `FlowRun::allUserInput()`) include only messages and sends **after** the marker. Nothing is deleted: Inbox, Chats and Replies still show the whole conversation. | Forget for the AI, keep the record. |
| **Only that customer.** The marker is per page and PSID, and never affects other customers. | — |
| **In the Test tab** the step clears the test conversation memory from spec 091, so the next AI step starts fresh. | Test matches live. |
| **Event.** The step writes an executed-action event `ai_cleared`, shown in the Inbox's executed actions as "AI memory cleared". | Visible. |
| **Optional option on the step:** "Also clear these fields", a multi-select of customer fields to empty at the same time, for example `gpt input` and `cxd`. Default none. | Common in CBB "clean slate" flows. |

## Done when

- **Tests:**
  - a Set field with a multi-line template stores and interpolates line breaks;
  - the clear step sets the marker and writes the event;
  - history after the marker excludes older messages and sends, live and in Test;
  - `{{ALL USER INPUT}}` after the marker includes only newer messages;
  - another customer is unaffected;
  - the optional fields are cleared;
  - an old flow without the step behaves as before.
- **Browser check:** the textarea with line breaks, the node card showing them, and the new step in the Add bar and on the canvas.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, and the full suite adds no new failures.
- Report the commits.

## Out of scope

- Deleting stored chats.
- Changing the live flow (the owner edits it).
