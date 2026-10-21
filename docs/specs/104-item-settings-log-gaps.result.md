# Result 104: every change to RTS, promo and COGS lands in the one edit log, deletes and /item/cogs included

Branch `feat/104-item-settings-log-gaps`, cut from `main` (18821a4a). Not pushed.

## What changed

- **One log, one new column.** Migration `2026_11_01_100000_add_source_to_page_item_settings_log.php` adds `source` (nullable string(30), indexed) to `page_item_settings_log`. Values are `owner_private`, `item_cogs` and `cogs_sync`. Existing rows stay NULL and read as `owner_private`, where every old row came from. No new table, and no other column changes.
- **New action `delete`.** Every new row also sets `scope` (`rts` / `promo` / `cogs`) when that column exists. `scope` has no migration in the repo; production may have it. The code checks with `hasColumn`, as `saveItemSetting` already does.
- **`App\Services\ItemSettingsLog::write()`** is the one insert used by the three new callers. It:
  - skips quietly when the table is missing;
  - drops optional columns (`old/new_promo`, `old/new_item_value_ceo`, `scope`, `source`) that the database doesn't have yet;
  - fills the timestamps;
  - fills `page_name = ''` for global COGS.
- **Path 1, `/owner/private` delete** (`OwnerPrivateController::deleteItemSetting`):
  - One `delete` row per deleted field, with old value → NULL, the user, the page, the item and the date.
  - COGS: one row with `old_item_value` and `old_item_value_ceo`, because one click deletes both. Non-CEO viewers still never see the CEO column.
  - RTS and promo: one row per `page_item_settings` row that had a value.
  - A field with no value writes no row.
  - Each delete and its log row run in one `DB::transaction`.
- **Path 2, `/item/cogs`** (`ItemCogsController`):
  - `update` writes `create` (no row yet) or `update` with old → new `item_value` and the user.
  - `delete` writes `delete` with old → NULL, only when a row was deleted.
  - `clean-redundant`, the page's bulk delete, writes one `delete` row per removed cell. The brief doesn't name it, but it deletes `cogs` cells from the same page.
  - Each write runs with its log in one transaction.
  - This page never edits `cogs_ceo`, so the CEO columns stay NULL.
- **`page_name` for `/item/cogs` and the sync is `''`, not NULL.** The column is NOT NULL since its first migration, and the brief allows only added nullable columns. COGS is global per item and date, with no page. The edit-logs page shows `''` as "— lahat ng page —" and leaves it out of the Page dropdown.
- **Path 3, `cogs:sync-from-macro`** (`CogsSyncFromMacro`):
  - The sync never changes an existing row. It skips (item, date) pairs that already have one, and inserts a new row with the last known price (or NULL).
  - So "changed" means the new row's value against the empty cell before it.
  - A new row that gets a price logs `create`, NULL → price, `user_email` NULL, `source = cogs_sync`. A row inserted with NULL logs nothing, and nor does a skipped pair.
  - The insert and its log run in one transaction.
  - The command has no schedule in the repo; it runs by hand.
- **The edit-logs page** (`editLogs` and `owner/edit_logs.blade.php`):
  - The Action cell shows the pill (new red `DELETE`) and, under it, the source: "/owner/private", "/item/cogs" or "cogs sync".
  - A delete shows the old value struck through, then "→ deleted", in RTS, Promo, COGS and (CEO only) CEO COGS.
  - A sync row's user is "system (cogs sync)". Other rows without a user still show "— anonymous —".
  - New Source filter (all, /owner/private incl. old rows, /item/cogs, cogs sync), shown when the column exists.
  - The user, page, item, scope, date and changed-only filters are unchanged. Delete rows count as changed.
  - Access is unchanged (CEO, Marketing - OIC). Existing rows render as before, plus the "/owner/private" label.
- **Test support only:** `dateExpr` in `ItemCogsController` and `CogsSyncFromMacro` got a `sqlite` branch (`DATE("TIMESTAMP")`), so the tests can run both paths. MySQL and pgsql take the same branches as before.
- **No value is computed or saved differently.** `saveItemSetting` is untouched. It still writes its own row, with `source` NULL (= /owner/private).

## Every write path to `cogs`, `cogs_ceo` and `page_item_settings`

Found with `git grep -nE "updateOrCreate|updateOrInsert|->delete\(\)|->update\(|Cogs::create|INSERT .*INTO cogs_ceo|UPDATE page_item_settings"` over `app/`, `routes/` and `database/`. Line numbers are after this change.

| Write | Where | Logs? |
|---|---|---|
| RTS / promo upsert, delete on rts = 0, apply-through cascade | `app/Http/Controllers/OwnerPrivateController.php:2969-3016` (`saveItemSetting`) | yes, as before (one row per save, `:3097`) |
| COGS / CEO COGS upsert and cascade | `OwnerPrivateController.php:3027-3058` (`saveItemSetting`) | yes, as before |
| `cogs` → `cogs_ceo` auto-mirror of missing pairs | `OwnerPrivateController.php:70,78` (`mirrorMissingCogsToCogsCeo`) | no: copies only missing pairs, never overwrites, and runs after a logged save (TODO) |
| Delete COGS + CEO COGS | `OwnerPrivateController.php:3162-3164` (`deleteItemSetting`) | **yes, new** |
| Delete RTS / promo (null the field or delete the row) | `OwnerPrivateController.php:3192-3194` (`deleteItemSetting`) | **yes, new** |
| `/item/cogs` edit a cell | `app/Http/Controllers/ItemCogsController.php:169` (`update`) | **yes, new** |
| `/item/cogs` delete a cell | `ItemCogsController.php:202` (`delete`) | **yes, new** |
| `/item/cogs` clean redundant anchors | `ItemCogsController.php:274` (`cleanRedundant`) | **yes, new** |
| cogs sync insert | `app/Console/Commands/CogsSyncFromMacro.php:59` | **yes, new** (only when the new row has a value) |
| Price-tag backfill of `page_item_settings.mode_cod_int` (only where NULL) | `app/Services/DailyPrimaryItemService.php:454,480` | no: touches no RTS, promo or COGS value (TODO) |
| Migrations: promo backfill to 'NONE', rts_pct NULL → 0 in `down()` | `database/migrations/2026_05_20_140000_…:27`, `2026_05_21_140000_…:54` | no: one-time schema steps run by hand |

No other job, command, seeder or route writes these tables.

## Migration (needs the owner's yes before release)

`database/migrations/2026_11_01_100000_add_source_to_page_item_settings_log.php` adds one nullable column with an index. It is guarded with `hasTable`/`hasColumn` and deletes or rewrites no data. Run it by hand with the deploy (`php artisan migrate --force`).

If the code goes out before the migration:
- The new rows are still written, without `source`.
- The page has no Source filter.
- The source label falls back to "/owner/private" for every row.
- Nothing errors.

## Case table

`vendor/bin/phpunit tests/Feature/OwnerPrivate/ItemSettingsLogTest.php`: **OK (9 tests, 70 assertions)**, on the final code.

| Case | Test | Result |
|---|---|---|
| S-104.1 | `test_S_104_1_owner_private_delete_writes_a_delete_row_old_to_null_with_the_user` (rts, promo, cogs) | pass ×3 |
| S-104.2 | `test_S_104_2_owner_private_delete_of_a_field_with_no_value_writes_no_row` | pass |
| S-104.3 | `test_S_104_3_item_cogs_update_and_delete_write_rows_with_old_new_and_the_user` | pass |
| S-104.4 | `test_S_104_4_item_cogs_clean_redundant_writes_a_delete_row_per_removed_cell` | pass |
| S-104.5 | `test_S_104_5_cogs_sync_logs_only_rows_whose_value_changed_attributed_to_the_system` | pass |
| S-104.6 | `test_S_104_6_edit_logs_lists_new_rows_with_labels_and_the_source_filter` | pass |
| S-104.7 | `test_S_104_7_existing_rows_without_source_read_as_owner_private_and_show_as_before` | pass |
| S-104.8 | browser (below) | pass |

Red runs, before the code (after the migration existed):
- S-104.1 (rts, promo, cogs), S-104.4, S-104.5: `Failed asserting that actual size 0 matches expected size 1` (no log row).
- S-104.3: `Failed asserting that two arrays are identical` (no rows).
- S-104.6: `Failed asserting that '<!doctype html>…' contains ">DELETE<"`.
- S-104.2 passed from the start. It is the negative case (no row when nothing is deleted), and it still guards the new code.
- S-104.7 first passed on a weak check: "/owner/private" also appears in the Back link. I tightened it to the source label element (`class="log-src"`) before writing the view.

**Required suites:** `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake tests/Feature/OwnerPrivate`: **OK, 1038 tests, 7133 assertions** (1 skipped, 50 PHPUnit deprecations; no failure).

**Full suite:** `vendor/bin/phpunit`: **2213 tests, 22238 assertions, 1 error, 1 failure** (4 skipped, 50 PHPUnit deprecations). Neither is new: both fail the same way on a clean `main` checkout (18821a4a), run in a temporary worktree.
- `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`: `InvalidArgumentException: file "…/storage/app/credentials.json" does not exist`. The test needs a local file that is not in git.
- `ExampleTest::test_the_application_returns_a_successful_response`: `Failed asserting that 302 is identical to 200`. `/` redirects to login.

## Browser check

There was no built-in browser here, so I used headless Chrome over DevTools (Node 24, no packages), as in spec 103. The scripts lived outside the repo. A throwaway PHPUnit test, also outside the repo, seeded data through the real routes and saved the rendered page:
- two old rows: one with RTS and promo changes and a comment, one anonymous;
- a `/owner/private` delete of RTS, and one of COGS + CEO COGS;
- a cogs sync;
- a `/item/cogs` edit and delete.

I opened the saved page with the app's built CSS at 1366 and 390 px, as the CEO (the widest view, with the CEO column). I measured the page width and every element wider than the viewport outside a scroll box. No env file was created.

**Result: pass. No page-level horizontal scroll at either width and no console errors.**

| Check | 1366 | 390 | Evidence |
|---|---|---|---|
| DELETE / UPDATE / CREATE pills with the source under each ("/item/cogs", "cogs sync", "/owner/private" for old rows) | pass | pass (the table scrolls inside its box) | `104-item-settings-log-gaps/1366-edit-logs.png`, `390-edit-logs.png` |
| Deleted values struck through with "→ deleted" (RTS, COGS, CEO COGS): 4 cells | pass | pass | same |
| "system (cogs sync)" as the user; "— lahat ng page —" for global COGS; old rows as before | pass | pass | same |
| Source filter beside the others (it wraps to two columns on a phone) | pass | pass | same |
| Page width = viewport width (1366/1366, 390/390); 0 overflowing elements; the table is 824 px inside a 340 px scroll box at 390 | pass | pass | measured |

The broken logo in the screenshots comes from opening the page from a file. It is not part of this change.

## Story changes

- The spec brought no stories, so I wrote S-104 into `qa/stories.md` straight from its "Done when", without the story-writer round. This was a small bounded brief, started on the instruction to start at once.
- I added S-104.2 (a delete of an empty field writes nothing) and S-104.4 (`clean-redundant`, the third delete on `/item/cogs`). The brief doesn't name them, but without them an empty delete would fill the log with no-op rows, or a bulk delete would leave no trace.
- The S-104.1 promo case uses a row without RTS, so the whole row is deleted. Deleting promo while RTS is set fails in the existing code (NOT NULL column, see Accepted findings). That isn't this spec's to change.

## Review

One `skeptic-reviewer` pass, standard depth (medium tier), on `main..66cb2559`. **No blocker and no major.** It confirmed:
- all four paths log, in the same transaction as the write;
- values are saved as before;
- no XSS: every value is escaped, and the source label goes through a fixed map;
- `rtsSetByMap` is unaffected: it reads `new_rts_pct IS NOT NULL` only, and the new rows never set it.

Its minors are accepted in `TODO.md`, none on an untrusted path with a realistic failure. There was no fix loop.

## Accepted findings

All in `TODO.md` under "104: item settings log gaps", each with its reason:
- **`/item/cogs` routes have no `auth` middleware** (`routes/web.php:686-690`). This was already true before the spec. Anyone who can load a page can post a COGS edit. Since this change the edit is at least logged, as "— anonymous —". This is the most important item for the owner: suggestion to put these routes behind `web, auth, allowed_ip` with a role check.
- **The promo delete on `/owner/private` while RTS is still set** writes NULL to the NOT NULL `promo` column. On MySQL strict mode that is a 500, as before. The log row rolls back with it.
- The `cogs` → `cogs_ceo` mirror and the `mode_cod_int` backfill write no log row (no value changes, see the table).
- `rtsSetByMap` keeps crediting the first setter after a delete and a re-set to the same value. A clear through rts = 0 already behaved this way.
- Cosmetic inconsistencies:
  - a NULL-valued `/item/cogs` delete logs "—";
  - a same-price edit logs a no-op row;
  - a delete without a price tag logs one row per price row.
- Untested plain paths:
  - `clean-redundant` preview;
  - the transaction rollback;
  - a CEO-only COGS delete;
  - the Marketing - OIC view of a delete row.

## Conflicts

- The kit's "run story-writer first" step was replaced by stories written from the spec (see Story changes).
- Nothing else conflicts between the spec and the project rules.

## Commits

| Commit | What |
|---|---|
| `1f41f632` | docs: brief for item settings log gaps (spec 104) |
| `66cb2559` | feat: log deletes, /item/cogs edits and cogs sync in the item settings edit log (spec 104) |
| (this commit) | docs: result, screenshots and accepted findings for item settings log gaps (spec 104) |

## Merge danger

- **One migration (adds a nullable column).** Release it only with the owner's yes. The code runs before it too.
- **Behaviour:** the three paths now run their write and the log insert in one transaction. If the log insert fails, the delete or edit fails with it, instead of happening without a trace. Values themselves are computed and saved as before.
- `/item/cogs` and the sync write `page_name = ''` into the log, not NULL (NOT NULL column).
