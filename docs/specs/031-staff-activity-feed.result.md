# Result: brief 031 Staff activity record and read-only staff feed

Branch `feat/031-staff-activity-feed`, cut from `develop` at d6352b4.

## Commits

| Commit | What |
|---|---|
| `9c57118` docs | The brief, verbatim |
| `632e12b` feat | Part A: last seen, last login, `staff_events`, middleware, login/logout listener, prune command and schedule |
| `e4d723b` feat | Part B: `GET /api/staff-status`, `GET /api/staff-events`, `services.staff_feed.key`, `.env.example` |
| this commit, docs | This result |

## Part A

- Migrations: `2026_10_16_100001_add_last_seen_and_login_to_users` (`last_seen_at` datetime, `last_seen_path` string 255,
  `last_login_at` datetime, all nullable, each added only if missing) and `2026_10_16_100002_create_staff_events_table`
  (id, user_id, role, kind 191, method 10, status smallint, ref 64, created_at; indexes `(user_id, created_at)` and
  `(created_at)`; guarded with `Schema::hasTable`). Both are sqlite-safe; the tests run them as-is.
- `App\Http\Middleware\RecordStaffActivity`, appended to the `web` group in `bootstrap/app.php`:
  - last seen: after the response, for the logged-in user, `Cache::add("staff_last_seen:{id}", 1, 60)`; only when the
    key was absent does it update `last_seen_at` and `last_seen_path` (`$request->path()`, never the query string).
  - event: one `staff_events` row when the method is POST/PUT/PATCH/DELETE, the status is 2xx/3xx, and the same user
    is logged in before and after the request. The last rule keeps the login POST (guest before) and the logout POST
    (guest after) out of the middleware: the listener records those as `login` / `logout`, so they are not doubled.
  - kind = route name, else `METHOD uri-pattern` (e.g. `POST t/unnamed/{task}`); ref = the raw URL segment
    (`originalParameters()`, before model binding) of the first parameter named `id`, `*_id` or `*Id`, else the
    first parameter, if scalar; cut to 64.
  - The body and query string are never read. CSRF failures (419) never reach the event code; 4xx/5xx are skipped.
    Everything after `$next` is in try/catch with `Log::warning`.
- `App\Support\StaffEvents::record()`: snapshots `employee_profiles.role`, inserts, never throws.
- `App\Listeners\StaffLoginActivity` (`recordLogin`, `recordLogout`), registered explicitly in
  `AppServiceProvider::register()` like the existing login listener, with non-`handle*` names so event discovery does
  not also register them.
- `staff-events:prune` (`App\Console\Commands\PruneStaffEvents`): deletes rows with `created_at` older than now − 90
  days; scheduled in `routes/console.php` daily 04:20 Asia/Manila, `withoutOverlapping`.

## Part B: JSON shapes (fake data)

Both: header `X-STAFF-FEED-KEY`; `hash_equals` against `config('services.staff_feed.key')` (env `STAFF_FEED_KEY`);
empty key → 503, missing or wrong header → 401; `throttle:30,1`; GET only (POST → 405). Times are ISO 8601 in
Asia/Manila.

`GET /api/staff-status?date=2026-10-16` (date optional, `YYYY-MM-DD`, else 422; default today in Manila)

```json
{
  "date": "2026-10-16",
  "generated_at": "2026-10-16T10:00:00+08:00",
  "staff": [
    {
      "user_id": 7,
      "name": "Staff Uno",
      "role": "Data Encoder",
      "department": "Operations",
      "last_seen_at": "2026-10-16T09:58:00+08:00",
      "last_seen_path": "encoder/orders",
      "last_login_at": "2026-10-16T08:00:00+08:00",
      "online": true,
      "last_event": { "kind": "encoder.update", "at": "2026-10-16T09:57:12+08:00" },
      "counts": { "encoder.update": 41, "login": 1 },
      "checklist_due_not_submitted": ["Bilangin ang stock"]
    }
  ]
}
```

- One entry per `employee_profiles.status = 'Active'` joined to `users`, ordered by user id. `name` is
  `employee_profiles.name`, falling back to `users.name`.
- `online`: true/false from `sessions.last_activity` within 10 minutes, by `user_id`, when `session.driver` is
  `database` and the table exists; else null. Only `user_id` and `last_activity` are read.
- `last_event`: the newest `staff_events` row of the user (any day), or null. `counts`: `{kind: n}` for the requested
  Manila day (`{}` when none).
- `checklist_due_not_submitted`: active tasks assigned to the user, created on or before the day and not deleted by
  then, due by the checklist page's rules (weekly `frequency_day` = day of week, monthly = day of month, `once` until
  the day of its first submission, daily always), with no submission that day. Group task: any assignee's submission
  counts as done (as in the checklist range report); individual task: only the user's own.

`GET /api/staff-events?since=2026-10-16 08:00&user_id=7&limit=200` (`since` required, any date/datetime Carbon
parses, else 422; `user_id` integer else 422; `limit` default 200, clamped to 1..1000)

```json
{
  "since": "2026-10-16T08:00:00+08:00",
  "count": 1,
  "events": [
    {
      "user_id": 7,
      "name": "Staff Uno",
      "role": "Data Encoder",
      "kind": "encoder.update",
      "method": "POST",
      "status": 302,
      "ref": "18342",
      "created_at": "2026-10-16T09:57:12+08:00"
    }
  ]
}
```

Newest first (`created_at`, then `id`). Every query has an explicit column list; no model is serialised.

## Middleware cost

Measured with a throwaway timing test (not committed; sqlite in memory, array cache): about 22 µs per GET and about
130 µs per write (role lookup + insert). On the server the cache store default is `database`, so each authenticated
request adds one `Cache::add` round trip (sub-millisecond on local MySQL), plus one `users` update at most once a
minute per user; each successful write adds three small queries (table check, role, insert), about 1–2 ms.

## Case table

| Case | Test | Result |
|---|---|---|
| POST 2xx/3xx writes one event, route name, no body or query | `StaffEventRecordingTest::test_a_successful_post_writes_one_event_with_the_route_name_and_no_body` | pass |
| Unnamed route kind = `METHOD pattern` | `test_an_unnamed_route_is_recorded_as_method_and_path_pattern` | pass |
| GET, 4xx, 5xx write none | `test_reads_and_failed_writes_write_no_event` (3 data sets) | pass |
| CSRF failure writes none | `test_a_csrf_failure_writes_no_event` | pass |
| Guests write none | `test_guests_write_no_event_and_no_last_seen` | pass |
| last_seen at most once a minute, path without query | `test_last_seen_updates_at_most_once_a_minute` | pass |
| Login/logout events, last_login_at | `test_login_sets_last_login_and_writes_login_and_logout_events` | pass |
| Login/logout POSTs not doubled | `test_the_login_and_logout_posts_are_not_logged_twice` | pass |
| Prune only > 90 days | `test_prune_removes_only_events_older_than_90_days` | pass |
| Schedule 04:20 Manila | `test_prune_is_scheduled_daily_at_0420_manila` | pass |
| No key 401, wrong key 401, unset key 503 | `StaffFeedApiTest::test_the_feed_refuses_without_the_right_key` (3 data sets, both routes) | pass |
| POST → 405 | `test_post_to_the_feed_routes_is_405` | pass |
| 200 with exactly the documented keys, no private data, inactive excluded | `test_status_has_exactly_the_documented_keys_and_no_private_data` | pass |
| online false after 10 min, null when not database | `test_online_is_false_after_10_minutes_and_null_when_sessions_are_not_in_the_database` | pass |
| Counts per kind for a date | `test_counts_per_kind_are_for_the_requested_manila_day` | pass |
| Bad date 422 | `test_a_bad_date_is_422` | pass |
| checklist_due_not_submitted with and without submission, frequencies, group/individual | `test_checklist_due_not_submitted_lists_due_tasks_without_a_submission_that_day` | pass |
| Events since, user_id, newest first, exact keys | `test_events_since_newest_first_with_exact_keys` | pass |
| Events limit default 200, cap 1000 | `test_events_limit_defaults_to_200_and_caps_at_1000` | pass |
| Events without valid since 422 | `test_events_without_a_valid_since_is_422` | pass |

Red runs: Part A failed first on missing behaviour (`ItemNotFoundException` for no event row, `CommandNotFoundException`
for the prune command, schedule count 0); Part B failed first with 404 (no routes).

## Suite

`php artisan test`: 2 failed, 141 passed, 980 warnings (15098 assertions), 238 s. The two failures are the known
`Tests\Feature\ExampleTest` and `Tests\Feature\NightRun\ImportStartTest` (Google credentials file absent in this
worktree). The warnings are from the worktree environment (no credentials/env files here) and appear in suites this
work did not touch; `vendor/bin/phpunit tests/Feature/StaffActivity tests/Feature/Auth tests/Feature/Checklist` runs
clean (`OK`). No other test fails.

## Release

- Run the two migrations (`php artisan migrate --force`).
- Set `STAFF_FEED_KEY` on the server; while it is empty the feed answers 503.
- The prune runs from the existing `schedule:run` cron.

## Brief vs code

- The brief asks for counts "per kind" and a `last_event`; it does not say whether `last_event` is limited to the
  requested date. It is the newest event of any day, so a staff member idle today still shows what they did last.
- The brief does not say how group checklist tasks count. They follow the checklist range report: one submission by
  any assignee closes the task for all of them.
- `/api/staff-status` also returns `date` (the day the counts are for); `/api/staff-events` returns `since` and
  `count`. They are part of the documented key set and asserted in the tests.
- Remember-me sign-ins fire Laravel's `Login` event, so they also set `last_login_at` and write a `login` event.
- The `auth:sanctum` route in `routes/api.php` is untouched (out of scope).

## Story changes

None: the brief carried its cases; no `qa/stories.md` section was added (fast-mode brief).
