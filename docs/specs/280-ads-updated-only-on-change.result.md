# 280 Result: ads rows keep their update time when nothing changed

Branch `fix/280-ads-updated-only-on-change`, based on `16dade83`.
Commits: `186eafc0` (spec), `6ed2ef1e` (fix and tests), then this result.
Migrations: none.

## What changed (one file: `app/Jobs/ProcessAdsManagerReportsUpload.php`)
- **Stories 1 and 2.** In `upsertChunk` the existing per-day query (one per day per chunk, still `lockForUpdate`) now loads the whole stored row instead of `ad_id` and the monotonic columns. Just before the UPDATE, the new `changes($stored, $write)` compares every column the upsert writes against the stored row, leaving out `updated_at`. If nothing differs, the row is not touched, so `updated_at` stays as it was. If any value differs, the UPDATE runs as before with `updated_at = now`. Inserts are unchanged: `created_at = updated_at = now`.
  - Type handling: numeric columns (the monotonic ones plus `ad_set_budget`, `cost_per_result` and the video columns) are compared after rounding to 2 decimals, so a stored `"100.00"` matches an incoming `100.0`. Every other column is compared as a string. Null and `''` count as equal. A written column missing from the stored row counts as a change.
  - The same (day, ad_id) twice in one chunk: a separate `$currentByKey` copy is updated after each write. The second occurrence is therefore compared with what the first one wrote, and the last value still wins, as before. `$existingByKey`, which the protection uses, is unchanged.
  - Second cause, found by the tests: `toDateTime` parsed a date-only "Reporting starts/ends" text cell with `createFromFormat('Y-m-d')`, which fills in the current time of day. Every re-upload of the same file therefore wrote a different `reporting_starts`/`reporting_ends` (for example `2026-11-12 15:03:00`), which would have counted as a change. The date-only formats now use `!` (midnight). The only readers of these columns use `DATE(reporting_starts)`, so nothing that reads them changes. Excel date-serial cells were already midnight.
- **Story 3.** No CPP change. `freshnessByKey` still reads `MAX(updated_at)`, and that value now only moves when a number changes.
- **Story 4.** The protection code and its `$existingByKey` input are unchanged. A refused row is skipped before the new check, as before.
- **Counters:** the upload log's `updated` count works as before. A stored row that passes the protection counts as "updated" whether or not its values changed. `inserted` is unchanged.

## Tests: `tests/Feature/MetaAds/AdsUpdatedOnlyOnChangeTest.php` (10 tests)
| Story | Test |
|---|---|
| 1 | the same upload twice keeps updated_at of every row |
| 1 | a sync that returns the same numbers keeps updated_at |
| 1 | stored decimal strings and nulls match the incoming values |
| 2 | a changed value moves updated_at only on that row (spend / impressions / campaign name) |
| 2 | a sync with new spend moves updated_at |
| 2 | the same ad twice in one file ends with the last value as before (guard for the in-chunk copy; passes on base too) |
| 3 | the CPP hover time (`freshnessByKey`) is when the numbers last changed |
| 4 | a refused lower row keeps its values and updated_at and is recorded (fails on base only because the other, unchanged row gets bumped) |

Red, on base code (app file reverted, same tests):
```
 ✘ S1 the same upload twice keeps updated at of every row
 ✘ S1 a sync that returns the same numbers keeps updated at
 ✘ S1 stored decimal strings and nulls match the incoming values
 ✘ S2 a changed value moves updated at only on that row with spend
 ✘ S2 ... with impressions
 ✘ S2 ... with campaign name
 ✘ S2 a sync with new spend moves updated at
 ✔ S2 the same ad twice in one file ends with the last value as before
 ✘ S3 the cpp hover time is when the numbers last changed   ('2026-11-16 15:03' instead of '2026-11-12 18:45')
 ✘ S4 a refused lower row keeps its values and updated at and is recorded
Tests: 10, Assertions: 26, Failures: 9.
```
Green, on the branch:
```
OK (10 tests, 37 assertions)
```

## Full suite (sqlite)
- Base (`16dade83` app code, new test file set aside): Tests 5273, Failures 3, Skipped 6.
- Branch: Tests 5292, Failures 3, Skipped 6. The new file adds 10 tests; `--list-tests` lists 5283. I did not trace where the other 9 in the run total come from (most likely a data provider whose cases depend on files in the repo).
- The same 3 failures on both, no new ones:
  1. `ExampleTest::test_the_application_returns_a_successful_response`
  2. `Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
  3. `OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_the_shared_owner_pages_render_byte_for_byte_as_at_the_base`

  The spec expected 3 failures, all in SharedPagesBasePinTest. Here that file has 1 failure, plus the two above. The base and the branch fail the same tests.

### Test environment note
A single `vendor` junction to `..\likha-wt277l\vendor` makes Composer load `App\` and `Tests\` from likha-wt277l, so tests run that worktree's code. My first red run went through that junction and was thrown away. Every run reported above used a local `vendor/` instead: its own copy of `vendor/composer`, `autoload.php` and `bin`, plus one junction per package. I confirmed that `App\…\ProcessAdsManagerReportsUpload`, `Tests\…\MetaAdsTestCase`, the registered Composer loader and `basePath()` all resolve to likha-wt280. `vendor/` is git-ignored and not committed.

## Other readers of `ads_manager_reports.updated_at`
- `CPPController::freshnessByKey`: the CPP hover and the "Ads spend as of" line. This is the reader the change is for.
- `AdsDailyOutputController::adsOfDay` → `synced_at`, shown on `/ads_manager/daily_output` as **"Huling sync:"**. It now means "the last time day D's numbers changed", not "the last sync". For today the numbers usually change on every sync, but if a sync finds nothing new the label shows the earlier time. The label is not changed here; it may need rewording.
- Not affected: `AdsManagerCatalogController` (`MAX(updated_at)` reads `ad_catalog`); `FromAdsManagerController` (the `ads_managers` table).

## Questions
- Should "Huling sync:" on the daily output page be renamed (for example to "Huling pagbabago:"), or should it read the sync log time instead? Not done here.
