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

## Amendment 2: To send and History, no stuck rows, auto's backlog rule

The amendment was saved verbatim to the main checkout's `handoff/056-chatblast-app/AMENDMENT-2.md` (git-ignored, not
committed). It is treated as part of the brief. It **replaces row 2 of amendment 1** (earlier ready rows under the date
filter); rows 1 and 3 of amendment 1 stay. Plan: the "Amendment 2" section of `docs/plans/056-chatblast-app.md`.

### What changed

1. **No schema change.** The re-check stamp is `botcake_messages.updated_at`. Other code reads `updated_at` only on
   `unknown` and `sending` rows, and the stamp touches only `ready` rows, so no existing rule changes. No migration.
2. **No stuck rows** (`Chatblast::skipStale`). It runs on every fetch: the web Fetch new job, `chatblast:build` and
   auto's own build. It re-checks **every** unsent row of each built sequence, whatever its build date:
   - **page left the sequence:** the row is skipped, as before, and its key is released;
   - **waybill left the sequence's statuses:** the row is skipped with the same reason as before, and now **also
     releases its key**, so the waybill gets its own new row if it comes back;
   - **order older than the sequence's window** (`macro_output.ts_date`, or the build date when there is no order; the
     same bound as the build): the row is skipped with "The order is older than the sequence's N-day window" and its
     key is released. This was added after the review;
   - **still valid and ready:** the row is stamped (`updated_at = now`, one UPDATE per chunk of 500).
   - **Day and text** need no refresh. While an unsent row holds its key, the waybill's next day is that same row. The
     text follows the sequence through the existing re-render, and the On Delivery rider is refreshed at send as
     before.
3. **Auto's backlog rule** (`chatblast:auto`).
   - **The new rule:** at each due time, auto queues ready rows of the due steps whose `created_at` **or** `updated_at`
     is at or after the switch to auto (`mode_changed_at`; the start of today when unknown).
   - **The old rule:** built today after the switch.
   - **Effect:** auto's own fetch, just before, re-checks every row, so every still-valid ready row from before is
     sent once. A row nobody re-checked since before the switch is not.
   - **Unchanged:** every sender rule (one per waybill per status per day, stop rules, On Delivery re-track, pause,
     off, caps).
4. **Two views and two nav items.** The nav reads To send · History · Messages · Sequences · Page settings. One Blade
   view serves both, with `$view`, and the route `/chatblast/history` is new.
   - **To send** (`/chatblast`): every non-test row that is `ready`, `queued` or `sending`, of any date.
     - It shows counts per status, plus ready per sequence and per page, from one GROUP BY.
     - It has Fetch new, Send selected, Send all ready, Pause and Resume.
     - It has no dates. Page, Sequence and Search narrow the view.
   - **History** (`/chatblast/history`): `sent`, `failed`, `field_mismatch`, `skipped`, `no_psid`, `no_config`, `unknown`.
     - It has From/To, view only. The date is **when the row was processed** (`sent_at` for sent, the last change for
       the rest), not when it was built. This was changed after the review, so the backlog sent or failed at 07:30
       shows under that day.
     - It has status chips, Page, Sequence, Search and Retry failed.
   - Amendment 1's earlier-ready OR, `earlier_ready` and the "all (no skipped)" label are gone: skipped rows live in
     History.
5. **Send all ready** sends every non-test `ready` row of the sequences that are on, narrowed only by the chosen page
   and sequence; dates and the search are ignored. The list returns that count (`sendable`), and the confirm names the
   count and the scope, e.g. "Send 100 ready messages (Sample Page 1, In transit)?". Send selected is unchanged.

### Case table (amendment 2)

Red runs, before the code:

- **S-056.13:** `'WB-1' => 'ready'` (expected queued).
- **S-056.14:** the key was still `WB-1|c-auto|1`.
- **S-056.16:** `Failed asserting that 0 is identical to 2`.
- **S-056.17:** the arrays differed.
- **S-056.18:** the nav labels differed.
- **S-056.19:** the 17-day-old row was **queued** by auto.
- **S-056.20:** 1 of 3 rows.
- **S-056.21:** 2 vs 1.

S-056.15 and the S-056.14 additions (rebuild after the waybill comes back, the stamp on ready rows only) passed on the
first run. They pin behaviour that was already right, and the review asked for them.

| Case | Test | Result |
|---|---|---|
| S-056.13 | `ChatblastAutoTest::test_S_056_13_a_ready_row_from_before_the_switch_is_sent_once_auto_s_fetch_re_checks_it` | pass |
| S-056.14 | `ChatblastAutoTest::test_S_056_14_a_ready_row_whose_waybill_moved_on_is_skipped_frees_its_key_and_the_next_sequence_builds` | pass |
| S-056.15 | `ChatblastAutoTest::test_S_056_15_auto_does_not_send_a_row_nobody_re_checked_since_before_the_switch` | pass |
| S-056.16 | `ChatblastToSendTest::test_S_056_16_send_all_ready_sends_every_ready_row_of_the_page_and_sequence_whatever_its_date` | pass |
| S-056.17 | `ChatblastToSendTest::test_S_056_17_to_send_lists_what_is_still_to_send_and_history_what_was_processed` | pass |
| S-056.18 | `ChatblastToSendTest::test_S_056_18_the_nav_and_the_two_pages_have_their_own_tools` and the browser check | pass |
| S-056.19 | `ChatblastAutoTest::test_S_056_19_a_ready_row_whose_order_left_the_sequence_window_is_skipped_and_not_sent` | pass |
| S-056.20 | `ChatblastToSendTest::test_S_056_20_history_dates_are_when_the_row_was_processed_not_when_it_was_built` | pass |
| S-056.21 | `ChatblastToSendTest::test_S_056_21_send_all_ready_counts_only_sequences_that_are_on` | pass |

These existing tests were changed to the new rules:

- `ChatblastAutoTest::test_auto_never_sends_the_backlog_only_rows_built_today_after_the_switch` pinned the old backlog
  rule. It is replaced by S-056.13.
- `ChatblastPageTest`: the list test is split into To send and History, and the query-count bound goes from 7 to 8 for
  the "sequences that are off" lookup. The count is still constant.
- `ChatblastEnglishTest`: Retry failed and the empty text are checked on History, and the skipped reasons are read
  from History.
- `ChatblastAppTest`: the nav labels.
- Amendment 1's S-056.8, .9, .10 and .12 tests are removed, because those cases are replaced.

**Suite:**

- `tests/Feature/Chatblast`: `OK, Tests: 164, Assertions: 1112`.
- The full suite on `ae720131`: `Tests: 2 failed, 1482 warnings, 158 passed (18661 assertions)`, 264 s. The two
  failures are the known `ExampleTest` and `NightRun\ImportStartTest`.

### Browser check (amendment 2)

The check ran on the same scratch app and data as amendment 1: one page, 8 ready rows today, 100 ready In transit rows
built yesterday, 5 skipped and 3 sent. No worker was running and no external call was made. Evidence and screenshots
are in the main checkout's `handoff/056-chatblast-app/screens/amendment-2/` (`to-send.png`, `history.png`,
`evidence-amendment-2.txt`).

- **To send:**
  - The nav read "To send* · History · Messages · Sequences · Page settings".
  - The chips read "all 108 · ready 108", with "Ready per sequence: Delivering 8 · In transit 100" and "Ready per page:
    Sample Page 1 108".
  - There were no dates. The buttons were Pause, Resume, Fetch new, Send selected and Send all ready, with no Retry
    failed.
  - The confirm read "Send 108 ready messages (all pages, all sequences)?".
- **To send, filtered:** with page Sample Page 1, sequence In transit and search "Buyer 9", the view narrowed to 11
  rows, and the confirm read "Send 100 ready messages (Sample Page 1, In transit)?": the search is view only.
- **History:**
  - It showed "all 8 · sent 3 · skipped 5", with From/To and only Retry failed.
  - The skipped chip showed the 5 skipped rows.
- **Width:** To send has no horizontal scroll at 1280 (1265 <= 1265).
- **No JS errors.** This check ran before the review fix, which changed only server queries.

### Review (amendment 2)

One `skeptic-reviewer` pass at **adversarial** depth on `3d63e1d7..a0f6bd1a`, then a re-check of the fix (`ae720131`).
It found no blockers and two majors, both fixed and closed in the re-check:

1. **The re-check never applied the sequence's window,** so auto would send a stamped row for an order of any age. It
   is now skipped with its key released (S-056.19). Before the fix, the test row was queued.
2. **History dated rows by build date,** so the backlog sent or failed at 07:30 would not show under "today", and
   Retry failed would queue nothing. It now dates by processing (S-056.20).

Fixed minors:
- the Send all ready count included sequences that are off (S-056.21);
- there was no test of the same sequence rebuilding after the key release, and none that the stamp leaves non-ready
  rows alone (both added to S-056.14);
- the stories table (S-056.8, .9, .10 and .12 marked replaced; S-056.19 to .21 added).

Accepted, with reasons in `TODO.md`:
- the sender's own status-skip keeps its key;
- the rider text for a Delivering sequence without "requires On Delivery";
- Send selected accepts skipped rows;
- the Send all ready count can be up to 30 s old;
- History's `updated_at` moves rows when they are re-rendered;
- History's OR may scan the table on MySQL;
- the first fetch skips every out-of-window row;
- the backlog rule cannot trigger in production.

### Story changes (amendment 2)

- **S-056.8, S-056.9, S-056.10 and S-056.12** are marked "Replaced by amendment 2". Their tests are removed. Skipped
  rows now live in History, and Send all ready is no longer date-bound.
- **S-056.13 to S-056.18** are new, from the amendment.
- **S-056.19, S-056.20 and S-056.21** are new, from the review.

### Merge danger (amendment 2): read before 07:30

- **The first auto run sends the backlog.**
  - At each sequence's first due time, auto's fetch re-checks all the old ready rows (about 661 per the amendment).
  - It skips those whose status, page or order window no longer fits, and queues the rest, with every sender rule and
    the daily caps applied.
  - That is the amendment's intent: no stuck rows.
- **The first fetch after deploy also skips every unsent row outside its window,** including old failed, no PSID and
  no config rows. They all appear in that day's History as "The order is older than the sequence's N-day window".
  Worth telling staff.
- **No migration.**
- **The default-queue worker** for Fetch new (amendment 1) still applies.
- **Open tabs:** a To send tab opened before the deploy should be reloaded.

## Amendment 3: what the customer saw, and Open in Botcake

The amendment was saved verbatim to the main checkout's `handoff/056-chatblast-app/AMENDMENT-3.md` (git-ignored, not
committed). It is treated as part of the brief.

### What changed

1. **"What the customer saw"** in the row panel, on both To send and History (`ChatblastController::show`,
   `customer_saw`).
   - **The text:** the page's stored shipping template body (`botcake.template.shipping.text`, spec 052) with `{{1}}`
     replaced by the row's text, shown as a grey chat bubble.
   - **Unknown template:** when there is no body, the stored value is a marker (it starts with `!`: not checked, not
     approved, not found, deleted) or the body has no `{{1}}`, the bubble shows the row text alone with "Template text
     unknown: run Test token on the page".
   - **Stored data only:** no Botcake call is made.
   - **Unsent rows** read "What the customer will see:" (from the review).
2. **"Open in Botcake ↗"** opens in a new tab (`rel="noopener noreferrer"`). It shows when the row has a PSID and the
   page has `botcake.page_id`.
   - The URL format lives in one place, `config('services.botcake.inbox_url')`, set by `BOTCAKE_INBOX_URL`. The default
     is `https://botcake.io/pages/{page_id}/live_chat`, and `.env.example` documents it.
   - `{psid}` is supported in the format.
   - **The format is not confirmed.** Botcake's public docs and API directory show no live-chat or inbox URL, with or
     without the customer. So the default is the page's inbox without the customer, and even that path is a best guess.
     Someone logged in to Botcake should open one page's live chat, copy its address, and set `BOTCAKE_INBOX_URL`, with
     `{psid}` if the address names the customer.
3. **History lists sent rows with the first line of what the customer saw** (template plus text). Other rows keep the
   first line of their own text.

### Case table (amendment 3)

Red runs, before the code:

- **S-056.22:** `customer_saw` was null.
- **S-056.23:** `botcake_url` was null.
- **S-056.24:** the first line was not the template's.
- **S-056.25:** the strings were not in the page.

| Case | Test | Result |
|---|---|---|
| S-056.22 | `ChatblastCustomerSawTest::test_S_056_22_the_panel_shows_what_the_customer_saw_from_stored_data_only` (4 data sets: body, not checked, not approved, none; no HTTP sent) | pass |
| S-056.23 | `ChatblastCustomerSawTest::test_S_056_23_open_in_botcake_needs_a_psid_and_the_page_id` | pass |
| S-056.24 | `ChatblastCustomerSawTest::test_S_056_24_history_lists_sent_rows_with_the_first_line_the_customer_saw` | pass |
| S-056.25 | `ChatblastCustomerSawTest::test_S_056_25_the_panel_has_the_chat_bubble_and_the_botcake_link_in_a_new_tab` and the browser check | pass |

**Suite:**

- `tests/Feature/Chatblast`: `Tests: 171, Assertions: 1138`, all passing.
- The full suite on `f2311a32`: `Tests: 2 failed, 1489 warnings, 158 passed (18687 assertions)`. The two failures are
  the known `ExampleTest` and `NightRun\ImportStartTest`.
- The review fix (`d73daf8a`) changed only one label in the script. `ChatblastCustomerSawTest` passed again and the
  script parses.

### Browser check (amendment 3)

The check ran on the scratch app, with a made-up template "Good day po!\n{{1}}\nSalamat po sa pag-order." and page ID
100001 on the scratch page. Screenshots `panel-sent.png` and `panel-ready.png`, with `evidence-amendment-3.txt`, are in
the main checkout's `handoff/056-chatblast-app/screens/amendment-3/`.

- **History, sent row:** the list's first line was "Good day po!". The panel bubble read "Good day po! / Hello Buyer 2
  / Salamat po sa pag-order.", and the link "Open in Botcake ↗" went to `https://botcake.io/pages/100001/live_chat`
  with target `_blank` and rel `noopener noreferrer`.
- **To send, ready row:** the bubble was composed the same way, with the same link.
- **No JS errors.**

### Review (amendment 3)

One `skeptic-reviewer` pass at standard depth. It found no blockers and no majors. It confirmed:
- no XSS: the bubble uses `textContent` and the link uses `setAttribute`, with the PSID URL-encoded;
- no Botcake call;
- the marker check matches `BotcakeFlowNames`.

One minor was fixed: unsent rows now read "What the customer will see". The rest are accepted in `TODO.md`:
- the panel uses the page's current template, not the one in use when the row was sent;
- a real body starting with `!` would read as unknown;
- S-056.25 checks strings only.

### Story changes (amendment 3)

- **S-056.22 to S-056.25** are new, from the amendment.

## Amendment 4: To send refreshes itself; Refresh now; J&T at build and now

The amendment was saved verbatim to the main checkout's `handoff/056-chatblast-app/AMENDMENT-4.md` (git-ignored, not
committed). It is treated as part of the brief.

### What changed

1. **The To send list refreshes itself** (`Chatblast::refreshIfDue()`, called at the top of `chatblast:auto`).
   - **When:** `chatblast:auto` already runs every 5 minutes on Likha (the scheduler) and on Incepxion (its cron). Once
     per hour, at the first run at or after :15 (06:15 to 23:15 Manila, about 10 minutes after the hourly J&T upload),
     it runs one full fetch: build plus re-check, with the 60 s J&T budget.
   - **Never sends.** It also runs while sending is paused.
   - **Uses the existing build lock.** When a fetch is already running, it is skipped and tried again on the next
     5-minute run in the same hour.
   - **A failing refresh** is reported, is not retried until the next hour, and never stops auto's sends in that run.
   - **Incepxion needs no new cron line:** this is the amendment's preferred option, because `chatblast:auto` itself
     runs the hourly refresh.
   - **Steps with their own time are not built by the refresh** in auto sequences. Auto builds them at their own
     time, as before. Otherwise a row built after a step's time would go out at the next unrelated send time, hours
     late; the review found this.
   - **Trade-off:** in auto sequences, those steps appear in To send only once their time has come.
2. **J&T check at fetch time** (Delivering, "requires On Delivery").
   - The build already tracked these waybills with J&T within the budget. It already left out candidates whose latest
     scan is not On Delivery, and skipped (moved to History) unsent rows whose new scan is not On Delivery.
   - **New:** the tracking order puts the **newest orders first** (`orderByDesc('mo.id')`). When the budget runs out,
     the oldest are the ones not reached; they are checked on the next hourly refresh or right before sending, as
     before.
3. **"Fetch new" is now "Refresh now"**, a small secondary button. Beside it: "Updated HH:MM · next refresh HH:MM".
   - "Updated" is the last full fetch (the hourly refresh, or Refresh now with no sequence chosen).
   - It shows the date (e.g. "Oct 18 14:15") when that was not today, so a stopped scheduler is visible.
   - "next refresh" is the next :15 slot, or 06:15 the next morning.
4. **The row panel shows "J&T at build"** (the stored `jnt_status`) **and "J&T now"** (`from_jnts`, the furthest
   status).

### Case table (amendment 4)

Red runs, before the code:

- **S-056.26:** `['WB-1' => 'ready']` expected at 09:15, got `[]`.
- **S-056.27:** the first J&T call did not start with the newest waybill.
- **S-056.28:** `state.refresh` was null.
- **S-056.29:** `jnt_now` was missing.
- **S-056.30:** 'WB-1 D2 queued' at 18:00. It still fails that way with the service change set aside.
- **S-056.31:** the RuntimeException stopped the command.

| Case | Test | Result |
|---|---|---|
| S-056.26 | `ChatblastAutoTest::test_S_056_26_the_to_send_list_refreshes_itself_at_quarter_past_each_hour_and_never_sends` | pass |
| S-056.27 | `ChatblastTrackTest::test_the_build_tracks_for_at_most_60_seconds_and_leaves_the_rest_pending` (newest first, oldest 20 left pending) | pass |
| S-056.28 | `ChatblastToSendTest::test_S_056_28_to_send_shows_when_it_was_updated_and_offers_refresh_now_instead_of_fetch_new` | pass |
| S-056.29 | `ChatblastToSendTest::test_S_056_29_the_panel_shows_the_j_and_t_status_at_build_and_now` | pass |
| S-056.30 | `ChatblastScheduleTest::test_S_056_30_the_hourly_refresh_never_builds_a_step_with_its_own_time_so_it_is_not_sent_late` | pass |
| S-056.31 | `ChatblastAutoTest::test_S_056_31_the_refresh_runs_until_23_15_and_a_failing_refresh_never_stops_auto` | pass |

These existing tests were changed:

- `ChatblastAutoTest::test_an_auto_campaign_builds_and_queues_at_each_send_time_once_a_day` and
  `ChatblastScheduleTest::test_auto_fires_hourly_inside_the_range_only_and_once_per_hour` now expect rows built as
  `ready` by the refresh at 08:59 and 09:30. Auto still queues them only at their time.
- `ChatblastEnglishTest` expects "Refresh now".
- `ChatblastDashboardListTest` expects the "press Refresh now again" error text.

**Suite:**

- `tests/Feature/Chatblast`: `Tests: 176, Assertions: 1173`, all passing.
- The full suite on `1509cf12`: `Tests: 2 failed, 1494 warnings, 158 passed (18722 assertions)`. The two failures are
  the known `ExampleTest` and `NightRun\ImportStartTest`.

### Browser check (amendment 4)

The check ran on the scratch app. Evidence is in the main checkout's `handoff/056-chatblast-app/screens/amendment-4/`
(`a4-to-send.png`, `evidence-amendment-4.txt`).

- **Refresh now:** a small grey bordered button, with "Updated 18:01 · next refresh 19:15" at 18:21.
- **Panel:** for a row built as Delivering whose waybill is now Delivered, it showed "J&T at build: Delivering" and
  "J&T now: Delivered".
- **No JS errors.** This ran before the review fix, which changed only the server.

### Review (amendment 4)

One `skeptic-reviewer` pass (standard depth, with anything that can change what is sent judged as high), then a
re-check of the fix (`1509cf12`).

- **One major, fixed and closed:** the refresh built steps with their own time after that time, and auto then sent
  them hours late (S-056.30).
- **Fixed minors:**
  - a throwing refresh stopped auto's sends and retried every 5 minutes (S-056.31);
  - "Updated" had no date;
  - there were no tests for the 23:15 and 00:15 slots or for a build that throws.
- **Accepted, with reasons in `TODO.md`:**
  - newest first applies to new candidates only;
  - Refresh now and a manual-to-auto switch can still build a timed step late;
  - a race between the lock release and the slot write;
  - rider-missing `no_config` rows from budget-limited builds.

### Story changes (amendment 4)

- **S-056.26 to S-056.29** are new, from the amendment.
- **S-056.30 and S-056.31** are new, from the review.

### Merge danger (amendment 4)

- **Load:** the first `chatblast:auto` run at or after :15 each hour, 06:15 to 23:15, now runs a full fetch, with up to
  60 s of J&T and a build over all sequences. `withoutOverlapping(30)` only skips 5-minute runs while it works.
- **Incepxion:** nothing to add. Its existing `chatblast:auto` cron runs the refresh, provided that cron runs at least
  every few minutes.
- **No migration.**

## Independent review (fb1c7283..9a352301): fixes

The independent review found one major and two minors. Each is fixed with a regression test. The rest of that review
was clean: no double send, nothing sent on a manual site, access, the 301, XSS and callers.

### What changed

1. **Major: overlapping hourly refreshes** (`Chatblast::refreshIfDue`, `Chatblast::build`).
   - **The hour is claimed before the build.** It uses `Cache::add("chatblast-refresh-slot:Y-m-d H")`, which is
     atomic. The next `chatblast:auto` in the same hour, including the one from Incepxion's plain cron with no overlap
     guard, does not start a second refresh while the first is still running.
   - **When the claim is released:** only when the build is busy (another fetch holds the lock) or throws, so it is
     tried again on the next run.
   - **The build lock** now lasts `BUILD_LOCK_SECONDS` = 1800 s instead of 300 s. That is longer than the worst case
     (60 s of J&T plus the build over all pages), and the lock is still released in `finally`. So no second build can
     run beside a long one.
   - **If a process is killed outright,** the lock stays until it expires, at most 30 minutes. The Refresh now job still
     releases its own lock in `failed()`.
2. **Minor: Delivering rows when J&T can't be reached** (`Chatblast::refreshRider`, at send time).
   - **Earlier-day rows:** when the J&T re-track gets no answer, a Delivering row built on an earlier day is **not
     sent**. It goes back to `ready` with "J&T not reached; will retry at the next send time", and no message reaches
     the customer.
   - **Rows built today** keep the current behaviour: they are sent with the version without a rider, or become
     `no_config` when every version needs `{rider}`.
3. **Minor: auto's own build had no guard** (`chatblast:auto`). A sequence whose build throws is reported and skipped
   for this run; its time is not recorded, so it is tried again on the next run while the time is still valid. The
   later sequences still run.

### Behaviour changed by the fix

- **S-056.31:** a refresh that throws used to wait until the next hour. Now its claim is released and the next 5-minute
  run tries again, as the review asked ("clear the claim only when the build is busy or errors out"). Auto's sends
  still go out in that run.

### Case table (independent review)

Red runs, before the code:

- **S-056.32:** the second refresh at 06:21 ran, and a second build got the lock after 300 s.
- **S-056.33:** both data sets were `sent`.
- **S-056.34:** `RuntimeException: database went away` stopped the run.
- **S-056.31:** after the change, it expects 2 tries (got 1 before the code).

| Case | Test | Result |
|---|---|---|
| S-056.32 | `ChatblastAutoTest::test_S_056_32_a_long_refresh_is_never_started_twice_and_no_other_build_runs_beside_it` | pass |
| S-056.33 | `ChatblastTrackTest::test_S_056_33_an_earlier_days_delivering_row_is_not_sent_when_jnt_gives_no_answer_at_send_time` (2 data sets) | pass |
| S-056.34 | `ChatblastAutoTest::test_S_056_34_a_sequence_whose_build_throws_never_stops_the_next_sequence` | pass |
| S-056.31 | `ChatblastAutoTest::test_S_056_31_the_refresh_runs_until_23_15_and_a_failing_refresh_never_stops_auto` (now expects the retry) | pass |

**Changed test:** `ChatblastTrackTest::test_yesterdays_rider_is_dropped_when_the_send_time_track_gets_no_answer`
pinned the old behaviour (an earlier-day row sent without J&T). It is replaced by S-056.33. Today-built rows stay
covered by `test_a_jnt_error_at_send_time_still_sends_with_the_fallback_and_is_not_stamped_as_tracked`.

**Suite:**

- `tests/Feature/Chatblast`: `Tests: 178, Assertions: 1176`, all passing.
- The full suite: `Tests: 2 failed, 1496 warnings, 158 passed (18725 assertions)`. The two failures are the known
  `ExampleTest` and `NightRun\ImportStartTest`.

### Story changes (independent review)

- **S-056.31:** a failing refresh is now retried on the next run, not the next hour.
- **S-056.32, S-056.33 and S-056.34** are new.

### Merge danger (independent review)

- **Stuck lock after a hard kill:** a killed build process (not a job, which releases its own lock) leaves the build
  lock for up to 30 minutes instead of 5. During that time Refresh now answers "A fetch is already running", and
  refreshes and auto builds wait.
- **No migration.**

## Amendment 5: History shows what was sent

The amendment was saved verbatim to the main checkout's `handoff/056-chatblast-app/AMENDMENT-5.md` (git-ignored, not
committed). It is treated as part of the brief.

### What changed

1. **History shows only sent rows by default.** That is status `sent`, plus `unknown` (send_flow was called but the
   answer was unclear), which is labelled **"Sent (unconfirmed)"**.
   - The "all" chip is gone.
   - The first chip is **Sent N** (sent + unconfirmed), selected by default; the hidden `status` starts at `sent` on
     History.
   - The server treats a missing or unknown chip as `sent` (`ChatblastController::HISTORY_CHIPS`). The total follows
     the chosen chip.
2. **The other processed states are chips, not selected by default:**
   - **Failed N** (failed + field mismatch);
   - **Skipped N**;
   - **No PSID N**;
   - **No config N**.

   Clicking a chip shows only that state, and clicking it again or clicking Sent goes back to Sent. **Retry failed**
   shows only while the Failed chip is selected; it still retries failed and field mismatch rows in the History
   filter.
3. **An empty Sent view** says "Nothing sent in this range yet." Other chips say "No processed messages in this
   filter."

To send is unchanged.

### Case table (amendment 5)

Red runs, before the code:

- **S-056.35:** the default list held all 7 rows instead of the sent 2.
- **S-056.36:** the History chip strings were not in the page.

| Case | Test | Result |
|---|---|---|
| S-056.35 | `ChatblastToSendTest::test_S_056_35_history_shows_only_what_was_sent_by_default_and_each_other_state_on_its_chip` | pass |
| S-056.36 | `ChatblastToSendTest::test_S_056_36_the_history_page_has_sent_first_no_all_chip_and_retry_only_on_failed` and the browser check | pass |

These existing tests now pick the chip they read:
- S-056.17 and S-056.20: the default is sent only, and the other states are read through their chips;
- S-056.24: the sent and failed chips;
- `ChatblastEnglishTest`: the Skipped chip for the old reasons.

`tests/Feature/Chatblast`: `Tests: 180, Assertions: 1206`, all passing.

### Browser check (amendment 5)

The check ran on the scratch app (3 sent and 5 skipped today). Evidence is in the main checkout's
`handoff/056-chatblast-app/screens/amendment-5/` (`a5-history.png`, `evidence-amendment-5.txt`).

- **Default:** the chips read "Sent 3* · Failed 0 · Skipped 5 · No PSID 0 · No config 0", only sent rows showed, and
  Retry failed was hidden.
- **Skipped:** only the 5 skipped rows.
- **Failed:** an empty list with Retry failed shown.
- **Sent:** back to the default.
- **An empty date range:** "Nothing sent in this range yet."
- **No JS errors.**

### Story changes (amendment 5)

- **S-056.35 and S-056.36** are new.
- **S-056.17** now reads History's processed states through their chips. Its Then ("History lists the rest within
  From/To, with status chips") now means sent by default and the rest on their chips.

## Amendment 6: no Log out, no sticky, one clean look

The amendment was saved verbatim to the main checkout's `handoff/056-chatblast-app/AMENDMENT-6.md` (git-ignored, not
committed). It is treated as part of the brief.

### What changed

1. **No Log out** in the Chatblast top bar. "← Likha" and the user's name stay.
2. **Nothing sticks or floats while scrolling.** Every page scrolls as a normal document.
   - **Gone:** the sticky table header on To send and History, the sticky header row and sticky Page column of the
     page settings grid, and the table's own scroll boxes (`max-h-[70vh]`, `max-h-[75vh]`). The top bar was already
     not sticky.
   - **Page settings grid:** it keeps no sticky header at all, the "prefer no sticky" option. Its header row has no
     band (transparent, light bottom line) and no right borders.
   - **The row detail panel** on To send and History is now a **centred modal** instead of a fixed side panel against
     the table. It closes with ✕, Escape or a click outside. All its content and buttons are unchanged.
   - **The page settings value history drawer** became the same kind of modal.
3. **One look across the five pages.** Shared styles live in the layout (`cb-btn`, `cb-btn-primary`, `cb-card`,
   `cb-table`, in Tailwind's components layer so utilities such as `hidden` still win).
   - **Page header:** the title, then a one-line description:
     - To send: "Everything still to send, whatever its build date. The list refreshes itself every hour."
     - History: "What was sent, by send date. Failed, skipped and the other outcomes are on their own chips."
     - Messages and Sequences: their help line, shortened.
     - Page settings: "The Botcake settings of every page: ID, token, flows, message field and template. Values save as
       you type."
   - **Filters:** one tidy row with rounded inputs.
   - **Buttons:** one primary blue per page; everything else is a neutral white button with a border.
     - Primaries: Send all ready (To send), Retry failed (History, Failed chip), Create (Messages and Sequences), Sync
       all (Page settings), and Send in the row modal.
     - Pause, Resume, Stop, Refresh now, Send selected, Show, the pager and the grid's Test and Sync now are neutral.
   - **Chips:** small rounded pills.
   - **Tables:** light row dividers (slate-100), comfortable row height, small uppercase grey headers with no band, and
     no heavy borders.
   - **Ellipsis:** Page and Customer cells clip with an ellipsis and show the full text as a tooltip, as the Message
     cell already did.
   - **Cards:** a thin border instead of a shadow.
4. **Every function stays.** Same element ids, same scripts and endpoints. All UI text is English.

### Browser check (amendment 6)

Before and after screenshots at 1366 px (full page, plus the To send row panel) are in the main checkout's
`handoff/056-chatblast-app/screens/amendment-6/` (`before-*.png`, `after-*.png`, `evidence-before.txt`,
`evidence-after.txt`).

| Page | Sticky or fixed elements on screen, before → after | Log out | 1366 / 1280 px (`scrollWidth <= clientWidth`) |
|---|---|---|---|
| To send | every table header cell → none | gone | 1351 ≤ 1351 / 1265 ≤ 1265 |
| History | every table header cell → none | gone | 1366 ≤ 1366 / 1280 ≤ 1280 |
| Messages | none → none | gone | 1351 ≤ 1351 / 1265 ≤ 1265 |
| Sequences | none → none | gone | 1351 ≤ 1351 / 1265 ≤ 1265 |
| Page settings | the Page column and header cells → none | gone | 1366 ≤ 1366 / 1280 ≤ 1280 |

- **Visibility after the change:**
  - To send: Pause shows and Resume is hidden while sending runs.
  - History: Retry failed is hidden on Sent and shows on the Failed chip.
  - Page settings: the Sync all Pause, Resume and Stop and the history modal stay hidden until needed.
  - The row modal is hidden until a row is clicked.
- **A bug caught here:** in the first "after" run, the shared button style overrode `hidden`, and Pause and Resume both
  showed. Moving the shared styles into the components layer fixed it, and the run was repeated.
- **No JS errors.**

### Tests (amendment 6)

- **Changed:** `ChatblastAppTest::test_S_056_1…` now asserts that "Log out" is **not** in the Chatblast pages. All other
  tests are unchanged: the element ids, the `h1` and the texts they check stayed.
- `tests/Feature/Chatblast` and `tests/Feature/PageSettings`: `Tests: 296, Assertions: 2182`, all passing.

### Story changes (amendment 6)

- **S-056.1:** "Log out" is removed from the Then; the top bar shows "← Likha" and the user's name.
