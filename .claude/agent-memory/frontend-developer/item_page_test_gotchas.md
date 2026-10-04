---
name: item-page-test-gotchas
description: Non-obvious traps when writing markup tests for /item (ItemPageTest) and running artisan test on this Windows box
metadata:
  type: project
---

- `php.bat artisan test --filter "a|b"` breaks: the .bat wrapper treats `|` as a pipe. Run one filter name at a time.
- A failing `assertString(Not)ContainsString` on the full render dumps ~40 KB of HTML and Collision cuts off the needle. In loops over many needles use `assertFalse(str_contains($html, $s), "found: {$s}")` (or `assertTrue`) so the failing needle is printed.
- The page JS (and its Taglish `//` comments) is inline in index.blade.php, so it is part of the render. An "absent string" assertion can be broken by a comment or a JS method name (e.g. `saveQuote()`, `this.suppliersFor(G.item_name)`). Use markup-specific needles like `@click.stop="saveQuote()"` or `x-for="(s, si) in suppliersFor(G.item_name)"`.
- Some Old-view tests (004 era) pin chrome that both views share: `Walang category` (category filter option) and `Lagyan ng numero (0–255) ang lead at palugit.` (JS validation). Since 006 T1 they are branched on `layoutOld` (Blade `@if` / `this.layoutOld ?`), so the old view keeps the Taglish and the new view is in English. Don't "clean up" the branch unless those old tests are retired.
- Blade comments `{{-- --}}` don't render; the item views mention "x-html" in comments, so the no-x-html check must use the attribute regex `/\sx-html\s*=/`.
- ItemPageTest's `headers()` helper parses `<th ...>` with `[^>]*`, so a `>` inside a header attribute (e.g. `x-show="n > 0"`) breaks it. Use a truthy expression instead.
- Card mode (<1,100 px) hides `.il-table thead` with a class rule. Any `#il-table-…` id rule that sets thead `position` wins on specificity; wrap it in `@media (min-width: 1100px)`.
- `_table_old.blade.php` is pinned by the sha1 of its LF-normalised content in ItemPageTest (value from d9606c8). Any edit there fails the test by design.

- Blade `{{-- --}}` markers don't reach the render; to slice a block out of a page in a test use an HTML comment (`<!-- claude-cells-start -->`) or a JS needle. The server-injected column config (`hidden`/`order` JSON) puts column ids like `claude_action` in the source even for non-CEO, so "absent" checks must target the label and `row.claude_` / `case 'claude_` code, not the bare id.
- `/owner/private`, its breakdown and `/item` all render in one test class on the OwnerPrivate base plus hand-made `tasks`, `ads_manager_reports`, `fee_settings` and the real `daily_page_primary_item` migration (see ClaudeActionViewTest).

**Why:** each of these cost a red/green cycle on 006 T1 (2026-10-02).
**How to apply:** check these before writing new /item markup assertions or moving shared chrome.
