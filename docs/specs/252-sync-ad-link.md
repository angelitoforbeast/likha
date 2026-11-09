# 252: The ads sync fills the ad link from the post id

## Why
ad_campaign_creatives.ad_link (edited at /ads-manager/edit-messaging-template) is typed by staff by hand; on 2026-11-08 only 22 of 202 ads that spent had one. The Meta Ads sync already requests creative.effective_object_story_id ("<page id>_<post id>") in Insights::AD_FIELDS (app/Services/MetaAds/Insights.php:33) but only uses it to find the page. Staff links already use the form https://www.facebook.com/<post id> (1,659 of 2,524 saved links start with https://www.facebook.com/). The owner wants the sync to fill the link so staff no longer type it, including replacing links staff typed.

## Stories
**S1 (P1)** When the sync writes an ad whose creative has effective_object_story_id "P_Q", ad_campaign_creatives.ad_link for that ad becomes `https://www.facebook.com/Q`.
- Overwrites an existing staff link (owner's decision).
- No effective_object_story_id (or malformed, no "_", empty post part): ad_link is left as it is (never blanked).
- A manual upload (CSV/XLSX import through the same importer) does not change ad_link unless the file row carries a link value the importer already understands; uploads keep today's behaviour.
**S2 (P2)** The ads_manager_reports row is not given a new column; the link lives only in ad_campaign_creatives (no migration).
**S3 (P2)** The result reports, from the sync code path, which ads get links (only ads the API reads in the synced range) and confirms the edit page still shows and saves ad_link.

## Decisions already made
| Decision | Why |
|---|---|
| Link format https://www.facebook.com/<post id> | Matches what staff already save and opens the ad's post. |
| The sync overwrites staff links | The owner wants no manual link work; Meta is the source. A backup of ad_link is taken before release. |
| No new table or column | Keep the schema lean; the link already has a column. |

## Constraints
- Find where the sync turns an ad into a row (Insights row mapping around Insights.php:195-212) and where creatives are upserted (ProcessAdsManagerReportsUpload::upsertCreativesForChunk around line 772, reused by Sync via `new ProcessAdsManagerReportsUpload('')` in Sync.php:180). Carry the link through the row the sync builds; set ad_link in the creatives upsert only when the row has a non-empty link (so uploads without it never blank it). Make sure the existing update path for existing ad_id rows actually writes ad_link (check whether the upsert uses insertOrIgnore + separate updates, and include ad_link in the update for sync rows).
- Threat model: the post id comes from Meta's API (trusted-ish); still accept only digits for the post part before building the URL. Risk tier: low-medium (writes a column on production data that staff edit).

## Done when
1. Tests: sync row with "123_456" → ad_link https://www.facebook.com/456 on insert and on update over a staff value; missing/malformed id leaves ad_link unchanged; an upload row without a link leaves ad_link unchanged.
2. CPP and MetaAds tests pass; full suite adds no new failures vs 840da5b6 (name the known ones).
3. No migration; `git diff 840da5b6 --stat` lists only the sync/importer code, tests and docs.
4. Result file docs/specs/252-sync-ad-link.result.md: files changed (file:line), test counts, final sha, and anything not done.
5. Branch pushed.

## Out of scope
Data health changes, other creative fields, a preview_shareable_link, Instagram links, any deploy.
