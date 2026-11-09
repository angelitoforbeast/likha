# 256: Result

Branch `fix/256-embeddable-ad-link`, from 078a87e0.

## What changed
- `app/Services/MetaAds/Insights.php:155-176` `Insights::adLink` now takes the whole creative:
  - S1: a digits-only `object_story_spec.video_data.video_id` → `https://www.facebook.com/reel/<video_id>`. That is the only video id in the fields we already request (`AD_FIELDS`), so nothing else was used.
  - S2: otherwise, `effective_object_story_id` "<page id>_<post id>" (both digits) → `https://www.facebook.com/<page id>/posts/<post id>`.
  - S3: a missing or malformed video id falls back to the story id. If neither is valid the result is null, and the stored ad_link stays as it was.
- `app/Services/MetaAds/Insights.php:222` the row mapping passes the creative (`self::adLink($cr)`).
- S4: no view change. `fbEmbedSrc` already sends `/reel/` links to the video plugin and `/<page>/posts/<post>` links to the post plugin.

## Tests
- `tests/Feature/MetaAds/MetaAdsAdLinkTest.php`: the 252 expectations now use the page/posts form. New tests are at lines 129 (video → reel, on insert and over a staff link), 139 (image/link creative → page/posts), 161 (6 malformed video ids → story fallback or null) and 167 (no video and no story id → null).
- MetaAds: 177 tests, 728 assertions, all pass.
- Full suite: 4862 tests, 78956 assertions, 2 failures, 6 skipped. The 2 failures are the known ones (ExampleTest::test_the_application_returns_a_successful_response, ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order), so there are no new failures.

## Notes
- An ad made from an existing video post (no `object_story_spec.video_data`) gets the page/posts link. The post plugin should still show it, but that has not been checked in a browser.
- Dynamic/asset_feed creatives have no `video_data`, so they get the page/posts link. Out of scope.

Final sha: the commit that last changed this file (`git log -1 -- docs/specs/256-embeddable-ad-link.result.md`).
