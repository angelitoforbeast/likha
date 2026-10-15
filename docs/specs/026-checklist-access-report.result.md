# Result: brief 026 The staff checklist gets proper access rules and a multi-day report

> Committed beside the spec: names no people and no decision ids.

**Status:** done (not seen in a browser: the browser check is open, listed below)
**Date:** 2026-10-15
**Branch / PR:** `feat/026-checklist-access-report` (no PR, no push; the reviewer reviews the branch)

## Part 1: access rules

The routes keep `web` + `auth`. The rules are private helpers at the bottom of `ChecklistController` (one place):
`checklistRole()` (role trimmed and uppercased), `isCeo()`, `isOic()`, `isOicOf($department)` (department trimmed and
lowercased; a blank department matches nothing), `authorizeEditTask()`, `authorizeManageTasks()`,
`authorizeReviewSubmission()` and `refuse()`. Every CEO check in the controller (`report()`, `manage()`,
`deleteSubmission()`, `deleteFile()`) now uses `isCeo()`; `manage()` uses `isOic()` too, so its listing is the same
except that a role such as " ceo " or "ops - oic" now counts.

| Route | Who may | Refused answer |
| --- | --- | --- |
| GET `/checklist` | everyone (unchanged) | — |
| GET `/checklist/report` (day view and `?view=range`) | everyone, scoped as today (CEO all, others their assigned tasks) | — |
| GET `/checklist/manage` | everyone, listing unchanged | — |
| POST `/checklist/{task}/submit` | unchanged (assignees, or anyone on an unassigned task) | unchanged |
| DELETE `/checklist/submission/{submission}`, `/checklist/files/{file}` | owner of the submission or CEO (unchanged rule, one CEO comparison) | 403 |
| POST `/checklist/tasks` (create) | CEO, any OIC | 403 page |
| PATCH `/checklist/tasks/{task}`, DELETE `/checklist/tasks/{task}`, POST `/checklist/tasks/{task}/duplicate` | CEO, OIC of the task's department, the task's creator | 403 page |
| POST `/checklist/tasks/reorder` | CEO, OIC when every id in `order` is a task of their department | 403 JSON |
| POST `/checklist/tasks/bulk-assign`, `/bulk-type`, `/bulk-submission-type` | CEO, OIC when every `task_ids` task is of their department (one foreign id refuses the whole request) | 403 page |
| POST `/checklist/submission/{s}/analyze`, `/approval-check`; GET `/analysis-logs`, `/approval-logs` | CEO, the submission's owner, OIC of the department of the submission's task (soft-deleted tasks too) | 403 JSON |

JSON refusals are `{"ok":false,"message":"…"}`; a form route answers JSON too when the request asks for JSON. A refusal
happens before any write and before any OpenAI call. `bulk-submission-type` was not named in the brief; it gets the
same rule as bulk-type (it is a bulk change of tasks).

## Part 2: "Over several days"

`/checklist/report?view=range&from=…&to=…&dept=…`, linked as "Over several days" beside the date in the day view's
header; the one-day view is unchanged and stays the default. New view `resources/views/checklist/report-range.blade.php`.

- **Range:** default the last 14 days ending today; `to` is capped at today; `from` after `to` becomes `to`; more than
  62 days keeps the 62 days ending at `to`. Bad dates fall back to the defaults.
- **Visibility:** as `report()`: CEO all tasks; others only tasks assigned to them. **Department filter:** a select of
  the visible tasks' departments, matched case-insensitively (as the day view's filter).
- **Due rules:** active tasks only; created on or before the day; not deleted by the end of the day (as the day view's
  past-date rule); daily every day, weekly on `frequency_day` (0 = Sunday), monthly on that day of the month, once
  until and including the day of its first submission (then not due).
- **Per day:** tasks due, due tasks with a submission, completion %, and the latest approval verdict of each
  submission that day: approved / not approved / unknown (a submission with no approval run counts in none).
- **Per task:** one small cell per day (green done, red missing, grey not due), then completion % (due days with a
  submission ÷ due days) and not-approved % (latest verdict not approved ÷ its submissions). Weakest first; tasks with
  no due day in the range at the end; tasks never due and never submitted in the range are left out. A submission on
  a day the task was not due shows as done but is not counted in the percentages. The grid scrolls inside its own box;
  the task name column is sticky on the left and the two % columns are sticky on the right.
- **Per person:** every user assigned to at least one visible task: submissions in the range, approved / not approved
  (latest verdict), days with a due task, missed days (days with a due task and zero submissions). Most missed first.
- **CSV:** "CSV per task" (task, department, one column per day: done / missing / blank, then due days, done,
  completion %, submissions, not approved, not approved %) and "CSV per person". Text from users that starts with
  `= + - @` gets a leading `'` so a spreadsheet does not run it as a formula.
- **Queries:** the report itself runs 4 queries for any range and any number of tasks or people: tasks, their
  assigned users, the first submission date of the `once` tasks (only when there are any), and one GROUP BY over
  (date, task, user) with the latest verdict picked by a correlated subquery. The whole page measured 7 in the test
  (plus the employee profile and the layout's two nav counts); on the live site add the logged-in user (and the
  session row if sessions are in the database). A test pins that the count is the same for 3 days × 1 task as for
  62 days × 6 tasks × 7 people.

## Tests

`tests/Feature/Checklist/` (new; `ChecklistTestCase` builds the checklist tables in their final shape because one
migration is a MySQL-only ALTER; OpenAI is `Http::fake()`d with stray requests blocked). 95 tests, 294 assertions, all
pass.

| Test | Result |
| --- | --- |
| Access matrix: 12 routes × 6 actors (CEO, OIC same department, OIC other department, creator, plain staff, plain staff on own submission), allowed → 200/302 with the change made (or `logs` JSON), refused → 403 with nothing changed and no HTTP call (JSON `ok:false` on the AJAX routes) | 72 pass |
| Bulk change by an OIC refused whole when one task is another department's | pass |
| OIC department match: "ops " vs "Ops" allowed; blank OIC department vs NULL or empty task department refused | 3 pass |
| Deleting someone else's submission: "CEO" and " ceo " allowed, plain staff 403 | 3 pass |
| /checklist, /checklist/report, /checklist/manage stay open to plain staff | pass |
| Day cells follow the due rules: daily, weekly on its day, monthly, once, created mid-range, deleted mid-range, inactive | 7 pass |
| Only the latest approval verdict of each submission counts (per day, per task %, per person) | pass |
| A non-CEO sees only tasks assigned to them, their people, and no other task's submissions in the counts | pass |
| The department filter keeps only that department's tasks | pass |
| Tasks sort weakest first, people most missed first | pass |
| CSV header and one row, per task and per person (with the formula guard) | pass |
| Range default 14 days, capped at 62 | pass |
| Query count does not grow with days, tasks or people | pass |
| The day view stays the default and links to the range view | pass |

Red runs: Part 1, 43 refused cases failed before the change (for example `update-task as staff`: expected 403,
got 302); Part 2, every range test failed with `Undefined array key "taskRows"` (the view did not exist).

**Full suite:** 1005 tests, 1 failure and 1 error, both known: `ExampleTest` (302 instead of 200) and `ImportStartTest`
(`storage/app/credentials.json` does not exist in a fresh worktree). No other failures. `php -l` clean on the
controller and the tests.

## Review

An adversarial review of Part 1 and a standard review of Part 2 found no way past the new rules (bulk ids, reorder ids,
soft-deleted ids, nested arrays, route binding) and no SQL problem for MySQL or pgsql. Fixed from it: the access tests'
allowed side now checks that the change happened, plus the department-match, monthly and CSV formula-guard cases.
Accepted with reasons in `TODO.md`, including one serious problem outside this brief:

- **`/assign-roles` has only the `web` middleware**: anyone can give any user the CEO role, which undoes these rules.
  It needs its own brief, with priority.

## Decisions made here

- The rules sit as private helpers in the controller, like the project's other per-controller role checks
  (`getNormalizedRole()`), not as a Laravel policy (the project has none).
- The range view is a query parameter on the same route, so `routes/web.php` is unchanged and the new view inherits
  the group's middleware.
- Due rules: `is_active` applies to every day (there is no history of it); `once` is due until and including the day
  of its first submission.

## For the owner, after release

1. As CEO, open **Checklist → Report**. Beside the date at the top there is **Over several days**; click it.
2. Check that the range shows the last 14 days, then pick a From date three months back and click **Show**: the header
   says "62 days · max 62".
3. Pick a department in **All depts** and click **Show**: only that department's tasks remain.
4. Scroll the **Per task** grid sideways: the task names stay on the left and the Done / Not appr. columns on the right;
   the page itself should not scroll sideways on a laptop.
5. Click **CSV per task** and **CSV per person** and open both files.
6. Click a date in **Per day**: it opens that day in the one-day view.
7. Ask a staff member (not OIC) to open /checklist/manage and try to edit a task they did not create: they get a 403
   page. Their daily checklist and submitting work as before.
8. Ask an OIC to drag a task on /checklist/manage, and to run "Analyze" on a submission of their department in the
   report: both work. On another department's submission, the panel says "Server error (403)".

## Story changes

None (fast mode, no stories file for this brief).

## Conflicts

None between the brief and the project's instructions.
