# 285 result: Meta Compare hidden from the UI

Base: live 09ef9a25. Branch `chore/285-hide-meta-compare`. No migration, no route, controller, job or config change.

## What changed

- `resources/views/ads_manager/_meta_ads.blade.php`:
  - Removed the "Compare with uploads" form (From/To dates and button posting to `ads_manager.meta.compare`). Sync now stays in the same row, still shown only when write mode is on.
  - Removed the whole "Resulta ng Compare" block (running with its 5-second reload, failed, and the "Compare: API vs uploads" result).
  - Removed the per-account Compare numbers ("upload n rows · API n rows", "not in uploads") and "Wala pa sa huling Compare." from the ad-account list.
  - Help text: "Sync now at ang schedule: ang may check lang." (the Compare sentence is gone). Write mode off now reads "OFF (no writes)" / "Off: no writes" instead of "compare only".
- The controller still loads the latest compare log and per-account numbers into `$meta`; they are no longer rendered (cheap, left as is). `POST /ads_manager/meta/compare`, `CompareMetaAds`, `meta-ads:compare` and the stored compare logs are unchanged.
- Tests:
  - New `tests/Feature/MetaAds/MetaAdsCompareHiddenTest.php`: with write mode on and a finished compare stored, the report page shows Sync now, the account checkbox and the Ad Accounts link, and does not show "Compare with uploads", "API vs uploads", the compare route or "Wala pa sa huling Compare.".
  - `MetaAdsAccountsTest`: the account-list test now asserts the compare numbers are not shown (was: shown).
  - `MetaAdsCompareTest`: the route and job are still tested in full; the two page checks now assert "Compare running…" and the result are not shown.

## Test output

```
vendor/bin/phpunit tests/Feature/MetaAds/MetaAdsCompareHiddenTest.php
OK (1 test, 8 assertions)

vendor/bin/phpunit tests/Feature/MetaAds
OK (254 tests, 1202 assertions)
```

Note: `php artisan test` shows a `file_get_contents(...)` warning per test on this machine for untouched tests too (a Collision reporter quirk); plain PHPUnit is clean.

## Merge danger

Low. View-only change, two-way door: revert the commit to bring Compare back. No data is touched.
