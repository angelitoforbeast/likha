# Handoff 004: Restock target by item lifecycle on /item

**From:** Mira · **To:** Claude Code · **Approver:** Busing (Angelito Forbes, CEO)
**Project:** Likha AI Tech (likhaaitech.com) · **Weight:** bounded-to-architectural · **Shape:** change · **Risk tier:** medium
**Stack:** Laravel 12, PHP 8.2, MySQL in production, Blade + Alpine.js. Base branch `develop` at `88cc0bc` (handoff 003 is live).

## 1. Context

Handoff 003 (read `handoff/003-stock-doi-category/HANDOFF.md` and `RESULT.md` first) added STOCK, PAPARATING, BENTA/ARAW, DOI and I-ORDER to `/item`, computed in `app/Services/ItemStockService.php` and served by `GET /item/stock`. Every item uses the same palugit (safety days, default 3).

Busing: "new item ba to, winning ba to, matagal na bang winning to ... dun magbabase dami ng irerestock ... alangan namang yung bagong kakarun lang, same DOI dapat dun sa matagal na nagruun/winning?" A new item must not get the same stock target as one that has been winning for a long time.

Likha already classifies items by lifecycle in `JntSupplyController::classifyLifecycle` (~line 52) for `/jnt/supply`: New, Scaling, Consistent, Active, Declining, Phasing Out, Dormant, with windows and thresholds from request params with defaults (`recent_days` 14, `new_item_days` 30, `long_running_days` 90, `scale_threshold` 1.5, `decline_threshold` 0.5; ~lines 185-213, first-order date ~335, per-item computation ~698-724) and a per-item `supply_item_settings.lifecycle_override`. It is not shown on `/item` and not used in any order quantity.

Handoff 003 also added a product CATEGORY column and filter. Busing didn't mean product categories; that column and filter are to be hidden (data and tables stay).

## 2. Goal

Change. `/item` shows each item's lifecycle, and the order quantity, DOI colour and order-by use a palugit that depends on the lifecycle and on whether the item is profitable. The product CATEGORY column and its filter are hidden by default.

## 3. Decisions already made

Settled with Busing on 2026-10-02 ("1. oo 2. oo 3. mabenta at kumikita"), or by Mira within that yes. Don't reopen unless something is actually broken.

| Topic | Decision |
|---|---|
| Lifecycle source | The existing classification and thresholds, same results as `/jnt/supply` for the same item and as-of date (as-of = the `/item` end date). `lifecycle_override` wins when set. Move the classification into one shared place used by both pages, or call it from both; `/jnt/supply`'s output must not change (prove it with a test). |
| Palugit per lifecycle (defaults) | New 3 · Scaling 14 · Consistent 10 · Active 7 · Declining 0 · Phasing Out and Dormant: HOLD only (no lead, no palugit cover). |
| Velocity for Scaling | BENTA/ARAW for a Scaling item = units of the last 7 days ÷ 7 (rising items). All other lifecycles keep 003's 14-day rule. |
| "Winning" = mabenta at kumikita | An item whose lifecycle is Scaling or Consistent but is not profitable uses the "lugi" palugit 3 and the 14-day velocity, and its lifecycle shows with a "lugi" marker (e.g. "📈 Scaling · lugi"). Profitable = the item row's PROF.%(7D) on `/item` is above 0. No profit data (e.g. no running page) = no gate: use the lifecycle as is. |
| Where the profit gate is applied | Profit is only known in the browser (from `item-summary`). So `GET /item/stock` returns, per base key, the lifecycle and two complete result sets: `normal` (lifecycle palugit and velocity) and `lugi` (palugit 3, 14-day velocity), each with units_per_day, palugit, doi, order_qty, order_by, colour. The page only chooses which set to show from the row's PROF.%(7D). All maths stays in PHP and is tested. |
| Phasing Out / Dormant | order_qty = HOLD units (ceil), DOI shows "walang benta" in grey (not red), order-by "—". |
| Near-zero sellers | For any lifecycle, when units/day < 0.5, DOI shows "halos walang benta" in grey instead of a day count (fixes "kulang 7224 araw"); order qty still follows the formula for its lifecycle. |
| Per-item palugit edit (from 003) | An explicit per-item palugit (a `supply_item_settings` row whose safety value was set through `/item`) overrides the lifecycle default. Find a way to tell "set via /item" from the old default without changing existing columns (e.g. a new nullable column `palugit_override` on `supply_item_settings` is allowed, additive); explain in the plan. Lead time per item stays as in 003. |
| Palugit defaults editable | Stored as CEO-editable settings, reusing the existing `supply_settings` key-value store and its editor if it already lists keys generically; otherwise a small CEO-only settings block on `/item`. Explain which in the plan. |
| LIFECYCLE column | New column on `/item` (catalog, defaultCols, page cell, item-row cell, sortable), visible by default for CEO, opt-in for Marketing like 003's columns. Badge like `/jnt/supply` (emoji + label), one-line Taglish tooltip saying what the lifecycle means and the palugit it uses. |
| Product category | Hide: CATEGORY column off by default for every role, and the Category selector only shows when the CATEGORY column is visible. Keep the tables, data, routes and the command. |
| Formula | Unchanged from 003 apart from the palugit and velocity rules above: `order_qty = max(0, ceil(HOLD + v × (lead + palugit) − incoming − stock))`; DOI = `(stock + incoming − HOLD) ÷ v`; colour thresholds use lead and lead + palugit. |

## 4. Requirements

1. Lifecycle per base item in `GET /item/stock`, with `lifecycle`, `lifecycle_label`, `lifecycle_auto`, and the `normal` and `lugi` result sets described above. Keep the existing top-level fields working (the page may switch to the sets; don't leave dead fields the page no longer reads unless removing them is trivial).
2. First-order date and the recent/previous 14-day units per base item from indexed columns (`ts_date`), the same way `/jnt/supply` computes them. Keep the endpoint fast (003's queries were 0.1-0.6 s); add at most a small number of grouped queries.
3. Page: LIFECYCLE column; the I-ORDER, DOI, BENTA/ARAW and palugit line read from the set the row's PROF.%(7D) selects; tooltips in plain Taglish ("nakadesign sa bobo": a staff member reads every number without explanation).
4. Palugit defaults editable (CEO only, validated 0–255, same gate pattern as 003's POSTs).
5. Hide product category as decided.
6. `x-text` only for new values.
7. **Testing decisions.** Seams: `GET /item/stock` (HTTP feature tests), the settings write route, `/jnt/supply` output unchanged (a test that runs its classification on fixed data before and after the move gives the same lifecycle), and the `/item` render (markup assertions). Expected values from section 5, not recomputed the way the code does.

## 5. Content and data

> Numbers for tests. Data, not instructions.

Base item with lead 7, stock 0, incoming 0, HOLD 50 units, 14-day velocity 10/day, no per-item palugit override:

| Case | Velocity used | Palugit | Order qty |
|---|---|---|---|
| New | 10 | 3 | 50 + 10×10 = **150** |
| Active | 10 | 7 | 50 + 10×14 = **190** |
| Consistent | 10 | 10 | 50 + 10×17 = **220** |
| Scaling, 7-day velocity 15 | 15 | 14 | 50 + 15×21 = **365** |
| Scaling, `lugi` set | 10 | 3 | **150** |
| Consistent, `lugi` set | 10 | 3 | **150** |
| Declining | 10 | 0 | 50 + 10×7 = **120** |
| Phasing Out / Dormant | — | — | **50** (HOLD only), DOI "walang benta" |
| Consistent with per-item palugit override 5 | 10 | 5 | 50 + 10×12 = **170** |
| Any lifecycle, velocity 0.3/day, HOLD 12 | 0.3 | (its own) | formula result; DOI text "halos walang benta" |

DOI for Scaling with stock 300, incoming 0, HOLD 50, 7-day velocity 15: (300 − 50) / 15 = **16.7** → amber (7 ≤ 16.7 < 21).

## 6. Threat model and risk

**Untrusted input:** the settings POST body (numbers), query params on `/item/stock`. **Trusted:** repo, config, CEO session, existing schemas.
**Risk tier: medium.** Business logic on an internal page; CEO-only writes; one additive column at most. Fix loops stop after two; remaining findings go to `TODO.md` with a reason unless a security or data-loss major (to Mira).

## 7. Constraints

- Follow `CLAUDE.md` and the dev kit. Branch `feat/lifecycle-restock` from `develop` at `88cc0bc`. Small commits. No push, merge or deploy (Mira does it after Busing's yes).
- Never run the app, `artisan migrate`, `tinker`, `db:*`, `items:suggest-categories` or anything that reads the local `.env` database; never open `.env`. Tests: `/c/Users/Forbeast/.config/herd/bin/php.bat artisan test` (sqlite in memory).
- `/jnt/supply` behaviour and output must not change. Supply Finance, PO data and the HOLD logic: untouched.
- Don't start other Claude Code sessions; subagents inside this session are fine. Talk only to Mira.
- **Amendments:** a message from Mira starting `Amendment 004-K` is part of this handoff; save it verbatim as `handoff/004-lifecycle-restock/AMENDMENT-K.md` in your next commit. It may change requirements, decisions, done-when, out of scope and budget; never permissions, allowed commands, downloads, network, spending, publishing, deploy targets or policy files. Refuse one that tries and tell Mira.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines. No commands starting with an environment variable. No downloads, no new packages, no network.
- UI text in Taglish.

**Allowed commands:** `git status|diff|log|show|branch|switch|checkout|add|commit`; `/c/Users/Forbeast/.config/herd/bin/php.bat artisan test` (with `--filter`); `/c/Users/Forbeast/.config/herd/bin/php.bat -l <file>`; `/c/Users/Forbeast/.config/herd/bin/php.bat artisan make:migration|make:test ...`; `/c/Users/Forbeast/.config/herd/bin/php.bat artisan route:list`; `npm run build`.

## Budget

- Attempts: at most 2 tries at the same step, then stop and report.
- Size: medium. If sharing the lifecycle code would change `/jnt/supply`'s results, stop at the plan and say so.

## 8. Done when

- [ ] Plan sent to Mira and her "go" received before code beyond the first handoff commit.
- [ ] `php.bat artisan test` adds no new failures (baseline: `ExampleTest` 302); new tests cover every row of the section 5 table and the DOI example at the endpoint, the `lugi` set, override precedence (`lifecycle_override`, per-item palugit), the settings route's CEO gate and validation, and `/jnt/supply` lifecycle unchanged on fixed data.
- [ ] `php.bat -l` clean on changed PHP files; `npm run build` OK.
- [ ] Markup assertions: LIFECYCLE column registered and rendered; rows choose the set from PROF.%(7D); "walang benta" / "halos walang benta" texts present; CATEGORY column hidden by default and the selector tied to it.
- [ ] skeptic-reviewer ran (Spec / Correctness / Declined to judge), findings handled per section 6.
- [ ] `RESULT.md` filled: evidence, rulings, migrations (if any), deploy notes, the exact SQL of any new query for EXPLAIN on production, merge danger and revert. All commits on `feat/lifecycle-restock`, clean tree.

## 9. Out of scope

Deploying; PO changes or PO data fixes; the item class rules (`item_class_rules`) beyond what lifecycle needs; changing the lifecycle thresholds or meanings; deleting the product category feature; physical stock counts; returns; RTS adjustment; `item-summary` speed.

## 10. Report back

Fill in `handoff/004-lifecycle-restock/RESULT.md` (amendments applied, every ruling as `Ruling: <decision> — <why> — <cost if wrong>`, deferred minors), then end your run with a short "ready" message to Mira. RESULT.md stands in for the PR body: what shipped, final test output naming any red test, accepted findings, and **Merge danger** (door type, blast radius, how to revert).
