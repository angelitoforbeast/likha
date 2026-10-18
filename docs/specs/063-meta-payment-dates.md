New task: spec 063.

**Branch.** This worktree is a new branch `fix/063-meta-payment-dates`, cut from `main` at b3866dbd (live on Likha).

**Rules.**
- Never push. Never touch remote hosts.
- Never run the backfill against a real database.
- Use fake data in tests, with no names or real card or account numbers.
- No attribution lines.

**Files.**
- Save this brief as `docs/specs/063-meta-payment-dates.md` in your first commit.
- Save the result as `docs/specs/063-meta-payment-dates.result.md`.

Aim at about 1.5 hours. Run a review before the result.

# Brief 063: Meta payment import reads dates correctly, and the swapped rows get fixed

## Why

The owner's RCBC card statement showed "Wala sa Meta" for payments that ARE in `payment_activity_ads_manager`. Those rows have the day and month swapped. For example, an Oct 3, 2026 payment of 340,669.22 is stored as 2026-03-10. A read-only investigation found the cause in `app/Jobs/ProcessPaymentActivityUpload.php`:

- **The guess (~L364-399).** The importer reads the date order from the "Billing report:" line. When every number in that range is 12 or less, it falls back to `d/m/Y` at ~L393-395, which the code calls "Meta standard". That is wrong.
- **The parse (~L419).** Every row is parsed with that format.
- **The fallback list (~L424).** It tries `d/m/Y` first.
- **Rollover.** The parse is not strict, so 5/31/2026 rolls over into 2028-07-05.
- **The upsert (~L468-472).** It overwrites `date`, `uploaded_at` and `source_filename` on every re-import. `created_at` is never overwritten.

Meta exports come in two date orders. They can be told apart by the header wording:

| Header | Order | Typical form |
|---|---|---|
| "Billing Report:" (capital R) | M/D/YYYY | mostly not zero-padded |
| "Billing report:" (lowercase r) | DD/MM/YYYY | zero-padded |

Across the 1,278 stored CSVs in `storage/app/private/uploads/payment_activity/`, both forms appear:
- capital-R files: 808 unambiguous and 259 ambiguous, all of which were mis-parsed;
- lowercase files: 170 unambiguous and 41 ambiguous, all of which were read correctly.

Filenames carry an ISO range, e.g. `20261009_100215__2026-10-02--2026-10-10_Invoice_Summary.csv`.

**Live count on Likha:** 374 rows are swapped (about ₱10.75M). Incepxion's table is empty; that is a separate issue and not in this spec.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| **Decide the date order once per file, in this order:** <br>(1) any number above 12 in the billing line or in any data row (first pass over the rows); <br>(2) otherwise, match the billing line against the ISO dates in the filename; <br>(3) otherwise, the header wording ("Billing Report" = M/D, "Billing report" = D/M); <br>(4) otherwise, reject the file with a clear error shown on the upload page ("Cannot tell the date order of this file"). <br>Remove the `d/m/Y` default and its first place in the fallback list. | Correct order every time |
| **Parse strictly.** Use `!`-prefixed formats, and treat any warnings or overflow as a failure, so 5/31 can never become 2028. Refuse a row dated after the file's upload date, or outside the filename's ISO range (allow one day of slack). | No silent wrong dates |
| **Backfill command `payments:fix-swapped-dates`.** It takes `--dry-run` (the default) and `--apply`. <br>**Rule:** a row is swapped when the stored day is 12 or less and the swapped reading T is plausible while the stored reading S is not. "Plausible" means 0 to 35 days before `created_at`. When both readings are plausible, take the one closer to `created_at`. Compute T for the rollover cases too: with Y0 = the year of `created_at` (or the year before), T = (Y0, month = S.day, day = (S.year − Y0)×12 + S.month), valid only if that is a real date. <br>**Dry-run** prints the count, the total amount and 10 examples (id, stored, corrected, amount, card last 4), and writes a CSV of every change to storage. <br>**Apply** first copies the table to `payment_activity_ads_manager_bak_YYYYMMDD_HHMM` (a small table, about 2.4k rows), then updates only `date` by id in one transaction. It logs who ran it and the count. It refuses to run when the count differs from `--expect=N` if that is given. | The owner: "itama ang 374 na record, may backup muna" |
| **Known cases the tests must cover** (use fake ids): Oct 1/3/4 stored as Jan 10/Mar 10/Apr 10; 2028-07-05 → 2026-05-31; 2027-01-02 → 2026-02-13; an ambiguous row kept (stored 2025-12-11, created 2025-12-12); an ambiguous row swapped (stored 2026-02-03, created 2026-03-04). | Proven by the investigation |
| **Re-imports.** The importer change is what stops a re-import from breaking the fixed rows again. Add a test: re-importing an ambiguous capital-R file keeps the correct date. | Must hold after the fix |

## Tests

- Each of the order rules.
- Rejection of an undecidable file.
- Strict parsing with no rollover.
- The date range guard.
- The backfill cases above.
- Dry-run changes nothing.
- Apply makes the backup table and changes only `date`.
- `--expect` mismatch refuses.
- The full suite passes, except the known `ExampleTest` and `ImportStartTest`.

## Final message

Report the commits, the parse logic, the backfill usage, and the test lines with the suite result, plus the review findings and anything the brief got wrong.
