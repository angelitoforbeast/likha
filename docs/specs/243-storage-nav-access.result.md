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

## Done-when evidence

## Story changes

## Files changed

## Head commit

## For the reviewer before release
