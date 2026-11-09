# 255 result: The chatblast setup delivery check uses the page index

Branch `fix/255-setup-check-index`, from 078a87e0.

## How botcake_messages.page_name is written
- Real (non-test) rows: `app/Services/Chatblast.php:600` `$page = $this->settings->normPage((string) $o->page)`, written at `:617` and inserted at `:628`. The name comes from the order's PAGE (macro_output), normalized by `PageSettings::normPage()` (`app/Services/PageSettings.php:82-85`: trim plus inner whitespace collapsed to one space). This has been the case since the table was created (e012b6de).
- Test rows: `app/Services/ChatblastSender.php:68-71` copies the row's page_name; `:88-90` uses `normPage($page)`. Both are `is_test = true`, so sentVia never reads them.
- The settings key is `PageSettings::pageKey()` (`PageSettings.php:87-90`): `mb_strtolower(normPage(...))`.
- So stored names can differ from the key by case (e.g. "Page Uno" and "PAGE UNO" from different orders, as `ChatblastReady.php:122-123` already notes). Surrounding spaces are not written today, but older or hand-made rows could have them, and the old filter `LOWER(TRIM(page_name))` accepted them, so the new code does too.

## Approach (S1)
`app/Services/ChatblastSetupCheck.php`:
- `:366-382` `sentVia()` now filters `whereIn('page_name', $names)` together with status, is_test and sent_at. There is no function on the column, so MySQL can use `botcake_messages_page_name_status_sent_at_index` (page_name, status, sent_at) as a range. If no stored name matches, it returns 0 without reading.
- `:384-396` `storedNames($page)`: `SELECT DISTINCT page_name FROM botcake_messages`, kept in PHP when `mb_strtolower(trim($name, ' '))` equals `pageKey($page)`. This is the same test as the old SQL (`LOWER(TRIM(page_name)) = key`; TRIM removes spaces only). page_name is the first column of six indexes on the table (migration `2026_10_18_300000_create_botcake_messages_table.php:47-51`), so MySQL reads this DISTINCT from an index with a loose index scan: one lookup per distinct page, not a scan of the table. It runs once per sentVia call. It is not cached across calls, so a new spelling of a page is never missed.
- Why not page settings as the name source: botcake_messages.page_name comes from the order's PAGE, not from page_settings, so its case can differ from the settings row. Using the settings names would miss rows.
- MySQL collation (utf8mb4_unicode_ci, case-insensitive, PAD SPACE): DISTINCT returns one value per group of names that compare equal, and `IN` matches the whole group. Both the old and the new filter compare under that collation, so the same rows match. One exception: if the stored value differs from the key only by an accent (e.g. "Café" against key "cafe"), the old SQL matched it but the PHP check does not. Pages and orders use the same page name, so I don't expect this to happen.
- No new index. No migration. The pgsql branch no longer needs BTRIM here; `whereIn` and `distinct` work on every driver.

## S2: other LOWER(TRIM(page_name)) queries on botcake_messages (unchanged)
1. `app/Services/ChatblastReady.php:124-126` `fresh()`: `MAX(queued_at)` of sent non-test rows, filtered with `LOWER(TRIM(page_name)) = ?`. It runs from `reason()` (`:91-95`), which is called by the sender before every real send (`ChatblastSender.php:198`) and cached for 60 s per page. This is the hottest one: during sending, each page runs it at most once a minute. The same fix applies (move `storedNames` into a shared place). I did not change it because no test covers its query shape, and the spec says not to.
2. `app/Http/Controllers/PageSettingController.php:389-391` Send test: the latest non-test row with text for the page, ordered by id desc. It runs once per button press, so it is not a hot path.

The other `LOWER(TRIM(page_name))` queries in app/ are on other tables (page_settings, botcake_psid_import_runs, the chatbot AI queue, page_item_settings, ads_manager_reports), so they are out of scope.

## Tests
- New `tests/Feature/Chatblast/ChatblastSetupCheckIndexTest.php`: 6 tests. The same counts for exact, different-case and padded names, and for a padded or different-case key. Rows that must not count: too recent, another flow, test sends, failed, another page, different inner spacing, a longer name. `since` still applies. All case variants of one page count together. A page with no rows returns 0. A query-capture test checks that no botcake_messages query wraps page_name in LOWER/TRIM/BTRIM/UPPER and that the read uses `"page_name" in (`. Before the fix, the 5 behaviour tests passed and the query-capture test failed.
- `ChatblastSetupCheckTest` + the new file: 25 tests, 140 assertions, all pass.
- Full suite at 078a87e0: 4853 tests, 78936 assertions, 2 failures, 6 skipped.
- Full suite on the branch: 4859 tests, 78952 assertions, 2 failures, 6 skipped. The 2 failures are the known baseline ones (ExampleTest::test_the_application_returns_a_successful_response, ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order). No new failures; 6 tests added.

## Not verified
- I could not run EXPLAIN on production MySQL from here. To confirm after deploy: `EXPLAIN SELECT DISTINCT page_name FROM botcake_messages` should show "Using index for group-by", and the sentVia read should show range access on `botcake_messages_page_name_status_sent_at_index`.

## Final sha
967a85d5 (the code commit; this result file is the commit after it).
