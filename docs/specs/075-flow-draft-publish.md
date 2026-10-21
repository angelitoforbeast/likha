New task: spec 075, medium, about 2.5 hours.

**Where you work.** In this worktree, run `git checkout -b feat/075-flow-draft-publish main`. Main is 3e750712, which is live on Likha. Another branch, spec 074 (the /chatbot inbox, plus engine changes for pausing and event logging), is being built at the same time. Keep your engine change small and local to *which graph is loaded*.

**Setup.** If `vendor/` is missing, copy it from `../botcake-chat-059/vendor`, then run `composer dump-autoload`.

**Rules**
- Never push. Never touch remote hosts. Never call real APIs.
- No attribution lines.
- Save this brief as `docs/specs/075-flow-draft-publish.md` in your first commit, and the result as `docs/specs/075-flow-draft-publish.result.md`.
- Run an adversarial review and a browser check (desktop and phone) before the result.

# Brief 075: the flow editor gets Undo/Redo, Save and Publish

## Why

The /chatbot flow editor (spec 069; `resources/views/chatbot/editor.blade.php`, `ChatbotFlowController`, `App\Services\Chatbot\FlowGraph`) autosaves every change straight into `chatbot_flows.graph`. The engine (`FlowEngine` / `ChatbotRunner`) runs that same graph, so a half-finished edit goes live at once on every page linked to the flow.

The owner wants it to work like CBB and Botcake: "need na may undo, save, and publish etc".

## Stories

- **S-075.1:** As the owner, I edit a flow. Changes go to a **draft**. Live customers keep getting the **published** version until I press **Publish**.
- **S-075.2:** The toolbar has:
  - **Undo** and **Redo**: Ctrl+Z, and Ctrl+Shift+Z or Ctrl+Y; at least 50 steps; they cover adding, moving, deleting and duplicating steps, wiring and unwiring, and form edits.
  - **Save draft**: also autosaved every few seconds, as now, with a "Draft saved hh:mm" status.
  - **Publish**: asks to confirm, shows how many pages use the flow, and refuses with a clear message if the graph does not pass `FlowGraph::clean`.
  - **Discard draft**: back to the published version; asks to confirm.
  - **Preview / Test**: opens the Test tab with this flow's draft.
  - A status chip: "Published", "Draft (unpublished changes)", or "Never published".
- **S-075.3:** **Versions.** Every Publish keeps a snapshot: who, when, and an optional note. A "History" drawer lists the last 20 publishes and can **Restore** one into the draft (it does not publish by itself).
- **S-075.4:** Leaving the page with unsaved local changes warns. When two people edit, the second save warns: "This draft changed since you opened it. Reload, or overwrite?" Use a draft version number or updated_at check.
- **S-075.5:** The Test tab can run the **draft** or the **published** version (a toggle, default draft). Live runs always use **published**.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Storage**, in an additive migration:<br>• `chatbot_flows` gets `draft_graph` (longText JSON, nullable), `draft_version` (int), `published_at`, `published_by`;<br>• the existing `graph` becomes the **published** graph;<br>• a new table `chatbot_flow_versions` (flow_id, graph JSON, note, published_by, created_at), keeping the last 50 per flow.<br>Existing flows count as published, with `draft_graph` = null, meaning "no unpublished changes". | No breaking change to the live engine. |
| **The live engine reads only `graph`** (published). The editor reads and writes `draft_graph`, falling back to `graph` when the draft is null. Publish copies the draft into `graph`, in a transaction with a snapshot. | Live safety. |
| **Undo and Redo are client-side**, as a history stack of graph states in the editor's JS. They are not stored on the server. | Simple. |
| **Renaming, duplicating and deleting stay as in 069.** Duplicate copies the published graph and the draft. | — |
| **All screen text in English.** No page-level horizontal scroll. | House rules. |

## Done when

- **Tests:**
  - an edit does not change what a live run uses until Publish;
  - Publish refuses an invalid graph;
  - Discard restores the draft;
  - Restore from history puts the version in the draft, unpublished;
  - the conflict check on a stale `draft_version`;
  - Test runs the draft or the published version;
  - existing flows keep running after the migration.
- **Browser check:**
  - undo/redo across add, move and wire;
  - publish, history and restore;
  - the status chip;
  - phone width.

  Put screenshots in `docs/specs/075-flow-draft-publish/`.
- `php artisan test tests/Feature/Chatblast` passes. The full suite adds no new failures (the known ones are ExampleTest and NightRun ImportStartTest).

## Report back

Report the commits, the migration, the test lines, the review findings, the screenshots, and anything the brief got wrong.
