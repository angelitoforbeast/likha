# Brief 147: the release test run is green and the duplicate tests test something again

## Why
- The release run (`tests/Feature/OrderEncode tests/Feature/NightRun tests/Feature/Chatblast tests/Feature/StaffActivity tests/Feature/PageSettings tests/Feature/Botcake`) always has 1 error: `Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` fails with "storage/app/credentials.json does not exist" on any machine without that untracked Google credentials file. A permanent red line hides new failures.
- Since spec 144 the duplicate hold (`order_encode_duplicate_hold`, `Settings::DUPLICATE_HOLD`) defaults off. `tests/Feature/OrderEncode/RulesTest.php::test_S_135_17_another_day_another_name_a_cannot_proceed_row_or_a_whitelisted_phone_is_not_a_duplicate` only asserts `proceed === true`, which is now always true, so it can no longer catch a wrong duplicate. Other negative R5 (duplicate) tests may have the same problem.
- The S-146 section of `qa/stories.md` is plain bullets; every other recent slice (S-144, S-145) is a table with P1/P2, Test and Status.

## Decisions (do not reopen)
| Decision | Why |
|---|---|
| ImportStartTest must not need the real credentials file: fake the Google client/credentials the way other NightRun or Sheet tests already do (find the existing pattern first; reuse it). No product code change unless the code reads the file at a point no test can intercept; then the smallest seam (e.g. a container binding) with a note in the result. | Green run on any machine. |
| Every R5-negative test (a case whose point is "this is NOT a duplicate") sets `AppSetting::set(Settings::DUPLICATE_HOLD, 'on')` first, so it fails if the duplicate check wrongly fires. Find them in tests/Feature/OrderEncode (grep for S-135.17, S-135.60, "duplicate", "R5", "whitelist"). Prove each one: temporarily break the duplicate check (e.g. make it treat every phone as a duplicate), run, see them fail, then revert. Record which tests failed under the break in the result. | Tests that can fail. |
| Rewrite only the S-146 section of qa/stories.md as a table like S-145 (ID, P, Given/When/Then short, Test, Status); keep every case's meaning, including the "(new) → Main Flow" first-write wording already recorded there. | Same format everywhere. |

## Done when
- The release run above: 0 errors, 0 failures (paste the summary line). 
- The break-check list for the R5-negative tests is in the result.
- Result ends with "147: <done|blocked> — <one line>".

## Out of scope
Product behaviour, other stories, other test folders, deploying.
