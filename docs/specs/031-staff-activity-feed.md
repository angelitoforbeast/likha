# Brief 031: record what each staff member does, and serve it read-only to the owner's assistant

## Why

The owner wants to know, at any time, what each staff member is doing: who is active, what they did last and how much today. A read-only study of this commit found: the roles checked in code are CEO, Marketing - OIC, Marketing, Data Encoder - OIC, Data Encoder (others such as Marketing Assistant are free-text roles in `employee_profiles.role`); encoder single-field edits and status changes are logged as text on the order row, but bulk save, `update($id)` and the validate buttons record no who; most Marketing writes have who and when in their own tables, reading pages records nothing; Marketing Assistant and other roles leave traces only in the checklist; there is no last-login or last-seen anywhere. The only existing API auth is the `X-AUTOMATION-KEY` header pattern in `routes/api.php` (`hash_equals` against a config value); the `auth:sanctum` route there cannot work (Sanctum is not installed).

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| One generic record for every role, written by middleware, not per controller | Covers all roles and every page with one change |
| Store the route name, method, status and an id, never the request body or query string | No customer data, passwords or money may enter the log |
| Read-only API with its own key (`STAFF_FEED_KEY`), same header pattern as the automation key | The assistant reads it without a browser login; a separate key can be revoked alone |
| Explicit column lists in every query of the feed; no `select *`, no model serialisation | Prevents leaking sensitive columns |
| Packing is out of scope | There is no packing page yet |

## Part A: last seen, last login, staff events (commit on its own)

- Migration: `users.last_seen_at` (nullable datetime), `users.last_seen_path` (nullable string 255, path only, no query string), `users.last_login_at` (nullable datetime).
- Migration: `staff_events`: id, user_id, role (snapshot of `employee_profiles.role` at the time), kind (the route name, or `METHOD path-pattern` when unnamed), method, status (HTTP status), ref (nullable string 64: the route's `id`-like parameter if it is a scalar, else null), created_at. Indexes `(user_id, created_at)` and `(created_at)`.
- Middleware in the `web` group, for authenticated users only: (1) update `last_seen_at` and `last_seen_path` at most once a minute per user (cache throttle); (2) after the response, for POST, PUT, PATCH and DELETE with a 2xx or 3xx status, insert one `staff_events` row. It must never throw into the request (catch and log a warning), must not log CSRF-failed or 4xx/5xx requests, and must add negligible time.
- Login listener (the `Login` event): set `last_login_at` and write a `staff_events` row with kind `login`. Logout writes kind `logout`.
- Retention: a command that deletes `staff_events` older than 90 days, scheduled daily at 04:20 Asia/Manila (after the 03:00-03:45 night step and the 03:50 refresh).

## Part B: the read-only feed (commit on its own)

- `routes/api.php`: `GET /api/staff-status` and `GET /api/staff-events`, guarded by header `X-STAFF-FEED-KEY` checked with `hash_equals` against `config('services.staff_feed.key')` (env `STAFF_FEED_KEY`); 503 when the key is not configured, 401 when wrong; `throttle:30,1`; GET only.
- `/api/staff-status` (optional `date=YYYY-MM-DD`, default today in Asia/Manila): one entry per active employee (`employee_profiles.status = 'Active'`) with: user_id, name, role, department, last_seen_at, last_seen_path, last_login_at, online (a session with `last_activity` in the last 10 minutes, from `sessions` by `user_id`, if the driver is database; else null), last_event {kind, at}, counts for the day per kind from `staff_events`, and `checklist_due_not_submitted`: the titles of active checklist tasks assigned to that user, due that day (frequency rules as the checklist page applies them) and with no submission that day. Plus `generated_at`.
- `/api/staff-events` (`since` datetime required, optional `user_id`, `limit` default 200, max 1000): rows of user_id, name, role, kind, method, status, ref, created_at, newest first.
- Forbidden in both: passwords and `password_plain`, tokens, emails, session ids and payloads, IP addresses, user agents, every `employee_profiles` column other than name, role, department, status; anything from `macro_output`, J&T, money or message tables. Add a test that asserts the JSON keys are exactly the documented set.
- `.env.example`: `STAFF_FEED_KEY=` (empty).

## Tests

Part A: a successful POST writes one event with the route name and no body; a GET, a 4xx, a 5xx and a CSRF failure write none; guests write none; last_seen updates at most once a minute; login/logout events and last_login_at; the prune command removes only rows older than 90 days; the schedule is registered at 04:20 Manila. Part B: no key 401, wrong key 401, unset key 503; correct key 200 with exactly the documented keys; counts per kind for a date; `checklist_due_not_submitted` for a due task with and without a submission; events `since` and `limit` cap; POST to the routes 405. Red first where natural. The whole suite must pass except the known `ExampleTest` and `ImportStartTest`; name any other.

## Out of scope

Packing pages or events; any change to existing logs (`status_logs`, `HISTORICAL LOGS`); any UI page for the feed; changing role checks of existing routes; reading the request body for richer events.
