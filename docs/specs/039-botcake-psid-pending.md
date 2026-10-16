# Brief 039: Botcake PSIDs wait in a table until their order arrives; the import runs by itself and cleans the sheet

## Why

Customers' Messenger PSIDs reach a Google Sheet (configured in `botcake_psid_settings`; columns A page, B Facebook name, C PSID, D date, J status). `ImportBotcakePsidFromGoogleSheet` (started only by a button, `BotcakePsidGsheetController::import`) writes the PSID onto `macro_output.botcake_psid` for rows with the same `PAGE` and `fb_name`, and writes J = "imported" or "not existing". It reads only rows whose J is blank, so a PSID that arrives before its order is marked "not existing" and never retried: that order is never messaged. The owner wants no PSID lost, the import automatic, and the sheet kept small.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| `macro_output` and its `botcake_psid` column stay as they are; a matched PSID is written there as today | Owner: keep macro_output as is, fix the new part |
| New table (e.g. `botcake_psids`): page, fb_name, psid, sheet date, setting id, status `waiting` / `matched` / `expired` / `conflict`, first_seen_at, matched_at, matched_rows, last_checked_at. Every PSID row the import processes is recorded there (matched ones too), so it is the full history once the sheet rows are deleted. Rows are never deleted; only the status changes | Owner: no deleting; history |
| Import, per unprocessed sheet row: order exists (same rule as today: PAGE + fb_name, exact then case-insensitive) → write to macro_output as today, record `matched`, J = "imported"; no order yet → record `waiting`, J = "waiting" | Owner's design |
| At the start of every import run (even with no new sheet rows): try every `waiting` row again; match → write to macro_output (only rows whose `botcake_psid` is empty), `matched`; still none → stays `waiting`; `waiting` for more than 30 days → `expired` (no longer tried). No other periodic retry | Owner: retry at each PSID import, not every 30 minutes |
| Reorders: during each run, a `macro_output` row with empty `botcake_psid` whose PAGE + fb_name already has exactly one distinct PSID on other macro_output rows gets that PSID (limit to rows from the last 30 days so the pass stays cheap) | Repeat customers keep getting messages |
| Conflict: when the same PAGE + fb_name has two or more different PSIDs (in the table, or the incoming one differs from one already on that customer's macro_output rows), attach nothing for it, mark `conflict`, J = "conflict" | Better no message than a waybill and COD sent to the wrong person |
| The import runs by itself every hour (all settings, no cutoff), never two at once (`withoutOverlapping` or the existing run status), plus the existing button | Owner: "gawin mo ng kusa" |
| Every day at 03:00 Manila: first delete from the sheet every row already recorded in the new table (J = imported / waiting / conflict, and page + name + PSID + date equal to the recorded row), then run the import. Real row deletion (Sheets `batchUpdate` `deleteDimension`), bottom to top, in as few requests as possible, re-reading the sheet right before; never the header, never a row with blank J, never a row whose content differs from the record. Log how many were deleted | Owner: delete the processed rows daily, before the PSID import; quiet hour because Botcake keeps appending |
| One-time backfill command (dry-run by default, `--apply` to write): read every existing J = "not existing" row of every setting into the table as `waiting` and set its J to "waiting", then run the matching once. The next 03:00 run deletes them | Owner: old "not existing" PSIDs go to the table too |

## Do

- Part A (commit): migration and model for the table; the import changes above (record, waiting, retry at run start, expiry, reorder copy, conflict); the backfill command. Keep the existing run/event tracking (`botcake_psid_import_runs` / `_events`) and add the new counts (waiting, matched from waiting, expired, conflicts, reorder copies).
- Part B (commit): the hourly schedule and the 03:00 delete-then-import in `routes/console.php` (Asia/Manila, like the other entries). The import today seeds its scan from the sheet's K1 cell and `next_scan_from`; deleting rows shifts row numbers: make the scan correct after deletions (e.g. scan all rows with blank J, the sheet being small) and say how in the result.
- The button page keeps working and shows the new counts.

## Tests

With a fake Sheets client: a PSID with an existing order → macro_output written, `matched`, J imported; without an order → `waiting`, J waiting, macro_output untouched; the order arrives, next run (no new sheet rows) → attached, `matched`; 31 days waiting → `expired`; two PSIDs for one page + name → `conflict`, nothing written; reorder copy; a row already holding a PSID is not overwritten from waiting; the 03:00 delete removes only recorded rows with matching content, bottom to top, keeps header, blank-J rows and changed rows; the backfill dry-run writes nothing and `--apply` records and marks; the schedule entries exist with the right times. Red first where natural. The whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

Sending messages, the chatblast sheet and its Apps Script, Botcake API calls, the export to the chatblast sheet, any UI beyond the counts.

## Final message

Commits; the table and statuses as built; how the scan works after deletions; the schedule entries; the backfill command and what its dry-run prints; test lines and suite result; what to run on the server after release (migrate, backfill dry-run then apply); anything the brief got wrong.
