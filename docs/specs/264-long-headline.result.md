# 264 result: a long headline no longer fails the upload, and a failed upload shows why

Status: **done** on `fix/264-long-headline` (from origin/main a680c168) and on `release/264-incx` (from df0abc88, Incepxion).

## What changed

- `database/migrations/2026_11_12_110000_widen_ads_manager_reports_headline.php`: `ads_manager_reports.headline`
  varchar(255) -> varchar(1000), nullable, same charset/collation (read from information_schema), same position.
  MySQL: raw in-place ALTER; other drivers (sqlite in the tests): schema builder `->change()`. Down: back to 255.
- `app/Jobs/ProcessAdsManagerReportsUpload.php`:
  - `upsertChunk` cuts `headline` to 1000 characters (`mb_substr`) before saving, for the upload and the Meta sync
    (`importRows`) alike. ads_manager_reports, ad_campaign_creatives and ad_catalog all get the cut headline.
  - `upsertAdCatalogForChunk` cuts `ad_catalog.ad_name` (the headline as label) to 500 characters: that column is
    varchar(500) on live, so a 501-1000 character headline would otherwise still fail the upload there (1406). No
    schema change; only the label is cut.
  - the catch(\Throwable) in `handle()` now also writes `error_message`: `Import failed: <message>`, cut before
    ` (Connection:` (so no SQL and no ad text), whitespace folded to one line, capped to 65,535 bytes (the column is
    `text`, migration 2026_04_28_120001) with `mb_strcut` so it stays valid UTF-8. Counts are saved and the exception
    is rethrown as before. The "Missing required column(s)" path is untouched.
- Tests: `tests/Feature/MetaAds/AdsUploadLongHeadlineTest.php` (6), `tests/Feature/MetaAds/WidenHeadlineMigrationTest.php` (2).
  sqlite does not enforce varchar lengths, so the upload tests add triggers that raise "Data too long for column ..."
  like MySQL strict mode (headline 255 before / 1000 after the migration, ad_catalog.ad_name 500).

## SQL that runs on MySQL

Up (charset/collation are the column's current ones; on live utf8mb4 with its collation):

```sql
ALTER TABLE `ads_manager_reports` MODIFY `headline` VARCHAR(1000) CHARACTER SET <cs> COLLATE <co> NULL, ALGORITHM=INPLACE, LOCK=NONE
```

e.g. `... VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, ALGORITHM=INPLACE, LOCK=NONE`.
If MySQL could not do it in place it refuses with an error instead of copying the table.

Down: `ALTER TABLE `ads_manager_reports` MODIFY `headline` VARCHAR(255) CHARACTER SET <cs> COLLATE <co> NULL`
(a table copy; fails in strict mode if a headline is over 255).

## Stories -> tests

| Story | Test |
|---|---|
| S1.1 481-char headline with emoji imports exactly | `test_S1_1_a_481_character_headline_with_emoji_imports_exactly` |
| S1.2 >1000 chars stored cut to 1000, done | `test_S1_2_a_headline_over_1000_characters_is_stored_cut_to_1000_and_the_upload_is_done` |
| S1.3 short headlines: same inserted/updated/skipped | `test_S1_3_short_headlines_import_and_update_exactly_as_before` |
| S2.1 failed + one short line, no SQL/ad text, capped | `test_S2_1_a_failed_upload_stores_why_in_one_short_line_without_the_sql_or_the_ad_text`, `test_S2_1_the_message_is_capped_to_the_text_column_and_stays_valid_utf8` |
| S2.2 counts saved, exception rethrown | `test_S2_2_the_counts_are_saved_and_the_exception_is_rethrown` (2000 rows saved, the 2nd chunk fails) |
| S2.3 missing-column message unchanged | existing `AdsUploadVideoColumnsTest`, `AdsReportPageVideoColumnsTest` (main) pass unchanged |
| migration | `WidenHeadlineMigrationTest` (up keeps rows and nullable, takes 1000 chars; down runs) |

Against the old importer, S1.2, S2.1 (both) and S2.2 fail; with the change all pass.

## Test runs (PHP 8.4, sqlite :memory: per phpunit.xml)

- `php vendor/bin/phpunit tests/Feature/MetaAds/AdsUploadLongHeadlineTest.php tests/Feature/MetaAds/WidenHeadlineMigrationTest.php`:
  main OK (8 tests, 43 assertions); incx OK (8 tests, 43 assertions).
- `php vendor/bin/phpunit --filter 'MetaAds|AdsManager|Upload'`: main 274 tests, all pass; incx 171 tests, all pass.
- Full suite on main (`php vendor/bin/phpunit`): baseline a680c168 = 4999 tests, 3 failures, 6 skipped; with this
  change = 5007 tests (+8 new), the same 3 failures, 6 skipped. No new failures. The 3 pre-existing failures:
  - `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (302, not 200)
  - `Tests\Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
  - `Tests\Feature\OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_..._at_the_base` with data set "/owner/private"
- The worktrees have no `.env`, so tests log a `file_get_contents(.env)` warning; it is not a failure.
- composer install needed `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` on Windows (horizon).

## Incepxion (`release/264-incx`)

Cherry-picked from the main commit; one conflict (main has `recordDataHealth()` next to the new method, incx does
not), resolved by keeping only `failureMessage()`. Same migration file name. The incx test schema has no video
columns; the extra columns in the test file are ignored by the importer.
