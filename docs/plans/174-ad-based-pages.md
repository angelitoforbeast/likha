# Plan 174: Page-based or Ad-based reply values, expanding rows, ad status

Spec: `docs/specs/174-ad-based-pages.md`. Builds on 172 (`AdGroups`, `ChatbotAdGroupController`, `public/js/chatbot-adgroups.js`).

## Threat model

- Untrusted: every request body and query on `/chatbot/ad-groups*` (page name, group id, ad IDs text, values, setting id),
  and stored text that came from users or the web (group names, values, ad names from ads_manager_reports uploads,
  chat payload ad_id). All of it is shown with `textContent` only.
- Trusted: config, migrations, the owner's and the reviewer's inputs.

## Design

**Switch storage.** `page_settings` row, provider `adgroup`, key `mode`, value `ad`. Absent (or inactive) = Page-based.
Switching to Page-based deactivates the row (history: `replaced_at`, `replaced_by`), so "absent = page-based" stays
literally true. Written only by `AdGroups::setMode` as a system write (`PageSettings::validate` already refuses
provider `adgroup` from other writers). `groups()` parses only `gN.*` keys, so `mode` never looks like a group.

**Resolution gate.** One method, `AdGroups::adBased($page)` (from the cached page settings, no extra query).
- `forCustomer` and `forSequence` return null (page values) when the page is Page-based.
- `pin` writes nothing on a Page-based page.
- `ChatbotPage::runs` only looks at group flows on an Ad-based page.
- Everything else in 172 is unchanged; groups are kept when a page goes back to Page-based.
- The /chatbot Test tab keeps its explicit "Ad group" pick (a test chooses a group on purpose).

**Ad status** (`AdGroups::statuses(array $ads)`), cached 300 s per sorted ad list. Two grouped queries on
`ads_manager_reports`, both filtered by `ad_id IN (...)` (index `ads_report_adid_idx`):
1. per ad: `MIN(day)`, `MAX(day)`, `MAX(day) where amount_spent_php > 0`, `MIN(day) where not both deliveries active`.
2. the rows of each ad on its latest day (join on ad_id + day = the max from 1): both deliveries active and spend.

Rules: On = latest day both `active%` (the same `LOWER(TRIM(..)) LIKE 'active%'` test the Ads Manager report uses) or
spend > 0 that day. Off since = day after the last spend day, or the first non-active day when it never spent; N days
= as-of day − off-since + 1, where as-of = the latest report day among the page's ads. New = first report day within
the last 3 days; when the ad has no report data, its first customer in the 30-day window within the last 3 days.
No report data = not in ads_manager_reports.

**Server routes** (same access `NavAccess /chatblast`, same throttle buckets as 172):
- `GET /chatbot/ad-groups`: adds `mode`, `as_of`, per group `ads` as objects (name, status, customers, last, new),
  `all_off`, unassigned rows with status sorted New → On → customers, `new_unassigned`.
- `POST /chatbot/ad-groups/mode` {page, mode: page|ad}.
- `POST /chatbot/ad-groups/value` {page, id, key, value}: one group cell, through the same lock and checks as Save.
- `POST /chatbot/ad-groups/remove-ad` {page, id, ad}.
- `POST /chatbot/ad-groups/add`: `ads` may also be typed text (comma, space, newline); bad tokens refused.
- `/chatbot/data` rows get `reply` (`page`|`ad`) and, for Ad-based pages, `ad_new` (New ads not in a group).

**Screen.** Each page row shows "Replies: Page-based / Ad-based" (select, confirm before switching). An Ad-based page
gets an expand button with the group count and a "N new" badge. Expanded, under the page row:
a header line (as of, N new not in a group), **Default** (the page's cells, editable as in the page row), one row per
group (name, History, Delete; group-settable cells editable inline through the long-text editor, empty cells show the
default muted with "uses default", page-only cells show "page value", group FLOW as a select), each followed by its ad
list (name, status, customers, last time, Remove; "Show all" past 5; type/paste to add), then **Add ad group** and
**Ads not in a group yet** (search, tick, add to a group or a new one). The Edit window's Ad groups section is replaced
by a pointer to the row (one place to edit). The sticky first column holds the group names; the full-width rows keep
their content in a block pinned to the left edge, so at 390 px they read without sideways scrolling.

## Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | Switch: storage, `adBased` gate in resolution, `/ad-groups/mode`, `reply` in `/chatbot/data`; 172 tests switch Page P to Ad-based | backend | high (auth on a new write route) | S-174.1, .2, .9 |
| 2 | Ad status queries and rules; group ads as objects; unassigned with status, sort, `new_unassigned`, `ad_new` | backend | medium | S-174.4 (data), .6, .7 |
| 3 | Group cell write (`/value`), remove ad, typed add; history and Restore through the 172 endpoints | backend | high (new write routes) | S-174.3 (server), .4 (remove), .5 |
| 4 | Expanding rows, Reply values select, badge, ad lists, add flows; Edit window pointer | frontend | medium | S-174.3 (screen), .8, .10 |
| 5 | Full suites vs the baseline, 390 px check, skeptic review, result | both | — | S-174.9, .10 |

Tasks share `AdGroups.php` and the controller, so they run in order, one commit each.
