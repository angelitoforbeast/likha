# Plan: spec 221 urgent only for pages that sell

Scope after Amendment 221-1: only S-221.1. No obsolete mark, no comeback, no page_settings row, no route, no
banner link. Risk tier: medium (what staff are warned about). Threat model: page names come from page_settings
and macro_output (trusted database); no new input, no new endpoint.

No migration, no new table, no new column, no route, no config. No queued job class changes. Out of
`resources/views/chatbot/index.blade.php`, `resources/views/chatbot/test.blade.php` and their JS.

## Design (all in `App\Services\Chatbot\ProblemsPanel`)

- `ORDER_DAYS = 14`: a page "sells" when it has at least one `macro_output` row whose `ts_date` is within the
  last 14 Manila calendar days (today and the 13 days before), the way `PageExpectedItem` counts its 3 days.
- One grouped query per banner build: `SELECT PAGE ... WHERE ts_date >= <day> AND PAGE IS NOT NULL GROUP BY
  PAGE` (index `ts_date, PAGE`), matched in PHP with `PageSettings::pageKey` (trim, inner spaces, case). It
  sits inside the existing 60-second `chatbot:problems-panel` cache next to `sending`, so a quiet page that
  gets an order is orange again within 60 s.
- `setup()`: sending and sells: orange `Setup: <reason>` (as today). Sending and no order: white
  `Quiet (no order in 14 days), setup incomplete: <reason>`. Not sending: white `Not sending, setup
  incomplete: <reason>` (as today).
- If the order query fails (or `macro_output` is missing), the pages are treated as selling: the warning stays
  orange as today rather than hiding a page that may sell.

## Tests (new `tests/Feature/Chatblast/QuietSetupTest.php`, red first)

- S-221.1.1 order 13 days ago (last day of the window) on a sending page with a setup X: orange `Setup:`.
- S-221.1.2 only an order 14 days ago: white `Quiet (no order in 14 days), setup incomplete: ...`, 0 urgent.
- S-221.1.3 quiet page, order arrives: still white while cached, orange after 61 s.
- S-221.1.4 settings `Page Uno`, order `PAGE` `  page   UNO `: matched, orange.
- S-221.1.5 80 sending pages with setup X: exactly one query on `macro_output` for a banner build, none on the
  next build within the cache.

Existing banner tests (`ProblemsPanelTest`, `SetupProblemsBannerTest`) expect orange for a sending page with
a setup X and no orders: they get an order today for their pages so they keep testing what they tested.

## Steps

1. Stories in `qa/stories.md` (S-221.1 only), tests, watch them fail (red lines in the result).
2. Code in `ProblemsPanel`; existing banner tests get their orders; green.
3. Full suite to a file, compare with the known baseline.
4. Result filled in, stories "Last run" filled.
