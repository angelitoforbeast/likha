# Result 225: one "Replies via" choice, sticky columns that keep their width, CPP rows per ad group

**Branch:** `feat/225-pages-and-cpp-per-ad-group` from `af110811`. **Status:** done, not pushed, not deployed. No
migration. Plan: `docs/plans/225-pages-and-cpp-per-ad-group.md`.

## What shipped

- **S1** `/chatbot/pages`: one "Replies via" select per page: `Botcake`, then `Bot N (<address>)` for each address in
  `Gateways::routes()` (`Bot 1 (Forbeast)`, `Bot 2 (Incepxion)`). With one address it is `Botcake / Forbeast` as before.
  The "App:" select is gone. The bulk "Replies via … Apply to selected" has the same options. `POST /chatbot/provider`
  takes an optional `gateway_app`: with `provider=gateway` it is checked like `/chatbot/gateway-app` and written in the
  same transaction as `chatbot.provider` (address first, all pages or none, the user on both rows); with Botcake it is
  ignored and the address stays. Without it the endpoint is unchanged. `/chatbot/gateway` and `/chatbot/gateway-app`
  stay for other callers. The gateway-software select (Forbeast vs Incepxionbot, only for pages with tokens on both)
  is unchanged; when a page's active gateway is not Forbeast the addresses don't apply and the choice is as before.
- **S2** The four pinned columns have one width each (`--cb-w1` 8rem on phones / 12rem from 640 px, `--cb-w2` 11rem,
  `--cb-w3` 10rem, `--cb-w4` 14rem; width = min = max, border-box, long text wraps). Each `left` is `calc()` of the
  widths before it. The width classes on the header cells were removed.
- **S3** `/ads_manager/cpp`: under an ad-based page's row, one indented row per ad group (`Ad group: <name>`), then
  `Not in a group` and `Unknown ad` when non-zero, with Amount Spent, Orders, CPP, CPI, CPM, TCPR, Item Names and Item
  Price. Same per-day cell code (`CPPController::cell`) and the same JS sums (`rowSums`) as the page row. Not in the
  TOTAL row and not in Copy Table (the pasted sheet keeps one row per page). Group names are escaped.
- **S4** `/chatbot/pages`: a 2px `#94a3b8` line above every page row (between pages), a 1px `#cbd5e1` line above each
  ad-group row (between groups), and the sub-rows (Default, groups, their ID blocks) indented 1.75rem with a 3px left bar
  on their existing faint `#f8fafc` background. `/ads_manager/cpp` uses the same rule on its page rows and group rows.

## Order-to-ad rule

An order belongs to the ad stored on the order, `macro_output.botcake_ad_id`. It is written once, when the order's
customer is matched (`BotcakeCustomerMatcher`): the customer's `botcake_psids.ad_id` on that page at that time, newest
row first, never overwritten. So a customer with more than one ad credits the ad they had when the order got its PSID
(the last ad before the order). Chosen because it is already the per-order ad of the other per-ad reports
(`/owner/private` delivery per ad, `AdDeliveryStatus`, PSID coverage), it is an indexed column on the rows CPP already
reads (no join, no migration), and a report-time "last chat ad before the order" join would compare the text order time
with chat times per PSID on the live tables.

Ad to group: the ad itself, then its ad set, then its campaign (most specific wins, as in the chatbot run), with the
parents from the cached `AdGroups::parentsMany` (`ad_catalog`, then `ads_manager_reports`) through the new
`AdGroups::groupsForAds()`; no Meta calls. Spend: each `ads_manager_reports` row of the page in the range goes to the group
of its `ad_id`, else `ad_set_id`, else `campaign_id`; no group = Not in a group; no ad id = Unknown ad. Orders with no
stored ad = Unknown ad; with an ad in no group = Not in a group.

The page row counts orders and TCPR only on days the page has spend; the group rows use the page's spend for that gate,
so the group rows add up to the page row (checked in the browser on the seed: Perf Page 007 ₱80,000 / 204 orders =
six groups ₱9,000 / 130 + Not in a group ₱71,000 / 37 + Unknown ad ₱0 / 37).

## Rulings

1. One endpoint extended (`/chatbot/provider` + `gateway_app`), not a new one.
2. The gateway-software select stays; the Bot N options only apply to the Forbeast gateway.
3. Two remainder rows (`Not in a group`, `Unknown ad`) instead of one, so "no ad recorded" and "an ad nobody grouped" are
   not mixed. Every group of the page is listed, even with nothing in the range; the remainder rows only when non-zero.
4. A group or remainder row with orders and no spend shows CPP ₱0.00 (same formula as the page row); a row with spend and
   no orders shows `—`.
5. Group rows are shown in the multi-page Summary of Ads table only; the single-page, date, snapshot and timeline views
   are unchanged. Group rows are left out of Copy Table and the TOTAL row.

## Case table

| Case | Test | Result |
|---|---|---|
| S-225.1.1 | `RepliesViaChoiceTest::test_S_225_1_1_bot_2_sets_the_provider_and_the_address_in_one_save_with_the_user` | pass |
| S-225.1.2 | `RepliesViaChoiceTest::test_S_225_1_2_botcake_keeps_the_address` | pass |
| S-225.1.3 | `RepliesViaChoiceTest::test_S_225_1_3_a_bad_address_writes_nothing_for_any_page` | pass |
| S-225.1.4 | `RepliesViaChoiceTest::test_S_225_1_4_the_bulk_apply_sets_both_for_every_selected_page` | pass |
| S-225.1.5 | `RepliesViaChoiceTest::test_S_225_1_5_with_one_address_the_choice_is_botcake_or_forbeast_as_before` | pass |
| S-225.1.6 | `RepliesViaChoiceTest::test_S_225_1_6_the_screen_has_one_replies_via_select_and_no_app_select` | pass |
| S-225.2.1 | `PinnedColumnsTest::test_S_225_2_1_each_pinned_column_has_one_fixed_width` | pass |
| S-225.2.2 | `PinnedColumnsTest::test_S_225_2_2_each_left_offset_is_the_sum_of_the_widths_before_it` | pass |
| S-225.2.3 | browser check below | pass |
| S-225.3.1 | `CppAdGroupRowsTest::test_S_225_3_1_two_ad_groups_and_the_rest_add_up_to_the_page_row` | pass |
| S-225.3.2 | `CppAdGroupRowsTest::test_S_225_3_2_a_page_based_page_has_no_group_rows` | pass |
| S-225.3.3 | `CppAdGroupRowsTest::test_S_225_3_3_a_group_with_spend_and_no_orders_has_no_cpp` | pass |
| S-225.3.4 | `CppAdGroupRowsTest::test_S_225_3_4_ad_set_and_campaign_ids_in_a_group_take_their_ads_and_the_ad_itself_wins` | pass |
| S-225.3.5 | `CppAdGroupRowsTest::test_S_225_3_5_the_page_draws_the_group_rows_under_the_page_and_keeps_them_out_of_the_copy` | pass |
| S-225.4.1 | `PinnedColumnsTest::test_S_225_4_1_pages_ad_groups_and_their_rows_read_as_one_block` | pass |
| S-225.4.2 | `CppAdGroupRowsTest::test_S_225_4_2_the_cpp_group_rows_follow_the_same_grouping_rule` | pass |

Existing tests touched by the change and still green: `ChatbotProviderTest`, `GatewayRoutePerPageTest`,
`GatewayPerPageTest`, `AdGroupRunTest`, `AdGroupPagesTest`, `AdBasedPagesTest`.

## Browser check

Local only: the spec 217 set (`php tests/perf/set217.php migrate` and `seed`, SQLite) plus a local extra seed (a long page
name, a long flow name, Forbeast tokens, `GATEWAY_URLS=incepxion=…` pointing at 127.0.0.1, 7 days of reports and 22,396
orders), served with `php -S 127.0.0.1` and headless Chrome with every other host blocked. Screenshots in
`docs/specs/225-screens/`:

- `pages-1366-scrolled.png`, `pages-1920-rest.png`: pinned cells measured 192 / 176 / 160 / 224 px at left 25 / 217 / 393
  / 553 at 1366 and 1920 px, at rest and scrolled 900 px right, on the header, page rows and ad-group rows; each ends
  where the next begins (no overlap), the scrolling columns pass under them.
- `pages-1920-long-name.png`: the long page name and the long flow name wrap inside their columns; same widths.
- `pages-1920-replies-via.png`: one select, options `Botcake`, `Bot 1 (Forbeast)`, `Bot 2 (Incepxion)`; no "App:" select;
  bulk options the same.
- `cpp-1600-ad-groups.png`: group rows under the three ad-based pages, indented, faint background, lines as in S4.
- No page errors. The staff layout's own Tailwind CDN and Chart.js were served locally (stubs), so the top bar looks
  plain in the CPP shot.

## Timing (S3)

`CPPController::buildData` for 7 days on the local SQLite set (14,530 report rows, 22,396 orders, 3 ad-based pages with 6
groups each), 5 runs each, interleaved with the `af110811` code: median 801 / 906 / 929 ms before, 933 / 996 / 987 ms
after (+6 to +16%). SQLite takes the PHP date-parse path over all orders, so this is an upper bound on the share of
work, not a live-server time. No new query for page-based data; for ad-based pages: one `page_settings` read
(`pagesWith`), the cached `parentsMany` (`ad_id IN (...)`, indexed), and three extra columns on each of the two existing
reads. Not measured on a copy of the live database.

## Full suite

Before (`af110811`): TOTALS_BEFORE. After: TOTALS_AFTER. Known failures in both: `ExampleTest`, `ItemTableFitScriptTest`.

## Story changes

- `ChatbotFrozenCheckTest::test_S_149_27_…` pinned the old hard-coded offsets (`left: 18rem` for Flow, `left: 28rem` for
  Check). S2 replaces them by design, so the two expected strings now read the `calc()` offsets; the rest of that test
  (desktop-only freeze, compact Check cell, the popup) is unchanged and passes.

## Not done / notes

- `qa/stories.md` gets a spec 225 section at the end; the spec 224 branch also appends there, so a merge may need both
  sections kept.
- The browser check ran on seed data, not the live pages.
