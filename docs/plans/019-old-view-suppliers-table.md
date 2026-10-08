# Plan 019: The Old view shows the suppliers table

Spec: `docs/specs/019-old-view-suppliers-table.md`. Branch `feat/019-old-view-suppliers-table`,
base `5370bdc`. Risk tier: high. The plan raises no question the spec leaves open, so the build
follows in the same run.

## 1. What the code shows (on `5370bdc`)

- `ItemController::index` (`:89-91`) reads `layout` once and sets two view variables:
  `$layoutSuppliers = $layout === 'suppliers' && $effectiveIsCEO` and
  `$layoutOld = $layout === 'old' || $layout === 'suppliers'`. `$effectiveIsCEO = $isCEO &&
  $viewAs === 'ceo'`, where `view_as` is lower-cased, trimmed and anything but `marketing` becomes
  `ceo` (`:68-70`). None of that changes.
- `index.blade.php` tests `$layoutSuppliers` for every suppliers-only block: the styles (`:461`),
  the table include (`:849`), the loaded flags of the two loaders (`:3217-3247`), the script
  members (`:4004`) and the address word (`:1753-1756`, `qsObj.layout = 'suppliers';`). The two
  toolbar links of spec 017 sit in `@if(!empty($effectiveIsCEO) && !empty($layoutOld))`
  (`:698-712`): "Old view" when `$layoutSuppliers`, "Suppliers view" otherwise.
- `$layoutOld` alone decides everything the Old view shares with the suppliers table: the
  "New view" link, `layoutOld: true` in the script, `if (this.layoutOld) qsObj.layout = 'old';`
  (`:1752`), the hidden Claude columns, the default order.
- The framework trims query strings before the controller (`layout=old%20` renders the same bytes
  as `layout=old` on the base), and an array is never `===` a string.
- `view_as[]=marketing` answers 500 on the base (an array cast to a string, before the gate).
  Not caused by this work and not touched; listed under "Proposed tasks".

## 2. The gate: how the layout words map

The controller keeps its three lines and its two variables; no third variable is added.

```php
$layout          = $request->query('layout');
$layoutSuppliers = ($layout === 'old' || $layout === 'suppliers') && $effectiveIsCEO;
$layoutOld       = $layout === 'old' || $layout === 'suppliers' || $layout === 'original';
```

| `layout` (after trimming) | CEO view: `layoutOld` / `layoutSuppliers` | CEO view gets | Other viewers: `layoutOld` / `layoutSuppliers` | Other viewers get |
|---|---|---|---|---|
| `old` | true / **true** | the suppliers table, link "Original table", address kept as `layout=old` | true / false | the original table, as today |
| `suppliers` | true / **true** | the same bytes as `old`; address becomes `layout=old` after the first load | true / false | the same bytes as `old` |
| `original` | true / false | the original table, link "Table with suppliers", address kept as `layout=original` | true / false | the same bytes as `old` (address becomes `layout=old`, as today) |
| anything else, missing, an array | false / false | the default layout | false / false | the default layout |

`$layoutSuppliers` stays the one boolean every suppliers-only block tests, and it can only be true
when `$effectiveIsCEO` is. "The CEO view of the original table" needs no variable of its own: it
is `$effectiveIsCEO && $layoutOld && !$layoutSuppliers`, which the toolbar block already computes
with its `@if` / `@else`. For a viewer who is not the CEO view that expression is false, so
neither link and no new word is rendered for them.

## 3. The Blade changes (`resources/views/item/index.blade.php` only)

1. **Toolbar (`:698-712`).** Same outer `@if`, same anchor markup and style. Inside
   `@if($layoutSuppliers)`: link "🗂 Original table", title "Open the table as it was before the
   suppliers columns", `href="?layout=original"`, click handler sets `layout` to `original` on the
   current query. In the `@else`: link "🏷 Table with suppliers", title "Back to the table with
   suppliers and prices side by side", `href="?layout=old"`, handler sets `layout` to `old`.
2. **Address word (`:1753-1756`).** The suppliers-only block that wrote `qsObj.layout =
   'suppliers';` is removed, so the suppliers table keeps `layout=old` through the line above it.
   In its place, inside `@if(!empty($effectiveIsCEO) && !empty($layoutOld) && empty($layoutSuppliers))`:
   a one-line comment and `qsObj.layout = 'original';`, so the original table is not turned into
   the suppliers table by the first load.

Nothing else in the file changes. `_table_old`, `_table_suppliers`, `_suppliers_style`,
`_suppliers_js`, routes and the quotes endpoint are not opened for writing.

## 4. Stories and ids

The last story in `qa/stories.md` is S-21, so: A = **S-22** (S-22.1 to S-22.5, S-22.5 the owner
check), B = **S-23** (S-23.1 to S-23.3), C = **S-24** (S-24.1 to S-24.4). The slice is appended at
the end of the file; S-22.5 is added under `## Owner checks`. Cases S-13.5, S-18.1 and S-19.3 of
slice 017 are rewritten in place (old and new Then go into the result under "Story changes"). The
owner checks S-13.7, S-17.7 and S-19.8 name the address `layout=suppliers`, which still opens that
view; their text is left alone.

## 5. Tasks

All in `tests/Feature/Item/SuppliersGroupTest.php` (it owns the pinned hashes and the render
helpers), red first.

| # | Task | Cases | Tier | Side | Files |
|---|---|---|---|---|---|
| 1 | The gate and the address word: the three controller lines, the address block | S-22.1, S-22.2, S-22.3, S-23.1 (routing and address), S-24.1 to S-24.4, S-13.5, S-18.1 | high | backend | `ItemController.php`, `index.blade.php`, the test file |
| 2 | The two toolbar links | S-22.4, S-23.1 (link), S-23.2, S-19.3 | high | frontend | `index.blade.php`, the test file |
| 3 | Stories, owner check, result | S-22.5, S-23.3, all rows | low | docs | `qa/stories.md`, the result, `TODO.md` if a finding is accepted |

Tasks 1 and 2 share both files and are one review unit: one adversarial review of the whole diff.
Not parallel-safe.

**How each case is proven** (expected values come from the case text or from hashes taken on the
base, never from the code under test):

- **S-22.1** `layout=old` as the CEO: the route gives `layoutOld` and `layoutSuppliers` true; the
  render with those flags, after putting back the two strings that differ on purpose (the toolbar
  link of the suppliers view and its two address lines), has the sha1 of the suppliers view on the
  base, `6f616f8795f7bc941e8cd5b4068bb1372f572f89` (taken on `5370bdc` with the test file's own
  `render('ceo', true, true)` and `normalise`). Markers: the SUPPLIERS header, the second
  `<style>`, `splReady`.
- **S-22.2** `layout=suppliers` as the CEO: the body is identical to the body of `layout=old`; it
  holds `if (this.layoutOld) qsObj.layout = 'old';` and no `qsObj.layout = 'suppliers'`.
- **S-22.3** (characterisation) no `layout`, `OLD`, `x`, `layout[]=old`, `layout[]=original` as
  the CEO: flags false / false, all bodies identical, and the render with those flags has the
  pinned `default.ceo` hash. Red if the gate starts to accept another word or the default render
  moves.
- **S-22.4** the Old view of the CEO: no "Suppliers view", exactly one "Original table" link,
  pinned as a whole string (href, handler, title, style).
- **S-23.1** `layout=original` as the CEO: flags true / false; the stacked supplier lines of the
  original Item cell are there; none of the suppliers markers; `qsObj.layout = 'original';` once;
  the "Table with suppliers" link pinned as a whole string.
- **S-23.2** the render of the CEO's original table minus exactly two pinned strings (the link
  block, the two address lines) has the pinned `old.ceo` hash; each string occurs once.
- **S-23.3** (characterisation) the existing
  `ItemPageTest::test_old_table_partial_is_byte_identical_to_the_base_commit`, unchanged. No second
  test for the same pin. Red if the file is edited.
- **S-24.1** the three non-CEO viewers × the three words: the three bodies are identical; the
  route's view data is exactly the tuple the pinned render uses for that viewer, and that render
  has the pinned `old.<viewer>` hash; none of the markers (header words, `spl-`, helper names,
  both link texts, both titles, `layout=original`, `'original'`, a seeded supplier name), each
  marker first proven present in the CEO's renders.
- **S-24.2** (characterisation) the same nine requests: neither loader call in the script; the CEO
  view has each once.
- **S-24.3** (characterisation) the default layout for the three viewers: flags false / false and
  the pinned `default.<viewer>` hashes.
- **S-24.4** `original%20` selects what `original` selects (trimmed by the framework, as `old%20`
  does); `Original`, `ORIGINAL`, `layout[]=original` give the default layout with status 200; for
  the CEO and for a Marketing user.

**Existing tests touched, and why**

| Test | Change | Why |
|---|---|---|
| `SuppliersGroupTest::test_S_13_5_…` | renamed to say what it now pins; the word → flags table gets `original` and the new values for `old`; the link and address assertions move to the new cases | the case changes on purpose |
| `SuppliersGroupTest::test_S_18_1_…` | replaced by the S-24.1 test (three words, the new link markers); the S-18.1 row points to it | the case changes on purpose; one test per behaviour |
| `SuppliersGroupTest::test_S_18_2_…` | extended to the three words and renamed for S-24.2; the S-18.2 row points to it | same behaviour, more addresses |
| `SuppliersGroupTest::test_S_19_3_…` | the "one link only" comparison is now the S-23.2 test; the S-19.3 test keeps the half that says the CEO's Old view is the suppliers table | the case changes on purpose |
| `SuppliersGroupTest::SUPPLIERS_LINK` | removed with the link it described | the link is gone |
| `ItemLayoutTest`, `ItemPageTest`, every other `SuppliersGroupTest` test | none | they render the view directly with `layoutOld` and no `layoutSuppliers`, or read `layoutOld` from the route: all still true (`ItemLayoutTest` asserts `layoutOld` only) |

The pinned `BASE` hashes, the byte pin of `_table_old.blade.php` and the helpers `render`,
`normalise`, `hash` are not edited.

## 6. Review

`skeptic-reviewer`, spec-review mode, on the spec and this plan before the build (high tier);
`skeptic-reviewer`, adversarial depth, on `5370bdc..HEAD` after tasks 1 and 2. At most two fix
loops. Browser check: skipped unless the session has browser tools and a running app with data;
said in the result either way.
