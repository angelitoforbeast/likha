# Result 243: the Storage view and its banner follow the nav settings, not a fixed role

## Plan

One task, risk tier low (kept from the spec). Side: backend, one migration, comments in two layouts.

**Where the code is today (71cf1635)**

- `StorageWarning::canSee` is a fixed role check (CEO or `Marketing - OIC`, case-insensitive). The Storage action (`SystemMonitorController::storage`) and `StorageWarning::banner` (both layouts) use it. The banner checks the role first and then reads the disk through `Cache::remember('storage_warning_disk', 60)`.
- `NavAccess::allows($role, $url)`: CEO always; any other role when an active nav link with that `route_url` is checked (`is_visible`) for the role. One `exists` query per call, not cached.
- The menu in `<x-layout>` is `NavLink::visibleFor($effectiveRole)`, and `/owner/nav-settings` lists every `nav_links` row. So adding a row is enough for both.
- The Chatblast link was added by `2026_10_18_300100_add_chatblast_nav_link.php` (not added to `NavLink::defaultData`), which this follows.

**Change (smallest)**

1. Migration `2026_11_19_100000_add_storage_nav_link.php`, a copy of the Chatblast one: key `storage`, label `Storage`, `/system-monitor/storage`, `fa-solid fa-hard-drive`, `active_pattern` `system-monitor/storage*`, sort order max + 1, visibility rows for `CEO`, `Marketing - OIC`, `Marketing`, `Data Encoder`, `Data Encoder - OIC`, checked for CEO and OIC only. Idempotent; `down()` removes the link and its rows.
2. `StorageWarning::canSee(?User)` becomes `NavAccess::allows(<actual role>, '/system-monitor/storage')`. The Storage action keeps calling it (404 otherwise).
3. `StorageWarning::banner(?User)`: the cached disk is read first and anything under 80 % (or a failed read) returns null before any access check. Only at 80 % or more is the access answer read, cached per role for 60 s (`storage_warning_access:<md5 of the normalized role>`).
4. `NavSettingsController::save()` and `reset()` forget those per-role access answers after saving. Without that, an unchecked OIC would keep the banner for up to 60 s after the owner saves.
5. Comments in `components/layout` and `layouts/chatblast` that name the two roles now point at nav settings. The markup is unchanged, so pages without a banner stay byte for byte.

**Tests** (new `tests/Feature/SystemMonitor/StorageNavAccessTest.php`; spec 242's `StorageTestCase` and `ChatblastStorageBannerTest` seed the nav tables and run the new migration instead of relying on fixed roles)

| Case | Type | Given / When / Then |
|---|---|---|
| S1.1 | happy | After the migration: one `storage` link with the fields above, sorted last, 5 visibility rows, checked only for CEO and OIC; running `up()` again adds nothing; `down()` removes the link and its rows |
| S1.2 | happy | `/owner/nav-settings` (CEO) lists Storage with its URL and checkboxes, CEO and OIC checked |
| S1.3 | happy/negative | Menu: the OIC sees the Storage link; `Marketing` (unchecked) does not; after checking Marketing it does |
| S2.1 | happy | OIC opens the view (200); CEO opens it even with the CEO row unchecked |
| S2.2 | negative | `Marketing`, `Data Encoder`, no role: 404 |
| S2.3 | edge | The CEO unchecks OIC through `POST /owner/nav-settings`: OIC gets 404 and no banner at once; the CEO checks Marketing: Marketing sees the view and the banner |
| S3.1 | happy/negative | At 85 % the banner shows for checked roles only, on both layouts |
| S3.2 | perf | Under 80 %: `banner()` runs no query at all. At 85 %: the first call runs the access query, the next calls within 60 s run none, and after 61 s it runs again |

**Followed as planned.** Amendment 243-1 (below) arrived after the S1–S3 code commit. It added S4, the server stats on the Storage view, done the same way: tests first, then the code.

## Amendments

### Amendment 243-1 (verbatim)

Amendment 243-1 (part of spec 243 from now on; save it verbatim in the result file under "Amendments" and list the change there).

| Change | Who decided, why |
|---|---|
| S4 (P1, new): the Storage view (`/system-monitor/storage`) also shows the "Server stats" section of the System Monitor: memory used/total with %, memory available, swap, CPU load (1/5/15 min) with the number of cores, and uptime. Reuse the CEO monitor's existing server-stats code (SystemMonitorController's server stats method and its cache) instead of writing a second reader; move it into a shared place if needed, without changing what the CEO's `/system-monitor` shows. The section shows with the same nav access as the rest of the Storage view (S2). The database table list and the processes section stay CEO-only on `/system-monitor`. | The owner, 2026-11-08: the marketing OIC reports disk usage and server stats daily from the Storage view. |
| Edge: when a stat cannot be read (no /proc, function disabled), that line says "Not available" and the page still renders. Tests use a fake, never the test machine's real numbers (same seam as the disk). | Same reason. |
| Done-when 1 now also covers S4. | Same reason. |

Everything else in spec 243 is unchanged. Report as before when done.

### What changed for 243-1

- The reader of `SystemMonitorController::getServerStats()` moved unchanged into `App\Services\ServerStorage::serverStats()`, the seam the disk already uses. `emptyServerStats()` holds the defaults (0, or null for the load). `cachedServerStats()` wraps the reader in the monitor's own cache (`sysmon_server`, 10 s). `getServerStats()` now returns `app(ServerStorage::class)->cachedServerStats()`, so `/system-monitor/data` sends the same keys and values as before. A diff of the old and new method bodies, ignoring indentation, shows only the wrapper lines.
- `SystemMonitorController::storage()` passes `server => cachedServerStats()`. `system_monitor/storage.blade.php` shows a "Server stats" card after the disk. It uses the monitor's formats: `used / total MB` with %, available in MB under 1 GB and otherwise GB with 2 decimals, `used / total MB` swap, load `1m / 5m / 15m` with "1 / 5 / 15 min, N cores", and uptime `Xd Yh Zm`.
- "Not available": the shared reader leaves 0 (or null for the load) where `/proc` or `sys_getloadavg` cannot be read. So `memory_total_mb` 0 shows Memory, Memory available and Swap as "Not available", a null load shows CPU load as "Not available", `cpu_count` 0 drops the cores, and uptime 0 shows "Not available".
- Tests: `FakeServerStorage::serverStats()` returns fixed numbers (or the empty shape). The base `tests/TestCase.php` seam returns the empty shape, so no test reads this machine's `/proc`. New `tests/Feature/SystemMonitor/StorageServerStatsTest.php` (8 tests). The table list, file storage and processes stay off the Storage view, and `/system-monitor` stays CEO-only.

## Done-when evidence

All runs were on this Windows machine with Herd's PHP 8.4.16 and PHPUnit 11.5.24, using the database `phpunit.xml` sets (SQLite `:memory:`, cache `array`).

1. **Feature tests prove S1, S2, S3 and S4.** `cmd //c "php vendor\bin\phpunit tests/Feature/SystemMonitor"`
   - Before the code: the S1–S3 tests were committed in `69f9fe78` before any code. The folder gave `Tests: 44, Assertions: 0, Errors: 44`, all in `setUp`, because the Storage link migration did not exist yet. `ChatblastStorageBannerTest` gave `Tests: 10, Errors: 10` for the same reason. The S4 tests were committed in `a0f178bc` before their code, and `StorageServerStatsTest` gave `Tests: 8, Errors: 1, Failures: 6`. The one test that passed was the "tables and processes stay off" case.
   - After: `OK (52 tests, 221 assertions)` for `tests/Feature/SystemMonitor`, and `OK (10 tests, 36 assertions)` for `tests/Feature/Chatblast/ChatblastStorageBannerTest.php`.
   - One test bug was fixed on the way. In the 60 s cache test, an arrow function copied the query log when it was made, so the count stayed 0. It now captures the log by reference. The code did not change for this fix.
   - Case table: `qa/stories.md` Slice 243 (S-243.1.1 to S-243.4.5).
2. **Folders and the full suite.**

   | Run | Before (71cf1635 code) | After (`f3cd1361`) |
   |---|---|---|
   | `tests/Feature/SystemMonitor` | 26 tests, OK (spec 242 result) | OK, 52 tests, 221 assertions |
   | `tests/Feature/Chatblast` | 2096 tests, 2 skipped, OK (spec 242 result) | 2098 tests, 56447 assertions, 0 failures, 2 skipped |
   | `tests/Feature/AdAccount` (nav-settings access tests) | not run separately | OK, 19 tests, 99 assertions |
   | Full suite `cmd //c "php vendor\bin\phpunit"` | `Tests: 4709, Assertions: 78210, Failures: 2, Skipped: 6` (15:09) | `Tests: 4737, Assertions: 78348, Failures: 2, Skipped: 6` (15:50) |

   - The baseline full run was made on this branch with only the two docs commits on top of `71cf1635`, so the code was the same as `71cf1635`.
   - The same 2 failures appear before and after, the known ones: `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` and `Tests\Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`.
   - +28 tests: 18 in `StorageNavAccessTest`, 8 in `StorageServerStatsTest`, 2 in `ChatblastStorageBannerTest`. The other nav-settings folders (PageSettings, CardReconcile, Checklist, Jnt, MetaAds) ran inside the full suite with no new failure.
3. **Exactly one new migration, inserts only.** `git diff --stat 71cf1635 HEAD -- database` lists only `database/migrations/2026_11_19_100000_add_storage_nav_link.php`.
   - `php artisan migrate` cannot build the full schema on SQLite: `2025_10_19_150654_fix_payment_activity_unique_txn` uses the MySQL-only `DELETE t1 FROM … JOIN`.
   - So a scratch SQLite file (outside the repo, in the session scratchpad) was set up like production. The nav tables migration ran for real, and the 330 other migrations were marked as run in `migrations`. `migrate:status` then shows 331 Ran and only `2026_11_19_100000_add_storage_nav_link ... Pending`.
   - `DB_CONNECTION=sqlite DB_DATABASE=<scratch> php artisan migrate --pretend --force` lists only that migration. It prints one query, `select exists (select 1 from "main".sqlite_master where name = 'nav_links' …)`. Pretend mode answers every select with nothing, so the migration's own `Schema::hasTable` guard stops it there. This is a limit of `--pretend` for data migrations (the Chatblast link migration behaves the same).
   - So the real SQL was printed by running `up()`, `up()` again and `down()` on that scratch database with a query listener:
     ```
     -- up()
     select exists (… 'nav_links' …), select exists (… 'nav_link_role_visibility' …)
     select exists(select * from "nav_links" where "key" = ?)  ["storage"]
     select max("sort_order") as aggregate from "nav_links"
     insert into "nav_links" ("key","label","route_url","icon","active_pattern","sort_order","is_active","created_at","updated_at")
       values ("storage","Storage","/system-monitor/storage","fa-solid fa-hard-drive","system-monitor/storage*",39,true,…)
     insert into "nav_link_role_visibility" (…) values (39,"CEO",true,…)
     insert into "nav_link_role_visibility" (…) values (39,"Marketing - OIC",true,…)
     insert into "nav_link_role_visibility" (…) values (39,"Marketing",false,…)
     insert into "nav_link_role_visibility" (…) values (39,"Data Encoder",false,…)
     insert into "nav_link_role_visibility" (…) values (39,"Data Encoder - OIC",false,…)
     -- up() again (idempotent): the three selects only, no insert
     -- down(): select the link, delete from "nav_link_role_visibility" where "nav_link_id" = 39, delete from "nav_links" where "id" = 39
     ```
   - No DDL, and inserts only on `up()`. The sort order is the current max + 1 (39 on the seed-only scratch database; on the live server it is whatever the max is then).
4. **Branch.** `feat/243-storage-nav-access` from `71cf1635`, pushed to `origin`; the head is below.

## Story changes

- No change to the spec's stories or to any Then. Added as Slice 243 in `qa/stories.md` (S-243.1 to S-243.3, plus S-243.4 for amendment 243-1).
- Added inside existing cases: an inactive Storage link closes the view for everyone but the CEO (S-243.2.2). Reset in nav settings also drops the cached answers (S-243.2.3). Without the nav tables only the CEO sees the banner (S-243.3.4).
- Two Slice 242 rows were updated to match spec 243.
  - S-242.1.1: the OIC role is now matched as nav settings match it. Extra spaces are still collapsed, but the case is no longer folded in code. On SQLite the compare is case-sensitive; on the live MySQL it follows the column collation, as for every other nav-settings page. The 242 test row that used the lower-case spelling now uses `  Marketing   -  OIC `.
  - S-242.2.3: other roles still never see the banner. But the cached disk is now read first, so their render does one cached disk read where spec 242 did none. That is what S3 asks: check the disk first, then the access.

## Files changed

- `database/migrations/2026_11_19_100000_add_storage_nav_link.php` (new): the Storage nav link and its 5 visibility rows (CEO and Marketing - OIC checked). Idempotent; `down()` removes it.
- `app/Services/StorageWarning.php`: `canSee()` = `NavAccess::allows(<actual role>, '/system-monitor/storage')`. `banner()` checks the cached disk first, then the per-role access cached 60 s. `forgetAccess()` drops those answers.
- `app/Http/Controllers/NavSettingsController.php`: `save()` and `reset()` call `StorageWarning::forgetAccess()`.
- `app/Services/ServerStorage.php` (243-1): `serverStats()` (the monitor's reader, moved), `emptyServerStats()`, `cachedServerStats()` (`sysmon_server`, 10 s).
- `app/Http/Controllers/SystemMonitorController.php`: `getServerStats()` uses the shared reader. `storage()` also passes the server stats. The comment names nav settings.
- `resources/views/system_monitor/storage.blade.php` (243-1): the "Server stats" card.
- `resources/views/components/layout.blade.php`, `resources/views/layouts/chatblast.blade.php`: comments only (PHP and Blade comments, nothing rendered), so pages without a banner stay byte for byte.
- `tests/TestCase.php`: the base seam also returns empty server stats.
- `tests/Feature/SystemMonitor/StorageTestCase.php`: runs the nav tables migration and the Storage link migration, plus `setStorageAccess()`.
- `tests/Feature/SystemMonitor/FakeServerStorage.php`: fake server stats and a call count.
- `tests/Feature/SystemMonitor/StorageViewTest.php`, `StorageBannerTest.php`: the spacing case keeps the role's case, and the other-roles case expects one cached disk read.
- `tests/Feature/SystemMonitor/StorageNavAccessTest.php`, `StorageServerStatsTest.php` (new).
- `tests/Feature/Chatblast/ChatblastStorageBannerTest.php`: runs the Storage link migration, plus a case for the banner following nav settings on Chatblast and Chatbot.
- `qa/stories.md`: Slice 243 and the two Slice 242 rows.
- `docs/specs/243-storage-nav-access.md`, `docs/specs/243-storage-nav-access.result.md`.

## Head commit

The code head is `f3cd1361` (amendment 243-1, server stats). The branch tip is the docs commit that records this result, right after it.

## For the reviewer before release

- **Release command (migration).** After the code is deployed, run `php artisan migrate --force` on the server. It runs the one pending migration, `2026_11_19_100000_add_storage_nav_link`. To run only that file: `php artisan migrate --force --path=database/migrations/2026_11_19_100000_add_storage_nav_link.php`. Rollback: `php artisan migrate:rollback --path=database/migrations/2026_11_19_100000_add_storage_nav_link.php --force`.
- **Order matters a little.** Between the code deploy and the migration, nobody except the CEO has the Storage view or the banner, because there is no Storage row yet. Run the migration right after the deploy.
- **The menu.** CEO and Marketing - OIC get a new "Storage" link at the end of the top nav. The owner can move it or check other roles in `/owner/nav-settings`. It is not in `NavLink::defaultData()` (Chatblast is not either), so Reset leaves its checkboxes as they are.
- **Cost per page view.** Under 80 %, the banner costs one cache read (the disk) and no query. At 80 % or more, add one cache read per page view for the role's access, and one `exists` query per role per 60 s. A save or reset in nav settings drops the cached access answers, so a change counts from the next page view.
- **Role spelling.** Access now matches the role as nav settings store it, after collapsing spaces (as `NavAccess` does for every page). A role stored with a different case (for example `marketing - oic`) works on MySQL only if the column collation is case-insensitive, which is the MySQL default.
- **"View as".** The banner and the Storage view use the actual role, not the CEO's "view as". The menu link follows "view as", like the rest of the menu.
- **Server stats (243-1).** These are the same numbers and the same 10 s cache entry as the CEO's `/system-monitor`. Opening the Storage view refreshes the entry the CEO monitor reads, and the reverse. On the live server, if `/proc` cannot be read, the lines say "Not available".
- No `.env`, config or server change.
