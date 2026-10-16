# Brief 037: Marketing OICs get the full supplier table on /item, and every edit there is logged

## Why

`/item` (`ItemController`, views `resources/views/item/*`) shows the "Table with suppliers" (`?layout=old` / `?layout=suppliers`, `_table_suppliers.blade.php`, `_suppliers_js`), supplier unit costs (`GET /item/suppliers`), supplier quotes (`/item/quotes`, save, delete), categories (`POST /item/category`), lead time and safety days (`POST /item/supply-settings`) and the sourcing worklists (`GET /item/worklist`) to the CEO only, on the data layer and in the view (`$effectiveIsCEO`, `$layoutSuppliers`, `getNormalizedRole() !== 'CEO'` checks). The owner wants the Marketing OICs to see and use all of it, and wants every edit on the page traceable: who changed what, when, from what to what (for example a supplier price).

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| Role `Marketing - OIC` (same normalisation as the existing role checks) gets everything listed above: view and edit (quotes save/delete, category, supply settings), the supplier table layout and its link in the header, and the worklists | Owner, 2026-10-16: full access ("c") |
| What stays CEO-only, unchanged: the CEO view (`view_as` toggle, `cogs_ceo` profit, CEO unit cost / `item_value_ceo`, CEO column and modal field), Daily, Snapshots, the CEO note / Claude action modal, column settings | These are the owner's private profit view, not supplier work |
| Plain `Marketing` and every other role: unchanged | Not asked |
| Implement as one access check (e.g. `canSupplierWork()`: CEO in CEO view, or Marketing - OIC) used by the controller endpoints and passed to the views; do not widen `$effectiveIsCEO` | Keeps the CEO-only parts CEO-only |
| Every write reachable from /item is logged in one new table, with old and new values; logs are append-only | Owner: "kunwari inedit yung price etc." |

## Part A: access (commit on its own)

- Inventory, in the result, every CEO gate in `ItemController` and the item views (file:line), and for each: moved to the new check, or kept CEO-only (and why, by the table above).
- Endpoints: `suppliers`, `worklist`, `quotes`, `quoteSave`, `quoteDelete`, `categorySave`, `supplySettingsSave` allow the new check; everyone else keeps today's response (empty list or 403).
- Views: the supplier table layout, its header link and the sourcing worklist chips show for the new check.

## Part B: edit logs (commit on its own)

- New table `item_edit_logs` (additive migration): id, user_id, role snapshot, action (e.g. `quote.save`, `quote.delete`, `category.set`, `supply_settings.save`, `image.upload`, `image.delete`, `photo.save`), item key, page name if any, field, old_value, new_value (text, short; no file data, only file names), created_at (Manila, like the rest of the app). One row per changed field; nothing when the value did not change.
- Write it in every write route reachable from /item that is not already logged: at least the quote, category, supply-settings, image and photo routes; inventory any other (e.g. what the row's "Change" button calls) and say whether it is already logged (`page_item_settings_log` covers RTS % and item value; `item_supplier_quote_history` exists for quotes: keep it, add the new log too). Never throw into the request.
- A logs page for CEO and Marketing - OIC (404 for others), linked from /item's header next to the existing "Logs": newest first, filters by item, user, action and date range, paginated, showing user name, action, item, field, old → new, time. Include the existing `page_item_settings_log` rows in the same list if it stays simple (one query or a union); otherwise link to the existing edit-logs page and say so.

## Tests

Marketing - OIC: 200 with data on suppliers, worklist and quotes; can save and delete a quote, set a category and supply settings; sees the supplier table layout and link; does not see CEO-only parts (view toggle, Daily, Snapshots, CEO unit cost, `item_value_ceo` in `/item/stock`). Plain Marketing and another role: unchanged (empty / 403 / hidden). Each logged action writes the right row (old and new value; no row when unchanged); the logs page: 200 for CEO and Marketing - OIC, 404 for Marketing; filters work. Red first where natural. The whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

Supply Finance pages (`/finance/supply`); changing prices or any data; the staff feed; backfill of old edits.

## Final message

Commits; the gate inventory with file:line; the migration; the log actions written and where; the logs page URL; test lines and suite result; what the owner should click after release; anything the brief got wrong.
