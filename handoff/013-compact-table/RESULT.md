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

Status: **in progress.**

## Plan (written before code)

Mira's measurement shows every column sat at its minimum content width (the table was wider than the card), so the numbers can only come down by making the content itself smaller or cappable. Tier medium, one frontend task, then `skeptic-reviewer` at standard depth.

1. **Smaller cell chrome and type, compact only:** body cells `padding:2px 2px`, 11px (was `2px 4px`, 12px); header cells `padding:3px 2px`, 9.5px; the RTS block's inner cells 10px with `padding:0 2px`; pencil icons and their gap tightened. At a zoom of 0.96 or better an 11px figure is drawn larger than today's 12px at 0.86.
2. **`rts_set` in two lines:** the wrapper around the percentage and its notes gets a pixel `max-width`, `white-space:nowrap`, `overflow:hidden`, `text-overflow:ellipsis`; the percentage is a block (line 1), the "from date" note and the comment note become inline (line 2). A compact-only `:title` on the wrapper carries both notes in full.
3. **Notes that must not widen or heighten a cell:** `item_val` notes, the page cell's "mixed primary", "computed since" and "back-filled" lines, and the secondary-item lines under an item name each stay on one line with an ellipsis and a pixel `max-width` (a pixel cap is what table layout honours), each with a compact-only `:title`. Page and item names wrap; Promo wraps anywhere.
4. **Explicit widths:** every non-text header cell gets `width:1px` in compact, which makes it a fixed-width column that sits at its content width and takes no share of spare width; Page 115, Item 115 and Promo 100 get their own pixel widths; the text columns stay `width:auto` with their 135 / 155 floors, so all spare width is theirs.
5. **Header break:** `hdr()` also allows a break after a full stop inside a label (`PROF.` / `PROFIT` / `(1D)`), so `Prof.Profit(1D)` and `Prof.%(1M)` no longer set their column's width.
6. Old layout: every new rule under `.ow-compact`, every new attribute bound to `null` unless `owCompact`; the scoping test already pins the first.
