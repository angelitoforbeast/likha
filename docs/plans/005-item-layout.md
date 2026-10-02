# Plan 005: /item layout that fits the screen, restock decision first

Spec: `docs/specs/005-item-layout.md`. Branch `feat/item-layout` (from `feat/lifecycle-restock` at `762d4ae`).

All tasks run one after another on this branch: T2–T5 share `resources/views/item/index.blade.php`, and `git worktree` isn't in the handoff's allowlist.

| # | Task | Tier | Side | Parallel | Depends |
|---|---|---|---|---|---|
| T1 | `ItemStockService::restockSet` adds `velocity_days` to each set. `ItemController::index` passes `layoutOld` (`layout === 'old'` only). Tests: `velocity_days` rows in `LifecycleStockTest`; HTTP `GET /item` default / `?layout=old` / `?layout=OLD` (needs `ads_manager_reports` and fee tables in the test). | medium | backend | — | — |
| T2 | Split the view: today's table block moves verbatim to `item/_table_old.blade.php`, rendered for `layoutOld`; empty `_table_new` shell for the default; `layoutOld` in Alpine; `load()` keeps `layout=old`; "Lumang view" / "Bagong view" link. `ItemPageTest`'s render helper takes a layout; existing assertions on old-table strings use the old render. Characterisation first: the old render's table markers before the move. | medium | frontend | — | T1 |
| T3 | New main row: headers with sort keys, ITEM cell (sticky, chip, CEO supplier/quote block, warnings), LIFECYCLE Taglish badges, STOCK / PAPARATING, BENTA/ARAW with `velocity_days` tooltip, AABOT PA? states + lead line + CEO ✎ editor, I-ORDER qty / pill / cost / reason, KITA NGAYON, KITA % grid, ADS with BE, ACTION, ›. Composite visibility from `cols` (spec §4). New-layout default urgency sort. New formatters (−₱, "Okt 24", days). Colour and font rules. Markup assertions. | medium | frontend | — | T2 |
| T4 | Expanded block: item definition grid (puhunan, RTS/DEL/INT, TCPR, period profit, NP/O(1M), category, reason line, Change, Copy) and per-page cards (every visible page field without fills, ✎ modals, warnings, breakdown link, Campaigns toggle with the shared panel unchanged). Markup assertions. | medium | frontend | — | T3 |
| T5 | TOTAL (nakikita), chip rename, toolbar wrap + fixed-width Expand/Hide all, breakpoints (1,440 / 1,366 / 1,100) and the narrow-screen cards. Markup assertions. `npm run build`. | medium | frontend | — | T4 |

Per task: Red → Green → Blue, one-line red run reported, one `feat:` commit per task (T2: a `test:` characterisation commit, then `refactor:` for the verbatim move, then `feat:` for the switch). Developers on sonnet; after each task `skeptic-reviewer` at standard depth (sonnet) on that task's diff. Blocker/major → fix loop, at most 2. Minors on untrusted paths fixed in one final wave; the rest go to `TODO.md` with a reason.

After T5: full `php.bat artisan test`, `php.bat -l` on every changed PHP file, `npm run build`, RESULT.md (evidence, rulings, the browser checklist for 1,366 / 1,920 / phone, deploy notes with 004, merge danger and revert). The browser check is skipped: running the app reads the local `.env` database (handoff §7).

Files touched:
- **New:** `resources/views/item/_table_old.blade.php` (verbatim move), `resources/views/item/_table_new.blade.php`, possibly `resources/views/item/_expand_new.blade.php`.
- **Changed:** `app/Services/ItemStockService.php` (one field), `app/Http/Controllers/ItemController.php` (`index`), `resources/views/item/index.blade.php` (Alpine helpers, toolbar, include switch), `tests/Feature/Item/ItemPageTest.php`, `tests/Feature/Item/LifecycleStockTest.php`, a new `tests/Feature/Item/ItemLayoutTest.php` (HTTP), `TODO.md`, handoff RESULT/README.
- **Untouched:** `/owner/private` and every `resources/views/owner/*` file (the campaigns panel is included as is), `OwnerColumnSettingsController` (catalog and defaults), `/jnt/supply`, Supply Finance, PO data, `item-summary`, `_agg_cells.blade.php` (used by the old table only). No migration.
