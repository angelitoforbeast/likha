# Result 242: the marketing OIC sees the server storage, and every screen warns at 80%

## Plan

One task, risk tier low-medium (spec tier kept: the banner sits in the layout every `<x-layout>` page uses, so it must never slow or break a page). Side: backend plus one Blade view and one layout line.

**Where the code is today**

- `SystemMonitorController` (`/system-monitor`, `/system-monitor/data`, routes in `routes/web.php` around line 1239, middleware `web`, `auth`, `allowed_ip`) is CEO-only through `checkCEO()` (trim, collapse spaces, `^ceo$` case-insensitive, else 404). Disk comes from `@disk_total_space('/')` / `@disk_free_space('/')` (cached 30 s); the database total is the `information_schema.tables` SUM; there is no binary log number.
- File storage: the monitor scans `storage/app/uploads/jnt_v2`, `storage/app/uploads/jnt`, `storage/logs` and `storage/framework` with a recursive directory walk (up to 50,000 files, cached 2 minutes). `storage/app` as a whole is not measured, and `storage/logs` only through that walk, which is not cheap; both are left out of the Storage view (S1 allows this).
- Every authenticated Likha page that is not Chatblast/Chatbot uses the `<x-layout>` component, `resources/views/components/layout.blade.php` (170 views). Chatblast/Chatbot pages use `layouts/chatblast.blade.php` (their own app shell, no Likha nav); `layouts/chatbot-tv` is a TV screen. The banner goes in `<x-layout>` only.
- `Marketing - OIC` checks elsewhere normalize the role the same way (`CPPController::checkAccess`, `ItemController`).

**Change (smallest)**

1. `App\Services\ServerStorage` (new, the seam bound in the container; tests replace it): `disk()` returns total/free/used bytes and used % of `/` from the two disk functions, or null when either returns false or total is 0; `databaseBytes()` runs the existing `information_schema` SUM query; `binaryLogs()` runs `SHOW BINARY LOGS` and returns the summed `File_size` and file count; both return null on any error (refused privilege, binary logging off, SQLite) without logging.
2. `App\Services\StorageWarning` (new): `canSee(?User)` = actual employee role is CEO or `Marketing - OIC` after trim + collapse spaces, case-insensitive. `banner(?User)`: null unless `canSee`; reads `ServerStorage::disk()` through `Cache::remember('storage_warning_disk', 60)` (a failed read is cached as false, so a failure is not retried on every page); null under 80 %; tone amber at 80 to under 90, red at 90 or more; text "Server storage is at NN% (FF GB free)." Any exception → null.
3. `SystemMonitorController::storage()` + route `GET /system-monitor/storage` (`system.monitor.storage`, next to the existing two): 404 unless `StorageWarning::canSee`; view `system_monitor/storage.blade.php`, server-rendered, read-only (no buttons, no forms, no JS): disk total/used/free GB and used %, database size, binary logs (size and file count, or "Not available"). `index()` and `data()` stay CEO-only and unchanged.
4. `components/layout.blade.php`: after the top nav, `@php $storageBanner = StorageWarning::banner(Auth::user()) @endphp` and the banner (amber or red) with a link to the Storage view; the page heading keeps its `mt-16` only when no banner is shown (the banner carries it instead).

No migration, no new table, no config or `.env` change.

**Cases and tests** (`tests/Feature/SystemMonitor/StorageViewTest.php`, `tests/Feature/SystemMonitor/StorageBannerTest.php`; disk and database numbers come from a fake `ServerStorage` bound in the container)

| Case | Type | Given / When / Then |
|---|---|---|
| S1.1 | happy | `Marketing - OIC` (also `  marketing  -  oic `) opens `/system-monitor/storage`: 200, sees total/used/free GB, used %, database size, binary log size and file count |
| S1.2 | happy | CEO opens the Storage view (200) and `/system-monitor` as today (200); OIC gets 404 on `/system-monitor` and `/system-monitor/data` |
| S1.3 | negative | `Marketing`, `Encoder - OIC`, no role: 404 on the Storage view |
| S1.4 | edge | `SHOW BINARY LOGS` refused (real `ServerStorage::binaryLogs()` on SQLite throws): the line says "Not available", page 200, no warning/error logged; summing of `SHOW BINARY LOGS` rows tested on the real class with fixed rows |
| S1.5 | edge | read-only: the view has no `<form>` and no `<button>` |
| S2.1 | happy | 85 % used: amber banner "Server storage is at 85% (… GB free)." with the Storage view link, for CEO and OIC |
| S2.2 | happy | 95 % used: the same banner in red |
| S2.3 | negative | 85 % / 95 % used, role `Marketing` / `Encoder`: no banner |
| S2.4 | negative | 79.9 % used: no banner rendered |
| S2.5 | edge | disk read fails (null) or throws: no banner, page renders |
| S2.6 | perf | two renders call `disk()` once (60 s cache); `databaseBytes()` / `binaryLogs()` are never called and no query touches `information_schema` |

**Followed as planned, with one addition.** The full run showed that the existing byte-for-byte pin of `/owner/column-settings/owner-private` (`SharedPagesBasePinTest`) failed for two reasons. First, the layout lines added two whitespace-only lines to every render, even with no banner. Second, the test machine's own disk is 90 % used, so the CEO render showed the real banner. Fixes: the banner block in the layout is written so that, with no banner, the output is byte for byte what it was (the comment is in PHP, the directives start at column 0); and `tests/TestCase.php` binds a `ServerStorage` whose `disk()` is null, so no test reads the machine's disk (the spec 242 tests bind their own fake over it). The pin hash was not changed.

## Done-when evidence

All commands were run on this Windows machine with Herd's PHP 8.4.16 and PHPUnit 11.5.24. The database is the one `phpunit.xml` configures (SQLite `:memory:`, cache `array`). `vendor/` was installed with `composer install --no-scripts --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`, because Horizon needs pcntl, which Windows does not have.

1. **Feature tests for every case of S1 and S2.** `php vendor\bin\phpunit tests/Feature/SystemMonitor`
   - Before the code (tests first): `Tests: 26, Assertions: 45, Failures: 15`. They failed on the 404 of the missing route and the missing banner.
   - After the code: `OK (26 tests, 91 assertions)`.
   - Case table: `qa/stories.md` Slice 242 (S-242.1.1 to 1.5, S-242.2.1 to 2.6).
2. **No new failures against `57aa21b8`.** Full suite `php vendor\bin\phpunit` (one run, no folder split needed, about 12 minutes).
   - Baseline: the branch with only the two docs commits on top of `57aa21b8`, so the code was identical to `57aa21b8` and no extra worktree was needed. Result: `Tests: 4675, Assertions: 78091, Failures: 2, Skipped: 6` (11:47).
   - After (code head `86dc001f`): `Tests: 4701, Assertions: 78182, Failures: 2, Skipped: 6` (12:00).
   - The same 2 failures before and after, both unrelated to this spec: `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` and `Tests\Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`.
   - An intermediate run at `61db68f9` had 3 failures: the third was `SharedPagesBasePinTest`, fixed in `86dc001f` (see Plan).
   - **Send-back (banner on the Chatblast/Chatbot layout).** The full suite was not re-run, since no pin test covers this layout. Instead, the folders that render the Chatbot/Chatblast views and the spec's own folder were run before and after the layout change, each as `php vendor\bin\phpunit tests/Feature/<folder>`. All are green before and after, with no failures:

     | Folder | Before | After |
     |---|---|---|
     | `tests/Feature/SystemMonitor` | OK, 26 tests, 91 assertions | OK, 26 tests, 91 assertions |
     | `tests/Feature/Chatblast` | OK, 2088 tests, 56411 assertions, 2 skipped | OK, 2096 tests (the 8 new tests in `ChatblastStorageBannerTest`), 56439 assertions, 2 skipped |
     | `tests/Feature/PageSettings` | OK, 149 tests, 1221 assertions | OK, 149 tests, 1221 assertions |
     | `tests/Feature/Botcake` | OK, 129 tests, 492 assertions | OK, 129 tests, 492 assertions |
     | `tests/Feature/OrderEncode` | OK, 526 tests, 1555 assertions | OK, 526 tests, 1555 assertions |

     Tests first: before the layout change, `ChatblastStorageBannerTest` ran 8 tests with 3 failures. These were the cases where the banner should show. After the change: OK, 8 tests, 28 assertions.
3. **`git diff 57aa21b8 -- database` is empty**: `git diff 57aa21b8 -- database | wc -l` gives `0`.
4. **Branch.** `feat/242-storage-warning` from `57aa21b8`, pushed to `origin`; the head is below.

## Story changes

No change to the spec's stories or to any Then. Added to `qa/stories.md` as Slice 242. Edge rows added inside existing cases:
- S-242.1.3: `Marketing OIC` without the dash is not the OIC. Normalization is only trim, collapsed spaces and case, as in `checkCEO`.
- S-242.1.4: an unreadable `information_schema` or a failed disk read also shows "Not available".
- S-242.2.1: exactly 80 % is amber, and 89.9 % shows "89%" in amber. The percent is rounded down, so the number shown never reaches 90 before the colour turns red.
- S-242.2.3: the disk is not even read for other roles.

Send-back: no story changes. The added layout (Chatblast/Chatbot) is covered by a new case row S-242.2.7 under the existing S-242.2.

`storage/app` and `storage/logs` are left out of the Storage view (S1 allows this). The existing monitor does not measure `storage/app` as a whole, only two upload subfolders. It measures `storage/logs` only with a recursive directory walk of up to 50,000 files, which is not cheap.

## Files changed

- `app/Services/ServerStorage.php` (new): the seam. `disk()` uses `disk_total_space` / `disk_free_space('/')`, or null. `databaseBytes()` is the System Monitor's `information_schema` SUM. `binaryLogs()` sums `SHOW BINARY LOGS` and counts the files. Both return null on any error, without logging.
- `app/Services/StorageWarning.php` (new): `canSee()` (CEO or Marketing - OIC by the actual role) and `banner()` (cached 60 s, 80 % amber, 90 % red, never throws).
- `app/Http/Controllers/SystemMonitorController.php`: new `storage()` action. `index()` and `data()` are unchanged and stay CEO-only.
- `routes/web.php`: `GET /system-monitor/storage` (`system.monitor.storage`), in the same group as the monitor (`web`, `auth`, `allowed_ip`).
- `resources/views/system_monitor/storage.blade.php` (new): read-only view rendered on the server (no JS, no buttons, no forms).
- `resources/views/components/layout.blade.php`: the banner after the top nav. The heading keeps its `mt-16` only when there is no banner.
- `resources/views/layouts/chatblast.blade.php` (send-back): the same banner on the Chatblast/Chatbot screens, above the problems banner.
- `tests/Feature/Chatblast/ChatblastStorageBannerTest.php` (new, send-back): the banner on `/chatblast` and `/chatbot/pages`.
- `tests/TestCase.php`: binds a `ServerStorage` with no disk reading for all tests.
- `tests/Feature/SystemMonitor/StorageTestCase.php`, `FakeServerStorage.php`, `StorageViewTest.php`, `StorageBannerTest.php` (new).
- `qa/stories.md`: Slice 242.
- `docs/specs/242-storage-warning.md`, `docs/specs/242-storage-warning.result.md`.

## Head commit

Code head `9e4d3584` (the send-back: banner on the Chatblast/Chatbot layout). The branch tip is the docs commit that records this result, right after it.

## For the reviewer before release

- **Where the banner renders.** In `resources/views/components/layout.blade.php`, right after the closing `</nav>` of the fixed top nav and before the page heading `<header>`. That is the `<x-layout>` component used by every Likha page except the Chatblast/Chatbot app shell and the TV screen. The banner has `mt-16` to clear the fixed nav. On pages that hide the heading (checklist, jnt_rts, prompt-generator, boardroom), those pages add their own `mt-16`, so there is an extra 4 rem gap under the banner while it shows. The layout is unchanged otherwise.
- **Chatblast and Chatbot screens (added on send-back).** The banner is also in `resources/views/layouts/chatblast.blade.php`, right after the app bar's `</header>` and directly above the existing problems banner, before `<main>`. It uses the same `StorageWarning::banner`, the same roles and the same 60 s cache, and is styled like the problems banner (one line, full width). The TV screen (`layouts/chatbot-tv`, used by `/chatbot/live`) is left without it. Without a banner the Chatblast layout renders byte for byte as before. This was checked by rendering `/chatblast` and `/chatbot/pages` as `Marketing` with this layout and with the layout at `57aa21b8`: the two outputs are identical (233,243 bytes). No pin test covers this layout. This layout already uses the inline `@php(...)` form, and a block `@php … @endphp` added after it is swallowed by Blade (a parse error, found and fixed in the test run), so the banner line uses the inline form too.
- **How long the check takes.** Per page view, for CEO and OIC only: one cache read of `storage_warning_disk` from the configured cache store, plus a role check on the already loaded profile. Once per 60 s per cache store, the two disk functions run. Measured on this machine: 0.024 ms per `disk()` call (average of 1,000 calls; on Linux it is a `statvfs`). Other roles: no cache read and no disk read. No directory scan and no database query are run for the banner (test S-242.2.6).
- **Binary logs.** "Not available" on the live server until the owner grants REPLICATION CLIENT to the app's database user (out of scope here). The query is `SHOW BINARY LOGS` and runs only when the Storage view is opened.
- **Database size.** The Storage view runs the `information_schema` SUM on every open (not cached). Only two roles open it, and on demand.
- Deploy is a code-only release: no migration, no config, no `.env`.
