# Result 172: per-ad setup on a chatbot page (ad groups)

Status: done. Branch `feat/172-ad-groups`, based on f48b1fb0. Not pushed, not deployed.

## What shipped

1. **Storage** (`app/Services/Chatbot/AdGroups.php`). There is no new table and no migration.
   - Groups are rows in `page_settings` under a new provider **`adgroup`**. This follows the precedent of `transfer`, `order_encode` and `analyst`, which each use their own provider so they never become a /chatbot column or a botfield.
   - A group is the number `N` in:

     | key | value |
     |---|---|
     | `gN.name` | the group's name |
     | `gN.ads` | the ad IDs: digits only, comma separated, sorted, at most 500 |
     | `gN.<key>` | an override of one value |

   - A missing `gN.<key>` row means the group uses the page value.
   - `N` is never reused on a page, so a deleted group's history is never mixed into a new group.
   - History works like the other page values: the old rows stay with `active=false` and `replaced_at`, and Restore writes the old value again. Delete deactivates every `gN.*` row.
   - Only `AdGroups` writes these rows, as system writes. `PageSettings::validate` refuses provider `adgroup` from every other writer.
2. **Keys a group can set.** These are the real botfield keys:

   | Field | Key |
   |---|---|
   | item name | `item_name_in_jnt_dashboard` |
   | price | `price` |
   | quantity | `quantity` |
   | picture | `picture` |
   | Main Flow | `main_flow` |
   | Main Flow 2 | `main_flow_2` |
   | the flow | `flow_id` |
   | the sequence values | `seq_N`, `seq_img_N` (N = 1–99; the page has 1–20 by default) |

   Everything else stays page-only: Fill-Up Form, Loop 3 Form, prompts, shop details, after-order fields, comment replies, AI settings, provider and connection keys, and On/Off.
3. **Resolution: which group a customer gets.**
   - **The source for "latest ad"** (`AdGroups::latestAd`), checked in this order:
     1. The newest of the customer's last 50 chats on this page (`botcake_chats`, by id) that carries a digits `payload.ad_id`. That is the Forbeast referral or the ad param of the Botcake webhook. Each chat has its own received time, so this is the newest ad the site actually saw.
     2. If no chat has one: `botcake_psids.ad_id` of this page, which is Botcake's current ad for the customer. It has no time of its own, so it only counts when no chat carries an ad.

     A chat without an ad does not clear an earlier ad.
   - **Applying the group.** If that ad is in a group of the page, each key the group sets replaces the page value. That includes SHOP DETAILS, which is rebuilt from the resolved item, price and quantity.
   - **Group flow.** A group with a `flow_id` runs that flow. If that flow was deleted, the page flow runs, still with the group's values. Off on the page still means no run.
   - **No groups.** A page with no groups makes no extra query and gives exactly the old output. Groups are read from the page's existing settings cache, which writes clear.
   - **Sequences.** When a customer enters a sequence, their group is pinned in their run state: `chatbot_customer_values` key `__ad_group_seq_<sequenceId>`, value `0` = page values. That is the existing per-customer table, not a new one. Every step uses the pin, so a sequence does not switch midway.
     - "Clear all fields" keeps the pins.
     - A subscriber who entered before the page had groups is pinned at their next step.
     - A pin to a deleted group means page values.
4. **Screen.** The page Edit window on /chatbot/pages has an **Ad groups** section below the page values (`public/js/chatbot-adgroups.js`). The pages list is unchanged. The section has:
   - the groups, with Edit, History (with Restore) and Delete;
   - "+ New group", where the editor shows the page value as each field's placeholder;
   - **Ads not in a group yet**:
     - the page's ads from the last 30 days, from chats and botcake_psids;
     - for each ad, its name from ads_manager_reports (campaign / ad set / headline), the number of customers and the last time;
     - the top 50 by customers, a search box, and a 120 s cache per page;
     - tick several ads, then "Add to group" (an existing group, or "New group…" which opens the editor with the ticked ads).

   You can type or paste ad IDs: commas, spaces or new lines all work, and anything that is not digits is refused.
5. **Rules on the server** (`ChatbotAdGroupController`, `/chatbot/ad-groups*`).
   - Access uses the same check as editing page values: `NavAccess /chatblast`. Anyone else gets 403 and nothing is written.
   - Throttles use the page-value buckets: 30 writes per minute, 120 history reads per minute, and restore on `chatbot-restore`.
   - One ad belongs to at most one group per page. Saving a duplicate is refused with "Ad 111 is already in the group "Item A"."
   - The duplicate check and the write run under a per-page lock. Inside the lock the groups are read fresh: the page's settings memo and cache are cleared first. Add and Restore merge inside that lock.
   - Restore goes through the same checks as Save.
   - A `flow_id` must be an existing flow.
   - The page must have settings.
6. **Test tab (P2).** An "Ad group" dropdown appears when the page has groups. The test run uses that group's values, and its flow when no flow is picked.

## Value readers

**Changed to the customer's group:**
- `ChatbotRunner::handle`, `skip` and `resume` (flow and botfields).
- `ChatbotSequences::subscribe` (pin) and `runStep` (botfields of the pinned group).
- `ChatbotInbound`: a page whose only flow is in a group still dispatches runs (`ChatbotPage::runs`).
- `ChatbotSheetController::preview` (sheet cells for a chat).
- `ChatbotTabsController` Test send and skip (chosen group).
- **Orders:** Encode order, Upload and the sheet cells read `{{SHOP DETAILS}}`, `{{PRICE}}`, `{{QUANTITY}}` and `{{ITEM NAME IN JNT DASHBOARD}}` from the run's botfields. So the order, and the J&T item name inside SHOP DETAILS, follow the group the customer was shown.

**Deliberately left on page values:**
- the Forbeast page values API (`PageValues`; returns page defaults only);
- `/chatbot/data` grid cells, Preview, History, Restore and Changes of page values;
- `ChatbotFields::columns`;
- the generic `/chatblast/page-settings` grid: it excludes provider `adgroup` in its build, its changes feed and "+ Page", and refuses writing or deactivating group rows;
- chatblast (botcake provider only) and `ChatblastSetupCheck`;
- `PageContentCheck`, `ChatbotPageCheck` and BotHealth (they check the page setup);
- `CommentCheck` and `CommentReplies` (comments are out of scope);
- `PageExpectedItem`: it never read page settings, only orders, ads_manager_reports and page_item_settings;
- `OrderVars`: it reads orders, not page values;
- `FlowEncode::orderFor`'s fallback to the order's own shop details;
- `PageTransfers` export: ad groups do not move with a page to the other site (see TODO).

## Case table

| Case | Test | Result |
|---|---|---|
| S-172.1 | `AdGroupRunTest::test_S_172_1…` + existing suites | pass |
| S-172.2 | `AdGroupRunTest::test_S_172_2…`, `AdGroupsTest::test_S_172_2…` | pass |
| S-172.3 / .4 | `AdGroupRunTest::test_S_172_3_and_4…` (7 data sets) | pass |
| S-172.5 | `AdGroupRunTest::test_S_172_5…` | pass |
| S-172.6 | `AdGroupRunTest::test_S_172_6…` | pass |
| S-172.7 | `AdGroupsTest::test_S_172_7…` (2 tests) | pass |
| S-172.8 | `AdGroupsTest::test_S_172_8…` (2), `AdGroupPagesTest::test_S_172_8…` (2) | pass |
| S-172.9 | `AdGroupsTest::test_S_172_9…`, `AdGroupPagesTest::test_S_172_9…` (2) | pass |
| S-172.10 | `AdGroupPagesTest::test_S_172_10…` | pass |
| S-172.11 | `AdGroupsTest::test_S_172_11…` | pass |
| S-172.12 | full run below | pass |
| S-172.13 (P2) | headless Chrome, 390 px iframe, 6 states (see below) | pass |
| S-172.14 (P2) | `AdGroupRunTest::test_S_172_14…` | pass |

**Runs.** Both used `php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode`. There are no separate Chatbot test folders; the chatbot tests live in Chatblast.

| Run | Tests | Assertions | Failures | Skipped | Deprecations |
|---|---|---|---|---|---|
| Baseline at f48b1fb0 | 1892 | 47725 | 0 | 1 | 82 |
| Final | 1916 | 47847 | 0 | 1 | 82 |

The final run has no new failures.

**390 px check.** The browser-checker agent had no browser tools, so I ran headless Chrome myself.
- The setup was a static copy of the Edit modal: the real script, the page's button styles, and a fake server.
- It sat in a 390 px iframe. Headless Chrome can't make a window narrower than about 500 px, so the iframe gives the true phone width.
- I checked 6 states:
  1. the list;
  2. two ads ticked;
  3. "New group…" opening the editor with the ticked ads;
  4. Edit;
  5. History with Restore;
  6. a search, which keeps focus.
- Fixed along the way:
  - a long group name in the "Add to" select overflowed by 31 px;
  - a stray "null" showed under the groups;
  - tap targets were under 24 px.
- After the fixes there is no horizontal overflow in any state.
- Not checked against the real logged-in page.

## Story changes

S-172 cases were added to `qa/stories.md` from the brief. I made these additions:
- **.4** gained three variants: "a later chat without an ad keeps the last ad", "Botcake's ad when no chat has one", and "a chat's ad wins over Botcake's". They pin down the source rule.
- **.5** gained "a deleted group flow falls back to the page flow" and "a page with only a group flow still runs", both from the review.
- **.7** gained "also when another worker changed the groups a moment earlier", from the review.
- **.9** gained "restore, delete and history act only on the posted page" and "restoring an ad list with an ad now in another group is refused", from the review.

## Review

The skeptic reviewer ran at adversarial depth on the whole diff and raised three majors. All three were fixed in one loop (commit 4f581d74):
1. **Stale read before the lock.** Add and Restore read the group before the lock. Because of the 2 s settings memo, two people adding the same ad to two groups could both succeed, or one person's added ads could be lost. Now the read and the merge happen inside the lock, after clearing the memo. Tested with a pre-warmed memo.
2. **No reply after a group flow is deleted.** Customers of a group whose flow was deleted got no reply. Now the page flow runs, and Save refuses flow IDs that don't exist. Tested.
3. **Missing cross-page and restore tests.** Added. Array input that gave a 500 now gives a 422.

The other minors are accepted with reasons in `TODO.md` (section 172). A second review round was not run: the brief asks to fix the majors once.

## How a marketing user sets up "Item A"

1. Open **Chatbot → Pages**, find the page (e.g. "Precious Lopez") and open its **Edit** window.
2. Scroll below the page values to **Ad groups**.
3. Under **Ads not in a group yet**, tick the ads for Item A. You can search by ad ID or by campaign name. If an ad isn't listed yet, type or paste its ID in step 5.
4. Choose **New group…** in the select and press **Add N ads to group**. The group editor opens with those ad IDs.
5. Type the name "Item A". Add more ad IDs if needed (comma, space or new line). Fill only what differs from the page:
   - ITEM NAME IN JNT DASHBOARD, PRICE, QUANTITY, PICTURE;
   - Main Flow and Main Flow 2;
   - FLOW, which is only needed if Item A has its own flow;
   - any Seq / Seq Img.

   Each empty field shows the page value it will use.
6. Press **Create group**. From now on, customers whose latest ad is one of these get Item A's values. Their orders (SHOP DETAILS, PRICE) carry Item A too.
7. Later, use **Edit** to change the group, **History** then **Restore** to bring back an old value, and **Delete** to send its ads back to the page values. To check the replies, use **Chatbot → Test**, pick the page and choose "Item A" in **Ad group**.

## Deploy notes

- **No migration, no new table, no new config, no env var.** The new routes are inside the existing chatbot group.
- No Vite build is needed. The chatbot layout uses the Tailwind CDN, and `public/js/chatbot-adgroups.js` is versioned by its hash.
- Clear the route cache if routes are cached (`php artisan route:cache` or `optimize`).
- Restart the queue workers so the `chatbot` queue picks up the new runner code (`supervisorctl restart` or `php artisan queue:restart`).
- **Incepxion:** the same commits apply as they are. It is the same codebase with no site-specific code here. Its lower ad coverage (~75%) only means more customers get the page values.
- After deploy, open one page's Ad groups once on MySQL to check the "Ads not in a group yet" query. It groups by `payload->ad_id`, and the tests ran it only on sqlite.

## Notes and conflicts

- The kit asks for the plan to get the reviewer's "go" before code. The brief asked to write the plan to `docs/plans/172-ad-groups.md` and build. I followed the brief, and the plan is committed.
- Botcake's `ad_id` meaning (first or latest ad) is not visible in the code. Chats with a referral come first in the resolution for that reason.

172: done — ad groups on /chatbot/pages pick each customer's item, flow and values from their latest ad, with page values as the default; no migration.
