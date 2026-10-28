# Result 174: Page-based or Ad-based reply values, expanding rows, ad status

Status: done. Branch `feat/174-ad-based-pages`, based on 88f49262. Not pushed, not deployed.

## What shipped

1. **The switch** (`AdGroups::adBased`, `setMode`).
   - Storage: one `page_settings` row, provider `adgroup`, key `mode`, value `ad`. No row (or an inactive one) means **Page-based**, the default for every page.
   - Switching back to Page-based deactivates the row, which keeps it as history (`replaced_at`, `replaced_by`). Switching to the mode a page already has writes nothing.
   - The row is written only by `AdGroups`, as a system write. Since 172, `PageSettings::validate` refuses provider `adgroup` from every other writer. The `mode` key is never read as a group: groups are only `gN.*` keys.
   - Route: `POST /chatbot/ad-groups/mode` `{page, mode: page|ad}`. Same access as page values (NavAccess `/chatblast`; others get 403 and nothing is written) and the same 30-per-minute write bucket as 172.
   - Pages that already have groups stay Page-based until someone switches them.
2. **Page-based ignores the groups everywhere a run reads them.** The groups are kept, not deleted.
   - `forCustomer` returns page values. It is used by the runner (handle, resume, skip), the sheet preview and sequence subscribe.
   - `forSequence` returns page values and leaves an existing pin alone. That pin applies again if the page goes back to Ad-based.
   - `pin` writes nothing.
   - `ChatbotPage::runs` counts group flows only on Ad-based pages.
   - On an Ad-based page, 172's resolution is unchanged.
   - The Test tab keeps its explicit "Ad group" pick, because a test chooses a group on purpose.
3. **Ad status** (`AdGroups::statuses`). It reads only `ads_manager_reports`; there is no Meta call. It is cached 300 s per ad list. Two grouped queries, both filtered by `ad_id IN (…)` on the existing index `ads_report_adid_idx`, so no migration was needed:
   ```sql
   -- 1. per ad
   SELECT ad_id, MIN(day) AS first_day, MAX(day) AS last_day,
          MAX(CASE WHEN amount_spent_php > 0 THEN day END) AS last_spend,
          MIN(CASE WHEN NOT (<both active>) THEN day END) AS first_off
   FROM ads_manager_reports WHERE ad_id IN (?, …) AND day IS NOT NULL GROUP BY ad_id
   -- 2. the rows of each ad on its own latest day
   SELECT a.ad_id, SUM(COALESCE(a.amount_spent_php, 0)) AS spend, MAX(CASE WHEN <both active> THEN 1 ELSE 0 END) AS on_now
   FROM ads_manager_reports a
   JOIN (SELECT ad_id, MAX(day) AS d FROM ads_manager_reports WHERE ad_id IN (?, …) AND day IS NOT NULL GROUP BY ad_id) m
     ON a.ad_id = m.ad_id AND a.day = m.d
   GROUP BY a.ad_id
   ```
   `<both active>` is `LOWER(TRIM(COALESCE(campaign_delivery,''))) LIKE 'active%' AND LOWER(TRIM(COALESCE(ad_set_delivery,''))) LIKE 'active%'`, the same test the Ads Manager report uses.

   **Rules:**

   | Status | When | Shown as |
   |---|---|---|
   | as of | The latest report day among the page's ads, shown once per expanded page. | "as of <day>" |
   | **On** | The ad has a row on the as-of day, and that day both deliveries are active or its spend is above 0. | On |
   | **Off** | Every other ad in the reports, including an ad whose rows stop before the as-of day. The report has rows only on delivery days, so a paused ad simply stops appearing. | "Off since <date> (N days)" |
   | **New** | The ad's first report day is within the last 3 days, today included. If the ad is not in the reports, its first customer in the 30-day window is within the last 3 days. | badge |
   | **No report data** | The ad is not in `ads_manager_reports`. Customers still show. | "No report data" |
   | **all ads off** | A group has ads and every one of them is Off. | the group is muted |

   For Off, "since" is the day after the last day with spend. If the ad never spent, it is the first non-active day; if no day was non-active, the day after its last row. N = as-of − since + 1.
4. **Data for the screen.**
   - `GET /chatbot/ad-groups` now returns `mode`, `as_of`, `new_unassigned`, and for each group its ads as objects: name, status, off_since, off_days, new, customers and last time, plus `all_off`.
   - "Ads not in a group yet" rows carry the same fields. They are sorted New first, then On, then by customers.
   - `/chatbot/data` rows carry `reply` (`page` or `ad`). Ad-based pages also carry `ad_new`, the number of New ads not in a group.
5. **New writes**, all through 172's per-page lock and checks, with history:
   - `POST /chatbot/ad-groups/value` sets one group cell. An empty value means the group uses the default. A key that is page-only is refused.
   - `POST /chatbot/ad-groups/remove-ad` takes one ad ID out of a group.
   - `POST /chatbot/ad-groups/add` now also accepts typed text (commas, spaces or new lines). Non-digits are refused with a message. An ID already in another group of the page is refused, naming that group.
   - History and Restore go through 172's routes.
6. **Screen** (`public/js/chatbot-adgroups.js`, rewritten as the table's sub-rows, and `chatbot/index.blade.php`).
   - Every page row has **Replies: Page-based / Ad-based**, with a confirm before switching.
   - An Ad-based row has an **Ad groups** button with an "N new" badge.
   - Expanded, these rows appear under the page row:
     1. a header line: what Ad-based means, the as-of day, and "N new ad IDs not in a group yet";
     2. **Default**: the page's own cells, editable exactly like the page row;
     3. **one row per group**: name, Rename, History (with Restore) and Delete; a FLOW select; one cell per column. A group value can be edited (✎). An empty cell shows the default value muted, with "uses default". A page-only column shows "page value", and SHOP DETAILS says it is built from the group's item;
     4. under each group, its ad IDs: name, New, status, customers in 30 days and last time, with Remove. "Show all N" appears past 5 ads, and there is a box to type or paste more IDs;
     5. **+ Add ad group** (name plus ad IDs) and **Ads not in a group yet**: search, tick, then add to a group or a new one.
   - Page-based pages stay single rows.
7. **Edit window: the section was replaced by a pointer.** The 172 Ad groups section in the page's Edit window is gone, so there is one place to edit. It now says either "This page is Ad-based… press 'Ad groups' under the page name" or how to switch to Ad-based.

## Case table

Final run, same command as the baseline: `php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/MetaAds`

| Run | Tests | Assertions | Failures | Skipped | Deprecations |
|---|---|---|---|---|---|
| Baseline at 88f49262 | 1572 | 46958 | 0 | 1 | 60 |
| Final (after the review fix) | 1580 | 47033 | 0 | 1 | 60 |

| Case | Test | Result |
|---|---|---|
| S-174.1 | `AdBasedPagesTest::test_S_174_1…` | pass |
| S-174.2 | `AdGroupRunTest::test_S_174_2_a_page_based_page_ignores…`, `…test_S_174_2_a_sequence_on_a_page_based_page…` | pass |
| S-174.3 | `AdBasedPagesTest::test_S_174_3…` (server: value, history, Restore, page-only refused) and the 390 px check (screen: muted default, "uses default", "page value") | pass |
| S-174.4 | `AdBasedPagesTest::test_S_174_7…` (ad list fields), `test_S_174_4…` (remove with history) | pass |
| S-174.5 | `AdBasedPagesTest::test_S_174_5…` | pass |
| S-174.6 | `AdBasedPagesTest::test_S_174_6…` | pass |
| S-174.7 | `AdBasedPagesTest::test_S_174_7…` (On, Off since 5 days, New, No report data, a paused ad with no rows, never spent, all ads off) | pass |
| S-174.8 | `test_S_174_1…` (`reply` is `page` by default) and the 390 px check: a Page-based page renders 1 row | pass |
| S-174.9 | full run above | pass |
| S-174.10 (P2) | headless Chrome, 390 px iframe (see below) | pass |

**390 px check.** The browser-checker agent had no browser tools here, so I ran headless Chrome myself.
- Setup: the real `/chatbot/pages` HTML rendered by the app, with the real scripts and a mocked `fetch`. Headless Chrome cannot make a window narrower than about 500 px, so the page sat in a 390 px iframe.
- The probe found:
  - no horizontal page overflow;
  - Page Q (Page-based): 1 row;
  - Precious (Ad-based): page row plus Default, 2 group rows and 4 full-width blocks, all inside the table box (354 of 358 px);
  - "uses default", "page value", "all ads off", "Off since … (5 days)" and "Show all 7" present;
  - no script errors.
- After the review fix: the new-group form keeps typed text across a re-render, and it no longer steals focus.
- I looked at the screenshots: group names, ad names and statuses wrap and stay readable.
- Not checked against the logged-in live page.

## Story changes

- **S-174.7** gained "an ad whose report rows stop before the as-of day is Off". It came from the review: the report has rows only on delivery days, so a paused ad just disappears.
- **S-174.2** gained a sequence variant: on a Page-based page a sequence sends page values and pins nothing.
- **S-174.8** is checked by the `reply` field and the browser probe. There is no PHP test of the rendering; the table is drawn by JS.

## Review

The skeptic reviewer ran at adversarial depth on the whole diff and raised two majors. Both were fixed in one loop (commit 303570b0):
1. **A paused ad showed "On".** Its own last row usually has spend. Now an ad with no row on the as-of day is Off, since the day after its last spend. Tested.
2. **The sequence path on a Page-based page had no test.** Added: page values are sent and no pin is written.

Two minors were fixed in the same commit:
- the new-group form lost its text and stole focus on every re-render;
- `AdGroups::unassigned` had no callers left, and was removed.

The other minors are accepted with reasons in `TODO.md` (section 174). The brief asks to fix majors once, so there was no second review round.

## Notes and conflicts

- **New** follows the spec literally only when the ad has no report data. If the ad is in the reports, its first report day decides. The customer window is 30 days, so "first customer" would mark an old ad as New after a quiet month (TODO 174).
- **as of** is per page: the latest report day among that page's ads, not the whole table. Another account's fresher upload does not make this page's ads look Off.
- The kit asks for the reviewer's "go" on the plan before code. The brief said to write the plan and build, so I followed the brief; the plan is committed (`docs/plans/174-ad-based-pages.md`).
- Rows with only `reporting_starts` and no `day` are not counted for status (TODO).

## How marketing sets Precious Lopez up by ad

1. Open **Chatbot → Pages** and find **Precious Lopez**.
2. Under the page name, set **Replies** to **Ad-based** and confirm. Until you make groups, every customer still gets the page values.
3. Press **Ad groups** under the page name. The rows under the page show:
   - **Default**: the page values, for customers whose ad is in no group or who have no ad;
   - **Ads not in a group yet**: New ads first, then ads that are On, then by customers. Each has its status (On, Off since … (N days), No report data) and the date the Ads Manager data is from.
4. Tick the ads of one item (e.g. Serum), choose **New group…** and press **Add N ads to group**. Type the name "Serum" and press **Create group**. You can also press **+ Add ad group** and paste the ad IDs (commas, spaces or new lines).
5. In the **Serum** row, press ✎ on the cells that differ from the page:
   - ITEM NAME IN JNT DASHBOARD, PRICE, QUANTITY, PICTURE;
   - Main Flow and Main Flow 2;
   - any Seq / Seq Img;
   - FLOW, from the select, only if Serum has its own flow.

   Cells you leave empty show the default in grey ("uses default"). Columns marked "page value" are set on the page only.
6. Repeat for each item. Under each group you can remove an ad ID or paste more. **History** shows every change, with **Restore**.
7. Check the groups now and then. A group whose ads are all Off is greyed with "all ads off". The "N new" badge on the page row counts new ads that are not in a group yet.
8. To stop using the groups, set Replies back to **Page-based**. The groups are kept for later.

## Deploy notes

- **No migration, no new table, no config, no env var.** The status queries use the existing index `ads_report_adid_idx`.
- **New routes** inside the chatbot group: `ad-groups/mode`, `ad-groups/value`, `ad-groups/remove-ad`. Run `php artisan route:clear` (or `route:cache` again) and `php artisan view:clear`.
- No Vite build is needed. `public/js/chatbot-adgroups.js` is versioned by its hash, and the layout uses the Tailwind CDN.
- Restart the queue workers (`php artisan queue:restart`). The runner and sequences read the new Page-based gate.
- **Behaviour at deploy:** every page is Page-based. A page that already has 172 groups stops using them until someone switches it to Ad-based. No page has groups today.
- After deploy, expand one Ad-based page once on MySQL to check the status and "seen" queries. The tests ran them only on sqlite.
- **Incepxion:** the same commits apply as they are. It is the same codebase, with no site-specific code here.

174: done — each page has a Page-based (default) / Ad-based switch; Ad-based pages expand in the pages table with Default, their ad groups' editable values, and each ad's status from the reports; no migration.
