# Result 032: one login event per login, and last_login_at saved every time

## Causes

### 1. The middleware records the login POST as `POST login`

- `app/Http/Middleware/RecordStaffActivity.php:43` (at 2306716) skipped a write only when the signed-in user was
  different before and after the request. That holds for a login from a guest session, not for a session that is
  **already signed in** and posts the login form again: the user is the same before and after, so the middleware
  wrote a row as well. `/login` has no guest redirect (`routes/web.php:97-98`), so a staff member who is still signed
  in (the login page left open in a tab, a bookmark to `/login`) sees the form and logs in again. The
  listener writes `login` and the middleware writes another row.
- The kind is `POST login` because the POST route has no name (`routes/web.php:98`), so `describe()` falls back to
  `METHOD uri` (`RecordStaffActivity.php:90`).
- Reproduced with the real route and controller: `test_logging_in_again_on_a_signed_in_session_writes_one_login_event_and_no_post_login`
  was red with `+ 1 => 'POST login'`.

### 2. A second `login` row on the CEO's login, and last_login_at

- `app/Http/Controllers/Auth/LoginController.php:22` and `:28`: a CEO login calls `Auth::attempt` and then
  `Auth::login($user, true)` to issue the remember cookie. Each call fires the `Login` event, so
  `app/Listeners/StaffLoginActivity.php:28` wrote two `login` rows for every CEO login. Red test:
  `test_a_ceo_login_writes_one_login_event` (`+ 1 => 'login'`).
- **last_login_at: no cause found in the code.** `StaffLoginActivity.php:23` updates `last_login_at` with the query
  builder (no `$fillable`, no model save). It runs on every `Login` event, before any other listener that could
  throw: it is registered in `AppServiceProvider::register()`, and the discovered `CopyEverydayTasksOnLogin` is
  attached later. The update errors only into a `Log::warning`. Nothing else in `app/` writes that column. With the real
  route it is set on every path tested: a guest login, a login on a signed-in session, the CEO login, and a second user
  logging in. The production numbers also fit one user logging in 6 times on a session that was still signed in.
  Each of those logins gives one `login` and one `POST login` row, and only that one user gets `last_login_at`. So the
  brief's "1 of the users who logged in" may be 1 of 1. Check with
  `SELECT user_id, kind, COUNT(*) FROM staff_events GROUP BY user_id, kind`. If several user ids have a `login`
  row and `last_login_at` is still NULL for them, look for `StaffLoginActivity: hindi na-update ang last_login_at`
  in the production log: that warning carries the error.

## Fix

- `RecordStaffActivity`: the middleware never records the `LoginController@login` or `LoginController@logout` action
  (matched by the route's action name, so the routes stay unnamed and unchanged).
- `StaffLoginActivity::recordLogin`: `last_login_at` is still updated on every `Login` event; the `login` row is
  written once per user per request (a request attribute), so the CEO's second `Login` event adds no row.
- Nothing else changed: no route, controller, migration or feed change.

## Tests

`tests/Feature/StaffActivity/LoginEventsTest.php`, all through `POST /login` and `POST /logout`:

| Case | Test | Before fix | After |
| --- | --- | --- | --- |
| One login → one `login`, `last_login_at` set | `test_one_login_writes_one_login_event_and_sets_last_login_at` | pass | pass |
| Login again on a signed-in session → one `login`, no `POST login` | `test_logging_in_again_on_a_signed_in_session_writes_one_login_event_and_no_post_login` | **fail** (`POST login`) | pass |
| CEO login → one `login` | `test_a_ceo_login_writes_one_login_event` | **fail** (second `login`) | pass |
| Logout → one `logout` | `test_logout_writes_one_logout_event` | pass | pass |
| Second user gets their own `last_login_at` | `test_a_second_user_logging_in_gets_their_own_last_login_at` | pass | pass |

Affected folders: `vendor/bin/phpunit tests/Feature/StaffActivity tests/Feature/Auth` → `OK (87 tests, 610 assertions)`.

Full suite (`vendor/bin/phpunit`): `Tests: 1146, Assertions: 15964, Errors: 1, Failures: 1, Skipped: 3`. The two red tests are the known ones: `ExampleTest::test_the_application_returns_a_successful_response` and `NightRunImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (fresh worktree). No other test is red.

Lint: `php -l` clean on both changed files.

## What the brief got wrong

- It says each login was recorded twice because the middleware should skip a changed user. The skip worked for
  guests. The doubled rows come from sessions that were already signed in, which the "user changed" rule cannot
  catch.
- "last_login_at set for only 1 of the users": the code saves it on every login. See cause 2 for the query that
  tells whether more than one user was involved.

## Story changes

None: `qa/stories.md` has no stories for the staff feed; the brief's cases are in the table above.

## Notes

- Fresh worktree: `php artisan test` adds a `file_get_contents` warning to each test (the worktree has no
  environment file), so the suite was run with `vendor/bin/phpunit`.
- Suggestion for the owner, not done: a guest redirect on `/login` would stop signed-in staff from logging in again.
  That is a behaviour change outside this brief.
