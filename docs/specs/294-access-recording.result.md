# 294 result: Access: close /roles, record would-blocks

Base: live 8164024f. Branch `fix/294-access-recording`.

## Plan

Code read first: `bootstrap/app.php` (how `RecordStaffActivity` is appended to `web`), `RecordStaffActivity`
(never-throw style), `RoleController` and its three routes (web.php 1206-1208, inside the `web,auth,allowed_ip`
group), `ChecklistController::isCeo()`, `NavAccess` (role trimming/collapsing), `NavLink` (+ the nav seed
migrations, e.g. `finance/supply*` visible to CEO only), `AppSetting`, the checklist range report view (look).

1. **AC-1 /roles CEO only.** Tests first (`tests/Feature/Access/RolesCeoOnlyTest.php`): CEO opens index, stores and
   updates; OIC, staff and a user without a profile get 403 on each of the three routes and nothing changes.
   Code: one private `abortUnlessCeo()` in `RoleController` called from all three actions
   (`strtoupper(trim(role)) === 'CEO'`). No change to `routes/web.php` for this, so no route conflict with siblings.
2. **AC-2 page-access rule, record mode.**
   - `config/access.php`: the open list (Laravel `Str::is` path patterns), one comment per entry.
   - Migration `create_access_records_table` (`user_id`, `role`, `path_pattern`, `decision`, `day`, `hits`,
     `last_at`, timestamps; unique on user/path_pattern/decision/day).
   - `App\Support\AccessRule`: `mode()` (app_settings `access.mode`, default `record`), the nav map (active links,
     pattern, route_url, label, visible roles; one query, cached 60 s with the table checks), `decide(role, path)`
     in the spec's order (CEO → open list → longest nav match → visible? → else unmapped) and `record()` (one
     upsert that increments `hits`; skipped when the table is missing).
   - `App\Http\Middleware\RecordPageAccess`, appended to `web` after `RecordStaffActivity`. Only signed-in GETs
     with a matched route that are not ajax/JSON and not on the open list are looked at. Record mode: the page is
     served, then a would-block / unmapped is counted unless the response is JSON or a file download. Enforce
     mode: a would-block answers 403 with `resources/views/access/blocked.blade.php`. Every step of the rule is in
     try/catch: a failure logs a warning and allows.
   - Tests (`PageAccessRecordingTest.php`): Marketing on `/finance/supply` → one row, again → hits 2; CEO → no row;
     open-list page → no row; enforce → 403 page and CEO not blocked; unmapped page → `unmapped`; query string never
     stored, route pattern stored (`{id}`); JSON and API untouched; rule throwing → page still 200; query count
     (JSON route adds 0 queries, warm-cache page adds no nav query).
3. **AC-3 report.** `/owner/access-report` (`AccessReportController`, CEO only, route next to `/owner/nav-settings`),
   view in the checklist report style: mode line, days (1-30, default 7), role and decision filters, rows per
   role+page+decision with nav link label or "No nav link", hits, distinct people, last time. Tests
   (`AccessReportTest.php`): CEO sees rows and mode line; others 403; filters; day window; days clamped.
4. Result: open list with reasons, unmapped page routes on a fresh seed (from `route:list` + the seeded nav
   links), migration, merge danger.
