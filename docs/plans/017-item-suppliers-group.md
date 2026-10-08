# Plan 017: Suppliers group on the item table

Spec: `docs/specs/017-item-suppliers-group.md`. Branch `feat/017-item-suppliers-group`, base
`90b900f`. Risk tier: high. No product code and no test is written before the reviewer's "go".
The numbered questions are in the result file under "Open questions"; this plan says what is built
under the recommended answer to each.

## 1. What the code shows (on `90b900f`)

**The page and its switch**

- `ItemController::index` (line 87) sets `$layoutOld = $request->query('layout') === 'old'` and
  passes it to `item.index`. `index.blade.php:830-853` includes `item._table_old` when it is set,
  otherwise the default layout (`_table_order` / `_table_sales`). `$effectiveIsCEO = $isCEO &&
  $viewAs === 'ceo'` (line 70).
- `$layoutOld` is read in more places than the include: the toolbar link (`:682-694`), the
  category option text (`:822-826`), `layoutOld: @json(...)` in the script (`:1381`), the hidden
  Claude columns (`:1627`, "the pinned `_table_old` has no cell template for them"), the address
  kept by `load()` (`:1730`, `qsObj.layout = 'old'`), the default item order by HOLD (`:3064`),
  one message's language (`:3834`) and `viewSwitchUrl()` (`:3385`).
- `_table_old.blade.php` (866 lines): one header row (`:5-47`); four full-width rows with
  `:colspan="cols.length + 2"` (`:52, 60, 66, 71`); the item row (`:88-219`) with the click handler
  `@click="row.hasPages && toggleItemExpand(row.item_name)"` (`:90-91`), the item cell (`:92-136`,
  with the worklist's extra lines inside `@if($effectiveIsCEO)`), the Item cell with the supplier
  stack (`:137-213`, the stack inside `@if($effectiveIsCEO)` `:145-203`), then the `cols` loop that
  includes `item._agg_cells` (`:214-218`); the repeated per-page header (`:230-238`); the page row
  (`:240-748`) with its own inline three-line RTS / DEL / INT cell; the expanded page block
  (`:754-759`, `:colspan="(cols.length + 2)"`); the TOTAL row (`:762-…`, `<td>TOTAL</td><td></td>`).
- `item._agg_cells` is included by `_table_old` only. Its `jnt_rdt` cell is a nested three-row
  table (`:72-107`) that follows `col.members`.

**The quotes**

- `ItemController::quoteRows()` (line 700) is the only source of the lists in GET `/item/quotes`
  and in the answers of POST `/item/quotes` and POST `/item/quotes/delete`. It orders with
  `->orderBy('price')` and nothing else: no rule for null, no tie key. The worklist has its own
  query with the same `orderBy('price')` (line 313); it is not touched.
- Role gates as they are: `quotes()` and `suppliers()` answer `{ok:true, …empty lists}` to every
  role but CEO; `quoteSave()` and `quoteDelete()` answer 403; `checkAccess()` gives 404 to roles
  other than CEO, Marketing - OIC, Marketing. `quoteDelete` validates `id` as `required|integer`
  with no `exists` rule and deletes only when the row is found, so a second delete already answers
  ok with the current list.
- The page calls `loadItemSuppliers()` and `loadItemQuotes()` only inside `@if($effectiveIsCEO)` in
  `init()` (`index.blade.php:3968-3971`). Both loaders swallow errors and only assign when the key
  is present; nothing records that they answered. `quotesFor`, `suppliersFor`, `supKey`,
  `safeLink`, `openQuote`, `saveQuote`, `deleteQuote` and `quoteForm` are in the shared script for
  every role (`ItemPageTest::test_marketing_to_order_has_no_cost_line_…` relies on that).
- `safeLink(u)` (`:3850`) is `const s = String(u || '').trim(); return /^https?:\/\//i.test(s) ? s : '';`.
- `openQuote(name, q)` stores `key: supKey(name)` **and** `item_name: name`; the Old view opens the
  form on `quoteForm.key === supKey(row.item_name)` only, which is why two variant rows open it
  together.
- Other readers of the endpoint: the default layout's expanded block (`_il_expand.blade.php:253`,
  `x-for … in quotesFor(G.item_name)`), `ilCheapQuote()` (compares prices itself, `> 0` only) and
  the photo page (`photo.blade.php:111`, `x-for="q in (it.quotes||[])"`). All list every quote.

**The existing tests that constrain the work** (`tests/Feature/Item/ItemPageTest.php`)

| Test | What it pins | How the plan lives with it |
|---|---|---|
| `test_old_table_partial_is_byte_identical_to_the_base_commit` | sha1 of `_table_old.blade.php` | The file is not opened for writing. The new partial is a copy under another name. |
| `test_new_layout_font_sizes_are_never_below_11px` | every `font-size` between the comment `/* ── Bagong layout (005)` and the first `</style>` after it, plus four named partials | The new styles go in their own `<style>` block **after** that `</style>`, in a partial the test does not name. The test is not edited. |
| `test_old_total_is_unchanged_and_only_in_the_old_view` | `<td>TOTAL</td>` is absent from the default layout and present in the Old view | The test renders only those two. Its intent is "the Old table's TOTAL row never leaks into the default layout"; the suppliers view is a copy of the Old table, so the same `<td>TOTAL</td>` belongs in it, and the default layout still has none. **No assertion changes.** A new case (S-13.4) pins the suppliers view's TOTAL row. |
| `test_item_views_have_no_x_html` | no `x-html` in `resources/views/item/*.blade.php` (glob) | The three new files sit in that folder, so the glob covers them. |
| `test_default_render_has_the_new_layout_and_the_old_one_has_the_old_table` | `'🗂 Old view'` is not in the Old render | The back link "🗂 Old view" is rendered in the suppliers view only. |
| `ItemLayoutTest::test_only_the_exact_string_old_selects_the_old_layout` | `viewData('layoutOld')` for four addresses | Unchanged: `layoutOld` keeps its meaning for those four. |

**Measured in a throwaway test (written, run and deleted; nothing of it is committed)**

- A Blade directive that starts at column 0 on its own line leaves **no byte** in the output when
  its branch is false: `"a\n@if($x)\nb\n@endif\nc\n"` renders `"a\nc\n"`. The same directive
  indented leaves its indentation behind (`"c\n  e\n"`). Every suppliers-only block in a shared
  file is therefore written with its `@if` / `@else` / `@endif` at column 0 (the file already does
  this at `index.blade.php:1593` and `:1625`). This is what keeps the Old view and the default
  layout byte for byte.
- `view('item.index', […])->render()` with the fixed variables of the existing test helper is the
  same string on every call (338,181 bytes for the Old view as the CEO). The only parts that
  depend on the machine or the session are the CSRF token (empty in a direct render, 40 characters
  over HTTP) and the application root (`http://localhost`, 32 times). There is no `\r` in it.
- The page over HTTP (`GET /item?layout=old`, with the three extra tables `ItemLayoutTest` creates)
  is the same string twice in one session.

**Suite on the base in this worktree** (after the one `composer install --no-interaction` from the
unchanged lock file; `git status` clean, no diff on `composer.json` or `composer.lock`):
`php.bat vendor/phpunit/phpunit/phpunit` → `Tests: 589, Assertions: 6575, Errors: 1, Failures: 1,
Skipped: 3.` The two red tests are the ones the spec names
(`ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, missing
`storage/app/credentials.json`, and `ExampleTest`).

## 2. The change

### 2.1 Files

| File | Change |
|---|---|
| `app/Http/Controllers/ItemController.php` | `index()`: one more view variable. `quoteRows()`: the order and the `cheapest` flag. Nothing else. |
| `resources/views/item/index.blade.php` | Five suppliers-only blocks at column 0 (include switch, style include, script include, the address kept by `load()`, the two loaded flags) and one toolbar block (the two links). |
| `resources/views/item/_table_suppliers.blade.php` | New. Starts as a byte copy of `_table_old.blade.php`, then changed as in 2.4. |
| `resources/views/item/_suppliers_style.blade.php` | New. One `<style>` block; every selector starts with `.spl-`. |
| `resources/views/item/_suppliers_js.blade.php` | New. The suppliers-only members of the page's Alpine object (same pattern as `owner._claude_action_modal_js`). |
| `resources/views/item/_agg_cells.blade.php` | One optional parameter (`$rdtOneLine`) around the `jnt_rdt` cell, directives at column 0. |
| `tests/Feature/Item/SuppliersGroupTest.php` | New: every new test, as the spec says. Its `setUp` creates the three extra tables the page needs over HTTP (`tasks`, `ads_manager_reports`, `fee_settings`, as `ItemLayoutTest` does). No existing test file is edited. |
| `qa/stories.md`, the result, `TODO.md` (if findings are accepted) | As the spec says. |

`_table_old.blade.php`, `routes/`, `composer.json`, `composer.lock` and every migration: no diff.

### 2.2 The switch and the gate (`ItemController::index`)

```php
$layout          = $request->query('layout');
$layoutSuppliers = $layout === 'suppliers' && $effectiveIsCEO;
$layoutOld       = $layout === 'old' || $layout === 'suppliers';
```

- Exact strings only. An array (`layout[]=suppliers`) is not `===` either string, so it is the
  default layout, with no error.
- Not the effective CEO view with `layout=suppliers`: `layoutOld` true, `layoutSuppliers` false.
  The view then gets exactly the variables `layout=old` gives it, so the response is the Old view
  byte for byte (S-18.1 compares the two responses).
- The effective CEO view with `layout=suppliers`: both true. The page takes every Old-view branch
  listed in section 1 (default order by HOLD, the same texts, the Claude columns hidden because the
  copied table has no cell template for them, like the Old table) and swaps only the table
  partial. This is the smallest switch: no existing `layoutOld` check is edited. Open question 3.
- The gate is `$effectiveIsCEO`, computed on the server from the role and the allow-listed
  `view_as`. Every suppliers-only block in the views tests `!empty($layoutSuppliers)` and nothing
  else, so one boolean decides all of it.

In `index.blade.php` (all directives at column 0):

1. After the first `</style>` (line 460): `@if(!empty($layoutSuppliers))` →
   `@include('item._suppliers_style')`.
2. At the table include (line 830): inside the `@if(!empty($layoutOld))` branch,
   `@if(!empty($layoutSuppliers))` → `@include('item._table_suppliers')` `@else` → the existing
   `@include('item._table_old')` line, untouched.
3. In the script object, before `async init()`: `@if(!empty($layoutSuppliers))` →
   `@include('item._suppliers_js')`.
4. In `load()` after line 1730: suppliers-only line `qsObj.layout = 'suppliers';` so that the
   address keeps the view (without it the first load would rewrite it to `layout=old`).
5. In `loadItemSuppliers()` and `loadItemQuotes()`, after the existing assignment, one
   suppliers-only line each (2.5).
6. Toolbar, right after the existing layout link (line 694), inside
   `@if(!empty($effectiveIsCEO) && !empty($layoutOld))`: in the suppliers view the link back
   ("🗂 Old view", title "Back to the original table"), otherwise the link in ("🏷 Suppliers view",
   title "Open the table with suppliers and prices side by side"). Both are one `<a>` in the style
   of the links beside them, with the address built at click time from the current query
   (`new URLSearchParams(window.location.search)`, `layout` set, everything else kept), written
   inline in the `@click.prevent`, so the Old view gains one element and **no new helper name**.

### 2.3 Quote order and `cheapest` (`ItemController::quoteRows`)

The query keeps its `orderBy('price')` (harmless) and the rows are then sorted **in PHP**, per
item key, so the result does not depend on the database's null order or on its tie order:

1. quotes with a price above 0 first, price ascending;
2. then quotes with no price or a price of 0 (both "no usable price"), by id only;
3. ties in group 1 by id ascending.

`cheapest`: per item key, when at least two quotes have a price above 0, `true` for every quote
whose price (compared as the two-decimal string the method already builds) equals the lowest of
them; `false` for all others and in every other case. The stored price is returned unchanged
(`0.0` stays `0.0`, null stays null). Because the three endpoints share `quoteRows()`, the save
and delete answers carry the same order and flag with no further change. The worklist query is not
touched (`WorklistTest` stays as it is).

### 2.4 The table (`_table_suppliers.blade.php`)

First step of the task: `cp _table_old.blade.php _table_suppliers.blade.php`, committed in the
same commit as the changes below (the diff between the two files is what the reviewer reads).
The whole partial is only ever included when `$layoutSuppliers` is true, so it needs no
`$effectiveIsCEO` checks of its own; the copied ones stay as they are.

- **Table root:** `<table class="spl-table">`. Every new rule is scoped under it.
- **Header, two rows.** Row 1 (`class="spl-h1"`): Page (`rowspan="2"`, `spl-c1`), Item
  (`rowspan="2"`, `spl-c2`), `<th class="spl-grp" colspan="4">SUPPLIERS</th>`, then the existing
  `x-for` header template with `rowspan="2"` added (its sort and drag handlers unchanged). Row 2
  (`class="spl-h2"`): `Supplier 1` (title "Cheapest first. Changing a price can move a quote to
  another column."), `Supplier 2`, `Supplier 3`, `PO` (title "Supplier(s) from purchase orders"):
  plain `<th>`, no `@click`, no `draggable`. The global rules `thead th:first-child` /
  `:last-child` (corner radius) would hit Supplier 1 and PO: the style block resets them for
  `.spl-h2`. Row 2 is sticky at `top:22px` under a row 1 of fixed 22 px height.
- **Full-width rows:** the five `cols.length + 2` become `cols.length + 6`.
- **Item row.** The row's click handler is unchanged.
  - Cell 1 (`spl-c1`): as copied (chevron, photo, name, HOLD, the worklist's extra lines).
  - Cell 2 (`spl-c2`): "N running page(s)" / "⚠ walang running page" as copied; the PO line, the
    grey "walang supplier", the quote lines, the "dati" text, the red line, the inline form and
    the "+ supplier quote" button are removed from it; Change and Copy stay, wrapped in
    `<div class="spl-rowact">` (hidden until row hover or focus by the style block).
  - The group, in this order (Alpine's `x-if` takes one root element, so the four cells cannot be
    four literal `<td>`s under one condition):
    1. `<template x-if="!splReady()">` → one `<td colspan="4" class="spl-sc spl-wait" @click.stop>`
       with the neutral placeholder.
    2. `<template x-if="splReady() && splNone(row.item_name)">` → one
       `<td colspan="4" class="spl-sc spl-nosup" @click.stop>`: "⚠ wala pang supplier" once and the
       "+ supplier quote" button.
    3. `<template x-for="si in (splReady() && !splNone(row.item_name) ? [0, 1, 2] : [])">` → one
       `<td class="spl-sc" @click.stop>` per slot: the quote `splTop3(row.item_name)[si]` (name
       button over price and MOQ; on slot 2 the "+N" button when `splRest(row.item_name) > 0`), or
       the "+" button when the slot is empty.
    4. `<template x-if="splReady() && !splNone(row.item_name)">` → the PO cell
       `<td class="spl-sc spl-po" @click.stop>`: the first of `suppliersFor(row.item_name)` over its
       cost and "+N" for the others, or a faint dash.
    So a row always has exactly four columns after Item: either one cell spanning four, or three
    slots and the PO cell. Open question 6 (how S-13.2 reads "four cells").
  - Then the `cols` loop, with `@include('item._agg_cells', ['rdtOneLine' => true])`.
- **Repeated per-page header:** `<th colspan="4"></th>` after Item.
- **Page row:** `<td colspan="4" class="spl-under"></td>` after its Item cell; everything else
  (including its inline three-line `jnt_rdt` cell) stays as copied. Its first two cells get
  `spl-c1` / `spl-c2`.
- **TOTAL row:** `<td>TOTAL</td><td colspan="5"></td>` then the `cols` loop as copied.
- **Text only.** Every supplier name, price, MOQ, date and cost is an `x-text`; the full name is
  also in `:title` and `:aria-label`; the link is `<template x-if="safeLink(q.link)"><a
  :href="safeLink(q.link)" target="_blank" rel="noopener" @click.stop>link</a></template>` (the
  page's existing guard, unchanged); the photo is `:src` / `:alt`. No `x-html`, no `innerHTML`, no
  `{!! !!}`. `q.note` is not referenced anywhere in the new files.
- **Price text:** `q.price !== null ? money(q.price) : '—'` (the explicit null check; a stored 0
  shows as the formatter prints it, `₱0.00`). MOQ only when present (`x-show="q.moq"` is not used:
  a MOQ of 0 is a value; the test is `q.moq !== null && q.moq !== undefined`). The cheapest mark is
  `:class="q.cheapest === true ? 'spl-price spl-low' : 'spl-price'"`: the browser never compares
  prices.

### 2.5 The script block (`_suppliers_js.blade.php`)

Members added to the page's object, all prefixed `spl`, rendered only in the suppliers view:

```js
splLoaded: { quotes:false, po:false },
splFailed: false,
splReady(){ return this.splLoaded.quotes && this.splLoaded.po; },
splTop3(name){ return this.quotesFor(name).slice(0, 3); },
splRest(name){ return Math.max(0, this.quotesFor(name).length - 3); },
splNone(name){ return !this.quotesFor(name).length && !this.suppliersFor(name).length; },
```

(`splReady`, `splTop3` and `splRest` are the pinned texts of S-15.10 and S-14.8.) Plus the card's
state and four small functions: open (view / list / PO / form), close, place, and the form's
open test.

- **Loaded flags.** In the shared loaders, one suppliers-only line each, right after the existing
  assignment: `if (res.ok && j && j.ok === true && j.quotes) this.splLoaded.quotes = true; else
  this.splFailed = true;` (and the same for `j.suppliers` → `splLoaded.po`), and
  `this.splFailed = true;` in the existing `catch`. A flag is never set to true anywhere else, so
  the red band and the "+" cells cannot appear before both lists answered with `ok`. The existing
  lines of the loaders are not edited; `init()` still calls each loader once.
- **Placeholder.** While waiting: a grey "…" (title "Loading suppliers…"). After a failed fetch:
  a grey "—" with the title "Hindi na-load ang listahan ng supplier. I-refresh ang page." No retry
  control: the lists load once at start-up as today, so a reload of the page is the retry. Open question 5.
- **The card.** One card at most, rendered inside the cell that owns it by an `x-if` on the card's
  state (so only the open card is in the DOM and the Tab order is the natural one: name → card
  controls → next cell). `position:fixed`, left and top computed from the cell's
  `getBoundingClientRect()` when it opens; it flips upward when the space below, minus the sticky
  TOTAL row, is too small, and is clamped to the window's width. No ancestor of the table has a
  `transform`, `filter` or `contain` (checked: `body`, `#scroll`, `.card`), so `fixed` is relative
  to the window and the scroll area cannot clip it. `z-index:60`: above the sticky header (30),
  the sticky corner (40) and TOTAL (20, 25), below the page's modals (80 and up) and the toolbar's
  menus.
  - Opens: on pointer enter of a filled cell where the device hovers
    (`matchMedia('(hover:hover)')`; a hover card is not pinned and closes on pointer leave or when
    the table scrolls); on click, tap, Enter or Space on the name button, "+N" or the PO name
    (pinned; a second click closes it).
  - Closes: Esc (focus returns to the control that opened it); a click or tap anywhere outside the
    owning cell (a capturing listener on the window, because the cells stop the click before it
    bubbles); opening another card.
  - Contents, from data already loaded (no request): the quote card (full name, price and MOQ or
    "walang MOQ", "dati" price and date, updated date, link, photo thumbnail that opens the
    existing photo modal, edit, remove); the list card behind "+N" (every quote in server order
    with the same marks and controls, and "+ supplier quote"); the PO card (every PO supplier with
    cost, date and order number, no controls).
- **The form.** The page's existing `quoteForm`, `openQuote()`, `saveQuote()`, `deleteQuote()` and
  routes, unchanged. The same fields as the Old view's form (supplier, price, MOQ, link, photo,
  Save, Cancel) are drawn inside the card. It shows where
  `quoteForm.key === supKey(row.item_name) && quoteForm.item_name === row.item_name` and the card
  belongs to that row (pinned text of S-16.6), so of two variant rows that share quotes only the
  one whose control was used opens it. A card that holds the open form is pinned: it follows the
  table's scroll, and under the recommended answer to open question 4 it closes on Cancel, Esc or
  a successful save, not on a click elsewhere. A failed save leaves `quoteForm` as it is (the
  existing function already does), so the form stays with its values.
- **Known and unchanged:** `saveQuote()` does not send the note, and the server sets fields that
  are not sent to null. That is today's behaviour of the Old view's form and is already recorded
  in `TODO.md` (001); the suppliers view uses the same function and inherits it.

### 2.6 The styles (`_suppliers_style.blade.php`) and the one-line RTS / DEL / INT

- The comp's added block (after its comment "item-suppliers change"), with the class names
  prefixed `spl-` (open question 1) and every selector scoped under `.spl-table` with child
  combinators (`.spl-table > tbody > tr.item-row > td …`), so the nested tables of the expanded
  page block are not touched. Widths as in the brief: item cell 284, Item 150, supplier cells 118,
  PO 104, with `width = min-width = max-width` so the ellipsis works.
- Sticky item column inside `@media (min-width:768px)` only: `spl-c1` cells and the TOTAL row's
  first cell (`.spl-table > tbody > tr.total-row > td:first-child`, so the markup stays
  `<td>TOTAL</td>`). An opaque background for every row kind: item row, its hover, the no-page
  tint and its hover, page rows, the expanded page row, the editing row, the repeated per-page
  header, TOTAL. Header corner `z-index:40`, body cells 6, TOTAL's first cell 25.
- Change and Copy: `.spl-rowact{opacity:0}`, shown on `tr.item-row:hover` and
  `tr.item-row:focus-within`; always shown under `@media (hover:none), (max-width:767px)`.
- Header: `.spl-h1 th{height:22px}`, `.spl-h2 th{top:22px}`, the corner-radius reset for `.spl-h2`.
- `_agg_cells.blade.php`: at column 0, `@if(!empty($rdtOneLine))` → a `jnt_rdt` template with
  `<div class="spl-rdt">` and one `<span>` per member in `col.members` (RTS, DEL, INT), each with
  `x-text` of the percentage (or a dash) and `:title` of "RTS 12.5% (120)"; `@else` → the existing
  nested table, untouched; `@endif`. `$rdtOneLine` is only passed by `_table_suppliers`.

## 3. The traps the spec lists, one by one

| Trap | Handling |
|---|---|
| Hash pin of `_table_old` | Not edited; new partial is a copy (2.4). S-19.1 is the existing test. |
| Font-size scan of a marked range | New styles are a separate `<style>` after that range (2.2 step 1), in a partial the scan does not read. |
| `<td>TOTAL</td>` only in the Old view | Section 1, table: the assertion is not changed; the suppliers view keeps the exact string. |
| No `x-html` in the item views | New files are in the globbed folder; S-19.6 and S-20.1 add `innerHTML` and `{!!`. |
| Plain `orderBy('price')` | Sorted in PHP in `quoteRows()` only (2.3); the worklist query is not touched. |
| Endpoint gated by role, page by effective view | Both kept. The new view adds a third gate on the same server boolean (2.2). |
| Shared script carries the quote helpers for every role | Not touched. New names live in `_suppliers_js`, included only in the suppliers view. |
| Row click handler | Each of the group's `<td>`s carries `@click.stop`; Change, Copy, the photo and the chevron keep theirs (S-16.1). |
| Second header row offset; first-child radius; per-page header | 2.4 and 2.6. |
| Scroll container clips absolute children | The card is `position:fixed`, placed on open (2.5). |
| Money formatter prints 0.00 for null | Explicit `q.price !== null` check for the dash (2.4). |
| Two variant rows share quotes and the open form | The form's open test also compares `quoteForm.item_name` (2.5). |

## 4. Tasks

Every task: red first (one line per slice in the result: test name, first failure line), then the
code, one `feat:` commit per slice with test and code together. Developers and reviewers are
subagents in this session, in the foreground. High tier: developer on opus, `skeptic-reviewer` on
opus at adversarial depth on the task's diff; medium: sonnet and standard depth. No task is
`parallel-safe`: every one touches `index.blade.php`, the controller or the new partial.

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| T1 | Stories into `qa/stories.md`; the characterisation and absence tests, in one `test:` commit **before any product code**: the base renders pinned (2.7 below), the unchanged endpoint and gate behaviour | backend | low (tests and docs only) | S-14.9, S-16.2, S-16.3 (named), S-16.4, S-16.5, S-18.3, S-18.4, S-19.1 (named), S-19.2, S-19.5 (named) |
| T2 | Quote order and `cheapest` in `quoteRows()` | backend | high | S-14.1 to S-14.7 |
| T3 | The switch and the gate: `$layoutSuppliers`, the include switch, `_table_suppliers` as a byte copy, the address kept by `load()`, the two toolbar links | backend | high | S-13.5, S-18.1, S-18.2, S-19.3 (and S-18.4, S-19.2 stay green) |
| T4 | The table's structure: two-row header, the group's cells and their loaded state, the Item cell cleaned, colspans, page row, per-page header, TOTAL; `_suppliers_js` with the helpers and the loaded flags | frontend | high | S-13.1 to S-13.4, S-14.8, S-15.10, S-19.4, S-19.6, S-21.1 |
| T5 | The cells' contents, the card, the form in the card, click isolation, text-only bindings, the link guard | frontend | high | S-16.1, S-16.6, S-20.1, S-20.2 |
| T6 | The compact row: `_suppliers_style`, sticky column, Change and Copy on hover, one-line RTS / DEL / INT through `_agg_cells` | frontend | medium | S-17.1, S-17.2 |
| T7 | Close: `php -l` on changed PHP, the full suite, one last adversarial review of the whole diff on the role gate, the endpoint and every place supplier text is printed; minors on untrusted paths fixed in one wave, the rest to `TODO.md`; `qa/stories.md` last-run column; the result | main session | — | the done-when list |

Browser and owner-check cases (S-13.6, S-13.7, S-14.10, S-14.11, S-15.1 to S-15.9, S-16.7, S-16.8,
S-17.3 to S-17.7, S-19.7, S-19.8, S-20.3, S-21.2) are written into `qa/stories.md` in T1 as
"none: browser check" / "Not run · browser" and under `## Owner checks`; no task can run them
here. The kit's optional browser check is skipped for the same reason (this worktree has no
environment file and no database, so the app cannot be started); the result will say so and list
what to look at first.

### 2.7 How the base renders are pinned (T1)

`SuppliersGroupTest` gets its own copy of the existing render helper (same fixed variables, plus
`isCEO`, `isMarketingOIC` and `layoutSuppliers`). In T1, on a tree that still has no product
change, it pins the sha1 of the normalised render of the default layout and of the Old view for:
the CEO, the CEO viewing as Marketing, a Marketing user and a Marketing - OIC user (eight values,
one table; open question 2). Normalised = CRLF to LF, the application root (plain and JSON-escaped) to a fixed
word, the CSRF token to a fixed word. The commit is green when made and its parent is the plan
commit, so the reviewer can check out that commit and see the hashes come from the base views.

- S-18.4 and S-19.2: the hashes must still match after every later task. They turn red on any
  byte that reaches the default layout or a Marketing render.
- S-19.3: written in T3, red first. The Old view as the CEO must contain the link's exact markup
  once; with that one string removed, the hash must equal the base hash of the Old view as the
  CEO.
- S-18.1 runs over HTTP (real requests as each user): for each of the three users the body of
  `layout=suppliers` equals the body of `layout=old` after the same normalisation, and holds none
  of the markers (`SUPPLIERS`, `Supplier 1`, `Supplier 2`, `Supplier 3`, `spl-`, `splReady`,
  `splTop3`, `splRest`, `splNone`, `splLoaded`, `layout=suppliers`, `Suppliers view`, the seeded
  supplier's name). Checked on the base with a search of the item views and the partials they include: none of
  these strings is there today. (The first choice of prefix, `sg-`, was dropped for this reason:
  it is inside the existing class `msg-tbody`, so its absence could not be asserted.)

## 5. What each kind of test is

- **Endpoint tests** (S-14, S-16.2, S-16.4, S-16.5, S-18.3): real requests on in-memory sqlite,
  fixtures with literal prices; the expected order is written out in the test (142, 148, 155,
  160, none), never computed.
- **Render tests**: read the rendered page or the partial's source. They prove that the markup
  and the script text are there; **they do not run the script**. Each pin names the exact text it
  pins (`splTop3`, `splRest`, `splReady`, the form's open test, the two loaded-flag lines,
  `safeLink`).
- **Characterisation** (S-14.9, S-16.3, S-16.5, S-18.4, S-19.1, S-19.2, S-19.5) and **absence**
  (S-13.3, S-18.1 to S-18.3, S-19.6, S-20.1) are reported as such in the result, each with what
  would turn it red.

## 6. Size and risks

Estimate: `_table_suppliers` about 1,000 lines (866 copied), `_suppliers_js` about 130,
`_suppliers_style` about 90, the controller about 30, `index.blade.php` about 35, `_agg_cells`
about 20, tests about 650. That is the "large job" of the budget, not more. The Old view can stay
byte for byte (measured, section 1), so nothing here is a reason to stop.

What no test here can show, and so goes to the reviewer's preview list: the real row height (49
to 51 px), the two header rows lining up while scrolling (row 1 must really be 22 px), the sticky
column (including the 16 px side padding of the scroll area, where scrolled cells could show to
the left of the sticky cell), the card's placement near the bottom and the right edge, hover
versus tap, the keyboard path through the card, and every `browser` case.
