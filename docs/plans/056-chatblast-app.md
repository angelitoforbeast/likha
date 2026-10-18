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

## Amendment 1 (Dashboard list and Fetch new)

The amendment itself stays in the main checkout's `handoff/056-chatblast-app/AMENDMENT-1.md` (not committed).

1. **Skipped hidden by default** (`ChatblastController::filtered`): when no status is chosen, the rows and the total
   leave out `skipped`; the counts still include it, so the "skipped" chip keeps its number and shows those rows (with
   their reasons) when clicked. The "all" chip reads "all (no skipped) N".
2. **Ready rows from earlier days** in the default view: when From = To = today (Manila), the date condition becomes
   "built today, OR ready and built within its sequence's window" (`chatblast_campaigns.window_days` per kind, the
   same window the build uses). The list returns `earlier_ready` (how many of those were built before today) and the
   page shows "+ N ready from earlier days". "Send all ready" uses the same filter, so it sends them too. Any other
   range is an exact build-date filter, as before.
3. **Fetch new as a queued job** (`App\Jobs\ChatblastBuildJob`, default queue, like the page settings Sync now job):
   - the button posts, the server takes a one-build slot (`Cache::add`, 10 minutes) and dispatches the job; a second
     press while a build is queued or running, or while the build lock is held (e.g. `chatblast:auto`), gets the same
     409 "A fetch is already running";
   - the job runs `Chatblast::build` with the same 20 s J&T budget and its own lock owner token
     (`build(..., lockOwner)`), so there is no web time limit; `finally` frees the slot; `failed()` (also called on a
     worker timeout, `$timeout` 280 < the 300 s lock) releases the build lock only if this job still owns it;
   - progress: the run (queued / running / done / failed / busy, times, result) is kept in the cache and sent in the
     list's `state.build`; the page shows "Fetching new messages… running 35 s", keeps polling, disables the button
     while it runs, and shows the same result line as before when it is done. A run whose slot is gone while it still
     says queued or running shows as stopped.

| # | Task | Cases | Side | Tier |
|---|------|-------|------|------|
| 5 | List: skipped hidden by default, earlier ready rows in the default view, `earlier_ready` | S-056.8, S-056.9, S-056.10 | backend | medium |
| 6 | Fetch new as a queued job with run state, slot and lock release | S-056.11 | backend | medium |
| 7 | Dashboard script: "all (no skipped)", the earlier-ready note, build progress | S-056.12 | frontend | medium |

## Amendment 2 (To send and History; no stuck rows; auto's backlog rule)

It replaces row 2 of amendment 1 (earlier ready rows under the date filter); rows 1 and 3 of amendment 1 stay. The
amendment itself stays in the main checkout's `handoff/056-chatblast-app/AMENDMENT-2.md` (not committed).

1. **No schema change.** The re-check stamp is `botcake_messages.updated_at`. Other code reads `updated_at` only on
   `unknown` and `sending` rows (the cross-sequence rule, the today block, the candidates' "today"), and the stamp only
   touches `ready` rows, so it changes none of those rules.
2. **Re-check on every fetch** (`Chatblast::skipStale`, which already runs on every build, web, `chatblast:build` and
   auto, over every unsent row of each active sequence, whatever its build date):
   - a row whose page left the sequence, or whose waybill left the sequence's statuses, becomes `skipped` with the
     reason as today, and now **both** release the key (`dedupe_key = null`);
   - every row that is still `ready` and passes is stamped (`updated_at = now`, one UPDATE per chunk of 500).
   - Day and text need no refresh here: while an unsent row holds its key, the waybill's next day is that same row
     (`sent_n` does not move), the text follows the sequence's days through the existing re-render, and the
     Delivering rider is refreshed at send time as today.
3. **Auto's backlog rule** (`chatblast:auto`): it queues `ready` rows of the due steps whose `created_at` **or**
   `updated_at` is at or after the moment auto was switched on (`mode_changed_at`; the start of today when unknown).
   Auto's own fetch runs just before, so every still-valid row it re-checked qualifies; a row nobody re-checked since
   before auto was on does not. Everything the sender checks stays (one per waybill per status per day, stop rules,
   On Delivery re-track, pause, off, caps).
4. **Two views, one Blade view with a mode** (`chatblast.index`, `$view = 'send' | 'history'`):
   - **To send** (`/chatblast`, nav "To send"): rows `ready`, `queued`, `sending`, any build date; counts per status, per
     sequence and per page (one GROUP BY); Fetch new, Send selected, Send all ready, Pause and Resume; Page, Sequence
     and Search filter the view; no dates.
   - **History** (`/chatblast/history`, nav "History"): `sent`, `failed`, `field_mismatch`, `skipped`, `no_psid`,
     `no_config`, `unknown`; From/To (build date, view only), Page, Sequence, Search, the status chips; Retry failed.
   - Amendment 1's "earlier ready" OR and `earlier_ready` are removed (replaced).
5. **Send all ready** sends every `ready` row narrowed only by the chosen page and sequence (not dates, not the
   search). The list returns that count (`sendable`), and the confirm says e.g. "Send 112 ready messages (Page Uno,
   all sequences)?".

| # | Task | Cases | Side | Tier |
|---|------|-------|------|------|
| 8 | Re-check stamp and key release; auto's rule | S-056.13, S-056.14, S-056.15 | backend | high (sending) |
| 9 | The two views' data, Send all ready scope, route | S-056.16, S-056.17 | backend | medium |
| 10 | The two views' page and nav | S-056.18 | frontend | medium |
