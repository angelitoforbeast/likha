# Plan 004: Restock target by item lifecycle on /item

Spec: `docs/specs/004-lifecycle-restock.md`. Branch `feat/lifecycle-restock`.

All tasks run one after another on this branch (no worktrees: `git worktree` isn't in the task's allowlist, and T3/T4 share `ItemStockService` and the tests harness).

| # | Task | Tier | Side | Parallel | Depends |
|---|---|---|---|---|---|
| T1 | Characterisation test of `JntSupplyController::classifyLifecycle` / `lifecycleBadge` on a fixed table (green before any change), then move both into `App\Support\ItemLifecycle` with the default thresholds; the controller's private methods delegate. Same table green against both. | medium | backend | — | — |
| T2 | Migrations: `supply_item_settings.palugit_override`; `supply_settings` rows of group `item_palugit` (insert only missing); `category` hidden in the saved `owner_private` column config. `setting-kv` 0–255 check for `item_palugit`; `POST /item/supply-settings` writes / clears `palugit_override`. `ItemTestCase` migration paths. Tests: migrations, CEO gate and 422 table on `setting-kv`, override write/clear. | medium | backend | — | — |
| T3 | `ItemStockService`: lifecycle inputs (1 new grouped query on [end−27, end] + the first-date query), 7-day units folded into the demand query, palugit settings, `normal` / `lugi` sets, `gated`, notes, override precedence; top-level per-set fields removed and 003 tests read `normal.*`. Tests: every §5 row and the DOI example at the endpoint. | medium | backend | — | T1, T2 |
| T4 | Page: LIFECYCLE column (catalog, `DEFAULT_VISIBLE`, `defaultCols`, cells, sort, tooltips), set choice from `A.proj_pct_7d`, DOI / BENTA/ARAW / palugit line / I-ORDER / order-by from the set, the two grey texts, palugit editor blank = default, CATEGORY off by default and the selector tied to its visibility. `ItemPageTest` assertions. `npm run build`. | medium | frontend | — | T3 |

Per task: Red → Green → Blue, one-line red run reported, one `feat:`/`refactor:` commit per task (T1 is a `test:` commit then a `refactor:` commit). Developers on sonnet; after each task `skeptic-reviewer` at standard depth (sonnet) on that task's diff. Blocker/major → fix loop, max 2. Minors on untrusted paths fixed in one final wave; the rest to `TODO.md` with a reason.

After T4: full `php.bat artisan test`, `php.bat -l` on every changed PHP file, `npm run build`, the result notes (evidence, rulings, SQL for EXPLAIN, deploy notes, merge danger). The browser check is skipped: running the app reads the local `.env` database (the task brief, §7).

Files touched:
- **New:** `app/Support/ItemLifecycle.php`, 3 migrations, `tests/Feature/Item/JntSupplyLifecycleTest.php` (or `tests/Unit/`), `tests/Feature/Item/LifecycleStockTest.php`.
- **Changed:** `app/Http/Controllers/JntSupplyController.php` (two private method bodies delegate; `saveSupplySetting` one range check for `item_palugit`), `app/Services/ItemStockService.php`, `app/Http/Controllers/ItemController.php` (`supplySettingsSave`), `app/Http/Controllers/OwnerColumnSettingsController.php` (`CATALOG`, `DEFAULT_VISIBLE`), `resources/views/item/index.blade.php`, `resources/views/item/_agg_cells.blade.php`, `tests/Feature/Item/ItemTestCase.php`, `StockEndpointTest.php`, `ItemEditTest.php`, `ItemPageTest.php`, `TODO.md`, the task's result notes.

Untouched: `/jnt/supply` output, Supply Finance, PO data, `HoldService`, `item-summary`.
