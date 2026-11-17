# 284 — Ad Accounts: balance, payment, limit and health per Meta ad account

## Why
The owner wants to see, per Meta ad account, the money side that the ads sync does not show today: the unpaid balance, the spend limit and what is left, total spent, the payment method, and the account's health (active, disabled, grace period), with a warning before an account stops delivering. Today the sync only reads account_status (app/Services/MetaAds/Connections.php, me/adaccounts with fields account_id,name,account_status). On 2026-11-09 two accounts were in IN_GRACE_PERIOD (account_status 9), which usually means a failed payment, and nobody saw it on the site.

## Stories and cases
**AA-1 Ad Accounts page (P1).** As the owner or a marketing lead, I open one page and see every ad account the site's Meta connections can read, with: name and account id; connection (whose token); status in words (Active, Disabled, Unsettled, Pending review, In grace period, Pending closure, Closed, etc.) and the disable reason in words when disabled; currency; balance (amount owed, unpaid); amount spent (lifetime); spend cap and remaining (cap − spent) with a % used; payment method display string (e.g. "Visa *1234") when the API gives it; the time the numbers were read.
- H: 5 accounts, all readable → 5 rows with every field; money shown in the account currency with 2 decimals (the Graph API returns balance/amount_spent/spend_cap as strings in the account's minor units — convert correctly; prove it with a test).
- N: no Meta connection on the site (the second site today) → an empty state saying no Meta connection, no error.
- N: an account returns an error or a field is not permitted (e.g. funding_source_details needs more access) → the row still shows what was readable, the missing field shows "not available", and the reason is listed once at the bottom; the page never fails.
- E: no spend cap (0 or missing) → "No limit", no % used.
- E: the same account visible through two connections → one row.
- E: token expired → the row says so and names the connection.

**AA-2 Warnings (P1).** A clear warning (red/amber) when an account is: In grace period, Unsettled, Disabled, or spend cap used ≥ 90%. The warning also shows on /ads_manager/report (one line with a link to the page) so the team sees it where they already work.
- H: one account in grace period → a red line on both pages naming it.
- N: all healthy → no warning line.

**AA-3 Freshness and cost (P1).** The page does not call Meta on every load for every viewer: read once and cache (e.g. 10 minutes) and/or record during the existing scheduled sync; a "Refresh now" button for permitted users that respects the same write/permission rules as Sync now; one Graph call per connection (batch the fields on me/adaccounts or act_<id>), never one per row per field.
- E: Meta rate-limit or block error (as on 2026-11-09, "(#200) API access blocked") → the page shows the last good read with its time and the error, no crash.

**AA-4 Access (P1).** Same middleware and permission as the existing Ads Manager report pages; nobody without access sees balances or payment strings.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Build the page now; it releases together with 280 and 281 | Owner's go on 2026-11-17 |
| Read-only toward Meta: never change a spend cap, budget or payment | Showing, not managing |
| Lean schema: no new table; cache, or the existing app_settings rows if a stored last-good read is needed | Owner's standing preference for lean schemas |

## Constraints
- Reuse the existing Meta Ads code (app/Services/MetaAds: Connections, Graph client, Sync); don't add a second Graph client. Never log or print tokens.
- Graph fields to request (confirm names against the code's Graph API version): account_id, name, account_status, disable_reason, currency, balance, amount_spent, spend_cap, funding_source_details. If funding_source_details is refused for some accounts, degrade per AA-1 N.
- Page under the Ads Manager area, linked from /ads_manager/report; this repo's Blade/Tailwind style; readable at 390 px.
- Feature tests with a faked Graph client.

## Threat model and risk
Untrusted: Meta API responses (strings, missing fields, errors). Sensitive: balances and payment strings — staff with Ads Manager access only. Tokens never leave the server. Risk tier: low-medium (read-only, money data shown).

## Allowed commands
composer install; php artisan test (with filters) and vendor/bin/phpunit via Herd PHP; php artisan route:list; git add, git commit, git push -u origin feat/284-ad-accounts-health.

## Done when
1. Feature tests for AA-1..AA-4 pass (faked Graph), including the minor-units conversion and the degraded-field case; the full suite adds no new failures compared with base 9fa8ed84 (list the base failures).
2. The page renders with seeded fake data: evidence (HTML excerpt or screenshot) in the result.
3. No Graph call happens on a page load when a fresh cached read exists (test).
4. The branch is pushed; the result has evidence and a merge-danger section (what ships with 280/281, how to revert, any config needed).

## Out of scope
Changing anything in Meta; billing history or invoices; the second site's Meta connection; deploy (the reviewer does it).

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and report.
