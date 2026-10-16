# Brief 043: J&T delivery status and RTS% per campaign, ad set and ad in `/owner/private`

## Why

`/owner/private` shows, per page row, how many orders were delivered, returned, for return and in transit (J&T status through the waybill), and the RTS%. When a page is expanded it lists its campaigns, ad sets and ads (spend and ad metrics) but no delivery outcome, so the owner cannot see which ad brings orders that get returned. Orders now carry the ad that brought the customer: `macro_output.botcake_ad_id` (spec 040/041), the same id as `ads_manager_reports.ad_id`, whose rows also give `ad_set_id` and `campaign_id`. A check on live data: all 1,191 orders with an ad id are found in `ads_manager_reports.ad_id` (15 ads, 15 ad sets, 13 campaigns), and for one page the biggest campaign had 321 orders: 153 delivered, 83 returned, 18 for return, 37 in transit, 30 not yet in J&T. Orders have an ad id only on pages whose Botcake customers were synced (two pages today; more soon).

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| In the expand of `/owner/private`, every campaign, ad set and ad row gets five columns: Delivered, In transit, For return, Returned, RTS%. They count the page's orders whose `botcake_ad_id` belongs to that ad (ad row), to an ad of that ad set, or to an ad of that campaign | The owner, 2026-10-17: "Oo, gawin mo ang 043: sa expand ng /owner/private, idagdag ang Delivered, In transit, For return, Returned at RTS% sa campaign, ad set at ad, may linyang walang ad id" |
| Exactly the same rules as the page row in `OwnerPrivateController::data`: the same order date (`ts_date`, the page's date range from the filter), the same page match, the same J&T source (`from_jnts` by `macro_output.waybill` = `waybill_number`) and the same per-waybill flags (`is_delivered`, `is_returned`, `is_for_return`, `is_in_transit`), shipped = distinct waybills, RTS% = (returned + for return) / shipped. Reuse that logic rather than copying it where you can; if you must copy, a test proves the page row and the sum of its campaign rows plus the "walang ad id" line are equal | The numbers must agree with the page row the owner already trusts |
| Under each expanded page, one extra line "Walang ad id" with the same five columns for the page's orders in range that have no `botcake_ad_id`, or whose ad id is not found in `ads_manager_reports`, so campaign rows plus this line add up to the page row | Same; most pages have no ad ids yet, and the owner must see how much is not attributed |
| An ad id maps to its ad set and campaign through `ads_manager_reports` (its latest row when there are several). An order counts under the campaign of its ad even if the campaign's page name differs from the order's page; it is listed under the order's page | One ad belongs to one ad set and campaign |
| The new columns follow the existing column system of the campaigns table (the `campaigns` column config: order, hide, format, roles) like the other metric columns, and are visible by default to the roles that see `/owner/private`. If other screens use the same campaigns table (e.g. `/ads_manager/campaigns`, `/item`), say in the result whether the columns appear there and keep those screens working and not slower | Reuse the house pattern |
| Speed: no query per row. The status counts for all ads shown in one expand (or one batch request) come from one grouped query (orders joined to J&T flags, grouped by `botcake_ad_id`), then rolled up to ad set and campaign in PHP. Check that `macro_output.botcake_ad_id` (indexed), `macro_output.waybill` and `from_jnts.waybill_number` are indexed; if one is not, report it (do not add a migration on `from_jnts` or `macro_output` without saying so in the result: both are large) | `/owner/private` is already heavy |
| Read-only: nothing writes to any table | — |

## Tests

Per ad, ad set and campaign: counts and RTS% from orders with ad ids and J&T rows (delivered, returned, for return, in transit, and an order with a waybill not yet in J&T counts as shipped only, as on the page row); an ad set and a campaign roll up their ads; orders outside the date range or of another page don't count; orders without an ad id, or with an ad id not in `ads_manager_reports`, land in "Walang ad id"; the page row equals the sum of its campaigns plus "Walang ad id"; one grouped query for the status counts however many ads are shown; access as before. Red first where natural. The whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

Changes to the page row's numbers, to matching or syncing ad ids, to `from_jnts_2`, to ads metrics (spend, CPP, etc.); new tables.

## Final message

Commits; where the numbers come from (which function, which query) and how they are rolled up; how "Walang ad id" is computed; query count and timing on a realistic test set; indexes found or missing; which screens show the new columns; test lines and suite result; anything the brief got wrong.
