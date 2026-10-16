# Result 035: Data Encoders may view /jnt_upload (no uploading), and see it in the menu

## Access as built (`app/Http/Controllers/JntUploadController.php`)

- `canUpload()`: the existing rule, unchanged (CEO, Marketing, Marketing - OIC; trimmed, spaces collapsed,
  case-insensitive, any of `-–—` before OIC). `checkAccess()` now calls it and still answers 404.
- `isViewOnly()`: `Data Encoder` or `Data Encoder - OIC` under the same normalisation.
- Read routes (`index`, `status`, `history`) use `checkViewAccess()`: uploaders or view-only roles; any other role → 404.
- The only write route of this controller is `store` (POST /jnt_upload): view-only roles → 403 before anything is
  validated, stored or queued; other non-uploaders → 404 as before.
- `index` passes `$canUpload` to the view.

## Page (`resources/views/jnt_upload.blade.php`)

When `$canUpload` is false, the upload form (file input, UPLOAD button), the status box and the optional date/time
field are not rendered; a line reads "View only — hindi puwedeng mag-upload ang role mo rito." The history table and
its Refresh button stay. The two upload listeners in the script are guarded (`if (fileInput)`, `if (btnUpload)`) so the
history script still runs without the form.

## Menu

Visibility lives in database rows: `nav_link_role_visibility` per `nav_links` row, read by `NavLink::visibleFor()` in
`resources/views/components/layout.blade.php`. The layout has no hard-coded guard for this link any more (the old
controller comment pointed at one); the link is the `waybill` row (`/jnt_upload`). The original seed put
`is_visible = false` rows for both Data Encoder roles.

- **Migration** `database/migrations/2026_10_16_200000_show_waybill_nav_link_to_data_encoders.php`: for the `waybill`
  link only, sets `is_visible = true` for `Data Encoder` and `Data Encoder - OIC` (updates the existing false row,
  or inserts it when missing). Rerun: no change, no duplicates (the table also has a unique `nav_link_id, role`).
  Guarded with `Schema::hasTable`; does nothing when the `waybill` row is absent. `down()` sets the two rows back to false.
  Run on the server by hand: `php artisan migrate --force`.
- `NavLink::defaultData()` (the defaults the /owner/nav-settings Reset button re-applies) lists both Data Encoder roles
  for `waybill`, so a Reset keeps the change.
- Note: the menu matches the stored role string exactly (unlike the controller's normalisation), as for every other link.

## Tests (`tests/Feature/Jnt/JntUploadAccessTest.php`)

| Case | Test | Result |
| --- | --- | --- |
| S-035.1 | `test_S_035_1_data_encoders_view_the_page_status_and_history_without_the_upload_form` (Data Encoder, Data Encoder - OIC, `  data encoder – oic `) | pass |
| S-035.2 | `test_S_035_2_data_encoders_get_403_on_upload_and_nothing_is_stored_or_queued` (same 3) | pass |
| S-035.3 | `test_S_035_3_ceo_and_marketing_still_see_the_form_and_may_upload` (CEO, Marketing, Marketing - OIC) | pass |
| S-035.4 | `test_S_035_4_other_roles_still_get_404_everywhere` (no role, Sales) | pass |
| S-035.5 | `test_S_035_5_the_menu_shows_the_waybill_link_to_data_encoders` (both roles) | pass |
| S-035.6 | `test_S_035_6_the_menu_migration_reruns_without_duplicates_and_touches_no_other_row` | pass |

Red run before the code: 9 failed, e.g. S-035.1 `Expected response status code [200] but received 404`, S-035.2
`Expected response status code [403] but received 404`, S-035.6 `Failed asserting that two arrays are identical`
(waybill visible only to CEO, Marketing, Marketing - OIC). S-035.3 and S-035.4 passed from the start (they pin
today's behaviour). After: 15 passed, 82 assertions.

Full suite: `Tests: 2 failed, 1040 warnings, 141 passed (16137 assertions)`. The 2 failures are the known ones:
`ExampleTest > the application returns a successful response` and
`NightRun\ImportStartTest > macro job final write applies only while the run is still active` (fresh worktree).

## Notes

- `vendor/` was copied from the main checkout (missing in the fresh worktree); it is git-ignored.
- Story changes: none; the brief brought no story file, so slice 035 was added to `qa/stories.md` from the brief's
  Tests section. The kit's plan gate was replaced by this fast-mode brief.
- `/jnt_upload_v2` and other pages unchanged.
