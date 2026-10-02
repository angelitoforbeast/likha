# Result 005: /item layout that fits the screen, restock decision first

Status: **done, waiting for Mira's review of branch `feat/item-layout`**, cut from `feat/lifecycle-restock` at `762d4ae`.
- Spec: `docs/specs/005-item-layout.md`. Mira's answers are in §13a.
- Plan: `docs/plans/005-item-layout.md`.
- There's no PR, so this file stands in for the PR body. Nothing was pushed, merged or deployed.

## Summary

**New default layout of `/item`** (`resources/views/item/_table_new.blade.php`, `_il_expand.blade.php`, `_il_lifecycle.blade.php`):

**Main row:** one row per item with 11 columns:
- **ITEM:** sticky, with photo, name and a neutral "Naka-hold: 1,761" chip. For the CEO it also has the supplier/PO line, quote lines with inline add/edit/delete, and "⚠ wala pang supplier". It keeps "⚠ walang running page" (peach row) and the worklist info.
- **LIFECYCLE:** Taglish badges 🆕 Bago · 📈 Lumalaki · ✅ Stable · 🔄 Aktibo · 📉 Bumababa · 🚫 Itinitigil · 💤 Tulog, plus "· lugi" as text and "(manual)" with its tooltip. No red badges.
- **STOCK / PAPARATING:** "Stock 40 · Paparating 200", or grey "Hindi pa nabibilang" when stock needs counting.
- **BENTA/ARAW:** tooltip "average ng huling N araw", where N comes from the new `velocity_days` field.
- **AABOT PA?:** one state per row:
  - ⚠ Kulang: X araw na benta ang naka-hold
  - ⚠ Mauubos bago dumating
  - Malapit na: X araw
  - ✓ Sapat: X araw / mahigit 1 taon
  - 💤 Walang benta
  - 🐢 Halos walang benta
  - Hindi pa alam — bilangin muna ang stock

  Line 2 reads "Dating sa 7 araw + 3 araw reserba (Lumalaki)", or "· HOLD lang" for Phasing Out / Dormant. The CEO gets a ✎ button that opens the existing lead/palugit editor.
- **I-ORDER:** "Umorder 3,299 pcs", then a pill:
  - "Bilangin muna ang stock"
  - "Hindi pa kailangan"
  - "Hanap muna ng supplier" (CEO)
  - "⚠ ngayon na"
  - "bago Okt 24"

  CEO view adds "≈ ₱98,970". The tooltip gives the reason, e.g. "1,538 para sa 10 araw + 1,761 naka-hold − 0 paparating − 0 stock".
- **KITA NGAYON:** ▲/▼ money plus "84 orders".
- **KITA %:** 2×2 grid of 1D / 3D / 7D / 1M, blue ▲ or orange ▼.
- **ADS:** spend, plus "CPP ₱… · BE ₱…".
- **ACTION:** the latest note.
- **›:** keyboard-reachable expand button, present on every item including hold-only ones.

**Expanded block (›):**
- An item grid with:
  - Puhunan bawat piraso (+ "CEO: ₱…" only when it differs)
  - RTS/DEL/INT, TCPR, PROF.PROFIT for the range, 3D and 7D, NP/O(1M), Orders, Proceed
  - CATEGORY when it's visible (the CEO gets a select)
  - "Paano nakuha:" with the I-ORDER reason
  - Change / Add photo, and Copy
- One card per page:
  - the breakdown link and the mixed-primary / back-filled warnings
  - every page field the role can see, as text with no fills
  - ✎ for Set RTS%, Promo, Item Val., Item Val. (CEO) and Action, using the same modals as today
  - a "Campaigns ›" toggle that shows the shared campaigns → ad sets → ads panel inside `.il-camp`

**Campaigns panel:** CSS scoped to `.il-camp` makes it wrap with no horizontal scroll. It uses a fixed table layout at 100% width, `min-width:0`, wrapping cells, and a vertical inner scroll of at most 70vh. The shared include `owner/_private_expand_inline.blade.php` is unchanged.

**Default sort (new layout only):** by urgency, in this order:
1. Kulang/Mauubos with a supplier or quote
2. Kulang/Mauubos without one
3. Hindi pa nabibilang
4. Malapit na
5. Sapat
6. Everything else

Ties go by HOLD, highest first. Header sorts work through the existing `_itemSortValue`; ACTION uses a new `il_action_at` key.

**TOTAL (nakikita):** sums the item groups currently shown. It follows the item checkbox filter, the sourcing chip and the category filter, and shows Naka-hold, KITA NGAYON, KITA % and ADS.

**Chip:** "I-order na" is now "Handa nang i-order (may supplier)", with the same key and logic.

**Toolbar (both views):** it wraps instead of clipping. "Expand all / Hide all" has a fixed width of 118 px.

**Screen widths:**
- **≥1,440 px:** all 11 columns.
- **1,366–1,439 px:** LIFECYCLE moves under the item name.
- **1,100–1,365 px:** ACTION also moves into the expanded block, and KITA % stacks.
- **<1,100 px:** one card per item.

**Formats:** "−₱534.71", "Okt 24", whole days at 10 and over, one decimal below 10.

**Column visibility:** the composite columns are built from the existing `owner_private` column ids, the same way RTS/DEL/INT already merges three ids. A column shows when one of its ids is visible for the role, and each line inside it follows its own id. The CEO grants them per role on `/owner/column-settings` as today. There's no catalog change, no new config key and no migration. `/owner/private` isn't touched.

**Old layout:** `?layout=old` renders today's table. The block moved verbatim into `resources/views/item/_table_old.blade.php`: `git diff --numstat 762d4ae:resources/views/item/index.blade.php HEAD:resources/views/item/_table_old.blade.php` gives `0	3372`, so no line was added. The old view keeps its HOLD-descending sort, its TOTAL and `tot()`, its fills and drag-reorder. "🗂 Lumang view" and "✨ Bagong view" toolbar links switch between the two, and `load()` keeps `layout=old` in the URL.

**Data:** `GET /item/stock` adds `velocity_days` to each set (`normal` and `lugi`). It's the day count behind `units_per_day`: 7 when Scaling's 7-day figure wins, otherwise the 14-day window's day count. No number changed and there's no new query.

**Unchanged** (none of these files appears in `git diff --stat 762d4ae HEAD`):
- `/owner/private` and every `resources/views/owner/*` file
- `OwnerColumnSettingsController`
- `/jnt/supply`, Supply Finance, PO data, `HoldService`, `item-summary`
- `_agg_cells.blade.php`
- every 003/004 formula

## Done-when evidence

| Done-when | Evidence |
|---|---|
| Plan sent to Mira (width budget ≤ 1,366 px), her "go" before code | The plan was the final message of the first run. Commits before the go: `d77d0e1` (handoff files) and `e974ba4` (spec, plan, and the width table in spec §12). Mira's go came with answers 1–8; they're recorded in spec §13a (`88a2701`). The first code commit is `42f7e69`, after the go. |
| `artisan test`: no new failures; markup assertions for the decision rows | Final `php.bat artisan test --compact`: `Tests: 1 failed, 3 skipped, 228 passed (2109 assertions)`. The baseline on `762d4ae` was 210 passed. The only failure is the known `Tests\Feature\ExampleTest > the application returns a successful response` ("Expected response status code [200] but received 302."). The 3 skipped are the Boardroom live tests. Coverage is listed below the table. |
| `php -l` clean; `npm run build` OK | `php.bat -l` gives "No syntax errors detected" on `app/Http/Controllers/ItemController.php`, `app/Services/ItemStockService.php`, `tests/Feature/Item/ItemLayoutTest.php`, `ItemPageTest.php` and `LifecycleStockTest.php`, the only changed PHP files. `npm run build` gives `vite v6.3.5 … ✓ built in 2.44s`. The bundle is unchanged (`app-DNxiirP_.js 35.32 kB`) because the page JS is inline in Blade. |
| "How to check in the browser" list | See the section below. |
| skeptic-reviewer ran; findings handled | Five reviews at standard depth (sonnet), one per task, plus a scoped re-check of the fix wave. No blocker or major. The minors are fixed in `7149d29` / `65ece26` or accepted in `TODO.md`. See "Review findings". |
| RESULT filled; migration; deploy notes; merge danger and revert (incl. `?layout=old`); commits on branch; clean tree | This file. There's no migration. Every commit from `d77d0e1` to the last one is on `feat/item-layout`; the last commit adds this file, `TODO.md` and the reviewer memory, and `git status` is clean after it. |

**What the tests cover:**
- **`ItemPageTest`** (27 tests, CEO and Marketing renders):
  - **Headers:** the 11 headers in order, each with its sort key.
  - **Texts:**
    - the seven lifecycle labels
    - every AABOT PA? state text, plus "Dating sa " and "araw reserba"
    - the five pills
    - "Umorder ", "Naka-hold: "
    - the Taglish months and "−₱"
    - "average ng huling "
  - **CEO only:** absent in the Marketing render are the cost line, ✎ lead/palugit editor, quote editor, "wala pang supplier" and "CEO: ₱".
  - **Sort:** the urgency branch, with the HOLD-descending branch kept for `layoutOld`.
  - **Expanded block:**
    - its texts
    - the campaigns panel inside `il-camp`
    - the scoped `.il-camp` CSS (`overflow-x:visible`, `table-layout:fixed`)
    - `il-camp` absent from `owner/private.blade.php` and `_private_expand_inline.blade.php`
    - the page-card ✎ calls
    - Copy
    - `case 'il_action_at':`
    - no `pbStyle(` / `cellFormatStyle(` in the new partials
  - **TOTAL and toolbar:**
    - "TOTAL (nakikita)" built from `itemGroups()`
    - the old TOTAL and `tot()` unchanged
    - the chip rename
    - `flex-wrap:wrap` on the nav, and Expand/Hide all at a fixed width
  - **Breakpoints and fonts:**
    - the 1,365 / 1,099 media queries
    - no font size under 11 px in the new partials and `.il-` CSS
    - no `x-html`
  - **Old layout:** the old-layout render keeps today's table: the `_agg_cells` cells, "Drag headers to reorder", `<td>TOTAL</td>`, `colDragStart($event`, `page-col-header` and `expand-panel`. The default render doesn't contain them. Every 003/004 assertion still runs against the old render, none weakened.
  - **Fix wave:** the click-time view link, the cover derivation, `BENTA/<wbr>ARAW`, the HOLD tooltip, and the single `x-for="T in [ilTotVisible()]"`.
- **`ItemLayoutTest`** (HTTP `GET /item` as CEO): `layoutOld` is false by default, true for `?layout=old`, and false for `?layout=OLD` and `?layout=x`.
- **`LifecycleStockTest`:** a `velocity_days` table of [normal, lugi] for each item:
  - Scaling, 7-day wins → [7, 14]
  - Scaling, 14-day wins → [14, 14]
  - Scaling, equal rates → [14, 14]
  - Active, New, Consistent, Declining and override → [14, 14]
  - Phasing Out / Dormant → [14, 14]
  - first order 5 days before the end date (Active and Phasing Out) → [5, 5]
  - when stock isn't ready, the field is still present
- **Markup only (no JS runner, no browser):** everything below is covered by markup assertions alone; none of it was executed:
  - the AABOT state choice, pill choice, day and date formats, cost line, reason line, urgency ranks, column visibility, TOTAL sums and page-card values at runtime
  - the breakpoints, the cards and the campaigns-panel fit

  The numbers themselves come from the server and are tested there.

**Red runs** (first failure per slice):
- **T1:**
  - `every section 5 row gives its order qty in both sets` failed with `Undefined array key "velocity_days"`.
  - `only the exact string old selects the old layout` failed with `Failed asserting that null matches expected true`.
- **T2:**
  - The characterisation test `test_old_render_has_the_full_scrolling_table` was green first, by design: it pins existing behaviour.
  - The verbatim move then stayed green.
  - The switch test failed because the default render still contained "Drag headers to reorder".
- **T3:** `new table has the eleven headers in order with sort keys` failed with "header ITEM missing or out of order". Three more new tests failed on missing texts.
- **T4:** `expanded block has item section page cards and actions` failed on the missing "Puhunan bawat piraso". Three more failed on missing texts.
- **T5:** `new table total follows the visible items and the old total is unchanged` failed on the missing `class="il-total"`. Four more failed on missing markup.
- **Fix wave (`7149d29`):** **no red run.** The developer wrote the markup assertions after the view edits, and they passed on first run, so they were never seen failing on the old code. The two `velocity_days` rows in `65ece26` are characterisation and were green at once, as expected.

## How to check in the browser

Open `/item` as the CEO, then again with 👁 View: Marketing.

**At 1,366 px wide:**
- [ ] There's no horizontal scrollbar on the page, and none inside an opened Campaigns panel (only a vertical one is allowed).
- [ ] LIFECYCLE sits under the item name. ACTION is still a column.
- [ ] Every row shows the item name next to AABOT PA? and I-ORDER.
- [ ] Glow Tape reads:
  - "⚠ Kulang: 12 araw na benta ang naka-hold" (whole days, per answer 1)
  - "Umorder 3,299 pcs"
  - a "Hanap muna ng supplier" pill if it has no supplier, otherwise "⚠ ngayon na"
  - "≈ ₱98,970" for the CEO only
- [ ] Nail Care Pen reads "🐢 Halos walang benta".
- [ ] Seat Cover reads "✓ Sapat: …" with "Hindi pa kailangan" or "bago Okt …".
- [ ] The toolbar wraps with nothing clipped, and the buttons don't move when you press Expand all / Hide all.
- [ ] The header labels fit their columns ("BENTA/ARAW" may break after the slash).
- [ ] The STOCK / PAPARATING text doesn't overlap the next column.
- [ ] Hovering BENTA/ARAW shows "average ng huling N araw". Hovering I-ORDER shows the reason line.
- [ ] Open › on an item with pages:
  - the item grid shows
  - each page card shows
  - the ✎ for RTS / Promo / Item Val. / Item Val. (CEO) / Action opens the same modal as before and saves
  - Campaigns › → ad sets → ads open and wrap, and a campaign name opens the creative preview
- [ ] Open › on a hold-only (peach) item: Change / Add photo and Copy work.
- [ ] Pick "Handa nang i-order (may supplier)" and a category: TOTAL (nakikita) changes with the filter.
- [ ] Default order: Kulang/Mauubos items with a supplier come first. A header click sorts, and a second click reverses.
- [ ] "🗂 Lumang view" opens today's table with the same dates and chip. "✨ Bagong view" comes back.

**At 1,920 px wide:**
- [ ] All 11 columns, LIFECYCLE as its own column, and no scroll.

**At 1,100–1,365 px** (e.g. a 1,280 px window):
- [ ] ACTION isn't a column; it's in the › block. KITA % is stacked. No scroll.

**On a phone** (or a window under 1,100 px):
- [ ] One card per item: name + lifecycle, AABOT PA?, I-ORDER, KITA % and ›.
- [ ] Stock, benta/araw, kita ngayon, ads and action are inside ›.
- [ ] No sideways scroll anywhere, including the Campaigns panel. It wraps tightly; judge whether it's still usable.
- [ ] The toolbar and date pickers are usable.

**Marketing view:**
- [ ] No supplier/quote lines, no "≈ ₱", no ✎ next to the lead line, no "CEO: ₱".
- [ ] Columns follow what the CEO granted on the Columns page. For example, KITA % shows only the granted lines.

## Files changed

- **New:**
  - `resources/views/item/_table_old.blade.php` (verbatim move)
  - `resources/views/item/_table_new.blade.php`
  - `resources/views/item/_il_expand.blade.php`
  - `resources/views/item/_il_lifecycle.blade.php`
  - `tests/Feature/Item/ItemLayoutTest.php`
- **Changed:**
  - `resources/views/item/index.blade.php`: the include switch, the `.il-` CSS, the toolbar, the chip label, the `layoutOld` state, `load()`, `viewSwitchUrl()`, the `il…` helpers, the new-layout branch of the default sort in `itemGroups()`, and the `il_action_at` case in `_itemSortValue`
  - `app/Http/Controllers/ItemController.php`: `index()` passes `layoutOld`
  - `app/Services/ItemStockService.php`: `velocity_days`
  - `tests/Feature/Item/ItemPageTest.php`
  - `tests/Feature/Item/LifecycleStockTest.php`
- **Docs and records:**
  - `docs/specs/005-item-layout.md`
  - `docs/plans/005-item-layout.md`
  - `TODO.md`
  - `.claude/agent-memory/skeptic-reviewer/repo_weak_spots.md`
  - `handoff/005-item-layout/*`
  - `handoff/README.md`

## Migrations

None. The new columns reuse the existing `owner_private` column ids, so there's no config key, catalog entry or data migration.

## Amendments applied

None arrived. Mira's go came with answers 1–8, applied as the rulings below and recorded in spec §13a.

## Rulings

- Ruling: whole days at 10 and over, rounded; one decimal below 10. The rounding is done to one decimal first, so 9.96 shows "10" (**Mira's answer 1**) — the handoff's explicit rule beats its example numbers — cost if wrong: "Kulang: 12 araw" where the old page said 11.5.
- Ruling: the cost line is qty × (item value ÷ N of the row's "N x"), and "Puhunan bawat piraso" is per piece (**answer 2**) — a "2 x" row's cogs is per order of 2 — cost if wrong: if a variant's cogs were already per piece, its cost reads half.
- Ruling: BE on the item row is one page's value, or lowest–highest across several pages, and is left out when no page has one (**answer 3**) — no new formula — cost if wrong: none; each page card shows its own BE.
- Ruling: at 1,100–1,365 px, ACTION moves into the expanded block and KITA % stacks (**answer 4**) — that keeps "no scroll" below 1,366 — cost if wrong: the action note is one click away on smaller laptops.
- Ruling: the new layout shows no conditional-formatting or profit fills; the old view keeps them (**answer 5**) — "no full-cell fills" decision — cost if wrong: CEO colour rules from `/owner/column-settings` show only in `?layout=old`.
- Ruling: the campaigns panel fits through CSS scoped to `.il-camp`, with the shared include's markup unchanged. That means a fixed layout at 100% width, `min-width:0 !important` and `white-space:normal !important` on cells, 11 px text, and a vertical scroll of at most 70vh (**answer 6**) — `/owner/private` must not change — cost if wrong: on a phone the table wraps mid-word (in `TODO.md`).
- Ruling: uncounted stock shows grey "Hindi pa alam — bilangin muna ang stock". In the Marketing view, which has no supplier data, both Kulang/Mauubos sort groups merge (**answer 7**) — the shown 0 isn't a real count — cost if wrong: none.
- Ruling: the pill order is Bilangin muna → Hindi pa kailangan → Hanap muna ng supplier → ngayon na → bago <date> (**answer 8**) — cost if wrong: an item with qty 0 and no supplier says "Hindi pa kailangan", which is true.
- Ruling: the old view is the same Alpine component with the table markup moved verbatim into `_table_old`, not a full copy of the page — one copy of the JS (no 2,000-line duplicate), and the move is proven line-for-line — cost if wrong: a later change to a shared helper could alter the old view. The new code only adds `il…` helpers and branches on `layoutOld`.
- Ruling: composite columns map to existing column ids, and the new layout ignores the saved column order and doesn't write it. Drag-reorder exists only in the old view — `/owner/private` shares that order — cost if wrong: the CEO can't reorder the new columns. The order is fixed by the handoff.
- Ruling: `velocity_days` is added to `/item/stock` — the BENTA/ARAW tooltip needed the 7-vs-14 fact, and no field carried it — cost if wrong: none; it's additive and no number changes.
- Ruling: the reason line derives its cover figure from the server's numbers (`order_qty − HOLD + paparating + stock`) when `order_qty > 0` — it can then never disagree with "Umorder N" — cost if wrong: at qty 0 the cover uses the 2-decimal rate and may be off by 1 (`TODO.md`).
- Ruling: the ACTION column shows the latest non-empty note among the item's pages, and editing is per page in the expanded block — action notes are per page and date — cost if wrong: an item with several pages shows only the newest note in the row.
- Ruling: the toolbar change (wrap, fixed Expand/Hide width) and the chip rename apply to both views — they sit above the table, and "unchanged" was about the table — cost if wrong: the old view's toolbar can be two lines high.
- Ruling: TOTAL (nakikita) sums the page rows of the shown item groups. Hold-only items add to Naka-hold only — no double counting, because pages are grouped by item — cost if wrong: none found.
- Ruling: all tasks ran one after another on this branch, with no worktrees — `git worktree` isn't in the allowlist, and T2–T5 share `index.blade.php` — cost if wrong: none.
- Ruling: no browser check — running the app reads the local `.env` database (handoff §7) — cost if wrong: layout problems surface in Mira's live check (list above).
- Ruling: no JS helper module — the build has no JS test runner and `node` isn't an allowed command — cost if wrong: the page logic is only covered by markup assertions (`TODO.md`).

## Review findings

`skeptic-reviewer` ran at standard depth (sonnet) once per task: T1, T2, T3, T4 and T5. A scoped re-check of the fix wave followed. No blocker or major came up, so there was no fix loop and nothing was escalated. One final wave fixed the cheap minors in `7149d29` and `65ece26`, and the re-check found no regression.

### Spec

- **Fixed:**
  - The ACTION header sort did nothing at item level. Now `il_action_at` uses the latest non-empty note (T4, `7149d29`).
  - `ilDays(9.96)` gave "10.0" (`7149d29`).
  - The reason line could differ by 1 from the server (`7149d29`).
- **Accepted in `TODO.md`:**
  - 11 px text and light greys inside the campaigns panel (the shared include, and the need to fit)
  - mid-word wrapping on a phone
  - `ilColspan` counting hidden columns
  - no header sort in card view (per spec)
- **Checked and fine:**
  - the column-width sums match spec §12 (1,152 / 1,040 / 820 against 1,407 / 1,333 / 1,067)
  - the AABOT states and pill order match §6, §7 and §13a
  - CEO-only content is gated in Blade

### Correctness

- **Fixed in `7149d29`:**
  - the view-switch link went stale after a date or chip change; it's now built on click
  - BENTA/ARAW showed "0.0" for null; it now shows "—"
  - the page-card HOLD tooltip had gone missing
  - the STOCK cell text overflowed into the next cell
  - "BENTA/ARAW" broke mid-word
  - TOTAL recomputed `itemGroups()` about 13 times per render
- **Fixed in `65ece26`:** `velocity_days` rows for equal rates and for stock that isn't ready.
- **Confirmed:**
  - the old table move is verbatim (`git diff --numstat` gives `0	3372`)
  - `viewSwitchUrl()` can't produce a `javascript:` URL (pathname plus `URLSearchParams`)
  - `.il-camp` CSS can't reach `/owner/private`, whose styles are in its own view
  - there's no `x-html`
  - untrusted text only goes through `x-text` / `:title`
- **Accepted in `TODO.md`:**
  - no runtime JS test
  - ctrl/cmd-click on the view link opens in the same tab
  - `th { overflow-wrap:normal }` is unverified at 1,100–1,365 px
  - `ilPageVal` repeats work for open items
  - the listed edge tests

### Declined to judge

- Real rendering at 1,366 / 1,100 / 360 px, the sticky ITEM cell and sticky TOTAL, the campaigns-panel fit, and `<col>` hiding by media query. No browser was used, so these are on the checklist above.
- Alpine behaviour at runtime, because there's no JS runner.
- MySQL/pgsql: nothing new. The one PHP change adds a field and no query.

## Deploy notes for Mira

Deploy together with 004, from this branch; it contains 004's commits.
1. Follow 004's deploy notes first: `php artisan migrate --force` for 004's three migrations. 005 adds no migration.
2. Run `php artisan view:clear`; the Blade views changed.
3. `npm run build` only if production builds assets on deploy. The bundle didn't change.
4. No `item-summary` cache bump is needed: its output didn't change. `/item/stock` only gains `velocity_days`.
5. After the deploy, do the browser checklist above at 1,366 px, 1,920 px and on a phone, as CEO and in Marketing view. If the new layout blocks ordering work, staff can use `/item?layout=old` right away. It's the same table as before.

## Merge danger

- **Two-way door.** The change is view-only, plus one additive JSON field, with no migration and no data writes.
- **Blast radius:**
  - `/item`'s default look: the new layout.
  - The toolbar in both views: wrapping, and the renamed chip.
  - `GET /item/stock`: gains `velocity_days`.
  - `/owner/private`, `/owner/column-settings`, `/jnt/supply`: untouched.
- **Revert:**
  - **Soft revert, no deploy:** use `/item?layout=old`. It renders today's table, and the "🗂 Lumang view" link goes there.
  - **Full revert:**
    1. Revert this branch's commits `42f7e69..65ece26`, or the squash commit; leave the docs commits.
    2. Run `php artisan view:clear`.

    There's no database step.
- **One-way part:** none.

## Fix list 1

These are Mira's verifier findings. There's one `fix:` commit per item; each has a red markup test first, then the code.

| # | Fix | Commit | Red run |
|---|---|---|---|
| 1 | `ilColspan()` equals the columns actually rendered: 11 at ≥1,440 px, 10 at 1,366–1,439, 9 below 1,366 when every column is on. The reactive `ilVw.lt1440` / `ilVw.lt1366` come from `window.matchMedia('(max-width: 1439px)')` / `('(max-width: 1365px)')`, the same queries as the CSS. `change` listeners (falling back to `addListener`) start in `init()`. LIFECYCLE or ACTION is subtracted only when it is both on and hidden by width. Every `:colspan` in `_table_new` uses `ilColspan()`, and the test also forbids a numeric constant. The TODO note is removed. | `f8f2fbf` | `colspan follows the columns the media queries leave visible`: the `window.matchMedia('(max-width: 1439px)')` call was missing |
| 2 | The campaigns panel has no inner scroll. `.il-camp .expand-wrap` and the nested ad set / ad wraps are `overflow:visible !important; max-height:none !important`, so the panel grows taller instead. There's no `overflow-x:hidden`, and the wrapping rules are kept. The test now asserts that rule and the absence of `overflow-y:auto`, `max-height:70vh`, `overflow:auto` and `overflow:hidden` in the `.il-camp` rules. The TODO note about `overflow-x` computing to `auto` is removed. | `fd5c97e` | `campaigns panel is scoped to fit without horizontal scroll`: the new rule was missing |
| 3 | `.il-chev` is `width:24px; height:24px; padding:0; font-size:18px`, inside the 36 px cell. The card-mode placement is unchanged. | `d37dd85` | `chevron button fits its column`: `width:24px; height:24px` was missing |
| 4 | `ilPct` gives "▲ 15.8%", "▼ −3.2%" (U+2212) and "—" for null. Page cards use the same helper, so they show the minus too. | `3a84d46` | `pct helper shows the unicode minus with the down arrow`: the `'▼ −'` expression was missing |
| 5 | KITA NGAYON uses a new `ilMoneyKita()`: whole pesos when the amount rounded to 2 decimals is ₱1,000 or more either way (e.g. "▲ ₱2,638", "▼ −₱1,500"), otherwise `ilMoney` with two decimals. It's used only in the KITA NGAYON cells: the main row, TOTAL, and the narrow-screen duplicate in `_il_expand`. `ilMoney` and every other cell are unchanged. | `164300d` | `kita ngayon uses whole pesos from 1000 and only there`: no `ilMoneyKita` helper |

**Outputs on the final tree:**
- `php.bat artisan test --compact` → `Tests: 1 failed, 3 skipped, 232 passed (2130 assertions)`. The only failure is the known baseline `Tests\Feature\ExampleTest > the application returns a successful response` ("Expected response status code [200] but received 302."). The 3 skipped are the Boardroom live tests. `ItemPageTest` alone gives 31 passed (523 assertions).
- `php.bat -l tests/Feature/Item/ItemPageTest.php` → "No syntax errors detected in tests/Feature/Item/ItemPageTest.php". It's the only changed PHP file; the rest are Blade views, `TODO.md` and this file.
- `npm run build` → `vite v6.3.5 … ✓ built in 2.21s`. The bundle is unchanged (`app-DNxiirP_.js 35.32 kB`).
- `git diff --stat 5879e63 164300d` touches only `index.blade.php`, `_table_new.blade.php`, `_il_expand.blade.php`, `ItemPageTest.php` and `TODO.md`. `_table_old`, `_agg_cells` and `owner/*` are untouched.

**Review:** `skeptic-reviewer` ran a scoped review at standard depth (sonnet) and found no blocker or major.
- **Confirmed:**
  - the queries equal the CSS breakpoints
  - there's no double subtract when LIFECYCLE or ACTION is switched off in the column settings
  - `ilVw` is reactive
  - `!important` overrides the base `.expand-wrap{overflow-x:auto}`
  - there's no scope creep and no `x-html`
- **Minors, accepted in `TODO.md`:**
  - **Page cards keep two decimals.** Their Prof.Profit(1D) still shows two decimals, because the fix list named only the KITA NGAYON cells. Say if you want the whole-peso rule there too; it's one line.
  - **Tiny negatives.** `ilPct(-0.04)` shows "▼ −0.0%".
  - **Loose colspan test.** It matches the code by text order only.
- **Not tested at runtime:** the colspan on resize, the 24 px chevron and the panel growing taller are covered by markup assertions only. For the live check:
  - Open › at 1,920 px, then shrink the window to 1,400 and 1,300 px. The expanded block should stay as wide as the table, with no phantom column and ITEM not narrowing.
  - An open Campaigns panel should have no scrollbar of its own and just grow taller.
  - The › button should sit inside its cell.

## Suggestions for Busing

- **Allow `node --test` for one small pure JS file** with the `/item` helpers (states, pills, formats, sort ranks), so they can be tested without a new package.
- **Once the new layout has run for a while, remove `?layout=old`** (`_table_old.blade.php` and `_agg_cells.blade.php`).
- **Give the shared campaigns panel AA-contrast greys and 12 px text.** It would read better on both pages, but it changes `/owner/private`, so it needs its own handoff.
