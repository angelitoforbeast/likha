# Result 078: sequences send at any hour and always skip outside 24 hours

Branch `fix/078-sequence-simplify`, cut from `main` at `ffa70ba7`. Nothing was pushed.

## Commits

| Commit | What |
|---|---|
| `5878c4ef` | docs: the brief (verbatim) |
| `8bd0117b` | fix: no send hours, always skip outside 24 hours, the editor and list text, the tests |
| (this commit) | docs: result, stories |

## What changed

- **`ChatbotSequences::dueAt($from, $amount, $unit)`** is now `$from` + the delay, at any hour (Manila, to the minute, as before). The window logic and its two parameters are gone.
  - Subscribing uses the subscribe time; advancing uses the time the step ran or was skipped.
- **`runStep`:** when the customer's last message is 24 hours old or more, the step is always skipped with the event "Sequence … step N skipped (outside 24h)", and the sequence continues. The `stop` branch is removed.
  - Old "… stopped (outside 24h)" events already in the log still read the same.
- **`ChatbotSequenceController::update`** no longer validates or saves `send_from`, `send_to` or `outside_window`. If a client still sends them, they are ignored. The `TIME` pattern they used was removed.
- **`ChatbotSequence`:** the three columns are no longer fillable, and a comment says they are kept in the table but unused. No migration.
- **Editor (`sequence-edit.blade.php`):** the Send from / Send until fields and the "Outside the 24-hour window" dropdown are gone, and so are their fields in the save request. The help text reads "… Each step runs at its delay. A step is skipped when the customer's last message is over 24 hours old (Messenger's rule)."
- **List (`sequences.blade.php`):** the intro no longer mentions send hours. It says a step runs at its delay and is skipped outside 24 hours. The list had no time columns.

## Tests

| Case | Test | Result |
|---|---|---|
| S-078.1 | `test_S_078_1_a_step_due_at_2am_is_sent_at_2am`: subscribed at 01:00, 1-hour delay, old 08:00–21:00 hours still in the table → due and sent at 02:00, next step 02:30 | ✔ pass |
| S-078.2 | `test_S_078_2_outside_24_hours_the_step_is_always_skipped`: two sequences, one with the old column set to "stop"; both skip step 1 and schedule step 2; nothing is sent | ✔ pass |
| S-078.3 | `test_S_070_2_…` (adjusted): no time fields or dropdown in the editor, the new help text, the list text; saves without the three fields | ✔ pass |
| S-070.1 | adjusted: a new sequence's defaults no longer check the three columns | ✔ pass |

**Removed or adjusted:**
- the 11-row window table, `test_S_070_4c_…`, replaced by S-078.1;
- `test_S_070_5a_…` (skip or stop), replaced by S-078.2;
- the bad-input cases for a bad time and an unknown outside-window value, dropped from S-070.2.

Red runs, before the fix:
- S-078.1: `Failed asserting that two strings are identical.` (the step was due at 08:00).
- S-078.2: `Failed asserting that two arrays are identical.` (the "stop" sequence unsubscribed).
- S-070.2: the page still showed "Send from".

`vendor/bin/phpunit tests/Feature/Chatblast`: 408 tests, 2715 assertions, all pass (PHPUnit deprecations only). I used `vendor/bin/phpunit` rather than `php artisan test`: this worktree has no environment file, and one must not be created.

## Story changes

- S-070.2: no send hours or outside-window rule.
- S-070.4c and S-070.5a are marked as replaced.
- New section S-078 (S-078.1 to .3).

## Notes

- **Existing subscriptions keep the `next_send_at` already stored.** A step that the old window pushed to 08:00 still runs at 08:00. Every step after it is scheduled at its delay. No data change was needed.
- **No browser check:** the brief didn't ask for one. The editor change only removes fields, and the save request was checked in the tests.
