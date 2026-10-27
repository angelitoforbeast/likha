# Plan 172: ad groups on a chatbot page

## Storage (no new table, no migration)

Rows in `page_settings` under a new provider **`adgroup`**, one group = one number `N` that is never reused on a page
(next N = highest N ever written for the page, active or not, + 1):

| key | value |
|---|---|
| `gN.name` | the group's name (e.g. "Item A") |
| `gN.ads` | the ad IDs, digits only, comma separated, sorted |
| `gN.<value key>` | an override; absent = use the page value |

Value keys a group may set (the real chatbot keys): `item_name_in_jnt_dashboard`, `price`, `quantity`, `picture`,
`main_flow`, `main_flow_2`, `flow_id`, `seq_N`, `seq_img_N`.

Precedent: `transfer.hold`, `order_encode.*` and `analyst.*` each use their own provider so they never become a
/chatbot column or a botfield. History = the inactive rows (`active=false`, `replaced_at`), Restore = `set()` of an old
value, delete = `deactivate()` of every active `gN.*` row. All writes go through `PageSettings::set(…, system: true)`;
`validate()` refuses provider `adgroup` from any non-system writer (the generic /chatblast/page-settings grid), and that
grid, its changes feed and the /chatbot "+ Page" list skip provider `adgroup` the way they skip `analyst`.

## Resolution

- `AdGroups::groups($page)`: parsed from `PageSettings::allWithIds($page, 'adgroup')` (the page's existing cached map,
  no extra query). A page with no groups stops here: no ad lookup at all.
- Latest ad of a customer (`AdGroups::latestAd`): the newest of the customer's last 50 chats on this page
  (`botcake_chats`, by id) carrying a digits `payload.ad_id` (Forbeast referral or Botcake ad param, each with its
  received time); else `botcake_psids.ad_id` of this page (Botcake's current ad for the customer, no time of its own).
- `ChatbotPage::flow($page, $group)` / `botfields($page, $group)`: the group's override wins per key; SHOP DETAILS is
  rebuilt from the resolved item/price/quantity. Group `flow_id` only replaces the page flow; page Off still wins.
- `ChatbotRunner` (handle, skip, resume) resolve the group once per customer and pass it; `FlowRun::$adGroup` carries it.
- Sequences: on `subscribe` (result subscribed) the group is pinned in the customer's run state
  `chatbot_customer_values` key `__ad_group_seq_<sequenceId>` (`0` = page values); `runStep` uses the pin
  (missing pin, e.g. enrolled before this change → resolve now and pin). "Clear all fields" keeps the pins.
- Orders: Encode order / Upload / sheet cells read `{{SHOP DETAILS}}`, `{{PRICE}}` … from the run's botfields, so they
  follow the group. The sheet-cell preview (ChatbotSheetController) also resolves the group.

## Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | `AdGroups` service: read, parse ads, save with duplicate check, delete, history, restore; provider guarded and hidden from the generic grid | backend | high (new write path) | .7, .8 (parse), .9, .11 |
| 2 | Resolution: latest ad, ChatbotPage overlay, runner, sequence pin, sheet preview | backend | medium | .1–.6 |
| 3 | `ChatbotAdGroupController` + routes (same `NavAccess /chatblast` check and throttles as page values), "Ads not in a group yet" (30 days, top 50 by customers, search) | backend | high (new routes) | .8, .10 |
| 4 | UI: "Ad groups" section at the bottom of the page Edit modal (`public/js/chatbot-adgroups.js`), 390 px | frontend | medium | .13 |
| 5 | P2: Test tab run with a chosen group (`ad_group` param) — only if time allows | backend | low | .14 |

Tasks share files in order (1 → 2 → 3 → 4); none run in parallel. One skeptic review at the end over the whole diff.
