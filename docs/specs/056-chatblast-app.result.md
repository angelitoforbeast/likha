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
| `bed7fad3` | docs: this result (first version) |
| `e8009be7` | docs (amendment 1): plan section and stories S-056.8 to S-056.12 |
| `4d245aa9` | feat (amendment 1): list hides skipped by default, earlier ready rows in the today view, Fetch new as a queued job |
| `14c966eb` | feat (amendment 1): the dashboard's "all (no skipped)" chip, the earlier-ready note, the fetch progress |
| `b71f6667` | fix (amendment 1, review): earlier ready leaves out test rows, a long wait shows why it stopped, the progress line no longer hides other notices |
| (this commit) | docs: result, stories and TODO for amendment 1 |

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

## Amendment 1: the Dashboard list and Fetch new

The amendment was saved verbatim to the main checkout's `handoff/056-chatblast-app/AMENDMENT-1.md` (git-ignored, not
committed). It is treated as part of the brief. Plan: the "Amendment 1" section of `docs/plans/056-chatblast-app.md`.

### What changed

1. **Skipped rows are hidden by default** (`ChatblastController::filtered` and `list`).
   - With no status chosen, the rows and the total leave out `skipped`. The counts still include it, so the "skipped"
     chip keeps its number.
   - Clicking that chip shows the skipped rows with their reasons; clicking it again returns to the default.
   - The first chip reads **"all (no skipped) N"**.
   - Sending is unchanged: Send all ready only takes `ready`, and Retry failed only `failed` / `field_mismatch`.
2. **Ready rows from earlier days show in the default view.**
   - When From = To = today (Manila), the build-date condition becomes "built today, OR still `ready` (not a test row)
     and built within its sequence's window". The window is `chatblast_campaigns.window_days` per kind, the same
     window the build re-tracks ready rows in.
   - The page, sequence and search filters still apply to those rows.
   - The list returns `earlier_ready`, computed in the same GROUP BY as the counts, so there is no extra query. The
     page shows **"+ N ready from earlier days (included)"**.
   - Send all ready uses the same filter, so in the default view it sends those rows too.
   - Any other date range is an exact build-date filter, as before.
3. **Fetch new runs as a queued job** (`App\Jobs\ChatblastBuildJob`, default queue, like page settings' Sync now).
   This was the amendment's preferred option.
   - **The endpoint** checks that the build lock is free, takes a one-build slot (`Cache::add`, 10 minutes), records
     the run and dispatches the job.
   - **A second press** while a build waits or runs, or while `chatblast:auto` holds the lock, gets the same 409 "A
     fetch is already running".
   - **The job** runs `Chatblast::build` with the same 20 s J&T budget the web build had. It has no web time limit and
     passes its own token as the lock owner; `Chatblast::build` gained an optional `lockOwner` argument.
   - **The lock is always released:**
     - the build's `finally` releases it;
     - on a failure or a worker timeout, `failed()` releases it, but only if this job still owns it. The job's
       `$timeout` is 280 s, below the 300 s lock, with `failOnTimeout`;
     - if the process is killed outright, the lock's own 300 s expiry and the slot's 10 minutes still free it.
   - **The job also** frees its slot in `finally`, and refreshes the slot when it starts, so a long wait in the queue
     does not show it as stopped while it runs.
   - **Progress on the page:** the run (queued, running, done, failed, busy or stopped, with seconds and the result)
     comes in the list's `state.build`.
     - While it waits or runs, the page shows "Fetching new messages in the background: waiting for the worker /
       running · N s", keeps polling and disables the button, also in other tabs.
     - When the run is done, the page shows the same result line as before and reloads the counts.
     - A run that waited more than 10 minutes for a worker shows "The fetch waited too long for the worker; press
       Fetch new again."
   - **Existing tests:** three were updated to the new behaviour:
     - `ChatblastPageTest` default list: a 2-day-old ready row is now included, and an exact range still excludes it.
     - `ChatblastPageTest` buttons: the build now answers with `run`.
     - `ChatblastEnglishTest`: it reads its skipped rows with `status=skipped`.

### Case table (amendment 1)

Red runs, before the code:

- **S-056.8:** `Failed asserting that 3 is identical to 2` (the total included skipped).
- **S-056.9:** the earlier rows were missing from the list.
- **S-056.10:** `Failed asserting that 1 is identical to 2`.
- **S-056.11:** `Failed asserting that null is identical to 'queued'`.
- **S-056.12:** the strings were not in the page.
- **Review fix:** the test row was counted (`2` vs `1`), and the stopped run had no error text.

| Case | Test | Result |
|---|---|---|
| S-056.8 | `ChatblastDashboardListTest::test_S_056_8_skipped_rows_are_hidden_unless_the_skipped_chip_is_chosen` | pass |
| S-056.9 | `ChatblastDashboardListTest::test_S_056_9_the_default_view_adds_ready_rows_built_on_earlier_days_within_the_window` | pass |
| S-056.10 | `ChatblastDashboardListTest::test_S_056_10_send_all_ready_in_the_default_view_sends_the_earlier_ready_rows_too` | pass |
| S-056.11 | `ChatblastDashboardListTest::test_S_056_11_fetch_new_runs_as_a_queued_job_one_at_a_time_and_always_frees_the_lock` | pass |
| S-056.12 | `ChatblastDashboardListTest::test_S_056_12_the_dashboard_says_what_the_list_covers_and_shows_the_fetch_progress` and the browser check | pass |

`tests/Feature/Chatblast`: `OK, Tests: 159, Assertions: 1069`. The full suite on `b71f6667`: `Tests: 2 failed, 1477
warnings, 158 passed (18618 assertions)`, 247 s. The two failures are the known `ExampleTest` and
`NightRun\ImportStartTest`.

### Browser check (amendment 1)

The browser check ran on the scratch app with made-up rows on one page: 8 ready today, 100 ready In transit built
yesterday, 5 skipped and 3 sent today. The queue was the database queue with **no worker**, so no build ran and no J&T
call was made. The "done" state was written into the cache by hand. Evidence and screenshots are in the main
checkout's `handoff/056-chatblast-app/screens/amendment-1/`: `dash-default`, `dash-skipped`, `dash-fetch-queued`,
`dash-fetch-done` and `evidence-amendment-1.txt`.

- **Default:** the chips read "all (no skipped) 111 · ready 108 · sent 3 · skipped 5", with "+ 100 ready from earlier
  days (included)"; 111 rows over 2 pages, and no skipped row.
- **The skipped chip:** shows 5 skipped rows; clicking it again returns to 111.
- **From = To = yesterday:** "all (no skipped) 100 · ready 100", with no note.
- **Fetch new:** "Fetching new messages in the background: waiting for the worker · 0 s", then 8 s, with the button
  disabled. After the run was marked done, the page showed "12 new (9 ready, 3 no PSID, 0 no config); 1 updated; J&T:
  40 tracked." and the button was enabled again.
- **No JS errors** were logged. The check was re-run after the review fix, with the same results.

### Review (amendment 1)

One `skeptic-reviewer` pass at standard depth on `e8009be7..14c966eb`, then a re-check of the fix (`b71f6667`). There
were no blockers.

- **The major:** there was no test that Send all ready keeps the page, sequence and search filters on earlier ready
  rows. The code was right, but nothing pinned the OR group inside its closure. It is fixed with the S-056.10
  filtered sends, and the re-check closed it.
- **Fixed minors:**
  - earlier test rows were included;
  - a run waiting behind a long default-queue job could show "stopped" while it ran;
  - there were no stopped or busy tests;
  - the progress line overwrote other notices and stayed in other tabs;
  - a failed poll stopped polling;
  - the disabled Fetch new button looked enabled, so it got `disabled:opacity-50`.
- **Accepted, with reasons in `TODO.md`:**
  - the J&T budget constant is read from the controller;
  - the window rule is written twice;
  - S-056.12 checks strings only;
  - the OR condition's index use is not measured;
  - a network error while polling stops the poll;
  - Fetch new needs a default-queue worker.

### Story changes (amendment 1)

- **S-056.10** gained the filtered sends and the test-row exclusion, from the review.
- **S-056.11** gained the stopped and busy states, from the review.

### Merge danger (amendment 1)

- **Fetch new needs a worker on the default queue.** Page settings' Sync now already uses that queue, so one should be
  running. If none is, the page says so after 10 minutes, and nothing else is affected.
- **The cache store must be shared by the web and the worker,** which the build lock already needs. It defaults to the
  database store.
- **Pages open from before the deploy** still expect the old build answer: a press shows a red script error in the
  notice line until the page is reloaded. The build itself is queued and runs.
- **No migrations.**
