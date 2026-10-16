# Brief 038: Marketing and Marketing OICs can edit the checklist tasks they are assigned to or created

## Why

In `ChecklistController` (specs 026, 028, 033 in `docs/specs/`), editing a task (`canEditTask`, about line 1252: `canManageTask` = CEO or OIC of the task's department, or the creator) leaves most live tasks uneditable for the Marketing team: 18+ tasks have a blank department and no creator, others are "Marketing Assistant", so a Marketing - OIC who did not create them cannot fix a wrong prompt, and a plain Marketing user can edit nothing. Staff report a task whose approval prompt they need to correct and cannot.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| A user whose role is `Marketing` or `Marketing - OIC` (same normalisation as the existing role helpers; any dash before OIC) may edit a task when they are one of its assignees or its creator | Owner, 2026-10-16: "maeedit dapat ng marketing at marketing oic yung task na nandun sila, or sila nagcreate" |
| "Edit" means what `canEditTask` gates today for a creator: the edit form and `updateTask` (all fields, including the prompts and required photos), the History page, and the edit buttons in Manage | Same |
| Deleting a task (`destroyTask`) and the bulk actions (`authorizeManageTasks`: reorder, bulk assign/type/submission type) keep today's rules; creating tasks (`storeTask`) unchanged | Not asked; deleting is not editing |
| All existing rights stay (CEO all; OIC own department; creator). Other roles unchanged | Not asked |
| Every edit by these users is recorded the same way as other edits (`checklist_task_changes`, if `updateTask` writes it today; if it does not, say so in the result, do not add it) | Traceability |

## Do

- Extend `canEditTask` (or add a sibling used in the same places) with the assignee rule above; use the existing assignee relation/table. Make sure Manage lists the tasks such a user can edit (today non-OIC users see tasks they created or are assigned to; an OIC sees department + created: add assigned for Marketing - OIC) and shows the edit button for them.
- Do not change `destroyTask` or `authorizeManageTasks`.

## Tests

Plain Marketing assigned to a blank-department task with no creator: sees it in Manage with an edit button, can update its approval prompt (200, value saved), can open History, cannot delete it (same response as today for non-editors of delete). Marketing - OIC assigned to a "Marketing Assistant" task they did not create: can edit. Marketing user not assigned and not creator: cannot edit (same refusal as today). A Data Encoder assigned to a task: unchanged (cannot edit unless today's rules allow). CEO and OIC-of-department unchanged. Red first. The whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Final message

Commits; the rule as built with file:line; whether edits are recorded in `checklist_task_changes`; test lines and suite result; anything the brief got wrong.
