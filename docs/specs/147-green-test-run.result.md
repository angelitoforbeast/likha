# Result 147: green test run

## Plan
1. ImportStartTest: fake Google credentials using an existing test pattern.
2. R5-negative tests: turn the duplicate hold on, prove with a temporary break.
3. qa/stories.md: S-146 as a table.

## Progress
1. Done. `test_macro_job_final_write_applies_only_while_the_run_is_still_active` now gives the job its own storage folder with a fake service-account `credentials.json`. This reuses the NightRun pattern from `AstraDryRunCommandTest` (`useStoragePath` on a temp folder, deleted afterwards). No product code changed: `Google_Client::setAuthConfig` only reads the file, and the test reaches no Google fetch (the healthy run has no items, and the closed run's item has no setting). The real `storage/app` is never touched, so a machine that has the real file keeps it.
2. Done. R5-negative tests found in `tests/Feature/OrderEncode`:
   - Changed (they asserted "not a duplicate" with the hold off, so they could not fail): RulesTest `S-135.17`, `S-135.60`, `S-135.19`; OneRowPerDayTest `S-136.53`. Each now sets `AppSetting::set(Settings::DUPLICATE_HOLD, 'on')` first.
   - Already had the hold on, not changed: OneRowPerDayTest `S-136.8`, EncodeMacroTest `S-137.17 r5_holds_only_for_another_customers_order` ("the same customer").
   - Not R5-negative, not changed: `S-144.1` (hold off on purpose), `S-144.3` "the hold is off (default)" (tests the switch itself), `S-144.4` (the setting page).
3. Done. Only the S-146 section of `qa/stories.md` changed: it is now a table (Case with P1/P2, Given / When / Then, Test, Status) like S-145, with every case's wording kept, including "(new) → Main Flow" for the first write in S-146.1. The P1/P2 split comes from spec 146 (.1–.6 P1, .7 P2).

## Files
- `tests/Feature/NightRun/ImportStartTest.php`: `fakeGoogleCredentials()` helper, used in the macro final-write test.
- `tests/Feature/OrderEncode/RulesTest.php`, `tests/Feature/OrderEncode/OneRowPerDayTest.php`: duplicate hold on in the four R5-negative tests.
- `qa/stories.md`: S-146 section as a table.
- `docs/specs/147-green-test-run.md`, `docs/specs/147-green-test-run.result.md`.

## Evidence
Red before step 1:
`InvalidArgumentException: file "...storage\app/credentials.json" does not exist` (ImportMacroFromGoogleSheet.php:70). After: `ImportStartTest` `OK (13 tests, 100 assertions)`.

Break check (temporary edits, reverted with `git checkout app/`; the tree shows no change under `app/`):
- Break A: `Duplicates::find` returns `["999"]` (every order is a duplicate).
- Break B: Break A plus `Runner` runs `duplicates()` in sheet mode too (`if ($rules)` → `if (true)`).

| Test | Old test under Break B | New test under Break A | New test under Break B |
|---|---|---|---|
| RulesTest S-135.17 (another day / name / CANNOT PROCEED / whitelist) | passed (no teeth) | **failed** | **failed** |
| RulesTest S-135.60 (San Isidro, improved mode proceeds) | passed (no teeth) | **failed** | **failed** |
| RulesTest S-135.19 (sheet mode does not check) | passed (no teeth) | passed (sheet mode never calls `find`: correct) | **failed** |
| OneRowPerDayTest S-136.53 (own row from yesterday) | passed (no teeth) | **failed** | **failed** |
| OneRowPerDayTest S-136.8 (own row, hold already on) | failed | **failed** | **failed** |
| EncodeMacroTest S-137.17 "the same customer" (hold already on) | failed | **failed** | **failed** |

Break B, new tests: `Tests: 7, Assertions: 12, Failures: 6` (the 7th is S-137.17 "another customer", a positive duplicate case, which still holds as expected).
After the revert: RulesTest `OK (28 tests, 79 assertions)`; OneRowPerDayTest `Tests: 25, Assertions: 67` OK.

Release run (`tests/Feature/OrderEncode tests/Feature/NightRun tests/Feature/Chatblast tests/Feature/StaffActivity tests/Feature/PageSettings tests/Feature/Botcake`), on this branch:
```
OK, but there were issues!
Tests: 1831, Assertions: 14281, PHPUnit Deprecations: 74, Skipped: 1.
```
0 errors, 0 failures. The "issues" are only PHPUnit deprecations (doc-comment `@dataProvider`) and 1 skip that were there before.

## Commits
- `8a29ec06` docs: spec 147 green test run
- `9ecb6404` test: macro final-write test uses a fake credentials file in its own storage (spec 147)
- `e8a020d9` test: R5-negative tests turn the duplicate hold on so a wrong duplicate fails them (spec 147)
- `482b1e8b` docs: S-146 stories as a table like the other slices (spec 147)
- this result commit

## Open points
- Running a regex `--filter` with `|` through `php.bat` breaks in cmd (the pipe is parsed by cmd). Call `php84\php.exe` directly, and in Git Bash set `MSYS_NO_PATHCONV=1` so a filter starting with `/` is not turned into a path.
- Not pushed; not merged; nothing deployed.

147: done — release run green (1831 tests, 0 errors, 0 failures); the 4 toothless R5-negative tests now run with the hold on and fail under a broken duplicate check; S-146 is a table.
