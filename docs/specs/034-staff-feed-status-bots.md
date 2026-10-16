# Brief 034: the staff feed includes staff with a blank status, and marks bot accounts

## Why

Spec 031's `GET /api/staff-status` lists only employees with `employee_profiles.status = 'Active'`. On production several staff have a blank status and are missing from the feed (and so from the owner's dashboard), although they work every day. One active account is the team's automation bot; its many logins and imports should not read as a person's.

## Do

- `/api/staff-status`: include every employee profile except those explicitly inactive. First list the distinct values the code base writes or checks for `employee_profiles.status` (e.g. Active, Inactive, Resigned, Terminated) and treat NULL or empty as active; exclude only the explicit inactive values; document the rule in the result.
- Bots: a config list of user ids, `config('services.staff_feed.bot_user_ids')` from env `STAFF_FEED_BOT_USER_IDS` (comma-separated integers; empty by default; invalid parts ignored). Each staff entry gets `"is_bot": true|false`; `/api/staff-events` rows get `"is_bot"` too. Bots stay in the lists. Add the env key, empty, to `.env.example`.
- Update the test that pins the exact JSON key set.

## Tests

Blank and NULL status included; an explicit inactive value excluded; `is_bot` true only for configured ids, false when the env is empty or malformed; key-set test updated. Red first where natural. The whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

Changing any status value in the data; any other feed change.
