# 254 result: one creative per ad

Branch `feat/254-creative-per-ad`, from 078a87e0 (live production). Code at 96ec9b90; the branch tip is the commit that adds this file (`git log -1 feat/254-creative-per-ad`). Nothing merged, deployed or run on a server.

## Migration
`database/migrations/2026_11_23_100000_allow_several_creatives_per_campaign.php`
- `up()`: adds a plain index `ad_campaign_creatives_campaign_id_index` on `campaign_id` first, then drops every unique
  index on exactly `(campaign_id)`, found by columns through `Schema::getIndexes`, so the name doesn't matter. In
  production that is `ad_campaign_creatives_campaign_id_unique`. `ad_id` stays unique. It does nothing if the table is
  missing, and it is safe to run again.
- `down()`: if any campaign has more than one row, it throws a `RuntimeException` and changes nothing: "Cannot restore
  the unique index on ad_campaign_creatives.campaign_id: N campaign(s) have more than one row (one per ad since spec 254).
  Nothing was changed. Merge or delete the extra rows first, then roll back again." Otherwise it restores the unique
  index and drops the plain one.
- The table has about 3,200 rows, so both index changes are quick. Not run on any server.

## Writers (S1)
`app/Jobs/ProcessAdsManagerReportsUpload.php`
- `:51` `$fromSync`, set to true only while `importRows()` runs (`:237`), which is the Meta sync. The upload's
  `handle()` leaves it false.
- `:795` `CREATIVE_CONTENT_FIELDS` = headline, body_ad_settings, welcome_message, quick_reply_1..3.
- `upsertCreativesForChunk()` (`:801-…`):
  - An ad with no row of its own gets one (insertOrIgnore, `ad_id` unique), even when its campaign already has rows.
  - The old step that attached the ad's id to the campaign's row is removed. Campaign-only rows (no `ad_id`,
    `:859`) stay the campaign fallback, and their account is still filled when empty.
  - Sync only: an ad's existing row gets Meta's non-empty values for the six fields, written only when the value
    differs (`:915`). An empty or missing value never blanks a field.
  - Upload: existing rows keep today's behaviour. Their text is never overwritten; only an empty `account_id` is filled.
  - `ad_link` is unchanged from spec 252: the same overwrite with Meta's link, using `Insights::adLink` as it is.
- `app/Models/AdCampaignCreative.php:37` new `campaignFallback($campaignId, $cols)`: the row that stands for a
  campaign. That is the campaign-only row if there is one, else the oldest row, which was the campaign's single row
  before this change. All the "fallback to the campaign row" readers use it, so the choice is the same everywhere and
  never depends on row order.

## Backfill (S2)
`php artisan ads:backfill-creatives-per-ad [--dry-run]` (`app/Console/Commands/BackfillCreativesPerAd.php`)
- Finds the ads in `ads_manager_reports` with no `ad_campaign_creatives` row of their own. For each one it takes the
  ad's latest report row (newest `day`, then newest `id`) and copies campaign_id, campaign_name, page_name,
  ad_set_delivery, headline, body_ad_settings, welcome_message and quick_reply_1..3. Empty strings are stored as null.
- `account_id` comes from the report row's `account_id` when it has one, else from MAX(account_id) of the campaign's
  creative rows, which is how the readers derive it today. `ad_link` stays null.
- An ad whose latest report has no campaign id is skipped and counted, because `campaign_id` is NOT NULL in production.
- insertOrIgnore in chunks of 200 ads. It never updates an existing row, and a second run creates 0.
- Output (counts only):
  ```
  Ads without their own creative row: 3
  Would create: 2            (without --dry-run: "Created: 2")
  Skipped (no campaign id): 1
  ```
- ad_set_delivery is included although the spec does not list it. It is the ad's own value, the upload's insert sets
  it too, and the edit page puts Active rows first by it.

## Readers (S3)

### Changed
| Reader | file:line | Change |
|---|---|---|
| Creative preview (modal on /owner/private and the item page) | `app/Http/Controllers/AdsManagerCampaignsController.php:600` `creativePreview`, helpers `:698` `creativeItem`, `:720` `creativesOfAds`, `:756` `adNames` | level=ad returns the ad's own row, else `campaignFallback` (`:644`). level=adset and level=campaign return `creatives`: every distinct creative of the ads in it. Ads are ordered by total spend, then latest day. Identical content (body, headline, welcome, 3 replies, link) is listed once under the highest-spend ad, with every ad sharing it in `ad_ids`. `ad_name` comes from `ad_catalog.ad_name`, else "Ad <id>". An ad with no row of its own is left out rather than shown with another ad's content. If no ad has its own row, the result is the campaign's row as before (`:669`). For a campaign, ad rows stored under it without report rows come last. `creative` is still returned (the first item), so old clients still work. The cache key moved to `ads_mgr_creative_preview:v2:` so cached entries in the old shape are not served. |
| Modal, /owner/private | `resources/views/owner/private.blade.php:1834-1847` (switcher), `:2098` (`pick` state), `:2471` (reset on open) | With more than one creative, a row of pill buttons above the 3-column grid: "N different creatives", then one button per creative labelled with its ad name, plus "+K" when K more ads share it; the title lists the ad ids. A click sets `creativeModal.data.creative`, so all three sections (post, text, Messenger) show that creative. With one creative nothing is added. All text goes through `x-text`. |
| Modal, item page | `resources/views/item/index.blade.php:1249-1262`, `:1517`, `:1932` | The same switcher, the same state and the same reset. The callers in `resources/views/owner/_private_expand_inline.blade.php` (:96 campaign, :229 ad set, :342 ad) are unchanged; they still call `openCreativePreview`. |
| History log, creatives per event | `AdsManagerCampaignsController.php:341-366`, `:395` | Ad events use the ad's own row and fall back to the campaign's row. Campaign events use the campaign's row through the same choice as `campaignFallback`; before, they took MAX(id), which with several rows would be a random ad's row. Ad set events are unchanged (they already go through an ad id). |
| Ads table "has ad link" flag | `AdsManagerCampaignsController.php:2095-2130` | An ad with its own row uses only that row's link, never a sibling's. An ad without one uses the link of the campaign's fallback row. Campaign and ad set rows are unchanged (any ad in the campaign has a link). |
| Chatbot ad group preview (`ChatbotAdGroupController::ad` `:191` → `AdGroups::creative`) | `app/Services/Chatbot/AdGroups.php:1071` | The fallback is `campaignFallback` instead of `->first()` over several rows. |
| Edit page /ads-manager/edit-messaging-template | `app/Http/Controllers/AdCampaignCreativeController.php:46` (select `ad_id`), `:64-65` (search by ad id and campaign id), `:87-89` (after the chosen sort: campaign_id, campaign row first, then ad_id, so a campaign's ads sit together); `resources/views/ads_manager/edit-messaging-template.blade.php:108` | Already one table row per DB row, saved per row by id (`update`). It now shows "Ad <id>" or "Campaign row" under the campaign name with Blade `{{ }}`. The existing search fields still work. |

### Checked, unchanged (correct with several rows per campaign)
| Reader | file:line | Why it is fine |
|---|---|---|
| `historyData` accountByCampaign | `AdsManagerCampaignsController.php:528` | `GROUP BY campaign_id, MAX(account_id)`: one value per campaign, and every ad of a campaign is in the same account. |
| `batchData` linkedCampaignIds / accountByCampaign | `:980`, `:993` | `->unique()->flip()`, and GROUP BY: no duplicates. "Has a link" for a campaign = any of its ads. |
| `data` accountByCampaign | `:1798` | GROUP BY campaign: one row per campaign (tested: campaign c1 with 4 rows appears once, account 111 "Likha A"). |
| `data` linkedCampaignIds (campaign and ad set levels) | `:2083` | unique() + flip(). |
| `diag` crStats | `:60` | Diagnostic row counts: the total is now ads + campaign rows; `COUNT(DISTINCT account_id)` is unaffected. |
| GPTAdGeneratorController | `app/Http/Controllers/GPTAdGeneratorController.php:706` | Already keyed by `ad_id`. |
| AdsManagerCatalogController | `app/Http/Controllers/AdsManagerCatalogController.php:72` | Reads `ad_catalog`, not creatives. |
| StoreAdCatalogParents | `app/Jobs/StoreAdCatalogParents.php:30` → `AdGroups::storeParents` | Writes `ad_catalog` only. |
| DataHealth (out of scope) | `app/Services/MetaAds/DataHealth.php:156-157`, `:255` | MAX by ad, then MAX by campaign; pluck by ad_id. Unaffected. |
| Meta Compare | `app/Services/MetaAds/Compare.php:65` | `whereIn ad_id` subselect. |
| `AdsManagerReport::creative()` (hasOne by campaign) | `app/Models/AdsManagerReport.php:58` | Not used anywhere in app/, resources/ or routes/. |
| `AdsManagerReportImport` | `app/Imports/AdsManagerReportImport.php:104` | Not used anywhere (dead code; the upload is `ProcessAdsManagerReportsUpload`). |
| ad_catalog backfill migration | `database/migrations/2026_05_22_140100_backfill_ad_catalog_from_reports.php:110` | A one-off that already ran. |

## S4
The CPP page, the report page and Data health were not touched (`git diff 078a87e0 --stat` shows no CPP, report or
DataHealth file). Their tests are included in the full-suite numbers below.

## Rendered modal (several creatives)
This is the Alpine render of the switcher markup copied from `private.blade.php`, served from a scratch page with
Alpine 3.14.1 and the readers test's campaign c1 response (a2 and a3 share content; a1 is different). It was not
captured from the live app.
```html
<div class="ow-modal-section creative-switcher" style="border-top:1px solid #e2e8f0;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
  <span ...>2 different creatives</span>
  <button type="button" title="Ad a2, a3" style="...border:1px solid #1e40af;background:#1e40af;color:white;">Video two +1</button>
  <button type="button" title="Ad a1" style="...border:1px solid #cbd5e1;background:white;color:#0f172a;">Ad a1</button>
</div>
```
After a click on "Ad a1", that button became the highlighted one and the shown creative switched to welcome "W1".
A body of `Body <b>not bold</b>` rendered as `Body &lt;b&gt;not bold&lt;/b&gt;`, so the text stays escaped.

## Tests
New (25 tests):
- `tests/Feature/MetaAds/CreativePerAdTest.php` (9): two ads of one campaign keep separate rows through the sync (in
  one run, and in a later run beside an existing sibling row) and through the upload (in one file, and in a later file);
  the sync writes non-empty Meta text and never blanks (headline and body updated; welcome and quick reply 1 kept when
  Meta has none; welcome and quick replies 1-2 updated and quick reply 3 kept when Meta has two); a campaign-only row
  stays beside the ad's new row; a second sync makes no duplicates; the upload keeps staff text in an existing ad row
  and adds the missing ad.
- `tests/Feature/MetaAds/CreativePerAdMigrationTest.php` (4): the real create, add_ad_id and new migrations on sqlite;
  two rows per campaign are allowed and the campaign_id index is plain; ad_id is still unique; down() restores the
  unique index; down() refuses with the message and changes nothing when there are duplicates.
- `tests/Feature/MetaAds/BackfillCreativesPerAdTest.php` (3): dry-run counts and writes nothing; rows come from the
  latest report (not the campaign row), the account comes from the report or the campaign, an ad without a campaign is
  skipped, and existing rows are byte-identical afterwards; a second run creates 0.
- `tests/Feature/OwnerPrivate/CreativePerAdReadersTest.php` (9): campaign lookup returns distinct creatives, highest
  spend first and deduped; ad set lookup returns only its ads' creatives; ad lookup returns its own row, and the campaign
  fallback for an ad without one; a campaign-only campaign is unchanged; the campaigns list shows each campaign once with
  its account; the ads link flag never comes from a sibling; history ad events carry their own creative; the chatbot
  preview falls back to the campaign-only row; the edit page shows the ad of each row and finds a row by ad id.

Red check: with the app changes reverted, 20 of the first 23 new tests failed (plus the 2 changed spec 252 tests), and
the 2 "after a sibling" tests failed against the old job. Of the 3 that passed: the campaigns-list account test passed
because that reader was already correct, and the two one-batch tests passed because both ads were in one chunk. The two
"after a sibling" tests were added to cover that path.

Changed existing tests:
- `tests/Feature/MetaAds/MetaAdsAdLinkTest.php`: the sync now also replaces a staff headline with Meta's non-empty
  headline (spec 254: Meta is the source). A campaign row without an ad now stays, and the ad gets its own row and link
  (2 rows; before, the ad was attached to the campaign row).
- `tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php`: `/owner/private` re-pinned to `d680b2ff…`, with a note in its
  list of re-pins. The only render change is the switcher template and the `pick` state. Checked: with the view put
  back to 078a87e0, the old hash `19d9a00e…` comes out again.
- `tests/Feature/Item/SuppliersGroupTest.php`: the eight item page render pins re-pinned, for the same reason (the
  item page's copy of the modal). Checked the same way: with the item view put back to 078a87e0, all eight old hashes
  come out again.

Full suite (bootstrap shim, no memory_limit override, each run alone on the machine):
- Baseline 078a87e0, with that commit checked out: **4853 tests**, 78,936 assertions, 4 failures, 6 skipped. The
  failures are the 2 known ones, `ExampleTest::test_the_application_returns_a_successful_response` and
  `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`, plus 2
  timing tests that fail on the unchanged code: `AstraAddressRulesTest::test_S_26_18_…` and `…::test_S_26_23_…`.
  (A first baseline run overlapped with my edits and was thrown away.)
- Branch at 96ec9b90: **4878 tests** (+25 new), 6 skipped, 3 failures: the 2 known ones plus
  `BigBroadcastTest::test_S_167_1_5000_messages_on_50_pages_all_go_out_without_one_failed_for_waiting` ("3846 is
  identical to 5000" for the pool handoff). That test file passes alone: 10 tests, 30,077 assertions. It is a
  load-sensitive chatblast test that this branch does not touch. Its early stop is why that run shows 49,035
  assertions. An earlier full branch run, under load, had BigBroadcast passing and Astra `S_26_18` failing on its
  25 s limit, the same flakiness as the baseline.
- No new failures against 078a87e0.

## Open
- An ad with no row of its own and no report row cannot be backfilled. An ad whose latest report has no campaign id is
  skipped (counted in the output).
- Campaign-only rows are no longer turned into ad rows. Staff text typed on them stays visible on the edit page as
  "Campaign row", and it is only the fallback for ads that have no row of their own. After the backfill, that is ads
  without report rows.
- After the deploy, the sync overwrites staff-typed headline, body, welcome and quick-reply text of an ad whenever Meta
  returns non-empty text for that ad (the spec's decision). If the staff text should be kept, back up
  `ad_campaign_creatives` before the release.
- `ads:backfill-creatives-per-ad` has not been run anywhere. Run the dry run first on the server.
- The route `POST /ads-manager/edit-messaging-template` points to `AdCampaignCreativeController::bulkUpdate`, which does
  not exist. This was already so at 078a87e0, and the page does not use it. Not changed.
- `Insights::adLink` was not touched (spec 256 changes it on another branch). The sync writes whatever it returns.
- Not checked in the live app with real data. The modal was checked on a scratch page with the same markup.
