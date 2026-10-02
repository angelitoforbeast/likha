# Result 006: /item simple "To order" view, in English

Status: **done, waiting for Mira's review of branch `feat/item-simple-view`**, cut from `develop` at `d9606c8`.
- Spec: `docs/specs/006-item-simple-view.md`.
- Plan: `docs/plans/006-item-simple-view.md`.
- Mira's "go": all of T1–T6, the width budget and the seven recommendations. Each is recorded as a ruling below.
- There's no PR, so this file stands in for the PR body. Nothing was pushed, merged or deployed.

## Summary

`/item` now opens on a simple **To order** view with a **Sales & Profit** tab next to it. The page is in plain English everywhere except the Old view table. `?layout=old` still renders the original table unchanged. 005's 11-column layout is gone: `_table_new.blade.php` is deleted.

**Tabs (T1):**
- To order is the default; Sales & Profit is `?tab=sales`. Only the exact value `sales` selects it, and the value is never written into the page.
- The tab stays in the URL through date and refresh reloads and the Old view / New view links. Switching tabs resets any header sort to the default order.
- The toolbar has "🗂 Old view" / "✨ New view". The chips read All · Need a supplier · Has a quote, not ordered · Ready to order · Ordered, waiting, with the same keys, logic and counts.
- The category filter reads "All" / "No category". Empty, loading and error rows, alerts and confirms, and the action-note, edit-row and photo modals are in English.

**To order (T2, T3):** Item · Next step · Qty to order · Days left · Profit (7 days) · Trend · ›.
- **Item:** photo, name, "1,761 on hold". The row is peach when there's no running page.
- **Next step:** one state, first match wins:
  - "—" when there's no data
  - 🟠 Count the stock first
  - ⚪ Barely selling
  - 🟢 OK
  - 🔴 Find a supplier (CEO view only)
  - 🔴 Order from <supplier> now, or "Order now"
  - 🟡 Order from <supplier> by Oct 24, or "Order by Oct 24"

  The supplier is the cheapest quote with a price above 0, else the latest PO supplier. A short reason line sits under the state: "1,761 waiting + 10 days of sales", "only the orders waiting", "2,000 arriving", "enough stock" or "count is below zero".
- **Qty to order:** "3,299 pcs" in bold, or a grey "—" at 0. The CEO also sees "≈ ₱38,335", from the cheapest priced quote or else item value ÷ N, with " (min 3,000)" when the qty is under that quote's MOQ.
- **Days left:** "Short 12 days" in red, or "29 days" coloured by the server's red / amber / green. Over 365 it shows "1 year+"; under 1, "<1 day". It's a grey "—" for no data, Count the stock first and Barely selling.
- **Profit (7 days):** "▲ 19.9%" with "₱18,400" under it, combined over all variants of the base item; the tooltip names the variants. The cell has a light tint of the colour from your PROF.%(7D) rule. With no saved rule the default is green at 15% and up, yellow from 0 to 15%, red below 0. No data gives a grey "—".
- **Trend:** 🆕 New · 📈 Growing · ✅ Steady · 🔄 Active · 📉 Slowing · 🚫 Stopping · 💤 Stopped, with a "losing money" tag and "(manual)" with its tooltip.
- **Default sort:** red, orange, yellow, green, grey, then no data, then hold descending. Every header sorts; the first click on Next step puts red first.
- **TOTAL:** "To order now: 48 items · 31,250 pcs · ≈ ₱1,204,000" over the red rows shown, or "To order now: nothing urgent". The ₱ part is CEO only.

**Sales & Profit (T4):** Item · Orders today · Profit today · Profit % · Ad spend · Cost per order · ›.
- **Profit %** has four sortable sub-columns: Today, 3 days, 7 days and 1 month. Each is tinted by your rule for that window.
- **Cost per order** shows CPP with "break-even ₱52" under it.
- **Total (shown)** covers the items on screen.

**Column visibility:** both views are fixed column sets. A column whose data id isn't granted to the role on `/owner/column-settings` isn't rendered.

**Details panel, › (T5), in English:**
- **For every role:**
  - sales a day, with "Average of the last N days"
  - stock / incoming
  - "Arrives in 7 days + 3 days buffer (Growing)"
  - how the qty is worked out
  - trend detail
  - running pages / "No running ad"
  - cost per piece
  - category
  - Change / Add photo, Copy
  - RTS/DEL/INT, TCPR and the profit fields
- **CEO only:**
  - the ✎ lead time / buffer editor
  - the sourcing-list info
  - supplier lines
  - quote lines with inline add / edit / delete
  - "CEO value"
  - the category select
- **Page cards and the campaigns panel:** they behave as in 005, and the shared include `owner/_private_expand_inline.blade.php` is untouched.

**Widths (T6):** the column widths follow spec §8.
- **≥ 1,366 px:** the To order table's minimum width is 1,096 px and Sales & Profit's is 1,122 px, against about 1,333 px usable, so there's no horizontal scroll.
- **1,100–1,365 px:** the minimums drop to 1,056 / 1,034 px.
- **Below 1,100 px:** one two-column card per item in both views.
- **Text:** nothing under 11 px, and item names wrap.
- **Cleanup:** 005's leftover helpers and CSS that nothing uses any more are removed.

**Unchanged:** none of these appear in `git diff --stat d9606c8 HEAD`:
- `app/`, `routes/`, `database/`
- `resources/views/owner/*` (so `/owner/private` and the column settings)
- `_table_old.blade.php`, `_agg_cells.blade.php`

Also unchanged: `/jnt/supply`, every endpoint, every formula. There's no migration and no new dependency.

## Done-when evidence

| Done-when | Evidence |
|---|---|
| Plan sent to Mira (width budget for both views at 1,366 px), her "go" before code | The plan, with the width table, was the final message of the first run. The only commits before the go are `7bcc356` (handoff files) and `0443d99` (spec and plan; budget in spec §8). Mira's go came in the next message. The first code commit is `c60472e`. |
| `artisan test`: no new failures; markup assertions in §4 | Baseline on `d9606c8`, run in this session: `Tests: 1 failed, 3 skipped, 232 passed (2130 assertions)`. Final tree: `php.bat artisan test --compact` gives `Tests: 1 failed, 3 skipped, 246 passed (2547 assertions)`. The only red test is `Tests\Feature\ExampleTest > the application returns a successful response` ("Expected response status code [200] but received 302."), the known baseline failure. The 3 skipped are the Boardroom live tests. Coverage is listed below the table. |
| `php -l` clean; `npm run build` OK | `php.bat -l tests/Feature/Item/ItemPageTest.php` gives "No syntax errors detected in tests/Feature/Item/ItemPageTest.php". It's the only PHP file changed in 006. `npm run build` gives `vite v6.3.5 … ✓ built in 4.17s`. The bundle is unchanged (`app-DNxiirP_.js 35.32 kB`) because the page JS is inline in Blade. |
| "How to check in the browser" list (1,366 px, 1,920 px, phone, Marketing view, Old view) | See the section below. |
| skeptic-reviewer ran (Spec / Correctness / Declined to judge); findings handled | Six reviews at standard depth (sonnet), one per task: T1 to T6, with T4 also covering the T2-review fix commit. There was no blocker or major, so no fix loop. The cheap minors are fixed in `d57a856` and `2a6e0d6`; the rest are accepted in `TODO.md`. See "Review findings". |
| RESULT filled: evidence, rulings, deploy notes, merge danger and revert; commits on branch; clean tree | This file. Every commit from `7bcc356` on is on `feat/item-simple-view`. The last commit adds this file, `TODO.md`, the README row and the reviewer memory. `git status` is clean after it. |

**What the tests cover** (`tests/Feature/Item/ItemPageTest.php`, 45 tests, CEO and Marketing renders of `/item`):
- **Tabs:** both present, To order first; the `ilTab` exact-`'sales'` check; `setTab` resets the sort.
- **Headers:** both header sets in order with sort keys and one `title` each; the Profit % sub-headers and their keys.
- **To order texts:**
  - the six Next step states and their reason texts
  - "pcs", "≈ ₱", "(min "
  - "Short ", "1 year+", "<1 day"
  - the seven Trend labels and "losing money"
  - `ilCfTint` reading `__COL_FORMAT__` with `color-mix` and the 15 / 0 default, and no fill for a rejected rule colour
- **Sort and TOTAL:** the rank sort and the Old view's hold sort, `il_next` / `il_profit7`, the TOTAL texts, and "nothing urgent".
- **Sales:** the cells, the column gates, the single total row, and the sort keys resolving in `_itemSortValue`.
- **Chips and Taglish:** the five English chip labels, with the Taglish ones absent. About 65 replaced Taglish strings are absent from the default render, in the markup or in the whole page.
- **CEO only:** absent for Marketing are "Find a supplier", "Order from ", "≈ ₱", the ₱ total, the quote editor, ✎ lead/buffer, supplier lines, sourcing info, "CEO value" and the category select.
- **Details:** the English labels, and the campaigns panel still inside `il-camp`.
- **Widths:** the column widths per view, both media blocks with their rules, the card labels, and 005's leftovers absent.
- **Fonts:** no font size under 11 px in the new partials and `.il-` CSS.
- **No `x-html`:** no `x-html` attribute in any item view.
- **Old view:** `_table_old.blade.php` pinned by hash to `d9606c8`, and every Old-view assertion from 003, 004 and 005 kept.

**Covered by markup only (no JS runner, no browser):** none of the following was ever run.
- which Next step, Days left and Trend state a row actually gets
- the numbers and the cost line
- the tint colours
- the sort order and the TOTAL sums
- the tab switch at runtime
- the widths, the narrowing and the phone cards

The numbers themselves come from `/item/stock` and the page data, which are tested on the server and didn't change.

**Red runs** (first failure per slice):
- **T1:** `test_default_render_has_both_tabs_to_order_first_and_both_header_sets_with_titles` failed with "Failed asserting that 0 is identical to 2." (no `role="tab"` yet).
- **T2:** `test_to_order_cells_show_one_english_state_each_behind_their_column_grant` failed with "… contains "x-for="N in [ilNext(G.item_name)]"".
- **T3:** `test_every_to_order_header_sorts_and_switching_tab_resets_the_sort` failed with "… contains "tabindex="0" @click="sb('il_next')" …"".
- **T2-review fix:** `test_t2_review_minors_are_fixed` failed on the missing rejected-colour `return '';` in `ilCfTint`.
- **T4:** `test_sales_headers_sort_by_keys_the_item_sort_resolves` failed with "… contains "tabindex="0" @click="sb('proj_pct_last_day')" …"".
- **T5:** `test_details_panel_taglish_is_gone_from_the_new_view` failed on the old Taglish details text.
- **T6:** `test_each_view_has_the_spec_column_widths_and_a_minimum_that_fits_1366` failed with "_table_order: Item col is flexible — … matches PCRE pattern "/<colgroup>\s*<col class="il-w-item">/"".

The `_table_old` hash test is a pinning test and was green from the start, by design.

## How to check in the browser

Open `/item` as the CEO, then again with 👁 View: Marketing.

**At 1,366 px wide:**
- [ ] There's no horizontal scrollbar on the page in either tab, and none inside an opened Campaigns panel.
- [ ] To order shows 6 columns plus ›. Each cell is one line plus at most one small line.
- [ ] Glow Tape: "🔴 Find a supplier", or "Order from … now" if it has a supplier.
  - [ ] Under it, "1,761 waiting + 10 days of sales".
  - [ ] "3,299 pcs" and "≈ ₱98,970".
  - [ ] "Short 12 days" in red.
  - [ ] A tinted "▲ …%" with ₱ under it.
  - [ ] "🆕 New".
- [ ] Invisible Belt: "Order from Kelly Uy now" and "≈ ₱38,335".
- [ ] Seat Cover: "🟢 OK" with "2,000 arriving", qty "—", a green "N days", and "✅ Steady · losing money" if it applies.
- [ ] Nail Care Pen: "⚪ Barely selling" with "only the orders waiting", "517 pcs", Days left "—", and "📉 Slowing". The row is peach if it has no running page.
- [ ] The Profit (7 days) tint matches the colour your PROF.%(7D) rule gives on `/owner/private`, only lighter. With the default rule set, 19.9% is light cyan.
- [ ] Red rows come first, then orange, yellow, green and grey.
  - [ ] A header click sorts.
  - [ ] The first click on Next step puts red first.
  - [ ] Switching tabs resets the sort.
- [ ] The TOTAL row reads "To order now: N items · N pcs · ≈ ₱N".
- [ ] Sales & Profit shows Orders today, Profit today, Profit % (Today / 3 days / 7 days / 1 month, tinted per window), Ad spend and Cost per order with "break-even ₱…". The Total (shown) row changes with the chips and the category filter.
- [ ] Open › on an item with pages:
  - [ ] the details read in English
  - [ ] ✎ lead time / buffer saves
  - [ ] "+ supplier quote", edit and delete work
  - [ ] Change photo and Copy work
  - [ ] each page card's ✎ (RTS, Promo, Item Val., CEO, Action) opens the same modal as before and saves
  - [ ] Campaigns › opens and wraps with no sideways scroll
- [ ] Each chip filters with the same counts as before. Reloading with `?tab=sales` opens Sales & Profit.
- [ ] Every toolbar button still works:
  - [ ] dates, 1D Date, Refresh, Expand / Hide all, Copy all
  - [ ] Columns, Excluded, Logs, Primary Items
  - [ ] Save Snapshot, Snapshots, Matrix, Daily
  - [ ] the view toggle

**At 1,920 px wide:**
- [ ] The Item column takes the extra width, and the other columns keep their size.

**At 1,100–1,365 px** (e.g. a 1,280 px window):
- [ ] There's no sideways scroll. Profit % values like "▼ −100.0%" may wrap; check they're still readable.

**On a phone** (or a window under 1,100 px):
- [ ] One card per item:
  - [ ] To order: name + hold, then Next step + reason, then Qty | Days left, then Profit | Trend, and › in the corner.
  - [ ] Sales: Orders | Profit today, then the four % values as a 2×2 grid, then Ad spend | Cost per order.
- [ ] There's no sideways scroll, and the toolbar and tabs are usable.

**Marketing view:**
- [ ] No supplier names: rows read "Order now" or "Order by Oct 24", and "Find a supplier" never appears.
- [ ] No "≈ ₱" in Qty or TOTAL.
- [ ] No ✎ lead time / buffer, no quote lines or editor, no sourcing info, no "CEO value", no category select.
- [ ] A column whose data the CEO didn't grant to Marketing on the Columns page isn't shown.

**Old view** (`/item?layout=old`):
- [ ] The original table, exactly as before: the same columns, fills, drag-reorder and TOTAL.
- [ ] The toolbar, chips and modals around it are now English, by ruling. "✨ New view" goes back and keeps the dates, chip and tab.

## Files changed

- **New:**
  - `resources/views/item/_table_order.blade.php`
  - `resources/views/item/_table_sales.blade.php`
- **Deleted:** `resources/views/item/_table_new.blade.php` (005's 11-column table).
- **Changed:**
  - `resources/views/item/index.blade.php`: tabs, English chrome, the 006 helpers, sort, totals and CSS, plus removal of 005 leftovers
  - `resources/views/item/_il_expand.blade.php`: the details panel in English, with the CEO editors moved in
  - `resources/views/item/_il_lifecycle.blade.php`: now the English Trend cell
  - `tests/Feature/Item/ItemPageTest.php`
- **Docs and records:**
  - `docs/specs/006-item-simple-view.md`
  - `docs/plans/006-item-simple-view.md`
  - `TODO.md`
  - `handoff/006-item-simple-view/*`
  - `handoff/README.md`
  - `.claude/agent-memory/frontend-developer/*`
  - `.claude/agent-memory/skeptic-reviewer/repo_weak_spots.md`

## Migrations

None. No PHP outside the tests changed, and there's no config key, catalog entry or data migration.

## Amendments applied

None arrived. Mira's go accepted the plan and all seven recommendations; they are the first seven rulings below.

## Rulings

- Ruling: the profit fill is a light tint (`color-mix(in srgb, <rule colour> 22%, white)`) of the colour from the owner's matching rule, with dark text (**Mira's go, point 1**) — the owner's rules use full-strength colours, and the handoff asks for a light fill — cost if wrong: the cell is paler than on `/owner/private`.
- Ruling: the owner's own PROF.% rule wins, even when 19.9% comes out cyan rather than the wireframe's green (**point 2**) — "colours from Busing's own conditional-format rule" — cost if wrong: none; he changes the rule on `/owner/column-settings`.
- Ruling: role column grants are kept as a data gate, so a column whose source ids are all hidden for the role isn't rendered (**point 3**) — nothing hidden today becomes visible — cost if wrong: a role without e.g. `proj_pct_7d` doesn't see Profit (7 days).
- Ruling: the toolbar, chips, category filter and modals are English in the Old view too, and the Old view table is unchanged (**point 4**) — that chrome is shared by both views — cost if wrong: Old view users see English buttons around the old table.
- Ruling: Days left is a grey "—" for Barely selling and Count the stock first (**point 5**) — the wireframe's Nail Care Pen row, and the uncounted 0 isn't a real count — cost if wrong: a barely-selling item that is short of its hold shows no day count; Next step and Qty still show it.
- Ruling: To order's Profit (7 days) is combined over all variants of the base item, and the tooltip says so and names them. Sales & Profit's four % values are per item row, as in 005 (**point 6**) — the handoff asks for the 004 base figure — cost if wrong: a multi-variant item's 7-day % can differ between the two tabs.
- Ruling: supplier names never show in Marketing view: rows read "Order now" or "Order by …", and "Find a supplier" is CEO only (**point 7**) — supplier names are CEO-only data — cost if wrong: none.
- Ruling: two Old-view-pinned strings stay Taglish in the Old view through a `layoutOld` branch: "Walang category" and the lead/buffer number error. The new view gets "No category" and "Enter a number (0–255) for the lead time and the buffer." — 004's Old-view tests pin them and "Old view stays as is" — cost if wrong: two Taglish strings in the Old view; removing the branch is one line each.
- Ruling: `_table_old.blade.php` is pinned by an LF-normalised sha1 against `d9606c8` — so a CRLF checkout on Windows doesn't fail the test — cost if wrong: none.
- Ruling: the Item cell and the › details row are written twice, once in each table partial — two copies is under the "third repetition" bar, and only one table is in the page at a time — cost if wrong: one change has to be made in two places.
- Ruling: when the owner has rules for a column but none matches, the cell has no fill. A matched rule whose colour isn't a plain hex or colour name also gives no fill. The 15 / 0 default applies only when no rule is saved for that column — the owner's rules decide — cost if wrong: an unmatched value shows unfilled.
- Ruling: `ilCfTint` evaluates the rules with no reference row — the To order value is a combined base-item figure, not a page row — cost if wrong: a rule that compares against another column is skipped (`TODO.md`).
- Ruling: Next step shows "—" when `order_qty` is null — there's nothing to act on — cost if wrong: none.
- Ruling: Next step and Qty to order follow the `order_qty` grant, Days left the `doi` grant, Profit (7 days) `proj_pct_7d` (its ₱ line also `proj_prof_7d`), and Trend `lifecycle` — this is 005's id mapping — cost if wrong: hiding `doi` hides Days left but not Next step.
- Ruling: a quote priced 0 or less counts as no price, both for the cost line and for the supplier pick — a ₱0 quote isn't a real offer — cost if wrong: an item whose only quote is ₱0 shows its PO supplier or "Find a supplier" (`TODO.md`).
- Ruling: "To order now" counts the red rows (Find a supplier, Order … now). Its ₱ sums only the rows that have a cost line, unrounded, rounded once — spec §6 — cost if wrong: the ₱ figure understates when red rows have no price (`TODO.md`).
- Ruling: the first click on Next step sorts with red first; every other header still sorts descending first — clicking the urgency column should not bury the urgent rows — cost if wrong: none.
- Ruling: the Sales Total (shown) row tints its four % values with the same per-window rule, and has no break-even line — one colour meaning per column, and break-even is a per-page value — cost if wrong: none.
- Ruling: the "Profit %" spanning header isn't sortable; its four sub-headers are — there's no single value to sort it by — cost if wrong: none.
- Ruling: 005's narrow-screen duplicates in the details are removed: action note, stock, sales a day, profit today and ads — those columns no longer exist, and stock and sales a day are now always in the details. The action note is still on each page card — cost if wrong: on narrow screens the latest action note is only on the page cards.
- Ruling: the Lead time line in the details isn't tied to a column grant, as in 005 — it's a supply setting, not a column — cost if wrong: a role without the `lifecycle` grant still sees the trend word in it (`TODO.md`).
- Ruling: the Item column's minimum width is enforced through each table's `min-width` — a `<col>` can't have a min-width in a fixed-layout table — cost if wrong: just above 1,100 px a few pixels of scroll could appear inside the existing safety-net wrapper. The reviewer's arithmetic says it fits.
- Ruling: phone cards use a fixed two-column grid — it gives the 2×2 Profit % layout and predictable pairs — cost if wrong: on very narrow phones long values wrap.
- Ruling: the unknown-lifecycle "(—)" case and the Trend detail repeating its tooltip are left as they are — the server always sends one of the seven lifecycles, and the visible tip is the "lifecycle detail" the handoff asks for — cost if wrong: one repeated sentence in the details.
- Ruling: all tasks ran one after another on this branch, with no worktrees and one developer — every task edits `index.blade.php`, and `git worktree` isn't an allowed command — cost if wrong: none.
- Ruling: no browser check — running the app reads the local `.env` database (handoff §7) — cost if wrong: layout problems surface in Mira's live check (list above).

## Review findings

`skeptic-reviewer` ran at standard depth (sonnet) once per task: T1, T2, T3, T4 (with the T2-review fixes), T5 and T6. No review found a blocker or major, so there was no fix loop and nothing went to Mira. Minors were fixed in `d57a856` (T2 review) and `2a6e0d6` (T3/T4 review) or accepted in `TODO.md`.

### Spec

- **Fixed:**
  - `ilCfTint` fell back to the default band when a matched rule's colour was rejected; it now gives no fill (`d57a856`).
  - The Profit (7 days) ₱ line ignored the `proj_prof_7d` grant (`d57a856`).
  - A ₱0 quote counted as a price (`d57a856`).
  - The first click on Next step buried the red rows (`2a6e0d6`).
  - A hidden sort survived a tab switch (T3, `95a28d3`).
- **Accepted (rulings above / `TODO.md`):**
  - the Total (shown) tint
  - the ₱ total covering only priced rows
  - the ₱0-quote chip mismatch
  - the Lead time line not tied to a grant
  - the repeated Trend tip
  - no sort in card view (inherited from 005)
- **Checked and fine:**
  - the Next step order matches §4.1 and `restockSet()`
  - 1,783 × ₱21.50 rounds to "≈ ₱38,335"
  - the width sums are 796 / 822 (+300) and 1,056 / 1,034 in the narrow band
  - CEO-only content is gated in both Blade and JS

### Correctness

- **Fixed:**
  - the Sales total % values lacked `il-num` (`2a6e0d6`)
  - unused 005 helpers and CSS removed (`2a6e0d6`)
  - `ilDays` was kept on a wrong claim that a test needed it; it's now removed
- **Confirmed:**
  - no `x-html`, and staff text only goes through `x-text` / `:title`
  - `?tab` isn't echoed
  - `ilCfTint` can't inject CSS: the colour is allow-listed, and the rules are trusted CEO config
  - nothing an Old-view path calls was removed (`stockTip` is kept for `_agg_cells`)
  - every action listed in handoff §4 req. 3 is still reachable
  - the `.il-` / `#il-table-*` CSS can't reach `_table_old` or `/owner/private`
  - the frontend-developer memory holds no secrets or paths
- **Accepted in `TODO.md`:**
  - no runtime JS test
  - rules evaluated without a reference row
  - possible wrapping at 72 px
  - the `il-w-item` marker class

### Declined to judge

- Real rendering at 1,366 / 1,920 / 1,100–1,365 px and on a phone, tint contrast, the sticky two-row Sales header, and the cards. No browser was used, so these are on the checklist above.
- Alpine behaviour at runtime: state choice, sort, totals, tab switch. There's no JS runner.
- Whether `lifecycle` is meant to be hidden from Marketing. That's a policy question for Mira (`TODO.md`).

## Deploy notes for Mira

1. Deploy from this branch after your review. There's no migration and no config change.
2. Run `php artisan view:clear`; the Blade views changed.
3. Run `npm run build` only if production builds assets on deploy. The bundle didn't change.
4. No `item-summary` cache bump is needed; no endpoint output changed.
5. After the deploy, do the browser checklist above as the CEO and in Marketing view. If the new view blocks ordering work, staff can use `/item?layout=old` right away.

## Merge danger

- **Two-way door.** The change is view-only: Blade and inline JS/CSS, plus tests. There's no PHP, route, query, migration or data write.
- **Blast radius:**
  - `/item`'s default look: To order and Sales & Profit replace 005's layout.
  - The toolbar, chips, filter and modals in both views: now English.
  - `/owner/private`, `/owner/column-settings`, `/jnt/supply` and every endpoint: untouched.
- **Revert:**
  - **Soft revert, no deploy:** use `/item?layout=old`. It renders the original table, and the "🗂 Old view" link goes there.
  - **Full revert:**
    1. Revert `c60472e..2a6e0d6`, or the squash commit; leave the docs and memory commits.
    2. Run `php artisan view:clear`.

    That brings back 005's layout. There's no database step.
- **One-way part:** none.

## Suggestions for Busing

- **Allow `node --test` for one small pure JS file** with the `/item` helpers (Next step, Days left, tint, sort ranks), so they're tested at runtime without a new package.
- **Check the PROF.% colour rules on `/owner/column-settings`.** The seeded set (< 5 red, < 10 orange, < 20 cyan, ≥ 20 green) is what tints Profit (7 days). If you want the 15 / 0 green-yellow-red you described, set it there; no code change is needed.
- **Once the new view has run for a while, remove `?layout=old`** (`_table_old.blade.php` and `_agg_cells.blade.php`) in its own handoff.
