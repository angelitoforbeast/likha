# Brief 033: staff see the AI check result, and OICs see who has not submitted

## Why

The staff checklist (`ChecklistController`, views `resources/views/checklist/*`; specs 026 and 028 in `docs/specs/`, read them first) runs an AI approval check on every save (`checklist_analysis_logs`: verdict APPROVED / NOT APPROVED). A read-only study at d6352b4 found: (1) staff never see the verdict or its reason on `/checklist`; a NOT APPROVED submission still shows DONE, the progress bar and "all tasks completed" count it as done, so nobody knows to redo it (`index.blade.php` has no verdict; `doneCount` CC:109-113). (2) `report()` and `rangeReport()` scope every non-CEO to tasks they are assigned to (CC:164-169, 289-292), so an OIC sees staff work only if also an assignee; the per-person "not yet submitted" list exists only for individual tasks (`report.blade.php` ~253, 967-977) and every live task is a group task, because the individual mode has never been used; tasks an OIC creates for another department or with a blank department disappear from that OIC's Manage list (CC:542-550). Live data: 59 active tasks, all `submission_type = group`; about 50 submissions a day; about 56% approved.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| A NOT APPROVED submission is shown to staff as needing a fix (red, with the AI's reason), not as done; staff can fix and resubmit with the existing Edit flow, which reruns the check | Staff must know what to redo |
| The counts staff see split into approved / needs fix / not submitted; "all tasks completed" only when every due task is approved | Same |
| Reports (one day and several days) keep their current numbers; add the verdict split where it is missing, do not redefine "done" there | The owner's daily numbers read the report |
| An OIC sees in both reports every task of their department (as decided in 026: department from the profile, else from the role text) plus tasks they created or are assigned to; CEO sees all; other staff unchanged | OICs must follow their people's work |
| Task type stays per task (`submission_type` group / individual) as today; this brief makes individual mode work end to end and does not switch any live task | The owner will choose which tasks are individual after release |
| A task an OIC created stays visible and editable for that OIC in Manage, whatever its department | Owner, 2026-10-15: a Marketing OIC may create tasks for the Encoders |

## Part A: staff see the verdict (commit on its own)

- On `/checklist`, per task: the latest verdict of today's submission (APPROVED green; NOT APPROVED red "Kailangang ayusin" with the AI's short reason, the first 300 characters of the latest approval result, escaped; "Sinusuri pa" / no verdict when the check failed or has not run). Use the latest `checklist_analysis_logs` row of that submission.
- Status and counts as decided above; the dot/badge and sort follow the verdict (needs-fix tasks sort to the top with not-submitted ones).
- No change to how the check runs or what it costs.

## Part B: individual mode works, OICs see who has not submitted (commit on its own)

- Individual tasks: each assignee has their own submission per day (check the unique indexes left after earlier changes: a (task, user, date) unique index should allow it; fix whatever stops it), sees only their own submission and Edit, and their own verdict. Group tasks unchanged.
- Report scope for OICs as decided above, in `report()` and `rangeReport()`, with the same rule in any route the report pages call (exports/CSV included).
- One-day report: for individual tasks, under each task, who submitted (with verdict) and who has not yet; for group tasks keep "submitted by" and show the verdict.
- Several-days report: per person, missed days counted only for individual tasks they are assigned to (group tasks keep the current team rule), plus per task the not-submitted names per day for individual tasks.
- Manage: an OIC sees tasks of their department plus tasks they created; edit rights follow 026 (CEO all; OIC own department; creator own tasks).

## Tests

Part A: approved, not approved (with reason, escaped), no verdict; counts and "all completed"; sort. Part B: two assignees on an individual task both submit on the same day with separate verdicts; one submits, the other appears in "not yet submitted" in the one-day and several-days reports; a Marketing OIC sees a Marketing task they are not assigned to, does not see an Encoder task they did not create, and does see an Encoder task they created; staff scope unchanged; CEO sees all; exports follow the same scope. Red first where natural. The whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree); name any other.

## Out of scope

Switching any live task to individual; changing the AI prompts or the check; reminders or notifications; the weekly-task day bug; upload limits and compression.

## Final message

Commits; what staff now see (one example of each state); the report scope rule as built; how individual mode stores submissions (indexes); test lines and suite result; what the owner should click after release (no browser here); anything the brief got wrong.
