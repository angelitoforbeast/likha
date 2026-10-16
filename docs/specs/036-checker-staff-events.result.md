# Result 036: Checker edits become staff events, and the staff feed counts them

## Commits

- `docs: brief of spec 036`
- `feat: Checker 1 and J&T status changes write one checker event per changed order (spec 036)` (Part A)
- `feat: staff feed shows checker events without their order id and counts checker work per person (spec 036)` (Part B)
- `docs: result of spec 036`
- `feat: staff feed counts approved checklist submissions per person and adds totals (spec 036)` (amendment 1)
- `fix: J&T upload writes one generic event again, not one checker event per waybill (spec 036)` (amendment 1)
- `docs: result of spec 036 with amendment 1`

## Amendment 1 (changes to the brief)

| Change | Done |
|---|---|
| `/api/staff-status`: per staff `checklist_approved_today` = their checklist submissions dated the requested Manila day whose latest approval log (`checklist_analysis_logs`, `log_type = approval`, newest `created_at` then `id`) has verdict approved; credit to the submission's `user_id`; zero when none | `StaffFeedController::checklistApproved`. Group tasks allow one submission per task per day (unique `(checklist_task_id, date)`), so its `user_id` is the first submitter; individual tasks have one per person. No group/individual branch is needed. |
| Top-level `totals`: `checklist_approved_today`, `status_changes`, `edited_orders` summed over everyone in the list | Summed from the returned staff rows, so people left out of the list (inactive) are not counted. |
| `/jnt_update` writes no checker events; the middleware keeps its one generic event | `FromJntController` is back to its `develop` version; removed from `CHECKER_ACTIONS`; the test asserts one `POST jnt_update` event and no checker event. |
| Counts only: no titles, notes, verdict text or file data | Only integers are added; the test checks that task titles, notes, the AI text and `not_approved` are absent from the response. |

## Inventory (signed-in staff actions that change orders or J&T statuses)

| Path | Route | What it changes | Event written |
|---|---|---|---|
| `app/Http/Controllers/MacroOutputController.php` `updateField` (event at :1607) | `POST /encoder/checker_1/update-field` | one field of one order, appends `status_logs` / `HISTORICAL LOGS` | `checker.status` if field is STATUS, else `checker.edit`; none when the value is the same |
| `MacroOutputController::bulkUpdate` (event at :1703, compare at :1671) | `POST /encoder/checker_1/update` | any fields of many orders, no order log | one per order whose values really change: `checker.status` if STATUS changes, else `checker.edit` (compared before the save, `bulkChangeKind`) |
| `MacroOutputController::validateItems` (event at :342) | `POST /macro_output/validate-items` | `item_checker` 1 / 0 | `checker.validate` per order whose flag changes |
| `MacroOutputController::validateAddresses` (event at :569) | `POST /macro_output/validate` | `validate_2` 1 / 0 | `checker.validate` per order whose flag changes |
| `MacroOutputController::validateCheckerToFix` (event at :640) | `POST /macro_output/validate1` | `validate_1` = 1 for the day's scope, then `APP SCRIPT CHECKER`, `validate_2`, `item_checker` | `checker.validate` per order whose `validate_1` changes (the button's own flag; the follow-up checker columns are the same press and are not counted again) |
| `app/Http/Controllers/FromJntController.php` `updateOrInsert` (~1170) | `POST /jnt_update` (page `jnt_update`) | `from_jnts.status` + `status_logs` of existing waybills from an uploaded file; inserts new ones | none (amendment 1): the middleware's one generic `POST jnt_update` event per upload |

Not wired, with the reason:

- `MacroOutputController::update` (1070): no route points to it.
- `FromJntController::store` (957): inserts rows only, no status change.
- `JntCheckerController.php:441`: sets `macro_output.waybill` while matching J&T data, not a checker edit.
- `JntUploadV2Controller` / `JntUploadController`, `ProcessJntUpload`, `ConsolidateAndMergeJntV2Run`, `MergeWinnersToFromJnts2Pipeline`, `SyncFromJntStatusByTrack`, `AstraEncoder`: imports, queue jobs and Astra (automated writers). `from_jnts_2*` is written only by these.
- `Checker1SummaryController`, `Checker1IdleSummaryController`, `MacroExportController`: read only.

The generic middleware row is skipped for the five Checker 1 actions above by controller action (`RecordStaffActivity::CHECKER_ACTIONS`), always, also when nothing changed.

## Migration

`database/migrations/2026_10_16_200001_add_source_to_staff_events.php`: adds nullable `staff_events.source` varchar(32) (`checker_1` today; NULL for route and login events). Additive and guarded (`hasTable` / `hasColumn`). Chosen over packing the page into `kind` so the kinds stay exactly the three agreed names. Run by hand on deploy: `php artisan migrate --force`. Until it runs, checker events fail softly (warning in the log, request unaffected) and the feed returns `source` null and zero checker counts. The approved count needs no migration.

## JSON (fake data)

`/api/staff-status?date=2026-10-15`:

```json
{
  "date": "2026-10-15",
  "generated_at": "2026-10-15T18:00:00+08:00",
  "staff": [
    { "user_id": 7, "name": "Encoder Uno", "...": "existing keys",
      "checker": { "status_last_editor": 2, "edited_orders": 4, "status_changes": 3, "validates": 1 },
      "checklist_approved_today": 2 }
  ],
  "totals": { "checklist_approved_today": 4, "status_changes": 5, "edited_orders": 6 }
}
```

`/api/staff-events`, each event gets `source`; `ref` is null for checker kinds:

```json
{ "user_id": 7, "name": "Encoder Uno", "role": "Data Encoder", "kind": "checker.status", "source": "checker_1",
  "method": "POST", "status": 200, "ref": null, "created_at": "2026-10-15T10:00:00+08:00", "is_bot": false }
```

Counting rules: an order is `source` + `ref`. `status_last_editor` = orders whose latest `checker.status` of the Manila day (created_at, then id) is the user's; `edited_orders` = distinct orders with any checker event by the user that day; `status_changes` / `validates` = event counts. Zeros when none.

## Tests

`tests/Feature/StaffActivity/CheckerEventsTest.php` (new), `StaffFeedApiTest.php` (4 new, key-set updates), `StaffEventRecordingTest.php` (column set now includes `source`).

| Test | Result |
|---|---|
| a status edit writes one checker status event and no route event (also: role, method, status 200, no customer data) | pass |
| a field edit writes checker edit and an unchanged value writes nothing | pass |
| bulk save of three rows with two changed writes two events | pass |
| the item check button writes checker validate only for orders whose flag changed | pass |
| a jnt upload writes one generic event and no checker event | pass |
| a writer without a signed-in user writes no event (Checker 1 `updateField` called with no Auth user) | pass |
| events show kind and source of checker rows but never their ref | pass |
| checker counts per person use the last editor rule for the manila day (two users, same order, 23:59:59 / 00:00 boundary, same id under another source, zeros) | pass |
| checklist approved today counts submissions whose latest verdict is approved (approved, not approved, no verdict, NOT APPROVED then APPROVED re-check counts once, APPROVED then NOT APPROVED counts zero, group first submitter vs individual each, day before / after, idle user zero, total, no titles / notes / AI text) | pass |
| totals sum the checker counts of everyone listed | pass |
| status has exactly the documented keys (now `checker`, `checklist_approved_today`, top-level `totals`) / events since newest first with exact keys | pass |

Red runs: Part A, all five route tests failed with the generic event (`'macro_output.update_field'` instead of `'checker.status'`); Part B, `Undefined array key "checker"` / `"source"` and the key-set asserts; amendment, `Undefined array key "checklist_approved_today"`, `null is identical to Array` (totals), and the J&T test got `['checker.status', 'jnt', '1']` instead of `['POST jnt_update', null, null]`. The rewritten "no signed-in user" test pins behaviour that already existed (the helper's Auth guard), so it had no behaviour red.

Full suite: `Tests: 2 failed, 1036 warnings, 141 passed (16088 assertions)`. The 2 failures are the known `ExampleTest` and `NightRun\ImportStartTest` (fresh worktree). Every test reports a warning, before this change too.

## What the brief got wrong or left open

- The J&T "status edit" was a file upload (`/jnt_update`), not a per-row edit; amendment 1 settled it: no checker events, one generic event per upload.
- No J&T page lets staff edit one status by hand; `from_jnts_2*` status logs are written only by jobs. So all checker events today come from Checker 1 (`source` = `checker_1`).
- "Status 200": bulk save answers with a redirect (302), but the checker events record 200 as the brief says.
- Validate buttons count only orders whose flag value actually changes (consistent with "no row when nothing changed"); pressing validate again on already-validated orders writes nothing.
- Amendment 1 "group: first submitter only": the live unique index already allows one submission per group task per day, so counting by the submission's `user_id` gives that rule without a type check.

## Story changes

None: the brief brought its test cases and no stories file entries.

## Suggestions for the owner (not done)

- The Checker 1 POST routes have no role check beyond `auth` + `allowed_ip`.
- `FromJnt` casts `status_logs` to array while `updateOrInsert` assigns a JSON string, which may double-encode the log; untouched (order logs out of scope).
