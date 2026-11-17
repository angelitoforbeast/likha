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

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
