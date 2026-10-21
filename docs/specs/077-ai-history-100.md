Small task: spec 077, about 30 minutes.

**Branch:** `git checkout -b fix/077-ai-history-100 main` in this worktree. `main` is 0d574830 and is live.

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/077-ai-history-100.md`, and the result as `docs/specs/077-ai-history-100.result.md`.

# Brief 077: AI steps remember up to 100 messages, 100 by default

## Why

The AI step's "Remember the last N messages (0 = off)" is capped at 20 (`FlowGraph::MAX_HISTORY`) and defaults to 0. The owner wants a default of 100 (2026-10-19: "default Remember the last N messages … iset na 100"). The history is the customer's own conversation on that page, `page_id` + `psid`: their chats plus our sent replies. Keep it that way.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| `FlowGraph::MAX_HISTORY` = 100. | The owner's number. |
| The default for a **new** AI step is 100, in the editor's add-step defaults and in `cleanConfig` when the key is missing. An explicit 0 stays 0, and so do existing saved values. | Don't silently change saved flows. |
| Check every place the history passes through for a cap or a cost guard (`ChatbotAi`, how the history is loaded), and keep loading it bounded: one query per side with a LIMIT N, newest first, then reversed. | 1M chats/day. |
| The editor form text says "Remember the last N messages of this customer on this page (0 = off, max 100)". | Clarity. |

## Done when

- Tests:
  - a new AI step defaults to 100;
  - 100 is accepted and 101 is clamped to 100 (or refused, matching the existing behaviour for out-of-range values);
  - the AI request carries at most N history messages, only this page and PSID, in time order.
- `php artisan test tests/Feature/Chatblast` passes.
- Report the commits.
