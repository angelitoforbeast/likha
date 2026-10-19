# Result 075: the flow editor gets Undo/Redo, Save and Publish

Branch `feat/075-flow-draft-publish`, cut from `main` at `3e750712`. Nothing was pushed or deployed, and no real API was called.

## Commits

| Commit | What |
|---|---|
| `f1fd72a3` | docs: the brief (verbatim), the S-075 stories, the plan |
| `8edff0f3` | feat: migration, model, draft / publish / discard / history / restore endpoints with the version check, the Test tab's version toggle, the editor toolbar (Undo/Redo, Save draft, Publish, Discard draft, History, Preview / Test, the status chip, the conflict banner, the leave warning, Duplicate step) |
| `b8304a81` | fix (review loop 1): strict draft-equals-published check, Discard refuses a never-published flow, Publish checks its own write, deleting a flow drops its history, more access and conflict tests |
| (this commit) | docs: result, stories, TODO, screenshots |

## The engine

**No engine change.** `ChatbotRunner` already loads `$flow->graph` and nothing else, and `graph` is now the published graph. The only code that writes `graph` is `publish()`, plus `duplicate()` for a new flow (which copies the source's published graph). `FlowEngine`, `FlowRun` and `ChatbotRunner` are untouched, so the work does not overlap with spec 074.

## The migration

`database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php`. It only adds, and every step is guarded with `hasTable` / `hasColumn`:
- **`chatbot_flows`** gets four columns:
  - `draft_graph`: longText JSON, nullable. Null means no unpublished changes.
  - `draft_version`: unsigned int, default 0.
  - `published_at`: timestamp, nullable.
  - `published_by`: nullable.
- **Existing rows** get `published_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)`, so they show "Published", with no draft and version 0. `updated_at` is not touched.
- **New table `chatbot_flow_versions`:** id, `flow_id`, `graph` (longText JSON), `note` (200), `published_by`, `created_at`, indexed on (`flow_id`, `id`). Each Publish keeps the last 50 per flow and deletes older ones in the same transaction.
- **`down()`** drops the table and the four columns, which also drops unpublished drafts.

Deploy: run it by hand together with the code (see TODO.md). Until it runs, the editor's save returns 500, but live runs keep working.

## How it works

- **Status:**
  - `published_at` null: "Never published".
  - `draft_graph` set: "Draft (unpublished changes)".
  - Otherwise: "Published".
  - A saved draft that is *exactly* (`===`) the published graph is stored as null.
- **Version check:** save, Publish, Discard and Restore send the `draft_version` the editor holds. The write is one `UPDATE … WHERE draft_version = ?` that also bumps the version, so two writes at the same time can't both pass. A stale or missing version gets 409 with "This draft changed since you opened it. Reload, or overwrite?". Only save accepts `overwrite: true`.
- **Publish** runs in a transaction with `lockForUpdate`:
  1. It checks the version.
  2. It refuses "There are no unpublished changes." on a published flow that has no draft.
  3. It runs the draft through `FlowGraph::clean` again. On failure it returns 422 "This flow cannot be published: …".
  4. It copies the draft into `graph` and clears the draft.
  5. It keeps the snapshot (who, when, the note) and prunes to 50.
- **Discard** clears the draft. It refuses a never-published flow, because there is no published version to go back to.
- **History** (`GET /chatbot/flows/{id}/versions`) lists the last 20 publishes, newest first, with date and time (Manila), who and the note.
- **Restore** (`POST …/versions/{v}/restore`) writes that version into the draft only, and returns 404 for another flow's version.
- **Test tab:** a new Version select (Draft by default, or "Published (live)"). The editor's Preview / Test saves the draft, then opens `/chatbot/test?flow=ID&version=draft` with both preselected.
- **Editor:**
  - **Undo/Redo:** a client-side stack of graph snapshots, 100 deep. Each add, move (one step at pointer-up), wire, unwire, Duplicate step, delete, form Done, Arrange, Discard and Restore is one step.
  - **Shortcuts:** Ctrl+Z, Ctrl+Shift+Z and Ctrl+Y. They do nothing while typing in a field or while a dialog is open.
  - **Saving:** autosave 0.8 s after a change, shown as "Draft saved hh:mm". Saves queue one at a time. After a 409, autosave stops until Reload or Overwrite.
  - **Publish dialog:** shows how many pages use the flow and their names, and takes an optional note. It saves the draft first.
  - **Confirms:** Discard asks to confirm, and so does Restore.
  - **Leave warning:** a `beforeunload` warning while local changes are unsaved.
  - **Layout:** all text in English, and no page-level horizontal scroll.

## Case table

`vendor/bin/phpunit tests/Feature/Chatblast/ChatbotDraftPublishTest.php tests/Feature/Chatblast/ChatbotEditorTest.php`: 25 tests, 209 assertions, all pass.

| Case | Test | Result |
|---|---|---|
| S-075.1 | `test_S_075_1_an_edit_does_not_reach_live_runs_until_publish` | ✔ pass |
| S-075.2 | `test_S_075_2_publish_refuses_a_graph_that_does_not_pass_clean` | ✔ pass |
| S-075.3 | `test_S_075_3_discard_brings_back_the_published_graph` | ✔ pass |
| S-075.4 | `test_S_075_4_history_lists_publishes_and_restore_puts_one_in_the_draft` | ✔ pass |
| S-075.5 | `test_S_075_5_a_write_on_a_stale_draft_version_is_refused` | ✔ pass |
| S-075.6 | `test_S_075_6_test_runs_the_draft_or_the_published_version` | ✔ pass |
| S-075.7 | `test_S_075_7_existing_flows_keep_running_after_the_migration` | ✔ pass |
| S-075.8 | `test_S_075_8_the_status_and_the_pages_using_the_flow` | ✔ pass |
| S-075.9 | `test_S_075_9_duplicate_copies_the_published_graph_and_the_draft` | ✔ pass |
| S-075.10 | browser check (below) | ✔ pass |
| S-069.D1, D4 | updated: the save sends `version`; D4 also expects 403 on publish, discard, restore and the versions list | ✔ pass |

The brief's Done-when tests map to the cases like this:
- an edit does not change a live run until Publish: S-075.1;
- Publish refuses an invalid graph: S-075.2;
- Discard restores the draft: S-075.3;
- Restore puts the version in the draft, unpublished: S-075.4;
- the stale `draft_version` check: S-075.5;
- Test runs the draft or the published version: S-075.6;
- existing flows keep running after the migration: S-075.7.

Red runs:
- **All nine S-075 tests, first run:** `FileNotFoundException: File does not exist at path …/2026_10_24_100000_add_draft_to_chatbot_flows.php` (the migration about to be written).
- **S-075.8, after the backend and before the editor:** `Failed asserting that '<!DOCTYPE html>…' contains ">Undo<"`.
- **Review loop 1:**
  - S-075.3: `Expected response status code [422] but received 200.`
  - S-075.8: `Unable to find JSON: [{"status": "draft"}]` (the loose `==` made "1" → "01" count as no change).
  - S-075.9: `Failed asserting that 1 is identical to 0.` (history left after a delete).
- **Passed at once (characterisation tests):** D4's new 403s and S-075.5's extra stale and missing version calls. The code already did both, and the tests now pin it.

**Suites:**
- `vendor/bin/phpunit tests/Feature/Chatblast`: 362 tests, 2287 assertions, all pass (PHPUnit deprecations only).
- Full suite: 1947 tests, 1 error and 1 failure, both the known ones: `ExampleTest::test_the_application_returns_a_successful_response` and `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. No new failures.
- I used `vendor/bin/phpunit` rather than `php artisan test`: this worktree has no environment file, and one must not be created.

## Review

`skeptic-reviewer` ran at adversarial depth on `f1fd72a3..8edff0f3`, then re-checked the fix (`8edff0f3..b8304a81`). The re-check found it **clean**.

| Finding | Severity | Outcome |
|---|---|---|
| `draftValue()` used loose `==`: a real edit like "1" → "01" or "500" → "500.00" counted as no change, so the draft was dropped and Publish refused | major | fixed (`===`), with a test |
| The new publish, discard, restore and versions routes were missing from the D4 access test | major | fixed |
| S-075.5 did not cover Restore, or a missing version on Publish and Discard | minor | fixed |
| `publish()` ignored the result of its own write | minor | fixed (409 before the snapshot insert) |
| Discard on a never-published flow wiped the draft back to blank | minor | fixed (422, and the button is disabled) |
| Deleting a flow left its history rows behind | minor | fixed |
| The prune test did not check which rows were removed | minor | fixed |
| Overwrite vs. Publish in the same milliseconds | minor | accepted, TODO.md |
| No `Schema::hasTable` guard on `chatbot_flow_versions` | minor | accepted, TODO.md (deploy order) |
| Duplicate step copies the last applied config | minor | accepted, TODO.md |
| A duplicated flow is "Never published" while it carries a published graph | minor (story) | accepted, TODO.md, a question for the owner |
| Restore of a version that fails `clean`, and the note trim, are untested | minor | accepted, TODO.md |

## Browser check

The browser-checker agent had no browser tools in this session. I ran the check myself with headless Chrome over CDP, using a script outside the repo (`%TEMP%\likha075\check.mjs`), the same approach as spec 069. The scratch app:
- `php artisan serve` on 127.0.0.1:8075, with a scratch sqlite file under `%TEMP%`;
- replies switched off, no OpenAI key, fake Botcake tokens;
- flow "Main COD", published and linked to 2 pages, with 2 old publishes; flow "Promo", never published;
- no AI step anywhere.

| Check | Result |
|---|---|
| Chip on "Promo" / "Main COD" | "Never published" (Discard disabled) / "Published" ✔ |
| 7 changes: add, move (real drag), wire (real drag), unwire, Duplicate step, delete (confirm), form edit | chip "Draft (unpublished changes)", status "Draft saved 12:41" ✔ |
| Undo ×7 (3 with the button, 4 with Ctrl+Z), Redo ×7 (3 with Ctrl+Shift+Z, 4 with Ctrl+Y) | canvas matches each earlier state, redo 7 of 7 ✔. Undo matched 6 of 7 because adding a step and filling its form are two steps; the 7th undo lands on the added blank step, as designed. Undo and Redo enable and disable correctly ✔ |
| Ctrl+Z inside a form field; Escape | canvas unchanged; the form closes ✔ |
| Publish dialog | "Live customers on 2 pages (Alpha Store, Beta Shop) get this version right away." With a note → chip "Published", status "Published 12:41" ✔. Escape closes it ✔ |
| History | 3 rows, newest first: "2026-10-19 12:41 · Owner CEO · Browser check: price 279", "… New price for October", "… First price" ✔. Escape closes it ✔ |
| Restore "First price" (confirm) | canvas "Price is 249 pesos.", chip Draft; the published run still answers "Price is 279 pesos." ✔ |
| Preview / Test | lands on `/chatbot/test?flow=1&version=draft` with Main COD and Draft selected; "price" → Draft "Price is 249 pesos.", Published "Price is 279 pesos." ✔ |
| Discard (confirm) | canvas back to "Price is 279 pesos.", chip Published ✔ |
| Conflict (another save with the same version, then a change here) | amber banner "This draft changed since you opened it. Reload, or overwrite?" ✔. Overwrite → saved, banner gone ✔. Reload → reloads without a leave warning ✔ |
| Leave with unsaved changes | a `beforeunload` dialog ✔ |
| Phone 390 × 844 | editor, publish dialog (358 px), history drawer (384 px) and Test: scrollWidth 390 = viewport 390, no page-level horizontal scroll ✔ |
| Console | only the 409 network line from the conflict step, which is expected ✔ |

Screenshots are in `docs/specs/075-flow-draft-publish/`, `01-…` to `14-…`:
- chip states: 01, 02, 03, 05;
- publish dialog: 04;
- history: 06;
- restore: 07;
- Test draft and published: 08;
- discard: 09;
- conflict: 10;
- phone: 11 to 14.

## Story changes

- New section S-075 (S-075.1 to .10), drafted from the brief's stories in the plan commit.
- S-075.3: on a never-published flow, Discard is refused (the review found it wiped the draft).
- S-075.8: "exactly the published graph" ("1" → "01" is a change).
- S-075.9: deleting a flow deletes its history.

## What the brief got wrong, or left open

- **"Duplicating steps" (S-075.2):** the 069 editor had no Duplicate step. I added a "Duplicate step" button to the step form (a copy without wires), so Undo has something to cover.
- **"Keep your engine change small":** no engine change was needed. The runner already reads only `graph`.
- **"Draft saved hh:mm":** the 069 status showed seconds ("Saved 04:46:53"). It now shows hh:mm as asked.
- **"Existing flows count as published":** they need a `published_at`, otherwise the status rule would call them "Never published". The migration fills it from `updated_at`.
- **"Never published" on a new flow:** its `graph` is the blank Start-only graph, so a page linked to it before the first Publish gets no reply, as in 069.
- **Duplicate:** the copy carries the source's published graph but is "Never published" (see TODO.md). This is for the owner to decide.
- **Process:**
  - The plan went into `docs/plans/075-flow-draft-publish.md` and I went ahead without a separate "go". This session can't wait for a reply, and the brief already fixed every design decision.
  - The kit says to branch from `develop`. The brief said `main` (3e750712, live). I followed the brief, since `main` is what is live.
  - The brief's `php artisan test` was run as `vendor/bin/phpunit` (see above).
