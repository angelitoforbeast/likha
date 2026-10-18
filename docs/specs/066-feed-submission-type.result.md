# Result 066: submission type and who submitted, on each checklist item of the staff feed

## What shipped

- `ChecklistDueToday::rows()` adds `submission_type` (`"individual"` only when the stored value is exactly `individual`; blank, null or anything else is `"group"`, the same rule the row already used to pick the submission). The submission that counts now carries its `user_id`. Nothing else in the rows changed, and the badge (`ChecklistBadge`) reads only `submitted` and `deadline`, so it is unaffected.
- `StaffFeedController`: one lookup of the names of the users whose submissions count, from the same source the feed already uses for people (`employee_profiles.name`, else `users.name`, as in `/api/staff-events`). The lookup runs on `users` directly, so a submitter who is no longer on the staff list (for example, someone who resigned) still gets a name. Every item of `checklist_today` and `checklist_late_today` ends with `submission_type` and `submitted_by` (`null` when not submitted). Every other field, its value and the order are unchanged.

Commits: `f806b537 docs: spec 066 ...`, `f0d92051 feat: each staff feed checklist item says group or individual and whose submission counts ... (spec 066)`, and this result.

## Samples (fake data, real output)

Taken from a throwaway test, deleted after the run. It uses the C1/C2 setup: three people, a group task and an individual task, and B submits both. Now is 2026-10-16 09:00 Manila time.

`checklist_today` of A, who submitted nothing:

```json
{"task_id": 1, "title": "Group: linisin ang bodega", "scheduled_time": "08:00", "deadline": "08:15",
 "submitted": true, "submitted_at": "08:01", "verdict": "not_approved", "rechecks": 0,
 "submission_type": "group", "submitted_by": "Tagapagsumite B"}
{"task_id": 2, "title": "Individual: sariling ulat", "scheduled_time": "08:00", "deadline": "08:15",
 "submitted": false, "submitted_at": null, "verdict": null, "rechecks": 0,
 "submission_type": "individual", "submitted_by": null}
```

`checklist_late_today` of B:

```json
{"task_id": 1, "title": "Group: linisin ang bodega", "deadline": "08:15", "submitted": true, "submitted_at": "08:01",
 "verdict": "not_approved", "approval_checks": 1, "rechecks": 0, "overridden": false, "overridden_by": null,
 "overridden_at": null, "submission_type": "group", "submitted_by": "Tagapagsumite B"}
{"task_id": 2, "title": "Individual: sariling ulat", "deadline": "08:15", "submitted": true, "submitted_at": "08:05",
 "verdict": null, "approval_checks": 0, "rechecks": 0, "overridden": false, "overridden_by": null,
 "overridden_at": null, "submission_type": "individual", "submitted_by": "Tagapagsumite B"}
```

## Case table

All tests are in `tests/Feature/StaffActivity/StaffFeedApiTest.php`.

| Case | Test | Red (before code) | Now |
|---|---|---|---|
| C1 | `test_C1_a_group_task_submitted_once_shows_the_same_submitter_for_everyone_assigned` | `ErrorException: Undefined array key "submission_type"` | pass |
| C2 | `test_C2_an_individual_task_shows_only_the_persons_own_submission` | `ErrorException: Undefined array key "submission_type"` | pass |
| C3 | `test_C3_a_blank_submission_type_is_reported_as_group` | `Failed asserting that two arrays are identical.` | pass |
| C4 | `test_S_051_9_…`, `test_S_051_11_…`, `test_S_051_31_…` (exact item key lists, with the two keys added; S_051_31 also asserts the type and submitter of each item) | `Failed asserting that two arrays are identical.` (all three) | pass |
| C4 | every other test in the file, unchanged | n/a | pass |

C1 also proves the name source: the submitter's employee-profile name differs from their user name, and the feed shows the profile name. C1 has a second, later submission by another person, and the first submission still counts for all three people.

## Done-when evidence

Feed tests: `php.bat vendor/bin/phpunit -c phpunit.xml tests/Feature/StaffActivity/StaffFeedApiTest.php`

```
OK (43 tests, 163 assertions)
```

Full suite: `php.bat vendor/bin/phpunit -c phpunit.xml`

```
1) Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active
1) Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response
Tests: 1815, Assertions: 19580, Errors: 1, Failures: 1, PHPUnit Deprecations: 28, Skipped: 3.
```

The only failures are the two known ones on develop.

Lint: `php.bat -l` on each changed file

```
No syntax errors detected in .../app/Http/Controllers/StaffFeedController.php
No syntax errors detected in .../app/Services/ChecklistDueToday.php
No syntax errors detected in .../tests/Feature/StaffActivity/StaffFeedApiTest.php
```

## Review

`skeptic-reviewer` at standard depth found nothing. It raised one cosmetic minor: the new array keys are not column-aligned with their neighbours. Accepted: aligning them would mean re-indenting existing lines.

## Notes

- **C3 covers blank only.** The test schema's `submission_type` column is not nullable, so the test checks a blank value. Null takes the same path in the code (`?? 'group'`).
- **Submitter with no user row.** If the submitter's user row is gone, `submitted_by` is `null` while `submitted` is `true`. No case covers this.
- **What the brief got wrong.** Nothing found.
- **Story changes.** None. The brief brought its cases C1 to C4. No `qa/stories.md` entries were added, because the brief's cases are the stories for this small job.
- **Browser check.** Skipped: the change is API only, with no UI.
