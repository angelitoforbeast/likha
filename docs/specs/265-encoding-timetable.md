# 265: Encoding timetable — what time encoding, J&T upload and the waybill download finished, per day

Weight: bounded (one new read-only page). Risk tier: low-medium (read-only reports on live tables; must not slow the live DB). Size: medium. Budget: 2 attempts per failing step; if blocked, stop and report.

## Why
The owner wants to see, per day, what time the team finished each step of the order work: encoding, uploading to J&T, and downloading the file (waybill print). Today nobody can see it in one place. It must be visible to CEO, Marketing and Marketing - OIC only.

## Facts (checked on live data 2026-11-12; times are Asia/Manila wall-clock, stored without timezone)
- Work done on day D processes orders of order date D-1 (e.g. on 2026-11-12 staff validated orders with macro_output.ts_date = 2026-11-11 from 08:10 to 10:21, booked J&T 09:47–10:41 with filters.date 2026-11-11, printed waybills 10:55–11:24 with date 2026-11-11). So the page is keyed by ORDER DATE and shows the actual clock time (with the date if it is not the next day).
- **Encoding** = Checker 1 actions in `staff_events` with `kind IN ('checker.validate','checker.edit','checker.status')`, `source='checker_1'`, `ref` = `macro_output.id` (100% join on live). `macro_output.ts_date` (date) and `macro_output.PAGE` (page name). Today: 8,696 validate, 427 edit, 161 status rows. Written by app/Support/StaffEvents.php; MacroOutputController updateField/bulkUpdate. `staff_events` indexes: (created_at), (user_id, created_at); no index on kind/ref. Rows older than 90 days are pruned (app/Console/Commands/PruneStaffEvents.php), so encoding times exist only for the last ~90 days; older days show "wala nang record".
- **J&T upload** = in-app J&T API booking: `jnt_batch_runs` (filters JSON with date and page, status, started_at, finished_at, created_by), created by JntOrderUiController::createBatch (routes/web.php ~681), finished in app/Jobs/CreateJntOrder.php. ~1,488 runs in 30 days (one per page per day).
- **Waybill download** = bulk waybill print: `jnt_waybill_print_runs` (date, filter_value = product filter e.g. "2 x TUMBLER", status, created_by, created_at, finished_at) from JntWaybillPrintController / app/Jobs/BuildJntWaybillBulkPrint.php. ~1,263 runs in 30 days. The PDF download itself is not logged; the run's finished_at is the time the file was ready.
- **Excel download** of orders: `downloaded_macro_output_logs` (timestamp = order date filter, page, downloaded_by name, downloaded_at), written in MacroOutputController::download. Rare (4 in 30 days). Show it as an extra column only.
- Roles: `employee_profiles.role` values CEO, Marketing - OIC, Marketing, Data Encoder, Data Encoder - OIC. Page access lives in DB rows: `nav_links(route_url, is_active)` + `nav_link_role_visibility(nav_link_id, role, is_visible)`, enforced by app/Support/NavAccess.php (CEO always allowed) and edited at /owner/nav-settings. Follow the precedent of spec 243 (docs/specs/243-storage-nav-access.md) and the existing /encoder/summary page (MacroOutputController::summary, view macro_output.summary) for layout, nav and access.

## Stories
S1 (P1) The owner opens /encoder/timetable.
- .1 happy: a table, newest order date first, default last 14 order dates (date-from/date-to filter, max 62 days). Columns per order date: Encoding (start – finish time, orders touched, who finished last), J&T upload (finish time = latest finished_at of that date's runs; runs finished/total; flag any run not finished or failed), Waybill (finish time = latest finished_at of that date's print runs; runs count; flag unfinished), Excel download (latest downloaded_at, by whom, or "—"). Also the gap in minutes between the steps (encoding finish → J&T finish → waybill finish).
- .2 each date row expands to a per-page breakdown: per page, encoding finish and J&T finish (pages from macro_output.PAGE and jnt_batch_runs filters.page), sorted by latest finish; waybill per product filter_value in its own small list.
- .3 a summary line above the table for the shown range: average finish time per step and the latest (worst) day per step; days whose finish is over 30 minutes later than the average are highlighted.
- .4 a time on a later day than order date + 1 shows its date too (e.g. "Nov 14 09:12").
- .5 empty: a date with no records shows "—"; dates older than the oldest staff_events row show "wala nang record" in Encoding.
S2 (P1) Access.
- .1 CEO, Marketing, Marketing - OIC can open it and see it in the nav (data migration inserting the nav_links row '/encoder/timetable' and visibility rows: CEO 1, Marketing 1, Marketing - OIC 1, Data Encoder 0, Data Encoder - OIC 0; idempotent, down removes them).
- .2 Data Encoder and Data Encoder - OIC get 403; guests are redirected to login.
S3 (P1) Performance on live tables.
- .1 every query is a plain SELECT (never CREATE TABLE ... AS SELECT, no temp tables, no writes); staff_events is filtered by a created_at range (order dates from-1 day to to+3 days) so it uses the created_at index, joined to macro_output by primary key, and grouped in SQL (not loading 9k rows per day into PHP). Show the EXPLAIN reasoning in the result.
- .2 the whole page renders in under 2 seconds for 14 days on a seeded test with realistic volume (state what you measured; a test asserting query count is fine).

## Decisions (do not reopen)
| Decision | Why |
|---|---|
| No new table, no new columns, no indexes | read from existing tables; the owner's rule is lean schema |
| Keyed by order date | that is how the team works a day's orders |
| Waybill = jnt_waybill_print_runs.finished_at | the print file is the daily download; the macro_output Excel download is rare |
| Access through nav_links rows | precedent; the owner edits access at /owner/nav-settings |

## Constraints
- Smallest change: one controller (or a method on an existing encoder controller), one Blade view, routes, the nav data migration, tests. No refactors.
- Interface text in the style of nearby pages (English/Taglish as they use).
- Commit messages: Conventional Commits, e.g. `feat(encoder): a timetable shows when encoding, J&T upload and waybills finished each day (265)`. NO Co-Authored-By, Claude-Session or "Generated with" lines, no person names.
- Save this spec text as docs/specs/265-encoding-timetable.md and a short result as docs/specs/265-encoding-timetable.result.md (what changed, test commands and output, EXPLAIN notes, screenshots not needed), committed with the work.

## Allowed
composer install, php artisan test (the new tests plus the full suite once; list pre-existing failures on db70b9c1 as pre-existing), vendor/bin/pint on changed files, npm run build only if the view needs new assets, git add/commit, `git push -u origin feat/265-encoding-timetable`. Do NOT merge, do NOT open a PR, do NOT push to main, do NOT deploy.

## Done when
- Tests for S1.1, S1.2, S1.3, S1.5, S2.1, S2.2, S3.1 exist and pass; the full suite adds no new failures.
- Branch pushed to origin.

## Report back (final message)
Branch and commit SHAs, the route and view, the exact SQL of the main queries, test results (counts, pre-existing failures), the nav migration, and anything you could not do.
