# Spec 228: the order and J&T status summaries use indexes, and minute jobs never stack

**Project:** Likha (same tree is deployed as the Incepxion site). **Shape:** bug · **Weight:** bounded · **Risk tier:** medium (reports and scheduler only; no sending logic)

> Committed with the work as `docs/specs/228-summaries-and-stacking-jobs.md`. Names no people and no decision ids.

## Bug (seen on the Incepxion site 2026-11-04)

The Incepxion server ran at load ~40 on 4 CPUs with MySQL at ~180% CPU; `/encoder/summary` (order summary) and the J&T status summary answered 504.
- `MacroOutputController` (~line 950) filters `macro_output` with one `orWhere('TIMESTAMP', 'like', "%{d-m-Y}")` per day. A leading-wildcard LIKE cannot use an index, so every open scans all 1.67 M rows (3 GB); three copies ran 9+ minutes at once. `macro_output` already has indexed `ts_date` (`macro_output_ts_date_index`, `macro_output_ts_date_page_idx`).
- `FromJntController` (~line 160) scans `from_jnts` (2.18 M rows) with `status_logs like "%{date}%"` inside a `submission_time` window; observed 15–21 minutes.
- Scheduled commands in `routes/console.php` (several `everyMinute`/`everyTwoMinutes`/`everyFiveMinutes`, and `chatbot:replied-backfill`) start again while the previous run still holds slow queries; three `replied-backfill` and several copies of the `chatbot_ai_requests` / `botcake_send_requests` scans were stacked at once.

## Stories

**S1 (P1)** The order summary selects by `ts_date` (`whereIn('ts_date', $ymdList)` or `whereBetween`) instead of the TIMESTAMP LIKEs, giving the same rows. Verify `ts_date` is filled for every row the old filter matched (it is written on insert/update — check where; if some rows lack it, fall back to the LIKE only for rows with `ts_date` null, or backfill in a migration-free command). Same totals as before on test data with mixed rows.

**S2 (P1)** The J&T status summary narrows `from_jnts` by an indexed column first (check existing indexes on `submission_time`, `signingtime`; add one only if missing — say so) and keeps the `status_logs` check as a filter on the narrowed set; same results on test data. If the date semantics need the LIKE, keep it but only after the index narrows the window.

**S3 (P1)** Every scheduled command in `routes/console.php` that runs at a minute-level interval, and `chatbot:replied-backfill`, uses `->withoutOverlapping(<minutes>)` (expiry sized to the job, e.g. 10–60 min) unless it already guards itself; list each one in the result. `chatbot:replied-backfill` also stops itself after a time budget (e.g. 20 min) and resumes next run.

**S4 (P2)** The slow scans behind the minute jobs seen in the process list — `select id from chatbot_ai_requests where created_at between (now-7d) and (now-2d) and status in ('resumed'…)` and `select * from botcake_send_requests where kind='chatbot' and status in ('done','failed') and updated_at …` — find their callers and check they use an index (composite index on the filtered columns if missing; measure with EXPLAIN on SQLite/MySQL syntax notes). Report what you changed or why not.

## Constraints

Base: Likha live `9a1cfd8e` (Incepxion runs the same code family; the release to Incepxion will be merged onto its own main). Branch `fix/228-summaries-and-stacking-jobs`. Full suite: no new failures (2 known: ExampleTest, ItemTableFitScriptTest). New indexes only as migrations that are safe online on MySQL 8 (`ALGORITHM=INPLACE, LOCK=NONE`), stated in the result. Commit messages and comments name no people, sessions or decision ids; no attribution lines. Don't push, deploy or touch servers.

Budget: medium; at most 2 attempts per step.
