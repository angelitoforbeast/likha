# 264: An ads report upload with a long headline no longer fails, and a failed upload shows why

Weight: bounded. Risk tier: medium (live import of ad numbers used by reports; the migration runs on live MySQL tables of ~350k and ~1M rows). Size: small.

## Why
On /ads_manager/report, uploads of Ads Manager .xlsx files fail. The campaign "Francesca Lim - Eloria Cream 299 - 11.11.26 - ( 113 )" has a Headline of 481 characters; `ads_manager_reports.headline` is `varchar(255)`, so MySQL throws `SQLSTATE[22001] 1406 Data too long for column 'headline'` inside `upsertChunk`. `ProcessAdsManagerReportsUpload::handle()`'s catch(\Throwable) sets status `failed` but no `error_message`, so the Upload Logs table shows FAILED with an empty "Error message" column. One staff member re-uploaded the same file 11 times today, all failed. Rows of chunks before the bad row were saved; the bad chunk and everything after it were not.

Live facts (checked by the reviewer): MySQL 8.0.46, utf8mb4, ROW_FORMAT=Dynamic; no index on headline; the row's max size is ~13.4 KB, so a wider varchar is fine. `ad_campaign_creatives.headline` is already `text`.

## Stories
S1 (P1) A staff member uploads an Ads Manager report whose Headline is longer than 255 characters.
- .1 happy: a row with a 481-character headline (with emoji, multibyte) imports; status done; the stored headline equals the file's headline exactly.
- .2 edge: a headline longer than 1000 characters imports; it is stored cut to the first 1000 characters (mb-safe), and the upload is done, not failed.
- .3 regression: rows with short headlines import exactly as before (inserted/updated counts unchanged by this change).

S2 (P1) An upload fails for any other reason inside processing.
- .1 the Upload Logs row has status failed AND a non-empty `error_message` that says what went wrong in one short line, e.g. "Import failed: SQLSTATE[22001]: ... Data too long for column 'headline' at row 1". Cut the message before " (Connection:" so the SQL and the ad text are not stored, and cap it to the column's length (check the error_message column type in the migrations).
- .2 processed/inserted/updated/skipped are still saved as today; the exception is still rethrown (failed_jobs keeps the full trace) as today.
- .3 the existing "Missing required column(s)" message is unchanged.

## Decisions (do not reopen)
| Decision | Why |
|---|---|
| Widen `ads_manager_reports.headline` to `varchar(1000)`, not `text` | varchar(255)->varchar(1000) in utf8mb4 keeps a 2-byte length prefix, so MySQL 8 does it in place without copying a 1M-row live table. Put `ALGORITHM=INPLACE, LOCK=NONE` on the MySQL statement (raw `DB::statement` guarded by `DB::getDriverName() === 'mysql'`; for other drivers use the schema builder `->change()` or skip if the test DB can't change it — make the tests pass on whatever driver phpunit.xml uses). Keep it nullable and keep the column's current collation/charset. Down migration back to 255 is fine. |
| The importer also cuts headline to 1000 chars (mb_substr) before saving | so a longer headline never fails a whole upload again |
| Only headline is widened | it's the only column that failed; don't widen others |

## Constraints
- Smallest change: one migration, the importer's headline cut, and the catch block's error_message. No refactors, no other files unless a test needs a fixture.
- Commit messages: Conventional Commits, e.g. `fix(ads-upload): a long headline no longer fails the upload, and a failed upload shows why (264)`. NO Co-Authored-By, Claude-Session or "Generated with" lines, and no person names.
- Save this spec text as docs/specs/264-long-headline.md and a short result as docs/specs/264-long-headline.result.md (what changed, test commands and output summary, migration statement), committed with the work.

## Second branch: Incepxion
Incepxion runs an older fork of the same app. Its deployed commit is `df0abc88` (local branch `release/260-incx` in this repo; it is NOT on origin). Its `app/Jobs/ProcessAdsManagerReportsUpload.php` differs from main (about 111 lines fewer), but the catch block and the headline save exist there too.
After the main branch is done and green: create branch `release/264-incx` from `df0abc88` (`git branch release/264-incx df0abc88` then work in a second worktree `C:\mira\mira\projects\likha-wt249-incx` via `git worktree add`), and apply the same three changes adapted to that file (cherry-pick if it applies cleanly, otherwise port by hand). Same migration file name. Run the related tests there too. Commit with the same style.

## Allowed
composer install (if vendor is missing; or copy nothing — just run composer install), php artisan test (filter to the ads upload tests plus the full suite once on main to show no new failures — if the full suite already has failures on a680c168, list them as pre-existing), git add/commit, `git push -u origin fix/264-long-headline`, `git push -u origin release/264-incx`. Do NOT merge, do NOT open a PR, do NOT push to main.

## Done when
- Tests for S1.1, S1.2, S1.3, S2.1, S2.2 exist and pass on fix/264-long-headline; the full suite adds no new failures.
- The same changes are committed on release/264-incx (from df0abc88), its related tests pass.
- Both branches pushed to origin.

## Report back (final message)
Both branch names with commit SHAs, the exact migration SQL that will run on MySQL, test results (counts, any pre-existing failures), and anything you could not do. Budget: 2 attempts per failing step; stop and report if stuck.
