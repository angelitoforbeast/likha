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

Code commit `707d9a27` on `feat/284-ad-accounts-health` (base 9fa8ed84 + 280 + 281). No migration, no new table, no new config key, no new cron line.

- `app/Services/MetaAds/AccountHealth.php` (new):
  - The read: one `me/adaccounts` call per connection with `account_id,name,account_status,disable_reason,currency,balance,amount_spent,spend_cap,funding_source_details`, limit 200, paging followed. Field names checked against the Marketing API ad-account reference: all exist; `funding_source_details` needs MANAGE on the account.
  - The call uses 1 try and a 20 s timeout, so there is no rate-limit back-off inside a web request.
  - If the call fails for any reason other than 190 or a rate limit, it is made once more without `funding_source_details`. The payment method then shows "not available" and the reason is listed once.
  - 190 marks the connection expired, as Sync does. That connection's rows say "Token expired: <Facebook name> (Reconnect)".
  - Any other failure keeps the last good row for that connection's accounts, with its own read time and the reason.
  - An account seen through two connections is one row naming both. Names go through `Connections::names()`.
  - Storage: one `app_settings` row, `meta_ads.account_health`, used as both the 10-minute cache and the last good read. It is reused while the last attempt, good or failed, is under 10 minutes old. Reads run under `Cache::lock('meta_ads.account_health', 180)`. No token or raw Graph body is stored.
  - Money: amounts are kept as Meta's minor-unit strings and converted with string arithmetic, no floats. Offset 1 for Meta's 11 zero-offset currencies (CLP, COP, CRC, HUF, ISK, IDR, JPY, KRW, PYG, TWD, VND, from Meta's currency table); offset 100 for every other currency.
  - Status and disable-reason codes are shown in words. % used is rounded down, so 89.99% is not a 90% warning.
- `app/Services/MetaAds/Graph.php`: `all()` takes optional `$tries` and `$timeout` (default unchanged, so the existing callers behave the same).
- `app/Http/Controllers/MetaAdsController.php`:
  - `adAccounts()`: `GET /ads_manager/meta/ad-accounts`. It reads Meta in the request only when the stored read is stale.
  - `refreshAdAccounts()`: `POST /ads_manager/meta/ad-accounts/refresh`. It reads now, under the lock; if another read is running, it says so and shows the last read.
  - Both use `checkAccess()` (the "Ads" nav permission, 404 without it). Neither needs write mode.
  - `panel()` adds the warnings from the stored read only.
- `resources/views/ads_manager/ad_accounts.blade.php` (new):
  - A table from `md` up, one card per account below `md`. Long names and ids wrap, and there is no horizontal page scroll at 390 px.
  - Red and amber warning lines at the top.
  - The empty state "No Meta connection on this site."
  - The "not available" reasons, listed once at the bottom.
- `resources/views/ads_manager/report.blade.php`: one line `⚠ Ad accounts: <name> (<reason>), …  (read <time>) Open Ad Accounts`. It is red if any warning is red, otherwise amber. It shows only to users with the "Ads" permission.
- `resources/views/ads_manager/_meta_ads.blade.php`: an "Ad Accounts: balance, spend cap, payment" link in the Facebook (Meta Ads API) card.
- `app/Console/Commands/MetaAdsAccountHealth.php` (new): `meta-ads:account-health [--force]`. Without `--force` it reads only when there are connections and the stored read is stale.
- `routes/console.php`: `meta-ads:account-health` runs hourly (`0 * * * *`, withoutOverlapping, runInBackground) in the existing scheduler.
- `app/Console/Commands/MetaAdsSync.php`: after a `--scheduled` run, the stored read is refreshed. A failure there is a warning line and does not fail the sync.
- `tests/Feature/MetaAds/MetaAdsAccountHealthTest.php`: 20 tests. The Graph is faked with `Http::fake` and `preventStrayRequests`, the same as the other Meta tests.

## Evidence per done-when item

PHP 8.4.16 (Herd). The tests use the repo's phpunit.xml: sqlite in memory, array cache.

**1. Feature tests for AA-1..AA-4 pass; the full suite adds no new failures.**

```
php vendor/bin/phpunit tests/Feature/MetaAds/MetaAdsAccountHealthTest.php --testdox
 ✔ AA 1 H five accounts show every field with money in two decimals
 ✔ AA 1 minor units are converted by the currency offset without floats
 ✔ AA 1 N no meta connection shows the empty state and calls nothing
 ✔ AA 1 N payment method refused retries without it and lists the reason once
 ✔ AA 1 N a field missing from one account shows not available and the page still renders
 ✔ AA 1 E the same account through two connections is one row naming both
 ✔ AA 1 E an expired token is named on its rows and the connection is marked
 ✔ AA 2 H an account in grace period is a red line on both pages
 ✔ AA 2 unsettled disabled and cap reached are red
 ✔ AA 2 N all healthy shows no warning line
 ✔ AA 3 a fresh stored read makes no graph call on page load
 ✔ AA 3 another read in progress shows the stored read without calling
 ✔ AA 3 E api blocked shows the last good read with its time and the error
 ✔ AA 3 E a rate limit does not wait or retry in the request
 ✔ AA 3 refresh now reads even when fresh for anyone with ads access without write mode
 ✔ AA 3 the hourly command reads only when stale and is scheduled every hour
 ✔ AA 3 the scheduled sync refreshes the stored read after its run
 ✔ AA 4 a role without the ads link gets 404 and sees no balance or warning
 ✔ AA 4 guests are sent to login
 ✔ AA 4 the stored read holds no token
OK (20 tests, 146 assertions)

php vendor/bin/phpunit tests/Feature/MetaAds
OK (253 tests, 1193 assertions)
```

- Minor units:
  - PHP `123456` → `PHP 1,234.56`.
  - `5` → `0.05`.
  - USD `-1230` → `-12.30`.
  - `9007199254740993` → `90,071,992,547,409.93`. That is 2^53+1, where a float would lose the last digit.
  - JPY `1500` → `1,500.00` and VND `25000000` → `25,000,000.00` (offset 1).
  - A non-integer string, or a missing currency → "not available".
- Degraded field:
  - When `funding_source_details` is refused, there are 2 calls, and the second asks for the fields without it.
  - The payment cell reads "not available" on both rows.
  - The reason appears once.
  - A row missing `amount_spent`, `currency` and `funding_source_details`, with a non-numeric `balance`, renders "not available" in those cells, and the page is 200.

Full suite: `php -d memory_limit=2G vendor/bin/phpunit`

| | Tests | Failures |
|---|---|---|
| base 9fa8ed84 | 5,294 | 3 |
| branch (284 + 280 + 281) | 5,343 (+20 from 284, +29 cases from 280 and 281: 23 test methods, some with data sets) | 3 |

The 3 base failures:
- `ExampleTest::test_the_application_returns_a_successful_response`
- `Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
- `OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_... with data set "/owner/private"`

The branch has the same 3 failures and no others (time 20:37 vs 17:45 on the base).

**2. The page renders with seeded fake data.** A throwaway test, not committed, seeds two connections (Ana: 111, 222, 333; Ben: 333, 444). Ben's token is refused `funding_source_details`. The test GETs the page as the CEO. Excerpt of the HTML, with whitespace trimmed and the CSRF input removed:

```html
<div class="... border-red-300 bg-red-50 text-red-800" role="alert" data-warning="red">
  ⚠ <span class="font-semibold break-words">Kusina Grace</span> <span class="font-mono break-all">111</span>: In grace period</div>
<div class="... border-red-300 bg-red-50 text-red-800" role="alert" data-warning="red">
  ⚠ <span ...>Old Shop</span> <span ...>444</span>: Disabled</div>
<div class="... border-amber-300 bg-amber-50 text-amber-800" role="alert" data-warning="amber">
  ⚠ <span ...>Near Cap</span> <span ...>222</span>: spend cap 93% used</div>
<table class="min-w-full text-sm" id="ad-accounts-table"> ...
<tr data-account="111" class="bg-red-50">
  <td>Kusina Grace · 111 · PHP</td><td>Marketing User FB</td>
  <td><span class="text-red-700 font-semibold">In grace period</span></td>
  <td>PHP 18,450.75</td><td>PHP 1,250,043.21</td><td>No limit</td><td>Visa *4111</td><td>Nov 17, 2026 3:39 PM</td></tr>
<tr data-account="222" class="bg-amber-50">
  <td>Near Cap · 222 · PHP</td><td>Marketing User FB</td><td>Active</td>
  <td>PHP 300.00</td><td>PHP 46,500.00</td>
  <td>PHP 50,000.00 <div class="text-xs font-semibold">PHP 3,500.00 left · 93% used</div></td>
  <td>Visa *4222</td><td>Nov 17, 2026 3:39 PM</td></tr>
<tr data-account="444" class="bg-red-50">
  <td>Old Shop · 444 · USD</td><td>Marketing - OIC User FB</td>
  <td><span class="text-red-700 font-semibold">Disabled</span><div>Reason: Payment risk</div></td>
  <td>USD 0.00</td><td>USD 8,800.00</td><td>No limit</td><td>not available</td><td>Nov 17, 2026 3:39 PM</td></tr>
<tr data-account="333" class="">
  <td>Shared Shop · 333 · PHP</td><td>Marketing User FB, Marketing - OIC User FB</td><td>Active</td>
  <td>PHP 0.12</td><td>PHP 0.99</td><td>No limit</td><td>Visa *4333</td><td>Nov 17, 2026 3:39 PM</td></tr>
...
<div id="ad-accounts-notes"><div>Why some fields are "not available":</div>
  <div>Payment method not available for Marketing - OIC User FB&#039;s accounts: Graph error 100: (#100) Requires MANAGE for funding_source_details</div></div>
```

The same seeded data on `/ads_manager/report`:

```html
<div id="ad-accounts-warning" role="alert" class="rounded border px-4 py-2 text-sm break-words border-red-300 bg-red-50 text-red-800">
  ⚠ Ad accounts: Kusina Grace (In grace period), Old Shop (Disabled), Near Cap (spend cap 93% used)
  <span class="text-xs">(read Nov 17, 2026 3:39 PM)</span>
  <a href="http://localhost/ads_manager/meta/ad-accounts" class="underline font-semibold">Open Ad Accounts</a></div>
...
<h2 class="font-semibold">Facebook (Meta Ads API)</h2>
<a href="http://localhost/ads_manager/meta/ad-accounts" id="ad-accounts-link" ...>Ad Accounts: balance, spend cap, payment</a>
```

The page also contains the same rows as cards, which show below `md` (768 px). No screenshot was taken: the layout loads Tailwind from its CDN, and the built-in browser cannot open a local file. The 390 px layout is the card list, with `break-words`/`break-all` on names and ids and no fixed widths.

**3. No Graph call on a page load when a fresh cached read exists.** `test_AA_3_a_fresh_stored_read_makes_no_graph_call_on_page_load`:
- The first load makes 1 call.
- Two more loads at +9 min make 0 calls.
- At +11 min, the read happens again.

`test_AA_3_E_api_blocked_...` shows that a failed attempt also counts for the 10 minutes. `test_AA_3_another_read_in_progress_...` shows that, while the lock is held, neither the page nor Refresh now calls Meta. `test_AA_2_H_...` shows that the report page makes no Graph call.

**4. Branch pushed.** `git push -u origin feat/284-ad-accounts-health`: new branch on github.com:angelitoforbeast/likha; remote head `a26400fd` matched local HEAD (`git ls-remote`) before this evidence line was committed. Code is `707d9a27`; the later commits are tests and docs.

## Story changes

None. The Refresh-now permission and the hourly refresh follow the rulings below.

## Rulings

- Q1: Refresh now needs the "Ads" permission only. It writes nothing to Meta or the ads data, so write mode is not required.
- Q2: The report page never calls Meta. The stored read is refreshed hourly by the existing scheduler (no new cron line; `schedule:run` already runs) and after each scheduled `meta-ads:sync`. The read time is shown next to the warning.
- Q3: Reading Meta in the request when the data is stale is allowed only on the Ad Accounts page and Refresh now: one call per connection, 20 s timeout, under the lock.

## Merge danger

- **Ships with 280 and 281.** This branch is built on 9fa8ed84 with `fix/280-ads-updated-only-on-change` (a5a4f7ba) and `feat/281-meta-app-settings` (64992c69) merged in. Merging it brings in both of them. Merge 280 and 281 first (or together); after that, this branch adds only its own commits.
- **Files shared with 281:**
  - `MetaAdsController.php`: a new `use` line, a new key in `panel()`, and two new methods at the end of the panel section.
  - `routes/web.php`: two routes right after the 281 `meta/app/test` route.
  - `_meta_ads.blade.php`: one link in the card header.

  These conflict only if 281 changes again before the merge.
- **Graph::all signature:** two optional trailing parameters with the old defaults, so existing calls are unchanged.
- **Scheduler:** a new hourly `meta-ads:account-health`. It reads Meta only when there is a connection and the stored read is more than 10 minutes old: about 24 calls a day per connection, plus up to 3 after the scheduled syncs. On the second site (no Meta connection) it does nothing. The existing `schedule:run` cron runs it, so no cron change is needed.
- **Config:** none. No `.env` key and no migration. The `app_settings` row `meta_ads.account_health` is created on the first read.
- **Revert:** `git revert ce58d8b7 707d9a27`. That is the test rename, then the code commit; the docs commits can stay. Leaving the `meta_ads.account_health` row in place is harmless; delete it if you want the table clean (`DELETE FROM app_settings WHERE key = 'meta_ads.account_health'`). Nothing else holds state.
- **After deploy:** the first visit to `/ads_manager/meta/ad-accounts` (or the next hour's run) makes the first read. Until then, the report page shows no warning line.
