# Result 038: Marketing and Marketing OICs can edit the checklist tasks they are assigned to or created

## What shipped

`app/Http/Controllers/ChecklistController.php`:

- `isMarketing()` (line 1266): the profile role, trimmed and upper-cased like the other role helpers, is `MARKETING` or `MARKETING - OIC` with any spacing around the dash (`/^MARKETING(\s*-\s*OIC)?$/`). "Marketing Assistant" and other roles do not match.
- `canEditTaskContent()` (line 1276): `canEditTask()` (CEO, OIC of the task's department, creator: unchanged) **or** a Marketing / Marketing - OIC user who is in the task's assignees (`checklist_task_users`). The creator part comes from `canEditTask`, so a Marketing user who created a task keeps editing it as before.
- Used in `updateTask` (line 850, same 403 refusal text as before), `history` (line 610), and Manage's `editableIds` (line 597, which shows the History link).
- Manage list (line 582): a Marketing - OIC now also sees tasks assigned to them. Plain Marketing already saw created + assigned. Other OICs unchanged.
- `destroyTask`, `duplicateTask` (still `authorizeEditTask` → `canEditTask`), `authorizeManageTasks` and `storeTask` unchanged.

## Edit log

Yes: `updateTask` already writes `checklist_task_changes` (`logTaskChange`, user = the editor). The first test checks one `updated` row by the Marketing user.

## Case table

| Case | Test | Result |
|---|---|---|
| Plain Marketing, assigned, blank department, no creator: in Manage with History link, update approval prompt saved, History 200, delete 403 and task kept, change logged | `ChecklistMarketingEditTest::test_a_marketing_user_assigned_to_a_blank_task_with_no_creator_edits_it_but_cannot_delete_it` | pass |
| Marketing - OIC assigned to a "Marketing Assistant" task not theirs: can edit | `test_who_may_edit_a_task_of_another_department` "Marketing - OIC assigned" | pass |
| Same with `marketing-oic` spelling | same, "Marketing-OIC no spaces" | pass |
| Marketing not assigned, not creator: 403, nothing saved | same, "Marketing not assigned" | pass |
| Marketing - OIC not assigned: 403 | same, "Marketing - OIC not assigned" | pass |
| Data Encoder assigned: 403 (unchanged) | same, "Data Encoder assigned" | pass |
| Marketing Assistant assigned: 403 (not a Marketing role) | same, "Marketing Assistant assigned" | pass |
| CEO: can edit (unchanged) | same, "CEO not assigned" | pass |
| OIC of the task's department: can edit (unchanged) | same, "OIC of the department" | pass |
| Marketing - OIC Manage list includes assigned tasks | `ChecklistOicScopeTest::test_manage_lists_the_oic_department_plus_tasks_they_created_and_for_marketing_the_assigned` | pass |

Existing `ChecklistAccessTest` matrix (CEO, OIC, creator, staff on update/delete/duplicate/bulk) unchanged and green.

Red run: `ChecklistMarketingEditTest` 4 failed before the change (first: "Expected response status code [302] but received 403." for Marketing - OIC assigned; Manage page did not contain the History link).

Suite: `Tests: 2 failed, 1069 warnings, 141 passed (15709 assertions)`. The 2 failures are the known `ExampleTest` and `NightRun\ImportStartTest` (fresh worktree). Lint: `php -l` clean.

## Story changes

`ChecklistOicScopeTest` pinned that a Marketing - OIC did not see a blank-department task assigned to them in Manage. The brief changes that on purpose, so its expectation now includes "Walang dept, assigned" and the test name says so.

## Notes for the reviewer

- The Edit, Dup, Disable and Del buttons in Manage already show on every listed task (no gate in the view); only the History link uses `editableIds`. Assigned Marketing users now get a working Edit and Disable (Disable goes through `updateTask`); Dup and Del still answer 403 as they did before for non-editors. Hiding Dup/Del for them was not asked and was left alone.
- `updateTask` syncs `assigned_users` from the form, so a Marketing user who removes themselves from the assignees loses edit access to that task (expected, mentioned for awareness).
- "any dash before OIC" was read as a hyphen with any spacing, like `oicDepartment()`. En/em dashes are not matched, same as the existing helpers.
- No browser check (fast mode); no UI files changed.
