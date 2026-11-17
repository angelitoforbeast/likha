# 294 — Access: close /roles now, record who each page would block

## Why
An inventory of live main (2026-11-18) found about 70 page controllers with no role check: anyone signed in on an allowed IP can open them by URL, whatever the nav shows. `/roles/index` and its store and update routes have no check at all: any signed-in user can create or rename roles. The owner's plan ("LDT Virtual Office", Likha overhaul) makes a hidden page a blocked page, in two steps so nobody loses a page they really use: first **record** for a week who would be blocked, then enforce on his yes. This spec is step one, plus the `/roles` fix at once. His words: "gomo na lahat", "deploy na agad".

## Stories and cases
**AC-1 /roles is CEO only, now (P1).** `GET /roles/index`, its store and update routes (web.php around 1206-1208, `RoleController`) answer 403 for anyone but the CEO (same CEO rule as `ChecklistController::isCeo()`: `strtoupper(trim(role)) === 'CEO'`).
- H: CEO opens and saves as before. N: OIC, staff, a user without a profile → 403 on each route (test each).

**AC-2 One page-access rule, recording only (P1).** A middleware appended to the `web` group (as `RecordStaffActivity` is in `bootstrap/app.php`) decides for every signed-in GET request that renders a page whether the user's role may open it, and in **record** mode never blocks: it only records a would-block.
- The rule, in order: CEO → allowed. A path on the **open list** → allowed (`/`, `/profile`, `/checklist*`, `/astra*`, `/chat*`, `/order-encode*`, login, logout, register, assets, API and JSON polling routes; keep the list in one config file with a comment per entry). A path that belongs to a nav link (match `nav_links.active_pattern`, else `route_url` as a prefix; the longest match wins) → allowed when that link is visible for the role (`nav_link_role_visibility`, role compared after trimming and collapsing spaces, as `NavAccess` does). A path no link covers → allowed in record mode and recorded as `unmapped`, so the owner sees every page that has no home yet.
- Pages that already check access in their controller keep doing so; the middleware never loosens them.
- Mode in `app_settings`: `access.mode` = `record` (default) or `enforce`. `enforce` answers 403 with a plain English page ("You don't have access to this page. Ask your OIC or the owner.") and is covered by tests, but this spec ships with `record`.
- Records: a small table `access_records` (`user_id`, `role`, `path_pattern` (the route's URI pattern, not the raw URL), `decision` (`would_block` / `unmapped`), `day` (Manila date), `hits`, `last_at`), unique on (user, path_pattern, decision, day), incremented, never one row per request. A separate table because `staff_events` feeds the staff status and the office, and must not gain non-work rows.
- H: a Marketing user opens `/finance/supply` (not visible for Marketing) → allowed, one `would_block` row; the same page again → `hits` 2. E: CEO → no row. E: an open-list page → no row. E: `enforce` mode → 403 page, and the CEO is never blocked.
- Performance: the nav map and visibility are read once per request at most and cached for 60 seconds; the middleware adds no query to API routes (test the query count on one page).

**AC-3 The CEO sees the report (P1).** `/owner/access-report` (CEO only): for the last 7 days (choosable 1-30), one row per role and page: page pattern, the nav link it maps to (or "No nav link"), would-block hits, distinct people, last time; filters by role and decision; a short line on top saying the mode ("Recording only: nobody is blocked yet"). English UI in Likha's look (white card, table as the checklist report pages do).

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Record a week before blocking | The owner's plan: nobody loses a page they really use |
| `/roles/*` CEO only immediately | Any user can change roles today |
| Nav visibility rows are the access list | One list for what you see and what you may open; it exists already |
| A separate small counter table, not `staff_events` | `staff_events` drives the staff feed and office status |

## Constraints
- No change to what any page shows; no change to `NavLink`, the nav UI or the role names (later specs).
- Guard the new table with `Schema::hasTable` in code paths, as the codebase does.
- English UI. Comments in the language and style of the file around them.
- Feature tests in a new `tests/Feature/Access/` folder; the full suite adds no new failures compared with base 8164024f (list the base failures).

## Threat model and risk
New: a middleware on every web request (must never throw: a failure logs and allows in record mode), a CEO-only report, and a CEO-only 403 on `/roles`. Untrusted: request paths (stored as route patterns only, never raw query strings). Risk tier: medium (runs on every request).

## Allowed commands
`composer.bat install`, `php.bat artisan test` (with filters) and `vendor/bin/phpunit` via Herd PHP (`C:\Users\Forbeast\.config\herd\bin\php.bat`); `php.bat artisan route:list`; `php.bat artisan view:cache`; git add, git commit, git push -u origin fix/294-access-recording.

## Done when
1. Tests for AC-1..AC-3 pass, including every case above, the query-count check, and "the middleware never throws". The full suite adds no new failures compared with base 8164024f (list them).
2. The result lists the open list with a reason per entry, every page route the middleware would mark `unmapped` on a fresh seed (from `route:list`), the migration, and a merge-danger section (what ships, the migration, how to switch modes, how to revert).
3. The branch is pushed. No merge, no deploy.

## Out of scope
Enforcing (needs a week and the owner's yes); the roles model; the new navigation; changing which roles see which links.

## Budget
Small-to-medium job. At most 2 attempts at the same failing step, then stop and report.
