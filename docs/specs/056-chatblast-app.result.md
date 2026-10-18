# Result 056: Chatblast as its own app

Spec: `docs/specs/056-chatblast-app.md`. Plan: `docs/plans/056-chatblast-app.md`. Branch `feat/056-chatblast-app` from
`fb1c7283`. Not pushed. No migrations, no new dependencies, no Botcake or J&T calls (tests use fake HTTP).

## Commits

| Commit | What |
|---|---|
| `adf437a4` | docs: the spec, saved verbatim |
| `c9636b1b` | docs: plan and stories (slice 056) |
| `4f272608` | feat: page settings move to /chatblast/page-settings; the old GET links answer 301 with their query |
| `10571d31` | feat: page settings access also follows a nav link at the new path; the main nav shows one Chatblast link |
| `cf8736f3` | feat: one Chatblast layout with its own top bar and nav, used by the chatblast and page settings pages |
| `6db4dddb` | fix (review): the redirect keeps the raw encoded path; the brand text shows at every width |
| `d06fef1c` | docs: accepted review findings in `TODO.md` |
| (this commit) | docs: this result |

## Layout

`resources/views/layouts/chatblast.blade.php`, used with `@extends` by `chatblast/index`, `messages` and `sequence`,
and by `page_settings/index`, `customers`, `psid_coverage` and `psid_coverage_orders`.

- **A white top bar:** a violet "Chatblast" brand mark (paper-plane icon and text), then the nav items **Dashboard**,
  **Messages**, **Sequences** and **Page settings** as pills. The current item is violet and carries
  `aria-current="page"`. The page settings sub-pages mark Page settings.
- **The right side** has "← Likha" (to `/`), the user's name and Log out (POST `logout`).
- **No main Likha nav** appears inside the app.
- **One page header** for all pages: the `h1` (the same `text-2xl font-semibold` the pages already used), with the
  page's own links on the right. There is also one page padding.
- **The old in-page link rows are removed** (Messages, Sequences, Page Settings, Home), because the top bar replaces
  them. The page bodies, styles and scripts are unchanged. Styles go to `@push('styles')` in the head, and scripts
  stay after their markup.
- **A top bar, not a sidebar,** so the page settings grid keeps its full width. The bar is not sticky, because the
  grid's sticky table header (`top: 0`) would slide under it.
- **The Dashboard heading** is now "Dashboard" (it was "Chatblast"), because the brand mark now says Chatblast.

Screenshots (main checkout, git-ignored): `handoff/056-chatblast-app/screens/`. They are `page-settings-1366.png`,
`page-settings-1280.png`, `nav-dashboard.png`, `nav-messages.png`, `nav-sequences.png`, `nav-page-settings.png`,
`marketing-page-settings.png` (a Marketing role with page settings only) and `phone-390.png`. The raw measurements
are in `evidence.txt`.

## Routes moved and redirects

- **The page-settings route group** now has the prefix `chatblast/page-settings` and keeps the same route names
  (`page-settings.*`). Every caller uses `route()`, so they all follow: the grid's `data-*` URLs (grid, cell, history,
  page-check, test-token, test-token-one, sync, sync-all, coverage, customers), the controller redirects, and the
  links on the sub-pages. The new paths are:
  - `/chatblast/page-settings` (GET page, POST save)
  - `/grid`, `/cell`, `/history`, `/page-check` and `/{id}/deactivate`
  - `/test-token`, `/test-token/one`, `/sync`, `/sync-all` and `/sync-all/{pause|resume|stop}`
  - `/customers`, `/psid-coverage` and `/psid-coverage/orders`
- **GET `/page-settings` and `/page-settings/{anything}`** answer **301** to the same path under `/chatblast`, with the
  query string kept (route name `page-settings.legacy`). The raw, still-encoded path is used, so `%2F`, `%3F` and
  `%0D%0A` stay encoded and the redirect stays on the app host.
- **The old POST and JSON paths are gone.** They moved with their callers, as the spec allows. A tab left open from
  before the deploy needs a reload (merge danger below).
- **Grep result:** nothing points at a dead route. The `/page-settings` strings left in `ChatblastSender`,
  `ChatblastReason`, `ChatblastFlowInfo` and comments are message text. Some are already stored on rows, and the 301
  covers them.
- **Existing tests:** the 11 test files that called `/page-settings…` now call `/chatblast/page-settings…`. The
  nav-link rows in those tests keep `route_url` `/page-settings`, as production has.

## How access was kept

- **The chatblast pages** still check `NavAccess::allows($role, '/chatblast')`, unchanged.
- **Page settings** (all three controllers) now checks `ChatblastNav::allowsPageSettings($role)`. That is
  `NavAccess::allows` on route_url `/page-settings` **or** `/chatblast/page-settings`; `NavAccess::allows` now accepts
  a list.
  - The production link (`/page-settings`, added in nav-settings) keeps working with no data change.
  - If the owner edits that link's URL to the new path in /owner/nav-settings, access still follows it.
  - The CEO always passes.
  - Nobody gains access: both keys are opened only by the CEO checking a role in nav-settings.
  - A role without access still gets 404.
- **The Chatblast nav** shows Dashboard, Messages and Sequences only with `/chatblast` access, and Page settings only
  with page settings access. It uses the signed-in role, the same one the controllers check.
- **The main Likha nav** goes through `ChatblastNav::mainLinks()` in `components/layout.blade.php`, in memory only.
  - When the role sees the Chatblast link, the Page Settings link is dropped.
  - When it sees only Page Settings, that link becomes "Chatblast" in the same position.
  - It applies to the desktop and mobile menus, and follows the CEO's "view as" role.

## Case table

Red runs (first failure, before the code):

- **S-056.2:** `Expected response status code [301] but received 200`. For the review rows: `'.../page-settings///evil.example/x'` and a decoded `\r\n` in the Location.
- **S-056.3:** every moved test failed with 404 at the new path.
- **S-056.4:** `Expected response status code [200] but received 404`.
- **S-056.6:** `Failed asserting that two arrays are identical` (two links).
- **S-056.1 and S-056.5:** `data-chatblast-app` (and `data-cb-nav`) were not in the page.

| Case | Test | Result |
|---|---|---|
| S-056.1 | `ChatblastAppTest::test_S_056_1_each_page_has_the_chatblast_layout_and_nav_and_no_main_likha_nav` (7 data sets) | pass |
| S-056.2 | `ChatblastAppTest::test_S_056_2_an_old_page_settings_link_moves_permanently_with_its_query` (6 data sets) | pass |
| S-056.3 | `tests/Feature/PageSettings/*` at the new paths (save, cell, grid, history, page check, test token, test token one, sync, sync all and its controls, customers, coverage) | pass |
| S-056.4 | `ChatblastAppTest::test_S_056_4_page_settings_access_follows_nav_settings_under_the_old_and_the_new_path`, `PageSettingsTest::test_a_role_checked_in_nav_settings_reaches_the_pages_and_others_do_not` | pass |
| S-056.5 | `ChatblastAppTest::test_S_056_5_the_chatblast_nav_shows_only_what_the_role_may_open` | pass |
| S-056.6 | `ChatblastAppTest::test_S_056_6_the_main_nav_shows_one_chatblast_link` | pass |
| S-056.7 | Browser check below | pass |

`tests/Feature/Chatblast` and `tests/Feature/PageSettings`: all green, 1978 assertions. `ChatblastAppTest` after the
review fix: `OK, Tests: 16, Assertions: 112`.

## Full suite

`php.bat artisan test` on the tree after `cf8736f3` gave `Tests: 2 failed, 1469 warnings, 158 passed (18551
assertions)` in 289 s. The two failures are the known ones:

- `Tests\Feature\ExampleTest > the application returns a successful response`
- `Tests\Feature\NightRun\ImportStartTest > macro job final write applies only while the run is still active` (InvalidArgumentException)

The review fix (`6db4dddb`) changed only the redirect closure, a class on the brand text and `ChatblastAppTest`. After
it, `ChatblastAppTest` passed in full; the whole suite was not run again.

## Browser check (headless Chrome over CDP, scratch app)

- **Setup:** a scratch app on `127.0.0.1:8056` against a scratch sqlite file outside the repo, built from the tests'
  schema. The data was made up: 30 pages, one with a very long name, flows, a message field and other keys, with
  orders. There were no tokens, so no Botcake call could happen.
- **Sessions:** minted for a scratch CEO and a scratch Marketing user, and deleted afterwards.
- **No horizontal scroll** at 1366 and 1280 px (`scrollWidth <= clientWidth`):

| Width | Document | Grid box |
|---|---|---|
| 1366 × 768 | 1351 <= 1351 ✔ | 1288 <= 1288 ✔ |
| 1366 × 768, ▸ detail row open | 1351 <= 1351 ✔ | 1288 <= 1288 ✔ |
| 1280 × 720 | 1265 <= 1265 ✔ | 1202 <= 1202 ✔ |
| 1280 × 720, ▸ detail row open | 1265 <= 1265 ✔ | 1202 <= 1202 ✔ |

(The client width is 15 px under the window width because of the vertical scrollbar.)

- **Click-through** from /chatblast:
  - Messages → `/chatblast/campaigns`, active Messages
  - Sequences → `/chatblast/sequence`, active Sequences
  - Page settings → `/chatblast/page-settings`, active Page settings
  - Dashboard → `/chatblast`, active Dashboard
  - No page showed the Likha nav.
  - PSID coverage and Customers marked Page settings.
- **The old link** `/page-settings?a=1` landed on `/chatblast/page-settings?a=1`. With curl, `/page-settings?x=1&y=two`
  answered `301` with that Location.
- **A Marketing user** with only the page settings link saw only "Page settings" in the Chatblast nav.
- **At 390 px** the top bar fits (375 = 375). The page settings body overflows (426 > 375) because of its "+ Column"
  form, and the Dashboard table overflows too. Both are in the page bodies, which this spec leaves as they are, and are
  listed in `TODO.md`. The brand text was made visible at every width after this measurement and was not measured
  again; the nav row scrolls on its own (`overflow-x-auto`).
- **Not done by hand:** keyboard-only walking.

## Review

There was one `skeptic-reviewer` pass at standard depth on `fb1c7283..HEAD`. It found no blockers and no majors, and
8 minors.

Fixed (untrusted path or cheap):

- **The redirect decoded `{rest}`:** `%3F` became a real `?`, and an encoded CR/LF gave a 500. It also had no test with
  hostile paths. Fixed with the raw `getPathInfo()`, plus three new data sets (`%2F%2Fevil.example`, `%3F`, `%0D%0A`).
- **The brand text was hidden on phones.** It now shows at every width.

Accepted, with reasons in `TODO.md`:

- the role lookup is written once more, in the layout;
- `/chatblast/page-settings` is written in four places;
- nav-settings now suggests the new GET routes under "Add to nav";
- the old POST paths are gone, so an open tab needs a reload;
- two missing tests: the merged link's position, and an inactive new-path link.

Also recorded:

- **The spec's "one Chatblast link to `/chatblast`":** for a page-settings-only role, the link goes to
  `/chatblast/page-settings`. See "What the brief got wrong".

## Story changes

- **S-056.2** gained the hostile-path clause (`%2F%2F`, `%3F`, `%0D%0A` stay encoded under /chatblast/page-settings on
  the app host). Reason: a review minor on the new untrusted route.

## What the brief got wrong, or left open

1. **"One Chatblast link to /chatblast that shows for anyone with access to either part":** a role with page settings
   access but not `/chatblast` gets 404 on `/chatblast`. For that role, the single link goes to
   `/chatblast/page-settings` instead. It is still labelled "Chatblast" and sits in one place.
2. **"Sidebar width included":** I chose a top bar, which the spec allowed, so no width is lost. The 054 grid keeps
   the same width it had.
3. **"All four pages":** the three page settings sub-pages (Customers, PSID coverage, Orders) live under the same
   prefix. They use the layout too, with Page settings active, so nothing inside Chatblast looks like the old site.
4. **"All UI text is English":** the sub-pages' bodies are still partly Tagalog from before ("Orders ng page", "Lahat
   ng page", "Mula", "Ipakita"). The spec also says to keep each page's content as it is, so I left them and listed
   them in `TODO.md`.
5. **"One spacing, card and button style":** shared through the layout's chrome, header and padding only. The buttons
   and cards inside the page bodies are unchanged, because the spec also says the bodies must not be redesigned.
6. **No main Likha nav inside the pages:** the old pages were already standalone HTML without the Likha nav. What
   changed is that they now share one Chatblast chrome.

## Process notes

- **The brief was the "go".** There was no separate reviewer session in this chat, so the plan was committed and the
  work went ahead without waiting for a separate "go".
- **Workspace:** the worktree had no `vendor/`. A junction to the main checkout's `vendor/` made PHP load the main
  checkout's classes, so it was replaced with a copy (git-ignored).
- **Old browser tabs:** after the deploy, page settings tabs opened before it must be reloaded before saving.

## Merge danger

- **Low.** There are no migrations or data changes. The `page-settings.*` route names are the same; only their paths
  changed.
- **The page settings nav link** in production keeps route_url `/page-settings`, and access keeps working. Optionally,
  the owner can change it to `/chatblast/page-settings` in /owner/nav-settings; access follows either one.
- **Route cache:** the new redirect is a closure, like other routes in `web.php`.
