# Spec 247: the Retrieve 2 page answers in seconds instead of timing out

Type: bug (cause already found, see Why). Weight: bounded. Risk tier: medium. The page is how the team finds conversations that are not yet orders, and a wrong match shows existing orders again or hides real leads. Budget: medium job, at most 2 attempts per step.

## Why

`/pancake/retrieve2` and `/pancake/retrieve2/export` (`app/Http/Controllers/PancakeRetrieve2Controller.php`) now end in 504 Gateway Time-out on production. The query cannot use the indexes that exist:

1. **`$macroPairs` scans the whole table on every load.** It groups all of `macro_output` (about 947,000 rows) by `LOWER(TRIM(PAGE)), LOWER(TRIM(fb_name))`. Wrapping the columns in functions keeps the existing index `macro_output_page_fb_name_idx (PAGE, fb_name)` from being used. The result is then left-joined to every conversation, including in the PSID path, where only conversations without a PSID need it.
2. **The date filter reads every conversation.** It filters on `DATE(CONVERT_TZ(pc.created_at, ...))`. `pancake_conversations` (about 730,000 rows) has no index on `created_at`, and wrapping the column would keep one from being used anyway. A 7-day range is only about 11,000 rows.

Production facts, checked read-only:
- MySQL 8.0.46.
- `macro_output.PAGE`, `macro_output.fb_name`, `pancake_conversations.full_name` and `pancake_id.pancake_page_name` are all `utf8mb4_unicode_ci`. That collation is case-insensitive and PAD SPACE, so trailing spaces are ignored in `=`.
- 59 `macro_output.fb_name` values have leading or trailing whitespace. None of the other three columns has any.
- Existing indexes:
  - `macro_output`: `(PAGE, fb_name)`, `botcake_psid`, `ts_date`, `(ts_date, PAGE)`.
  - `pancake_conversations`: `full_name`, `(pancake_page_id, full_name)`, `pancake_page_id`, `(psid, pancake_page_id)`.

## Stories

S1 (P1). The page and the export return exactly the same rows as today for any date range, page filter and name filter, and the page loads in a few seconds.
- Happy: a conversation with no matching order is listed. A conversation whose page and name match a `macro_output` row (any letter case, surrounding spaces) is not listed. With a PSID, the existing PSID rule decides (`notYetAnOrder`), unchanged.
- Edge: a Manila day boundary. A conversation at 23:59 Manila on the last day is in the range, and one at 00:00 Manila the next day is not. `created_at` is stored in UTC, so compute the UTC bounds in PHP from the Manila dates (`>= from 00:00 Manila`, `< day after to, 00:00 Manila`) and compare the bare column.
- Edge: an order whose `fb_name` has a leading space still hides its conversation, as `LOWER(TRIM())` does today.
- Edge: a page name that differs only in letter case or trailing spaces still matches.
- Negative: a conversation is never hidden by an order from a different page.

S2 (P1). The query uses indexes:
- a new index on `pancake_conversations.created_at`;
- the existing `(PAGE, fb_name)` index of `macro_output`, reached through a correlated `NOT EXISTS` (or an equivalent that lets MySQL use the index) instead of the whole-table group.

`shopMode` (bounded by `ts_date` and its index) may stay as it is, unless the plan shows it is the slow part.

## Decisions already made (do not reopen unless something is actually broken)

| Decision | Reason |
|---|---|
| Fix the query; do not raise the timeout. | The owner chose the real fix. |
| The new index on `pancake_conversations.created_at` is approved. | The owner approved the fix with the index. It is a migration on a table of about 730,000 rows, so it must be an online index add (MySQL 8 InnoDB adds a secondary index in place without blocking writes) and must skip if the index already exists. |
| Same results as today. | It is a speed fix, not a behaviour change. |
| No new stored or generated column on `macro_output`. | That would be a heavy migration on about 947,000 rows. Handle the leading-space names in the query instead. |

## Threat model

Untrusted: the query-string filters (`page_contains`, `name_contains`, dates). They stay bound parameters and are never interpolated. Trusted: the database.

## Constraints

- **Tests first.** The test suite runs on sqlite in memory (`phpunit.xml`). `tests/Feature/PancakePsid/PancakeReadersPsidTest.php` registers a `CONVERT_TZ` stand-in for sqlite. Collation is binary in sqlite.
  - Write the queries so they still run there, for example a sqlite-only `COLLATE NOCASE` or a driver check, kept as small as possible.
  - Prove every case of S1 with feature tests, on both the page and the export.
  - If a local MySQL is available, also run the S1 cases against it and say so. If not, say so; the reviewer will compare the old and new queries read-only on production before release.
- Put the old and new SQL for one 7-day range in the result: the bare SQL with placeholders, from `toSql()`, so the reviewer can EXPLAIN and compare counts on production.
- The two methods (`index`, `export`) share the same query building: keep them in step, ideally in one private method.
- English for new UI text (none expected). Code comments explain the reason itself. Commit messages carry no Co-Authored-By, Claude-Session or "Generated with Claude Code" lines and name no people.
- No `.env`, server or deploy changes.
- **Worktree:** `C:/mira/mira/projects/likha-wt247`, branch `fix/247-retrieve2-speed`, from the live commit `eeb2e1f2`.
  - Its `vendor/` is a junction to the main checkout. Run tests with the shim bootstrap so they test this worktree: `php vendor/bin/phpunit --bootstrap "C:/Users/Forbeast/AppData/Local/Temp/claude/2e760ef270dbe960/07319e29-2afc-45d1-9b8d-9083b1ed643e/scratchpad/wt247-bootstrap.php" ...`.
  - Never run anything in the main checkout `C:/mira/mira/projects/likha`.

## Done when

1. Feature tests prove every case of S1. The PSID tests in `tests/Feature/PancakePsid/` still pass.
2. The full suite adds no new failures compared with `eeb2e1f2`. The 2 known failures are ExampleTest and ItemTableFitScriptTest; run the suite at the base first to confirm.
3. One migration only, the `created_at` index (online, idempotent, with `down()`).
4. Branch `fix/247-retrieve2-speed` pushed to `origin`. The result lists its head commit.

## Out of scope

Other Pancake pages, `shopMode` logic, the PSID rule, UI changes, timeouts, server settings.

## Report back

In `docs/specs/247-retrieve2-speed.result.md`:
- the plan;
- the done-when evidence (test names and suite counts at base and head);
- the old and new SQL;
- the migration's name;
- files changed;
- the head commit;
- anything to check before release.
