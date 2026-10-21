# Result 064: show the custom field name next to the Message field ID

Spec: `docs/specs/064-field-name.md`. Branch `feat/064-field-name`, cut from `02d8b503`. Not pushed. The brief was small, so the plan was in-line, and the brief was taken as the go.

## What changed

Everything is in the page script (`page_settings/index.blade.php`): `fieldName()`, called from `paint()` for `botcake.field.message`.

**The Message field line:**

| State | Shows |
|---|---|
| The ID is in the stored list | "204121 · GPT-INPUT" (the name in grey, wrapping) |
| The ID is not in the list | "188764 · (not in Botcake list)" in amber |
| No list stored yet | The ID plus a grey "press Test for the name" |

**Supporting changes:**
- `picks()` no longer adds its red "(not in Botcake list)" line under the Message field, so the state shows once. The Flow ID keeps its line.
- The name comes from the list already in the grid data (spec 062), so there is no Botcake call and no new query.
- A change to the list repaints the line, the same way it repaints the dropdown.

**Unchanged:** editing (the 062 dropdown), saves, history and Ready.

## Case table

**Red run, before the code:** all three data sets failed with "To contain …", because the code was missing.

| Case | Test | Result |
|---|---|---|
| S-064.1 the name is shown | `FieldNameTest::test_S_064_1_the_message_field_line_shows_the_name_from_the_stored_list` (data set "nasa listahan") | pass |
| S-064.1 not-in-list is shown | the same test (data set "wala sa listahan") | pass |
| S-064.1 no-list is shown | the same test (data set "wala pang listahan") | pass |

The test finds the page-script code only (there is no JS test harness), and also checks the list is in the grid data. The browser check proves what is drawn.

**Suite:** `tests/Feature/PageSettings` gives `Tests: 142`, `1150 assertions`, all passing.

## Browser check

Setup: headless Chrome, 1366 × 768, scratch app with fake Botcake. Evidence is in the main checkout's `handoff/064-field-name/screens/`.

- **ID in the list:** "801 · GPT Input".
- **ID not in the list:** "9999 · (not in Botcake list)" in amber.
- **No list stored yet:** "801" plus the grey "press Test for the name".
- **Width:** no horizontal scroll at 1366 and 1280.
- **Errors:** no navigation, no JS error.

## Review

No `skeptic-reviewer` pass was run. This is a low-risk display change in one view, with no new input path. Nothing new goes to `TODO.md`.
