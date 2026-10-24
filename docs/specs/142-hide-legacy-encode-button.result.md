# Result 142: the flow builder shows only one "Encode order" button

Status: done

## What changed
- `resources/views/chatbot/editor.blade.php`: the legacy `data-add="encode_order"` button is removed from the Add bar (its `hidden` attribute lost to the `cb-btn` display rule). The click handler is generic (`[data-add]`), so no code needed the element. Legacy nodes still load, label, run and edit as before (node rendering and config code untouched).
- Tests: new `S-142.1` in `UploadEncodeBuilderTest`; two older assertions that pinned the hidden button (`S-137.1` `data-add="encode_order" hidden`, `S-132.1` the button and its title) were dropped, since the spec removes that button. Their save/reload and legacy-label checks stay.

## Case table
| Case | Test | Result |
|---|---|---|
| S-142.1 | `UploadEncodeBuilderTest::test_S_142_1_the_add_bar_has_one_encode_order_button_and_no_legacy_one` | red before the fix ("Failed asserting that 1 is identical to 0."), pass after |
| S-142.2 | `UploadEncodeBuilderTest::test_S_137_1…`, `EncodeOrderNodeTest::test_S_132_1…` | pass |

## Runs
- Baseline `tests/Feature/Chatblast tests/Feature/OrderEncode`: OK, 1165 tests, 1 skipped, 56 PHPUnit deprecations.
- After: OK, 1166 tests, 6593 assertions, 1 skipped, 56 PHPUnit deprecations. No new failures.
- `php -l` clean on both changed test files. No JS asset change, so no `npm run build`.

## Story changes
- Added S-142 (spec brought no stories; two cases straight from its "Done when").

## Notes
- No browser check run (tiny job); the render test proves the button is absent from the HTML.
- Review: low risk (one-line view removal), handled by the main session's test run per the tier table.

142: done — the legacy encode_order button is gone from the Add bar; one "Encode order" button remains and legacy nodes still work.
