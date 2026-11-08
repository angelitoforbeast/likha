# Spec 248: Retrieve 2 reads created_at as Manila time (no 8-hour shift)

Type: bug, cause found. Weight: bounded. Risk tier: low; one page and its export. Budget: small job, at most 2 attempts per step.

## Why

`pancake_conversations.created_at` holds Manila wall-clock time, not UTC:
- the only writer, `ProcessPancakeConversationsUpload`, sets `created_at` to `$now`;
- the app timezone is `Asia/Manila` (`config/app.php`);
- the database connection sets no time zone, so the MySQL session is UTC;
- the column is a TIMESTAMP, so the Manila wall clock is stored and read back unchanged.

On production at 00:06 Manila, the newest row read `2026-11-09 00:06:35` while the server clock read 16:06 UTC.

`PancakeRetrieve2Controller::retrieveQuery()` treats the column as UTC:
- the date bounds are Manila midnight converted to UTC (`->utc()`, minus 8 hours);
- `date_created` is `CONVERT_TZ(pc.created_at,'+00:00','+08:00')` (plus 8 hours);
- the shop-mode join uses `DATE(CONVERT_TZ(pc.created_at, ...))`.

So a conversation uploaded after 16:00 Manila lands on the next day, and the shown time is 8 hours ahead. `CONVERT_TZ` appears nowhere else in `app/`.

## Stories

S1 (P1). The date range picks conversations by their Manila `created_at` as stored.
- The bounds are `>= 'YYYY-MM-DD 00:00:00'` of the from-date and `< 00:00:00` of the day after the to-date, with no conversion.
- Edge: a row at `2026-11-07 23:59:59` is in a 2026-11-07 range; a row at `2026-11-08 00:00:00` is not. A row at `2026-11-07 17:00:00` is in the 2026-11-07 range; today it shows under 2026-11-08.

S2 (P1). "Date created" on the page and `date_created` in the export show `pc.created_at` as stored, without adding 8 hours.

S3 (P1). The shop-details join matches `macro_output.ts_date` against `DATE(pc.created_at)`, without CONVERT_TZ.

S4. Nothing else changes: matching (PSID rule, page and name probe), filters, pagination, export fields and order. The `created_at` index from spec 247 is still used: the bounds compare the bare column.

## Decisions already made (do not reopen unless something is actually broken)

| Decision | Reason |
|---|---|
| Read `created_at` as Manila; no data change. | The data is consistent (every row was written as Manila). The query was wrong. The owner asked for the fix. |
| Retrieve 2 only. | It is the only `CONVERT_TZ` user in `app/`. |
| No migration. | Not needed. |

## Threat model

Untrusted: the query-string dates and filters. They stay bound parameters, and dates are parsed through Carbon as today. Trusted: the database.

## Constraints

- **Tests first.** Change the sqlite `CONVERT_TZ` stand-ins only as needed. Prove S1–S3 on both the page and the export. Re-pin the spec 247 tests whose expectations encoded the UTC shift (the day-boundary case), and list each changed assertion in the result under "Story changes".
- English UI text (none expected). Code comments state the reason ("created_at is stored as Manila wall-clock time"). Commit messages carry no Co-Authored-By, Claude-Session or "Generated with Claude Code" lines and name no people.
- No `.env`, server or deploy changes.
- **Worktree:** `C:/mira/mira/projects/likha-wt247`, branch `fix/248-retrieve2-manila-dates` (already checked out at the live commit `96980ba1`).
  - Run tests with `php vendor/bin/phpunit --bootstrap "C:/Users/Forbeast/AppData/Local/Temp/claude/2e760ef270dbe960/07319e29-2afc-45d1-9b8d-9083b1ed643e/scratchpad/wt247-bootstrap.php" ...`.
  - Never run anything in the main checkout.

## Done when

1. Feature tests prove S1–S3, and `tests/Feature/PancakePsid/` passes.
2. The full suite adds no new failures compared with `96980ba1` (2 known: ExampleTest, ItemTableFitScriptTest).
3. No migration.
4. Branch pushed to `origin`. The result lists its head and the new page SQL (`toSql()`).

## Out of scope

Other pages, other tables' time zones, the DB connection time zone, data changes.

## Report back

In `docs/specs/248-retrieve2-manila-dates.result.md`:
- the plan;
- the evidence: test names and suite counts at base and head;
- story changes;
- the new SQL;
- files changed;
- the head commit.
