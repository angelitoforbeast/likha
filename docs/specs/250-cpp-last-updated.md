# 250: The CPP page shows when ads spend and orders were last updated

## Why
On /ads_manager/cpp the ads spend comes from the Meta Ads sync (scheduled times, by default 10:00, 15:00 and 18:45 Manila) or from manual uploads, while orders (macro_output) arrive all day. When ads spend is from 6:45 PM but orders run to 10 PM, the CPP looks lower than it is, and nobody can tell from the page. The owner asked to see when the ads spend and the orders were last updated, on hover or any better idea, and approved this design: one always-visible line above the table plus a hover per page.

## Stories
**S1 (P1) Freshness line.** As a viewer of /ads_manager/cpp, above the main table I see one line: `Ads spend as of <time> · Last order <time>` for the selected range.
- Happy: range 2026-11-09..2026-11-09; latest ads_manager_reports.updated_at for rows with day in range is 18:45 Manila, latest macro_output TIMESTAMP in range is "21:58 09-11-2026" → line reads `Ads spend as of Nov 9, 6:45 PM · Last order Nov 9, 9:58 PM`.
- Lag warning: when the last order is more than 60 minutes later than the ads-spend time, the line is amber and adds `(ads spend is N h M min behind orders; CPP may look low)`. 60 minutes or less: normal grey.
- Empty: no ads rows in range → `Ads spend: none for this range`; no orders → `Last order: none`. No warning when either is missing.
- Multi-day range: times are the latest across the whole range, each shown with its date.
- The line updates when the date range / filters reload the data (the page loads its data by fetch; the line must follow the same data, not the first page load only).

**S2 (P1) Hover per page.** Hovering the Amount Spent or Orders cell of a page row (main table, and the per-date rows of the page detail table) shows a native tooltip (title attribute is fine): `Ads spend updated <time> · Last order <time>` for that page (and that date, in the per-date table).
- Page with ads but no orders in range: `Ads spend updated <time> · Last order none`, and the reverse.
- Ad-group sub-rows (ad-based pages) may reuse the page's times; no new per-ad-group query is required.

**S3 (P2) Times are Manila.** All times display in Asia/Manila in 12-hour form ("6:45 PM", with "Nov 9," when it is not obvious from a one-day range is fine to always include). Verify how ads_manager_reports.updated_at and macro_output.TIMESTAMP are stored (the app stores Manila wall-clock time in app tables; DB session may be UTC; macro_output.TIMESTAMP is a string 'H:i d-m-Y') and do not shift them by 8 hours. State what you found in the result.

## Decisions already made (do not reopen)
| Decision | Why |
|---|---|
| Visible line + hover, not hover only | A lag between ads and orders changes how CPP should be read; it must be seen without hovering. |
| Ads "updated" time = MAX(ads_manager_reports.updated_at) for the rows in range (per page for hover) | That is when the spend row was last written by sync or upload. |
| Order time = latest macro_output TIMESTAMP in range (per page for hover) | That is the newest order counted on the page. |
| 60-minute threshold for the amber warning | The owner's ask is about stale spend versus live orders; an hour is the useful gap. |
| No migration, no new table, no new index | Small, deployable change; the existing day/TIMESTAMP filters already bound the queries. If a query is slow (> 300 ms on the 1-day range in tests is not measurable; just keep it to one grouped query per source), say so in the result instead of adding an index. |

## Constraints
- Reuse the existing data path of the CPP page (CPPController buildData / the /ads_manager/cpp/data JSON); add the times to the JSON rather than a second request. Keep existing JSON keys unchanged (the timeline, snapshot and copy-table features read them).
- Copy Table / snapshot outputs must not change (the freshness line is outside the copied table; tooltips are attributes only).
- Match the page's existing Tailwind styling; small text (text-xs), grey normally, amber for the warning.
- Works for MySQL (production) and the test DB driver the suite uses; follow the controller's existing driver branches for TIMESTAMP parsing.

## Threat model and risk tier
Untrusted: nothing new; inputs are the existing start/end query params already validated by the page. Output is text built from DB timestamps; escape it in Blade/JS (textContent or escaped attributes, never innerHTML with raw strings). Risk tier: low (read-only display on an internal page behind the existing access check).

## Done when
1. Feature tests cover S1 happy, lag warning, empty, multi-day, and S2 per-page times (with and without orders), asserting the JSON values the page uses.
2. `php artisan test tests/Feature` for the CPP and MetaAds tests pass; the full suite adds no new failures versus d02ed7a1 (it had 2 known failures; name them).
3. No migration files added; `git diff d02ed7a1 --stat` lists only the controller, the view, tests and docs.
4. Rendering check: run the page locally if the stack allows, or show the rendered Blade/JS output in a test; include a screenshot or the rendered HTML snippet of the line and one tooltip in the result.
5. Branch pushed to origin; result file written.

## Out of scope
The /ads_manager/report page redesign; changing the sync schedule; per-ad-group freshness queries; any change to how CPP is computed.

## Report back
Write docs/specs/250-cpp-last-updated.result.md and commit it: what changed (files), how times are stored and how you displayed them (S3), test counts (targeted and full suite, the known failures by name), the rendered snippet, the final commit sha, and anything you could not do. Budget: medium job; at most 2 attempts at any one failing step, then stop and report what you tried.
