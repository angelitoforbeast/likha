# Brief 176: ad account names fall back to the names on /ads_manager/ad_account

## Why
The Meta Ads section on /ads_manager/report (spec 173) lists ad accounts with the name Meta returns. Several accounts come back from Meta with no real name (the name equals the account ID, e.g. 1978377456240271), so the list is hard to read. Likha already keeps names per ad account on /ads_manager/ad_account (table `ad_accounts`: ad_account_id, name, active, history columns; e.g. 1978377456240271 = "Vane Mendoza", 784395860945426 = "Drex Garcia", 1624473525582198 = "John Santos", 1332339022040935 = "Xander David", 1380335190216061 = "Vanie Lopez").

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Wherever the Meta Ads code shows or logs an ad account's name (the account list and checkboxes, the Compare report, `meta-ads:accounts --list`, run logs), use Meta's name **unless it is empty or equal to the account ID** (with or without `act_`); then use the active `ad_accounts` row's name for that ID; if none, the ID. Match IDs with or without the `act_` prefix. | Owner: use the ad account page only for the name when Meta gives none. |
| 2 | The selection (spec 173, CEO/Marketing/Marketing - OIC, new accounts ticked) is unchanged and stays separate from `ad_accounts`. No writes to `ad_accounts`. No new tables. | Owner: the ad account page is for the name only. |

## Cases (S-176)
P1: .1 Meta name "1978377456240271" and ad_accounts has "Vane Mendoza" → shown "Vane Mendoza"; .2 Meta name "Vanie Lopez" → kept even if ad_accounts says something else; .3 no Meta name, no ad_accounts row → the ID; .4 an inactive ad_accounts row is not used; .5 `act_` prefix matching; .6 MetaAds tests pass.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/MetaAds` and the Ads Manager tests add no new failures vs 88f49262. Result with deploy notes (expected: view:clear only). End with "176: <done|blocked> — <one line>".
