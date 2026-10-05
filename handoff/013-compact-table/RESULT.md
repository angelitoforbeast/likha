# Result 013: the Daily Summary table on owner/private becomes truly compact

Status: **built, reviewed and tested; not seen in a browser.** Branch `feat/013-compact-table`, cut from `617fb34`. Nothing was pushed, there is no PR and nothing was deployed. No browser and no dev server were available in this run, so every width, height and zoom figure below is an estimate from reading the CSS and the markup, not a measurement. The browser check at the end is what decides whether this is good enough to ship.

## Plan

Headless run: nobody could give the "go", so this plan was committed before any code and then built (see Rulings, 1). It is also the spec the developer and the reviewer worked from; done-when 1 allows no file under `docs/`.

Tier: **medium** (UI screen, no new way in for untrusted input). One task, side `frontend`: `frontend-developer`, then `skeptic-reviewer` at standard depth.

### Design

All compact rules live in `resources/views/owner/_fit_to_width.blade.php` (the partial 011 added: its `<style>` and its plain script). `private.blade.php` gets only what CSS cannot do alone.

1. **One switch, two states.** `#owFitSwitch` and the key `owFitMode` stay. State `fit` (default, anything that is not exactly `full`) is now "Compact": the script puts the class `ow-compact` on the `[data-ow-fit]` card, then measures and zooms only what is still too wide, as 011 does. Label `↔ Compact NN%` (100% when no zoom is needed). State `full` is untouched 011 behaviour: no class, no zoom, label `↔ 100%`, horizontal scroll.
2. **Cells get a column id only in compact.** The script sets `window.__owCompact` while the page is parsed and fires a window event `ow-fit-mode` on every change. The Alpine component keeps a flag `owCompact` (read from that global, updated by the event). The four `x-for="col in cols"` cells (main `th`, repeated `th` of an expanded section, body `td`, total `td`) get `:data-col="owCompact ? col.id : null"`. Alpine removes an attribute bound to `null`, so in the old layout the cells carry no new attribute and no rule can match them.
3. **CSS, all under `.ow-compact > table > …` with child combinators** (so the nested RTS table and the campaigns panel are not touched): tighter padding and header, floors for Item, Promo and the text columns, the RTS block's inner cells tightened, the text columns wrapped and clamped to three lines with the originals left in the file. With `table-layout:auto` the number columns end at their content width and the text columns, whose max-content is a whole sentence, take what is left. *(The first cut's numbers were tightened after the width arithmetic; the final values are in the table under done-when 4.)*
4. **Header wrap.** Labels such as `Prof.%(1M)` have no space. A small method `hdr(label)` returns the label unchanged in the old layout and, in compact, with a zero-width space before `(`; the two header `x-text` use it. Same text, one more break point.
5. **Actions view (R4).** Button `#owActionsBtn` in the partial, rendered only under `$effectiveIsCEO` (actual CEO in CEO view: the condition of the Claude and CEO columns). The script toggles the class `ow-actions` on the card and keeps `owActionsView` in localStorage. CSS hides every `[data-col]` cell except `cpp`, `proj_pct`, `proj_pct_1d`, `proj_pct_3d`, `proj_pct_7d`, `hold` and the five text columns; Page and Item are fixed cells without `data-col`, so they stay. `cols` is never changed, so sorting and `saveCols()` see the full list and nothing is written to the column settings. In the old-layout state the button is disabled and the class is off.
6. **R3 (merge): skipped,** reasons under Rulings.
7. **Tests first** (`tests/Feature/OwnerPrivate/FitToWidthTest.php`, extended).
8. Full suite once at the end, evidence below, no push, no PR.

## Summary

1. The switch 011 added is now "Compact" (default on): tight padding, number columns as wide as their content, wrapped headers, and the Action, Claude and CEO text columns take the remaining width with up to three lines; zoom is applied only to what still does not fit, and the other state of the switch is the table as before 011.
2. A CEO-only "☰ Actions" button shows PAGE, ITEM, CPP, the four Prof.%, HOLD and the five text columns only; it lives in the browser (`owActionsView`) and never touches the column settings. The optional merge (R3) was skipped.
3. Tests are green (suite: 514 passed, 3 skipped, 1 failed, the old ExampleTest), but **nothing was seen in a browser**; by my estimate the CEO's daily view lands at about 95 to 100% zoom at 1707 px with about 135 px per text column, and rows per screen go from about 12 to about 13 to 15, which is less than "clearly more" (see "What you should know before the browser check").

## What you should know before the browser check

- **Rows per screen may improve only a little.** Today's rows look small because the whole table is zoomed to 76%. Compact removes most of that zoom, so the text is about 30% larger, and a row with three lines of Action text plus the author line is about 56 px against about 48 px for a row whose tallest content is the RTS block. R1a's target (RTS-block row = block plus small padding) is met by the CSS; R1c's three lines are what make text rows taller than that. If more rows matter more than three lines, the one-line change is `-webkit-line-clamp:2` in the partial.
- **Text columns at 1707 px are about 135 px each with the full daily column set** (about 50 characters over three lines instead of about 20). That is what 16 number columns leave. The Actions view is where the texts get real room (about 230 px each with all five text columns).
- **The Item column is data-dependent.** The secondary-item lines under an item name do not wrap, so the column is as wide as the longest of them. I could not see the data; if that line is long, the number side is wider than my arithmetic and the zoom drops below 100%.
- **Anyone who chose "100%" under 011 stays in the old layout** (their stored `owFitMode` is `full`). They get compact by pressing the switch once.

## What changed

| File | What | Why |
|---|---|---|
| `resources/views/owner/_fit_to_width.blade.php` | Compact and Actions CSS (all under `.ow-compact` / `.ow-actions`); the `#owActionsBtn` button under `$effectiveIsCEO`; the script now adds and removes the two classes, announces the state to Alpine (`window.__owCompact`, event `ow-fit-mode`), keeps `owActionsView`, labels the switch `↔ Compact NN%`, and watches `data-col` changes | R1, R2, R4 in the partial 011 already owns; one switch |
| `resources/views/owner/private.blade.php` | 20 lines: flag `owCompact`; listener in `init()`; method `hdr()`; `:data-col="owCompact ? col.id : null"` on the four `x-for` cells; the two header `x-text` use `hdr(col.label)`; `colDragStart` refuses a drag while the Actions view is on | Cells need a column id for CSS to address them; labels need a break point; a drag in a partial view must not post a column order |
| `tests/Feature/OwnerPrivate/FitToWidthTest.php` | 7 new tests | done-when 2 and the reviewer's missing cases |
| `handoff/013-compact-table/`, `handoff/README.md` | handoff, this file, index row | protocol |

Commits (`git log --oneline 617fb34..HEAD`, before this file's own `docs:` commit):

```
3fe106d fix: tighten compact widths and block column drag in Actions view
e848ab0 feat: compact table layout with Actions view for the owner private page
1195b58 docs: plan for handoff 013 compact-table
10268da docs: handoff 013 compact-table, result skeleton and index row
```

## Evidence per done-when item

**1. Only the allowed paths changed.** `git diff --stat 617fb34..HEAD`:

```
 handoff/013-compact-table/HANDOFF.md          |  61 +++++++++++++
 handoff/013-compact-table/RESULT.md           |  59 ++++++++++++
 handoff/README.md                             |   1 +
 resources/views/owner/_fit_to_width.blade.php | 125 ++++++++++++++++++++++++--
 resources/views/owner/private.blade.php       |  20 +++--
 tests/Feature/OwnerPrivate/FitToWidthTest.php |  83 +++++++++++++++++
 6 files changed, 338 insertions(+), 11 deletions(-)
```

(RESULT.md's line count grows with this commit; no other path is added.) `git diff 617fb34..HEAD -- resources/views/item/_table_old.blade.php | wc -l` prints `0`. No new partial was needed.

**2. Feature tests.** `php.bat artisan test --filter=FitToWidthTest`:

```
   PASS  Tests\Feature\OwnerPrivate\FitToWidthTest
  ✓ owner private carries the fit helper and switch exactly once with data set " c e o"
  ✓ owner private carries the fit helper and switch exactly once with data set " marketing"
  ✓ ceo gets compact css and one actions button
  ✓ actions button is ceo view only
  ✓ data col is only the alpine binding so old layout is unchanged
  ✓ every new css rule is scoped so old layout cannot match
  ✓ actions whitelist ids are real columns
  ✓ column drag is refused while actions view is on
  ✓ fit script never writes html
  ✓ pages out of scope do not carry the fit helper
  ✓ fit factor is one pure function with its guards

  Tests:    11 passed (101 assertions)
```

| Done-when 2 asks | Test |
|---|---|
| CEO: page renders 200 | every test goes through `page()`, which asserts `assertOk()` |
| compact switch and Actions button in the HTML | `ceo gets compact css and one actions button` (exactly one of each id) |
| Actions button absent for a non-CEO role | `actions button is ceo view only` (role Marketing, and a CEO at `?view_as=marketing`) |
| CSS rules for the compact state present | `ceo gets compact css…` (`.ow-compact > table`, `-webkit-line-clamp:3`, `.ow-actions > table`) and `every new css rule is scoped…` |

Red runs, as the developer reported them: `ceo gets compact css and one actions button` failed first with `expected exactly one: id="owActionsBtn"` (0 is not 1); `column drag is refused while actions view is on` failed first with `Failed asserting that false is true`. **TDD slip, stated plainly:** in the first slice the developer edited `private.blade.php` before writing the tests, so `data col is only the alpine binding…` and `fit script never writes html` never had a red run; `every new css rule is scoped…` and `actions whitelist ids are real columns` are characterisation tests asked for by the reviewer and were green on arrival.

**3. Full suite on the branch head** (`php.bat artisan test`, run on `3fe106d`):

```
   FAILED  Tests\Feature\ExampleTest > the application returns a successful response
  Expected response status code [200] but received 302.

  Tests:    1 failed, 3 skipped, 514 passed (5352 assertions)
  Duration: 64.17s
```

Base: 507 passed, 3 skipped, 1 failed. Now 514 passed (the 7 new tests), the same 3 skipped and the same single failure.

**4. Column → width rule in compact layout.**

Common rules: body cells `padding:2px 4px`, 12px, `white-space:nowrap` unless stated (9 px of padding and border per column); header cells `padding:4px 3px`, 10px, uppercase, no letter-spacing, wrapping at spaces and before `(` (7 px per column); the inline `min-width:<minw>px` is overridden with `min-width:0 !important` unless a floor is named. "Content" means the widest cell in the column including the bold TOTAL row. Estimates assume Segoe UI (the Tailwind CDN's default font on Windows) and typical values such as `₱12,345.67`; they are mine, not measured.

| Column id | Header in compact | Width rule | Est. px |
|---|---|---|---|
| (page, fixed) | PAGE | floor 110 (the inline `min-width` stays); name wraps | 110 |
| (item, fixed) | ITEM | floor 120 (inline 160 overridden); name wraps, line-height 1.2; grows to the longest secondary-item line, which does not wrap | 120+ |
| `adspent` | ADSPENT | content | 77 |
| `orders` | ORDERS | header | 47 |
| `orders_1d` | ORDERS / (1D) | header | 47 |
| `cpp` | CPP | content | 51 |
| `proceed` | PROCEED | header | 53 |
| `pcpp` | P.CPP | content | 51 |
| `tcpr` | TCPR | content | 41 |
| `breakeven_cpp` | BREAKEVEN / CPP (5%) | header word "BREAKEVEN" | 66 |
| `proj_profit` | PROF.PROFIT | content (bold, TOTAL row) | 77 |
| `per_order` | /ORDER | content | 51 |
| `np_per_order` | NP/O | content | 53 |
| `np_per_order_3d`, `_7d`, `_1m` | NP/O / (3D) … | content | 53 each |
| `proj_pct`, `proj_pct_1d`, `proj_pct_3d`, `proj_pct_7d` | PROF.% / (1M) … | content or "PROF.%", whichever is wider | 47 each |
| `proj_prof_1d`, `_3d`, `_7d` | PROF.PROFIT / (1D) … | header word "PROF.PROFIT" or content | 77 each |
| `jnt_rts`, `jnt_del`, `jnt_transit` | shown as one column `jnt_rdt`: RTS / DEL / INT | the three-line block, inner cells `padding:0 3px`, line-height 1.2 | 99 |
| `rts_set` | SET RTS% | value + pencil; the "from date" line and the comment may wrap | 78 |
| `promo` | PROMO | floor 70; text wraps | 70 |
| `price` | PRICE | content | 60 |
| `item_val` | ITEM VAL. | value + pencil; the "from date" line and the comment may wrap | 78 |
| `item_val_ceo` | ITEM VAL. / (CEO) | value + pencil | 74 |
| `ship` | SHIP | content | 44 |
| `cod_fee` | COD / FEE | content | 44 |
| `hold` | HOLD | content | 38 |
| `action`, `claude_action`, `ceo_action` | as today | floor 135, then an equal-ish share of all remaining width; text wraps, three lines, then the existing "more" and title; author line one line beside "more" | 135+ |
| `claude_reason`, `ceo_reason` | as today | floor 155, then a share of the remaining width; three lines, then "more" | 155+ |

**Arithmetic at a 1707 px viewport, CEO's columns.** I cannot read the production column settings, so the set is the one the handoff describes from the screenshot: PAGE, ITEM, PROMO, PRICE, NP/O, ADSPENT, ORDERS, PROF.PROFIT, ITEM VAL., ITEM VAL. (CEO), CPP, BREAKEVEN CPP, the four PROF.%, TCPR, HOLD, the RTS block, and the three text columns Action, Claude Action, CEO Action.

- Width available to the card: 1707 − 32 (padding of `#scroll`, 16 px each side) − 15 (its vertical scrollbar) = **1660 px**.
- Number side: 110 + 120 + 70 + 60 + 53 + 77 + 47 + 77 + 78 + 74 + 51 + 66 + (4 × 47 = 188) + 41 + 38 + 99 = **1249 px**.
- Left for the text columns: 1660 − 1249 = 411 px → **137 px each** for three; their floors are 3 × 135 = 405, so the minimum table width is 1249 + 405 = **1654 px ≤ 1660: expected label `↔ Compact 100%`**, card `scrollWidth` = `clientWidth` = 1660.
- If my number side is 5% low (1311), the minimum is 1716 and the label reads about 97%.
- With all five text columns: 1249 + 405 + 310 = 1964 → about **84%**.
- Actions view, five text columns: 110 + 120 + 51 + 188 + 38 = 507; 1660 − 507 = 1153 → about **230 px per text column**, 100%.
- At 1280 px (available 1233): the three-text set needs 1654 → about **74%** (today, by the same reasoning from the owner's 76% at 1707, about 56%). No horizontal scrollbar at any width: the zoom takes what the layout cannot.

Row height, estimated: RTS-block row = 3 × (11 px × 1.2 + 1 px rule) + 4 px padding + 1 px rule ≈ **48 px**; a row with three text lines and the author line ≈ 3 × 13.2 + 11 + 5 ≈ **56 px**. Today, before zoom: at least 68 px, and about 91 px in the owner's screenshot (12 rows at 76%).

**5. Console one-liner** (works in both states of the switch; paste as one line):

```js
(()=>{const c=document.querySelector('[data-ow-fit]'),b=c.parentElement,t=c.querySelector('table'),w={};t.querySelectorAll(':scope > thead > tr > th').forEach(h=>{w[h.dataset.col||h.textContent.replace(/[^A-Za-z ]/g,'').trim().toLowerCase().replace(/\s+/g,'_')]=Math.round(h.getBoundingClientRect().width)});const tx=['action','claude_action','claude_reason','ceo_action','ceo_reason'];return JSON.stringify({viewport:innerWidth,mode:localStorage.getItem('owFitMode')||'fit (default)',actions:localStorage.getItem('owActionsView'),cardClass:c.className,zoom:c.style.zoom||'1',label:document.getElementById('owFitSwitch').textContent,card:{scrollWidth:c.scrollWidth,clientWidth:c.clientWidth},scrollBox:{scrollWidth:b.scrollWidth,clientWidth:b.clientWidth},tableWidth:t.offsetWidth,first5RowHeights:[...t.querySelectorAll(':scope > tbody.page-row-tbody > tr:not(.page-col-header):not(.page-expand-row)')].slice(0,5).map(r=>Math.round(r.getBoundingClientRect().height)),textCols:Object.fromEntries(tx.filter(k=>k in w).map(k=>[k,w[k]])),allCols:w},null,1)})()
```

It prints the card's and the scroll box's `scrollWidth` and `clientWidth` (no horizontal scrollbar when the scroll box's two numbers are equal), the zoom in use and the button label, the height of the first five rows, the width of each text column, and every column's width for comparison with the table above. Heights and widths come from `getBoundingClientRect`, so with a zoom below 1 they are the sizes as drawn on screen.

**6. Old layout state = 617fb34 for the table cells.** How I checked, by reading, not in a browser:

- `git diff 617fb34..HEAD -- resources/views/owner/private.blade.php` removes five lines and each comes back unchanged apart from two things: a new `:data-col="owCompact ? col.id : null"` binding on the four `x-for` cells, and `x-text="hdr(col.label)"` in place of `x-text="col.label"` on the two header labels. No existing `class`, `style`, `:class` or `:style` of any cell was edited (the diff's added lines are listed under "What changed").
- In the old state `owCompact` is false: Alpine removes an attribute bound to `null` (it keeps only the four `aria-*` state attributes when falsy), so no cell has `data-col`; `hdr()` returns the label itself, so the header text is identical.
- The script removes `ow-compact` and `ow-actions` from the card and clears the zoom in that state, and the test `every new css rule is scoped so old layout cannot match` proves every selector in the partial starts with `.ow-compact`, `.ow-actions` or `.ow-fit-on`; `data col is only the alpine binding…` proves there is no static `data-col` on any `th` or `td`.
- What is literally different in the DOM in the old state: the Alpine directive attributes themselves (`:data-col`, the changed `x-text` source) on those cells. They are template code and render nothing. If Mira reads "same HTML attributes" as excluding even those, this item fails and the design needs another way to address columns; I do not know of one that leaves the cells untouched.

**7. No attribution line.** `git log --format='%B' 617fb34..HEAD | grep -ciE 'co-authored|claude-session|generated with'` prints `0`; all four commits have a subject only.

## Review

`skeptic-reviewer`, standard depth, on `1195b58..e848ab0`: **no blocker, no major**, eight minors. It reviewed by reading only (its shell was denied part-way, so it ran no tests; I ran them).

| # | Finding | Outcome |
|---|---|---|
| 1 | Nothing proved the old layout cannot be matched by a new rule | Fixed: test `every new css rule is scoped…` |
| 2 | Actions whitelist not tied to the column catalog | Fixed: test `actions whitelist ids are real columns` |
| 3 | Dragging a header in the Actions view would post a column order | Fixed: `colDragStart` refuses the drag while `.ow-actions` is on, with a test |
| 4 | The author line's percentage `max-width` does not limit the column's minimum width | Fixed in CSS (`width:0; min-width:calc(100% - 46px)`); not seen in a browser |
| 5 | Users who chose "100%" under 011 stay in the old layout | Accepted, by design (their choice is kept); stated above |
| 6 | Compact made the campaigns panel text 0.5 px smaller and the Page column 10 px wider | Fixed: the expand row is excluded; the Page floor rule was removed |
| 7 | The expand row's `colspan` is larger than the visible columns in the Actions view | Accepted: expected to draw no width; on the browser checklist (step 7) |
| 8 | Red runs could not be judged from the diff | Stated under done-when 2, including the slip |

Policy would put accepted findings in `TODO.md`; done-when 1 allows no such file in this diff, so they are recorded here (Rulings, 6).

## Rulings

| # | Ruling | Reason | Cost |
|---|---|---|---|
| 1 | Built without Mira's "go" | CLAUDE.md asks for her go before code; the start message for this headless run said nobody can answer and to write the plan, then build. The plan was committed first (`1195b58`). | Mira sees the plan only now. |
| 2 | **R3 skipped** | (a) Conditional formatting from the column settings page colours a whole cell by column id; a merged PROMO+PRICE or ITEM VAL.+CEO cell can carry one background, so what the settings page produces would change. (b) Each column's header is its sort and drag target; hiding PRICE and ITEM VAL. (CEO) would need second click targets inside another header. (c) PRICE has up to three lines (price, lowest, highest) and ITEM VAL. up to three (value, source or date, comment); stacking them makes cells of four lines or more, taller than the RTS block, against R1a. | About 134 px at 1707 px (60 + 74), about 45 px more per text column. |
| 3 | Cells get `data-col` through an Alpine binding that is null in the old layout, instead of a static attribute or per-cell edits | CSS has to address a column whose position changes with the column settings; this keeps the old layout's cells without any new attribute (done-when 6). | Twenty lines in `private.blade.php`, a flag shared between the plain script and Alpine. |
| 4 | The Actions view works only in the compact state; in the old-layout state the button is disabled | "The other state shows the table exactly as before 011" stays true without exception. | A CEO in the old layout presses the switch first. |
| 5 | Dragging a column header is refused while the Actions view is on | A drag there would post an order made in a partial view (R4: never writes to the column settings). | Reorder columns with the Actions view off. |
| 6 | Accepted findings and gotchas are in this file, not in `TODO.md` or `.claude/agent-memory/` | Done-when 1 limits the diff to the views, tests and the handoff folder. CLAUDE.md wins on conflict in general; here I followed the handoff because the limit is a done-when item and nothing is lost. | Mira may want them copied after merge (Suggestions). |
| 7 | The short "more" button still appears for texts over 24 characters even when three lines show everything | It is the existing rule; changing it would change behaviour outside the layout. | A button that sometimes changes nothing. |
| 8 | When the browser has no CSS zoom, the switch is disabled and the old layout shows, as in 011 | Kept 011's fallback instead of adding a compact-without-zoom path nobody asked for. | Old browsers get no compact layout. |

## Deploy steps for Mira

1. Merge and pull on the server as usual. **No migration, no `npm run build`** (the CSS and script are inline in Blade; no Vite asset changed), no queue or supervisor restart, no `.env` change.
2. `php artisan view:clear` (or `php artisan view:cache` if the server caches views). No config, route or event cache is affected.
3. Nothing to set in the browser: compact is the default. The keys are `owFitMode` (`full` = old layout) and `owActionsView` (`1` = on). To send one person back without a deploy: press the switch.

## What a browser check must look at

Log in as the CEO at 1707 px wide, `/owner/private`, with the usual date.

1. **Default state.** The switch reads `↔ Compact NN%`. No horizontal scrollbar. Paste the one-liner from done-when 5 and compare `zoom`, `scrollBox`, `textCols` and `allCols` with the table and arithmetic under done-when 4.
2. **Rows.** Count the rows on one screen (12 today). `first5RowHeights` should be near 48 to 56 at 100%. Look for a row that is much taller and note which cell causes it (I expect the Item cell's secondary lines, or a comment under RTS or Item Val.).
3. **Number columns.** Headers wrap to two lines (`PROF.%` over `(1M)`, `BREAKEVEN` over `CPP (5%)`); no number is cut or wrapped; the four coloured Prof.% cells and TCPR are narrow; conditional-formatting colours still fill their cells.
4. **Text columns.** Action, Claude Action, CEO Action (and the Reasons if shown) wrap to at most three lines and are then cut with "…"; hovering shows the full text; "▸ more" opens the full text and "▾ less" closes it; the author-and-time line is one small line with "more" beside it, cut with "…" when long, and it does not make the column wider.
5. **Editing.** The pencil in each text cell opens its floating box at the right place and saves; the pencils in Set RTS%, Promo, Item Val. and Item Val. (CEO) open the edit modal.
6. **Sorting and reorder.** Click three headers to sort; drag one header to a new place and reload (order kept, as today).
7. **Expand a row.** The campaigns panel looks as before; the repeated header above it lines up with the columns. Then "Expand all" and "Hide all".
8. **Actions view.** Press `☰ Actions`: only PAGE, ITEM, CPP, the four Prof.%, HOLD and the text columns remain; the text columns are wide; the button looks pressed. Expand a row here and check the panel spans the table and nothing is drawn to the right of it. Try to drag a header: nothing happens. Use a pencil and save. Press the button again: every column is back. Reload with it on: it stays on. Open `⚙ Columns`: nothing changed there.
9. **Old layout.** Press the switch: label `↔ 100%`, full-size table with horizontal scroll, exactly as before 011; `☰ Actions` is greyed out. Run the one-liner again: `cardClass` is `card`, `zoom` is `1`, and `document.querySelectorAll('[data-col]').length` is `0`. Press the switch again: compact returns, and the Actions view too if it was on.
10. **Other widths.** 1280 px: no horizontal scrollbar, label below 100%. Phone width: the table still fits by zoom as after 011.
11. **Marketing.** As a Marketing user, and as the CEO with View: Marketing: the switch is there, `☰ Actions` is not, the Claude and CEO columns are absent, compact works.
12. **Elsewhere.** The breakdown page, the item page card, Excluded, Logs and Snapshots are unchanged (none includes the partial; the existing test `pages out of scope do not carry the fit helper` covers the first two).

## Proposed tasks

1. **Tune after the browser check** (low): the floors (Item 120, Promo 70, text 135 / 155), the padding and the three-line clamp are one line each in the partial; adjust them to what the measurement shows.
2. **Let the secondary-item lines wrap or cap them** (low, needs Busing's eye): they do not wrap today and can make the Item column the widest number-side column.
3. **Hide "more" when nothing is cut** (low): a few lines of script comparing `scrollHeight` with `clientHeight`; today's rule is "longer than 24 characters".
4. **R3 as its own handoff, if the 134 px are still wanted** (medium): needs a decision on conditional formatting in a merged cell and on where the sort and drag targets go.
5. **Fix or remove `ExampleTest`** (low): the one red test in the suite; `/` redirects to login.

## Suggestions for Mira

- Decide done-when 6 on the reading given there (rendered attributes equal; Alpine directives differ). It is the one item where a strict reading and mine part.
- If rows per screen matter more to Busing than three lines of text, ask for two lines; it is a one-word change and brings text rows down to the RTS block's height.
- The pattern worth keeping in agent memory after merge (not written in this run): a hard-coded list of column ids in CSS or script has no link to the `cols` catalog, so pin it with a test; any view-only feature on this page must say what column drag does, because drag posts to the shared column settings; substring checks cannot prove CSS is scoped to a state, test the selector prefixes.
- The Google Drive connector asked for authorisation during this run; nothing here needed it.

---

# Amendment 013-1 (branch `feat/013b-compact-tighten`, from `5f9de2e`)

Status: **built, reviewed and tested; still not seen in a browser.** I have no browser, so every figure below is derived from Mira's measurements and the CSS, not measured. Nothing was pushed and there is no PR. Where this section and the first part of this file disagree (widths, fonts, padding, the `rts_set` and `item_val` cells), this section is the current state.

## Plan (written before code)

Mira's measurement shows every column sat at its minimum content width (the table was wider than the card), so the numbers can only come down by making the content itself smaller or cappable. Tier medium, one frontend task, then `skeptic-reviewer` at standard depth.

1. **Smaller cell chrome and type, compact only:** body cells `padding:2px 2px`, 11px (was `2px 4px`, 12px); header cells `padding:3px 2px`, 9.5px; the RTS block's inner cells 10px with `padding:0 2px`; pencil icons and their gap tightened. At a zoom of 0.96 or better an 11px figure is drawn larger than today's 12px at 0.86.
2. **`rts_set` in two lines:** the wrapper around the percentage and its notes gets a pixel `max-width`, `white-space:nowrap`, `overflow:hidden`, `text-overflow:ellipsis`; the percentage is a block (line 1), the "from date" note and the comment note become inline (line 2). A compact-only `:title` on the wrapper carries both notes in full.
3. **Notes that must not widen or heighten a cell:** `item_val` notes, the page cell's "mixed primary", "computed since" and "back-filled" lines, and the secondary-item lines under an item name each stay on one line with an ellipsis and a pixel `max-width` (a pixel cap is what table layout honours), each with a compact-only `:title`. Page and item names wrap; Promo wraps anywhere.
4. **Explicit widths:** every non-text header cell gets `width:1px` in compact, which makes it a fixed-width column that sits at its content width and takes no share of spare width; Page 115, Item 115 and Promo 100 get their own pixel widths; the text columns stay `width:auto` with their 135 / 155 floors, so all spare width is theirs.
5. **Header break:** `hdr()` also allows a break after a full stop inside a label (`PROF.` / `PROFIT` / `(1D)`), so `Prof.Profit(1D)` and `Prof.%(1M)` no longer set their column's width.
6. Old layout: every new rule under `.ow-compact`, every new attribute bound to `null` unless `owCompact`; the scoping test already pins the first.

## Summary

1. The `rts_set` cell is now two lines (percentage; then "from date" and the note on one line, cut with an ellipsis, full text in the title), and the other notes that could wrap (Item Val., the page cell's notes, secondary items, a long item name) are held to one or two lines with titles.
2. Every non-text header cell has an explicit width in compact, so number columns sit at their content width and all spare width goes to the text columns; body cells went from 12px with 4px side padding to 11px with 2px, headers from 10px to 9.5px, the RTS block to 10px.
3. Expected at 1707 px with Mira's column set: natural width about **1714** (zoom about 0.97), rows about **45 to 50 px** without a three-line text and about **57 to 66 px** with one. These are derived, not measured.

## Evidence per done-when item

**8a. Per-column widths.** Method: Mira found every column at its minimum content width (the table was wider than the card), so each measured width is content plus 9 px of padding and border. New width = (measured − 9) × 11/12 + 5 for a column set by its body content (11px instead of 12px; 2 + 2 px padding + 1 px rule), and (measured − 7) × 0.95 + 5 where a header word sets it. Columns with a rule of their own are derived from the rule.

| Column | Measured (013) | Rule in compact now | Expected | Mira's target |
|---|---|---|---|---|
| page + item (the expander is inside the page cell) | 282 | `th` width 115 each; page body capped at 82 px after the 22 px chevron and 6 px gap; item lines capped at 110 px | 230 | 230 |
| `promo` | 148 | `th` width 100; text wraps anywhere | 100 | 100 |
| `price` | 51 | width 1px → content | 44 | 46 |
| `np_per_order_1m` | 55 | content | 47 | 48 |
| `adspent` | 88 | content | 77 | 72 |
| `orders_1d` | 44 | header word "ORDERS" | 40 | 40 |
| `proj_prof_1d` | 71 | content (the header now breaks after "PROF.") | 62 | 62 |
| `item_val` | 69 | content: value + 2 px gap + pencil; notes capped at 44 px | 58 | 58 |
| `item_val_ceo` | 69 | content: value + 2 px gap + pencil | 58 | 58 |
| `cpp` | 51 | content | 44 | 46 |
| `rts_set` | 71 | notes block capped at 64 px + 2 px gap + pencil (about 14) + 5 | 85 | up to 95 |
| `jnt_rdt` | 109 | inner table 10px, inner cells `padding:0 2px` | 92 | 95 |
| `tcpr` | 48 | content | 41 | 44 |
| `breakeven_cpp` | 61 | header word "BREAKEVEN" (content alone would be 53) | 56 | 52 |
| `proj_profit` | 78 | content | 68 | 68 |
| `proj_pct`, `_1d`, `_3d`, `_7d` | 48 each | content (41) or header "PROF." / "%(1M)"; counted at 44 | 44 each | 44 each |
| `hold` | 37 | content | 31 | 34 |
| `action`, `claude_action`, `ceo_action` | 135 each | `width:auto`, floor 135, all spare width | 135+ each | 135 or wider |

Columns outside Mira's set follow the same rule (`width:1px` → content): `orders`, `proceed`, `pcpp`, `per_order`, the other `np_per_order*`, `proj_prof_3d` / `_7d`, `ship`, `cod_fee`. `claude_reason` and `ceo_reason` are `width:auto` with a floor of 155.

**8b. Arithmetic.** 230 + 100 + 44 + 47 + 77 + 40 + 62 + 58 + 58 + 44 + 85 + 92 + 41 + 56 + 68 + (4 × 44 = 176) + 31 + (3 × 135 = 405) = **1714 px ≤ 1730**. Zoom = 1660 / 1714 = **0.968**. Two columns miss their suggested target because their content cannot be narrower without changing what it says: `adspent` (77 against 72: a figure such as ₱123,456.78 in the bold total row) and `breakeven_cpp` (56 against 52: the word BREAKEVEN). `rts_set` is 10 under its allowance, which pays for both. If the real total comes out over 1730, the cheapest levers are the `rts_set` cap (64 → 56) and Promo (100 → 90).

**8c. The `rts_set` rule.** `resources/views/owner/_fit_to_width.blade.php` lines 43 to 45:

```css
.ow-compact > table > tbody > tr > td[data-col="rts_set"] > span > div > template + div { display:block; max-width:64px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.2; }
.ow-compact > table > tbody > tr > td[data-col="rts_set"] > span > div > template + div > span { display:block; }
.ow-compact > table > tbody > tr > td[data-col="rts_set"] > span > div > template + div > div { display:inline !important; white-space:nowrap !important; max-width:none !important; margin:0 3px 0 0 !important; }
```

The first rule is the block around the percentage and its notes; the second puts the percentage on its own line; the third puts the "from date" note and the comment note side by side on the next line. The full text of both notes is in a compact-only `:title` on that block (`private.blade.php`, the `<div>` under `x-if="row.rts_pct !== null"`). I took "the estimated / updated note" to be the comment line (`rts_comment`, shown with 💬); it is the only other note in that cell. With "from 2026-10-01" taking about 62 of the 64 px, the comment is mostly cut and is read from the title.

**8d. Expected heights** (cell content; the row adds 4 px padding and a 1 px rule):

| Cell | Measured (013) | Expected now |
|---|---|---|
| `rts_set` | 89 to 102 | 2 × 13.2 = **about 27** (limit 34). The 💬 emoji may lift line two by a pixel or two. |
| `jnt_rdt` | 47 | 3 × (10 × 1.2 + 1) = **about 39** |
| `item_val` | 32 | value 13 + up to two notes of 10.4 = **24 to 34** |
| `price` | 16 to 18 | rule unchanged: up to three short lines, about 35 |
| item cell | 16 to 18 | name at most two lines (26), plus 11.5 per secondary-item line |
| page cell | 23 to 59 | name wraps freely, each note one line; exempt from the limit |
| text cells | 32 to 61 | rule unchanged: three lines and the author line, about 51 to 61 |

Rows: **about 45 to 50 px** when no text cell shows three lines, **about 57 to 66 px** when one does (target 70 or less). One case can break the 47 limit and I could not rule it out: an item with two or more secondary-item lines (26 + 2 × 11.5 = 49, more with three). Mira's measurement shows item cells at 16 to 18 px, so no row had secondary lines that day.

**9. Tests.** `php.bat artisan test --filter=FitToWidth` on `59f7ca7`: `Tests:    12 passed (126 assertions)`. Full suite on `59f7ca7`: `Tests:    1 failed, 3 skipped, 515 passed (5377 assertions)` (the failure is the old `ExampleTest`; base 514 / 3 / 1, plus the one new test). New test: `compact css holds explicit widths and the two line rts cell`. It pins the `width:1px` rule, the Promo and Page / Item widths, the two `rts_set` rules, the two-line item-name clamp, and that all eight new or changed `:title` bindings are gated on `owCompact`. Red runs as the developer reported them: first `missing css: th[data-col] { width:1px; }`, then, for the fix, `missing css: td:nth-child(2) > div:first-child { display:-webkit-box; …`.

**10. Paths and the old layout.** `git diff --stat 5f9de2e..HEAD` (before this file's own commit):

```
 handoff/013-compact-table/AMENDMENT-1.md      | 23 +++++++++++++
 handoff/013-compact-table/RESULT.md           | 17 ++++++++++
 resources/views/owner/_fit_to_width.blade.php | 47 +++++++++++++++++++--------
 resources/views/owner/private.blade.php       | 18 ++++++----
 tests/Feature/OwnerPrivate/FitToWidthTest.php | 24 ++++++++++++++
 5 files changed, 109 insertions(+), 20 deletions(-)
```

(`handoff/README.md` joins with this commit.) `git diff 5f9de2e..HEAD -- resources/views/item/_table_old.blade.php` is empty. Old layout, checked by reading and by test, not in a browser:

- The existing test `every new css rule is scoped so old layout cannot match` still passes: every selector starts with `.ow-compact`, `.ow-actions` or `.ow-fit-on`, and the script removes those classes in the old state.
- `private.blade.php` changed in nine places. Seven add one `:title` that is `null` unless `owCompact`, so Alpine removes the attribute in the old layout. The eighth is the back-fill line's existing `:title`, now `(owCompact ? <its visible text> + ' — ' : '') + <the old expression>`; with `owCompact` false that is the old string exactly. The ninth is `hdr()`, which still returns the label itself when not compact. No existing `style`, `class` or `x-text` was edited.
- The same caveat as done-when 6: the Alpine directive attributes themselves are new in the template.

**11.** `git log --format='%B' 5f9de2e..HEAD | grep -ciE 'co-authored|claude-session|generated with'` prints `0`. Commits: `2580223 docs: amendment 013-1 and its plan`, `70d016c feat: tighten compact widths, two-line RTS cell and compact-only titles`, `59f7ca7 fix: clamp compact item name to two lines with full name in title`, then this file's `docs:` commit. No push, no PR.

## Review

`skeptic-reviewer`, standard depth, on `2580223..70d016c`: **no blocker, no major**, five minors.

| # | Finding | Outcome |
|---|---|---|
| 1 | The Item Val. "from date" note is cut at 44 px ("from 202…") | Accepted: the 58 px target leaves no room; the full note is in the title. Raising the cap to 66 costs about 20 px of table width. |
| 2 | Nothing held page and item names to two lines | Item name fixed (`59f7ca7`: two-line clamp, full name in a compact-only title). Page name left to wrap: it is a link with its own title, and the page cell is exempt from the height limit. |
| 3 | Body font 12 → 11px and header 10 → 9.5px were not asked for | Deliberate, see Rulings. |
| 4 | The new test pins strings, not rendering | Accepted: there is no browser harness; the page-cell, Item Val. and RTS-block rules are not pinned. |
| 5 | The page cell has no slack (22 + 6 + 82 = 110 = 115 − 4 − 1) | Accepted; on the browser list. |

## Rulings

| # | Ruling | Reason | Cost |
|---|---|---|---|
| 1 | Smaller type in compact: body 11px, header 9.5px, RTS block 10px | Every column was already at its content width, so the targets (for example `cpp` 51 → 46) can only be met by smaller content or smaller padding; I used both. At the expected zoom of 0.97 an 11px figure is drawn at about 10.6 px, against 12 × 0.86 = 10.3 px on the live page. | If the zoom lands well under 0.95 the figures are smaller than today; then put the font back to 12px and accept a lower zoom. |
| 2 | `width:1px` on every non-text header instead of a pixel width per column | It is an explicit width on each `data-col` header that makes the column fixed-width at its content, so it takes no spare width and cannot clip a large figure; a pixel number per column would clip or be ignored whenever the data is wider than I guessed. Promo, Page and Item, which wrap, have real pixel widths. | The widths in the table are expectations, not guarantees. |
| 3 | Promo breaks anywhere | It measured 148 px at its minimum, so its text has no break point that fits 100 px. | A promo code may break in the middle of a word. |
| 4 | Titles are added only in compact | The old layout must not gain an attribute. | No hover text on those notes in the old layout, as before. |
| 5 | Built without Mira's "go" on the plan | Headless run; the plan was committed first (`2580223`). | Mira sees the plan only now. |

## What a browser check must look at (amendment)

Same setup as Mira's measurement (1707 px, CEO view, full daily column set). The one-liner from done-when 5 still works.

1. `tableWidth` at zoom 1 against 1714 (limit 1730), the label against `↔ Compact 97%`, and `allCols` against the table in 8a; the three text columns at 135 or more.
2. Row heights against 45 to 50 and 57 to 66. For the tallest row, which cell sets it.
3. `rts_set`: two lines, the second cut with "…", hover shows both notes in full, the percentage never cut, the pencil still opens the modal. Whether the ellipsis actually draws on line two.
4. Number columns: no figure cut or wrapped; headers break as `PROF.` / `PROFIT` / `(1D)` and `PROF.%` / `(1M)` or similar; the header row's height.
5. Spare width: widen the window or switch on the Actions view; only the text columns should grow.
6. Page cell: chevron and breakdown link still clickable, notes on one line with "…" and a hover text, no clipped pixel at the right edge. Item cell: a long name stops at two lines with the full name on hover; secondary lines cut with "…".
7. Promo at 100 px: where it breaks. Item Val.: "from 202…" with the full note on hover.
8. Old layout (`↔ 100%`): unchanged, and `document.querySelectorAll('[data-col]').length` is `0`.

## Deploy steps for Mira

As before: merge, pull, `php artisan view:clear`. No migration, no build, no restart.

## Proposed tasks

1. Tune from the measurement (low): the `rts_set` cap (64), Promo (100), the Item Val. note cap (44) and the fonts are one value each in the partial.
2. Decide what a row with several secondary-item lines should do (low): each is a line today, and they are the one thing that can still push a non-text cell past the RTS block.

## Suggestions for Mira

- Measure once more with the one-liner before the owner looks; the two numbers that decide it are `tableWidth` (1730 or less) and the height of the tallest row.
- The font trade in Ruling 1 is the one thing here the owner might notice that you did not ask for by name; it is the only way I found to reach the per-column targets.

---

# Amendment 013-2 (same branch `feat/013b-compact-tighten`)

Status: **built, reviewed and tested; not seen in a browser.** I still have no browser: the hover box, the one-line cells and the expanded-row texts were checked by reading the template, the CSS and the script, and by tests that look for the markup, never by running the page. Nothing was pushed and there is no PR. This section is the current state for the `rts_set`, Item Val., text and page cells; the width table of Amendment 013-1 still holds except `rts_set` (now about 56 px, total about 1685).

## Plan (written before code)

Tier medium. Two tasks, in order: `backend-developer` (one read-only field), then `frontend-developer`; `skeptic-reviewer` at standard depth on both.

1. **Who set the RTS (backend).** It is stored: every save writes `page_item_settings_log` with `user_email`, page, item, effective date and the new RTS. The page's row data does not carry it. One query for the whole load (not per row) builds a map "page, item family, effective date, RTS value → name of the last person who logged that value"; each row gets `rts_set_by` (name, or null when no log row matches). No calculation reads it. File: `app/Http/Controllers/OwnerPrivateController.php`.
2. **One hover box (frontend).** The fit script owns one fixed-position element on `<body>` (outside the zoomed card and the scroll box, filled with `textContent`). The body cell gets `:data-ow-tip` and `:title`, both `owCompact ? owTip(col.id, row) : null`; `owTip()` builds the text from the row for `rts_set`, `item_val`, `action`, `claude_action`, `ceo_action`. Shown on mouse-over at the pointer, hidden on mouse-out, scroll, or a click elsewhere (which is also what a tap does).
3. **Cells in compact:** CSS hides the notes in `rts_set` (replacing 013-1's two-line rules) and `item_val`, and the author line in the three action cells. The page-cell notes go back to full text and may wrap; 013-1's titles on them and on the hidden notes are removed again.
4. **Expanded row shows full texts:** the three-line clamp applies only to `tbody:not(.page-section-expanded)`; that class is already set by the existing expand state. Collapsing removes the class, so the clamp returns unless he opened the text himself (`_actionOpen` is untouched). CSS only, so the old layout is not affected.

## What changed

| Change asked | What was built | Where |
|---|---|---|
| `rts_set`: percentage only; notes and who set it on hover | The notes are hidden in compact; the hover lists "from date", the 💬 note and "Set by name". 013-1's two-line rules are gone. | CSS `_fit_to_width.blade.php:43`; hover text `private.blade.php:2489` |
| Who set the RTS | Stored but not sent: `page_item_settings_log.user_email`. New read-only row field `rts_set_by` (a display name or null), from one query per load. | `OwnerPrivateController.php`, `rtsSetByMap()` and one line in the row array |
| Author line of Action, Claude Action, CEO Action on hover | The line is hidden in compact; the hover shows the full text, then the author line. | CSS `:68`; hover text `:2489` |
| Item Val.: "cogs" and "from date" on hover | All notes under the value are hidden in compact (also the 💬 note, see Rulings); the hover lists them. | CSS `:45` |
| Page notes in full, wrapping | 013-1's one-line cut and its titles are undone; notes wrap, nothing is clipped. | CSS `:50` to `:52` |
| Expanded row shows the texts in full | The three-line cut applies only to a row that is not expanded; the "more" button is hidden while it is. | CSS `:64`, `:66` |
| A prompt hover that is not clipped | One box on `<body>`, placed at the pointer, filled with `textContent`; the native `title` is kept. A click or tap on a cell shows it, a click elsewhere or on a button hides it. | script `_fit_to_width.blade.php:262` |

## Evidence per done-when item

**12. Compact cells, by reading** (`resources/views/owner/_fit_to_width.blade.php` unless named):

- `rts_set` is one line: line 43, `…td[data-col="rts_set"] > span > div > template + div > div { display:none !important; }`. The cell's block holds the percentage `span` and the two note `div`s (`private.blade.php`, under `x-if="row.rts_pct !== null"`); only the `div`s match, so the percentage and the pencil remain.
- No author line in the three text cells: line 68, `…> span > div > div[title] > template + div { display:none !important; }`. In each cell the author line is the only `div` that directly follows a `<template>` inside the titled block; the text is the first child and "more" is a `button`.
- No "cogs" or date note in Item Val.: line 45, `…td[data-col="item_val"] > span > div > template + div > div { display:none !important; }`. `item_val_ceo` has no note in the template at all (value and pencil only), so there was nothing to hide there.
- Page notes in full: line 50 `.page-cell-body { max-width:82px; }` (no `overflow:hidden` any more) and line 52 `.page-cell-body div { white-space:normal; overflow-wrap:anywhere; }`. No ellipsis rule touches the page cell.

**13. Hover content, escaped.** The body cell (`private.blade.php:759`) carries `:data-ow-tip="owCompact ? owTip(col.id, row) : null"` and the same expression as `:title`. Alpine sets both as attribute values, which cannot hold markup. `owTip()` (`private.blade.php:2489`):

```js
owTip(colId, row){
  const L = [];
  if (colId === 'rts_set') {
    if (row.rts_pct === null || row.rts_pct === undefined) return null;
    if (row.settings_date) L.push('from ' + row.settings_date);
    if (row.rts_comment)   L.push('💬 ' + row.rts_comment);
    if (row.rts_set_by)    L.push('Set by ' + row.rts_set_by);
  } else if (colId === 'item_val') {
    if (row.item_value === null || row.item_value === undefined) return null;
    if (row.item_value_source === 'cogs') L.push('cogs');
    if (row.item_value_source === 'manual' && row.settings_date) L.push('from ' + row.settings_date);
    if (row.item_value_source === 'manual' && row.item_value_comment) L.push('💬 ' + row.item_value_comment);
  } else if (colId === 'action') {
    if (!row.action_comment) return null;
    L.push(row.action_comment);
    if (row.action_by) L.push('✎ ' + row.action_by + (row.action_at ? (' · ' + row.action_at) : ''));
  } else if (colId === 'claude_action' || colId === 'ceo_action') {
    const p = colId === 'claude_action' ? 'claude' : 'ceo';
    if (!row[p + '_action']) return null;
    L.push(row[p + '_action']);
    const meta = [row[p + '_source'], row[p + '_at']].filter(Boolean).join(' · ');
    if (meta) L.push(meta);
  } else {
    return null;
  }
  return L.length ? L.join('\n') : null;
},
```

The box itself (`_fit_to_width.blade.php`, between `// ow-tip-start` at line 262 and `// ow-tip-end`): `var text = cell.getAttribute('data-ow-tip'); … tip.textContent = text;` on a `div` appended to `document.body` with `position:fixed`, placed from the pointer's `clientX` / `clientY`. No HTML sink: the tests `fit script never writes html` and `compact cells show notes on hover only` pin `textContent` and the absence of `innerHTML`, `x-html`, `preventDefault` and `stopPropagation`.

**14. Expanded row.** Code path: the chevron calls `togglePageExpand()`, "Expand all" calls `toggleAllExpand()`; both set `expandedPages[page].open`, and the row's `<tbody>` already binds `page-section-expanded` to that flag (`private.blade.php:663`). The three-line cut is now `_fit_to_width.blade.php:64`, `.ow-compact > table > tbody:not(.page-section-expanded) > tr > td:is(…) … div:first-child[style*="ellipsis"] { -webkit-line-clamp:3 … }`, so it does not apply while the class is on, and the general rule above it (wrap, no max-width) shows the whole text. Line 66 hides "more" in an expanded section. Collapse: the class goes, the cut applies again to any text whose own state is closed; a text he opened with "more" has `_actionOpen` true, its bound style then has no `ellipsis`, and the cut never matched it, so it stays open. Nothing writes `_actionOpen`. Covered by reasoning and by the test pinning both selectors, not by running it.

**15. Tests.** `artisan test --filter=FitToWidth` on `d27dd1c`: `Tests:    13 passed (143 assertions)`. Full suite on `d27dd1c`: `Tests:    1 failed, 3 skipped, 520 passed (5402 assertions)` (the failure is the old `ExampleTest`; base 515 / 3 / 1; five new tests). New: `compact cells show notes on hover only` (the markers of this amendment) and `RtsSetByMapTest` (4 tests). Red runs as the developers reported them: `missing css: td[data-col="rts_set"] > span > div > template + div > div { display:none !important; }`; `Method …OwnerPrivateController::rtsSetByMap() does not exist`; and for the fix, `-'Person A' +'Person B'`.

**16. Paths.** `git diff --stat 5f9de2e..HEAD` (before this file's own commit):

```
 app/Http/Controllers/OwnerPrivateController.php |  66 ++++++++++
 handoff/013-compact-table/AMENDMENT-1.md        |  23 ++++
 handoff/013-compact-table/AMENDMENT-2.md        |  21 ++++
 handoff/013-compact-table/RESULT.md             | 161 ++++++++++++++++++++++++
 handoff/README.md                               |   2 +-
 resources/views/owner/_fit_to_width.blade.php   |  98 ++++++++++++---
 resources/views/owner/private.blade.php         |  36 +++++-
 tests/Feature/OwnerPrivate/FitToWidthTest.php   |  61 +++++++++
 tests/Feature/OwnerPrivate/RtsSetByMapTest.php  |  96 ++++++++++++++
 9 files changed, 543 insertions(+), 21 deletions(-)
```

The controller is `app/Http/Controllers/OwnerPrivateController.php`; **a second test file** came with it (`RtsSetByMapTest.php`), which the list in done-when 16 does not name. Its diff (`git diff 2f23cde..d27dd1c -- app/Http/Controllers/OwnerPrivateController.php`) is three additions and no removal:

```php
// 1. new helper, above cacheVersion()
protected function rtsSetByMap(string $date, \App\Services\ItemAliasResolver $aliases): array
{
    if (!Schema::hasTable('page_item_settings_log')) return [];
    try {
        $select = [
            'l.id', 'l.page_name', 'l.item_name', 'l.effective_date',
            'l.old_rts_pct', 'l.new_rts_pct', 'l.user_email',
            DB::raw('COALESCE(ep.name, u.name) AS user_name'),
        ];
        $hasScope = Schema::hasColumn('page_item_settings_log', 'scope');
        if ($hasScope) $select[] = 'l.scope';

        $rows = DB::table('page_item_settings_log as l')
            ->leftJoin('users as u', 'u.email', '=', 'l.user_email')
            ->leftJoin('employee_profiles as ep', 'ep.user_id', '=', 'u.id')
            ->where('l.effective_date', '<=', $date)
            ->whereNotNull('l.new_rts_pct')
            ->orderBy('l.effective_date')
            ->orderBy('l.id')
            ->select($select)
            ->get();

        $map  = [];
        $runs = [];
        foreach ($rows as $r) {
            $pair = strtolower(trim((string)$r->page_name)).'||'.$aliases->canonicalKey((string)$r->item_name);
            $val  = number_format((float)$r->new_rts_pct, 2, '.', '');
            if (!isset($runs[$pair]) || $runs[$pair]['val'] !== $val) {
                $name = '';
                if (!($hasScope && in_array((string)($r->scope ?? ''), ['promo', 'cogs'], true))) {
                    $name = trim((string)($r->user_name ?? ''));
                    if ($name === '') $name = trim(explode('@', (string)($r->user_email ?? ''))[0]);
                }
                $runs[$pair] = ['val' => $val, 'name' => $name];
            }
            $key = $pair.'||'.substr((string)$r->effective_date, 0, 10).'||'.$val;
            if ($runs[$pair]['name'] !== '') $map[$key] = $runs[$pair]['name'];
            else unset($map[$key]);
        }
        return $map;
    } catch (\Throwable $e) {
        \Log::warning('rtsSetByMap failed: '.$e->getMessage());
        return [];
    }
}

// 2. in itemSummary(), right after  $aliases = new \App\Services\ItemAliasResolver();
$rtsSetByMap = $this->rtsSetByMap($date, $aliases);

// 3. in the row array, right after  'rts_comment' => $rtsComment,
'rts_set_by' => ($settings && $rtsPct !== null) ? ($rtsSetByMap[$pk.'||'.$dominantKey.'||'.substr((string)$settings['effective_date'], 0, 10).'||'.number_format($rtsPct, 2, '.', '')] ?? null) : null,
```

(Comments and the docblock are left out here; the code lines are as committed.) No existing query, calculation, cache key or payload value changed; `git diff 5f9de2e..HEAD -- resources/views/item/_table_old.blade.php` is empty.

Old layout: every selector still starts with `.ow-compact`, `.ow-actions` or `.ow-fit-on` (test); `data-ow-tip` and the cell `title` are `null` unless `owCompact`; the box is only created when a cell with `data-ow-tip` is hovered or clicked, and such cells exist only in compact. The five 013-1 title bindings on the page notes, the back-fill line, the `rts_set` block and the Item Val. notes are back to `617fb34`: `git diff 617fb34..HEAD -- resources/views/owner/private.blade.php` removes six lines in all (the two header labels, the repeated header cell, the two `x-for` cells, and the secondary-item `div` that gained a compact-only title), none of them in those elements.

**17.** `git log --format='%B' 5f9de2e..HEAD | grep -ciE 'co-authored|claude-session|generated with'` prints `0`. Commits of this amendment: `2f23cde docs: amendment 013-2 and its plan`, `2bbe3c9 feat: add read-only rts_set_by to owner private rows`, `f5f9a69 feat: compact cells show notes in one hover box, full texts when expanded`, `d27dd1c fix: credit rts_set_by to who started the value run, not who carried it`, then this file's `docs:` commit. No push, no PR.

## Expected row heights now

Cell content, then the row adds 4 px padding and a 1 px rule. Derived, not measured.

| Cell | 013 measured | After 013-2 (expected) |
|---|---|---|
| `rts_set` | 89 to 102 | one line, about 13 to 15 (the pencil) |
| text cells | 32 to 61 | at most three lines of 13.2 = about 40, no author line |
| `jnt_rdt` | 47 | about 39 |
| Item Val. | 32 | one line, about 13 to 15 |
| page cell | 23 to 59 | name plus every note in full; a note that wraps adds a line. Roughly 13 to 26 for the name, 12 to 24 for "mixed primary", 11 to 22 for "computed since", 12 to 24 for "back-filled" |
| everything else | 16 to 18 | about 13 to 15 |

So a row without page notes is **about 44 to 46 px** (the RTS block or a three-line text sets it), against 104 measured after 013. A row with page notes is as tall as its notes need, by the owner's choice: up to about 80 to 100 px when all three notes show and wrap. An expanded row is as tall as its longest text.

Width: `rts_set` drops from the 85 px of 013-1 to about 56 (percentage, gap, pencil), so the expected total goes from 1714 to **about 1685** (zoom about 0.985).

## Review

`skeptic-reviewer`, standard depth, on `2f23cde..f5f9a69`: no blocker, **one major**, minors. One fix loop; its re-check on `d27dd1c`: **closed**.

| # | Finding | Outcome |
|---|---|---|
| 1 | **Major:** `rts_set_by` named the person who only carried the RTS forward. A promo-only or remark-only save on a new date is logged with the carried RTS as the new value, so "latest log row" named the wrong person. | Fixed (`d27dd1c`): the log is walked in effective-date order and each value is credited to whoever started its run. Re-check: closed. |
| 2 | The query reads the whole log on each uncached load | Accepted: one query, linear walk, cached with the rest of the payload. Worth a date floor if the log grows large. |
| 3 | The fallback shows the part of an email before `@` to every role | Accepted: never a full address; `action_by` already shows names to everyone. |
| 4 | No end-to-end test of the `rts_set_by` row field | Accepted: the data endpoint has no test harness (it needs dozens of tables). The key is built the same way on both sides, checked by reading. If the two ever differ the field is silently null. |
| 5 | Tests look for strings; `owTip()` and the box are never executed | Accepted: no JS harness in this repo. |
| 6 | The box is not refreshed while the pointer rests on one cell and the data changes | Accepted: it updates on the next move. |
| 7 | (re-check) The "started by a promo or cost save → no name" rule needs a `scope` column, and no migration in the repo adds it | Open question for Mira, see "What was left out". |
| 8 | (re-check) Someone who retypes the same value is not credited; the starter of the run is | Accepted: that is the rule. |

Policy note: the reviewer wrote one line into its memory file although told not to; I removed that line again so the diff stays within done-when 16. Its content is under Suggestions.

## Rulings

| # | Ruling | Reason | Cost |
|---|---|---|---|
| 1 | "Who set" = who started the current run of that value, read from the save log | The log is the only place a person is stored, and it also records saves that merely carry the RTS; the latest row would often name the wrong person. | The name can be missing; in the cases under "What was left out" it can still be wrong. |
| 2 | The Item Val. 💬 note also goes to the hover | The amendment names "cogs" and the date; leaving only the comment visible, cut at 44 px, would be the one note still wrapping or cut in that cell. | One more thing behind a hover; say so if he wants it back. |
| 3 | The hover box is placed at the pointer, not against the cell | The card can be zoomed, and element rectangles under CSS zoom differ between browser versions; pointer coordinates are always in screen pixels. | The box does not follow the pointer inside a cell. |
| 4 | Expanded rows show all five text columns in full, Reasons included | One rule for the text columns; he expands to read. | None seen. |
| 5 | Claude Reason and CEO Reason get no hover box | The amendment names the three action columns; the Reasons have no author line and keep their native title and "more". | Their full text needs "more", an expanded row, or the slow native title. |
| 6 | Touch: a tap shows the box through the same click handler; taps on buttons and links hide it | It adds a listener on `document` and never stops or prevents an event, so the pencil, "more" and the links act as before. | Not tried on a touch screen. |
| 7 | Built without Mira's "go" on the plan | Headless run; plan committed first (`2f23cde`). | Mira sees the plan only now. |

## What was left out, and why

- **`item_val_ceo` has no hover:** its cell has no note in the template (value and pencil only), so there is nothing to move.
- **Who set the RTS can be empty or, rarely, wrong.** Empty: no log row matches the page, item family, effective date and value (for example a value saved before the log existed in May 2026). Possibly wrong, both rare and both only for a value that has no earlier log row of its own: (a) a remark-only save with the RTS field left blank is logged like a typed RTS and cannot be told apart; (b) a promo-only or cost-only save is left unnamed only if the log table has a `scope` column, and **no migration in the repo adds that column** (the code writes it only when it exists). I could not check production. If the column is missing there, case (b) names the person who made that first save.
- **No stored "set by" field:** not added, as instructed. See Proposed tasks.
- **Nothing was run in a browser or on a touch screen.**

## Deploy steps for Mira

1. Merge, pull, `php artisan view:clear`. No migration, no build, no restart.
2. `rts_set_by` comes with freshly built row data. Rows served from the page's read cache do not have it until the cache is rebuilt; the page's own "🔄 Refresh" button does that. Until then the hover simply has no "Set by" line.

## What a browser check must look at (amendment 013-2)

CEO view, compact, 1707 px.

1. **Row heights** with the one-liner from done-when 5: rows without page notes near 45; `tableWidth` near 1685.
2. **`rts_set`:** only the percentage and the pencil. Hover: a dark box at the pointer, at once, with "from date", the 💬 note and "Set by name". Check one row whose RTS you know who set, and one where a promo was changed later by someone else: the name must be the person who set the RTS.
3. **Action, Claude Action, CEO Action:** at most three lines, no author line. Hover: the full text, then the author line. Pencil and "more" still work, and the box disappears when you click them.
4. **Item Val.:** value and pencil only; hover shows "cogs" or "from date" and the note. Item Val. (CEO): no hover.
5. **Page notes:** "mixed primary", "computed since", "back-filled" in full, wrapping, nothing cut; clicking them still opens the breakdown.
6. **Expand a row:** its texts show in full, "more" is gone; collapse: three lines again. Open one text with "more", expand and collapse the row: that text stays open. Then "Expand all" and "Hide all".
7. **The box:** near the right and bottom edges of the window it stays inside; it is not clipped by the table or shrunk by the zoom; it goes away on scroll and when the pointer leaves the table; it sits under the edit modals, not over them. Note whether the native tooltip appearing a second later on top of it is acceptable.
8. **Touch** (phone or device emulation): tap a cell → box; tap elsewhere → gone; tap the pencil → editor opens, no box left behind.
9. **Old layout (`↔ 100%`):** notes and author lines visible as before, no box, `document.querySelectorAll('[data-ow-tip]').length` is `0`.
10. **Marketing user:** `rts_set` hover works; "Set by" shows a name, never an email address.

## Proposed tasks

1. **Store who set the RTS** (medium, needs Busing's yes and a migration): a `rts_set_by` column on `page_item_settings`, written only when the RTS field itself is typed. It removes the guessing from the log.
2. **Confirm or add the `scope` column on `page_item_settings_log`** (low): the code writes it when present; the repo has no migration for it.
3. **A date floor for the log query** (low) when the log grows.
4. **Hover for the Reason columns** (low), if he wants the same there.
5. **A test harness for the data endpoint** (medium): it would let the row wiring of fields like this one be proven.

## Suggestions for Mira

- Check step 2 of the browser list with a real case before telling Busing the name is reliable; until task 1 exists, "Set by" is a best reading of the log.
- For the reviewer's memory after merge (not written in this run): `saveItemSetting` logs the carried RTS as the new value on promo-only and remark-only saves, so any reading of that log for "who" must follow runs of a value; tests on this page look for strings and never execute the script.
