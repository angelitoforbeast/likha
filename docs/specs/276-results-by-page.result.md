# 276 Chatbot Results: By page — result

Branch `feat/276-results-by-page` (from `065f58cf`). Not pushed, not deployed.

## What changed
- `app/Services/Chatbot/ChatbotResults.php`
  - New `byPage()`, called in `build()` right after `by_via`, so the cached report and `results.json` carry `by_page` = `{rows: [...], total: {...}}`.
  - It uses the same filters as Funnel: `sends($f, …, true)` for the sends (date range, page, flow, version, via) and the `$flowOrders` collection for the orders (via, flow, version). That way the per-page numbers add up to Funnel exactly.
  - The sends part is one grouped query: `GROUP BY page_id` with `COUNT(*)`, `COUNT(DISTINCT psid)` and `SUM(CASE WHEN replied_at IS NOT NULL …)`. The orders are grouped in PHP. Delivered comes from the `$delivery` map that `build()` already computes. Page names come from `BotcakeChat::pageMap()` (already read and cached by `build()`), falling back to the id.
  - Main flow vs after sequence: an order counts as "after sequence" when its `detail` has both `sequence_id` and `sequence_step`. This is the same test By sequence step uses. Every other order counts as main flow.
  - Each row has: `page_id`, `page`, `sent`, `customers_sent`, `replied`, `replied_pct`, `orders`, `orders_pct`, `main_flow`, `main_flow_pct`, `after_sequence`, `after_sequence_pct`, `delivered`, `delivered_pct`. Rows are sorted by orders, then sent (highest first), then page name. `total` has the same fields.
  - The report cache key changed from `chatbot-results:` to `chatbot-results:v2:`. Without this, a cached report from before the deploy (up to 5 minutes old) has no `by_page` and the page would break.
- `resources/views/chatbot/results.blade.php`: a new **By page** table after By bot, styled like the other tables (`cb-card`/`cb-table`). Each page name is a link that sets `page_id` and keeps the other filters. A Total row sits in `tfoot`.
- Tests
  - New `tests/Feature/Chatblast/ChatbotResultsByPageTest.php` (5 tests) covers:
    - per-page numbers for two pages, plus a page that has orders but no sends (shown by its id);
    - the main flow vs after-sequence split;
    - the total row equals Funnel, and the row sums equal Funnel, with no filter, `via` botcake, `via` forbeast, `flow_id` and `page_id`;
    - the `page_id` filter gives one row;
    - the `via` and `flow_id` filters apply;
    - the query count;
    - `results.json` has the `by_page` key in its place;
    - the HTML shows the section between By bot and By message, with the page link that keeps the filters.
  - `ChatbotResultsTest::test_S_100_12` now expects `by_page` in the JSON key list.

## Query count
The By page section adds **1 query**, the grouped sends query. `test_S_276_4_the_sends_part_is_one_grouped_query` asserts this: it calls `byPage()` with the query log on and expects exactly one query, containing `group by`.

## Tests
- `vendor/bin/phpunit --filter ChatbotResults`: before, 16 tests and 48 assertions, OK. After, 21 tests and 97 assertions, OK.
- Full suite, base `065f58cf` run in a clean separate checkout: 5230 tests, 81772 assertions, 3 failures, 6 skipped.
- Full suite on this branch: 5235 tests, 81821 assertions, 3 failures, 6 skipped.
- The 3 failures are the same in both runs, the known ones:
  - `ExampleTest::test_the_application_returns_a_successful_response`
  - `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
  - `SharedPagesBasePinTest::test_S_42_4… "/owner/private"`
- No new failures.

## Rendered table (test data, 2026-10-14 to 10-20, no filters)
Rendered HTML of the section, whitespace trimmed:

```html
<section aria-labelledby="h-page">
  <h2 id="h-page" class="mb-2 text-base font-semibold">By page</h2>
  <div class="cb-card overflow-x-auto">
    <table class="cb-table min-w-[56rem]">
      <thead><tr><th>Page</th><th class="!text-right">Sent</th><th class="!text-right">Customers</th><th class="!text-right">Replied</th><th class="!text-right">Orders</th><th class="!text-right">Order rate</th><th class="!text-right">Main flow</th><th class="!text-right">After sequence</th><th class="!text-right">Delivered</th></tr></thead>
      <tbody>
        <tr><td><a href="http://localhost/chatbot/results?page_id=1001&amp;from=2026-10-14&amp;to=2026-10-20" class="text-blue-700 hover:underline">Page Uno</a></td>
          <td>4</td><td>3</td><td>50%</td><td>3</td><td>100%</td><td>2 · 66.7%</td><td>1 · 33.3%</td><td>33.3%</td></tr>
        <tr><td><a href="http://localhost/chatbot/results?page_id=1002&amp;from=2026-10-14&amp;to=2026-10-20" class="text-blue-700 hover:underline">Page Dos</a></td>
          <td>2</td><td>2</td><td>50%</td><td>1</td><td>50%</td><td>0 · 0%</td><td>1 · 100%</td><td>0%</td></tr>
        <tr><td><a href="http://localhost/chatbot/results?page_id=1009&amp;from=2026-10-14&amp;to=2026-10-20" class="text-blue-700 hover:underline">1009</a></td>
          <td>0</td><td>0</td><td>—</td><td>1</td><td>—</td><td>1 · 100%</td><td>0 · 0%</td><td>0%</td></tr>
      </tbody>
      <tfoot><tr class="font-semibold"><td>Total</td>
          <td>6</td><td>5</td><td>50%</td><td>5</td><td>100%</td><td>3 · 60%</td><td>2 · 40%</td><td>20%</td></tr></tfoot>
    </table>
  </div>
</section>
```

(The number cells carry `class="text-right tabular-nums"`, left out above to keep it short.)

## Notes / unsure
- `by_page` is an object `{rows, total}`, not a plain list like the other sections, so that the totals row sits next to the rows in the JSON.
- A page with orders but no bot sends in the range still gets a row. Its Replied % and Order rate show "—" because nothing was sent. Its orders still count toward the total, which keeps the total equal to Funnel.
- No sortable columns (optional in the spec); sorting is fixed by orders.
- The `chatbot:results` command with `--json` prints the same JSON, so `by_page` shows up there too. Without `--json` it still prints only the funnel. The command itself is unchanged.
