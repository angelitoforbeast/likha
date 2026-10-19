Small task: spec 079, about 20 minutes.

**Branch.** In this worktree, run `git checkout -b fix/079-checklist-grace-30 main`. Main is cc0bb19b and is live.

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/079-checklist-grace-30.md` and the result as `docs/specs/079-checklist-grace-30.result.md`.

# Brief 079: a checklist task is late 30 minutes after its scheduled time, not 15

## Why

`App\Services\ChecklistDueToday` gives a task without its own `deadline_time` the deadline `scheduled_time + 15` minutes (`GRACE_MINUTES`). The 4PM reports are scheduled at 15:45, so the staff feed called them late at 16:00, and the team's alarm went out at 16:01. The owner, 2026-10-19: "oa naman yung sa deadline mo, adjust mo naman 15 mins yung nakaset na deadline", meaning 15 more minutes.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| `GRACE_MINUTES` = 30. A task with its own `deadline_time` is unchanged. | The owner wants 15 more minutes. |
| Update the comment, which today says "+ 15", and any screen text that states the 15-minute rule (search the checklist views, the manage page and the badge). | Keep the text true. |
| Update the tests that pin 15, and add one: a task scheduled at 15:45 is not late at 16:10 and is late at 16:15:01. | — |

## Done when

- `php artisan test --filter Checklist` passes, plus the StaffFeed tests (find them under `tests/`).
- Report the commits and the files changed.
