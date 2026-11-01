# 212: Ad groups match by ad, ad set or campaign id, and quiet groups fold away

## Why

On an Ad-based page (`adgroup.mode = ad`), a customer is answered with the botfields of the ad group whose ad ids include the customer's ad id. That ad id comes from the ad-open on botcake_chats or botcake_psids. When no group matches, the page-level values are used. These are often empty, so the flow's send nodes resolve to nothing: FlowEngine logs "Nothing to send" and the customer gets no reply.

On 2026-11-01, page "Precious Lopez" had two groups holding a campaign id copied from Ads Manager instead of an ad id. That left 16 customers that day without a reply. Staff naturally copy campaign or ad-set ids, so the owner decided that groups accept any of the three.

Separately, the Ad groups panel fills up with groups and ads that stopped long ago. The owner wants only what is active to stay in view, while a group or id just created or added by hand stays in view so staff can fill in its values.

## Code today (live, `vps/main`)

- `app/Services/Chatbot/AdGroups.php`:
  - `groupForAd()` is an exact `in_array($adId, $g['ads'])` match.
  - `forCustomer`, `forSequence`, `pin` and `latestAd` all go through it.
  - `forCustomer` is called by ChatbotRunner (3 places), ChatbotSequences, CommentReplies, Inbox and ChatbotSheetController.
  - `change()` refuses the same id in two groups, under a lock.
  - `parseAds` accepts digits only, up to 64 characters, and errors with "Not an ad ID".
  - `overview()` builds "Ads not in a group yet" from ads seen in the last 30 days, minus grouped ids.
  - `statuses()` and `adNames()` read `ads_manager_reports` and `ad_catalog`, and give status on, off or none, plus `off_since`, `off_days`, `customers` (30 days) and `last`.
- `app/Http/Controllers/ChatbotAdGroupController.php`: the save, add, remove-ad and value routes.
- `public/js/chatbot-adgroups.js`: the panel. It already has a "Show all N" control for long ad lists.
- `app/Services/Chatbot/FlowEngine.php:361`: the "Nothing to send" note. Leave it as it is.
- Group data lives in `page_settings` (provider `adgroup`), with created_by, created_at, replaced_at and replaced_by.
- `ads_manager_reports` (from the Meta Ads sync) links each ad to its ad set and campaign by id and name, with delivery and spend. `ad_catalog` has ad names and first_spend_day.
- The app already holds a Meta token for the Ads sync; find where the sync reads it. Never print it.

## Decisions already made (don't reopen them unless something is actually broken)

| # | Decision | Who and why |
|---|---|---|
| D1 | A group's id list may hold ad ids, ad set ids and campaign ids. A customer's ad matches a group that lists the ad, its ad set or its campaign. | The owner, 2026-11-01: staff copy campaign or ad-set ids, which left customers silent |
| D2 | Precedence is by level, not by group order: ad, then ad set, then campaign. The same id still may not be in two groups. An ad in group A and its ad set in group B is allowed. | Reviewer's decision: the most specific intent wins |
| D3 | When an ad's parents are not known locally, look them up live from Meta once, store the result and reuse it. Limits: 3 s timeout; after a failure, don't call again for that ad for 5 min; remember "not found" for 1 h; at most 30 lookups a minute per page; a found ad's parents are stored permanently. On failure, use page values for that message, with no exception reaching the customer or the queue. | Reviewer's decision: a new ad must not stay silent until the next sync, and Meta must never block or loop |
| D4 | A lookup happens only when the page is Ad-based, has at least one group, and the ad has no exact match and no known parents. It never happens from opening the panel. | Reviewer's decision |
| D5 | A failed lookup is not pinned as "page values" for a sequence; re-resolve on the next step until it succeeds. | Reviewer's decision |
| D6 | **No new table.** Store lookup results in existing rows, for example the existing `ad_catalog` row for the ad if its columns fit, or the established key/value row pattern. A migration that adds columns to an existing table is allowed if needed; say so in the result. | The owner's standing rule: lean schema, row-based precedent first |
| D7 | Ids unknown at save time are accepted and labelled "Not found in Ads Manager". | Reviewer's decision: blocking a campaign that isn't synced yet would be worse |
| D8 | An ad set or campaign id's status is On if any ad under it is On. "Off since" is the latest of its ads' off dates. Customers are the distinct customers whose ad is under it. | Reviewer's decision |
| D9 | **Quiet rule, N = 7 days.** An id is quiet when (a) it has been off for at least 7 days, or has no report data, AND (b) no customer message came with it, or under it, in the last 7 days (use the existing `last`), AND (c) it was added, or its group's values last edited, more than 7 days ago. | The owner, 2026-11-01: "7 araw lang, pareho pa ring off at walang nagme-message" (7 days; both off and no messages) |
| D10 | A group is hidden when it is older than 7 days, has at least one id, and every id is quiet. A group with no ids is never hidden. Inside a visible group, quiet ids fold behind "N hidden ads". "Ads not in a group yet" uses the same quiet rule. | The owner: only active ones stay in view; new and hand-added ones stay visible to be filled in |
| D11 | Hiding only changes the screen. It never writes or deletes data, and never changes routing: a hidden group still answers its customers. | The owner |

## Stories and done-when

Add these to `qa/stories.md` as the next free story numbers; the file currently ends at S-209. Give each case a test. Feature tests to extend: `tests/Feature/Chatblast/AdGroupRunTest.php`, `AdGroupsTest.php` and `AdGroupPagesTest.php`. Meta is faked in every lookup case.

### 1. Match by ad, ad set or campaign (P1)
- 1.1 A group lists only campaign C, and a customer whose ad is in C chats: the group's values are used and the reply goes out.
- 1.2 The same with a group listing only ad set S.
- 1.3 Precedence chain:
  - Group A lists campaign C, group B lists ad set S (in C), and group C2 lists the ad itself: C2 wins.
  - Remove the ad from C2: B wins. Then remove S from B: A wins. Then remove C from A: page values are used.
- 1.4 The more specific group has the higher group number: it still wins.
- 1.5 The customer's ad is in sibling ad set S2, or in another campaign: no match, page values.
- 1.6 An id already in group "X", at any level, is refused when added to another group, naming "X". An ad in A plus its ad set in B is allowed.
- 1.7 A double-submitted add stores the id once, with a clear message.
- 1.8 The latest-ad rule is unchanged (S-172.4): it is now matched through the full ad, ad set, campaign order.
- 1.9 On a Page-based page, no group matches and no lookup happens.
- 1.10 A customer ad id that isn't digits-only, or is longer than 64: ignored. No group, no Meta call, no error.
- 1.11 Sequence pinning follows S-172.6 unchanged, for a match by campaign.
- 1.12 A user without /chatblast access: every ad group route returns 403 and writes nothing.

### 2. Live lookup: once, stored, never loops (P1)
- 2.1 An ad not in reports. Meta returns adset_id and campaign_id, and a group lists the campaign: the group matches, the reply goes out, and the parents are stored.
- 2.2 After that, 100 more messages, and also after a cache flush: zero Meta calls.
- 2.3 An ad already in reports with parents: never a Meta call.
- 2.4 20 simultaneous customers from one new ad: exactly one Meta call.
- 2.5 An id unknown everywhere (not found, or no permission): page values, no error, "not found" remembered for 1 h.
- 2.6 Meta down (timeout, 5xx, rate limit, or missing or expired token):
  - page values for this message, delayed by no more than 3 s
  - no exception
  - the run log notes the lookup failed
  - no retry for that ad for 5 min
- 2.7 Meta back after the retry wait: the next message matches. A sequence entered during the failure re-resolves (D5).
- 2.8 500 different numeric ad ids in a minute: lookups stay within the cap and real replies are not delayed.
- 2.9 Page-based pages, Ad-based pages with no groups, and exact matches: no lookup.
- 2.10 No token or secret in any log, error or panel response.

### 3. No customer-facing change except replies now going out (P1)
- 3.1 Groups of exact ad ids only behave exactly as before. The existing S-172 tests pass unchanged.
- 3.2 A group holding a campaign id: a new customer of an ad in it gets the group's reply, not "Nothing to send".
- 3.3 The deploy replays and sends nothing old. Only messages that arrive afterwards are matched the new way.
- 3.4 An ad that matches nothing, with empty page values: "Nothing to send" is logged as today.
- 3.5 A match by ad set or campaign applies everywhere the old match applied: runner, sequences, comment replies, inbox, order sheet and Test tab.
- 3.6 A reply reached via the campaign is identical to one reached via the exact ad id.
- 3.7 On Page-based pages, nothing changes.

### 4. The panel labels each id as Ad, Ad set or Campaign, with its own name (P2)
- 4.1 One id of each kind:
  - the rows show "Ad", "Ad set" and "Campaign"
  - each row shows its own name
  - status and customers are rolled up per D8
- 4.2 Unknown ids show "Not found in Ads Manager", and are still saved and removable.
- 4.3 Names are escaped; Filipino text and emoji display intact.
- 4.4 At 390 x 844: no sideways scroll, and the buttons stay tappable.
- 4.5 Labels:
  - placeholder "IDs: ad, ad set or campaign; comma, space or new line"
  - header "N ids in <group>"
  - error "Not an ID (digits only)"
  - the remove confirm names the kind
- 4.6 The read-only list shows the same tags.
- 4.7 Opening the panel never calls Meta, and opens as fast as today while Meta is down.

### 5. "Ads not in a group yet" leaves out covered ads (P2)
- 5.1 An ad covered by a group's ad set or campaign is not listed.
- 5.2 Remove that ad set from the group, and the ad is listed again.
- 5.3 A sibling ad in an uncovered ad set is still listed.
- 5.4 An ad with unknown parents is listed.
- 5.5 The totals, the "(N new)" count and "Showing 50 of M" count the filtered list. The New badge rule itself is unchanged.
- 5.6 Search covers only the listed ads.
- 5.7 Adding a covered ad by hand is allowed.

### 6. Quiet groups and ids fold away (P2; D9 to D11)
- 6.1 A group off for 10 days, with no messages for 10 days and created 30 days ago, is hidden and counted in the "hidden" line.
- 6.2 A group that is off but had messages in the last 7 days stays visible.
- 6.3 Boundary: off 6 days, visible; off 7 days, hidden. Manila dates, with off_days counted as the panel does today.
- 6.4 A group with one On ad stays visible, and its quiet ids fold behind "N hidden ads".
- 6.5 A hidden ad that comes back On, or gets a message, is visible on the next load.
- 6.6 An id with no report data, no message in 7 days and added more than 7 days ago is hidden. With a message in the last 7 days, it is visible.
- 6.7 A hidden group still answers its customers. Hiding or showing writes no row.
- 6.8 A group with no ids is never hidden.
- 6.9 Ids of mixed levels are judged per id, with roll-up.
- 6.10 A stopped Meta sync does not cause new hiding.
- 6.11 On a page with 200 groups, the counts are right and the main view is short.

### 7. New and hand-added stay visible, and Show hidden brings the rest back (P2)
- 7.1 A group just created, with no status or traffic, is visible.
- 7.2 Pasting an id into a hidden group shows the group again. Its old quiet ids stay folded.
- 7.3 After 7 days with still no traffic, or off, it folds like any other.
- 7.4 The control:
  - with N hidden, a "Show hidden (N)" button appears
  - when on, hidden items show dimmed and the button reads "Hide again"
  - with 0 hidden, there is no button
- 7.5 Show hidden stays on through save, add, search and remove until reload. After reload, the default is hidden.
- 7.6 Edit, Rename, History, Remove and Delete work the same on hidden groups.
- 7.7 Pasting an id that is in a hidden group: the refusal names the group and says it is hidden.
- 7.8 The read-only list follows the same hiding. The pages-table badge count is not changed by hiding.
- 7.9 At 390 x 844, the button is keyboard-operable (`aria-pressed`), and its wording differs from "Show all N".

### 8. A busy page stays fast (P1)
- 8.1 Matching a known ad adds at most 1 cache read and 1 indexed query, whatever the number of groups. Assert the query count.
- 8.2 On a page with 150 groups, 3,000 ids and 100k chats, the panel opens in no more than 1.25 times the time on `vps/main`, and the query count doesn't grow with customers or hidden items.
- 8.3 With Meta slow (10 s), one customer waits no more than 3 s, and other customers are not blocked.
- 8.4 Two concurrent saves of the same ad set id: one is refused, and matching never sees a half-saved group.
- 8.5 A 500-id group (MAX_ADS) of mixed levels: saving, matching and rendering all work.
- 8.6 The lookup store grows by one entry per ad and holds no token or customer data.

**Done when:**
- Every case above has a passing test, or, for 4.4, 7.9 and 8.2, a recorded check in the result.
- `php artisan test` adds no new failures compared with `vps/main`. Note any failures already present there.
- The panel has been checked in a browser at desktop width and at 390 x 844, with screenshots under `docs/specs/212-evidence/`.
- The result file is filled in and committed.

## Threat model and risk

- **Untrusted:** customer ad ids from chats and referrals (digits check, length cap, lookup cap); Meta's API responses (validate the shape, store only ids); ids typed by staff (digits only).
- **Trusted:** the Meta token and the app's settings.
- **Risk tier: high.** This touches the reply path of every Ad-based page.

## Out of scope

- warnings or alerts for ungrouped ads
- the "new" badge rule
- deleting groups automatically
- changing FlowEngine's "Nothing to send"
- any deploy

## Report back

When done, or when blocked, end with a short report:
- branch and head commit
- what was built
- how lookups are stored (D6)
- the test summary, compared with `vps/main`
- any story changes, with reasons
- screenshot paths
- anything left open
