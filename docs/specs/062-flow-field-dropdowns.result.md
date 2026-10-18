# Result 062: Flow ID and Message field are picked from a dropdown

Spec: `docs/specs/062-flow-field-dropdowns.md`.

- **Branch:** `feat/062-flow-field-dropdowns`, cut from `3062015a`. Not pushed.
- **Scope:** no migration, and no new keys or routes.
- **Plan:** the brief was small, so the plan was in-line (no plan file). The brief was taken as the go.

## What changed

**Stored lists** (server):
- **Flows:** `botcake.flow.shipping.options` now holds every non-removed flow as `{id, name, candidate, recommended}`.
  - **candidate:** "CHATBLAST" in the name.
  - **recommended:** a candidate with "(post_order)".
- **Custom fields:** `botcake.field.message.options` now holds every custom field.
  - **candidate and recommended:** the name is "GPT Input" or "message" (any case, spaces and hyphens ignored).
- **Order:** stored already sorted. Recommended first, then the other candidates, then the rest, each group by name.
- **When they refresh:** at the same points as in 057: Test, Test all, and a Botcake ID or token save (`BotcakePageSetup::check`).
- **Unchanged:**
  - auto-fill still uses the candidates only;
  - a new Botcake ID still clears both lists;
  - rendering reads only the stored keys, so it never calls Botcake.

**Page** (`page_settings/index.blade.php`):
- **The dropdown:** editing the Flow ID or the Message field opens a select instead of the text input (`startPick`).
  - **Items:** "name · id", with " (recommended)" where marked.
  - **Empty cell:** "Pick one…" first.
  - **Saved ID not in the list:** shown as "<id> (not in Botcake list)".
  - **No stored list:** "No list yet: press Test" (disabled) and the current ID.
  - **Last item:** "Type an ID…", which opens the usual text input with the current ID.
- **Saving:**
  - picking an item goes through the existing `save()`, so it gets the same marks, history, Ready reset and no reload;
  - Escape cancels, and Enter or leaving the cell saves.
- **When not editing:**
  - the ID shows with its name under it, as before;
  - the guide shows when the list has no candidate;
  - red "(not in Botcake list)" shows when the saved ID is not in the list (flows skip it when the name line already says "Not in Botcake");
  - "This flow is not a CHATBLAST flow…" / "This field is not a 'GPT Input' field…" shows when it is in the list but not a candidate.
- **057's always-visible select** under the ID is removed, so there is one dropdown, in the edit.
- **Older lists:** lists stored before this change hold candidates only and have no `candidate` flag. The page treats a missing flag as a candidate, so they keep working until the next Test.
- **Unchanged:** 060's fixed header, the grid scroll and the no-reload behaviour.

## Case table

**Red runs, before the code:**
- **S-062.1** failed on the old candidates-only list ("Failed asserting that two arrays are identical").
- **S-062.3** failed because the dropdown code was missing from the page.
- **S-062.2 and S-062.4** pin behaviour the save path and the render already had. They passed before the change and still pass. My first S-062.2 expected two history rows, but history lists only the earlier values, so the assertion now checks the earlier value.

| Case | Test | Result |
|---|---|---|
| S-062.1 order and recommended marks | `FlowFieldDropdownTest::test_S_062_1_the_stored_lists_hold_every_flow_and_field_in_order_with_the_recommended_marks` | pass |
| S-062.2 a pick saves its ID (history, Ready reset); a typed ID still saves | `…test_S_062_2_picking_an_item_saves_its_id_through_the_cell_save_with_history_and_a_ready_reset` and the browser check | pass |
| S-062.3 the edit dropdown, "Type an ID…", no list falls back to typing | `…test_S_062_3_the_page_edits_the_two_ids_with_a_dropdown_that_falls_back_to_typing` (page literals) and the browser check | pass |
| S-062.4 no Botcake call on render | `…test_S_062_4_rendering_the_page_and_the_grid_never_calls_botcake` | pass |

**Changed 057 tests:**
- S-057.2 and S-057.3 now read the candidates from the full list by the `candidate` flag, and also check the list holds every flow or field.
- S-057.4 looks for `startPick` instead of the removed `cb-pick` select.

**Suite:** `tests/Feature/Chatblast` and `tests/Feature/PageSettings` give `Tests: 369`, `2647 assertions`, all passing. The full suite was not run, because the change touches only Page settings.

## Browser check

Setup: headless Chrome, 1366 × 768, scratch app with fake Botcake, a row near the bottom of the grid. Evidence is in the main checkout's `handoff/062-flow-field-dropdowns/screens/`.

1. **No list yet:** the Flow ID edit showed "[disabled] No list yet: press Test", "* 502", "Type an ID…".
2. **After Test:**
   - Flow: "CHATBLAST - SHIPPING STATUS (post_order) · 502 (recommended)", "CHATBLAST - SHIPPING STATUS · 501", "Welcome · 503", "Type an ID…".
   - Field: "GPT Input · 801 (recommended)", "GPT-INPUT · 802 (recommended)", "Phone · 803", "Type an ID…".
3. **Picking 501:** "Saved", the flow name line updated, and the Test send line turned "Needs a test send".
4. **"Type an ID…":** the text input opened with focus and the current ID. Typing 9999 and Enter saved it, and the dropdown then showed "9999 (not in Botcake list)".
5. **Throughout:** the grid scroll stayed at 5137, the window at 0. No navigation, no JS error, no horizontal scroll at 1366.

## Review

No `skeptic-reviewer` pass was run. The task is medium risk at most: a UI screen and a stored-list format behind existing validation, with no new way in, and the brief asked for about 45 minutes. Say if you want one before the PR.

**Accepted (in `TODO.md`):**
- The page-script tests find literals only (no JS test harness); the browser check covers the behaviour.
- A list stored before this change keeps the candidates only until the page's next Test.

## Brief notes

- **"Display when not editing: as today":** 057's extra select under the ID is gone, because the edit dropdown replaces it. Two dropdowns for one value would confuse.
- **"(not in Botcake list)"** shows both in the dropdown and as a red line under a saved ID. A saved flow that isn't in Botcake already gets "Not in Botcake" from its name line, so the flow skips the second line.
- **The Message field has no name line today,** and none was added ("as today").
