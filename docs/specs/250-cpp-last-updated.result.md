# Spec 250 result: the CPP page shows when ads spend and orders were last updated

Status: done on branch `feat/250-cpp-last-updated` (from `d02ed7a1`). Code head: `a5e200f9`. This result is committed on top of it.

## Applied changes from amendment 250-1

- "The JSON" is the data the page embeds from `CPPController::index`. The times are one new embedded variable,
  `const freshness = @json($freshness)`, built in `buildData` with one grouped MAX query per source per (date, page).
  The existing variables (`rawData`, `allDates`, `srvStart`, `srvEnd`, `adGroupRows`) and the matrix cell keys are unchanged.
- The line is computed in the browser inside `refreshAll()`, so it follows the page picker and the item filter.
  Date changes already reload the page.
- The tests check the view data (`viewData('freshness')`) and the rendered page instead of JSON. The line and tooltip
  logic is run in Node, taken from the rendered page.
- The broken `/ads_manager/cpp/data` route is left as it is (see "Found, not fixed").

## What changed

- `docs/specs/250-cpp-last-updated.md`: the spec (`4225bf01`).
- `tests/Feature/CPP/CppFreshnessTest.php`: tests first (`7e02c8ee`). Two assertion strings were aligned with the
  final variable names in `a5e200f9`.
- `app/Http/Controllers/CPPController.php` (`a5e200f9`):
  - `buildData()` has a new flag `$withFreshness`. Only `index` sets it. The snapshot's call is unchanged and runs no
    extra query. It returns a 4th element, `freshness`: page => date => `{ads, order}`, each `'Y-m-d H:i'` or null.
    The (date, page) keys and page names are the same as in the matrix.
  - New `freshnessByKey()`:
    - ads: `select day, page_name, MAX(updated_at) ... where day between ? and ? group by day, page_name`. Page names
      are normalised the same way as the matrix, and the later time wins. This runs only when
      `ads_manager_reports.updated_at` exists. Production has it from `timestamps()` in the create migration.
      Some older test schemas lack it; for them the ads time is null instead of an error.
    - orders on MySQL: `DATE_FORMAT(MAX(STR_TO_DATE(TIMESTAMP,'%H:%i %d-%m-%Y')),'%Y-%m-%d %H:%i')`, grouped by
      `DATE(STR_TO_DATE(...))` and `PAGE`, with the same day filter as the existing orders query.
    - orders on pgsql: the `to_timestamp` / `to_char` equivalent.
    - orders on other drivers (the test suite's sqlite): computed from the order rows the existing fallback already
      loads, parsing `TIMESTAMP` with `H:i d-m-Y`. This follows the controller's existing driver branches.
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

`tests/Feature/CPP/CppFreshnessTest.php`, 8 tests. Before the code commit, 7 failed: no `freshness` view data, no
block on the page. The snapshot test passed.

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

Targeted runs at `a5e200f9` (shim bootstrap):
- `tests/Feature/CPP`: OK, 30 tests, 182 assertions.
- `tests/Feature/MetaAds`: OK, 145 tests, 644 assertions.

Full suite (shim bootstrap):

| Commit | Tests | Assertions | Failed | Skipped |
|---|---|---|---|---|
| `0876b0cd` (code at `d02ed7a1`, from the spec 249 result) | 4815 | 78786 | 2 | 6 |
| `a5e200f9` (head) | 4823 | 78850 | 2 | 6 |

`d02ed7a1` differs from `0876b0cd` only by a docs file. The 2 failures are the known ones at both: `ExampleTest::test_the_application_returns_a_successful_response` and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`. The +8 tests are the new file. A first head run with `php -d memory_limit=-1` showed 5 `SpeedPrewarmTest` errors (`ChatbotSpeedWarm` cannot lower the memory limit once the process is above 512 MB) and one timing failure in `BigBroadcastTest`. Both came from that flag; the run in the table uses no flag, like the earlier results.

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
const freshness = {"Mobile Tripod":{"2026-11-09":{"ads":"2026-11-09 18:45","order":"2026-11-09 21:58"}},"Bag <b>":{"2026-11-09":{"ads":"2026-11-09 18:45","order":null}}};
```

MySQL SQL of the two new queries for 2026-11-09. It was generated with `pretend()` and has not been run on MySQL:

```sql
select `day`, `page_name`, MAX(updated_at) AS ads_at from `ads_manager_reports` where `day` between '2026-11-09' and '2026-11-09' group by `day`, `page_name`

select DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) AS ts_date, `PAGE`, DATE_FORMAT(MAX(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')), '%Y-%m-%d %H:%i') AS last_at from `macro_output` where DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) BETWEEN '2026-11-09' AND '2026-11-09' group by DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')), `PAGE`
```

Speed: each source runs one grouped query with the same `day` / `TIMESTAMP` filters as the existing CPP queries.
The orders query scans what the existing orders query already scans: it is non-sargable on `TIMESTAMP`, like the
existing one. There was no measurement against production data; no index was added.

## Found, not fixed

- `routes/web.php:911` maps `GET /ads_manager/cpp/data` to `CPPController::data`, which does not exist (nor in the
  file's history). Calling it errors. The page does not use it. Left as is.

## Could not do / open

- No browser check against a running app, and the MySQL queries were not run against MySQL. The local MySQL needs
  credentials that are not in the worktree. Before release, a read-only run of the two SQL statements above on
  production (or opening the page) would confirm them.
- Item filter: the line follows it only at page level (see the view section).
- Not pushed to any deploy remote; nothing merged or deployed.
