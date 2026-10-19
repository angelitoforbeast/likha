# Result 079: a checklist task is late 30 minutes after its scheduled time, not 15

Branch `fix/079-checklist-grace-30`, cut from `main` at `cc0bb19b`. Nothing was pushed.

## Commits

| Commit | What |
|---|---|
| `8cd551e7` | docs: the brief (verbatim) |
| `2e84804d` | fix: `GRACE_MINUTES` 30, the comment, the manage-page text, the tests, the stories |
| (this commit) | docs: result |

## Files changed

| File | Change |
|---|---|
| `app/Services/ChecklistDueToday.php` | `GRACE_MINUTES` = 30. The comment now says "scheduled_time + 30 minuto" and gives the example "11:50 PM + 30 = 12:20 AM". A task's own `deadline_time` is unchanged. |
| `resources/views/checklist/manage.blade.php` | The deadline field's tooltip, in both places (the new-task form and the edit row): "Blangko = oras + 30 minuto." |
| `tests/Unit/ChecklistDeadlineTest.php` | The grace data sets now expect + 30 (10:00 → 10:30, 23:50 → 00:20 the next day). New test `test_S_079_a_task_scheduled_at_1545_is_late_only_after_1615`. |
| `tests/Feature/Checklist/ChecklistBadgeTest.php` | S-051.16: the "due soon" task is now scheduled at 09:50, so its deadline stays 10:20. S-051.17: a comment now says 11:30. |
| `tests/Feature/StaffActivity/StaffFeedApiTest.php` | S-051.9 checks at 10:31 with deadline 10:30. S-051.10 checks at 10:29. S-051.31 expects deadlines 09:30, 10:30 and 12:30. In the past-date test, the same-day task is now scheduled at 23:20 (deadline 23:50): at 23:30 it would get a deadline of midnight, which counts as the next day. |
| `qa/stories.md` | S-051.5, .8, .9 and .10 use the new times. New section S-079. |

**Other screen text:** I searched the checklist views, the badge and the layouts. The only text that stated the 15-minute rule was the manage page's tooltip. The badge and the staff feed show the computed deadline, so they follow the change on their own.

## Tests

- `vendor/bin/phpunit --filter Checklist`: 221 tests, 788 assertions, all pass (PHPUnit deprecations only).
- `vendor/bin/phpunit tests/Feature/StaffActivity` (StaffFeedApiTest and the rest of the folder): OK, 63 tests, 234 assertions.
- I used `vendor/bin/phpunit` rather than `php artisan test`: this worktree has no environment file, and one must not be created.

New test: the 15:45 task has a deadline of 16:15. It is not late at 16:00, 16:10 or 16:15:00, and it is late at 16:15:01.

Red runs, before the fix:
- The five unit data sets and the new test failed, for example `Failed asserting that 615 is identical to 630.` and the deadline `'16:00'` instead of `'16:15'`.
- After the change, the badge and feed tests that pinned 15 indirectly failed. I adjusted them as listed above.

## Note

The 4PM reports (15:45) are now late from 16:15:01, so the alarm goes out at about 16:16. Tasks with their own `deadline_time` keep it.
