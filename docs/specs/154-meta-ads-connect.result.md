# Result 154: staff connect their Facebook once; Likha pulls the Ads Manager report from the Meta Marketing API

## Plan
`docs/plans/154-meta-ads-connect.md` (5 tasks: Graph client + OAuth + connections, insights mapper, compare, sync + write mode + schedule, page).

## Progress
- [x] Task 1: Graph client, OAuth connect/callback/disconnect, connections store
- [x] Task 2: insights mapper (API → `ads_manager_reports` columns)
- [x] Task 3: Compare with uploads (button + `meta-ads:compare`)
- [x] Task 4: Sync now, `meta-ads:sync`, schedule, write mode (CEO)
- [x] Task 5: the "Facebook (Meta Ads API)" card on /ads_manager/report
- [x] Review: skeptic-reviewer, adversarial depth, on `66ec2a4e..HEAD`. 1 major fixed (token sent to whatever host a `paging.next` named → now only graph.facebook.com, with a test); 3 minors fixed (error-path leak test, compare 500 on odd JSON, job timeout under the queue's retry_after, config comment placement); the rest accepted in `TODO.md` with reasons.
- Browser check (390 px, S-154.11 P2): not run. No browser tools were used in this session. The card uses `flex-wrap` and `overflow-auto` tables like the rest of the page, but nobody has looked at it at 390 px yet.

## Files
- `config/services.php` (`meta_ads`: app_id, app_secret, graph_version default v25.0), `.env.example` (`META_ADS_APP_ID`, `META_ADS_APP_SECRET`, `META_ADS_GRAPH_VERSION`, the redirect URL to register)
- `app/Services/MetaAds/Graph.php`: GET with the token in the Authorization header, paging (`paging.next` followed only on https://graph.facebook.com, with `access_token` stripped from it), backoff on 4/17/613/80000/80003/80004 (2 s, 8 s, 32 s, 4 tries), `clean()` strips tokens, secrets and codes from every error message
- `app/Services/MetaAds/GraphException.php`: `tokenExpired()` (190), `rateLimited()`
- `app/Services/MetaAds/Connections.php`: storage, the OAuth dialog URL, the code → short → long-lived token exchange, `debug_token` expiry, `/me`, `/me/adaccounts`, Reconnect rule, the event log
- `app/Services/MetaAds/Insights.php`: insights + ad/adset/campaign/creative + page names → report rows (the mapping below)
- `app/Services/MetaAds/Compare.php`, `app/Services/MetaAds/Sync.php`
- `app/Http/Controllers/MetaAdsController.php`: connect, callback, disconnect, compare, sync, writeMode, `panel()`
- `app/Jobs/SyncMetaAdsReports.php` (Sync now runs on the queue)
- `app/Console/Commands/MetaAdsCompare.php` (`meta-ads:compare --from --to`), `app/Console/Commands/MetaAdsSync.php` (`meta-ads:sync [--scheduled] --from --to`)
- `routes/web.php`: 6 routes under `/ads_manager/meta/*`, next to the report routes (same `web, auth, allowed_ip` group)
- `routes/console.php`: `meta-ads:sync --scheduled` every minute; it runs only with write mode on and only at a schedule time
- `resources/views/ads_manager/_meta_ads.blade.php` (new card), `resources/views/ads_manager/report.blade.php` (one `@include`)
- Changes to legacy files:
  - `app/Http/Controllers/AdsManagerReportController.php`: `index()` passes `meta`.
  - `app/Jobs/ProcessAdsManagerReportsUpload.php`: new `importRows()` (public, the same `upsertChunk`), plus `starts` / `ends` via `putIfExists`. The upload never sets those keys, so uploads behave as before.
- Tests: `tests/Feature/MetaAds/` (MetaAdsTestCase, MetaAdsConnectTest, MetaAdsCompareTest, MetaAdsSyncTest, AdsManagerUploadCharacterizationTest)
- `qa/stories.md` (S-154), `TODO.md` (accepted minors), `docs/specs/154-*`, `docs/plans/154-*`

## Storage design (and why)
No new table and no migration.
- **Connections:** one `app_settings` row per Likha user, key `meta_ads.connection.<user id>`. The value is JSON: `token` (`Crypt::encryptString`), `fb_user_id`, `fb_user_name`, `expires_at` (unix), `ad_accounts` [{id, name, status}], `status` (ok | expired), `connected_at`, `last_sync_at`, `last_error`. Disconnect deletes the row, so no copy of the token is left anywhere.
- **Write mode and schedule:** `app_settings` keys `meta_ads.write_mode` (on/off, missing = off) and `meta_ads.schedule` (default `10:00,15:00,18:45`).
- **Runs:** `upload_logs`, type `ads_manager_api`, with inserted/updated/skipped/protected, the user, start/finish, and `error_message` for a partial run. Events (connect, disconnect, compare, write mode) go in `upload_logs` too, type `ads_manager_api_event`, with the event in `original_name` plus the user and time. The existing upload list filters on `type = ads_manager_reports`, so neither type shows up there.
- **Why not `page_settings`** (the precedent checked first): the /page-settings grid lists every provider except `analyst`. A token row would show there as a "page", with the last 4 characters of the token, and anyone with page-settings access could edit or clear it. Keeping it out of the grid means changing the grid queries and the deactivate/history routes. `app_settings` already holds global key/value rows, has no listing UI, and fits one row per person.
- **`ad_accounts`** is not written to: each person's account list comes from `/me/adaccounts`, stored on their connection row.

## Field mapping (API → `ads_manager_reports`, as the upload fills it)
| Column | From | Pilot |
|---|---|---|
| day | insights `date_start` | |
| ad_id, campaign_id, campaign_name | insights `ad_id`, `campaign_id`, `campaign_name` | |
| ad_set_id, ad_set_name | insights `adset_id`, `adset_name` | |
| amount_spent_php | `spend` (account currency assumed PHP) | check the currency of each account |
| reach, impressions | `reach`, `impressions` | |
| link_clicks | `inline_link_clicks` | |
| messaging_conversations_started | `actions[onsite_conversion.messaging_conversation_started_7d]` | |
| purchases | first of `actions` `omni_purchase`, `purchase`, `offsite_conversion.fb_pixel_purchase`, `onsite_web_purchase` | **verify the key** |
| results | sum of `results[].values[].value` | **verify the shape** |
| cost_per_result | `cost_per_result[0].values[].value`, else spend / results | verify |
| result_type | ad set `optimization_goal` (CONVERSATIONS → "Messaging conversations started", OFFSITE_CONVERSIONS → "Website purchases", …) | compare with the export text |
| attribution_setting | `7d_click_1d_view` → "7-day click or 1-day view" (and 3 others) | |
| campaign_delivery, ad_set_delivery | `effective_status` ACTIVE → `active`, PAUSED / CAMPAIGN_PAUSED / ADSET_PAUSED → `inactive`, else lowercase | compare with the export values |
| ad_set_budget, ad_set_budget_type | `daily_budget` / 100, "Daily"; `lifetime_budget` / 100, "Lifetime"; neither → null, "Using campaign budget" | |
| starts, ends | ad set `start_time`, `end_time` in Manila time (the upload never filled these) | |
| reporting_starts, reporting_ends | `date_start` / `date_stop` at 00:00:00 | |
| page_name | creative `object_story_spec.page_id` → page `name`; if it can't be read, the page_name of an earlier row for the same ad; if there is none, the row is skipped (the column is NOT NULL) | **verify against the export's "Page name"** |
| headline, body_ad_settings | creative `title` / `body`, else `object_story_spec.link_data|video_data` name/title and message | **verify readable** |
| welcome_message, quick_reply_1..3 | `page_welcome_message` JSON: `text_format.message.text`; ice breakers, then quick replies, by `title` | **verify the shape and Limited-tier access** |
| account_id | insights `account_id` → `ad_campaign_creatives.account_id` (the upload also leaves it off `ads_manager_reports`) | |

Rows go through the upload's own upsert (`importRows` → `upsertChunk`). That means the same monotonic protection (spend, reach, … never go down), the same `ad_campaign_creatives` / `ad_catalog` updates, and the same `/owner/private` cache bump.

## What the pilot must verify live
1. Whether a Tester's token reads ad accounts in **other Business Managers** (`/me/adaccounts` lists all 13).
2. The `results` / `cost_per_result` shape and whether Results matches the export.
3. The purchases action key.
4. The creative fields (title, body, `page_welcome_message`) at the Limited tier, and whether partner pages need a Page token.
5. That page names match the export's "Page name".
6. The delivery text and Result type wording against the export.
7. Rate limits at the Limited tier with 13 accounts, three times a day; whether Compare finishes inside the web timeout.
8. The default range: today plus the 6 days before it, in Manila time. This was chosen because staff upload during the day. Confirm it matches the export's date setting.

## Story changes
None. The S-154 cases went into `qa/stories.md` as briefed, with the Then of each case written out in full.

## Evidence per case
The run was `php vendor\phpunit\phpunit\phpunit tests/Feature/MetaAds`: **OK (26 tests, 146 assertions)**.

| Case | Test | Result |
|---|---|---|
| S-154.1 | MetaAdsConnectTest::test_S_154_1_connect_redirects_to_the_oauth_dialog_with_ads_read_and_a_state; …callback_exchanges_for_a_long_lived_token_and_stores_it_encrypted_with_expiry_and_accounts; …callback_with_a_bad_or_missing_state_is_refused_and_stores_nothing (missing, wrong) | pass |
| S-154.2 | MetaAdsConnectTest::test_S_154_2_without_an_app_id_the_card_and_connect_say_meta_app_not_set_up_yet | pass |
| S-154.3 | MetaAdsCompareTest::test_S_154_3_compare_reports_only_in_api_only_in_upload_and_differences_per_account_and_writes_nothing; …the_compare_command_prints_the_same_report_and_writes_nothing | pass |
| S-154.4 | MetaAdsSyncTest::test_S_154_4_each_column_is_filled_from_the_api_as_the_upload_would | pass |
| S-154.5 | MetaAdsSyncTest::test_S_154_5_sync_now_with_write_mode_on_inserts_new_and_updates_existing_by_day_and_ad_id_and_logs_the_run; …with_write_mode_off_is_refused_and_writes_nothing | pass |
| S-154.6 | MetaAdsSyncTest::test_S_154_6_error_190_marks_that_token_expired_shows_reconnect_and_the_others_still_sync; …rate_limits_back_off_then_log_a_partial_run_without_crashing (4, 17, 613) | pass |
| S-154.7 | MetaAdsSyncTest::test_S_154_7_the_schedule_runs_only_when_write_mode_is_on_and_at_a_slot (off at slot, on at slot, on off-slot); …the_scheduler_has_the_meta_ads_sync_every_minute | pass |
| S-154.8 | MetaAdsConnectTest::test_S_154_8_the_token_never_appears_in_logs_responses_or_the_page; …a_paging_next_link_to_another_host_is_not_followed_with_the_token; …a_failed_exchange_shows_and_logs_no_secret_or_code | pass |
| S-154.9 | MetaAdsConnectTest::test_S_154_9_a_role_without_the_ads_link_gets_404_on_connect_disconnect_compare_and_sync; MetaAdsSyncTest::test_S_154_9_only_the_ceo_turns_write_mode_on_or_off_and_it_is_logged; …an_uploader_role_can_compare_and_sync | pass |
| S-154.10 | MetaAdsConnectTest::test_S_154_10_disconnect_removes_the_token_row_and_logs_it | pass |
| S-154.11 (P2) | none: 390 px was not checked in a browser | not run |
| (legacy) | AdsManagerUploadCharacterizationTest::test_the_upload_inserts_then_keeps_the_higher_spend_and_leaves_starts_empty | pass |

Red runs:
- Connect slice: the first run had 8 tests, with 6 failures and 1 error (routes missing).
- Paging-host fix: "Attempted request to [https://attacker.example/steal?after=p2] without a matching fake".
- **TDD deviation:** the Insights, Compare and Sync services were written in one pass with the connect slice, before their tests. Their tests were written next, and their first runs failed only on test-side bugs (a key type, a decimal trim, a Laravel output-matching quirk), not on missing behaviour.

**Feature suite:** the baseline at 66ec2a4e was 3016 tests with 2 failures (`ExampleTest::test_the_application_returns_a_successful_response`, `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`). After: see the note at the end of Open points.

## Commits
- `81b956c6` docs: spec, plan, stories and result skeleton for Meta Ads connect (spec 154)
- `62d8e445` feat: staff connect their Facebook on /ads_manager/report; token stored encrypted per Likha user (spec 154)
- `5b13147c` feat: Compare with uploads and meta-ads:compare report API vs upload rows per ad account, writing nothing (spec 154)
- `72937d29` feat: Sync now and the schedule write the Meta API report through the upload's upsert when write mode is on; 190 and rate limits handled (spec 154)
- `d15cb50d` fix: the token is sent only to graph.facebook.com when following paging links; compare shows a message on an odd answer; job timeout under retry_after (spec 154)
- plus the docs commit with this result and `TODO.md`

## Open points
- **Who can connect:** the "Ads" link `/ads_manager/report` in /owner/nav-settings decides it (seeded for Marketing, Marketing - OIC and CEO); other roles get 404. The upload itself still has no role check, as before. So "the same roles that can upload" is read as the roles that see the Ads link.
- **Connect on /profile:** not added (see `TODO.md`).
- **Before the pilot:** set `META_ADS_APP_ID` / `META_ADS_APP_SECRET` in production `.env`, and register `<APP_URL>/ads_manager/meta/callback` as a valid OAuth redirect in the Meta app.
- **Write mode** stays off until the CEO turns it on, after Compare shows the numbers match.
- **Pre-existing bug, not touched:** CSV uploads crash on OpenSpout v4 (`setFieldDelimiter`). XLSX uploads work.
- Accepted minors: `TODO.md` § "154".
