# Brief 189: the ad preview fails on a column that does not exist

Bug: clicking an ad to preview it (spec 181, `ChatbotAdGroupController` line ~170 → `App\Services\Chatbot\AdGroups::creative()` line ~528) returns a 500 on production: `SQLSTATE[42S22] Unknown column 'body' in 'field list' (SQL: select headline, body, campaign_id from ads_manager_reports …)`. Production's `ads_manager_reports` has `headline` and `body_ad_settings` (no `body`), plus `ad_id`, `campaign_id`, `day`. 4 errors on 2026-10-29.

Phase 1 (investigate): confirm why tests did not catch it (likely the test schema/factory has a `body` column that production lacks — check the migrations for ads_manager_reports and any test-only schema).

Phase 2 (fix): read `body_ad_settings` (returned as the creative's `body` as before), make the test schema match production's columns so a regression test fails before and passes after, and grep for any other read of `ads_manager_reports.body`. No other change.

Done when: the regression test fails before and passes after; `tests/Feature/Chatblast` and the ad-group tests add no new failures vs 4b19fccd.

Result: cause, fix, other places checked, test counts.
