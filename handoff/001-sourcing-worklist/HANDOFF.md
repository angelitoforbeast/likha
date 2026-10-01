# Handoff 001: Sourcing worklists on the item page

**From:** Mira · **To:** Claude Code · **Approver:** Busing (Angelito Forbes, CEO)
**Project:** Likha AI Tech (likhaaitech.com), the business operations app · **Stack:** see CLAUDE.md `## Stack` (Laravel 12, PHP 8.4 via Herd `php.bat`, Blade + Alpine, MySQL in production, PHPUnit on sqlite in memory)
**Weight:** architectural (new endpoint, two migrations, page changes) · **Shape:** change · **Risk tier:** medium

## 1. Context

Busing does all supplier sourcing himself, and many items pile up as HOLD (orders waiting) because they have no supplier or haven't been ordered. The `/item` Daily Summary page shows HOLD per item and a red "wala pang supplier" warning. What it can't do is answer his daily questions: which items do I still need to find a supplier for, and which do I need to order now?

His goal, in his words: "malist yung mga need ko hanapan ng supplier, yung mga need ko orderin if may supplier na etc".

A read-only survey of this repo (2026-10-01) found these facts. Verify them against the code.
- **The `/item` page.** It is `ItemController@index` (`routes/web.php:1038`). The page is `resources/views/item/index.blade.php`, an Alpine component.
  - HOLD comes from `/item/data` (`ItemController::data`, ~lines 99-175).
  - HOLD = `macro_output` rows with a non-blank `waybill` that is not in `from_jnts.waybill_number`, filtered by `STR_TO_DATE(TIMESTAMP,'%H:%i %d-%m-%Y')` in range.
  - It counts rows per raw `ITEM_NAME` and ignores `STATUS`, so cancelled orders count too.
  - The filter on a string column is likely slow. A `ts_date` column with an index exists (migrations 2025_12_17 / 2025_12_19*) but isn't used here.
- **Units per base item.** `HoldService::unitsByBaseItem` (`app/Services/HoldService.php`) already computes HOLD units per base item. The base item is the name with the `N x ` prefix stripped, lowercased, spaces collapsed.
- **Duplicated key logic.** The same key rule exists in four places: `ItemSupplierQuote::keyFor`, `HoldService::itemKey`, `SupplyFinanceController::itemKey`, and JS `supKey` in the page.
- **Suppliers, CEO only.** A PO supplier is the latest `unit_cost > 0` per supplier from `supply_order_items → supply_orders → suppliers` (`/item/suppliers`).
  - Quotes are `item_supplier_quotes` (`item_key`, `item_name`, `supplier_id`, `price`, `moq`, `link`, `note`, `updated_by`), unique `(item_key, supplier_id)`.
  - `updateOrCreate` overwrites the old price. There is no history and no image.
  - Every supplier and quote endpoint is gated to the exact role `CEO` (`ItemController.php:205, 244, 264, 295`), and Marketing sees none of it.
- **POs.** `supply_orders` (status ordered / delivered / counted, dates, supplier) and `supply_order_items` (`item_key`, `item_name`, `ordered_qty`, `unit_cost`, `received_qty`). `supply_item_settings` has `lead_time_days` and `safety_days`.
- **Item photos.** One per item, in `item_images`. Uploads go through `/item/photo` (jpg/jpeg/png/webp up to 10 MB, `ItemController.php:361-375`), on the public disk under `storage/app/public/item-images/`.
- **Junk in the repo.** `latest.dump` (~87 MB, likely a database dump) is tracked in git.

## 2. Goal

On `/item`, the CEO can switch between four worklists. Each item sits in exactly one list and each list shows a count.
- **Hanapan ng supplier**
- **May quote, hindi pa na-order**
- **I-order na**
- **Naka-order, hinihintay**

Each list is grouped by base item (the 1x/2x variants of one product in one row) and sorted by HOLD units. Quotes keep their price history and can carry a photo of the supplier's product. HOLD counts only live orders and loads faster. `latest.dump` is no longer tracked.

## 3. Decisions already made

| Topic | Decision |
|---|---|
| Who sees it | CEO only, as today. No new role and no team access to suppliers (Busing: "wag" to team access). Marketing view unchanged. Gate in the data layer like the existing endpoints. |
| Where | On the existing `/item` page as filter chips next to the current view ("filter or sort siguro"). No new page in the menu. The current default view stays as it is. |
| AI supplier search | Not now. Don't build it. |
| Plaintext passwords (`users.password_plain`, `/owner/users`) | Leave untouched. Busing hasn't decided yet. |
| Review | Busing reviews after it's built ("ikaw muna gumawa, pupunahin ko na lang"). Expect a follow-up handoff for his changes. |

## 4. Requirements

1. **Worklist endpoint** `GET /item/worklist?start_date&end_date` (CEO only; non-CEO gets the same empty shape the other supplier endpoints return).
   - It returns one row per base item with HOLD units > 0 in range, with these fields:
     - base key and display name
     - the variant names with their HOLD units
     - total HOLD units
     - the item photo URL
     - `list` (one of the four below)
     - the suppliers known (PO supplier and/or quotes, with latest price)
     - the open PO if any (supplier, order date, ordered qty, received qty, days since ordered, the item's `lead_time_days` if set)
     - for "I-order na", the shortfall
   - **Classification**, first match wins:
     1. **Hanapan ng supplier:** no PO line ever for the item, and no quote.
     2. **May quote, hindi pa na-order:** at least one quote, no PO line ever.
     3. **I-order na:** has a supplier (a past PO line or a quote), and either no open PO, or open PO quantity (ordered − received, on POs with status `ordered`) less than HOLD units. Shortfall = HOLD units − open qty.
     4. **Naka-order, hinihintay:** an open PO covering the HOLD units.
   - Put the classification in one pure function or class that takes plain data and returns the list name and shortfall, so it can be unit-tested. Confirm in your plan which `supply_orders.status` values mean "open". `ordered` is my reading.
   - HOLD units here = the HOLD rule from requirement 4 grouped by base item, units = rows × the `N x` quantity. Reuse `HoldService` where it fits.
   - Use one key function for the base item. Use the existing one and don't add a fifth copy. If a single shared helper means touching the other three call sites, list that in the plan as a suggestion, not part of this handoff.
2. **Page:** filter chips above the table, CEO view only.
   - The chips are "Lahat" (today's view, default), then the four lists, each with its count.
   - Choosing a list shows its rows from `/item/worklist` in a simple table:
     - item (photo, name, variants)
     - HOLD units
     - suppliers with latest price
     - open PO / shortfall / days waiting vs lead time
     - the existing "+ supplier quote", "Change" and "Copy" actions where they apply
   - Sorted by HOLD units, highest first.
   - Remember the chosen chip in the URL (`?list=`), so a reload or a shared link keeps it.
   - Match the page's existing Alpine and Tailwind style and its Taglish labels.
3. **Quote history and photo.**
   - **History:** new table `item_supplier_quote_history`. When a quote's price, MOQ or link changes, or the quote is deleted, write the old values with who and when (`updated_by`, timestamp).
   - **Previous price:** in the quote display, show "dati ₱X (date)" when there is a previous price.
   - **Photo:** add a nullable `photo_path` to `item_supplier_quotes` and an upload in the quote form.
     - Same file rules as the item photo upload: jpg/jpeg/png/webp, ≤10 MB, validated server side, generated file name, public disk.
     - Store under `storage/app/public/supplier-quote-images/`.
     - Show it as a thumbnail next to the item photo.
   - Guard new tables and columns with `Schema::hasTable` / `hasColumn` like the rest of the code.
4. **HOLD accuracy and speed in `/item/data`** and in the worklist.
   - Exclude cancelled orders. Find in the code which `macro_output.STATUS` values mean cancelled, list them in your plan, and wait for my go before using them.
   - Filter on the indexed `ts_date` column instead of `STR_TO_DATE`, if `ts_date` is reliably filled (check how it's populated and say so).
   - Start with a characterisation test of today's HOLD rule, then change it test-first.
5. **`latest.dump`:** stop tracking it (`git rm --cached latest.dump`) and add it to `.gitignore`. Don't delete the local file. Don't rewrite history.

**Testing decisions.**
- **Seams:**
  1. the pure classification function (unit tests with literal inputs from this handoff's rules)
  2. the HTTP endpoints `/item/worklist` and the quote update/delete (feature tests: CEO gets data, Marketing gets the empty shape; history row written on a price change; upload rejects a non-image)
  3. the HOLD query (characterisation test, then the cancelled-status change)
- **SQLite caveat:** tests run on sqlite in memory. Raw MySQL-only SQL (`STR_TO_DATE`) won't run there. Say in your plan how you test those paths, for example through the code's existing mysql/pgsql/sqlite branching if any, or by keeping the rule in testable PHP. Don't fake it.
- **Expected values:** from this handoff, never recomputed the way the code does.

## 5. Content and data

None. Labels: "Lahat", "Hanapan ng supplier", "May quote, hindi pa na-order", "I-order na", "Naka-order, hinihintay", "dati".

## 6. Threat model and risk

**Untrusted:**
- uploaded images
- quote fields and dates from request parameters (only logged-in CEO users reach them, but validate as usual)
- stored item names, which originally come from order sheets

Escape them in Blade and Alpine output. Never `{!! !!}` or `x-html` with them.

**Trusted:** the repo, config, the database schema, Busing's and Mira's inputs.

**Risk tier: medium** (business logic on a CEO-only path, plus an upload).

Reviews judge against this. Fix loops stop after two. Remaining findings go to `TODO.md` with a reason, unless they are a security or data-loss major, which goes to Mira.

## 7. Constraints

- CLAUDE.md wins on conflict (legacy mode: minimal touches; match existing code; TDD on changed behaviour; no new dependencies).
- **Never touch a real database.** The local `.env` may point at a real database.
  - Don't run `artisan migrate`, `tinker`, `db:*`, `queue:*` or any artisan command other than `artisan test`.
  - Don't read or edit `.env`.
  - Migrations are written and tested only through `artisan test` (sqlite in memory).
- **Git:** work only on `feat/sourcing-worklist`. Commit there with conventional messages (the commit-msg hook refuses attribution trailers).
  - Don't push, don't merge, don't touch `main` or `develop`.
  - `gh` isn't installed, so there's no PR. Mira reviews the branch and merges it into `develop` herself. Note this in RESULT.md instead of a PR body.
- Don't deploy or SSH. No downloads, no package installs, no `npm install`.
- Allowed commands:
  - `"/c/Users/Forbeast/.config/herd/bin/php.bat" artisan test` (with filters)
  - `"/c/Users/Forbeast/.config/herd/bin/php.bat" -l <file>`
  - `"/c/Users/Forbeast/.config/herd/bin/php.bat" artisan route:list`
  - `npm run build`
  - `node --test 'test/hooks/*.test.mjs'`
  - `git checkout -b`, `git status`, `git diff`, `git log`, `git show`, `git add`, `git commit`, `git rm --cached latest.dump`
  - Read, Edit and Write files in this repo; subagents for review (the kit's `skeptic-reviewer`).
- The kit's hook tests have 5 known failures on this machine (symlink EPERM on Windows). Report them; don't fix them.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report.
- Size: large. If the plan grows beyond this handoff (for example, the key-function cleanup across controllers, or HOLD changes that ripple into other pages), stop and report before going on.

## 8. Done when

- [ ] `php.bat artisan test` is green apart from failures that already existed (named in RESULT.md with the before/after run). The new tests cover the three seams.
- [ ] `php.bat -l` passes on every changed PHP file. `npm run build` succeeds if assets changed.
- [ ] `GET /item/worklist` returns the four lists per the rules. The classification unit tests encode each rule and the first-match order.
- [ ] Quote price change and delete write history. The display shows "dati ₱X (date)". The quote photo upload works and rejects non-images (feature tests).
- [ ] `/item/data` excludes the cancelled statuses I approve and filters on `ts_date` if reliable. A characterisation test was written first.
- [ ] `latest.dump` is untracked and in `.gitignore`.
- [ ] The `skeptic-reviewer` ran on the diff. Its two axes (Spec, Correctness) and "Declined to judge" are in RESULT.md, with what you did about each.
- [ ] All commits on `feat/sourcing-worklist`; `git status` clean; RESULT.md filled in.

## 9. Out of scope

- AI supplier search
- team or role access to suppliers
- plaintext passwords
- a new page or menu item
- changing the default `/item` view beyond the chips
- refactoring the key helpers across controllers (suggest only)
- deploying
- pushing
- Messenger integration

## 10. Report back

RESULT.md sections:
- **Summary**
- **Done-when evidence** (commands and output)
- **Files changed**
- **Migrations** (what they create, and that they are guarded)
- **Rulings**: one line each, `Ruling: <decision> — <why> — <cost if wrong>`
- **Review findings**: Spec / Correctness / Declined to judge
- **How Mira can preview it locally without touching a real database**, or say plainly that she can't
- **Deploy notes for Mira**: migrations to run, the storage link for the new image folder, cache clears
- **Suggestions for Busing**: things you noticed but didn't change

Then end with a short summary as your final message.
