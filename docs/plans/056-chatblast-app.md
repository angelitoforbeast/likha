# Plan 056: Chatblast as its own app

Spec: `docs/specs/056-chatblast-app.md`. No migration, no behaviour change in the page bodies.

## What exists today

- The chatblast pages (`chatblast/index`, `messages`, `sequence`) and the page settings pages (`page_settings/index`,
  `customers`, `psid_coverage`, `psid_coverage_orders`) are each a standalone HTML document with their own `<head>`,
  an `h1` and a row of links (Messages, Sequences, Page Settings, Home). None of them uses the main `x-layout`.
- The main Likha nav is DB-driven (`nav_links` + `nav_link_role_visibility`, edited at /owner/nav-settings). The
  Chatblast link (`/chatblast`) came from a migration; the Page Settings link (`/page-settings`) was added in the UI.
- Access: `NavAccess::allows($role, '/chatblast')` in the three chatblast controllers, `NavAccess::allows($role,
  '/page-settings')` in `PageSettingController`, `BotcakeCustomersController` and `PsidCoverageController`. NavAccess
  matches the nav link's `route_url`.

## Design

1. **Layout** `resources/views/layouts/chatblast.blade.php`, used with `@extends`. A white top bar (not a sidebar, so
   the page settings grid keeps its full width and spec 054's no-horizontal-scroll guarantee): a violet "Chatblast"
   brand mark, the nav items as pills (active one highlighted), and on the right "← Likha", the user's name and Log
   out. Below it one page header (title + the page's own links) and one padding. Each page's body markup, styles and
   script stay as they are; only the `<html>/<head>/<body>` shell, the `h1` and the old link row move into the layout.
   The page settings sub-pages (Customers, PSID coverage, its Orders) use it too, with Page settings active.
2. **Nav items** come from `App\Support\ChatblastNav::items($role)`: Dashboard, Messages and Sequences when
   `NavAccess` allows `/chatblast`, Page settings when it allows page settings.
3. **Routes:** the `page-settings` group moves to prefix `chatblast/page-settings` with the same route names, so every
   `route('page-settings.*')` caller (views, the grid's `data-*` URLs, controller redirects) follows. The old POST and
   JSON paths move with their callers (all of them use `route()`). The old GET paths, `/page-settings` and
   `/page-settings/{anything}`, answer 301 to the same path under `/chatblast`, query string kept.
4. **Access:** page settings is allowed when a nav link with route_url `/page-settings` **or**
   `/chatblast/page-settings` is checked for the role (`ChatblastNav::PAGE_SETTINGS`, `NavAccess::allows` takes a list).
   Today's rows (`/page-settings`) keep working unchanged; if the owner edits the link's URL to the new path in
   nav-settings, access still follows it. Nobody gains access: both keys are only opened by the CEO in nav-settings.
5. **Main nav:** `ChatblastNav::mainLinks($links)` in `components/layout.blade.php`: when the role sees the Chatblast
   link, any page-settings link is dropped; when it sees only the page-settings link, that one is shown as
   "Chatblast" pointing to `/chatblast/page-settings` (a user without `/chatblast` access gets 404 on `/chatblast`).
   Nothing is written to the DB.
6. Stored reasons that say "fix it on /page-settings" stay as they are: the old path redirects.

## Tasks

| # | Task | Cases | Side | Tier |
|---|------|-------|------|------|
| 1 | Routes moved + 301 redirect; existing page-settings tests to the new path | S-056.2, S-056.3 | backend | medium |
| 2 | `ChatblastNav` (items, page-settings access keys, main nav merge); controllers use it | S-056.4, S-056.5, S-056.6 | backend | medium |
| 3 | Chatblast layout and the 7 views on it | S-056.1 | frontend | medium |
| 4 | Browser check at 1366 and 1280 px, click-through | S-056.7 | frontend | — |

Tasks share `routes/web.php` and the views, so they run one after another in this session; one review (skeptic-reviewer,
standard depth) on the whole diff before the result.
