# 266: Ads daily output — what marketing did with the ads each day

Weight: bounded (one new read-only page). Risk tier: low-medium (read-only reports on live tables). Size: medium. Budget: 2 attempts per failing step; stop and report if blocked.

## Why
The owner wants to see, per day, what marketing did with the ads: how many new ads, how many turned off, how many turned back on, budget changes, with the campaign and the promo. Who made each change is NOT recorded anywhere and is out of scope (owner: "Yung sino gumawa, wala muna"). Visible to CEO, Marketing and Marketing - OIC.

## Facts (checked on live data 2026-11-12)
- `ads_manager_reports`: one row per (day, campaign, ad set, ad_id); columns include day (Meta's date), page_name, campaign_id, campaign_name, ad_set_id, ad_set_name, ad_id, ad_set_budget, ad_set_budget_type, amount_spent_php, results, updated_at. ~680-740 rows per day; about 70% of rows have 0 spend (the sync writes 0-spend rows, so "turned off" is detectable). Filled by the Meta sync (app/Services/MetaAds/Insights.php, runs ~10:00, 15:00, 18:45 Manila) and XLSX upload. Show "Huling sync: <MAX(updated_at) of that day>" so people know today is partial.
- The existing history page computes events from spend with LAG: AdsManagerCampaignsController::historyData (app/Http/Controllers/AdsManagerCampaignsController.php ~154-578), labels created_with_spend / turned_on / turned_off. Reuse its definitions where sensible but keep this page's SQL simple and per-day.
- `ad_catalog`: one row per ad_id with first_started (first day seen), first_spend_day, page_name, campaign_id, campaign_name, ad_set_id, ad_set_name.
- Promo: `page_item_settings(page_name, item_name, promo, effective_date, ...)` — promo text like "99+100(SF)= 199"; per page + item, latest effective_date <= day wins. Campaign names carry the product as a token, e.g. "Chloe Santiago | SALES VID 1-1-3 | 1000 | SIDEMIRROR | FB" while the item is "1 x SIDE MIRROR" or "2 x SIDE MIRROR". Also see `daily_page_primary_item(ts_date, page_key, primary_item)`.
- Today's numbers for checking (day 2026-11-12, last sync 18:46): new ads 4 (Chloe Santiago SIDEMIRROR x3, Faye Navarro Body Lotion x1), back on 1 (Karla Chavez EGGMOLD), turned off 9 ads in 6 campaigns (spent >0 on 11-11, 0 on 11-12), budget changes 0, running 184 ads on 66 pages.
- Access: nav_links + nav_link_role_visibility rows, NavAccess (as in 265).

## Definitions (per day D, per ad_id; spend = SUM(amount_spent_php) over the ad's rows that day)
- New: ad_catalog.first_started = D.
- Turned off: spend(D-1) > 0 and spend(D) <= 0 (including an ad with no row on D).
- Back on: spend(D-1) <= 0 or no row, spend(D) > 0, and first_started < D.
- Budget up / down: per ad_set_id, MAX(ad_set_budget) on D vs D-1 differs (show old → new).
- Running: spend(D) > 0.

## Stories
S1 (P1) The owner opens /ads_manager/daily-output.
- .1 default day = today (Manila), with a date picker and previous/next day; summary tiles: New, Turned off, Back on, Budget up, Budget down, Running (ads and pages), and "Huling sync".
- .2 below, one list per kind, grouped by page then campaign: campaign name, ad set name, number of ads, spend yesterday/today, and the promo.
- .3 promo: for each campaign, match its product token to that page's page_item_settings item (normalize: uppercase, remove spaces, drop a leading "<n> x "; match when equal or one contains the other); show the latest promo (effective_date <= D); if no match or more than one different promo, show "—" (never guess). Unit-test the matcher with SIDEMIRROR vs "2 x SIDE MIRROR", "1000-Body Lotion - F" vs "1 x BODY LOTION", and a no-match.
- .4 a 14-day mini table under the summary: per day the counts of New / Off / Back on / Budget changes, newest first, each day clickable to open that day.
- .5 empty day → "Walang galaw" per list.
S2 (P1) Access: CEO, Marketing, Marketing - OIC see it (nav row '/ads_manager/daily-output', label "Ads Daily Output", visibility CEO 1, Marketing 1, Marketing - OIC 1, Data Encoder 0, Data Encoder - OIC 0; idempotent; down removes); others 403; guests to login.
S3 (P1) Performance: plain SELECTs only (never CREATE TABLE AS SELECT, no temp tables, no writes); ads_manager_reports filtered by day (it has an index on day) for D and D-1 (and the 14-day window for the mini table, grouped in SQL); page under 2 s on a seeded test with realistic volume.

## Decisions (do not reopen)
| Decision | Why |
|---|---|
| No "who did it" | not recorded; owner said leave it for now |
| No new table, columns or indexes | lean schema; read existing tables |
| Promo shown only on a unique match | a wrong promo is worse than none |

## Constraints
- Smallest change: one controller, one Blade view in the style of the 265 page and nearby ads pages, routes in the same middleware group as the other ads_manager pages (check routes/web.php), the nav data migration, tests. No refactors.
- Commit messages: Conventional Commits, e.g. `feat(ads): a daily output page shows new, off, back-on ads and budget changes with their promo (266)`. NO Co-Authored-By, Claude-Session or "Generated with" lines, no person names.
- Save this spec text as docs/specs/266-advertiser-output.md and a short result as docs/specs/266-advertiser-output.result.md, committed with the work.

## Allowed
composer install, php artisan test (new tests plus the full suite once; the known pre-existing failures at 462b183e are ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest), vendor/bin/pint on changed PHP files, git add/commit, `git push -u origin feat/266-advertiser-output`. Do NOT merge, open a PR, push to main or deploy.

## Done when
- Tests for S1.1–S1.5, S2, S3 exist and pass; the full suite adds no new failures.
- Branch pushed to origin.

## Report back (final message)
Branch and commit SHAs, route, the main SQL, test results, the nav migration, anything you could not do.
