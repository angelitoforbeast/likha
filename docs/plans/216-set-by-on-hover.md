# Plan: spec 216 who set a value and when, on hover; AI usage only for Forbeast pages

Risk tier: medium (owner money screen with CEO-only data; AI billing path). Threat model as in the spec:
untrusted `view_as` and dates (already validated by `itemSummary`), trusted database and config. The one
medium risk is leaking the CEO value's setter, time or amount to a non-CEO viewer, so the CEO fields are
gated in the data layer exactly like `item_value_ceo` (`$isCEO && $viewAs === 'ceo'`), never in the view.

No new table, column, route or migration.

## Design

### A. Who set the value, and when (`/owner/private/item-summary` + `owner/private.blade.php`)

- **RTS.** `rtsSetByMap()` also keeps the `created_at` of the log row that started the value's run. It
  returns `name` and `at` per key, and the row gets `rts_set_at` beside `rts_set_by` (same key, same rule).
- **Item Value (COGS).** A new `cogsSetByMap()` runs once per data load, after the cogs and cogs_ceo maps:
  - The wanted keys are the shown values: canonical item → date of the latest `cogs` row ≤ end date
    (`$cogsLastDateMap`, already there), and for the CEO view canonical item → date of the latest `cogs_ceo`
    row (a new `$cogsCeoLastDateMap` filled in the same loop as `$cogsCeoMap`).
  - Queries: one `Schema::getColumnListing('page_item_settings_log')` (for the optional
    `new_item_value_ceo`, `source`, `scope` columns), and one select of the log rows whose `effective_date` is
    one of the wanted dates and that set a value (`new_item_value` or `new_item_value_ceo` not null), joined
    to `users` and `employee_profiles` as the Edit Audit Logs screen does, ordered by id. Nothing per row.
    No wanted dates = no select.
  - Matching in PHP: the log row's item resolves with `ItemAliasResolver::canonicalKey` (as `rtsSetByMap`),
    the date equals the wanted date, and the latest matching id wins. The setter is named only when that
    row's new value equals the value shown (2 decimals); otherwise (a value from an apply-through cascade,
    a mirror or anything written before logging) there is no "Set by", as today.
  - CEO side: rows with `new_item_value_ceo` not null (this covers saves logged as scope `cogs_ceo` and the
    `cogs`/`all` saves that carried a CEO value). Built only when the viewer is CEO and `view_as` is `ceo`.
  - Who: `COALESCE(ep.name, u.name)`, else the email; no email and source `cogs_sync` = "system (cogs sync)",
    no email otherwise = "system".
  - When: `created_at` shown in Manila time as `M j, Y g:i A` (e.g. "Nov 2, 2026 3:05 PM"), formatted on
    the server so the browser's zone never changes it.
  - Best effort like `rtsSetByMap`: any error gives no "Set by", never a broken page.
- **Row fields:** `rts_set_at`, `item_value_set_by`, `item_value_set_at`, and `item_value_ceo_set_by`,
  `item_value_ceo_set_at` (null unless CEO viewer in the CEO view).
- **Hover text** (`owTip`, the one place the lines are built):
  - `rts_set`: `Set by U · T` (or `Set by U` when there is no time).
  - `item_val`: the source line as today (`cogs`), then `Set by U · T`, then in the CEO view
    `🔒 CEO value set by U · T`.
  - Compact mode keeps using `owTip` through `data-ow-tip`; normal mode gets `:title="!owCompact ? owTip(…) :
    null"` on the RTS and Item Value cell content, the pattern the Mira RTS cell already uses.

### B. AI usage only for pages served through a gateway (`ChatbotAiUsage::gatewayOf`)

- `gatewayOf($page)` returns null unless `ChatbotProvider::name($page) === ChatbotProvider::GATEWAY`, the
  check `ChatbotProvider::for()` uses to pick the gateway or Botcake for a send. Its callers already treat null
  as "no gateway": `ChatbotAiQueue::billOf` (row gets `no_gateway`, cost kept), `ChatbotAiLoad::state`, `known`
  and `shown` (no load question, AI runs). The decision is made when the row is written, so rows written before
  a move keep their status (S-216.2.4).
- None of spec 215's files is touched (`ChatbotAiUsage.php` only; 215 changes the send path, not billing).
- Spec 210's tests used Botcake pages to stand for billed pages. Those pages now go on the gateway in the
  test (a helper in `AiKeyPoolTestCase`), with the fake answering the gateway send and `sent()` reading
  both paths, so the 210 cases keep testing what they tested.

## Tasks

| # | Task | Cases | Side | Tier | Files |
|---|------|-------|------|------|-------|
| 1 | Usage and load only for gateway pages | S-216.2.1 – 2.4 | backend | medium | `app/Services/Chatbot/ChatbotAiUsage.php`, `tests/Feature/Chatblast/AiUsageGatewayPagesTest.php` (new), `tests/Feature/Chatblast/AiKeyPoolTestCase.php`, the 210 tests that need a gateway page |
| 2 | Who/when in the item-summary rows | S-216.1.1, 1.2, 1.3, 1.5 – 1.10 | backend | medium | `app/Http/Controllers/OwnerPrivateController.php`, `tests/Feature/OwnerPrivate/SetByHoverTest.php` (new), `tests/Feature/OwnerPrivate/RtsSetByMapTest.php` (map shape) |
| 3 | Hover lines in compact and normal mode | S-216.1.4 (and the lines of 1.1, 1.2, 1.3, 1.5) | frontend | medium | `resources/views/owner/private.blade.php`, `tests/Feature/OwnerPrivate/SetByHoverTest.php` |
| 4 | Stories, review, full suite, result | all | both | – | `qa/stories.md`, the result |

Tasks 1 and 2 share no files (parallel-safe), 3 follows 2. The job is small, so the main session builds the
tasks in order, test first, with one `skeptic-reviewer` pass (standard depth) on the whole diff before the
result.

## Checks

- One test per case, named with its case ID; red watched before the code, one red line per slice.
- S-216.1.10: a test counts the queries of one data load for 1 and 6 pages (same count, the lookup adds at
  most two); the time is measured locally before and after on a fixture and written in the result (a timing
  assertion in the suite would be flaky).
- Hover text of S-216.1.1, .2 and .5: `owTip` run in node on the JSON rows from a local data load, output in
  the result.
- Full suite once at the end, compared with the known baseline failures.
