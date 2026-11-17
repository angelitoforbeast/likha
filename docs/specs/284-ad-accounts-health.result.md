# 284 result: Ad Accounts, balance, payment, limit and health per Meta ad account

## Plan

Base: 9fa8ed84 + 280 (a5a4f7ba) + 281 (64992c69). First step after "go": run the full suite on 9fa8ed84 and list its failures here.

**1. Graph fields and the call.** New `App\Services\MetaAds\AccountHealth` (reuses `Connections` and `Graph`; no second client). Per connection, one call: `me/adaccounts?fields=account_id,name,account_status,disable_reason,currency,balance,amount_spent,spend_cap,funding_source_details&limit=200` through `Graph::all` (paging followed as today). For the web request it must not sit in the rate-limit back-off (2+8+32 s), so `Graph::all` gets two optional, backward-compatible parameters (`$tries`, `$timeout`) passed to `get()`; this read uses tries 1, timeout 20 s. All names are valid ad-account fields in v25.0 (the configured version); I will check them against the Marketing API reference before coding.
- `funding_source_details` refused (permission error on the whole call): the same call is repeated once without it; that connection's rows show the payment method as "not available" and the reason is listed once at the bottom. A field simply missing from a row also shows "not available".
- 190: the connection is marked expired (`Connections::update`, as Sync does); its accounts not readable through another connection show "Token expired: <Facebook name>, Reconnect".
- Rate limit / "(#200) API access blocked" / other error: that connection's accounts keep their last good row with its read time, and the error is shown; never an exception to the page.
- Dedup: one row per account id; the "Connection" cell lists every Facebook name that reads it (as `Connections::accounts()` does). Names go through `Connections::names()`.

**2. Money.** balance, amount_spent, spend_cap come as strings in minor units. Convert with integer/bcmath-free string math (no float): amount = minor / offset, offset 100 for PHP, USD and most currencies, offset 1 for Meta's zero-offset currencies (a constant list from Meta's currency table, checked before coding). Shown with the currency code and 2 decimals. spend_cap 0 or missing = "No limit", no % used; remaining = cap − spent (not below 0), % used = spent / cap. Status and disable_reason codes mapped to words (1 Active, 2 Disabled, 3 Unsettled, 7 Pending risk review, 8 Pending settlement, 9 In grace period, 100 Pending closure, 101 Closed; disable reasons 1..15 by Meta's names); an unknown code shows "Unknown (n)".

**3. Cache and last good read, no new table.** One `app_settings` row `meta_ads.account_health` (JSON: `rows` per account with its own `read_at`, `notes`, `attempted_at`, `last_error`). It is both the cache and the last good read. A page load uses it when the last attempt is under 10 minutes old (success or failure, so a blocked API is not hit on every load); otherwise it reads Meta under `Cache::lock('meta_ads.account_health', 30)` so concurrent viewers make one read and the others show the stored one. Failures never overwrite good rows. No token or raw Graph body is stored.

**4. Page.** `GET /ads_manager/meta/ad-accounts` (`ads_manager.meta.ad_accounts`) and `POST /ads_manager/meta/ad-accounts/refresh`, both in `MetaAdsController` (same `checkAccess()` = the "Ads" nav permission, same web/auth/allowed_ip routing as the other `/ads_manager/meta/*` routes). View `ads_manager/ad_accounts.blade.php`, Tailwind, the repo's report style: a table on wide screens, one card per account under 640 px (readable at 390 px). Columns: name + id, connection, status (+ disable reason), currency, balance owed, spent (lifetime), spend cap / remaining / % used, payment method, read at. Notes ("not available" reasons, connection errors) listed once at the bottom. No connections: "No Meta connection on this site." empty state, no Graph call.

**5. Warnings.** Red: In grace period (9), Unsettled (3), Disabled (2). Amber: spend cap used ≥ 90% (red at ≥ 100%). On the page: one line per warned account at the top. On `/ads_manager/report`: one line naming the warned accounts with a link to the page, only for users with the "Ads" permission, built from the stored read only (the report page never calls Meta). A permanent "Ad Accounts" link sits in the Facebook (Meta Ads API) card.

**6. Freshness besides page loads.** After each scheduled `meta-ads:sync` run (3 times a day when write mode is on) the stored read is refreshed too, so the report-page warning is not older than the last sync. Refresh now bypasses the 10-minute window (still under the lock).

**7. Tests** (`tests/Feature/MetaAds/MetaAdsAccountHealthTest.php`, on `MetaAdsTestCase`, Graph faked with `Http::fake` + `preventStrayRequests`, as the other Meta tests): 5 accounts all fields; minor units (PHP "123456" → 1,234.56; a zero-offset currency; cap 0 and missing → No limit); empty state with no connection; funding_source_details refused → retry without, "not available" + reason once; field missing; same account through two connections → one row; token 190 → row names the connection; (#200) blocked → last good rows with time and error; fresh stored read → zero Graph calls on load (done-when 3); lock/10-minute window on failure; Refresh now permission; warnings on both pages (grace period red, ≥ 90% amber, all healthy → no line); no access → 404 on the page and no warning/balances on the report page; no token in the HTML. Done-when 2: render with seeded fake data, HTML excerpt here.

**Questions before go:**
- Q1: Refresh now "same write/permission rules as Sync now": Sync now needs the "Ads" permission and write mode on. My plan: Refresh now needs the "Ads" permission only (it writes nothing to the ads data). Or also require write mode on, like Sync now?
- Q2: The report-page warning uses only the stored read (refreshed by page visits, Refresh now, and the scheduled sync). If write mode is off and nobody opens the page, the warning can be old (its read time is shown). Acceptable, or should the report page also read Meta when the stored read is older than 10 minutes?
- Q3: The page reads Meta inside the web request when stale (one call per connection, 20 s timeout, no back-off). Acceptable, or queue it and show the stored read with "refreshing…"?

## What shipped

## Evidence per done-when item

## Story changes

## Rulings

- Q1: Refresh now needs the "Ads" permission only. It writes nothing to Meta or the ads data, so write mode is not required.
- Q2: The report page never calls Meta. The stored read is refreshed hourly by the existing scheduler (no new cron line; `schedule:run` already runs) and after each scheduled `meta-ads:sync`. The read time is shown next to the warning.
- Q3: Reading Meta in the request when the data is stale is allowed only on the Ad Accounts page and Refresh now: one call per connection, 20 s timeout, under the lock.

## Merge danger
