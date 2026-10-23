# Result 128: analyst-entered "Mira RTS", formula renamed "Projected RTS"

Spec: `docs/specs/128-analyst-rts.md`. Amendments: `docs/specs/128-analyst-rts.amendment-1.md` (storage in page_settings, no migration).

## What shipped
- **Storage**: `page_settings` rows, provider `analyst`, keys `rts_pct:<canonical item>` (e.g. `33`, `32.5`) and `rts_reason:<canonical item>`. Written with `PageSettings::set(..., system: true)`, so replaced rows keep the history. No migration file, `page_item_settings` untouched (`rts_pct` is never changed). The key column is 100 chars: an item whose canonical name makes the key longer is reported as a per-row error.
- **Write API** `POST /api/owner/analyst-rts`, header `X-ANALYST-RTS-KEY` (env `ANALYST_RTS_KEY`, `config('services.analyst_rts.key')`, hash_equals, 401 when missing, wrong or empty; throttle 30/min). Body `{"rows":[{"page","item","rts_pct" 0–100,"reason" 1–500 chars}]}` or the bare list, at most 200 rows. Page+item must match a `page_item_settings` row (alias-aware); an unknown pair is a per-row error and the other rows still save. Response `{saved, failed, errors:[{index,page,item,error}]}`.
- **/owner/private**: formula column relabelled "Projected RTS" (id `mira_rts`, its CF rules unchanged); new column "Mira RTS" (id `analyst_rts`, visible by default) with hover = reason, when set, previous value; empty shows "—".
- **Catalog ids**: `analyst_rts`, `analyst_gap_pts` (Set − Mira), `analyst_gap_abs`.
- **Seeded rule** (once, never overwrites saved rules, same marker approach as spec 124): column `analyst_rts`, compare_col `analyst_gap_abs`, red `#fecaca` when ≥ 5, amber `#fde68a` when ≥ 3.
- **Feed** `/api/owner/rts-check` adds `analyst_rts`, `analyst_reason`, `analyst_at`.
- Provider `analyst` rows are hidden from the Page settings grid (build and changes) and the chatbot "+ Page" list.

## Tests
`vendor/bin/phpunit tests/Feature/RtsProjection tests/Feature/OwnerPrivate tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 1184 tests, OK (1 skipped, 52 existing deprecations). New: `tests/Feature/RtsProjection/AnalystRtsTest.php` (key, save with history, per-row errors, never touches rts_pct, grid hides analyst rows, page values, catalog, seed, feed). Updated for the intended change: render pins of `/owner/private` and `/owner/column-settings/owner-private`, the feed field list, and the seed test now sees two default groups.

## Notes
- If the same pct is posted again with only a new reason, the pct row is unchanged (no new history row, `at` keeps the first time) and the reason row is replaced.
- Env to set on deploy: `ANALYST_RTS_KEY` (in `.env.example`). No migration to run.

## Commits
See `git log cb9b5f76..HEAD`.
