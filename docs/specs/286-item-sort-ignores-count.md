# 286: /owner/private sorts Item by the item name, ignoring the "N x " count

## Why

On /owner/private, clicking the Item column header sorts by the full text. All "1 x …" rows come before all "2 x …" rows, so the same item is split apart. The Item sort should use the item name only.

## Change

In `resources/views/owner/private.blade.php`, in the Alpine sort (`sortedRows()`, the `computedFor` / `sort((a,b)=>…)` near the `localeCompare`):
- When the sort column is `item_name`, compare the name with a leading count prefix removed: the regex `/^\s*\d+\s*x\s*/i`.
- Compare case-insensitively.
- When two names are equal after that, the smaller count comes first (1 x before 2 x). Within one count, keep the current order.
- The descending direction reverses the whole order.
- **Display is unchanged.** Other columns sort exactly as before.

## Tests

Add a test the repo can run. If there is a JS test harness for this page, use it. Otherwise add a small feature test that renders the page or view and asserts the new sort helper/expression is present. If a behaviour test is not practical, say so in the result and state how it was checked manually. Look for existing tests touching owner/private first (`tests/Feature`, "owner/private" or "OwnerPrivate").

Run the new test and the existing owner/private tests. `SharedPagesBasePinTest` is a known failure on base; note whether it still fails the same way.

## Done when

- Item sort groups one item together, name first (case ignored), then the smaller count; descending reverses it.
- Other columns and the displayed item text are unchanged.
- The tests above pass; branch `fix/286-item-sort-ignores-count` pushed; no deploy.
