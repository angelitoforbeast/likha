# Brief 032: one login event per login, and last_login_at saved every time

## Why

Spec 031 (live since 2026-10-16 14:52 Manila) records staff activity. In its first 45 minutes on production: 6 `staff_events` rows of kind `login` and 6 of kind `POST login` (each login recorded twice: once by the `Login` listener `App\Listeners\StaffLoginActivity`, once by the middleware `App\Http\Middleware\RecordStaffActivity`, although 031 meant the middleware to skip requests where the signed-in user differs before and after), and `users.last_login_at` set for only 1 of the users who logged in. Read `docs/specs/031-staff-activity-feed.md` and its result first.

## Do

- Find why the middleware records the login POST (e.g. how it reads the user "before" the request, session regeneration, the login route's name or an unnamed route) and why `last_login_at` is not saved for most logins (e.g. a mass-assignment guard, `$fillable`, a save on a stale model, the listener registered twice or on the wrong event, remember-me or another login path such as a custom login controller). Write the cause of each in the result with file:line.
- Fix both so that: every successful login writes exactly one `staff_events` row of kind `login` and sets `last_login_at` for that user; logout writes exactly one `logout` row; the middleware never records the login or logout requests themselves; nothing else changes.
- Tests that reproduce the production behaviour first (red), using the app's real login route and controller: one login → one `login` event, `last_login_at` set; logout → one `logout` event; a second user logging in gets their own `last_login_at`. The whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree); name any other.

## Out of scope

Cleaning the duplicate rows already in production (the reviewer handles data); any other change to the feed.

## Final message

The two causes with file:line; the fix; the test lines and suite result; anything the brief got wrong.
