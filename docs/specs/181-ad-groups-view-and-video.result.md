# Result 181: ad groups are viewed in the pages list (with each ad's video) and created in Edit

Status: done. Branch `feat/181-ad-groups-view-and-video`, based on fe54c227. Not pushed, not deployed.

## What shipped

1. **Pages list (view)** (`public/js/chatbot-adgroups.js` `subRows`). The Replies switch stays under the page name. Expanding an Ad-based page with **Ad groups** shows these rows:
   - a header line with what Ad-based means, "Ad status as of <day>", **"N ads not in a group yet (k new)"** and a **Manage in Edit** button;
   - **Default**: the page values;
   - **one row per group**: its name, number of ads and "all ads off", and its values in the page's columns;
   - under each group, **its ad IDs**: name, New, status (On / Off since … (N days) / No report data), customers in 30 days and last time, with "Show all N" past 5 ads.

   The list has no create, add, remove, rename, delete, History or "Ads not in a group yet" list.

   **Choice on group values:** they stay editable inline with ✎, and the group's FLOW select stays too. Page values are edited inline in the same table, so this keeps the two consistent.
2. **Edit window (manage)** (`panel`, `mount` and `unmount` in the same script, mounted from `openPe` in `chatbot/index.blade.php`).
   - For an Ad-based page, the Edit window's **Ad groups** section has:
     - each group with **Rename**, **History** (with Restore) and **Delete**;
     - its values (FLOW and every key a group may set), each showing its own value or "uses default: <page value>", with ✎;
     - its ad IDs with **Remove**, and a box to **paste** more;
     - **+ Add ad group**;
     - **Ads not in a group yet**, with search, tick and "Add to group" (an existing group or a new one).
   - **Manage in Edit** opens this window scrolled to that section, and focuses it.
   - A Page-based page's Edit window says how to make it Ad-based.
   - Group changes save at once, not with the window's Save.
   - Code reuse:
     - The list and the panel share one per-page state and the same helpers: the value editor, History, flow select, ad list, new-group form and unassigned block.
     - They also share the same 172/174 endpoints. No endpoint was added for writing.
     - A change made in Edit reloads the data, so the list behind the window shows it straight away.
3. **Ad video/creative.** In the list and in Edit, every ad ID is a button. It opens a small viewer showing:
   - the ad's **video or post**, as a Facebook plugin embed (the video plugin for reels and videos, the post plugin for other links), plus "Open on Facebook ↗";
   - its **headline** and **body**.

   If an ad has no stored link, or the link is not an https Facebook link, the viewer shows **"No preview"**. /owner/private has no link into Ads Manager by ad ID, so the viewer has none either.
4. **Access.** The new `GET /chatbot/ad-groups/ad?page=&ad=` uses the same check as page values (NavAccess `/chatblast`). Others get 403.
   - It answers only for an ad that is in one of the page's groups or was seen on the page in the last 30 days. Anything else gets 404, and a non-digit ID gets 422.
   - It is throttled at 120/min.
   - It makes no Meta call.
   - Nothing changed in resolution, statuses, storage or the Replies switch, and there is no new table.

## Where the media comes from

The viewer uses the same source as /owner/private (`AdsManagerCampaignsController::creativePreview`, shown by the "creative preview" modal there):

1. **`ad_campaign_creatives`, the row for the ad's `ad_id`**. Its `ad_link` is the Facebook post, reel or video URL. That table also gives `headline` and `body_ad_settings`. The rows are saved by the creatives screen and the upload.
2. If the ad has no row, **the row of its campaign** is used instead: the `campaign_id` comes from the latest `ads_manager_reports` row of the ad. The viewer then says "showing its campaign's".
3. A headline or body missing from the creative comes from the latest `ads_manager_reports` row (`headline`, `body`).

The link is embedded only if it matches `https://(subdomain.)facebook.com/…` or `https://fb.watch/…`. The iframe always points to `https://www.facebook.com/plugins/…`. The likely values were checked, and `ad_catalog` holds no media. Code: `AdGroups::creative` and `AdGroups::hasAd`.

## Case table

| Case | Test | Result |
|---|---|---|
| S-181.1 | 390 px probe of the real rendered page (see below) | pass |
| S-181.2 | 390 px probe: Edit has Rename, History, Delete, + Add ad group, unassigned list, paste, Remove; an ad added in Edit shows in the list, before and after closing. The writes are the 172/174 endpoints and tests | pass |
| S-181.3 | `AdPreviewTest::test_S_181_3_an_ad_with_stored_media…`, `…test_S_181_3_the_campaign_creative_is_the_fallback…` (allow-list table: `javascript:`, look-alike hosts, http), and the probe (iframe of the plugin; "No preview" for an ad without media) | pass |
| S-181.4 | probe: Page-based page = 1 row; `AdBasedPagesTest::test_S_174_1…` | pass |
| S-181.5 | `AdPreviewTest::test_S_181_5_only_editors_and_only_ads_of_the_page` (403, 404 for another page's ad or an unknown page, 422) | pass |
| S-181.6 | full run below | pass |
| S-181.7 (P2) | headless Chrome, page in a 390 px iframe | pass |

**Runs.** Both used `php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings`.

| Run | Tests | Assertions | Failures | Skipped | Deprecations |
|---|---|---|---|---|---|
| Baseline at fe54c227 | 1527 | 47078 | 0 | 1 | 63 |
| Final (before the review-minor tests) | 1530 | 47092 | 0 | 1 | 63 |

After the review I added assertions to `AdPreviewTest`, and it passes (3 tests, 26 assertions). The final run has no new failures.

**Red run:** `AdPreviewTest` failed first with "Expected response status code [200] but received 404" because the route was missing. Then it passed.

**390 px probe.**
- **Setup.** I rendered the real `/chatbot/pages` HTML in the test app with real seeded data: an Ad-based page with a long name and two groups, one of them with 7 ads, report rows, and ads not in a group. I also captured the app's real `/chatbot/data` and `/chatbot/ad-groups` JSON. The probe used the real scripts with a mocked `fetch`, and the page sat in a 390 px iframe in headless Chrome.
- **What it found:**
  - **List:** Default and group rows present; "as of"; "2 ads not in a group yet (2 new)"; Manage in Edit; 0 Remove buttons; 0 inputs; no add, create, rename or delete controls; 6 ad buttons; no horizontal overflow.
  - **Page-based page:** 1 row.
  - **Preview:** the iframe of the video plugin, then headline and body; the box is 374 px wide. An ad without media shows "No preview".
  - **Edit:** opens with focus on "Ad groups"; every manage control present; 0 elements past the width.
  - **Add:** pasting an ID into a group in Edit showed it in Edit, and in the list before and after closing.
  - No script errors.
- **Fixed along the way:** a stray "null" text in the viewer and in the Edit section.
- I checked the screenshots.
- **Not checked:** the logged-in live page. The browser-checker was not run; headless Chrome was used as in 172/174.

## Story changes

S-181 cases were added to `qa/stories.md` from the brief. **.3** gained "a link that is not an https Facebook link → No preview". **.4** gained "the Page-based Edit window says how to make it Ad-based".

## Review

The skeptic reviewer ran at standard depth on fe54c227..HEAD and found no blocker or major. On its minors:
- **Fixed with tests:** the link allow-list now has look-alike host and http tests; there is a body-from-report fallback test; and there is a render check for `data-ag-ad-url`.
- **Accepted with reasons in `TODO.md` (section 181):**
  - the creative lookup and embed URL repeat /owner/private's (reusing them means touching a Meta Ads controller other work is changing);
  - the ad button sits inside the checkbox label;
  - the shared throttle bucket;
  - the UI cases are checked by probe, not a committed test;
  - two untested fallbacks.

## Deploy notes

- **No migration, no new table, no config, no env var.**
- **New route** `GET /chatbot/ad-groups/ad` inside the chatbot group: run `php artisan route:clear` (or `route:cache` again) and `php artisan view:clear`.
- No Vite build is needed. `public/js/chatbot-adgroups.js` is versioned by its hash.
- No queue restart is needed for this change: no runner code changed.
- After deploy, click one ad ID on a live Ad-based page to see the Facebook embed load with a real stored link.
- **Incepxion:** the same commits apply as they are. It is the same codebase with no site-specific code here. An ad without a stored creative shows "No preview".

## Notes and conflicts

- The kit asks for the reviewer's "go" on a plan before code. The brief said to build in stages and commit each one, so I followed the brief; there is no separate plan file.
- No files of BotcakeSendPool or Meta Ads were touched.

181: done — the pages list shows an Ad-based page's groups and ad IDs read-only (group values still ✎ inline), the Edit window creates and manages groups, and clicking any ad ID shows its stored video/post, headline and body; no migration.
