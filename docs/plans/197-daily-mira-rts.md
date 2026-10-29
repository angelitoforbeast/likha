# Plan 197: Mira RTS every day, checked against reality every day

Spec: `docs/specs/197-daily-mira-rts.md`. Base 8705e881. Threat model: nothing new is reachable from outside. The run
is a scheduled command over trusted tables (macro_output, from_jnts, page_settings, app_settings); the only untrusted
text it touches is item and page names from orders, which it stores as data in keys it already uses. The analyst API
(own key) is unchanged apart from tagging its rows `source = mira`.

## Design

- **Estimate** (`App\Services\AnalystRtsDaily::run()`, command `rts:daily-estimate`, 06:00 Manila, `onOneServer`,
  `withoutOverlapping`). Range = first day of last month .. today (Manila). For every page+item in
  `RtsProjection::rows(range)` with a projected value: estimate = projected rounded to 0.5, written as the Mira RTS
  (`analyst` / `rts_pct:<item>`, `source = auto`) with an automatic Taglish reason (`rts_reason:<item>`, ≤500 chars).
  The command refuses to run between 03:00 and 03:45 Manila (night Astra step), whatever starts it.
- **Daily row** (no new table): one more analyst key per page+item, `rts_day:<item>`, a JSON value written once per
  day: `d`, range `from`/`to`, `est`, `proj`, `m` (matched), `fin` (% final), `t` (% in transit), `age` (avg transit
  age), and `acc` (the accuracy, below). Replaced rows of the three keys are the history; nothing is updated in place.
- **Analyst adjusts**: `AnalystRts::save()` writes `source = mira`. The run per item:
  - today's `rts_day` row exists → skip the item (rerun the same day = nothing new, S-197 P1.6);
  - else write the day row; write the auto value and reason unless the active value is `mira` and was written today at
    or after 06:00 (then it stays until tomorrow's run, S-197 P1.3).
- **Reason format**: `Auto 06:00: <m> order na may J&T status (<from>–<to>). <fin>% tapos na (RTS <r>%, delivered
  <d>%); <t>% nasa biyahe pa, avg <age> araw. Tantya ng final RTS: <est>%.` + `Huling 21 araw <x>% vs buong range <y>%
  (<±z> pts).` when |x−y| > 3 + `Set RTS <s>%: <±g> pts ang agwat.` when |g| ≥ 3.
- **No look-ahead** (S-197 P1.5): `RtsProjection::rows($from, $to, ?Carbon $asOf)` and `learnTable(Carbon $asOf)`.
  With `$asOf`: orders with ts_date ≤ asOf only; each order's state is the last status log at or before asOf (none
  picked up by then = no J&T state); age counts to asOf; p(age) is learned from orders known final at asOf, with
  samples only at ages before asOf. Without `$asOf` nothing changes (today's behaviour, saved rates). The command
  takes `--as-of=YYYY-MM-DD` to print a replay without writing.
- **Accuracy** (same run, after the estimate): for N = 7 and 14, the estimate of day D−N = the Mira RTS value in force
  at the end of that day (the analyst's if she adjusted, else the auto one; its source kept) for page+items whose
  `rts_day` of D−N exists. Actual = RTS of the same order set (ts_date in that day's `from`..`to`) with today's
  statuses: (Returned + For Return) ÷ (Delivered + Returned + For Return); `fin` = finished ÷ matched. Error = estimate
  − actual, in points. Stored in today's `rts_day.acc`.
- **Screens**: `/owner/private` column "Mira RTS accuracy" (`analyst_rts_acc`, catalogued, default visible, hideable):
  `7d ±x · 14d ±y`; hover = the last 7 days' errors with % final. Sort by |7d error|. The Mira RTS hover says
  "auto" for an automatic value. Feed `/api/owner/rts-check` gets a top-level `accuracy` list: per day, mean absolute
  error of the 7- and 14-day estimates over page+items with ≥30 matched orders in the set, and the count.
- **Load**: four `rows()` passes (range, last 21 days, the two accuracy sets) on indexed `macro_output.ts_date` and
  `from_jnts.waybill_number`; runtime measured on a seeded 2-month fixture of 47 page+items.

## Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | `RtsProjection` as-of: rows and rate table from status logs known at a date | backend | medium | P1.2, P1.5 |
| 2 | Daily run: estimate, reason, day row, analyst-adjust rule, rerun-safe, command, schedule, night guard | backend | medium | P1.1, P1.3, P1.6 |
| 3 | Accuracy in the run, readers, feed overall line | backend | medium | P1.4 (data) |
| 4 | "Mira RTS accuracy" column, hover, catalog; Mira RTS hover shows auto | frontend | medium | P1.4 (screen) |
| 5 | Runtime measurement, suites vs baseline, skeptic review, result | - | - | P1.7 |

Tasks share `AnalystRts` and the run, so they go in order in this worktree; each stage is committed when its tests
pass.

## Baseline

`tests/Feature` on 8705e881 (this branch before code): recorded in the result.
