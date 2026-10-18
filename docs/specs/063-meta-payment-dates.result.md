# Result 063: Meta payment import reads dates correctly, and the swapped rows get fixed

Spec: `docs/specs/063-meta-payment-dates.md`.

- **Branch:** `fix/063-meta-payment-dates`, cut from `main` at `b3866dbd`. Not pushed.
- **Scope:** no migration, no new route, no new dependency. The backfill was never run against a real database.
- **Plan:** the brief fixed every design decision, so the plan stayed in-line (no plan file). The brief was taken as the go.
- **Stories:** the brief brought none. The cases were written from its decisions and tests list (S-063.1 to S-063.11 in `qa/stories.md`), without a separate story-writer pass.

## What changed

**Importer** (`app/Jobs/ProcessPaymentActivityUpload.php`):
- **First pass:** reads every row of the file before parsing any date, then decides one date order for the whole file:
  1. A number above 12 in the billing line or in any data row. In the first place it means D/M; in the second, M/D. A file with both is rejected as contradictory.
  2. Otherwise, the billing line's start and end matched against the ISO range in the filename. Both forms are read: `2026-10-02--2026-10-10` and `2025-09-22-2025-10-20`. Only one order may match.
  3. Otherwise, the header wording, case-sensitive: "Billing Report:" means M/D, "Billing report:" means D/M.
  4. Otherwise, when the file has slash dates: nothing is written. The upload log becomes `failed`, with "Cannot tell the date order of this file".
- **Removed:** the `d/m/Y` default and the loose fallback list that tried `d/m/Y` first.
- **Strict parse:** slash dates go through `!n/j/Y` (or `!n/j/y`) in the file's order, and any parser warning or error means the date is invalid. 2/30 is refused, and 5/31 can't become 2028. ISO dates use `!Y-m-d` strictly. DateTime cells and Excel serials are unchanged.
- **Range guard:** a payment row is refused when:
  - its date is not a real date;
  - it is more than one day after the upload date (taken from the stored name `YYYYMMDD_HHMMSS__…`, otherwise today);
  - or it is more than one day outside the filename's ISO range.

  Refused rows count in `error_rows`, with a message, and are logged.
- **Not counted as refused:** Meta's "Total Amount Billed" and VAT footer rows (they have no transaction id and no amount).
- **Upload page** (`ads_manager/payment/upload.blade.php`): shows the log's `error_message` under the status, escaped.

**Backfill** (`app/Console/Commands/FixSwappedPaymentDates.php`, `payments:fix-swapped-dates`):
- **When a row is swapped:** the spec's rule exactly.
  - Only rows whose stored day is 12 or less are candidates.
  - The swapped reading T = (Y0, month = S.day, day = (S.year − Y0)×12 + S.month), for Y0 = the year of `created_at` and the year before, when that is a real date.
  - "Plausible" means 0 to 35 days before `created_at`. The row is swapped when T is plausible and S is not. When both are plausible, the one closer to `created_at` wins; a tie keeps S.
- **Default (or `--dry-run`):**
  - prints the count, the total and 10 examples: `#id  stored → corrected  amount  card last4`;
  - writes every change to `storage/app/private/payment_date_fixes/fix-swapped-dates_YYYYMMDD_HHMMSS.csv` (id, stored, corrected, amount, card_last4, created_at, source_filename);
  - changes nothing.
- **`--apply`:**
  1. Copies the table to `payment_activity_ads_manager_bak_YYYYMMDD_HHMM`. MySQL uses `CREATE TABLE … LIKE` + `INSERT … SELECT`; pgsql and sqlite use `CREATE TABLE … AS SELECT`. It refuses when that name already exists, and checks that the row counts match.
  2. In one transaction, updates only `date`, by id and only while the stored date is unchanged. A row that changed rolls the whole transaction back.
  3. Logs who ran it (the OS user), the count, the total, the backup name and the CSV.
- **`--expect=N`:** refuses (failed exit, no backup, nothing changed) when the count differs.

**Usage** on the server, by the deployer, after the importer fix is live:

```
php artisan payments:fix-swapped-dates                       # dry-run: count, total, examples, CSV
php artisan payments:fix-swapped-dates --apply --expect=374  # backup table, then fix only date
```

## Case table

| Case | Test | Result |
|---|---|---|
| S-063.1 | `PaymentDateOrderTest::test_S_063_1_a_number_above_12_in_any_row_or_the_billing_line_decides_the_order` | pass |
| S-063.2 | `PaymentDateOrderTest::test_S_063_2_with_all_numbers_12_or_less_the_filename_iso_range_decides` | pass |
| S-063.3 | `PaymentDateOrderTest::test_S_063_3_without_numbers_or_filename_range_the_header_wording_decides` | pass |
| S-063.4 | `PaymentDateOrderTest::test_S_063_4_an_undecidable_file_is_rejected_with_a_clear_error` | pass |
| S-063.5 | `PaymentDateOrderTest::test_S_063_5_strict_parse_never_rolls_an_invalid_date_over` | pass |
| S-063.6 | `PaymentDateOrderTest::test_S_063_6_rows_outside_the_filename_range_or_after_the_upload_date_are_refused` | pass |
| S-063.7 | `PaymentDateOrderTest::test_S_063_7_re_importing_an_ambiguous_capital_R_file_keeps_the_correct_date` | pass |
| S-063.8 | `PaymentDateOrderTest::test_S_063_8_the_upload_page_shows_the_error_of_a_failed_file` | pass |
| S-063.9 | `FixSwappedDatesTest::test_S_063_9_dry_run_lists_the_known_swapped_rows_and_changes_nothing` | pass |
| S-063.10 | `FixSwappedDatesTest::test_S_063_10_apply_backs_up_the_table_then_changes_only_date` | pass |
| S-063.11 | `FixSwappedDatesTest::test_S_063_11_apply_refuses_when_the_count_differs_from_expect` | pass |

**Red runs:**
- **Importer:** all 8 failed on behaviour, e.g. S-063.1 `Expected '2026-10-03', Actual '2026-03-10'`, S-063.5 `Actual '2028-07-05'`, S-063.4 `Expected 'failed', Actual 'done'`.
- **Backfill:** 3 errors, because the command did not exist yet.
- **Fix loop:**
  - S-063.5/6/7 failed on the footer rows counted as refused;
  - S-063.9 failed on the missing `source_filename` header;
  - the year-before rollover case passed at once (the code was already right; only the test was missing).

**Paths:** `tests/Feature/PaymentActivity/PaymentDateOrderTest.php` and `FixSwappedDatesTest.php`. 11 tests, 63 assertions.

**Full suite** (`vendor/bin/phpunit`, sqlite in memory): 1,809 tests, 19,554 assertions; the only failures are the 2 known ones, and 3 tests are skipped. The failures are `ExampleTest` (302) and `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (no `storage/app/credentials.json`). Through `artisan test`, Feature tests show a warning because the worktree has no `.env` file. That is environmental; no `.env` was created or read.

## Review

`skeptic-reviewer`, adversarial depth, opus, on `6fdde880..HEAD`. One fix loop (`f597b2f6`), re-checked and closed.

- **Fixed:**
  - (major) Meta's VAT footer rows were counted as refused on every real upload. Now only rows with a transaction id or amount count.
  - (major, missing test) the year-before rollover (2027-04-12, created 2026-01-05 → 2025-12-28) is in the backfill fixture.
  - (minor) the CSV carries `source_filename`.
- **Accepted, in `TODO.md`:** the whole file is held in memory; text dates still go to the loose parse; some branches are untested (xlsx, 2-digit years, the equal-distance and changed-while-running guards, and the MySQL/pgsql backups); `.` is accepted as a separator and the command refuses `--dry-run` with `--apply`; a row with an empty date is not counted as refused; the backfill can flag a correct row that was first imported about a month after its payment.

## Story changes

None to stories from the owner: the brief brought none. S-063.7 and S-063.9/10 were widened in the fix loop to cover the footer rows, the year-before rollover and the CSV column.

## What the brief got wrong or left open

- **"All 808 unambiguous capital-R files were mis-parsed":** this is likely wrong. The old code chose m/d whenever the billing line's second number was above 12 (e.g. "9/22/2025 - 10/20/2025"). So those files read correctly, and only the 259 ambiguous ones were swapped. The fix doesn't change, but rows from those files must not be flagged.
- **Filename form:** stored files use both `2026-10-02--2026-10-10` and the single-dash form `2025-09-22-2025-10-20`. Both are read.
- **Left open by the brief, decided here:**
  - **Contradicting rows** (one with a first number above 12, another with a second number above 12): the file is rejected.
  - **"Upload date":** taken from the stored name's timestamp (Manila).
  - **The one day of slack:** also applied to the upload date, for the Meta timezone vs Manila.
  - **Refused rows:** counted in `error_rows`, with a message on the upload page.
- **Backfill false positives:** the rule decides by `created_at` alone. A correct row from a file uploaded about a month late, with day ≤ 12 and day > month, can be "fixed" wrongly. None of the stored sample files is that late. Check the dry-run CSV before `--apply`: no row should come from a lowercase "Billing report" file. `--expect=374` confirms the count; it is not an independent check if the investigation used the same rule.
- **Deploy order:** the importer fix must be live before `--apply`. Otherwise a re-import of an old ambiguous capital-R file swaps the fixed rows again.

## Merge danger

Low. Two files change behaviour: the importer job and its upload view. The new command does nothing unless it is run. Nothing runs on deploy: no migration, no scheduler entry.
