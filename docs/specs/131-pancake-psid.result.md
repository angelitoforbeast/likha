# Result: 131 Pancake conversations keyed by PSID

## What shipped
- `database/migrations/2026_11_05_100000_add_psid_to_pancake_conversations.php`: nullable `psid` (string 32), old rows keep null.
- `app/Jobs/ProcessPancakeConversationsUpload.php`: reads the "Sender ID" column and keys by page + PSID.
- `app/Services/PancakeChat.php` (new): `forOrder(page name, fb name, psid)` returns `['chat', 'match']` or null.
- `app/Http/Controllers/MacroOutputController.php`: `pancakeMore` and `has_pancake` use the lookup.
- `resources/views/macro_output/index.blade.php`: sends the order id, shows the grey match label.
- Tests: `tests/Feature/PancakePsid/` (import, lookup, See more, list).

## Deviations from the brief (both needed, both in the migration)
1. **The unique index on (page, name) is dropped** and replaced by a plain index on the same columns. The old unique made "two PSIDs with the same name in one file → two rows" impossible. The migration is not purely additive because of this. `down()` restores the unique (it fails if duplicates exist by then).
2. **The index is (`psid`, `pancake_page_id`), not (`pancake_page_id`, `psid`).** The list query checks `pc.psid = botcake_psid` without a page; a page-first index would make that a full scan per row on 688k rows.

## Import rules
- Header containing "sender id" (case-insensitive). The cell is accepted as text, or as an int from a number cell (OpenSpout returns an exact int for 17 digits, tested). A float, date or bool is treated as missing. Digits only are kept; 10–25 digits, else missing and the row falls back to page + name.
- Aggregate per page + PSID when the row has one, else per page + name. The newest name in the file is kept.
- Write: same page + PSID → update chat and `full_name`. Else, a row with the same page + name and PSID null, and that name has exactly one PSID in the file → fill its PSID and update it (adopt). Else insert.
- Adoption needs the whole file, but the file is flushed every 4000 rows, so a cheap first pass counts PSIDs per page + name (test with a second PSID after the first chunk).
- A row without PSID only updates rows with PSID null, so a file without Sender ID cannot overwrite a PSID customer's chat. If only PSID rows exist for that name, it inserts a name-only row.
- Phone filter, chat replace rule and the log counts are unchanged. "With PSID" has no free column in `upload_logs`, so it goes to `laravel.log` (`[Pancake Import] done {"with_psid": …}`).
- The write path checks `Schema::hasColumn('pancake_conversations','psid')`, so the job still works before the migration is run.

## Lookup and See more
- Order: PSID (scoped to the mapped Pancake page id when `pancake_id` resolves the page name, else PSID alone) → page + name → name only. Newest `id` in each step.
- Labels: `Matched by PSID` / `Matched by page and name` / `Matched by name only — may be another customer`.
- `pancakeMore` takes `id` (order id) or, as before, `fb_name`; one of them is required (422 otherwise), CSRF unchanged. With an id, page, name and PSID are read from the order in the database, not from the client. Chat and label are set with `textContent` in the view; the 8000-char cap is kept.
- `has_pancake`: still one select. `CASE WHEN EXISTS(psid = botcake_psid) OR EXISTS(full_name = fb_name)`. The PSID part is skipped if either column is missing. The test checks that the list runs one query containing `has_pancake`.
- Known gap: the PSID `EXISTS` in the list is not scoped to the page, so on an order whose page is mapped and whose PSID exists only under another page, the link shows and then finds nothing. PSIDs are page-scoped, so this should be rare.

## Where others read by name (not changed)
- `AstraEncoder::pancakeHistory($fbName)`, `app/Services/AstraEncoder.php` ~line 839: `where('full_name', $fbName)->orderByDesc('id')`. Called at ~530 with `$row->fb_name`.
- `MacroChecker::fetchPancakeChat($fbName)`, `app/Services/MacroChecker.php` ~line 492; called from `MacroChecker` ~185 and `AstraEncoder` ~736.
- One-line switch for both: replace the query with `app(PancakeChat::class)->forOrder($row->PAGE, $row->fb_name, $row->botcake_psid)['chat'] ?? ''` (it also needs the `$row`, not only the name, passed in).
- Their behaviour is unchanged for old rows. After adoption a customer's row keeps its name (newest) and id, so they still match. Two same-name PSID customers are now two rows, and a name lookup takes the newest id.
- `PancakeRetrieve2Controller` (page + name against `macro_output` pairs, two places) is left alone: moving it to PSID means joining `macro_output.botcake_psid` inside the report subqueries, which is not small or clearly safe.

## Tests
- New: `php vendor\phpunit\phpunit\phpunit tests/Feature/PancakePsid` → OK (14 tests, 32 assertions).
- Requested suites (Chatblast, PageSettings, Botcake, NightRun + PancakePsid): 1296 tests, 1 error, which is the known local-only one: `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (missing `storage/app/credentials.json`). Existing Astra / MacroChecker tests are unchanged and pass.
- Red runs (first failure): import tests failed on `psid` null / missing rows before the job change; lookup tests failed with `Class "App\Services\PancakeChat" not found` before the service.

| Case | Test | Result |
|---|---|---|
| Sender ID inserts rows with PSID; two PSIDs same name → two rows | `test_export_with_sender_id_inserts_rows…` | pass |
| Same PSID later updates and takes newest name | `test_same_psid_in_a_later_file_updates…` | pass |
| Old name-only row adopted | `test_old_name_only_row_is_adopted…` | pass |
| Not adopted when name has two PSIDs (also after a 4000-row chunk) | `…not_adopted…`, `test_adoption_sees_a_second_psid…` | pass |
| File without Sender ID as before | `test_file_without_sender_id_behaves_as_before…` | pass |
| 17-digit number cell keeps digits | `test_sender_id_stored_as_a_number_cell…` | pass |
| Non-numeric / short / long Sender ID ignored | `test_bad_sender_ids_are_ignored…` | pass |
| Service order psid → page+name → name only; page scoping | `test_service_*` | pass |
| See more returns the PSID customer's chat and label; other labels; validation | `test_see_more_*` | pass |
| `has_pancake` true for PSID-only order; no N+1 | `test_has_pancake_is_true_for_a_psid_only_order…` | pass |

## Suggestions for the owner (not done)
- The chunk flush replaces the chat with the later chunk, so a customer whose rows span a 4000-row boundary loses the earlier part (existing behaviour, same for PSID rows).
- `Retrieve2`, `AstraEncoder`, `MacroChecker` switches above.
- Run `php artisan migrate --force` before importing files with Sender ID (the job still works without it, but stores no PSID).

## Commits
- `docs: spec 131 pancake psid`
- `feat: pancake import keys conversations by Sender ID (PSID) and adopts old name-only rows (spec 131)`
- `feat: See more and has_pancake use one lookup, PSID first, with a match label (spec 131)`
- the result file commit below.
