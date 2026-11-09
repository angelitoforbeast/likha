# 257: Result

Branch `fix/257-post-link-only`, from 0ba56fc6.

## What changed
- `app/Services/MetaAds/Insights.php:155-170` `Insights::adLink`:
  - S1: for every ad, video or not, a digits-only `effective_object_story_id` "<page id>_<post id>" → `https://www.facebook.com/<page id>/posts/<post id>`. The `object_story_spec.video_data.video_id` branch (the `/reel/<video_id>` link) is removed; the video id is no longer read.
  - S2: a missing or malformed story id → null, so the stored ad_link stays as it was.
- `app/Services/MetaAds/Insights.php:216` comment only (spec 252/257); the call `self::adLink($cr)` is unchanged.
- No view, field list or other change. The next sync rewrites the stored `/reel/` links of creatives that have a valid story id.

## Tests
- `tests/Feature/MetaAds/MetaAdsAdLinkTest.php`:
  - line 129: a video creative with both a video id and a story id → page/posts link, on sync (also over a stored `/reel/` link) and in `adLink` directly.
  - line 140: a video creative with no or a malformed story id → the stored link stays, a new row gets null, `adLink` returns null.
  - line 174: the 6 malformed video ids from 256 now assert the video id never decides the link (story id → page/posts link, no story id → null).
  - The old 256 test for the reel link is replaced by line 129.
- MetaAds: 178 tests, 733 assertions, all pass.
- Full suite: 4863 tests, 78961 assertions, 4 failures, 6 skipped. Two are the known ones (ExampleTest::test_the_application_returns_a_successful_response, ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order). The other two are wall-clock limits in `tests/Feature/NightRun/AstraAddressRulesTest.php` (S_26_18 at line 1541 and S_26_23 at line 2355, "less than 25.0" seconds; 26-36 s on this machine). They fail the same way at 0ba56fc6 run alone, and that test does not touch MetaAds, so there are no new failures.

Final sha: the commit that last changed this file (`git log -1 -- docs/specs/257-post-link-only.result.md`).
