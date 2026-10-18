# Brief 066: submission type and who submitted, on each checklist item of the staff feed

## Why

An outside alarm reads `/api/staff-status` and posts late or not-approved checklist items to a group chat. For a group task (one submission counts for everyone assigned) it listed nine names for one submission (one submit at 8:01, not approved), because each person's item looks the same as an individual task. With the type and the submitter on each item, the alarm can print one line per group submission.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| Every item of `checklist_today` and `checklist_late_today` gets `submission_type`: `"group"` or `"individual"`, the value of `checklist_tasks.submission_type`, blank or null read as `"group"` (as the app already does in `ChecklistDueToday`) | The owner: "may mga group na assignment, individual, meron panglahatan diba?" ... "sige, isama mo na" |
| Every such item also gets `submitted_by`: the name of the user whose submission counts (group: the first submission of the day by anyone; individual: the person's own), the same name source the feed already uses for people; `null` when not submitted | So the alarm can say whose submission it is |
| Nothing else changes: the group rule (one submission counts for all), every existing field and its value, the order, and no photos, notes, prompts or AI text | Small, safe change |

## Cases

C1 A group task assigned to three people, submitted once by one of them: all three items show `submission_type: "group"`, `submitted: true`, the same `submitted_at` and the same `submitted_by` (the submitter's name), in both `checklist_today` and (once past the deadline) `checklist_late_today`.
C2 An individual task assigned to three people, submitted by one: that person's item shows `submission_type: "individual"`, `submitted: true`, `submitted_by` = their name; the other two show `submitted: false`, `submitted_at: null`, `submitted_by: null`.
C3 A task with blank `submission_type` is reported as `"group"`.
C4 The existing feed tests still pass unchanged except where they assert the exact key list of an item (add the two keys there).

## Done when

Tests for C1-C4 pass; the full suite adds no new failures against develop's known ones (`ExampleTest`, and `ImportStartTest` in a fresh worktree); `php.bat -l` on every changed PHP file; the result shows a sample item of each kind (fake data).

## Out of scope

Anything outside the two feed lists; the badge; the checklist pages; deploying.

## Final message

Commits; the two new fields as built with samples; test lines and suite result; anything the brief got wrong.
