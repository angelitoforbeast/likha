# Brief 036: Checker edits become staff events, and the staff feed counts them

## Why

The owner wants one central place to monitor what each staff member does. Specs 031-034 (read `docs/specs/031-*` to `034-*` first) record every successful write request as one `staff_events` row (route name) and serve `/api/staff-status` and `/api/staff-events`. The encoders' main work, changing order statuses and fields on Checker 1 (`MacroOutputController::updateField`, `bulkUpdate`, the validate buttons such as validate_1 / validate_2 / item_checker) and status edits on the J&T pages (`FromJntController` and any other controller that appends to `status_logs` / `"HISTORICAL LOGS"` from a staff action), shows today only as one generic event per request, and bulk save and the validate buttons record no who at all in the order logs. The `/encoder/checker_1/summary` page (`Checker1SummaryController`) counts per user per day "Status Last-Editor" (rows whose latest status change that day was by the user) and "Historical Edits" (distinct rows with at least one edit by the user that day) by scanning the text logs.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| Record at the moment of the save, in the same code path that writes the order log, one event per order changed | The feed must be live and cheap; scanning text logs is heavy |
| Kinds: `checker.status` (status changed), `checker.edit` (another field changed), `checker.validate` (a validate / item check button set its flag) | Owner's words: "checker.status"; the others follow it |
| The order id is stored in `ref` for counting only and is never returned by the feed for checker kinds; no customer data (names, phones, addresses, COD, chat) is ever stored in the event | Names and counts only |
| The generic middleware event for the requests that now write checker events is skipped, so nothing is counted twice | One action, one count |
| Counts start at release; no backfill from old logs | The summary page still covers earlier days |
| Automated writers (imports, Astra, night run, queue jobs) write no checker events; only signed-in staff actions | It is people's work being monitored |

## Part A: write the events (commit on its own)

- Inventory first, in the result: every code path where a signed-in staff action changes `macro_output` STATUS or other fields, or a J&T status (`from_jnts` / `from_jnts_2*` status logs), with file:line, and which kind each writes. Include the validate buttons and item checker.
- In each path, after the change is saved, write one `staff_events` row per order actually changed (no row when nothing changed): user_id, role snapshot, kind, method, status 200, ref = the order or J&T row id, and add a small `page` value if the table has room (else put the page in `kind`, e.g. `checker.status` with a separate column `source` added by an additive migration: `checker_1`, `jnt`, ...; choose the simplest additive option and say which). Use the existing `StaffEvents::record` helper or extend it; never throw into the request.
- Skip the middleware's generic row for these routes (by route name or controller action, like spec 032 did for login).

## Part B: the feed (commit on its own)

- `/api/staff-events`: checker rows appear with kind and source; `ref` is null for checker kinds in the output.
- `/api/staff-status`, per person for the requested day: `checker` = { `status_last_editor`: number of orders whose latest `checker.status` event that day is by this user (same rule as the summary page), `edited_orders`: distinct orders with any checker event by this user that day, `status_changes`: count of `checker.status` events, `validates`: count of `checker.validate` events }. Zeros when none. Update the key-set test.

## Tests

Single status edit, single field edit, bulk save of 3 rows with 2 actually changed, a validate button, a J&T status edit: the right events (kind, source, one per changed order, none for unchanged), no generic duplicate; an automated path writes none; the feed hides ref for checker kinds; staff-status counts for two users editing the same order (last editor rule), including the day boundary in Asia/Manila. Red first where natural. The whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

Changing the summary pages; backfill; any change to the order logs themselves; UI.
