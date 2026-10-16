# Result 034: the staff feed includes staff with a blank status, and marks bot accounts

## Status values found for `employee_profiles.status`

- Written: only `'Active'`, by the `User::created` hook (`app/Models/User.php:49`). No form or controller in `app/`
  or `resources/views` writes the column; anything else on production came from outside the code (by hand or import).
- Checked: only `StaffFeedController` (`= 'Active'`, before this change).
- Named: the column's migration comment, `Active, Resigned, etc.`
  (`database/migrations/2025_07_08_154202_create_employee_profiles_table.php:33`), and the existing feed test's `Inactive`.

## Rule as built

`/api/staff-status` lists a profile **unless** `LOWER(TRIM(status))` is `inactive`, `resigned` or `terminated`.
NULL, blank, whitespace-only, `Active` and any other value are listed. One parameterised `whereRaw`
(`LOWER`/`TRIM` work the same on MySQL, PostgreSQL and SQLite); the list is the constant
`StaffFeedController::INACTIVE_STATUSES`.

## Bots

- `config('services.staff_feed.bot_user_ids')`, from env `STAFF_FEED_BOT_USER_IDS` (empty by default). The value is
  the comma-separated string from the env; the controller splits it, trims each part and keeps only all-digit parts
  (`abc`, `1.5`, `-2`, `9x`, empty parts are ignored).
- Each `/api/staff-status` staff entry and each `/api/staff-events` row ends with `"is_bot": true|false`. Bots stay in
  both lists.
- `.env.example`: `STAFF_FEED_BOT_USER_IDS=` added, empty.

## Tests (`tests/Feature/StaffActivity/StaffFeedApiTest.php`)

| Case | Test | Result |
| --- | --- | --- |
| NULL, blank, spaces, Active listed | `test_only_explicitly_inactive_statuses_are_left_out` (data sets NULL, blank, spaces lang, Active) | pass |
| Inactive, Resigned, Terminated, ` resigned ` left out | same test (data sets Inactive, Resigned, Terminated, ibang casing) | pass |
| `is_bot` false for empty, unset, malformed env | `test_is_bot_is_true_only_for_the_configured_user_ids` (walang laman, hindi nakaset, malformed) | pass |
| `is_bot` true only for the configured ids, on both endpoints | same test (unang user, may sira sa paligid) | pass |
| Key sets include `is_bot` | `test_status_has_exactly_the_documented_keys_and_no_private_data`, `test_events_since_newest_first_with_exact_keys` (`STAFF_KEYS`, `EVENT_KEYS`) | pass |

Red run before the code: 10 failed, e.g. `only explicitly inactive statuses are left out with data set "blank"`
(`Failed asserting that two arrays are identical`) and `is bot ... "unang user"` (`Undefined array key "is_bot"`).
After: `StaffFeedApiTest` 25 passed (87 assertions).

Full suite: `Tests: 2 failed, 1016 warnings, 141 passed (16000 assertions)`. The 2 failures are the known ones:
`ExampleTest > the application returns a successful response` and
`NightRun\ImportStartTest > macro job final write applies only whi…` (InvalidArgumentException, fresh worktree).

## To set on the server

`STAFF_FEED_BOT_USER_IDS=<the bot account's user id>` in the production environment file, then
`php artisan config:clear` (or `config:cache`). No migration.

## Notes

- `vendor/` was copied from the main checkout (missing in the fresh worktree); it is git-ignored.
- No story changes; the brief brought no stories and the kit's plan gate was replaced by this fast-mode brief.
- Not done (out of scope): no status value in the data changed.
