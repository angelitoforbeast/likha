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

## Amendment 142-1

| Change | Who decided, why |
|---|---|
| Chatbot sequences: "Exit when the customer replies" (`chatbot_sequences.exit_on_reply`) is **off by default** for new sequences (column default false via a new additive migration that changes the default, and any create path / form default that sets true), and **every existing sequence is set to off** (the same migration updates existing rows to false; its down() restores the old default only, never the old row values). | The owner, 2026-10-25: "uncheck by default mo yan, tapos lahat ng existing sequence, nakauncheck dapat". Today Likha has 3 sequences (1 on) and Incepxion 2 (1 on). |

Tests: a new sequence is created with exit_on_reply false; the migration sets existing rows to false; the edit form shows it unchecked for a new sequence and still saves a checked value if someone checks it. Re-run `tests/Feature/Chatblast tests/Feature/OrderEncode` (no new failures). Update the result (Files, Evidence) and end with "142-1: <done|blocked> — <one line>".

### Files (142-1)
- `database/migrations/2026_11_07_100000_turn_off_exit_on_reply_on_chatbot_sequences.php` (new): column default `exit_on_reply` → false, then every existing row set to false. Guarded with `Schema::hasTable`/`hasColumn`; plain Schema `change()` and a query-builder update, so it runs the same on MySQL, PostgreSQL and SQLite. `down()` puts the default back to true only; the old row values are not restored.
- No create path or form set true: `ChatbotSequenceController::store` relies on the column default, and the edit form's checkbox is `@checked($sequence->exit_on_reply)`, so both follow the new default without a code change.
- `tests/Feature/Chatblast/ChatbotSequencePagesTest.php`: new `S-142.3`, `S-142.4`; the new migration added to `setUp`'s migrate list; `S-070.1` no longer asserts that a new sequence has exit on reply on (that was the old default this amendment changes); it still asserts `active` is on.
- `qa/stories.md`: S-142.3, S-142.4.

### Evidence (142-1)
| Case | Test | Result |
|---|---|---|
| S-142.3 | `ChatbotSequencePagesTest::test_S_142_3_a_new_sequence_has_exit_on_reply_off_and_a_checked_box_still_saves` | red with a no-op migration ("Failed asserting that true is false."), pass after |
| S-142.4 | `ChatbotSequencePagesTest::test_S_142_4_the_migration_turns_exit_on_reply_off_on_every_existing_sequence` | red with a no-op migration ("Failed asserting that 1 is identical to 0."), pass after |

- `tests/Feature/Chatblast tests/Feature/OrderEncode` after: OK, 1168 tests, 6603 assertions, 1 skipped, 56 PHPUnit deprecations (same skip and deprecations as the baseline; no new failures).
- `php -l` clean on the migration and the test file.

### Notes (142-1)
- Data change on deploy: the migration turns off exit on reply on every sequence (Likha 1, Incepxion 1 today) and cannot undo that; `php artisan migrate --force` by hand as usual.
- Policy conflict: the kit keeps amendments out of commits; this amendment is saved here because the amendment itself asks for it. It names no person.

142-1: done — new sequences start with "Exit when the customer replies" off, and the migration turns it off on every existing sequence.
