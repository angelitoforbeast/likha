# 252 result: the ads sync fills the ad link from the post id

Branch `feat/252-sync-ad-link`, from 840da5b6 (live production).

## What changed
- `app/Services/MetaAds/Insights.php:155-166` new `Insights::adLink()`: `effective_object_story_id` "P_Q" becomes
  `https://www.facebook.com/Q`. Accepts only `^\d+_(\d+)$` (digits on both sides, no trailing newline); anything else,
  including a missing id, is `null`.
- `app/Services/MetaAds/Insights.php:211` `map()` puts `ad_link` on the row the sync builds (only when the ad detail is
  known, like the other creative fields).
- `app/Jobs/ProcessAdsManagerReportsUpload.php:899-914` end of `upsertCreativesForChunk()`: for every row with a
  non-empty `ad_link`, `UPDATE ad_campaign_creatives SET ad_link, updated_at WHERE ad_id = ? AND (ad_link IS NULL OR
  ad_link != ?)`. It runs after the existing `insertOrIgnore` and the ad_id attach, so a new creative row, an existing
  ad_id row (which before only got an account_id backfill) and a campaign row that just received this ad_id all get the
  link. Rows without a link never reach the update, so nothing is blanked. An unchanged link is not rewritten (no
  `updated_at` churn).
- `ads_manager_reports` is untouched: the facts upsert copies an explicit list of columns, and `ad_link` is not in it
  (S2). No migration.
- Tests: `tests/Feature/MetaAds/MetaAdsAdLinkTest.php` (new, 14 tests); `tests/Feature/MetaAds/MetaAdsTestCase.php:146-147`
  adds the existing `ad_link` column to the test schema of `ad_campaign_creatives`.

## Done when
1. Tests in `MetaAdsAdLinkTest`: "123_456" on a new ad gives `https://www.facebook.com/456`; it overwrites a staff link
   (other fields keep the staff values); a campaign row without an ad gets the ad and the link; missing, empty, no "_",
   empty post part, non-digit post part, a path, three parts and a trailing newline all leave the staff link and leave a
   new row's link null; the mapped row carries the link only for a valid id; `ads_manager_reports` gets no `ad_link`; an
   XLSX upload leaves a staff link as it is and creates new creatives with a null link.
2. `tests/Feature/MetaAds`: OK (159 tests, 676 assertions). `tests/Feature/CPP`: OK (33 tests, 191 assertions). Full suite (bootstrap shim, no memory_limit override): 4840 tests, 78891 assertions, 2 failures, 6 skipped. Baseline 840da5b6 was 4826 tests with the same 2 known failures, so +14 new tests and no new failures: `ExampleTest::test_the_application_returns_a_successful_response` and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`.
3. `git diff 840da5b6 --stat`: the two app files above, the two test files, and the two spec docs. No migration.
4. This file.
5. Pushed: `git push origin feat/252-sync-ad-link`.

## S3: which ads get links
From the sync code path: only ads that appear in the Insights rows of a synced account and date range (an ad with
insights in that range), whose ad detail (`/act_x/ads`) came back with a creative whose `effective_object_story_id`
matches `<digits>_<digits>`, and whose row was written (rows skipped for a missing page name, and rows refused by
DataHealth, are not; the creatives upsert runs before the refusal check, so a refused row still gets its link). Ads with
no spend or activity in the range are not read, so their link stays as it is until a sync range covers them.

The edit page (`/ads-manager/edit-messaging-template`, `AdCampaignCreativeController::editMessagingTemplate`/`update`)
is not changed: it still lists `ad_link` and saves it through `update()` (`ad_link` nullable string, max 2048). A link
staff save there is replaced on the next sync that reads that ad with a valid post id.

## Not done / open
- Ads whose creative has no row of their own are not linked. The creatives upsert (unchanged) skips an ad when its
  campaign already has a creative row owned by another ad; the repo's migrations still have `campaign_id` unique on
  `ad_campaign_creatives` (2025_07_18_154633), so in production only one creative row per campaign can exist. Such ads
  get no ad_link from this change. Not checked against production (no server access by design).
- Instagram-only creatives and creatives without `effective_object_story_id` (for example some dynamic or catalog ads)
  keep their staff link; out of scope.
- No browser check of the edit page was run (code unchanged there).
- The ad_link backup before release is for the release step; nothing deployed.

Final sha: the code is at aa1ae6b2; the branch tip is the commit that adds this file (see `git log -1 feat/252-sync-ad-link`).
