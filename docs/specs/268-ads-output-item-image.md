# 268: Ads daily output shows the item, the right promo and the main flow picture

Weight: bounded. Risk tier: low (read-only page). Size: small. Budget: 2 attempts per failing step; stop and report if blocked.

## Why
On /ads_manager/daily-output (spec 266, live) the promo is matched from the campaign name, which fails often: "EGGMOLD" vs item "1 x EGG FRYING MOLD", "RSTICKER" vs "1 x REFLECTIVE STICKER". The owner wants an Item column, the promo of that item, and the page's main flow picture, so he can see what each ad was advertising.

## Facts (checked on live data 2026-11-13)
- `daily_page_primary_item(ts_date, page_key, primary_item)`: the item a page sold most that day, page_key is the page name in lowercase (e.g. ('2026-11-12','chloe santiago','1 x SIDE MIRROR'), ('2026-11-12','faye navarro','1 x BODY LOTION'), ('2026-11-11','karla chavez','1 x EGG FRYING MOLD'), ('2026-11-11','patricia mercado','1 x REFLECTIVE STICKER')). Check how it is filled (grep the repo) and whether today's row exists early in the day; if the day's row is missing, fall back to the latest earlier row for that page and mark it "(kahapon)" or similar.
- `page_item_settings(page_name, item_name, promo, effective_date)`: Karla Chavez "1 x EGG FRYING MOLD" → "99+100(SF)= 199" (2026-10-29), Patricia Mercado "1 x REFLECTIVE STICKER" → "99+100(SF)= 199" (2026-11-10). Chloe Santiago has NO row for SIDE MIRROR and Faye Navarro none for BODY LOTION (promo not set yet).
- Main flow picture: `page_settings` rows with provider='chatbot', `key`='picture', value = https image URL (e.g. https://botcake.pro/i/....jpg), with history: active=1 is current; older rows have active=0 and replaced_at. Columns: page_name, provider, key, value, active, replaced_at, created_at. The picture in effect at a moment T is the row with created_at <= T and (replaced_at IS NULL OR replaced_at > T). Chloe Santiago: current set 2026-11-12 11:05:57, previous 2026-11-09 → 2026-11-12 11:05:57.

## Stories
S1 (P1) Each row (page/campaign/ad set) on the page shows an **Item** column:
- .1 the item is first the unique campaign-name match against that page's items (the 266 matcher), else the page's primary item: for New and Back on the primary item of day D; for Turned off the primary item of D-1 (the day the ad last spent); for budget rows day D.
- .2 the **Promo** column is the promo of that item from page_item_settings (latest effective_date <= the day used). If the item has no promo row, show "walang promo na naka-set" (muted); if no item at all, "—".
- .3 live examples must come out as: Chloe Santiago new → 1 x SIDE MIRROR, walang promo na naka-set; Faye Navarro new → 1 x BODY LOTION, walang promo na naka-set; Karla Chavez back on → 1 x EGG FRYING MOLD, 99+100(SF)= 199; Patricia Mercado off → 1 x REFLECTIVE STICKER, 99+100(SF)= 199 (write tests with this data shape).

S2 (P1) Each row shows the page's **main flow picture** as a small thumbnail (about 56 px, rounded, lazy-loaded, `referrerpolicy="no-referrer"`), opening the full image in a new tab on click; for New/Back on/budget the picture in effect at the end of day D (or now, for today), for Turned off at the end of D-1. No picture → a small grey placeholder. The URL is printed escaped; only http(s) URLs are rendered (anything else → placeholder).

S3 (P1) Performance stays: plain SELECTs only, the new reads are one query each for primary items (pages × 2 days), promos (already), and pictures (page_settings filtered by key='picture', provider='chatbot', page_name IN (...)); total data queries stay small and fixed; the 266 performance test still passes.

## Constraints
- Smallest change to the 266 controller/view/tests; no new tables/columns/indexes; same access.
- Commit: `feat(ads): the daily output shows each ad's item, its promo and the main flow picture (268)`; NO Co-Authored-By, Claude-Session or "Generated with" lines, no person names.
- Save this spec as docs/specs/268-ads-output-item-image.md and a result .result.md, committed with the work.

## Allowed
composer install, php artisan test (the 266/268 tests, plus the full suite once; known pre-existing failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest), vendor/bin/pint on changed PHP files, git add/commit, `git push -u origin feat/268-ads-output-item-image`. Do NOT merge, open a PR, push to main or deploy.

## Report back
Branch and SHA, the new SQL, how the item and picture are chosen, test results, anything you could not do.
