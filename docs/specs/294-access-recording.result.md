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

## What shipped

- **AC-1** `app/Http/Controllers/RoleController.php`: a private `abortUnlessCeo()` (`strtoupper(trim(role)) === 'CEO'`,
  the `ChecklistController::isCeo()` rule) at the top of `index`, `store` and `update`. A non-CEO gets 403 before
  validation, so nothing is written. `routes/web.php` is unchanged for this.
- **AC-2**
  - `config/access.php`: the open list (below), one comment per entry.
  - `database/migrations/2026_11_27_294000_create_access_records_table.php`: `access_records` (`user_id`, `role`,
    `path_pattern`, `decision`, `day`, `hits`, `last_at`, timestamps), unique on (user_id, path_pattern, decision,
    day), index on (day, role). Guarded with `Schema::hasTable`.
  - `app/Support/AccessRule.php`: the one rule (CEO → open list → longest nav match, allowed when visible for the
    role → else `unmapped`), `mode()` from `app_settings.access.mode` (`record` unless the value is `enforce`),
    `linkFor()`, and `record()` (one upsert: insert, or `hits = hits + 1` and `last_at`). The mode, the nav map
    (one join query) and the table checks are one cached value (`access_rule:state:v1`, 60 s), read at most once
    per request. `AccessRule::forget()` drops it.
  - `app/Http/Middleware/RecordPageAccess.php`, appended to the `web` group after `RecordStaffActivity` in
    `bootstrap/app.php`. It looks only at signed-in GETs with a matched route that are not ajax, do not expect
    JSON and are not on the open list; that check runs no query. Record mode: the request runs, then a would-block
    or unmapped view is counted unless the response is JSON, a file or a stream. Enforce mode: a would-block is
    counted and answered with `resources/views/access/blocked.blade.php` (403, "You don't have access to this page.
    Ask your OIC or the owner."); an unmapped page is served and counted. Every step of the rule is in a try/catch
    that logs `RecordPageAccess: skipped` and allows; the request's own exceptions are not caught.
- **AC-3** `app/Http/Controllers/AccessReportController.php` + `resources/views/owner/access-report.blade.php`, route
  `GET /owner/access-report` (`owner.access-report`) next to `/owner/nav-settings`, in the same `web,auth,allowed_ip`
  group. CEO only (403 otherwise). Last 7 days by default, `days` 1-30 (clamped; not a number → 7), filters `role`
  and `decision`. One row per role + page pattern + decision: nav link label or "No nav link", decision, hits
  (sum), people (distinct users), last time; most hits first. Mode line on top: "Recording only: nobody is blocked
  yet." or "Blocking is on: …". White card and table in the checklist range report's style.
- Tests: `tests/Feature/Access/` (`AccessTestCase`, `RolesCeoOnlyTest` 14, `PageAccessRecordingTest` 18,
  `AccessReportTest` 10 = 42 tests).

## Evidence per done-when item

**1. Tests.** `php.bat artisan test tests/Feature/Access` → 42 passed, 145 assertions. (The "!" on each test is the
missing-`.env` warning from `file_get_contents(.env)`, the same on every test of the base.) Test first: the AC-1 and
AC-3 tests were committed red before their code (12 and 10 failing); the AC-2 tests were run with the middleware
and rule removed (11 failing; the 7 others assert "no row") and committed before the code.

| Spec case | Test |
|---|---|
| AC-1 H: CEO opens and saves | `RolesCeoOnlyTest::test_the_ceo_opens_adds_and_renames_roles_as_before`, `test_a_ceo_role_with_spaces_or_lower_case_is_still_the_ceo` |
| AC-1 N: OIC, staff, no role, no profile → 403 on index, store, update; nothing changes | `RolesCeoOnlyTest::test_anyone_else_gets_403_and_nothing_changes` (12 data sets) |
| AC-2 H: Marketing on `/finance/supply` → allowed, one `would_block`; again → hits 2 | `test_a_marketing_user_on_a_ceo_page_is_allowed_and_one_would_block_row_is_counted` |
| AC-2 E: CEO → no row | `test_the_ceo_is_never_recorded` |
| AC-2 E: open-list page → no row | `test_open_list_pages_are_never_recorded` |
| AC-2 visible link (role trimmed/collapsed) → no row | `test_a_page_whose_link_is_visible_for_the_role_is_not_recorded` |
| AC-2 longest match wins | `test_the_longest_nav_match_wins` (`jnt/supply*` Marketing vs `jnt/supply/excluded-pages*` CEO) |
| AC-2 unmapped; route pattern only, no query string | `test_a_page_no_link_covers_is_allowed_and_recorded_as_unmapped_with_its_route_pattern_only`, `test_a_page_with_parameters_counts_on_its_route_pattern` |
| AC-2 user without a profile | `test_a_user_without_a_profile_is_recorded_with_an_empty_role` |
| AC-2 JSON, ajax, file, API, POST not recorded | `test_json_files_api_writes_and_guests_are_not_recorded` |
| AC-2 Manila day | `test_the_day_is_the_manila_date_and_a_new_day_starts_a_new_row` |
| AC-2 default mode `record` | `test_the_mode_defaults_to_record` |
| AC-2 E: enforce → 403 page; CEO never blocked | `test_enforce_mode_answers_a_plain_403_page_and_still_counts_it`, `test_enforce_mode_never_blocks_the_ceo_visible_open_or_unmapped_pages` |
| AC-2 never loosens a controller check | `test_the_middleware_never_loosens_a_controller_check` (`/roles/index` stays 403 for Marketing) |
| AC-2 never throws | `test_the_middleware_never_throws_when_the_rule_fails` (and logs the warning), `test_the_middleware_never_throws_in_enforce_mode_either`, `test_missing_tables_are_skipped_without_an_error` |
| AC-2 performance: query count | `test_the_nav_map_is_read_once_and_cached_and_api_and_json_routes_add_no_query`: on a warm cache a page view runs no `nav_link*`, `app_settings` or `sqlite_master` query and exactly one `access_records` query (the upsert); `/api/...`, a JSON route and an open-list page run the same number of queries with and without the middleware |
| AC-3 rows, nav link / "No nav link", hits, people, last time, mode line | `AccessReportTest::test_the_ceo_sees_one_row_per_role_and_page_with_hits_people_and_last_time` |
| AC-3 days 1-30 | `test_the_days_can_be_chosen_between_1_and_30` |
| AC-3 filters | `test_filters_by_role_and_decision` |
| AC-3 enforce mode line, empty report | `test_the_mode_line_says_when_blocking_is_on`, `test_an_empty_report_says_so` |
| AC-3 CEO only | `test_anyone_but_the_ceo_gets_403` (4 data sets), `test_guests_are_sent_to_login` |

`php.bat artisan view:cache` compiles all views, the two new ones included.

**Full suite against base 8164024f.**
`php.bat -d memory_limit=-1 vendor/bin/phpunit` (the default 1 GB limit ran out at test 5,124 of the base, so both runs use no limit):

| Run | Tests | Assertions | Failures | Skipped |
|---|---|---|---|---|
| Base 8164024f (clean `git archive` export) | 5,418 | 84,071 | 3 | 6 |
| Branch at `3e38232b` | 5,460 (+42 access tests) | 84,240 | 3 (the same three) | 6 |

Base failures, identical on the branch (also each confirmed alone on the base):
1. `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (302 to login, not 200)
2. `Tests\Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
3. `Tests\Feature\OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_..._byte_for_byte_as_at_the_base` with data set `/owner/private`

No new failures. The first branch run (before `3e38232b`) also failed three `ChatbotResultsBackgroundTest` cases (S 278 3, 4, 4b). They count a page's "report queries" by matching SQL text against the queries the report build runs, and the middleware's mode read had the same text as the build's `app_settings` read (`select "value" … limit 1`). The fix reads the mode with `pluck()` (no `limit 1`); that test file passes, and their test was not touched.

**2. Lists.**

Open list (`config/access.php`; `Str::is` patterns on the path without the leading slash):

| Entry | Reason |
|---|---|
| `/` | Home: everyone lands here after login |
| `profile`, `profile/*` | Own profile and password |
| `checklist`, `checklist/*` | Daily checklist: every role submits; the controller scopes what each role sees |
| `astra`, `astra/*` | Astra assistant: for every staff member |
| `chat`, `chat/*` | Staff chat (see rulings: not `chatblast`/`chatbot`) |
| `order-encode`, `order-encode/*` | Has its own access rule (spec 130), not the nav |
| `login`, `logout`, `register` | Sign in, sign out, register |
| `password/*`, `email/*` | Password reset and email verification links |
| `api/*` | API: its own auth, never a page |
| `up` | Health check |
| `broadcasting/*`, `sanctum/*` | Handshakes, never a page |
| `storage/*`, `build/*`, `css/*`, `js/*`, `images/*`, `img/*`, `fonts/*`, `favicon.ico` | Assets |
| `*.json`, `*/data`, `*/status`, `*/poll`, `*/refresh` | JSON polling routes by suffix (the suffixes `NavLink::unregisteredRoutes` skips) |

Page routes a fresh seed would mark `unmapped`: every GET route in the `web` group, not on the open list, that no
seeded nav link covers. Nav links: all 13 nav migrations run in order. Routes: `route:list` (408 GET routes),
classified by `AccessRule` itself in a throwaway test (not committed). 131 routes. Some answer JSON, a file or a
redirect; at runtime those are not counted (only pages are), so the report will show fewer.

- `/ad-copy-suggestions` (no `auth` middleware on the route: recorded only when signed in)
- `/ads-manager/edit-messaging-template`
- `/ads-manager/import-form`
- `/ads_manager/ad_account`
- `/ads_manager/ad_account/{ad_account_id}`
- `/ads_manager/ads`
- `/ads_manager/ads_manager`
- `/ads_manager/adsets`
- `/ads_manager/campaign`
- `/ads_manager/campaign_view`
- `/ads_manager/campaigns`
- `/ads_manager/campaigns/_diag`
- `/ads_manager/campaigns/assignments`
- `/ads_manager/campaigns/assignments/history`
- `/ads_manager/campaigns/assignments/log`
- `/ads_manager/campaigns/history`
- `/ads_manager/catalog`
- `/ads_manager/catalog/ads`
- `/ads_manager/catalog/adsets`
- `/ads_manager/creative-preview`
- `/ads_manager/employees`
- `/ads_manager/index`
- `/ads_manager/insights`
- `/ads_manager/insights/preview`
- `/ads_manager/meta/ad-accounts`
- `/ads_manager/meta/callback`
- `/ads_manager/meta/connect`
- `/ads_manager/view`
- `/boardroom`
- `/boardroom/agents`
- `/boardroom/api/agents`
- `/boardroom/api/agents/{id}/lessons`
- `/boardroom/api/bootstrap`
- `/boardroom/api/knowledge`
- `/boardroom/api/meetings/{id}`
- `/boardroom/api/resources`
- `/boardroom/resources`
- `/botcake/psid/import`
- `/botcake/psid/import/status/{runId}`
- `/botcake/psid/settings`
- `/checker_2/gsheet/import`
- `/checker_2/gsheet/settings`
- `/conversation/cancel-detector`
- `/conversation/cancel-detector/settings`
- `/conversation/cancel-detector/settings/fetch-sheets`
- `/conversation/cancel-detector/view`
- `/cpp`
- `/debug/ip`
- `/encoder/page-name`
- `/encoder/tools/ai`
- `/encoder/tools/ai/health`
- `/fb_ads_data`
- `/from_gsheet`
- `/from_jnt`
- `/from_jnt_view`
- `/gpt-ad-generator` (no `auth` middleware on the route: recorded only when signed in)
- `/gpt-ad-generator/history` (no `auth` middleware on the route: recorded only when signed in)
- `/gpt-ad-generator/history/{id}` (no `auth` middleware on the route: recorded only when signed in)
- `/gpt-ad-generator/prompt-history` (no `auth` middleware on the route: recorded only when signed in)
- `/gpt-ad-generator/prompt-history/{id}` (no `auth` middleware on the route: recorded only when signed in)
- `/gpt-ad-generator/video-analysis/{id}` (no `auth` middleware on the route: recorded only when signed in)
- `/gpt-ad-generator/video-history` (no `auth` middleware on the route: recorded only when signed in)
- `/gsheet_groups`
- `/gsheet_groups/history`
- `/gsheet_groups/{id}/values`
- `/image-host`
- `/import_gsheet`
- `/import_gsheet/settings`
- `/item`
- `/item/cogs`
- `/item/cogs/grid`
- `/item/edit-logs`
- `/item/images`
- `/item/photo`
- `/item/quotes`
- `/item/stock`
- `/item/suppliers`
- `/item/worklist`
- `/jnt/chatblast/gsheet/settings`
- `/jnt/dashboard`
- `/jnt/ondel` (no `auth` middleware on the route: recorded only when signed in)
- `/jnt/order-management`
- `/jnt/remittance`
- `/jnt/return/inventory`
- `/jnt/return/reconciliation`
- `/jnt/return/scanned`
- `/jnt/sales-declaration`
- `/jnt/sales-declaration/export`
- `/jnt/segregate-stickers`
- `/jnt/segregate-stickers/{runId}`
- `/jnt/segregate-stickers/{runId}/download-zip`
- `/jnt/segregate-stickers/{runId}/download/{file}`
- `/jnt/shipments`
- `/jnt/shipped`
- `/jnt/status-summary`
- `/jnt/status-summary/details`
- `/jnt/status-summary/rts-details`
- `/jnt/track-sync`
- `/jnt/track-sync/history`
- `/jnt/track-sync/status/{run}`
- `/jnt_update`
- `/likha_order/view`
- `/macro/barcodes`
- `/macro/barcodes/logs`
- `/macro/export`
- `/macro/orders`
- `/macro_output/download`
- `/macro_output/validated-summary`
- `/mes-download/{filename}`
- `/orders/tally`
- `/orders/tally/{date}`
- `/owner/access-report`
- `/page-settings/{rest?}`
- `/pancake/conversations`
- `/pancake/index`
- `/pancake/retrieve-orders`
- `/phone-whitelist`
- `/phpinfo`
- `/profit-calculator`
- `/registerlogin` (no `auth` middleware on the route: recorded only when signed in)
- `/roles/index`
- `/rts`
- `/summary/overall`
- `/system-monitor`
- `/task/create`
- `/task/create-everyday-task`
- `/task/index`
- `/task/my-everyday-task`
- `/task/my-tasks`
- `/task/team-tasks`
- `/validation-lists`

Migration: `database/migrations/2026_11_27_294000_create_access_records_table.php` (above). No data migration; no
`app_settings` row is written (a missing `access.mode` means `record`).

**3. Branch pushed** (`git push -u origin fix/294-access-recording`). No merge, no deploy.

## Story changes

None. Test detail: the `/finance/supply` and other pages in `PageAccessRecordingTest` are stubs registered on the
real paths, so the tests see only the middleware. (The real `SupplyFinanceController` answers 404 to non-CEOs by
itself; the middleware still counts the would-block, which is what the record is for.)

## Rulings

1. **What "renders a page" means.** A signed-in GET with a matched route, not ajax (`X-Requested-With`), not
   `expectsJson()`, not on the open list; in record mode the response must also not be JSON, a file download or a
   stream. Any status counts (a controller's own 403/404 on a page is still an attempt to open it).
2. **Enforce mode and `unmapped`.** Enforce blocks only `would_block`. An unmapped page is still served (and
   counted): the spec ties blocking to nav visibility and leaves pages with no home to the owner's review.
   *Reviewer/owner to confirm before switching to enforce.*
3. **Fail open in both modes.** The spec says a failure "logs and allows in record mode" and says nothing about
   enforce. I allow there too, so a broken cache or table never locks everyone out of every page.
4. **`/chat*` read as `chat` and `chat/*`.** There is no `/chat` route on main today. A literal `chat*` would also
   open `/chatblast` and `/chatbot`, which are nav apps with their own links and NavAccess checks. *Reviewer to
   confirm; one config line to change if `chat*` was meant.*
5. **Matching.** Path and patterns without leading slashes. `active_pattern` via `Str::is` (as the nav highlights
   links); with no `active_pattern`, `route_url` as a prefix on whole segments (`/jnt` does not cover
   `/jnt_upload`). Match length = the pattern without its trailing `*` (or the `route_url`); links tied on length
   are merged (allowed if any is visible, as `NavAccess::allows` with several URLs). Only active links count.
6. **Role comparison.** Trimmed, spaces collapsed (as `NavAccess`) and compared case-insensitively (as MySQL's
   default collation does in `NavAccess`'s `where role = ?`). CEO test as `ChecklistController::isCeo()`.
   A user without a profile or role is recorded with role `''`, shown as "No role".
7. **The record is written after the response** in record mode, so JSON routes not on the open list cost no nav
   query, and the role comes from the `employeeProfile` the layout has already loaded.
8. **Cache.** One cache entry (mode + nav map + table checks), 60 s, plus a per-request copy. With
   `CACHE_STORE=database` (the `.env.example` default) the cache read is itself one query per counted page view;
   that is within "read once per request at most". A mode or nav change takes up to 60 s to apply.
9. **Day** = `now()` in Asia/Manila (already the app's timezone); `last_at` is app time.
10. **`/roles` check in the controller**, not as route middleware, so `routes/web.php` around line 1206 stays
    untouched (other workers edit that file).
11. **Report row key** includes the decision. The nav label in the report is recomputed from the pattern with
    today's nav map.
12. **Composer.** `composer.bat install` fails on this Windows PHP for missing `ext-pcntl`/`ext-posix`; I ran it with
    `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` (vendor only, lock file unchanged).
13. **Base run.** The first base run in this worktree overlapped my first edits, so I stopped it and ran the base
    from a clean `git archive 8164024f` export in my scratchpad (same vendor) with `vendor/bin/phpunit`; the
    branch ran with the same command in this worktree, with no code edits during the run.

## Merge danger

- **What ships:** a middleware on every `web` request (`RecordPageAccess`), the `/roles` CEO check, the
  `/owner/access-report` page, `config/access.php`, one new table. In record mode nothing changes what any page
  shows; the only behaviour change for users is `/roles/*` answering 403 to non-CEOs.
- **Migration:** `2026_11_27_294000_create_access_records_table` creates `access_records` only (skips if it exists).
  Until it runs, the middleware records nothing and the report is empty; nothing breaks. It runs with the deploy's
  usual `php artisan migrate --force`.
- **Write load:** one upsert per would-block/unmapped page view; an allowed page view writes nothing. One row per
  user, page pattern, decision and day.
- **Switching modes:** `AppSetting::set('access.mode', 'enforce')` in tinker, or in SQL:

  ```sql
  INSERT INTO app_settings (`key`, value, created_at, updated_at)
  VALUES ('access.mode', 'enforce', NOW(), NOW())
  ON DUPLICATE KEY UPDATE value = 'enforce', updated_at = NOW();
  ```

  Back: set it to `record` or delete the row. Takes effect within
  60 s (`php artisan cache:clear` for at once). Not part of this spec: do not switch before the week of records and
  the owner's yes.
- **Revert:** revert the merge commit (removes the middleware line in `bootstrap/app.php`, the route, the check).
  The table can stay (nothing else reads it), or `php artisan migrate:rollback --step=1` if this migration was the
  last batch.
- **Conflicts with siblings (293, 295, 296):** shared files are `bootstrap/app.php` (one appended block after
  `RecordStaffActivity`) and `routes/web.php` (two lines after `/nav/view-as`); both are additive. A sibling that
  adds a `/chat` page gets it open through the open list; a sibling that adds pages or nav links changes the
  unmapped list above. A sibling's own tests that hit pages as a signed-in user now pass through the middleware
  (it skips quietly when its tables are missing). A sibling test that lists every query of a page view (as
  `ChatbotResultsBackgroundTest` does) can see the middleware's one cache-load query or one `access_records` upsert;
  re-run sibling suites after merging.
- **Config cache:** if the deploy caches config, `config/access.php` is picked up by the usual `config:cache`.
