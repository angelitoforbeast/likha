# 254: One creative per ad

## Why
ad_campaign_creatives holds each ad's messaging template (welcome message, 3 quick replies), headline, caption (body_ad_settings) and ad_link, but campaign_id is unique (migration 2025_07_18_154633), so a campaign keeps a single row. On 2026-11-08, 51 of the 93 campaigns that spent had 3 ads, and the ads of one campaign sometimes have different content. Only 87 of 202 ads that spent had their own row; the others show their campaign's row, which belongs to another ad. The owner wants each ad's own content and link kept and shown, including in the creative preview on /owner/private, where a campaign or ad set can contain several ads with different content.

## Stories
**S1 (P1) One row per ad.** The sync (Meta API, via ProcessAdsManagerReportsUpload::importRows / upsertCreativesForChunk) and the manual upload create one ad_campaign_creatives row per ad_id. Two ads in one campaign with different welcome messages keep both.
- The sync updates its ad's own row with the non-empty values Meta returns (headline, body_ad_settings, welcome_message, quick_reply_1..3, ad_link as in spec 252). Empty values never blank a field.
- The manual upload creates missing per-ad rows; for existing rows keep today's upload behaviour (do not start overwriting staff text from uploads).
- Rows without an ad_id (campaign-only rows from old uploads) keep working as the campaign fallback.
**S2 (P1) Backfill.** A one-off artisan command (idempotent, dry-run option, prints counts only) creates the missing per-ad rows for ads in ads_manager_reports that have no ad_campaign_creatives row of their own, from that ad's most recent ads_manager_reports row (campaign_id, campaign_name, page_name, account_id if derivable as today, headline, body_ad_settings, welcome_message, quick_reply_1..3). It never changes existing rows. ad_link stays empty there until the sync fills it.
**S3 (P1) Readers.** Every reader of ad_campaign_creatives that assumed one row per campaign works with several:
- AdsManagerCampaignsController creative lookup (around lines 574-670, used by the /owner/private creative modal): level=ad returns that ad's row (fallback to the campaign row as today); level=adset and level=campaign return all distinct creatives of the ads in it (dedupe identical content), each with its ad_id and ad name if available, newest/highest-spend first if spend is at hand.
- The /owner/private creative modal (resources/views/owner/private.blade.php around 1795-2080): when there is more than one distinct creative, show them all (e.g. a small switcher or stacked cards labelled by ad name/id); with one, unchanged.
- The edit page /ads-manager/edit-messaging-template (AdCampaignCreativeController): one row per ad, grouped or sortable by campaign, saving per row; search still works.
- Account lookups by campaign (accountByCampaign, linkedCampaignIds, crStats and similar in AdsManagerCampaignsController; AdGroups, ChatbotAdGroupController, GPTAdGeneratorController, AdsManagerCatalogController, StoreAdCatalogParents): list each reader with file:line in the result and make each correct with several rows per campaign (no duplicate counts, no wrong account).
**S4 (P2)** The CPP page, the report page and the Data health are unaffected.

## Decisions already made
| Decision | Why |
|---|---|
| Keep the table; drop the unique constraint on campaign_id (keep a plain index on it); ad_id stays unique | No new table; the owner wants each ad's own content. |
| Meta (sync) is the source of truth for each ad's content and link | The owner wants no manual work; spec 252 already overwrites the link. |
| Backfill from ads_manager_reports, never by copying the campaign row | Copying would give ads another ad's content. |
| Migration must be safe on production (about 3,200 rows; dropping a unique index and adding a plain one is quick) | Live table. |

## Constraints
- Migration with down(): restoring the unique index may fail if duplicates exist; make down() say so clearly instead of corrupting data.
- Threat model: no new external input; text shown in the modal and edit page must stay escaped (Alpine x-text / Blade {{ }}). Risk tier: medium (production migration, several readers).

## Done when
1. Tests: two ads in one campaign with different content keep separate rows through the sync and through the upload; sync updates non-empty fields only; backfill creates rows from reports and is idempotent; campaign/adset lookup returns several distinct creatives; ad lookup returns its own row; account lookups do not duplicate.
2. Full suite: no new failures versus 078a87e0 (name the known ones).
3. Result file docs/specs/254-creative-per-ad.result.md: migration, every reader changed (file:line) and every reader checked but unchanged, the backfill command name and its dry-run output shape, how the modal shows several creatives (a rendered snippet), test counts, final sha.
4. Branch pushed.

## Out of scope
Changing the CPP page, Data health, the send queue, any deploy, running the backfill on any server.
