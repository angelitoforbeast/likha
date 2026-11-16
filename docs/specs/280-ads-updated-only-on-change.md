# 280 Ads rows keep their update time when nothing changed

## Why
On /ads_manager/cpp, hovering a page's Amount Spent or Orders cell shows "Ads spend updated <time>". That time is `MAX(ads_manager_reports.updated_at)` per day and page (`app/Http/Controllers/CPPController.php::freshnessByKey`, about L251). The Meta API sync (`app/Services/MetaAds/Sync.php`, schedule 10:00/15:00/18:45, and the "Sync now" button) re-reads the last 7 days on every run, and an XLSX upload also covers several days. Both write through the same upsert in `app/Jobs/ProcessAdsManagerReportsUpload.php` (about L709-805), which always sets `updated_at = $now`, even when every value is the same. Production evidence, 2026-11-16: the 15:00 sync touched about 200 rows on each day from Nov 10 to Nov 16, so hovering Nov 12 says "Ads spend updated Nov 16, 3:03 PM" although Nov 12's spend did not change. The team reads this as the spend changing without anyone syncing.

## Decisions already made (do not reopen)
| Decision | Reason |
|---|---|
| `updated_at` of an ads_manager_reports row moves only when a value written by the upsert actually differs from what is stored | The hover must mean "the numbers changed at this time" |
| A brand-new row (insert) still gets created_at and updated_at = now | Unchanged |
| Rows already bumped in production are not repaired | No reliable old time exists |
| The rows-updated counters in the upload log may keep counting as today, or count only changed rows; say which in the result | Not visible to the owner as a problem |

## Stories
1. Re-running the same data (same upload twice, or an API sync that returns the same numbers) leaves `updated_at` of every unchanged row as it was.
2. A row whose spend (or any other written column) changes gets `updated_at = now`.
3. The CPP hover therefore shows, for a past day whose numbers did not change, the time those numbers last changed.
4. The monotonic protection (rows refused when a number goes down) behaves exactly as today.

## Constraints
- Smallest change in the shared upsert. Compare against the stored row (it is already loaded for MONOTONIC_FIELDS; widen the select to the written columns if needed, keep it one query per chunk). Watch type differences (decimal strings vs floats, null vs '') so equal values are not treated as changed. Grep for other readers of `ads_manager_reports.updated_at` and note them in the result.
- A feature test per story that fails before the change and passes after (tests use sqlite; see existing tests under `tests/Feature/MetaAds` and any upload tests). The whole suite adds no new failures: state base and branch counts (the base has 3 known failures in `tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php`).
- PHP: `C:/Users/Forbeast/.config/herd/bin/php84/php.exe` (php is not on PATH). If `vendor/` is missing in the worktree, create a junction: `cmd //c mklink /J vendor ..\\likha-wt277l\\vendor` from the worktree root (do not commit it).
- Code comments follow the file's style (Taglish is fine). Commit messages and code never mention who asked or any assistant; no Co-Authored-By lines.
- Never deploy, never touch a server, never push to main. When done: `git push origin fix/280-ads-updated-only-on-change`.
- Budget: small job, at most 2 attempts per step. If it needs much more than the upsert file and tests, stop and write why under Questions.

## Report back
Write `docs/specs/280-ads-updated-only-on-change.result.md` and commit it: what changed per story, test output (red then green), full-suite counts, head commit, migrations (none expected), and other readers of updated_at. Your final message: head commit sha and a 3-line summary.
