# Plan 225: one "Replies via" choice, sticky columns that keep their width, CPP rows per ad group

Spec: `docs/specs/225-pages-and-cpp-per-ad-group.md`. Base `af110811`. Test-first per story.

## S1 One "Replies via" dropdown (`/chatbot/pages`)

- **Server:** extend `POST /chatbot/provider` (`ChatbotController::provider`) with an optional `gateway_app`.
  - `provider=gateway` with `gateway_app`: the address is checked like `/chatbot/gateway-app` (more than one address
    configured, key in `Gateways::routes()`), then `chatbot.provider` and `chatbot.gateway_app` are written in **one
    transaction** for every page (all or nothing), with the user on both rows (the same change records as today).
  - `provider=botcake`: `gateway_app` is ignored, the address stays as it is.
  - Without `gateway_app` the endpoint behaves exactly as today. `/chatbot/gateway` and `/chatbot/gateway-app` stay.
  - Same access (`checkAccess`), same throttle, same "token first" check.
- **Screen:** one select replaces the Bot select and the "App:" select. Options: `Botcake`, then one per address in
  `gateway_apps` (the `/chatbot/data` payload, from `Gateways::routes()` in order), labelled `Bot N (<routeLabel>)`.
  With one address (no `gateway_apps`) it is `Botcake / Forbeast` as today. The gateway options follow today's rule
  (shown when the page is connected and the gateway is set up, or when current). The separate gateway-software select
  (Forbeast vs Incepxionbot, only for pages with tokens on both) is not part of this spec and stays; when a page's
  active gateway is not the Forbeast gateway, the addresses don't apply and the select is `Botcake / <gateway>` as today.
- **Bulk:** the "Replies via" select gets the same options; Apply posts `provider` + `gateway_app` in one call.
- Tests: `tests/Feature/Chatblast/RepliesViaChoiceTest.php` (S-225.1.x): combined save writes both rows with the user;
  botcake keeps the address; bad address = 422 and nothing written (all or nothing, bulk too); one address = old
  behaviour; the page renders one select builder and no `gatewayAppSelect`.

## S2 Pinned columns keep their width

CSS only, in the `@push('styles')` block of `resources/views/chatbot/index.blade.php`:
- Widths as variables on `#cb-table`: `--cb-w1` (Page) 8rem phone / 12rem from 640px, `--cb-w2` (Active/Bot) 11rem
  (fits the 9rem select plus padding), `--cb-w3` (Flow) 10rem, `--cb-w4` (Check) 14rem.
- Each pinned cell: `width = min-width = max-width = var(--cb-wN)`, `box-sizing: border-box`, long text wraps
  (`overflow-wrap: anywhere`) instead of widening the column.
- `left` of each pinned column is the sum of the widths before it (`calc(...)`), so the next column can never be covered;
  the first scrolling column starts after the last pinned one by normal table flow.
- The width utility classes on the four header cells are dropped so the CSS is the one source.
- Browser check at 1366 px and 1920 px with a long page name and a long flow name, if the app can be served locally.

## S3 CPP rows per ad group (`/ads_manager/cpp`)

**Order-to-ad rule: `macro_output.botcake_ad_id`, the order's own stored ad.** It is written once when the order's
customer is matched (`BotcakeCustomerMatcher`: the customer's `botcake_psids.ad_id` for that page at match time, newest
row first, never overwritten afterwards). So for a customer with more than one ad, the ad that is credited is the one the
customer had when the order got its PSID, i.e. the last ad before the order, and it does not move when the customer later
comes in through another ad.

Why this source and not a join to `chatbot_replies.ad_id` / `botcake_psids.ad_id` at report time:
- It is already the per-order ad used by the other per-ad reports (`/owner/private` delivery per ad, `AdDeliveryStatus`,
  PSID coverage), so CPP per ad group agrees with them.
- It is a column on the row CPP already reads, indexed (`macro_output_botcake_ad_id_idx`): no join, no per-order lookup,
  no migration. A report-time "last ad before the order" join would need the order time (stored as text
  `H:i d-m-Y`) against chat times per PSID, which is slow on the live table and would disagree with the other reports.
- `chatbot_replies.ad_id` only exists for chats the bot answered (Forbeast/Botcake webhook era) and changes per chat; the
  stored order ad is stable once written.

**Ad to group:** the page's groups (`AdGroups::groups`, adgroup rows), most specific wins as in the chatbot run: the ad
itself, then its ad set, then its campaign (`AdGroups` index + the cached `parentsMany` over `ad_catalog` /
`ads_manager_reports`). A small public `AdGroups::groupsForAds()` exposes this for a list of ads (no Meta calls).

**Spend per group:** the page's `ads_manager_reports` rows in the same date range, each row to the group of its `ad_id`,
else its `ad_set_id`, else its `campaign_id` (same most-specific rule). The CPP query already reads these rows; it now
also selects `ad_id, ad_set_id, campaign_id`.

**Rows:** under the page row, one indented row per group (`Ad group: <name>`), then `Not in a group` (spend and orders of
ads that are in no group) and `Unknown ad` (orders with no stored ad, spend rows with no ad id), each only when non-zero.
So the group rows add up to the page row. Ruling: two remainder rows instead of one, so "no ad recorded" and "an ad nobody
grouped" are not mixed.

**Formulas:** the same per-day cells as the page (spent, orders, CPM, CPI, TCPR fail, item pairs) computed by the same PHP
code, and the same JS sums as the page row. The page row counts orders and TCPR only on days the page has spend; the group
rows use the page's spend for that gate (not the group's), which is what makes them add up. A group with spend and no
orders shows `—` for CPP, as the page row does.

**Scope:** ad-based pages only (`adgroup.mode = ad`, found with one `pagesWith` query). Page-based pages and the
snapshot, timeline and single-page views are unchanged. The group rows are left out of "Copy Table" so the pasted sheet
keeps its shape. Group names are escaped.

**Speed:** no new query for page-based data; for ad-based pages: one `page_settings` read, the cached `parentsMany`
(indexed `ad_id IN (...)`), and three extra columns on the two existing reads. Measured on SQLite seed data in the
result; no index needed (`botcake_ad_id` and `ad_id` are indexed already).

Tests: `tests/Feature/CPP/CppAdGroupRowsTest.php` (S-225.3.x): two groups (+ unknown) add up to the page; page-based page
has no group rows; group with spend and no orders has null CPP; ad set and campaign IDs in a group; view renders the rows.

## Not touched

The Inbox, the `chatbot_replies` writes and the route column of spec 224.
