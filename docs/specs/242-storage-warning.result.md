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

## Done-when evidence

## Story changes

## Files changed

## Head commit

## For the reviewer before release
