# Brief 112: the flow knows which bot carried the chat — `{{replies_via}}`

The owner, 2026-10-22: "pwede bang magadd ng customfield, kung anong bot yung gumagana?" … "pwede bang maadd na yung custom field".

## Decisions
| Decision | Why |
|---|---|
| New system variable `{{replies_via}}` = `Botcake` or `Forbeast` (display names), resolved for the current run from the chat that started it (payload source `gateway` → Forbeast, else Botcake), falling back to the page's provider (`ChatbotProvider::name`). Available everywhere system variables work (send texts, AI prompts, conditions, sheet columns, Set field), and listed in the editor's variable list under System variables with a one-line description. | The owner's ask. |
| Record it with each reply row (add to the reply's existing JSON/tags the way spec 100 stores tags; no new column unless one is truly needed — if a migration is needed it must be nullable-only and the result must say so) and with order events. Results (`/chatbot/results`, `chatbot:results --json`) get a "Replies via" filter and a per-provider split in the funnel (chats, sent, replied %, orders, delivered). | Compare the two bots. |

## Done when
- Tests: variable resolves to Forbeast for a gateway chat and Botcake for a Botcake chat, fallback to page provider; works in a send text and a sheet column; listed in the variable list; results filter/split.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes.
- Result lists any migration (none expected) and the commits.
