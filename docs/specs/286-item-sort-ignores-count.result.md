# 286 result: Item sort ignores the "N x " count

Base: live 1302bdfc. Branch `fix/286-item-sort-ignores-count`. View and tests only; no route, controller, migration or config change.

## What changed

- `resources/views/owner/private.blade.php`: new component method `itemSortCmp(a, b)`. It strips a leading `/^\s*(\d+)\s*x\s+/i` count (a space after the x is required, since real names are always "N x NAME"), compares the rest lower-cased with `localeCompare`, then the count (a name with no count counts as 0). `sortedRows()` uses it when `sortCol === 'item_name'`, times the direction. Equal name and count return 0, so the stable sort keeps the current order in both directions. Every other column goes through the old code unchanged; the cell still shows `sq(row.item_name)`.
- `tests/js/private-sort.cjs` (new): node runner that takes the inline `privateUI()` script from a real render of the page, stubs the browser objects it touches at load, and runs `sortedRows()` on given rows and sort settings.
- `tests/Feature/OwnerPrivate/ItemSortTest.php` (new): renders /owner/private as CEO and checks, through the runner:
  - Item asc: `3XL Shirt`, `AB ROLLER`, `1 x hand grip`, `1 x HAND GRIP`, `2 x HAND GRIP`, `1 x YOGA MAT`, `2 x Yoga Mat`, `10 x Yoga Mat`; desc is the reverse with equal rows kept in current order. `3XL Shirt` sorts as its full name, not as count 3 of "L Shirt".
  - Page (text) and Orders (number) sort as before; Page still compares the full text, count prefix included.
  - The item cell still renders `x-text="sq(row.item_name)"`.

Edge case: the spec's first regex (`x\s*`) would have read "3XL Shirt" as count 3 of "L Shirt". Per review, a space after the x is now required, and the test covers it.

## Test output

```
vendor/bin/phpunit tests/Feature/OwnerPrivate/ItemSortTest.php
OK (3 tests, 10 assertions)
```
Re-run after the whitespace fix: same result. On the base view (fix stashed) the Item-order test fails, the other two pass.

```
vendor/bin/phpunit tests/Feature/OwnerPrivate
Tests: 265, Assertions: 2153, Failures: 1
1) SharedPagesBasePinTest::test_S_42_4_... with data set "/owner/private"
```
`SharedPagesBasePinTest` fails the same way on base (the /owner/private byte-for-byte pin, same data set); everything else passes. `php artisan test` adds a `file_get_contents(.env)` warning per test in this worktree (no .env), unrelated.

## Merge danger

Low. Client-side sort only, two-way door: revert the commit to go back to full-text sorting. No data is touched.
