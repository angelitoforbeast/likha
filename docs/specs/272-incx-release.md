# 272-incx: Likha main brought into the Incepxion line (specs 242-272)

Branch `release/272-incx`, from Incepxion's live main `d297c843`, merged with Likha main `cdfb082c`. Not deployed,
not merged to main.

## Specs brought in

| Spec | What |
|---|---|
| 242 | The marketing OIC sees the server storage; every screen warns at 80% |
| 243 | The Storage view and its banner follow the nav settings, not a fixed role |
| 244 | The Meta Ads sync brings in video views (3-second, ThruPlay, 50%) |
| 245 | The problems banner splits its list into Red, Orange and White tabs |
| 246 | Hook Rate, Hold Rate and Hold Rate 50% per ad; the Ads Manager upload requires the video columns |
| 247 | Retrieve 2 answers in seconds instead of timing out |
| 248 | Retrieve 2 reads created_at as Manila time |
| 249 | The ads upload accepts Meta's "15-second ThruPlays" column |
| 250 | The CPP page shows when ads spend and orders were last updated |
| 252 | The ads sync fills the ad link from the post id |
| 253 | Data health flags only uploaded accounts the API cannot read, checked after each upload |
| 254 | One creative per ad |
| 255 | The chatblast setup delivery check uses the page index |
| 256 | The synced ad link opens and embeds |
| 257 | The synced ad link is always the page/posts link |
| 258 | Days running on the page row, and one column list for the page and its settings |
| 261 | /owner/private opens fast and "Expand all" takes seconds |
| 262 | Every Botcake customer of every page is read, all time, several pages at once |
| 263 | Broadcast to a chosen audience of PSIDs (/chatblast/broadcast) |
| 265 | Encoding timetable (encoding, J&T upload and waybill download finish times per day) |
| 266 | Ads daily output (what marketing did with the ads each day) |
| 268 | Ads daily output shows the item, the right promo and the main flow picture |
| 269 | Waybill labels fetched after booking and stitched when printing; "All ready pages" in booking |
| 270 | Checklist tasks carry multi-line instructions with clickable links |
| 271 | A page needs a working shipping-status setup to be added; a page with ads or orders but no setup is urgent |
| 272 | Botcake Accounts (marketing adds a Botcake access token; pages and page tokens kept in step) |

Already on both lines (ported earlier; one copy kept, the files were identical or merged cleanly): 251, 259, 260,
264, 267.

## Conflicts and how each was resolved

| File | Conflict | Resolution |
|---|---|---|
| `config/jnt.php` | Incepxion's 267 queue block (print on `default`) vs Likha's (print on `jnt-print`, plus 269's `label` queue and `labels` settings) | Likha's block with Incepxion's print default kept: `print` = `default`, Incepxion comment kept; `label` queue and `labels` settings from Likha |
| `app/Jobs/BuildJntWaybillBulkPrint.php` | Incepxion's `onQueue(... 'default')` vs Likha's 269 rewrite (stored labels, parallel fetch, `jnt-print`) | Likha's 269 code, with Incepxion's queue fallback `default` and its comment |
| `app/Jobs/CreateJntOrder.php` | Likha's 269 `queueLabel()` added next to `markFail()` | Likha's version (Incepxion's 267 port is identical to Likha's 267) |
| `app/Jobs/ProcessAdsManagerReportsUpload.php` | Likha's 253 `recordDataHealth()` added where Incepxion's 264 port sits | Likha's version (contains the same 264 fix plus 246, 249, 253, 254) |
| `tests/Feature/Jnt/JntQueuesTest.php` (add/add) | Both lines added the 267 test | Incepxion's header and `test_bulk_print_stays_on_default_on_incepxion` (asserts `default`); Likha's fake J&T answer for the 269 label fetch (BILLQUERY) |
| `deploy/supervisor/likha-queue-jnt.conf` (no textual conflict) | Incepxion's 7aa65445 removed it; Likha added it (267, 269) | Removed again: Incepxion keeps only `incepxion-queue-jnt.conf` (booking workers) |

## Incepxion adaptations

1. **Data health without a Meta Ads API connection (253)** — `fix(incepxion)` 8c9d88df.
   Incepxion's ads data is upload-only. Spec 253 made every uploaded account the API cannot read "Not readable by
   API" (red notice on /ads_manager/report), so on Incepxion every uploaded account would be flagged forever, and the
   check now also runs after each upload. `DataHealth::evaluate` gives "Not readable by API" only when at least one
   API connection exists; with none, an uploaded account with rows is OK (MISSING still works). With a connection the
   253 rule is unchanged. Tests: `tests/Feature/MetaAds/AdsDataHealthUploadOnlyTest.php` (3 tests);
   `AdsDataHealthAfterUploadTest` gets a connection for another account before it asserts the flag.
2. **No label job after booking (269)** — `fix(incepxion)` ec563738.
   Spec 269 queues `FetchJntLabel` on `jnt-label` after every booking. Incepxion does not print in-app and has no
   `jnt-label` worker, so those jobs would pile up in `jobs`. New `jnt.labels.after_booking`
   (`JNT_LABEL_AFTER_BOOKING`, default false): off, booking queues no label job. A print run still uses stored labels
   and fetches missing ones itself (parallel, limited), so in-app printing keeps working. "All ready pages" on
   /jnt/orders (269) is booking-only and works as on Likha. Test:
   `test_incepxion_books_without_queueing_a_label_job_by_default` in `JntLabelsAheadTest`.
3. **J&T queues (267, kept from 7aa65445)** — `jnt.queues.print` and the job fallback stay `default`;
   `deploy/supervisor/likha-queue-jnt.conf` is not carried (Incepxion uses `incepxion-queue-jnt.conf`, booking only).

Checked and left as on Likha (nothing Incepxion lacks is required):
- 244, 252, 256, 257 (video views, ad link from the sync): only run inside the Meta Ads sync, which does nothing
  without a connection.
- 250 (CPP last updated): reads both sync and upload times.
- 262, 263, 271, 272 (Botcake customers, Broadcast, shipping-setup check, Botcake Accounts): Incepxion's chat pages
  are on Botcake, and `ChatblastSetupCheck` already exists on the Incepxion line.
- No merged code touches Forbeast orders.

## New migrations relative to d297c843

`git diff --name-only d297c843..HEAD -- database/migrations` (8 files; the 251 and 264 migrations are already on
Incepxion):

| Migration | Spec | What | Big table? |
|---|---|---|---|
| `2026_11_19_100000_add_broadcast_to_chatblast_campaigns.php` | 263 | JSON `broadcast` on `chatblast_campaigns` (small); index `botcake_messages_broadcast_index` (campaign_id, page_name, status, queued_at) on `botcake_messages` | **Yes: index on `botcake_messages`** (send log). Plain `Schema` index; on MySQL InnoDB it builds online, but it takes time on a big table |
| `2026_11_19_100000_add_storage_nav_link.php` | 243 | nav link `storage` | no |
| `2026_11_20_100000_add_video_views_to_ads_manager_reports.php` | 244 | 3 nullable unsigned int columns on `ads_manager_reports` | **Yes: ALTER on `ads_manager_reports`** (large). Nullable columns at the end: INSTANT on MySQL 8.0.12+ (no rebuild); a rebuild on older MySQL |
| `2026_11_21_100000_add_created_at_index_to_pancake_conversations.php` | 247 | index on `pancake_conversations.created_at` (`ALGORITHM=INPLACE, LOCK=NONE` on MySQL) | **Yes: `pancake_conversations`**. Online, but the build reads the whole table |
| `2026_11_23_100000_allow_several_creatives_per_campaign.php` | 254 | `ad_campaign_creatives`: plain `campaign_id` index added, unique dropped | no (about 3,200 rows on Likha) |
| `2026_11_24_100000_add_encoding_timetable_nav_link.php` | 265 | nav link `encoding_timetable` | no |
| `2026_11_25_100000_add_ads_daily_output_nav_link.php` | 266 | nav link `ads_daily_output` | no |
| `2026_11_26_100000_add_botcake_accounts_nav_link.php` | 272 | nav link `botcake_accounts` | no |

None touch `chatbot_replies`, `botcake_send_requests`, `botcake_chats` or `macro_output`.

## Queued jobs

Changed or new job classes since d297c843:
- `CreateJntOrder`, `BuildJntWaybillBulkPrint`: constructor and public properties unchanged (`shipmentId`, `runId`);
  queues unchanged on Incepxion. Old serialized jobs run as before.
- `ProcessAdsManagerReportsUpload`: new `private bool $fromSync = false`. An old serialized job unserializes with the
  default (false = an upload), which is right for every job queued before deploy. Low risk.
- `SyncBotcakeCustomersJob`, `SyncAllBotcakeCustomersJob` (262): `handle()` only; properties unchanged.
- New: `FetchJntLabel` (`jnt-label`, not queued on Incepxion by default), `QueueBroadcast` (263).

New scheduled commands: `chatblast:broadcast-feed` (every minute, nothing to do until a broadcast is sending),
`jnt:prune-labels` (04:30), `botcake:accounts-check` (05:10, nothing to do until an account token is saved).

## Before deploy (open points)

1. **Ads upload needs the video columns (246, 249).** Since 246 an Ads Manager upload without "3-second video
   plays", "ThruPlays" (or "15-second ThruPlays") and "Video plays at 50%" is refused. Incepxion's staff must add
   those columns to their Ads Manager export before this goes live, or every upload is refused. Kept as on Likha
   (the owner's rule; the columns exist in Ads Manager).
2. **Adding a page needs the shipping-status setup (271).** "+ Page" on Incepxion now refuses a page without the
   "UPDATE SHIPPING STATUS 2026" flow, the custom field and the approved template, and pages with ads or orders but no
   setup become orange in the problems panel. If Incepxion's Botcake pages do not use that flow, expect refusals and
   many orange rows.
3. **Botcake Accounts (272)** said "Likha only, Incepxion later". It arrives with this merge (nav link
   `botcake_accounts`); it does nothing until someone saves an account token.
4. **Nav links** for Storage (243), Encoding timetable (265), Ads daily output (266) and Botcake Accounts (272) are
   added by migration with Likha's role visibility; check the roles on Incepxion after deploy.
5. **Deploy order:** release the code, `php artisan migrate` (the three big-table migrations above run online on
   MySQL 8), `php artisan queue:restart`. No new supervisor program is needed on Incepxion.

## Tests

Full suite on ec563738 (local `.env` from `.env.example` with `CHATBOT_QUEUE_CONNECTION` and
`CHATBLAST_QUEUE_CONNECTION` commented out; sqlite in memory):

    Tests:    3 failed, 6 skipped, 5150 passed (81341 assertions)

The 3 failures are the known pre-existing ones: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest.
`tests/Feature/Jnt` + `tests/Feature/MetaAds`: 273 passed.
