# Plan 003: Item value on item rows, stock gauge, DOI, order quantity, category

Spec: `docs/specs/003-stock-doi-category.md`. Branch `feat/stock-doi-category`.

All tasks run one after another on this branch. There are no worktrees, because `git worktree` isn't in the task's allowlist and the tasks share `ItemController`, `routes/web.php` and the Blade file.

| # | Task | Tier | Side | Parallel | Depends |
|---|---|---|---|---|---|
| T1 | `ItemBaseKey` helper and its unit test. Three migrations (`item_categories` with the 8 seeds, `item_category_assignments`, `app_settings.item_stock_start`), `ItemCategory` / `ItemCategoryAssignment` models, and the `ItemTestCase` harness (`submission_time`, migration paths). | medium | backend | — | — |
| T2 | `GET /item/stock` (`ItemStockService` + `ItemController::stock` + route). Demand, HOLD units, left, received, incoming, lead/safety, category, derived numbers, and the `values` map (`cogs` / `cogs_ceo` with the CEO gate). `StockEndpointTest` covers examples A–D, the values gate, the earliest-waybill rule and `stock_ready`. | medium | backend | — | T1 |
| T3 | `POST /item/category` and `POST /item/supply-settings`: CEO-only, validated, throttled. `ItemEditTest` covers the 403s with nothing written, the 422s, case-insensitive reuse, clearing, and the settings row match and insert. | medium | backend | — | T1 |
| T4 | `config/item_categories.php` and the `items:suggest-categories` command (dry run / `--apply`). `SuggestCategoriesTest`. | medium | backend | — | T1 |
| T5 | Page columns. Six columns in the catalog (`CATALOG`, `DEFAULT_VISIBLE`), `defaultCols()`, `_agg_cells` and the blank page-row cell. Item-row ITEM VAL. / ITEM VAL. (CEO), `loadStock()` in parallel, sort cases, tooltips and colours. `ItemPageTest` assertions. `npm run build`. | medium | frontend | — | T2 |
| T6 | Page: category filter (all roles, AND-ed with the chips), and CEO inline edits (category select with "+ bagong category"; lead/palugit inputs) that re-fetch `/item/stock` after a save. `ItemPageTest` assertions (CEO-only controls). `npm run build`. | medium | frontend | — | T3, T5 |

Per task: the developer works Red → Green → Blue, reports the red line, and makes one `feat:` commit per task. The developers are `backend-developer` / `frontend-developer` on sonnet. After each task, `skeptic-reviewer` reviews at standard depth (sonnet) on that task's diff. Only blocker/major findings start a fix loop, with at most 2 loops. Minors on untrusted paths get fixed in one final wave; the rest go to `TODO.md` with a reason.

After T6:
- full `php.bat artisan test`
- `php.bat -l` on every changed PHP file
- `npm run build`
- The result notes (evidence, rulings, SQL for EXPLAIN, deploy notes, merge danger)

The browser check is skipped, because running the app reads the local `.env` database (the task brief, §7).

Files touched:
- **New:** `app/Support/ItemBaseKey.php`, `app/Services/ItemStockService.php`, `app/Models/ItemCategory.php`, `app/Models/ItemCategoryAssignment.php`, `app/Console/Commands/SuggestItemCategories.php`, `config/item_categories.php`, the 3 migrations, and the tests under `tests/Feature/Item/` and `tests/Unit/`.
- **Changed:** `app/Http/Controllers/ItemController.php` (3 methods), `routes/web.php` (3 lines), `app/Http/Controllers/OwnerColumnSettingsController.php` (`CATALOG`, `DEFAULT_VISIBLE`), `resources/views/item/index.blade.php`, `resources/views/item/_agg_cells.blade.php`, `tests/Feature/Item/ItemTestCase.php`, `tests/Feature/Item/ItemPageTest.php`, `TODO.md`, and the task's result notes.

Untouched: `/jnt/supply`, Supply Finance, `HoldService`, `OwnerPrivateController`, the `item-summary` output (no cache bump).
