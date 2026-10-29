# Result 189: the ad preview reads `body_ad_settings`

Status: done. Branch `fix/189-ad-preview-body`, based on 4b19fccd. Not pushed or deployed.

## Cause

`AdGroups::creative()` selected `headline, body, campaign_id` from `ads_manager_reports`. The column was added as `body` by `2025_07_17_222429_add_headline_and_body_...` and renamed to `body_ad_settings` four minutes later by `2025_07_17_224216_rename_body_to_body_ad_settings_...`, so production has no `body`.

Why the tests did not catch it: `AdPreviewTest`, `AdBasedPagesTest` and `AdGroupPagesTest` build the table by running only the create migration and the 222429 migration, not the rename. Their test table had `body` and no `body_ad_settings`, the opposite of production. `AdPreviewTest` even wrote the report body into `body`.

A second reason it stayed quiet: the tests use SQLite, which treats a double-quoted name that is not a column as a string literal (checked on SQLite 3.49.2). So with the right schema the old query does not error in tests; the report body just comes back empty. The regression test catches it through the value, not through a 500.

## Fix

- `app/Services/Chatbot/AdGroups.php` `creative()`: selects `body_ad_settings` and falls back to `$report->body_ad_settings`. The JSON key stays `body`; the creative's own `body_ad_settings` still comes first.
- The three ad tests also run the rename migration, so their `ads_manager_reports` has production's columns (`headline`, `body_ad_settings`, `ad_id`, `campaign_id`, `day`). `AdPreviewTest` S-181.3 writes the report body into `body_ad_settings`.

## Other places checked

Grepped every file in `app`, `resources`, `routes`, `database` and `tests` that touches `ads_manager_reports` or `AdsManagerReport` for a read of a `body` column. `creative()` was the only one. The others already use `body_ad_settings` (`AdCopyController`, `AdsManagerCampaignsController`, `GPTAdGeneratorController`, `ProcessAdsManagerReportsUpload`, `MetaAds\Insights`, where `body` is Meta's creative field). `ChatbotResultsTest` and `ChatbotContentCheckTest` build their own table without a body column and do not read one.

## Tests

- Regression: `AdPreviewTest` with the production columns and the old code: 1 failure (S-181.3, `body` was `''`, expected `Report body`). With the fix: OK, 3 tests, 26 assertions.
- `tests/Feature/Chatblast` (holds all ad-group tests: `AdGroupsTest`, `AdGroupRunTest`, `AdGroupPagesTest`, `AdBasedPagesTest`, `AdPreviewTest`): at 4b19fccd 1363 tests, 0 failures, 1 skipped; after the fix 1363 tests, 0 failures, 1 skipped. No new failures.

## Commits

- f632a229 docs: save the ad preview body column brief (spec 189)
- acbac7cc test: ad tests use the production columns of ads_manager_reports (spec 189)
- f2ba0816 fix: ad preview reads body_ad_settings from ads_manager_reports (spec 189)
