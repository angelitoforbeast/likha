# Spec 250 result: the CPP page shows when ads spend and orders were last updated

Status: done on branch `feat/250-cpp-last-updated` (from `d02ed7a1`). Code head: `75ff7906`. This result is committed on top of it.

## Applied changes from amendment 250-2

- The order times no longer use a separate grouped query on `macro_output`. The existing orders query in
  `buildData` (mysql and pgsql branches) also selects `TIMESTAMP` when freshness is on. The latest order time per
  (date, page) is computed in PHP from those rows, the same way as for the non-SQL fallback.
- The `ads_manager_reports` grouped query is unchanged.
- New tests check this:
  - On the sqlite page, exactly one query touches `macro_output`.
  - For mysql and pgsql, `buildData` runs under `pretend()` on a connection of that driver. Exactly one
    `macro_output` query is logged. It has no `MAX(` and selects `TIMESTAMP` as a column.
  - Before the fix, both driver cases failed with 2 `macro_output` queries.

## Applied changes from amendment 250-1

- "The JSON" is the data the page embeds from `CPPController::index`. The times are one new embedded variable,
  `const freshness = @json($freshness)`, built in `buildData`. Originally this was one grouped MAX query per source per (date, page); the orders side is
  now replaced by amendment 250-2.
  The existing variables (`rawData`, `allDates`, `srvStart`, `srvEnd`, `adGroupRows`) and the matrix cell keys are unchanged.
- The line is computed in the browser inside `refreshAll()`, so it follows the page picker and the item filter.
  Date changes already reload the page.
- The tests check the view data (`viewData('freshness')`) and the rendered page instead of JSON. The line and tooltip
  logic is run in Node, taken from the rendered page.
- The broken `/ads_manager/cpp/data` route is left as it is (see "Found, not fixed").

## What changed

- `docs/specs/250-cpp-last-updated.md`: the spec (`4225bf01`).
- `tests/Feature/CPP/CppFreshnessTest.php`: tests first (`7e02c8ee`). Two assertion strings were aligned with the
  final variable names in `a5e200f9`. The amendment 250-2 tests are in `889378fe`.
- `app/Http/Controllers/CPPController.php` (`a5e200f9`, order times reworked in `75ff7906`):
  - `buildData()` has a new flag `$withFreshness`. Only `index` sets it. The snapshot's call is unchanged and runs no
    extra query. It returns a 4th element, `freshness`: page => date => `{ads, order}`, each `'Y-m-d H:i'` or null.
    The (date, page) keys and page names are the same as in the matrix.
  - New `freshnessByKey()`:
    - ads: `select day, page_name, MAX(updated_at) ... where day between ? and ? group by day, page_name`. Page names
      are normalised the same way as the matrix, and the later time wins. This runs only when
      `ads_manager_reports.updated_at` exists. Production has it from `timestamps()` in the create migration.
      Some older test schemas lack it; for them the ads time is null instead of an error.
    - orders: there is no query. The latest `TIMESTAMP` (parsed with `H:i d-m-Y`, trimmed, unparsable values
      skipped) comes from the order rows `buildData` already loaded. With freshness on, the mysql and pgsql branches
      of the existing orders query also select `TIMESTAMP`. The fallback branch already selected it. The snapshot's
      call (freshness off) runs exactly the same orders query as before.
  - `index` passes `freshness` to the view.
- `resources/views/ads_manager/cpp.blade.php` (`a5e200f9`):
  - A new `<div id="cppFreshness" class="text-xs text-gray-500 mb-2">` sits above the display area. It is outside
    `#rightTableContainer` and `#multiPageTables`, so neither Copy Table nor the snapshot sees it.
  - `const freshness = @json($freshness ?? [])`.
  - A block marked `// cpp-freshness:begin` … `// cpp-freshness:end` holds `cppFreshStamp`, `cppFreshLatest`,
    `cppFreshLine` and `cppFreshTip`. They are pure functions that reformat the server strings without `Date`
    parsing, so the viewer's time zone cannot shift them. The 60-minute threshold is a constant.
  - `renderFreshness()` sets the line with `textContent` and switches the class between `text-gray-500` and
    `text-amber-700`.
  - `refreshAll()` fills the line before its "no data" early return, so the line also shows when the table is empty.
    It covers:
    - the selected pages (all when none are picked);
    - narrowed by the item filter with the same rule the summary table uses (a page stays when it has that item on a
      shown date);
    - over the whole selected range.

    The times stay page-level, not per item. An order of another item on the same page can still be the
    "last order". Splitting by item would need per-item times, which is out of scope.
  - Tooltips are `title` attributes on the Amount Spent and Orders cells, escaped with the page's existing `esc()`:
    - summary page rows: that page, whole range;
    - ad-group sub-rows: reuse their page's times;
    - per-date rows of the one-page table (`#singlePageTable`): that page and that date.

    The TOTAL rows and the all-pages "Performance by Date" table have no tooltip. Copy Table reads `textContent`,
    so the attributes are not copied.
- No migration, no new table, no new index. `git diff d02ed7a1 --stat` lists only the controller, the view, the
  test and the two docs.

## S3: how the times are stored and shown

- `ads_manager_reports.updated_at` is a `timestamps()` column. The upload job and the Meta sync (which uses the
  same importer) write `now()->toDateTimeString()` (`app/Jobs/ProcessAdsManagerReportsUpload.php:612`).
  `config/app.php` has `timezone => Asia/Manila`, so the value written is Manila wall-clock time.
  `config/database.php` sets no MySQL session time zone. Even if the column is a MySQL TIMESTAMP and the session is
  UTC, the same session converts on write and on read. The value read back is therefore the Manila wall-clock
  string that was written. It is used as read: no `CONVERT_TZ`, no +8 h.
- `macro_output.TIMESTAMP` is a `'H:i d-m-Y'` string of Manila wall-clock time. It is parsed with the format only,
  with no zone.
- Both reach the page as `'Y-m-d H:i'` strings. The browser only reformats the text to `Nov 9, 6:45 PM`, always with
  the date. The lag is computed from the two strings on a common clock: `Date.UTC` is used only as arithmetic.

## Tests

`tests/Feature/CPP/CppFreshnessTest.php`, 11 tests. Before the first code commit, 7 of the first 8 failed: no
`freshness` view data, no block on the page. The snapshot test passed.

- S1 happy: ads written 18:45:12, orders 09:30, 10:12 and 21:58 on Nov 9. The view data is
  `{ads: '2026-11-09 18:45', order: '2026-11-09 21:58'}`. The line starts with
  `Ads spend as of Nov 9, 6:45 PM · Last order Nov 9, 9:58 PM`. Because that gap is 3 h 13 min, the full line is
  amber and adds `(ads spend is 3 h 13 min behind orders; CPP may look low)`.
- Lag warning:
  - exactly 60 min: grey, no note;
  - 61 min: amber, `1 h 1 min`;
  - orders older than the ads time: grey.
- Empty:
  - ads only: `… · Last order: none`;
  - orders only: `Ads spend: none for this range · …`;
  - neither: both none.

  None of these warn. A range with no rows embeds `[]`.
- Multi-day, Nov 7 to 9:
  - per-day view data; a page name with other spacing merges, and the later time wins;
  - rows before or after the range are left out;
  - the all-pages line is `Ads spend as of Nov 8, 3:02 PM · Last order Nov 9, 8:15 AM (… 17 h 13 min …)`;
  - with one picked page, the line shows only that page's times.
- S2:
  - page tooltip over the range;
  - per-date tooltips (Nov 8 with an order, Nov 9 with `Last order none`);
  - an ads-only page: `Ads spend updated Nov 9, 6:45 PM · Last order none`;
  - an orders-only page: `Ads spend updated none · Last order Nov 9, 9:58 PM`.
- Page markup:
  - the line element sits before the tables and is grey by default;
  - `textContent` fills it;
  - there are 4 `title="${freshTip}"` (page and ad-group rows) and 2 `title="${dayTip}"`, all through `esc()`;
  - the amber class exists;
  - the matrix cell keys are exactly the old 10.
- Snapshot: the Copy Table snapshot still saves the page with its orders.
- No extra orders query (amendment 250-2), 3 tests:
  - the sqlite page reads `macro_output` once;
  - the mysql and pgsql `buildData` runs under `pretend()` each log one `macro_output` query. That query has no
    `MAX(` and has `TIMESTAMP` in its select list.

Targeted runs at `75ff7906` (shim bootstrap):
- `tests/Feature/CPP`: OK, 33 tests, 191 assertions.
- `tests/Feature/MetaAds`: OK, 145 tests, 644 assertions.

Full suite (shim bootstrap):

| Commit | Tests | Assertions | Failed | Skipped |
|---|---|---|---|---|
| `0876b0cd` (code at `d02ed7a1`, from the spec 249 result) | 4815 | 78786 | 2 | 6 |
| `a5e200f9` | 4823 | 78850 | 2 | 6 |
| `75ff7906` (head) | 4826 | 78859 | 2 | 6 |

`d02ed7a1` differs from `0876b0cd` only by a docs file. The 2 failures are the known ones at both: `ExampleTest::test_the_application_returns_a_successful_response` and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`. The +8 tests at `a5e200f9` and the +3 at `75ff7906` are the new file. A first head run with `php -d memory_limit=-1` showed 5 `SpeedPrewarmTest` errors (`ChatbotSpeedWarm` cannot lower the memory limit once the process is above 512 MB) and one timing failure in `BigBroadcastTest`. Both came from that flag; the run in the table uses no flag, like the earlier results.

## Rendering check

There is no local `.env` or database for the app here, so the page was not served in a browser. Instead:
- the page was rendered in a test (CEO user, sqlite) with these rows:
  - `Mobile Tripod`: ads written 18:45:12, order 21:58;
  - `Bag <b>`: ads only;
- the rendered page's own `freshness` line, `esc` and freshness block were run in Node on a stub element.

Output:

```html
<div id="cppFreshness" class="text-xs mb-2 text-amber-700" aria-live="polite">Ads spend as of Nov 9, 6:45 PM · Last order Nov 9, 9:58 PM (ads spend is 3 h 13 min behind orders; CPP may look low)</div>
<td class="border px-2 py-1" title="Ads spend updated Nov 9, 6:45 PM · Last order Nov 9, 9:58 PM">₱500.00</td>
<td class="border px-2 py-1" title="Ads spend updated Nov 9, 6:45 PM · Last order none">₱500.00</td>
```

The embedded data line as rendered. `@json` escapes `<` and `>` in the page name:

```js
const freshness = {"Mobile Tripod":{"2026-11-09":{"ads":"2026-11-09 18:45","order":"2026-11-09 21:58"}},"Bag \u003Cb\u003E":{"2026-11-09":{"ads":"2026-11-09 18:45","order":null}}};
```

MySQL SQL that the page runs on `ads_manager_reports` and `macro_output` for 2026-11-09, at `75ff7906`. It was
generated with `buildData` under `pretend()` and has not been run on MySQL. The `information_schema` lookup of
`hasColumn` is left out.

```sql
-- existing spend query, unchanged
select `day`, `page_name`, `amount_spent_php`, `messaging_conversations_started`, `impressions` from `ads_manager_reports` where `day` between '2026-11-09' and '2026-11-09'
-- existing orders query: only `TIMESTAMP` is added at the end of the select list
select DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) AS ts_date, `PAGE`, `ITEM_NAME`, `COD`, `STATUS`, `TIMESTAMP` from `macro_output` where DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) BETWEEN '2026-11-09' AND '2026-11-09'
-- new: last write of the spend rows
select `day`, `page_name`, MAX(updated_at) AS ads_at from `ads_manager_reports` where `day` between '2026-11-09' and '2026-11-09' group by `day`, `page_name`
```

Speed: the only new query is the `ads_manager_reports` grouped MAX, measured at about 10 ms by the reviewer on
production. The orders side adds no query: it adds one short string column to the rows the existing orders query
(1.2 s) already returns. The first version's grouped `macro_output` query took 18 s on production; it is gone. No
index was added.

## Found, not fixed

- `routes/web.php:911` maps `GET /ads_manager/cpp/data` to `CPPController::data`, which does not exist (nor in the
  file's history). Calling it errors. The page does not use it. Left as is.

## Could not do / open

- No browser check against a running app, and the SQL was not run against MySQL. The local MySQL needs credentials
  that are not in the worktree. Before release, a read-only run of the orders statement above with the added
  `TIMESTAMP` column on production (or opening the page) would confirm it.
- Item filter: the line follows it only at page level (see the view section).
- Not pushed to any deploy remote; nothing merged or deployed.
