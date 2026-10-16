# Result 033: staff see the AI check result, and OICs see who has not submitted

Branch `feat/033-checklist-verdict-oic`, cut from `develop` at 7934aa2.

## Commits

| Commit | What |
|---|---|
| c0a4a60 | docs: brief of spec 033 |
| 8d642bc | feat: staff see the AI check verdict on the daily checklist (Part A) |
| baea100 | feat: OICs see their department's checklist work and who has not submitted individual tasks (Part B) |
| (this) | docs: result of spec 033 |

## Part A: what staff see on `/checklist`

The page loads the latest approval log (`latestApproval`, `log_type = approval`, newest `created_at`) of each of today's submissions (the user's own for individual tasks, the task's one for group tasks). Each task gets one state:

| State | When | Dot | Status column |
|---|---|---|---|
| approved | submitted, photo count met, latest verdict `approved` | green | green "✓ APPROVED" |
| needs fix | latest verdict `not_approved` | red | red "✗ Kailangang ayusin" plus the first 300 characters of the latest approval text, escaped, e.g. "NOT APPROVED / Walang screenshot ng bank balance." |
| needs fix (photos) | submitted, verdict not NOT APPROVED, too few photos | orange | orange "IN PROGRESS" plus the existing "needs N more" chip (as before) |
| checking | submitted, no approval log yet (check failed or still running) or verdict `unknown` | grey | grey "Sinusuri pa" |
| not submitted | no submission | amber | grey "PENDING" (as before) |

Header: the bar and `N/M` now count approved only; beside it "N approved · N kailangang ayusin · N hindi pa naisumite" (plus "· N sinusuri pa" when any). "🎉 All tasks completed for today!" shows only when every due task is approved. Sort: needs fix and not submitted first, then checking, then approved; timed tasks first within each group, as before. Edit and resubmit are unchanged; a resubmit reruns the check, and the new log becomes the latest. Nothing changed in how the check runs or what it costs.

## Part B

### Report scope as built

One helper, `scopeReportTasks()`, used by `report()` (the task list and the past-date safety net), `rangeReport()` (the page, the `tasks`, `people` and `values` CSVs):

- CEO: every task.
- OIC (role contains "OIC"): tasks whose department equals the OIC's department (trimmed, case-insensitive; profile department, else the role text before "- OIC", as in 026), OR `created_by` = the OIC, OR the OIC is an assignee. An OIC with no department gets created + assigned only.
- Everyone else: tasks they are assigned to (unchanged).

Submissions in the one-day report follow the visible tasks, as before. The routes the report page calls for a submission (analyze, approval check, both log lists) use the same rule for OICs: `authorizeReviewSubmission()` now allows an OIC who would see the task in the report (department, creator or assignee). CEO and the submission's owner are unchanged; other staff are unchanged.

### Manage

An OIC with a department sees tasks of that department plus tasks they created (any department, blank included). Edit rights unchanged from 026 (CEO all; OIC own department; creator own tasks), so a created task is editable and gets its History link.

### Individual mode: how it stores submissions

- Index: `checklist_submissions` unique (`checklist_task_id`, `user_id`, `date`), set by migration `2026_04_12_000003_add_submission_type_and_update_unique.php`, which adds that index and drops the old (`checklist_task_id`, `date`) one if it is still there. That index allows one row per assignee per day.
- `submit()` already writes individual tasks with `updateOrCreate([task, user, date])` and group tasks with `updateOrCreate([task, date])`; `/checklist` already reads the user's own submission for individual tasks. The new test (two assignees, same day, separate verdicts) passed without a code change: nothing in the code stopped individual mode. I could not check the live database's indexes (no remote hosts here). Before switching a task to individual, run `SHOW INDEX FROM checklist_submissions;` on production: it must list `checklist_submissions_checklist_task_id_user_id_date_unique` and must not list `checklist_submissions_checklist_task_id_date_unique`. If the old one is still there, the second assignee's save fails with a duplicate-key error.
- One-day report: already showed, for individual tasks, each submitter with the verdict and "Name — not yet submitted" for the rest, and for group tasks "submitted by" with the saved verdict in the Approval Check column. With the OIC scope, an OIC now sees these lines for their department's tasks. No view change was needed.
- Several-days report: per person, "missed" is now the union of (a) days an individual task they are assigned to was due and they did not submit it, and (b) the old team rule for group tasks (a due group task nobody did, and the person submitted nothing that day). Under each individual task, a red line per day lists who did not submit (e.g. "Oct 14: " followed by the names). The legend text under the per-person table says so. Report counts (due, done, %, approved/not approved) are unchanged.

## Tests (case table)

| Case | Test | Result |
|---|---|---|
| A: approved | `ChecklistVerdictTest::test_approved_submission_shows_approved` | pass |
| A: not approved, reason escaped, cut at 300 | `…::test_not_approved_submission_shows_needs_fix_with_the_escaped_reason_cut_at_300` | pass |
| A: no verdict (no log, `unknown`) | `…::test_submission_without_a_verdict_shows_still_checking` | pass |
| A: counts split, "all completed" only when all approved | `…::test_counts_split_and_all_completed_only_when_every_task_is_approved` | pass |
| A: sort | `…::test_needs_fix_and_not_submitted_sort_before_checking_and_approved` | pass |
| B: two assignees, same day, own verdicts | `ChecklistOicScopeTest::test_two_assignees_each_submit_an_individual_task_with_their_own_verdict` | pass |
| B: not submitted listed in one-day and several-days reports; missed days | `…::test_an_assignee_who_did_not_submit_an_individual_task_is_listed_in_both_reports` | pass |
| B: scope per role (OIC dept / not other dept / created / assigned; staff unchanged; CEO all) in day view, range view and CSV | `…::test_report_scope_per_role_in_the_day_and_range_views_and_the_csv` | pass |
| B: review routes follow the OIC scope | `…::test_an_oic_may_check_submissions_of_a_task_they_created_for_another_department` | pass |
| B: Manage list for OIC | `…::test_manage_lists_the_oic_department_plus_tasks_they_created` | pass |

Red runs: Part A, all 5 failed on the missing behaviour (`Undefined array key "counts"`, missing "APPROVED"/"Kailangang ayusin"/"Sinusuri pa" text, wrong order). Part B: scope, review route, Manage and the reports test failed on the missing behaviour (OIC day view list, 403 instead of 200, missing names). The two-assignees test failed at first only because sqlite stores the date cast with a time; after normalising the stored date in the test (as the base helper does) it passed with no code change, which shows individual storage already worked.

Suite (`php artisan test`): 141 passed, 2 failed: `Tests\Feature\ExampleTest` and `Tests\Feature\NightRun\ImportStartTest` (both known). The Checklist folder: all green. The warnings count is the existing deprecation output, also present before this change. `php -l` clean on the controller.

## Story changes

The spec brought no stories and `qa/stories.md` was not changed; in fast mode the cases above come straight from the spec's Tests section.

## For the owner after release (no browser here)

1. As a staff user, open `/checklist`: a task whose check said NOT APPROVED shows red "Kailangang ayusin" with the reason and sits at the top; the header shows the approved / kailangang ayusin / hindi pa naisumite counts. Edit and resubmit it: after the check it turns green "APPROVED".
2. As a Marketing OIC (not assigned to a Marketing task), open `/checklist/report`: the Marketing tasks are listed; an Encoder task this OIC created is listed too; other Encoder tasks are not. Do the same in "Over several days" and download the Tasks CSV.
3. As that OIC, open `/checklist/manage`: the Encoder task they created is listed and editable.
4. Before setting any task to individual: run the `SHOW INDEX` check above. Then switch one test task to individual in Manage, have two assignees submit, and check the one-day report (each name with a verdict, the others "not yet submitted") and the several-days per-person "missed" column.
5. Run `npm run build` on deploy (new Tailwind classes in the Blade views; `public/build` is not committed).

## What the brief got wrong or left open

- "fix whatever stops it" (individual mode): nothing in the code stopped it; the only risk is a production index left over from the 2026-04-11 migrations, checked by the query above.
- "add the verdict split where it is missing" in the one-day report: it was already there (Approved / Not Approved chips and a verdict per submission row), so nothing was added.
- Not stated by the brief, decided here: a submission with an approved verdict but too few photos counts as "kailangang ayusin" (it keeps its orange IN PROGRESS badge); a verdict of `unknown` shows as "Sinusuri pa". The review routes (analyze, approval check, logs) for an OIC now follow the report scope, so an OIC can rerun the check, which costs one AI call, on tasks they created or are assigned to in another department.
- Process: fast mode, so no separate plan gate or skeptic review ran in this session; the review routes change is an auth change and is worth the reviewer's look.
