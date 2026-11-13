# 253 result: Data health flags only uploaded accounts the API cannot read, checked after each upload

Branch `feat/253-data-health-uploaded`, from 840da5b6. Amendment 253-1 (handoff/253-data-health-uploaded/AMENDMENT-1.md) is part of the spec.

## Amendment 253-1 (applied)
- An account the API cannot read that has upload rows for the checked day is "Not readable by API" and in the red notice. This replaces the earlier rule that showed it as OK; the existing test now expects 333 → Not readable by API, 777 → No recent spend, red notice 444, 555, 333.
- Order: MISSING, then NOT_READABLE (rows that day, not in the API), then NEW, OK, UNTICKED, QUIET. Zero-row accounts the API cannot read fall to QUIET.
- S2/S3: the recorded check runs right after the upload log is marked done in `handle()`; the sync path is untouched; the saved JSON has `trigger` ("upload" or "scheduled") beside `checked_at`, shown on the card.

## What changed (file:line)
- `app/Services/MetaAds/DataHealth.php:78-88` the status order. NOT_READABLE needs upload rows that day and an account the API cannot read; it comes right after MISSING. NEW now applies only to accounts the API reads (`$inApi && $first >= $newFrom`), so a zero-row account the API cannot read is QUIET even when it was just added to /ads_manager/ad_account (S1 case 1: it must not be in the red notice).
- `app/Services/MetaAds/DataHealth.php:112-127` `TRIGGER_SCHEDULED` / `TRIGGER_UPLOAD`; `record($day, $trigger = 'scheduled')` saves `trigger` in the same app_settings JSON (`meta_ads.data_health`). The 12:00 command still calls `record()`, so it saves "scheduled".
- `app/Jobs/ProcessAdsManagerReportsUpload.php:174` calls `recordDataHealth()` at the end of the successful path, after the upload log is marked done and the refusals are recorded (only when there is an upload log).
- `app/Jobs/ProcessAdsManagerReportsUpload.php:211-224` `recordDataHealth()`: `DataHealth::record(null, 'upload')`, inside try/catch. A failing check logs a warning and does not fail the finished upload; the 12:00 run is the fallback.
- `resources/views/ads_manager/_data_health.blade.php:14, 23, 25-31` the card shows "Last recorded check: <checked_at> (after an upload | scheduled) · for <day>", or "none yet". Older saved checks without `trigger` read as scheduled. The legend now says Not readable by API = has upload rows yesterday but the API cannot read it.
- `resources/views/ads_manager/_data_notice.blade.php:1` comment only.
- `tests/Feature/MetaAds/AdsDataHealthTest.php` updated S_178 expectations (333, 777, notice ids, trigger) and new S_253_1 / S_253_3 tests.
- `tests/Feature/MetaAds/AdsDataHealthAfterUploadTest.php` new S_253_2 tests.
- `routes/console.php` unchanged: the 12:00 run (`ads:data-health --record`) stays as the fallback.

## S2 choice
Once per finished upload, synchronously at the end of the upload job (already a queued job), no debounce. The check is one aggregate query over 60 days of ads_manager_reports plus app_settings reads, no Meta API call, so running it after each file is cheap; each run overwrites the one saved check, so the latest upload wins.
The Meta sync's internal importer (`Sync.php:180` `new ProcessAdsManagerReportsUpload('')`) only calls `importRows()`, never `handle()`, so it does not record a check. A failed upload (missing file, missing columns, an exception) does not record either.

## The 8 zero-row accounts flagged on 2026-11-09
Not readable by API, 0 upload rows and P0 spend that day: they now show "No recent spend" (QUIET), grey, and are not in the red notice. Exception: one that had upload spend in the 3 days before the checked day would show "Missing yesterday" (that rule is unchanged); I could not check production data from here.

## Migration
None. The trigger lives in the existing app_settings JSON.

## Tests
- Data health: tests/Feature/MetaAds/AdsDataHealthTest.php + AdsDataHealthAfterUploadTest.php: 15 tests, 58 assertions, all pass.
- tests/Feature/MetaAds: 154 tests, 676 assertions, all pass.
- Full suite: 4835 tests, 78891 assertions, 2 failures, 6 skipped. The 2 failures are the known baseline ones at 840da5b6 (4826 tests): ExampleTest::test_the_application_returns_a_successful_response and ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order. No new failures; +9 tests from this change.

## Final sha
7884838e (the code commit; this result file is the commit after it).
