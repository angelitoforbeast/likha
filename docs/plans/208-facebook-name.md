# Plan 208: the page's real Facebook name, and the shared page ID warning

Spec: `docs/specs/208-facebook-name.md`. Cases S-208.1 to S-208.8. Risk tier low-medium (warnings only).

## Design

- **Rows** (Page | Provider | Key | Value, no new table, no migration): `facebook.name` (the name as Facebook
  gives it, written only when a source answers) and `facebook.name_checked` (written on every attempt):
  `<Y-m-d H:i:s Manila> · <forbeast|incepxionbot|meta-ads|unknown> · <page ID>`. Both are system keys (not
  writable from the page settings grid).
- The page ID in `name_checked` ties the stored name to the ID it was read for: a lookup for a different ID
  first removes the old name (it belongs to another page), and the display ignores a name whose checked ID is
  not the row's current `botcake.page_id`.
- **New service `App\Services\Chatbot\FacebookName`:**
  - `remember(page, pageId, name, source)`: writes the two rows.
  - `lookup(page, skip = [], since = null)`: (a) each gateway with a token on the page (Forbeast, then
    IncepxionBot), `ForbeastPageSetup::probe` (no writes); (b) each stored Meta Ads connection (not expired, token
    readable), Graph `GET /{page-id}?fields=name`, one try each (no rate-limit retry), 10 s timeout, stop at the
    first name; (c) none: `name_checked` with `unknown`, the last known name kept. `skip` = gateways already probed
    in this run; `since` = a check already stored since then counts (the nightly run's gateway retest).
    Never the Meta app token. Page IDs that are not digits are not looked up.
  - `overview()`: two queries (active `botcake.page_id` rows, active `facebook.*` rows), no HTTP: per page key the
    known Facebook name for its ID, the last check, `differs` (compare via `PageSettings::pageKey`: trimmed,
    case-insensitive, collapsed spaces), `same_id` (other active rows with the same ID) and `match` (the row of the
    group whose name equals the Facebook name).
- **Where it is fetched (decision 3):**
  - Gateway Save and Test and the nightly retest: `ForbeastPageSetup::connect` / `retest` store the name the probe
    already returned (no extra call). Gateway Test that fails: `lookup(page, skip: [that gateway])`.
  - Botcake Save and test, Botcake Test, "+ Page" with an ID: `lookup(page)` after the existing work.
  - Nightly `chatbot:connections-check`: after each page's existing tests, `lookup(page, skip, since: run start)`,
    in its own try/catch; the page list also gets the gateway-only listed pages (page ID + gateway token), like
    `/chatbot/pages`. A count of names found / unknown / errors goes into the existing log line.
  - Never on `/chatbot/pages`, `/chatbot/data`, `/chatbot/connections` GET or the banner.
- **Graph:** `Graph::get` gets two optional trailing parameters (`$tries`, `$timeout`) so the lookup makes one
  short call per connection; existing callers unchanged.

## Display

- `/chatbot/data` rows get `facebook` (from `overview()`); the Check cell adds amber warnings from a pure JS helper
  `facebookLines` (marked `// facebook-lines:start/end` for node tests): "Facebook name: <name>" when it differs,
  "Same page ID as <rows>" with which row matches Facebook (or "Facebook name: unknown"). The frozen-cell summary
  counts them.
- Connections modal: `ChatbotConnections::info` gets `facebook`; a line under the title shows "Facebook name:
  <name>" / "Facebook name: unknown" (the FB link already sits beside the title) and the same warnings.
- Banner (`ProblemsPanel`): one orange line per shared ID group (the pair counts once), on the first row by name,
  naming the other row(s), the ID and which row matches Facebook; link to /chatbot/pages.
- "+ Page" with an ID already used: the existing "already used by X." plus " Facebook name: <name>." when known.

## Tasks

| # | Task | Cases | Tier | Side |
|---|---|---|---|---|
| 1 | `FacebookName` service (remember, lookup, overview), Graph params, system keys | .1 .2 .3 .4 | medium | backend |
| 2 | Hooks: gateway connect/retest, Botcake save/test, "+ Page", add-page error text | .1 .2 .3 .8 | medium | backend |
| 3 | Nightly run lookup | .7 | medium | backend |
| 4 | Data / connections JSON, banner line | .4 .5 .6 | low | backend |
| 5 | Check cell, modal line, summary count (JS helper, node tests) | .1 .5 .3 | low | frontend |
| 6 | Skeptic review of token handling and decision 3; result file | — | — | — |

Tests: `tests/Feature/Chatblast/FacebookNameTest.php`, one test per case named `test_S_208_N_…`, Http::fake for the
gateway and Graph, Http::preventStrayRequests from the base. Written first and seen failing.
