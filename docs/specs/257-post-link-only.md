# 257: The synced ad link is always the page/posts link

## Why
Spec 256 made video ads get https://www.facebook.com/reel/<video_id> from creative.object_story_spec.video_data.video_id. Checked in a browser after a real sync: those links do not play in the preview ("Video Unavailable") and do not open on Facebook ("This page isn't available") — the ad's video id is not the public reel id. The page/posts link from effective_object_story_id, https://www.facebook.com/<page id>/posts/<post id>, embeds through the post plugin and shows the video. 139 creatives now hold the broken reel links; the next sync with this fix rewrites them.

## Stories
**S1 (P1)** Insights::adLink returns https://www.facebook.com/<page id>/posts/<post id> from a digits-only "<page id>_<post id>" effective_object_story_id for every ad, video or not; video_data.video_id is no longer used for the link.
**S2 (P1)** No valid story id → null (ad_link unchanged), as before.

## Done when
1. Tests updated: a video creative with both video_id and story id → page/posts link; malformed/missing story id → null.
2. MetaAds tests pass; full suite no new failures vs 0ba56fc6.
3. Result file docs/specs/257-post-link-only.result.md with file:line, test counts, final sha. Branch pushed.

## Out of scope
Anything else.
