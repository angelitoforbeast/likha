# Result 036: Checker edits become staff events, and the staff feed counts them

## Commits

- `docs: brief of spec 036`
- `feat: Checker 1 and J&T status changes write one checker event per changed order (spec 036)` (Part A)
- `feat: staff feed shows checker events without their order id and counts checker work per person (spec 036)` (Part B)
- `docs: result of spec 036`

## Inventory (signed-in staff actions that change orders or J&T statuses)

| Path | Route | What it changes | Event written |
|---|---|---|---|
| `app/Http/Controllers/MacroOutputController.php` `updateField` (event at :1607) | `POST /encoder/checker_1/update-field` | one field of one order, appends `status_logs` / `HISTORICAL LOGS` | `checker.status` if field is STATUS, else `checker.edit`; none when the value is the same |
| `MacroOutputController::bulkUpdate` (event at :1703, compare at :1671) | `POST /encoder/checker_1/update` | any fields of many orders, no order log | one per order whose values really change: `checker.status` if STATUS changes, else `checker.edit` (compared before the save, `bulkChangeKind`) |
| `MacroOutputController::validateItems` (event at :342) | `POST /macro_output/validate-items` | `item_checker` 1 / 0 | `checker.validate` per order whose flag changes |
| `MacroOutputController::validateAddresses` (event at :569) | `POST /macro_output/validate` | `validate_2` 1 / 0 | `checker.validate` per order whose flag changes |
| `MacroOutputController::validateCheckerToFix` (event at :640) | `POST /macro_output/validate1` | `validate_1` = 1 for the day's scope, then `APP SCRIPT CHECKER`, `validate_2`, `item_checker` | `checker.validate` per order whose `validate_1` changes (the button's own flag; the follow-up checker columns are the same press and are not counted again) |
| `app/Http/Controllers/FromJntController.php` `updateOrInsert` (event at :1262) | `POST /jnt_update` (page `jnt_update`) | `from_jnts.status` + `status_logs` of existing waybills; inserts new ones | `checker.status`, source `jnt`, per existing row whose status really changes; new waybills write none |

Not wired, with the reason:

- `MacroOutputController::update` (1070): no route points to it.
- `FromJntController::store` (957): inserts rows only, no status change.
- `JntCheckerController.php:441`: sets `macro_output.waybill` while matching J&T data, not a checker edit.
- `JntUploadV2Controller` / `JntUploadController`, `ProcessJntUpload`, `ConsolidateAndMergeJntV2Run`, `MergeWinnersToFromJnts2Pipeline`, `SyncFromJntStatusByTrack`, `AstraEncoder`: imports, queue jobs and Astra (automated writers). `from_jnts_2*` is written only by these.
- `Checker1SummaryController`, `Checker1IdleSummaryController`, `MacroExportController`: read only.

The generic middleware row is skipped for the six actions above by controller action (`RecordStaffActivity::CHECKER_ACTIONS`), always, also when nothing changed.

## Migration

`database/migrations/2026_10_16_200001_add_source_to_staff_events.php`: adds nullable `staff_events.source` varchar(32) (`checker_1`, `jnt`; NULL for route and login events). Additive and guarded (`hasTable` / `hasColumn`). Chosen over packing the page into `kind` so the kinds stay exactly the three agreed names. Run by hand on deploy: `php artisan migrate --force`. Until it runs, checker events fail softly (warning in the log, request unaffected) and the feed omits `source` (null) and returns zero checker counts.

## JSON (fake data)

`/api/staff-status?date=2026-10-15`, each staff row gets:

```json
"checker": { "status_last_editor": 2, "edited_orders": 4, "status_changes": 3, "validates": 1 }
```

`/api/staff-events`, each event gets `source`; `ref` is null for checker kinds:

```json
{ "user_id": 7, "name": "Encoder Uno", "role": "Data Encoder", "kind": "checker.status", "source": "checker_1",
  "method": "POST", "status": 200, "ref": null, "created_at": "2026-10-15T10:00:00+08:00", "is_bot": false }
```

Counting rules: an order is `source` + `ref` (a macro_output id and a from_jnts id never collide). `status_last_editor` = orders whose latest `checker.status` of the Manila day (created_at, then id) is the user's; `edited_orders` = distinct orders with any checker event by the user that day; `status_changes` / `validates` = event counts. Zeros when none.

## Tests

`tests/Feature/StaffActivity/CheckerEventsTest.php` (new) and `StaffFeedApiTest.php` (2 new, 2 key-set updates), `StaffEventRecordingTest.php` (column set now includes `source`).

| Test | Result |
|---|---|
| a status edit writes one checker status event and no route event (also: role, method, status 200, no customer data) | pass |
| a field edit writes checker edit and an unchanged value writes nothing | pass |
| bulk save of three rows with two changed writes two events | pass |
| the item check button writes checker validate only for orders whose flag changed | pass |
| a jnt status update writes checker status per changed waybill (unchanged and new waybills write none) | pass |
| a writer without a signed-in user writes no event | pass |
| events show kind and source of checker rows but never their ref | pass |
| checker counts per person use the last editor rule for the manila day (two users, same order, 23:59:59 / 00:00 boundary, jnt id collision, zeros) | pass |
| status has exactly the documented keys / events since newest first with exact keys (updated key sets) | pass |

Red runs: Part A, all five route tests failed with the generic event (`'macro_output.update_field'` instead of `'checker.status'`); Part B, `Undefined array key "checker"` / `"source"` and the key-set asserts.

Full suite: `Tests: 2 failed, 1034 warnings, 141 passed (16078 assertions)`. The 2 failures are the known `ExampleTest` and `NightRun\ImportStartTest` (fresh worktree). The warnings are on every test, also before this change.

## What the brief got wrong or left open

- The J&T "status edit" is not a per-row edit: `/jnt_update` is a staff-run upload of J&T data (one POST, many waybills). It is a signed-in staff action, so it writes `checker.status` / `jnt` per waybill whose status changes, as Part A names `FromJntController`; but by the "imports write none" decision it could also be read as an import. One upload can write thousands of events and dominate `status_changes`. If that is not wanted, removing one line in `updateOrInsert` turns it off.
- No J&T page lets staff edit one status by hand; `from_jnts_2*` status logs are written only by jobs.
- "Status 200": bulk save and `/jnt_update` answer with a redirect (302), but the checker events record 200 as the brief says.
- Validate buttons count only orders whose flag value actually changes (consistent with "no row when nothing changed"); pressing validate again on already-validated orders writes nothing.

## Story changes

None: the brief brought its test cases and no stories file entries.

## Suggestions for the owner (not done)

- The Checker 1 POST routes have no role check beyond `auth` + `allowed_ip`.
- `FromJnt` casts `status_logs` to array while `updateOrInsert` assigns a JSON string, which may double-encode the log; untouched (order logs out of scope).
