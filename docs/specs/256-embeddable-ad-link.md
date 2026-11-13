# 256: The synced ad link opens and embeds

## Why
Spec 252 makes the sync write ad_link = https://www.facebook.com/<post id> (Insights::adLink, app/Services/MetaAds/Insights.php around 155-166). Checked in a browser: that URL opens the ad on Facebook, but the creative preview on /owner/private and the item page embeds ad_link with the Facebook plugin (fbEmbedSrc in resources/views/owner/private.blade.php ~2490: /reel|watch|videos/ → plugins/video.php, else plugins/post.php), and plugins/post.php?href=https://www.facebook.com/<post id> shows "This Facebook post is no longer available". A reel link (https://www.facebook.com/reel/<video id>/) embeds and plays through plugins/video.php. Most ads are video ads, so 252's format would replace working reel links with links that do not preview.

## Stories
**S1 (P1)** For a video ad, the sync's link is https://www.facebook.com/reel/<video_id>, taking video_id from creative.object_story_spec.video_data.video_id (already requested: AD_FIELDS includes object_story_spec). If the API offers it more reliably elsewhere in the fields we already request, use that and say where.
**S2 (P1)** For a non-video ad with effective_object_story_id "<page id>_<post id>", the link is https://www.facebook.com/<page id>/posts/<post id> (the canonical post URL the post plugin expects).
**S3 (P1)** Validation as in 252: digits only for ids; no valid id → null (ad_link unchanged).
**S4 (P2)** If cheap, also make fbEmbedSrc treat /reel/ links as video (it already does) and a bare https://www.facebook.com/<digits> link as unknown (no change needed unless trivial). Do not change the preview layout.

## Decisions already made
| Decision | Why |
|---|---|
| Video ads → /reel/<video_id> | Verified to embed and play in the plugin. |
| Other ads → /<page id>/posts/<post id> | The post plugin's canonical form. |
| Only Insights::adLink (and its caller in the row mapping) changes | Small, safe fix before the next scheduled sync. |

## Done when
1. Tests: video creative → reel URL; image/link creative with story id → page/posts URL; malformed ids → null; no video and no story id → null.
2. MetaAds tests pass; full suite no new failures vs 078a87e0.
3. Result file docs/specs/256-embeddable-ad-link.result.md: file:line, test counts, final sha.
4. Branch pushed.

## Out of scope
One-row-per-ad (another spec), dynamic/asset_feed creatives beyond noting them, any deploy.
