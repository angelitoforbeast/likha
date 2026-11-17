# 285: hide the Meta "Compare: API vs uploads" from the UI

## Why

Compare was used once to prove that the Meta Ads API matched the uploaded reports before write mode was turned on. The API sync is now the main source and nobody uses Compare day to day. It is hidden for everyone. **Hide only**: the controller method, route, job and data stay, so it can come back later.

## Change

In `resources/views/ads_manager/_meta_ads.blade.php`:
- Remove from the page the "Compare with uploads" form and button (the form posting to `route('ads_manager.meta.compare')`).
- Remove the whole "Resulta ng Compare" block: running, failed and the result table "Compare: API vs uploads, … (walang isinulat)".
- Remove the per-account Compare numbers and the "Wala pa sa huling Compare." hint in the ad-account list.
- Reword nearby help text that mentions Compare so it no longer does, for example "Sync now at ang schedule: ang may check lang."
- Keep Sync now, write mode, the account checkboxes, the Ad Accounts link and everything else as it is.

If the controller computes compare data only for this view, it may stay. A cheap unused query is acceptable; no refactor.

## Tests

A short feature test on `tests/Feature/MetaAds/MetaAdsTestCase`:
- `/ads_manager/report` no longer contains "Compare with uploads" or "API vs uploads";
- it still contains "Sync now".

Run the new test and the whole `tests/Feature/MetaAds` folder; both must pass.

## Done when

- The report page shows no Compare form, result or per-account numbers, and still shows Sync now, write mode, the account checkboxes and the Ad Accounts link.
- The tests above pass.
- Branch `chore/285-hide-meta-compare` pushed; no deploy.
