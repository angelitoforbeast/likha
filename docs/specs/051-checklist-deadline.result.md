# Result 051: checklist deadlines, late tasks in the staff feed, Checklist badge, re-check limit

Branch `feat/051-checklist-deadline`, from `develop` at 835d89ea. Plan: `docs/plans/051-checklist-deadline.md`.
Stories and case table: `qa/stories.md`, slice 051. Amendment 1 (re-check limit and lead override) came
during the work and was built as one more part.

## What shipped, per part

| Part | Commit | What |
|---|---|---|
| Spec, plan | ef193d6d | The spec saved verbatim; the plan |
| 1 Deadline field | 0550551a | `checklist_tasks.deadline_time` (nullable string after `scheduled_time`); validated `nullable\|date_format:H:i,H:i:s` on add and edit with the message "Ang Deadline ay dapat oras, hal. 10:30."; a "Deadline" time input next to the time in the add form and the edit form of `/checklist/manage`; "⏰ 10:30 AM" under the time in the table; logged in `checklist_task_changes` (label "Deadline", HH:MM vs HH:MM:SS is no change); copied by Duplicate |
| 2 One due-today source | 6c07e69a | `App\Services\ChecklistDueToday`: the effective deadline and the "due on a day / submitted" rules, moved unchanged out of `StaffFeedController::checklistDueNotSubmitted`, which now reads from it |
| 3 Feed | ff6cf4d3 | `/api/staff-status`: per person `checklist_late_today`, top-level `checklist_late_not_submitted` |
| 4 Badge | 413b5528 | `App\Services\ChecklistBadge` + layout + `x-navlink`: the badge on the Checklist link (desktop and mobile nav), and the team number for CEO and OICs |
| A1 Limit and override | 84f8b06a, ca8ef334 | Two approval checks per submission; past that only the CEO or the department OIC may check again or override (`POST /checklist/submission/{id}/approval-override`); `checklist_analysis_logs.is_override`; report page lock text, "Override" form; a per-submission lock against concurrent checks |
| A1 Feed | 681b28d8 | `rechecks`, `overridden`, `overridden_by`, `overridden_at` on each late item |
| Docs | fa10d806 | Stories, accepted findings in `TODO.md` |

### Migrations

- `2026_10_18_510000_add_deadline_time_to_checklist_tasks.php`: adds `deadline_time` (guarded with `hasColumn`); down drops it.
- `2026_10_18_510100_add_is_override_to_checklist_analysis_logs.php`: adds `is_override` boolean default false (guarded); down drops it.

Both run down and up (twice) on the test database: `ChecklistDeadlineFieldTest::test_the_deadline_and_override_migrations_run_down_and_up_again` → `OK (1 test, 4 assertions)`. Not run on MySQL here.

### The effective deadline as built

- The deadline field if it parses, else `scheduled_time` + 15 minutes, else no deadline. Both parse as `H:MM` or `H:MM:SS` (the `explode(':')` the checklist page uses, but a value that is not a time counts as not set instead of 00:00).
- Kept as minutes from midnight of the task's day. **Day boundary:** 24:00 or later (scheduled 11:50 PM → 12:05 AM) belongs to the next day, so the task is never late on its own day; it can still be "due soon" in the badge in the last 30 minutes before midnight.
- Late = now (Manila) is past the deadline: a 10:15 deadline is late from 10:15:01.
- Feed `?date=`: a past day → every deadline before midnight has passed; a future day → nothing late.

### The feed's new fields (sample, fake data)

```json
{
  "date": "2026-10-16",
  "generated_at": "2026-10-16T11:00:00+08:00",
  "staff": [
    {
      "user_id": 12, "name": "Staff 1", "...": "every existing field, unchanged",
      "checklist_due_not_submitted": ["Bilangin ang stock"],
      "checklist_approved_today": 0,
      "checklist_late_today": [
        {"task_id": 7, "title": "Bilangin ang stock", "deadline": "10:15", "submitted": false,
         "submitted_at": null, "verdict": null, "approval_checks": 0,
         "rechecks": 0, "overridden": false, "overridden_by": null, "overridden_at": null},
        {"task_id": 9, "title": "Linisin ang bodega", "deadline": "10:15", "submitted": true,
         "submitted_at": "10:20", "verdict": "approved", "approval_checks": 2,
         "rechecks": 1, "overridden": true, "overridden_by": "Lead 2", "overridden_at": "10:45"}
      ]
    }
  ],
  "totals": {"checklist_approved_today": 0, "status_changes": 0, "edited_orders": 0},
  "checklist_late_not_submitted": 1
}
```

- Order: by deadline, then task id. `submitted_at`, `verdict`, checks: from the submission that counts (group task: the first submission of the day by anyone; individual: the person's own).
- `verdict`: the latest approval row (an override included); anything other than `approved` / `not_approved` (e.g. `unknown`) is null.
- `approval_checks`: AI approval checks on that submission (override rows not counted); `rechecks` = checks after the first.
- `overridden_by` / `overridden_at`: the latest override's user name and HH:MM. The override reason is never in the feed.
- `checklist_late_not_submitted`: the number of (person, task) items with `submitted: false`.

### The badge

- For the signed-in user's own role (not "view as"): late + due within 30 minutes + no deadline and still open + own submissions dated today whose latest verdict is `not_approved`. Red when one is late, grey otherwise, hidden at 0. Hover (title): the non-zero parts, e.g. "1 late, 1 due soon, 1 open, 1 not approved".
- Second number (orange, title "N late sa team"): CEO = every active staff member (same inactive-status rule as the feed); OIC = active staff with the same department key. The viewer is left out of their own team number.
- **How OIC teams are found:** the checklist groups tasks by department, not users, so there is no user→department link in the checklist itself. A user's department key is the profile department (trimmed, lowercase), else the role without "- OIC" (the same fallback the checklist already uses to find an OIC's department). **Users with neither a department nor a role are left out of every OIC team** (the CEO's number still counts them).
- The layout finds the Checklist link by its URL (`/checklist`), because no migration seeds it and its key comes from nav settings.
- **Cost** (measured on the test database, sqlite, 30 staff and 60 tasks): cache miss = 3 data queries for staff (assigned tasks, today's submissions with latest verdict and check count as subselects, own not-approved), 5 for CEO / OIC (+ staff list, team tasks, team submissions; a `once` history query only when needed), plus 8 schema checks once per request (held on the app container); 2.6–4.7 ms. Cache hit (60 s per user, key `checklist_badge:{id}`): 0 queries, under 0.3 ms.
- **Fails quietly:** any exception → no badge, `Log::warning('Checklist badge failed: <class>')`, not cached, the page renders (`ChecklistBadgeTest::test_a_failing_badge_query…`).

### Amendment 1 as built (changes in neutral words)

- Re-check limits move into scope: the decision row "No re-check limit in this spec" and the out-of-scope line are replaced by the rules below.
- `approvalCheck` refuses with 403 "Naka-2 check na; OIC o CEO na lang ang pwedeng mag-check o mag-override." when the submission already has 2 approval-log rows (any author, the automatic check at submit and overrides included), unless the user is the CEO or an OIC whose department key equals the submitter's department key, or who is OIC of the task's department.
- For limited users a per-submission cache lock (`checklist-approval:{id}`, 60 s) is held while the check runs; a concurrent second check gets 409 "May approval check pang tumatakbo dito; hintayin munang matapos." with no AI call.
- `POST /checklist/submission/{id}/approval-override` (`checklist.approval-override`): CEO or department OIC only (403 otherwise); `verdict` approved/not_approved and `reason` (required, max 500) validated (422); writes an approval row with `is_override = true`, the user, the time, the verdict and the reason as its text; no AI call. It becomes the latest verdict.
- Approval logs JSON has `is_override` per row. The report page shows the limit text under the approval result and, for the CEO and the department OIC, an "Override" form (verdict, reason). When a submission is locked, the Analyze button reads "↻ Analysis lang" and runs only the analysis.
- The feed items gain `rechecks` and `overridden` (with `overridden_by`, `overridden_at`).

## Case table

| Case | Test | Result |
|---|---|---|
| S-051.1 | `ChecklistDeadlineFieldTest::test_S_051_1_a_ceo_sets_the_deadline_and_it_is_saved_shown_and_logged` | pass |
| S-051.2 | `ChecklistDeadlineFieldTest::test_S_051_2_a_marketing_user_without_edit_rights_cannot_set_it` | pass (passed before the change: the refusal already existed) |
| S-051.3 | `ChecklistDeadlineFieldTest::test_S_051_3_clearing_the_deadline_saves_null_and_logs_it` | pass |
| S-051.4 | `ChecklistDeadlineFieldTest::test_S_051_4_an_invalid_time_is_refused_with_the_forms_validation_message` | pass |
| S-051.5–8 | `ChecklistDeadlineTest` (data sets named S-051.5 … S-051.8, plus S-051.9/10 moments) | pass |
| S-051.9 | `StaffFeedApiTest::test_S_051_9_a_task_past_its_deadline_and_not_submitted_is_late_for_each_assigned_person` | pass |
| S-051.10 | `StaffFeedApiTest::test_S_051_10_before_the_deadline_the_task_is_not_late` | pass |
| S-051.11 | `StaffFeedApiTest::test_S_051_11_a_late_task_submitted_later_shows_when_and_its_latest_verdict_and_check_count` | pass |
| S-051.12 | `StaffFeedApiTest::test_S_051_12_an_inactive_task_or_one_not_due_today_never_appears` | pass |
| S-051.13 | `StaffFeedApiTest::test_S_051_13_a_group_task_submitted_by_one_person_is_submitted_for_everyone_assigned`, `…test_an_individual_task_submitted_only_by_another_person_is_not_submitted_for_this_person` | pass |
| S-051.14 | `StaffFeedApiTest::test_S_051_14_no_note_photo_path_prompt_or_ai_text_anywhere_in_the_feed` | pass |
| S-051.15 | `StaffFeedApiTest::test_S_051_15_the_existing_checklist_fields_keep_their_meaning_next_to_the_late_list`, all earlier feed tests | pass |
| S-051.16 | `ChecklistBadgeTest::test_S_051_16_one_late_task_and_one_due_in_20_minutes_is_2_red` | pass |
| S-051.17 | `ChecklistBadgeTest::test_S_051_17_only_one_due_in_20_minutes_is_1_neutral` | pass |
| S-051.18 | `ChecklistBadgeTest::test_S_051_18_a_not_approved_submission_today_counts_1` | pass |
| S-051.19 | `ChecklistBadgeTest::test_S_051_19_with_nothing_open_there_is_no_badge` | pass |
| S-051.20 | `ChecklistBadgeTest::test_S_051_20_the_badge_shows_on_a_checklist_page_and_on_any_other_page_with_the_layout` | pass |
| S-051.21 | `ChecklistBadgeTest::test_S_051_21_a_marketing_oic_sees_the_late_tasks_of_their_department_and_a_marketing_user_does_not` | pass |
| S-051.22 | `ChecklistBadgeTest::test_S_051_22_the_ceos_second_number_covers_all_staff` | pass |
| S-051.23 | `ChecklistBadgeTest::test_S_051_23_two_page_loads_within_60_seconds_run_the_badge_queries_once` | pass |
| — | `ChecklistBadgeTest::test_a_failing_badge_query_hides_the_badge_logs_the_class_and_keeps_the_page` | pass |
| S-051.24 (A1.1) | `ChecklistRecheckLimitTest::test_S_051_24_…`, `…test_a_limited_check_is_refused_while_another_check_of_the_same_submission_runs` | pass |
| S-051.25 (A1.2) | `ChecklistRecheckLimitTest::test_S_051_25_the_ceos_3rd_and_10th_checks_run` | pass (passed before the change: there was no limit) |
| S-051.26 (A1.3) | `ChecklistRecheckLimitTest::test_S_051_26_…` | pass |
| S-051.27 (A1.4) | `ChecklistRecheckLimitTest::test_S_051_27_…` | pass |
| S-051.28 (A1.5) | `ChecklistRecheckLimitTest::test_S_051_28_…` | pass |
| S-051.29 (A1.6) | `StaffFeedApiTest::test_S_051_29_the_late_list_shows_rechecks_and_an_override_with_who_and_when` | pass |

Red runs (one line each): part 1 `test_S_051_1…` "Failed asserting that null is identical to '10:30'"; part 2 `ChecklistDeadlineTest` "Class App\Services\ChecklistDueToday not found"; part 3 `test_S_051_10…` "Failed asserting that null is identical to Array &0 []"; part 4 `test_S_051_16…` "Failed asserting that null is identical to Array"; A1 `test_S_051_24…` "Failed asserting that 200 is identical to 403"; A1 feed `test_S_051_29…` "Failed asserting that two arrays are identical"; fix `test_a_limited_check_is_refused…` "Failed asserting that 200 is identical to 409".

## Suite

Full suite, `php.bat vendor/bin/phpunit`, on ca8ef334 (the last code commit):

```
ERRORS!
Tests: 1570, Assertions: 17964, Errors: 1, Failures: 1, PHPUnit Deprecations: 11, Skipped: 3.
1) Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active
1) Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response
```

Baseline on 835d89ea in this worktree: `Tests: 1522, Assertions: 17778, Errors: 1, Failures: 1` with the same two tests. These are the known failures on develop, so no new failures. Spec-area run: `tests/Feature/Checklist tests/Feature/StaffActivity tests/Unit/ChecklistDeadlineTest.php` → `OK (276 tests, 976 assertions)`.

`php.bat -l` on every changed PHP file: no syntax errors. No JS or CSS assets changed (Blade only; Tailwind comes from the CDN), so `npm run build` was not needed.

## Review

`skeptic-reviewer`, standard depth, on 835d89ea..681b28d8, then one fix loop on ca8ef334.

- **major (fixed, ca8ef334):** the limit was counted before a slow AI call with no lock, so parallel requests from one staff member could all pass it. Now a per-submission lock; a concurrent limited check gets 409. Re-check: closed, no new blocker or major.
- **minor (fixed):** the page test only asserted text inside an `x-show` element → the server now renders `data-check-locked` when locked, asserted both ways. **minor (fixed):** missing feed case for an individual task submitted only by the other person → added. **minor (fixed in part):** the Analyze button when locked → relabelled "↻ Analysis lang"; it stays enabled for the analysis (accepted).
- **Accepted in `TODO.md` with reasons:** an OIC can override their own submission; the department rule exists twice (`oicDepartment`, `departmentKey`); a deadline earlier than the scheduled time is taken as is; the lock needs a cache store with locks (production uses `database`, which has `cache_locks`); the automatic check at submit counts as check 1; the button stays enabled when locked; the race test holds the lock itself; MySQL and browser not tried here.
- Browser check: skipped; this session has no browser tools.

## Where the brief was wrong or open, and how it was handled

1. **"Reuse the /checklist/report logic"**: the day view of `/checklist/report` and the staff feed already had two versions of "due on a day", and they differ: the day view hides a `once` task from the day it is submitted, the feed (and the range view) count it as due through that day. The feed's version (equal to the range view's) is now the one source for the feed and the badge; the day view is untouched.
2. **"An OIC: the users of their own department as the checklist already groups them"**: the checklist groups tasks by department, not users. Built with the profile department, else the role (see "How OIC teams are found"); users with neither are left out.
3. **"A task with neither has no deadline … counted in the badge only while it is open today"** conflicts with the badge rule ("deadline passed or within 30 minutes"). Built as the decision row says: such tasks count as "open" in the badge while not submitted.
4. **"Keep every existing field unchanged"**: two existing tests asserted the exact key lists of a staff row and of the top level; they now list the new keys `checklist_late_today` and `checklist_late_not_submitted`. No existing value changed.
5. **The badge's own tasks are assigned tasks only.** `/checklist` also shows tasks with nobody assigned to everyone; the feed has always counted assigned tasks only, so the badge follows the feed.
6. **"Parse both the same way the app already parses scheduled_time"**: the app's `explode(':')` turns text that is not a time into 00:00; here such a value counts as not set, so it can't make a task late all day.
7. **No Checklist nav link in the migrations**: it lives in `nav_links` from nav settings, so the layout finds it by URL `/checklist`. If a role has no such link, it sees no badge.
8. **Amendment 1, "the first 2 run"**: submitting runs an approval check automatically, and the amendment counts every approval row, so after a submission a staff member has one manual check left. `ChecklistExtractFieldsTest::test_a_rerun_of_the_approval_check…` ran a staff member's 3rd check; its 3rd run is now the CEO's (the test is about the extracted values, not who runs it).
9. **Amendment 1, `rechecks`**: built as checks after the first (`approval_checks` − 1); `approval_checks` counts AI checks only, not overrides.

## Story changes

- S-051.13 gained the individual-task variant (only the person's own submission counts), from review.
- S-051.24 gained the concurrent-check case (409), from review.
- S-051.24–29 are the amendment's six cases, numbered after the spec's.

## Not done

Nothing from the spec or the amendment is left. Not pushed, no PR (the start prompt says never push), no deploy.
