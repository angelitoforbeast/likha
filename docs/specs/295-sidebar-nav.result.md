# 295 result: Labelled sidebar navigation

Base: live 8164024f. Branch `feat/295-sidebar-nav`.

## Plan

Read first: `components/layout.blade.php`, `components/navlink.blade.php`, `NavLink`, `NavAccess`, `ChatblastNav`, `NavSettingsController`, `owner/nav_settings.blade.php`, the 13 nav-link migrations, and the tests that read the layout (checklist badge `data-badge`, storage banner, Chatblast nav). Specs 293 (adds a CEO link `/checklist/ai-followups`), 294 (no NavLink or nav UI change) and 296 (adds `/chat`, sets `section` when the column exists) touch the same table, so the grouping must not depend on migration order.

**Data (NV-1, NV-4)**
- Migration `2026_11_27_100000_add_nav_sections_and_pins`: `nav_links.section` (string 30, nullable) and `users.nav_pins` (json, nullable), each guarded with `Schema::hasColumn`; assigns every existing link by `route_url` (normalised to a leading `/`) from the spec's inventory, unknown URLs to `More`. The URL list is frozen inside the migration. `down()` drops both columns.
- `App\Support\NavSections` (new): section order and icons (Home, Orders, Shipping, Marketing, Chat, AI, Money, Settings, More), the URL map, `of($link)` (the stored section when it is a known one, else the URL map, else More, so a link added later without a section, e.g. by 293, still lands in its place), and `group($links)` (non-empty sections only, in order).
- `NavLink`: `section` fillable; `defaultData()` gains `section` on every row and the 9 links the migrations added after May 2026 that it misses (users_admin, supply_finance, playbook, chatblast, chatbot, storage, encoding_timetable, ads_daily_output, botcake_accounts), with their migration visibility; Reset writes the section.
- Nav settings: a Section select per row; `save()` validates `section.*` against the section list (CEO only, the existing check).
- Pins: `POST /nav/pins` (new `NavPinsController`, in the auth group next to `/nav/view-as`): own user only, `pins` an array of at most 6 integers, ids the user cannot see (effective role, as the sidebar) dropped silently; `User` casts `nav_pins` to array. On render, pins to links no longer visible are dropped.

**UI (NV-2, NV-3, NV-5)**
- `components/sidebar.blade.php` (new): Likha mark, Pinned group, sections with collapsible uppercase headers, icon + label links, active state with `aria-current`, the checklist badge (same `data-badge` attributes the badge tests read), pin buttons, empty state for a role with no links, a 64 px rail mode with tooltips, a drawer below 1024 px. Collapse, rail and drawer state are attributes on `<html>` set by a tiny inline script before paint (localStorage, wrapped in try/catch), so nothing flashes.
- `components/nav-palette.blade.php` (new): Ctrl/Cmd+K and the search button open a dialog listing every visible link (data in a JSON script tag), matched on label, section and URL words, last 5 visited (localStorage) on top; arrows, Enter, Esc; focus returned on close.
- `components/layout.blade.php`: the dark 40-icon bar replaced by the sidebar + a slim fixed white top bar (64 px kept, because ~10 pages offset themselves by `mt-16`/`top-16`): menu button (phone), page title, search button, avatar menu (name, role, View as for the CEO, Profile, Logout). Content gets the sidebar's left padding; the full-width page list is kept as is. `components/navlink.blade.php` becomes the sidebar link.
- Design first with `impeccable`: before screenshots (5 pages × 1280/390), brief in `docs/design/295-sidebar-nav/brief.md`, then build, after screenshots, critique + audit, fixes.

**Tests (`tests/Feature/Nav/`), written before the code**
- `NavSectionMigrationTest`: every listed URL lands in its section; unknown → More; the visible set per role is identical before and after; hasColumn guards; `down()`.
- `SidebarTest`: CEO sees all eight sections; Data Encoder only theirs; a role with no links gets the empty state; View as changes the nav only; active link; checklist badge kept; a representative set of `<x-layout>` pages render.
- `NavPaletteTest`: the palette list per role equals the visible links.
- `NavPinsTest`: save own pins; limit 6; guest blocked; ids of hidden links dropped; pins to a link hidden later are not shown.
- `NavSettingsSectionTest`: the section select renders, saves, rejects an unknown section, CEO only; Reset restores sections and adds the missing links.

**Screenshots**: a throwaway (uncommitted) test builds a file sqlite database in the scratchpad with the nav tables, users and the pages' tables; then `php artisan serve` on 127.0.0.1 against it, and headless Chrome (driven over the DevTools protocol by a Node script in the scratchpad) logs in and captures.

## What shipped

**Migration** `database/migrations/2026_11_27_100000_add_nav_sections_and_pins.php`
- `nav_links.section` (string 30, nullable, after `icon`) and `users.nav_pins` (json, nullable), each behind `Schema::hasColumn`.
- Fills `section` for every row that has none, by `route_url` (leading/trailing `/` normalised) from the spec's inventory, frozen in the migration; any other url gets `More`. Rows that already have a section are left alone, so a rerun keeps the CEO's choices. Visibility rows are not touched.
- `down()` drops both columns.

**Code**
- `app/Support/NavSections.php` (new): section order and icons (Home, Orders, Shipping, Marketing, Chat, AI, Money, Settings, More), the live url map, `of($link)` (stored section if valid, else the url map, else More), `group()`.
- `app/Support/NavMenu.php` (new): the links a role sees (`NavLink::visibleFor` + `ChatblastNav::mainLinks`, as the old layout did), the pins among them (max 6, hidden ones dropped), palette entries.
- `app/Http/Controllers/NavPinsController.php` (new) + `POST /nav/pins` (`nav.pins`, same auth + allowed-IP group as `/nav/view-as`): saves the signed-in person's own list; `pins` must be an array of at most 6 integers (else 422); ids their own role cannot see and repeats are dropped silently.
- `NavLink`: `section` fillable; `defaultData()` gains the 9 links the migrations added after May 2026 (users_admin, supply_finance, playbook, chatblast, chatbot, botcake_accounts, storage, encoding_timetable, ads_daily_output) with their migration visibility, and every row carries its `section`.
- `NavSettingsController`: Section select per row on `/owner/nav-settings`, saved and validated against the section list (CEO only, the existing check); Reset writes each default's section; "Add to nav" gives a new link its url's section (More when unknown). All section writes skip themselves while the column does not exist.
- `User`: `nav_pins` cast to array.

**Components**
- `components/sidebar.blade.php` (new): Likha mark, Pinned group (drag or Alt+↑/↓ to reorder, pin/unpin on hover or focus, inline error and revert when saving fails), sections with collapsible uppercase headers (remembered per browser), icon + label rows, one `aria-current` row, checklist badge (same `data-badge` / `data-count` / `data-tone` attributes), empty state for a role with no links, 64 px rail with tooltips, drawer under 1024 px (focus moves in, Tab is kept inside, Esc / scrim / close returns focus).
- `components/nav-palette.blade.php` (new): Ctrl/Cmd+K or the search button; every visible link grouped by section, last 5 visited (localStorage) on top; every typed word must match label, section or url; ↑ ↓ Enter Esc; focus returns to where it was.
- `components/layout.blade.php`: the dark icon bar and the h1 band are replaced by the sidebar and a slim white fixed top bar (page title, search, avatar menu with name, role, View as for the CEO, Profile, Log out). Content sits beside the sidebar; the full-width list is unchanged. Sidebar state is applied before paint by a small head script.
- `components/navlink.blade.php`: now one sidebar row.

## Evidence per done-when item

**1. Tests** (`tests/Feature/Nav/`, 89 tests, all written before the code and seen failing first in commit 01290a49; all pass):
- Section migration (`NavSectionMigrationTest`): each of the 49 inventory urls lands in its section, one test per url. Also covered:
  - an unknown link lands in More;
  - a url stored without its leading `/` is still found;
  - every role's visible set is identical before and after, and no row is left without a section;
  - the columns are added once and a rerun keeps a section the CEO chose;
  - rollback drops both columns;
  - a link added later without a section is still placed by its url.
- Sidebar per role (`SidebarTest`):
  - the CEO sees all eight sections in order, with More last;
  - a Data Encoder sees only Home, Orders, Shipping, Chat and Settings;
  - every role sees exactly the links it saw before;
  - a role with no links, and a user with no role, get the empty state and the page body;
  - a section the CEO moved a link to is used.
- View as (`SidebarTest`, `NavPaletteTest`, `NavPinsTest`):
  - it changes the nav (sidebar, palette, shown pins) only; the CEO's name, the selector and the Preview mark stay;
  - it is ignored for anyone but the CEO.
- Active link and top bar: exactly one `aria-current`; the title, search and avatar are in the bar, and the old dark bar and the vecteezy logo are gone.
- Layout branches: each branch of the layout renders with the sidebar for the CEO and a Data Encoder (no header + full width, full width, padded wide, default), and `/owner/nav-settings` renders in the new layout.
- Palette per role (`NavPaletteTest`):
  - the list equals the sidebar's links for each of the 5 roles;
  - each entry carries id, label, section, url and icon;
  - a Data Encoder cannot find CEO pages;
  - View as gives the previewed role's list;
  - the dialog and the search button are present.
- Pins (`NavPinsTest`):
  - a user saves their own pins in order;
  - 7 pins are refused (422) and 6 are accepted;
  - only the signed-in user's row changes, even with a `user_id` in the body;
  - a guest gets 401;
  - hidden or unknown ids are dropped, junk gets 422, repeats are dropped, an empty list is allowed;
  - a pin to a link hidden later is not shown;
  - View as shows only the pins the previewed role could see.
- Nav settings' section field (`NavSettingsSectionTest`):
  - there is a select per link with the current section selected;
  - the CEO moves a link and visibility is unchanged;
  - an unknown section is refused;
  - the four other roles get 403 on GET and POST;
  - "Add to nav" sets a section;
  - `defaultData()` has a section on every row and every migration-made link with the same url and the same visibility;
  - Reset writes each default's section and visibility and re-creates a deleted default link.
- Full suite against base 8164024f: see below.

**Full suite against base 8164024f** (`phpunit`, the whole suite, `-d memory_limit=4G`. The first attempts ran out of memory in the JUnit logger at 100% and were rerun without it):

| | Tests | Assertions | Errors | Failures | Skipped |
|---|---|---|---|---|---|
| Base 8164024f | 5418 | 83040 | 97 | 183 | 6 |
| Branch, first run | 5507 | 83627 | 97 | 185 | 6 |
| Branch after the two test edits below (those two classes rerun; no app code changed after the full run) | 5507 (+89 in `tests/Feature/Nav`) | | 97 | 183 | 6 |

- **The failing set on the branch is identical to the base's 280**, compared by test name.
- 277 of the 280 are in `tests/Feature/Chatblast`. By class: CommentRepliesTest 48, BroadcastTest 23, ChatbotSequenceEngineTest 22, ChatblastSendTest 21, AdOpenWaitTest 18, FailedSendKeepsConversationTest 12, ChatbotWaitSkipTest 12, ChatblastTrackTest 11, ChatbotAsyncAiTest 10, CustomerReachInboundTest 8, ChatEchoTest 8, BotcakeAsyncSendTest 8, GatewayTypingTest 7, CommentPermissionTest 7, ChatbotProviderTest 7, ChatblastSafetyTest 6, ChatbotAiProviderPoolTest 5, ChatblastReadyTest 5, IncepxionEchoTest 4, CustomerReachChatblastTest 4, ChatbotAiRecordTest 4, ChatblastSequenceTest 4, AdGroupRunTest 4, ChatbotAiRestartTest 3, RealSendTimeTest 2, GatewayPerPageTest 2, ChatbotTagsTest 2, ChatbotAmendmentOneTest 2, ChatbotAfterOrderSequenceTest 2, ChatblastPageTest 2, RunEventRowTest 1, QueueRedisHorizonTest 1, ChatbotHealthTest 1, AiKeyPoolPathTest 1.
- The other 3 are:
  - `OwnerPrivate\SharedPagesBasePinTest` for `/owner/private`;
  - `Item\ItemTableFitScriptTest`;
  - `ExampleTest`.
- The first branch run had **2 new failures**. Both came from tests that read the whole layout, and I changed both tests. The reviewer should confirm these two edits (see Story changes):
  - `Boardroom\SecurityTest::test_model_output_is_delivered_as_plain_text`: it asserts that the `/boardroom` page contains no `localStorage` string anywhere. The new nav keeps UI state in localStorage, which the spec asks for: the rail, closed sections and the last 5 pages "per browser". The check now covers the page's own content (from `<main>`), which is where the boardroom's code lives.
  - `OwnerPrivate\SharedPagesBasePinTest` for `/owner/column-settings/owner-private`: this is a sha1 pin of the whole render, re-pinned on every intended change (its docblock lists each one). I rendered the page on base and on branch. From `<main>` down, the only difference is the closing `</div>` of the new content wrapper. I re-pinned the hash and added a docblock line.

**2. Design evidence** in `docs/design/295-sidebar-nav/`:
- `brief.md`, written before the build, with the critique and audit findings and what was fixed in section 7.
- Before and after, at 1280 and 390 px: `before-*.png` and `after-*.png` for checklist, ads-report, jnt-checker, finance and nav-settings. `/finance` is a standalone page that does not use `<x-layout>`, so it is identical before and after.
- `after-palette-open-1280.png`, `after-palette-search-1280.png` and `after-palette-open-390.png`: the palette.
- `after-rail-1280.png`: the rail collapsed, with a tooltip.
- `after-drawer-open-390.png`: the phone drawer.
- `before-menu-open-390.png`: the old phone menu.
- Also: pinned, avatar menu, sections collapsed, and Data Encoder at 1280 and 390.

**3.** The migration, the new components and the merge danger are listed under "What shipped" and "Merge danger".

**4.** The branch is pushed (`git push -u origin feat/295-sidebar-nav`). There was no merge and no deploy, and no migration ran against any server.

## Story changes

- **NV-2, checklist badge:** the old layout drew the badge twice (desktop bar and phone panel). The one sidebar now serves both, so the badge appears once per page. `tests/Feature/Checklist/ChecklistBadgeTest.php` `test_S_051_20` changes its count from 2 to 1; its other assertions (count, tone, title, team badge on `/checklist` and any layout page) are unchanged and pass.
- **NV-2, top bar:** the separate red Logout button became a "Log out" row in the avatar menu, as the spec's avatar menu lists it. The page heading band became the bar's title.
- **NV-4:** pinning is off while the CEO uses View as (ruling 7).
- **Two layout-wide tests outside this spec** were changed so they keep their intent with the new nav (details in Evidence, item 1):
  - `tests/Feature/Boardroom/SecurityTest.php`: the localStorage check now covers the page content.
  - `tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php`: re-pinned the hash; the diff is layout-only.
- No other story changed.

## Rulings

Choices the spec left open, each with its reason:
1. **The grouping does not depend on the stored column alone.** `NavSections::of()` uses the stored section when it is a valid name, else the url's place in the inventory, else More. Specs 293 (`/checklist/ai-followups`) and 296 (`/chat`) add links in parallel, and their migrations may run before or after mine, or (293) not set `section` at all. With this fallback those links still land in AI and Chat.
2. **The migration fills only empty sections.** A rerun, or a deploy after the CEO has moved links, keeps the CEO's choices.
3. **The migration keeps its own frozen copy of the url list**, and `NavSections::BY_URL` holds the live one. A migration should not change meaning when app code changes later.
4. **`/chatblast/page-settings` → Chat.** `ChatblastNav::mainLinks` rewrites the Page Settings link to this url for roles without `/chatblast`. It is in the live map; no stored row has this url.
5. **Hidden JNT config links (`/jnt/accounts`, `/jnt/orders` …) go to More.** They are not in the inventory, and no role sees them (the JNT Config hub replaced them in May), so More is invisible to everyone for them. The CEO can move them in Nav settings.
6. **Spec says "10 links added after May 2026" that Reset misses; I found 9 in the migrations.** These are: users_admin, supply_finance, playbook, chatblast, chatbot, botcake_accounts, storage, encoding_timetable, ads_daily_output. prompt_generator and jnt_config were already in the catalogue. The rows production has that no migration made (Checklist, Chat, Astra, Boardroom, Page Settings, Access Report …) cannot go into `defaultData()` because their keys are unknown. Reset leaves them alone, as it always did. A test asserts that every migration-made link is in the catalogue with the same url and visibility.
7. **Pins are validated against the person's own role, not the View as role.** In preview the pin buttons are hidden, because pinning there would save a list based on another role's links. Pins to links hidden in the previewed role are not shown (the test covers this).
8. **Hidden or unknown pin ids are dropped silently, not refused.** A link can be hidden between page load and save. The sidebar would otherwise fail on an honest click. More than 6 ids, or non-integers, return 422.
9. **The top bar stays 64 px.** About 10 pages offset themselves with `mt-16` / `top-16` (checklist, boardroom …). A shorter bar would leave gaps or hide content under it.
10. **The page h1 band is removed and the title moves into the top bar.** The spec asks for a slim top bar with the page title. Pages that draw their own header (checklist, J&T RTS, prompt generator, boardroom) keep it, and the bar shows their title as plain text so the page keeps one h1.
11. **Likha's mark is a blue "L" tile plus the word Likha.** The repository has no logo file. The old layout loaded a Facebook logo from vecteezy.com, which was someone else's mark.
12. **The accent is blue-600**, already the primary button colour on the pages.
13. **One sidebar serves desktop and phone.** The old layout drew the links twice (bar + phone panel). The checklist badge therefore appears once, so I changed `ChecklistBadgeTest::test_S_051_20` from 2 to 1 (see Story changes).
14. **Local screenshot data.** The screenshots were taken against a scratchpad sqlite built by an uncommitted throwaway test that ran this repo's migrations on it (13 MySQL-only migrations were skipped). It was seeded with three users and the hand-made production rows (Checklist, Astra, Boardroom, Page Settings, a Profit Calculator link to show More). It was served by `php artisan serve` on 127.0.0.1 and captured by headless Chrome. `.env` in the worktree (git-ignored, copied from `.env.example`) points at that file.
15. **Base run.** My first base run shared this worktree while I edited it, so I stopped it. The base totals come from a read-only export of 8164024f (`git archive` into the scratchpad, vendor copied from this worktree).

## Merge danger

**What ships**
- One migration (two nullable columns plus a data fill).
- Three new PHP classes (`NavSections`, `NavMenu`, `NavPinsController`) and one new route, `POST /nav/pins`.
- Changes to `NavLink`, `NavSettingsController`, `User` and `owner/nav_settings.blade.php`.
- Two new components; `layout.blade.php` and `navlink.blade.php` rewritten.
- The layout change reaches all 174 `<x-layout>` pages. `layouts/chatblast.blade.php` is untouched.

**Risk points**
- Content now starts 248 px from the left at ≥ 1024 px, or 64 px in the rail. A page with its own `position: fixed` full-width bar under the top bar, or a `100vw` width, could run under the sidebar. I found none among the five pages checked. Wide tables still scroll inside their own boxes.
- Pages lose the white h1 band; the title is in the bar.
- **Conflicts with parallel specs:**
  - 296 adds a `/chat` link and sets `section` if the column exists.
  - 293 adds `/checklist/ai-followups`.
  - Both land in the right section whatever the migration order (ruling 1).
  - 294 does not touch these files.
  - A textual merge conflict is possible only if another branch also edits `layout.blade.php`, `NavLink.php`, `NavSettingsController.php`, `nav_settings.blade.php` or the `/nav/view-as` lines of `routes/web.php`.
- `defaultData()` now contains 9 more rows. Pressing Reset in nav settings will create or reset those links with their migration visibility. That is the purpose of the change, but anything the CEO customised on those 9 is reset like every other row.

**How to revert to the old nav**
- Revert the UI commits. The layout reads `section` and `nav_pins` only through `NavSections` / `NavMenu`, so the old layout works with the columns still present.
- To remove the columns too: `php artisan migrate:rollback --path=database/migrations/2026_11_27_100000_add_nav_sections_and_pins.php`. It drops both columns. Visibility rows were never changed, so nothing else needs undoing.
