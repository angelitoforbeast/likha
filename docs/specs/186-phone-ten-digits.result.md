# Result 186: encoded orders store the phone as 9XXXXXXXXX

Status: done. Branch `fix/186-phone-ten-digits`, based on 36013979 (live). Not pushed, not deployed.

## Writers changed

| Writer | Before | Now |
|---|---|---|
| `OrderPhone::website()` (new) | none | Any valid PH mobile form (09…, 9…, +639…, 639…, with spaces, dashes or dots) becomes the 10 digits starting with 9. Anything else becomes `''`. It is built on `BotcakeCustomerMatcher::normPhone`; `forOrder` and `normPhone` are unchanged. |
| `LiveOrder::encodeRow` (Encode order step) | `forOrder(...)`, 09… | `website(forOrder(...))`. The same number in the other form (09… and 9…) counts as no change: no write and no HISTORICAL LOGS line. Staff protection (edited_* flags, staff log lines) is checked as before. A phone that is invalid or missing still writes nothing over a filled value. |
| `LiveOrder::insertRaw` (Upload to macro) | `normPhone`, 09…, else the raw text | `website`, 9…, else the raw text (unchanged) |
| `LiveOrder::create` / `values()['_phone']` (live encode row) | 09… from the Likha-import rule, else the raw encode phone | 9… from the same rule. A valid raw encode phone is also converted; an invalid one is stored raw as before. |
| `ImportLikhaFromGoogleSheet` (both the update and create paths) | 09… (`forOrder` or the `/09\d{9}/` match on D) | `website(...)`, or null as before. The identity used for matching (`$namePhone` passed to `OrderIdentity::find`) is unchanged, and `find` normalizes both forms. This is the likely source of the 1–8 rows a day in the 09… form before Oct 28. |
| `MacroChecker::duplicatePhoneRows` (Validate's same-day duplicate) | exact `PHONE NUMBER = $p` | `whereIn('PHONE NUMBER', [$p, '0'.$p])` |

Not changed:
- `ImportMacroFromGoogleSheet` writes the sheet value, which is already 10 digits.
- `AstraAddressRules` already writes 9….
- Staff edits are untouched.
- J&T upload and the J&T checker are not touched: both still normalize to 09… themselves (S-186.5).
- Flows are not touched.

## Command

```
php artisan orders:phone-ten-digits              # dry run (same as --dry-run): nothing changes
php artisan orders:phone-ten-digits --apply      # converts
  options: --dry-run (wins over --apply), --chunk=500 (rows per batch)
```

**What it selects.** Every row of `macro_output`, on any date, whose `PHONE NUMBER` matches `^09[0-9]{9}$` exactly:
- SQL prefilter: `LIKE '09_________'`, which works the same on MySQL, pgsql and sqlite.
- The PHP regex then has the final say.
- Everything else stays as it is: 10 digits starting with 0, 12 or 14 digits, empty, values with spaces or dashes, and rows already in the 9… form.

**How it writes.**
- Rows are read with `chunkById`, which moves forward by id, so changing the filtered column skips no rows.
- Each row is updated with `where id = ? and PHONE NUMBER = <before>`, so a row someone else changes in the meantime is left alone.
- The command is idempotent: a second `--apply` reports `Changed: 0`.

**Output.**
- First line: `DRY RUN (walang binago; --apply para i-convert): N row na 09XXXXXXXXX`. With `--apply` the line reads `APPLY: N row na 09XXXXXXXXX ang na-convert sa 9XXXXXXXXX`.
- A table with columns `ts_date | rows`, one row per day (`(walang ts_date)` for rows without one).
- `Halimbawa (id: before → after, ts_date):`, followed by up to 5 lines like `  #123: 09171234567 → 9171234567 (2026-10-28)`.
- With `--apply` it ends with `Changed: N (log: storage/logs/orders-phone-ten-digits.log)`.

**Log.**
- File: `storage/logs/orders-phone-ten-digits.log`, appended to, one line per change: `Y-m-d H:i:s<TAB>id=<id><TAB>before=09…<TAB>after=9…`.
- The file is opened before the first update. If it cannot be opened, the command exits with an error and changes nothing.
- Each line is written and flushed right after its row's update, and a failed write stops the run.
- In the unlikely case that a write fails after its update, the error message carries that row's id, before and after values, so keep the console output.
- No new table.

## What reacts to the row update

The command uses the query builder (`DB::table`), not Eloquent. Here is everything that could react:

| Possible reaction | Effect of the command |
|---|---|
| `updated_at` | Not changed. No Eloquent events fire, and there is no MacroOutput observer. The test asserts `updated_at` is unchanged. |
| TeleSMS-style `?since=` readers keyed on `updated_at` (MacroExportController) | Converted rows are not re-sent. Any copy already exported keeps the 09… form of the same number, which is harmless. |
| HISTORICAL LOGS | No line is written, on purpose. LiveOrder treats any line whose user is not "Likha encode" as a staff edit, so a command line would freeze the phone against later encodes. Staff reports also count those lines. The test asserts no line. |
| edited_* flags | Not touched. |
| Database triggers | MySQL's `trg_macro_output_set_ts_date_upd` fires BEFORE every UPDATE and recomputes `ts_date` from `TIMESTAMP`. That is the same value the insert trigger already set, so nothing changes. On pgsql the trigger is `UPDATE OF "TIMESTAMP"` only and does not fire. |
| Encode, Validate, Astra | None of them is triggered by an update. They run on their own schedules or buttons, and they now see the 10-digit form they expect. |

## Deploy notes (the command runs once after deploy)

1. Deploy, then restart the queue workers (`php artisan queue:restart`, or supervisor). Workers still on the old code would keep writing 09…. encodeRow treats the same number in either form as no change, so a row the old workers write in 09… stays 09… until the command runs.
2. Run `php artisan orders:phone-ten-digits` as a dry run and check the per-day counts. Expect Oct 28 and later to carry most of them, plus a few per day before that.
3. Run `php artisan orders:phone-ten-digits --apply`.
4. Run the dry run again: expect 0. It is safe to repeat at any time.
5. Keep the log file until the owner is satisfied, then delete it. It holds customer phone numbers.

**Incepxion.** incepxion.pro runs the same code on the same tables (`macro_output`), so all of the above applies there too: deploy, restart the workers, dry run, then apply on that install. If both sites turn out to share one database, the second site's dry run simply shows 0.

## Cases (S-186)

| Case | Test | Result |
|---|---|---|
| .1 the encode writes 9171234567 for "0917 123 4567" and "+63 917-123-4567"; no phone stays empty | `EncodeMacroTest::test_S_186_1_the_encode_writes_the_phone_as_ten_digits` (4 sets: chat 09 with spaces, chat +63 with dashes, uploaded 09 only, no phone); `PhoneTenDigitsTest::test_S_186_1_the_website_form_is_ten_digits_starting_with_9` (8 sets); `OrderImportKeyTest::test_S_186_1_the_likha_import_writes_the_phone_as_ten_digits` | pass |
| .2 a staff-set 9171234567 is not reported as edited when the encode finds 09171234567 | `EncodeMacroTest::test_S_186_2_the_same_number_in_the_other_form_is_not_an_edit` (staff set ten digits, ten digits, older 09 row) | pass |
| .3 the dry run counts and changes nothing; apply converts only `^09\d{9}$`; a second run converts 0 | `PhoneTenDigitsTest::test_S_186_3_dry_run_counts_and_apply_converts_only_eleven_digit_09_rows_once`, `test_S_186_3_one_row_chunks_convert_every_row_and_dry_run_beats_apply`, `test_S_186_3_apply_changes_nothing_when_the_log_cannot_be_written` | pass |
| .4 Validate passes a converted phone; same-day 9171234567 and 09171234567 are duplicates | `PhoneTenDigitsTest::test_S_186_4_validate_passes_a_converted_phone_and_flags_both_forms_as_same_day_duplicates` | pass |
| .5 the J&T payload is still 09171234567; the checker links a 09… waybill to a 9… order | `PhoneTenDigitsTest::test_S_186_5_jnt_still_sends_and_matches_the_09_form` (the checker part compares `makeKey` for both forms, which is the key the checker links on) | pass |
| .6 the existing OrderEncode, MacroChecker/Validate (NightRun) and Jnt suites pass | the suite runs below | pass |

**Red runs.**
- Before the code: `OrderPhone::website()` was undefined, the `orders:phone-ten-digits` command did not exist, and the encode wrote `09171234567` where `9171234567` was expected.
- The Likha import test failed with `09171234567`.
- The log-unwritable and `--dry-run` tests failed on the first command version ("file_put_contents … Failed to open stream" after the row was converted; "--dry-run option does not exist").

## Tests (sqlite)

| Suite | Baseline 36013979 | Final |
|---|---|---|
| tests/Feature/OrderEncode | 416 OK | 423 OK |
| tests/Feature/Orders | 22 OK | 36 OK |
| tests/Feature/Jnt | 37 OK | 37 OK |
| tests/Feature/NightRun (MacroChecker / Validate) | 326 OK | 326 OK |
| tests/Feature/Chatblast | 1347 OK, 1 skipped | 1347 OK, 1 skipped |
| tests/Feature/Botcake | 94 OK | 94 OK |

No new failures; only PHPUnit deprecation notices, the same as in the baseline.

**Existing tests changed for the new format.** These are expected values only; the behaviour they check is the same.
- `EncodeMacroTest::chatIdentity` / S_137_15: 09… became 9…, including the HISTORICAL LOGS line.
- `EncodeOrderTest` S_130_15 and S_130_17.
- `UploadMacroTest` S_137_2.
- `OrderImportKeyTest` S_121_1_4 and S_121_2_1.
- `AstraAddressRulesTest::duplicatePhoneQueries`: the SQL-shape regex now also accepts `"PHONE NUMBER" in (?, ?)`. Both of its callers ("ran once", "not run") still assert the same thing.

## Review

The skeptic reviewer found two majors, both fixed in one loop and re-checked as "fixes OK":
1. The Likha sheet import still wrote 09….
2. The command wrote its log only after each chunk, so a crash could leave converted rows without a record.

Minors I fixed:
- added `--dry-run`;
- updated `docs/order-encode.md`;
- added tests for chunk boundaries and an unwritable log.

## Notes for the owner (not changed, by scope)

- **The Validate button's own count.** `MacroOutputController::validateCheckerToFix` / `validateAddresses` count duplicate phones by exact string within their scope. A 9…/09… pair is not counted as a duplicate there, but the 09… row still hard-fails the `^9\d{9}$` format rule, so nothing gets through. After the conversion there are no such pairs. Decision 3 names only MacroChecker.
- **The `order_phone` flow variable.** It carries the row's PHONE NUMBER, so for new rows it now shows `9171234567`; between Oct 28 and this fix it showed 09…. Rows from the macro sheet have always been 10 digits. A flow that shows `{{order_phone}}` to a customer, or compares it with the 09… `{{PHONE}}`, would see the 10-digit form. Flows live in the database, so I could not check them.
- **The sheet-era `night:import likha` path** now also writes 9….

186: done — encode, Upload to macro and the Likha import write 9XXXXXXXXX, Validate catches both forms, and `orders:phone-ten-digits` (dry run, then `--apply`) converts the old 09… rows on Likha and Incepxion.
