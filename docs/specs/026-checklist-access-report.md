# Brief 026: the staff checklist gets proper access rules and a multi-day report

The checklist (`routes/web.php` ~166-185, prefix `checklist`, middleware only `web` + `auth`; `app/Http/Controllers/ChecklistController.php`; views `resources/views/checklist/{index,report,manage}.blade.php`; models `ChecklistTask`, `ChecklistSubmission`, `ChecklistSubmissionFile`, `ChecklistSubmissionLog`, `ChecklistAnalysisLog`) is used every day: about 50 submissions a day by 7 people, 59 active tasks, an AI approval check on every save. Verify the line numbers yourself.

## Part 1: access rules (do this first, commit it on its own)

Today only `auth` guards the routes; the role checks in `manage()` and `report()` only filter what is listed. Any logged-in user can create, edit, delete, duplicate, reorder and bulk-change tasks by calling the routes, and can trigger the OpenAI analysis or approval check (and read its text) on any submission.

Rules to enforce on the server, for every route of the group (use one place, e.g. a policy or a small helper, not copies):
- **CEO** (the role on `employeeProfile->role`, compared trimmed and case-insensitive, the way `report()` does it; make every CEO check in this controller use that one comparison): everything.
- **OIC** (role contains "OIC", same normalisation): create tasks; edit, delete, duplicate, reorder, bulk-assign and bulk-type tasks of their own department; analyze / approval-check / read logs of submissions of their department's tasks.
- **Task creator** (`created_by`): edit, delete, duplicate their own tasks.
- **Everyone else**: open /checklist and submit for tasks they may submit to (unchanged), delete their own submission or file (unchanged), open /checklist/report (scoped as today), open /checklist/manage only to see what `manage()` lists today but get 403 on any write they are not allowed; analyze / approval-check / logs only for their own submissions.
- Refused writes answer 403 (JSON `{"ok":false,"message":"..."}` for AJAX, as the page expects; read how the page calls them). Nothing about submitting, the daily page or what each role sees changes.
- Write in RESULT which route gets which rule.

Tests (feature, sqlite as the repo does): for each write route and each analysis route, an allowed role passes and a refused one gets 403 (CEO, OIC same department, OIC other department, creator, plain staff, plain staff on own submission); the AI calls must be faked (no real OpenAI call in tests: use `Http::fake()` or the repo's existing way). Red first where natural.

## Part 2: a multi-day report

Add to `/checklist/report` a second view, "Over several days" (a tab or a link next to the date picker; keep the existing one-day view exactly as it is and the default):
- A date range (default the last 14 days, at most 62 days), the same department filter as the day view, and the same visibility rule as `report()` (CEO all; others only their assigned tasks).
- **Summary row per day**: tasks due (same due rules as the day view: frequency, frequency_day, once, created on or before the day, not deleted by then, active), tasks with a submission, completion %, approval checks approved / not approved / unknown (the latest approval verdict per submission, not every re-run).
- **Per task**: a row per task with one small cell per day (done / missing / not due) and, at the end, completion % over the range and the not-approved % of its submissions. Sort by completion % ascending so the weakest tasks are on top.
- **Per person**: for each user assigned to at least one visible task, submissions in the range, how many of them were approved / not approved, and the days with zero submissions among the days they had a due task. Sort by most missed days first.
- **CSV download** of the per-task and per-person tables for the range.
- Queries must stay fast for 62 days × ~60 tasks: aggregate in SQL (group by date, task, user), no per-cell queries; say in RESULT how many queries the page runs.
- No horizontal page scroll at laptop width (1366 px): the per-task day grid scrolls inside its own box if 62 days do not fit, with the task name column sticky.

Tests: due-rule cases (daily, weekly on its day, once, a task created mid-range, a task deleted mid-range, inactive), the latest-verdict rule, the visibility rule for a non-CEO, the CSV's header and one row, and the 62-day cap.

## Done when

Both parts committed; the whole test suite passes except the known old failures (`ExampleTest`, and `ImportStartTest` needing `storage/app/credentials.json` in a fresh worktree; name any other); `php -l` clean on touched files. Final message: commits, route-to-rule table, what the multi-day view shows, the test lines, the query count, and exactly what the owner should click on the live site after release (no browser here).

## Out of scope

Moving the AI analysis to a queue, merging the three prompt copies, the MySQL-only migration, storing profit per page, any change to the daily /checklist page or to submitting.
