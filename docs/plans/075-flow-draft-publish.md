# Plan 075: flow draft, Undo/Redo, Save and Publish

Risk: medium (a change behind existing access checks; the live engine is not changed).

## Design left open by the spec

- **Engine:** no change. `ChatbotRunner` already loads `$flow->graph`, which stays the published graph.
- **Migration** `2026_10_24_100000_add_draft_to_chatbot_flows.php`: the four columns (`draft_version` unsigned int, default 0) and `chatbot_flow_versions` (graph as longText like `chatbot_flows.graph`, `note` up to 200, index on `flow_id, id`). Existing rows get `published_at = updated_at` so they show "Published". Guarded with `hasTable` / `hasColumn`.
- **Version check:** every write (save, publish, discard, restore) sends `version`; the update is one `UPDATE … WHERE draft_version = ?` that bumps it, so two saves cannot both pass. A save may send `overwrite: true`. A missing version is a conflict.
- **Status:** `published_at` null → "Never published"; `draft_graph` set → "Draft (unpublished changes)"; else "Published". A save whose graph equals the published graph stores `draft_graph = null`.
- **Publish** cleans the draft again (`FlowGraph::clean`), refuses "nothing to publish" on a published flow without a draft, and keeps the last 50 versions per flow.
- **Test tab:** `version=draft|published` (default draft); the editor's Preview / Test links to `/chatbot/test?flow=ID&version=draft`.
- **Duplicate step:** the editor has no Duplicate yet; the story's Undo covers it, so the step form gets a "Duplicate step" button (copy without wires).

## Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | Migration, model, draft/publish/discard/versions/restore endpoints, version check, Test tab version | backend | medium | S-075.1–.9 |
| 2 | Editor toolbar: Undo/Redo, Save draft, Publish modal, Discard, History drawer, chip, conflict banner, leave warning, Duplicate step; Test tab toggle | frontend | medium | S-075.10 |

Task 2 depends on task 1 (same controller responses). Review: skeptic-reviewer, adversarial depth, on the whole diff; then the browser check.
