# Result 176: ad account names fall back to the names on /ads_manager/ad_account

Status: done. Branch `feat/176-ad-account-names`, based on 88f49262. Not pushed, not deployed.

## What changed
- `Connections::names()` (new, static): Meta's name unless it is empty or equal to the account ID (with or without `act_`); then the active `ad_accounts` row's name for that ID (stored with or without `act_`, the oldest active row if there are several); then the ID. Reads `ad_accounts` only, one query, guarded by `Schema::hasTable`.
- `Connections::accounts()` applies it, so the account list and checkboxes on /ads_manager/report and `meta-ads:accounts --list` show the fallback name.
- `Compare::run()` takes each account's name from `accounts()`, so the Compare report (page and `meta-ads:compare`) shows it.
- `MetaAdsController::panel()`: the people table's per-connection ad account names use the same names.
- Run logs: the Sync run log and its problems list name accounts by ID only (`act_<id>`), never by name, so nothing to change there.
- Unchanged: the selection (spec 173), `ad_accounts` (no writes), no new tables, no migration. Compare reports saved before this deploy keep the name they were saved with.

## Case table
| Case | Test | Result |
|---|---|---|
| S-176.1 | `test_S_176_the_account_name_falls_back_…` "S-176.1 Meta name is the ID"; `test_S_176_1_the_list_the_accounts_command_and_compare_show_the_ad_accounts_name` | pass |
| S-176.2 | same, "S-176.2 a real Meta name is kept" | pass |
| S-176.3 | same, "S-176.3 no name anywhere: the ID" | pass |
| S-176.4 | same, "S-176.4 an inactive row is not used" | pass |
| S-176.5 | same, "S-176.5 Meta name act_ID" and "S-176.5 ad_accounts ID with act_" | pass |
| S-176.6 | `tests/Feature/MetaAds` OK (79 tests, 378 assertions; 72 before + 7 new); `tests/Feature/AdAccount` OK (19 tests, 99 assertions) | pass |

Red run: `S-176.1 Meta name is the ID` — "Failed asserting that two strings are identical." (6 of 7 red; S-176.2 already held and pins today's behaviour).
Baseline at 88f49262: MetaAds OK (72), AdAccount OK (19). No new failures.

## Story changes
- Added S-176 to `qa/stories.md` from the brief's cases. S-176.1 also names the people table (per-connection account list), which shows the same names.

## Review
Low tier (display only, read-only query, no new route): lint (`php -l`) and the tests above; no skeptic review run. No browser check (the view markup did not change).

## Deploy notes
No migration, no new config. `php artisan view:clear` is enough (no Blade changed, so even that is only a precaution); restart the queue workers so the Compare job loads the new code (`php artisan queue:restart`).

## Accepted / suggestions
- None open.

176: done — Meta account names that are empty or just the ID now show the active /ads_manager/ad_account name, else the ID.
