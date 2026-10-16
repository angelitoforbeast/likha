# Result 037: Marketing OICs get the full supplier table on /item, and every edit there is logged

Branch `feat/037-item-suppliers-moic-logs`, from `develop` at 0bbe481.

## Commits

- `docs: spec 037 …`: the brief, saved verbatim.
- `feat: marketing OIC gets supplier work on /item …` (Part A, access)
- `feat: item_edit_logs records every edit on /item …` (Part B, logs)
- `docs: result of spec 037`
- `feat: marketing OIC sees the supplier and quote columns on /item/photo (spec 037, amendment 1)`

## Part A: access

There is one check, `ItemController::canSupplierWork()` (`app/Http/Controllers/ItemController.php:39`). It is true for the roles `CEO` and `Marketing - OIC`, using the same normalisation as `getNormalizedRole()`. The endpoints use it. The views get `$canSupplierWork = $effectiveIsCEO || $isMarketingOIC` (`:100`), and the script gets the same value as `canSupplierWork` (`index.blade.php:1423`). `$effectiveIsCEO` is not widened. For other roles the responses are unchanged: the same empty JSON, or the same 403 body `{"ok":false,"error":"CEO lang"}`.

### Gate inventory

| Where | What it gates | Now |
|---|---|---|
| `ItemController.php:101` | `$layoutSuppliers` (table with suppliers) | moved: `$canSupplierWork` |
| `ItemController.php:210` | `suppliers()` data | moved |
| `ItemController.php:257` | `worklist()` data | moved |
| `ItemController.php:429` | `stock()` `item_value_ceo` | kept CEO in CEO view: CEO unit cost |
| `ItemController.php:443` | `quotes()` data | moved |
| `ItemController.php:463` | `quoteSave()` 403 | moved |
| `ItemController.php:538` | `categorySave()` 403 | moved |
| `ItemController.php:613` | `supplySettingsSave()` 403 | moved |
| `ItemController.php:742` | `quoteDelete()` 403 | moved |
| `ItemController.php:861` | `/item/photo` supplier / quote columns (was `$isCEO`) | moved by amendment 1: `canSupplierWork` |
| `_agg_cells.blade.php:160` | ITEM VAL. (CEO) cell | kept: `item_value_ceo` |
| `_agg_cells.blade.php:179` | category select | moved |
| `_agg_cells.blade.php:238` | lead / palugit ✎ | moved |
| `_il_expand.blade.php:34` | lead time ✎ (details) | moved |
| `_il_expand.blade.php:90` | "CEO value" per piece | kept: CEO unit cost |
| `_il_expand.blade.php:161` | category select (details) | moved |
| `_il_expand.blade.php:202` | sourcing info, PO supplier lines, quotes add / edit / delete | moved |
| `_il_expand.blade.php:352, 356, 367, 404` | Claude / CEO action columns and modal (`$isCEO`) | kept: CEO note / Claude action |
| `_il_expand.blade.php:396` | Edit CEO Unit Cost ✎ | kept |
| `_table_old.blade.php:57, 112, 145` | empty-list row, worklist info, supplier + unit cost lines | moved |
| `_table_old.blade.php:65`, `_table_order.blade.php:43`, `_table_sales.blade.php:56`, `_table_suppliers.blade.php:173` | category-empty row ignores `?list=` (JS `effectiveIsCeo`) | moved: JS `canSupplierWork` |
| `_table_order.blade.php:37`, `_table_sales.blade.php:50`, `_table_suppliers.blade.php:165` | empty sourcing-list row | moved |
| `_table_order.blade.php:96, 165` | "≈ ₱" order cost line and total | kept CEO-only: it falls back to the CEO item value (`ilPieceCost`, `index.blade.php:3676`) |
| `_table_suppliers.blade.php:225` | worklist info in the supplier table | moved |
| `index.blade.php:605` | 📅 Daily | kept |
| `index.blade.php:614` | 👁 View toggle | kept |
| `index.blade.php:698` | "Table with suppliers" / "Original table" header links | moved |
| `index.blade.php:717` | ⚙ Columns, 🚫 Excluded | kept: column settings |
| `index.blade.php:733` | 📜 Logs, 🔄 Primary Items (already CEO view or Marketing - OIC) | unchanged; "🧾 Item logs" added here |
| `index.blade.php:757` | 📸 Snapshots | kept |
| `index.blade.php:810` | Sourcing chips | moved |
| `index.blade.php:965, 1622, 1654, 2139, 3761` | Claude / CEO action modal, columns, script (`$isCEO`) | kept |
| `index.blade.php:1757, 1760, 1776, 3929, 3942` | `?list=` / `?layout=original` in the address, loading the worklist | moved |
| `index.blade.php:3514, 3535` | supplier name in "Next step", "🔴 Find a supplier" | moved |
| `index.blade.php:3566, 3573` | `ilQtyCost` / `ilQtyCostAmount` (CEO item value fallback) | kept CEO-only |
| `index.blade.php:3676` | `ilPieceCost` uses `item_value_ceo` | kept |
| `index.blade.php:3810, 3816` | `worklistItem` / `worklistKeep` | moved |
| `index.blade.php:4020` | `loadItemSuppliers()` / `loadItemQuotes()` on init | moved |
| `index.blade.php:1095, 2195, 2250` | edit modal's CEO unit cost field (`isCeoView`) | kept |

## Amendment 1

| Change | Done |
|---|---|
| `/item/photo` supplier and quote columns use `canSupplierWork()`, so Marketing - OIC sees them | `photoForm()` now passes `canSupplierWork` instead of `isCEO` (`ItemController.php:861`). `photo.blade.php:64, 65, 87, 294` (the two column headers, the cells, and the `/item/suppliers` + `/item/quotes` fetch) use it. The page shows no CEO item value or CEO unit cost, so nothing on it stays CEO-only. Test S-37A.5. |
| The "≈ ₱" order-cost line stays CEO-only | Unchanged (`_table_order.blade.php:96, 165`, `index.blade.php:3566, 3573`). |

## Part B: edit logs

**Migration** `database/migrations/2026_10_16_300000_create_item_edit_logs_table.php` (additive, guarded by `Schema::hasTable`). It creates `item_edit_logs` with: `id`, `user_id`, `role` (a snapshot), `action`, `item_key`, `item_name`, `page_name`, `field`, `old_value` (text), `new_value` (text) and `created_at` (app timezone Asia/Manila). Indexes are on `created_at` and on `(item_key, created_at)`. `item_name` is an extra column that the brief did not list: photos are stored per variant name (`1 x LAMP`), and the page shows the name.

**Writer** `app/Support/ItemEditLog::write()`. It writes one row per field whose trimmed value changed (null and blank count as the same) and nothing when no field changed. Text is cut to 500 characters. Files are logged by name only. Every error is caught and logged as a warning, so a failed log write never breaks the edit.

| Action | Route / method | Fields |
|---|---|---|
| `quote.save` | `POST /item/quotes`, `quoteSave` | `price · <supplier>` (2 decimals), `moq · …`, `link · …`, `note · …`, `photo · …` (file name, only when a new photo is uploaded) |
| `quote.delete` | `POST /item/quotes/delete`, `quoteDelete` | the same fields, old → empty |
| `category.set` | `POST /item/category`, `categorySave` | `category` (category name, or empty for "No category") |
| `supply_settings.save` | `POST /item/supply-settings`, `supplySettingsSave` | `lead_time_days`, `palugit` (`palugit_override`; empty = lifecycle default) |
| `image.upload` | `POST /item/image`, `uploadImage` | `photo` (file name) |
| `photo.save` | `POST /item/photo`, `photoStore` | `photo` (file name) |
| `image.delete` | `POST /item/image/delete`, `deleteImage` | `photo` → empty |

`item_supplier_quote_history` is kept as it was. Quotes write both tables.

### Other write routes reachable from /item

| Route | Called by | Logged? |
|---|---|---|
| `POST /owner/private/item-setting` (+ `/delete`) | the row's "Change" / ✎ modal (RTS %, promo, item value, CEO item value) | yes, already: `page_item_settings_log`, on `/owner/private/edit-logs` |
| `POST /owner/private/action` | Action note | yes, already: `page_day_action_logs` |
| `POST /owner/private/claude-action`, `/ceo-action` | CEO-only Claude / CEO note modal | yes, already: the service's own log table. Not copied into `item_edit_logs`, because Marketing - OIC can read that table and these notes are CEO-only |
| `POST /owner/column-settings/save` | ⚙ Columns (CEO) | no. This is page configuration, not an item edit; left as it is |
| `POST /owner/private/snapshots` | 📸 Save Snapshot (CEO) | no. It freezes a view and changes no item data |
| `POST /owner/private/refresh-primary-items` | 🔄 Primary Items | no. It recomputes data and is not an edit |
| `/item/cogs/*` (`ItemCogsController`) | the separate `/item/cogs` page; /item does not link to it | out of reach of /item; not touched |

### Logs page

`GET /item/edit-logs` (route `item.edit-logs`, `ItemController::editLogs`) returns 200 for CEO and Marketing - OIC and 404 for everyone else. It lists the newest rows first, 50 per page, with filters for item (part of the name), user, action, from and to. Each row shows the time, user name and role, action, item, field and old → new. It is linked from /item's header as "🧾 Item logs", next to "📜 Logs". The `page_item_settings_log` rows are **not** merged into this list: they have a different shape (one wide row with old/new RTS, promo, item value and CEO item value). The page links to `/owner/private/edit-logs` instead.

## Tests

Case table (stories in `qa/stories.md`, slice 037):

| Case | Test | Result |
|---|---|---|
| S-37A.1 | `MoicSupplierAccessTest::test_S_37A_1_marketing_oic_reads_suppliers_quotes_and_worklist_plain_marketing_does_not` | pass |
| S-37A.2 | `MoicSupplierAccessTest::test_S_37A_2_marketing_oic_edits_quotes_category_and_settings_plain_marketing_gets_403` | pass |
| S-37A.3 | `MoicSupplierAccessTest::test_S_37A_3_marketing_oic_sees_the_supplier_table_but_not_the_ceo_profit_view` | pass |
| S-37A.4 | `StockEndpointTest::test_item_value_ceo_only_for_ceo_viewing_as_ceo` (OIC case added) | pass |
| S-37A.5 | `MoicSupplierAccessTest::test_S_37A_5_marketing_oic_sees_the_supplier_and_quote_columns_on_the_photo_page` | pass |
| S-37B.1 | `ItemEditLogTest::test_S_37B_1_quote_save_and_delete_log_each_changed_field_and_nothing_when_unchanged` | pass |
| S-37B.2 | `ItemEditLogTest::test_S_37B_2_category_and_supply_settings_log_old_and_new` | pass |
| S-37B.3 | `ItemEditLogTest::test_S_37B_3_item_photo_upload_replace_and_delete_log_file_names_only` | pass |
| S-37B.4 | `ItemEditLogTest::test_S_37B_4_a_failing_log_write_never_breaks_the_edit` | pass |
| S-37B.5 | `ItemEditLogTest::test_S_37B_5_the_logs_page_is_for_ceo_and_marketing_oic_newest_first_with_filters` | pass |
| S-37B.6 | `ItemEditLogTest::test_S_37B_6_the_item_page_links_to_the_logs_page_for_supplier_work_only` | pass |

Red runs: S-37A.1 failed with `Failed asserting that null is identical to 'Acme'`, S-37A.2 with `Failed asserting that 403 is identical to 200`, S-37A.3 with an ErrorException because `canSupplierWork` was missing from the view data, and S-37A.5 with `Marketing - OIC: Supplier / Presyo` (column absent). S-37B.1–3 failed with `Failed asserting that two arrays are identical`, S-37B.5 with `Failed asserting that 404 is identical to 200`, and S-37B.6 with `RouteNotFoundException`.

Full suite after amendment 1: `Tests: 1203, Assertions: 15682, Errors: 1, Failures: 1, Skipped: 3`. The two that fail are the known `ExampleTest::test_the_application_returns_a_successful_response` and `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. Lint (`php -l`) is clean on every changed PHP file.

### Story changes (existing tests that pinned "Marketing - OIC gets nothing")

- `SuppliersGroupTest`: `marketing_oic` is removed from `NON_CEO_VIEWS` (S-24.1/2/3 keep plain Marketing and CEO-as-Marketing). S-18.3 now checks plain Marketing only. The `render()` helper passes `canSupplierWork`. The two `marketing_oic` base hashes are re-pinned. The CEO, CEO-as-Marketing and Marketing hashes are **not** re-pinned: the new `undoSupplierWorkGate()` takes out the only changes (the `canSupplierWork` flag line, the JS gate rename `effectiveIsCeo` → `canSupplierWork`, and the "🧾 Item logs" link), and the old base hashes still match. So nothing else changed for those viewers.
- `ItemPageTest`: the `render()` helper passes `canSupplierWork`. The JS gate strings were updated (`ilSupplier`, "Find a supplier", the category-empty row). The `_table_old` sha1 is re-pinned because the gate rename is its only change.
- `ItemEditTest::test_non_ceo_gets_403…` and `WorklistTest::test_non_ceo_roles_get_the_empty_shape_or_404`: Marketing - OIC is removed from the "nothing" list. The OIC is now covered in `MoicSupplierAccessTest`.
- I added the migration to `ItemTestCase::migrationPaths()`.

## Notes

- The worktree's `vendor/` was a symlink to the main checkout's `vendor/`. Composer's base dir then pointed at the main checkout, so the tests loaded the **main checkout's** `app/` and passed against unchanged code. I replaced the symlink with a copy of the folder. Worktrees made the same way elsewhere have the same trap.
- The browser check was not run: this session has no browser tools.
- Kit hook tests (`node --test 'test/hooks/*.test.mjs'`) were not part of this change.

## What the owner should click after release

1. Run `php artisan migrate --force` (one new table).
2. Sign in as a Marketing - OIC and open `/item`. Check that the "Sourcing:" chips show. Then click "🗂 Old view": it opens the table with suppliers, quotes and prices, and "🗂 Original table" / "🏷 Table with suppliers" switch between the two tables.
3. Edit a quote price, and set a category and a lead time.
4. Click "🧾 Item logs" (`/item/edit-logs`): each change shows who made it, when, and old → new.
5. As Marketing - OIC, open `/item/photo`: the "Supplier / Presyo" and "Suppliers (quote)" columns show.
6. As plain Marketing, `/item` and `/item/photo` look as before, and `/item/edit-logs` is a 404.

## What the brief got wrong or left open

- `/item/photo` was not in the brief. Amendment 1 opened its supplier and quote columns to Marketing - OIC.
- The "≈ ₱" order-cost line in "To order" stays CEO-only, because it falls back to the CEO item value (confirmed by amendment 1).
- `page_item_settings_log` cannot be merged into the new list as one simple query (different row shape, and it carries the CEO item value). The page links to it instead.

## Suggestions for the owner (not done)

- None.

