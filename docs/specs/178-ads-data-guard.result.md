# Result 178: Ads Manager data is complete every day, and bad data is refused and reported

Branch `feat/178-ads-data-guard`, based on fe54c227 (live). Not pushed, not deployed.

## What shipped
1. **Lower values are refused for the whole row** (`ProcessAdsManagerReportsUpload::upsertChunk`, used by both the upload and the Sync)
   - What the protection did before: it worked per column. A lower value, or a 0, was dropped for that column only, and the rest of the row was still written. So a row could hold the old impressions next to the new spend and names.
   - Now: for an existing (day, ad_id), if any of spend, impressions, reach, results, link_clicks, messaging_conversations_started or purchases arrives lower than the stored value, **the whole row is left as it is**. No column is written. The row is not counted as updated.
   - The existing 🛡 Protected count per column is kept.
   - A **0** is a number. A 0 against a stored value above 0 is lower, so the row is refused. A 0 where nothing is stored yet is not written (as before).
   - A **blank or missing** cell (null) is "no data". That column is not written and the row is not refused (as before).
   - Equal or higher values update the row as before, whatever its age.
   - Each refusal is recorded as one `upload_logs` row of type `ads_manager_refused` per upload or Sync run (no new table):
     - `path` is `log:<source log id>`;
     - `user_id` is the uploader, or whoever ran the Sync;
     - `error_rows` is the number of rows refused;
     - `protected_details` holds the source (`upload`/`sync`), the log id, the count, and up to 200 rows. Each row has its day, ad_id, account and, per metric, the stored and incoming numbers.
   - The account comes from the row's Account ID. When the row has none, it comes from `ad_campaign_creatives.account_id`.
   - Where it shows on /ads_manager/report:
     - in the upload log table (🛡 column): "⛔ N rows refused (lower values)", which opens to e.g. `2026-10-20 ad a1 · 111: impressions 1,500 → 1,400`;
     - on each Sync run in "API runs and changes", the same.
   - The existing-row read is now `lockForUpdate` (ordered by ad_id) until the chunk commits. An upload and a Sync (separate processes) can no longer both pass the check on the same row and let a lower value through.
   - The refusal details in the upload log table are shown only to the roles that see the Ads page. The other roles keep the old count columns.
   - Not changed: the chunk-level `ad_campaign_creatives` and `ad_catalog` upserts still run for a refused row. They hold names and dates, not the refused numbers.
2. **Data health box** (`DataHealth::evaluate`, `_data_health.blade.php`). It sits on /ads_manager/report above the Meta section, for the roles that see the Ads page (`MetaAdsController::allowed()`, the same check as the Meta section).
   - **Accounts come from three sources:**
     - the Meta connections;
     - the `ad_accounts` rows (/ads_manager/ad_account);
     - the uploads of the last 60 days: the account of the ad's `ad_campaign_creatives` row, else the account of its campaign's row. `campaign_id` is unique there, so only one ad per campaign has its own row. An ad with no known account is grouped as "Account unknown".
   - **Per account:** the name (176 rules; an account outside the connections takes its active ad_accounts name, else the ID), the source, the latest day with data (within 60 days), and yesterday's rows and spend.
   - **Source** is one of: API selected / API unticked / upload only / none.
   - **Status**, first match wins:
     1. **Missing yesterday**: spend in any of the 3 days before, and no row yesterday.
     2. **New**: first seen in the last 3 days, today included, from any source.
     3. **OK**: rows yesterday.
     4. **Not synced (unticked)**: in a connection but unticked.
     5. **Not readable by API**: in ad_accounts or the uploads, in no connection, and no data yesterday.
     6. **No recent spend**: everything else, e.g. a connected account with no spend at all.
   - **"First seen"** is the earliest of: the account's first upload row (within 60 days), its first ad_accounts row, and the first time a connection showed it.
     - The last is kept in app_settings `meta_ads.first_seen`.
     - On the very first check, the connection's `connected_at` seeds it, so the accounts that are there already do not all show as New on deploy day.
   - **Refused totals:** the box also shows "Refused (lower values): today N rows · yesterday M rows", by the day the upload or run came in (Manila).
   - **New ad accounts in the sync** stay ticked by default, as before (173-1), and show as New for 3 days. Unticked ones show as "Not synced (unticked)".
3. **Daily check and notice**
   - `php artisan ads:data-health --record` is scheduled daily at 12:00 Manila (`services.meta_ads.data_health_at`, env `META_ADS_DATA_HEALTH_AT`, blank = 12:00). It evaluates yesterday and stores the result as JSON in app_settings `meta_ads.data_health`.
   - When the stored check has any account that is Missing yesterday, New or Not readable, a red notice shows at the top of /ads_manager/report:
     - "⚠ N ad accounts need attention (check for <day>, <time>)";
     - the accounts and their status;
     - a link to the box.
   - The notice shows only for the roles that see the Ads page, and only on that page.
   - `php artisan ads:data-health --json` prints the last stored check, or the current one when none is stored. It writes nothing.
   - Without options, the command prints the current list. It writes nothing.

## Case table
| Case | Test | Result |
|---|---|---|
| S-178.1 | `AdsDataHealthTest::test_S_178_1_the_coverage_list_gives_each_account_its_source_and_status`, `…an_uploaded_ad_without_its_own_creatives_row_takes_its_campaigns_account` | pass |
| S-178.2 | `test_S_178_2_the_noon_check_records_the_result_shows_the_red_notice_and_prints_json`, `test_S_178_2_no_red_notice_when_every_account_is_ok` | pass |
| S-178.3 | `AdsDataRefusalTest::test_S_178_3_an_upload_with_lower_impressions_keeps_the_whole_row_and_records_the_refusal`, `…an_upload_with_equal_or_higher_values_updates_the_row` (equal, higher), `…a_zero_against_a_stored_value_refuses_the_row`, `…a_blank_metric_cell_keeps_that_column_and_updates_the_rest_without_a_refusal`, `…a_sync_row_with_lower_impressions_keeps_the_whole_row_and_the_run_shows_one_row_refused`, `AdsDataHealthTest::test_S_178_3_the_box_shows_the_daily_total_of_refused_rows` | pass |
| S-178.4 | `test_S_178_4_a_role_without_the_ads_page_does_not_see_the_box_or_the_notice` | pass |
| S-178.5 | `tests/Feature/MetaAds` (incl. `AdsManagerUploadCharacterizationTest`), `tests/Feature/AdAccount`, `tests/Feature/Chatblast` | pass, no new failures (see Suites) |
| S-178.6 (P2) | Checked by reading the markup only: the notice and the list stack per account (`flex-wrap`, `min-w-0`, `basis-48`, `break-all`, badges `shrink-0`). | not run in a browser |

Red runs:
- Stage 1: `S_178_3…lower_impressions…` failed with `Failed asserting that two arrays are identical` (spend 120 / "New camp" written next to the kept impressions: the half-updated row). The sync case failed the same way (spend 130, "Campaign a1"). The equal/higher cases first failed because the fixture lacked required columns, a test error. After the fixture was fixed they passed on the old code, as expected for unchanged behaviour.
- Stage 2: the coverage test failed with `Failed asserting that two arrays are identical` against a stub returning no accounts. The command tests failed with `The command "ads:data-health" does not exist`.

## Suites (done-when)
`php vendor\phpunit\phpunit\phpunit tests/Feature/MetaAds tests/Feature/AdAccount tests/Feature/Chatblast` (the Ads Manager upload test is `tests/Feature/MetaAds/AdsManagerUploadCharacterizationTest.php`):
- At fe54c227 (baseline): `Tests: 1391, Assertions: 46005, Skipped: 1`, no failures.
- After the change (HEAD, with the review fixes): `Tests: 1403, Assertions: 46055, Skipped: 1`, no failures. The same 48 PHPUnit deprecations as the baseline.
- No new failures.

## Changes to existing tests (needed by the decisions)
- `AdsManagerUploadCharacterizationTest`: the second upload (lower spend) now counts `updated` 0 instead of 1. Decision 3 makes the refused row untouched, so it is no longer an update. The 🛡 count `['amount_spent_php' => 1]` and the kept spend are unchanged.

## Story changes
- I added S-178 to `qa/stories.md`, with the brief's six cases. S-178.3 also covers the box's daily refused total (decision 3 asks for it; the brief's case list did not name a test for it). S-178.2 also covers "no notice when everything is OK".

## Judgment calls (for the reviewer)
- **A 0 now counts as lower.** Before, a 0 against a stored number was ignored for that column. Under "any incoming value lower than the stored one" it now refuses the row. Blank cells are still "no data". If the export ever writes 0 instead of blank for a metric it lacks, those rows will show as refused rather than being half-updated.
- **Small API vs export differences will show up.** Compare found the API and the export within ₱66 over ₱728k. Once write mode is on, an upload a few centavos below a synced value, or the other way round, is refused and listed. That is the owner's rule, but expect some early refusals.
- **"Not readable by API" includes every active ad_accounts row in no connection.** An old account that is still active on /ads_manager/ad_account keeps the red notice on until someone connects it or deletes the row there.
- **The notice reads the stored 12:00 check; the box is live.** After a late upload the box shows OK before the notice clears at the next check.
- **The page's live check writes `meta_ads.first_seen`** when a new connection account first appears, so "first seen" is the first time anyone looked, not only the 12:00 run. `--json` and the plain command never write.
- **An unticked account that has upload data yesterday shows OK**, with source "API unticked". "Not synced (unticked)" is used when it has no data.
- **The upload window is 60 days.** "Latest day" and the upload part of "first seen" read the last 60 days of ads_manager_reports, to keep the page fast. An account silent for over 60 days shows "latest —", and if it comes back it shows as New.

## Deploy notes
- **No migration.** No new table: app_settings keys `meta_ads.data_health` and `meta_ads.first_seen`, and upload_logs type `ads_manager_refused`.
- `php artisan view:clear`, `php artisan route:clear`, and `php artisan config:clear` if the config is cached (new key `services.meta_ads.data_health_at`).
- **Schedule:** the scheduler picks up `ads:data-health --record` at 12:00 Manila by itself. Optional `.env` setting `META_ADS_DATA_HEALTH_AT=12:00` (added to `.env.example`).
- **After deploy:** run `php artisan ads:data-health --record` once, so the notice has a first check, then `php artisan ads:data-health` to read the list.
- **Incepxion:** the same commits apply to Incepxion (same code paths, no data-specific values).

## Accepted findings
- **Review:** `skeptic-reviewer`, standard depth, on fe54c227..77783cbb. Three majors, fixed in one loop (f95552e1), each test-first where it changes behaviour:
  1. **Uploaded ads were mapped to an account through their own creatives row only.** In production `ad_campaign_creatives.campaign_id` is unique, so the other ads of a campaign fell under "Account unknown". Now the campaign's row is used too. New test; red before the fix.
  2. **Race between an upload and a Sync on the same row.** Added `lockForUpdate`. This is not testable on sqlite.
  3. **No tests for the 0 vs blank rule.** Two tests added. They passed on the code as written and pin it.
- **One minor fixed:** the refusal details are gated by Ads access, and S-178.4 now asserts `assertOk` and that the details are hidden (red before the fix). The importRows docblock is also fixed.
- **The other minors are accepted with reasons in `TODO.md`** (178 section): event-count totals, refusals lost when a Sync throws mid-account, the creatives-only fallback in `recordRefusals`, an unticked account with data showing OK, the extra plain command, the act_ strip copies, untested branches, insert races on new rows, and the 390 px check by markup only.
- **Scoped re-check of the fix commit:** requested; its verdict had not arrived when this was written.

178: done — accounts' daily coverage with a 12:00 check, red notice and `ads:data-health --json`; lower values refuse the whole row and are listed per upload/run.
