# Brief 128: the "Mira RTS" value is entered by the analyst (with a reason); the formula becomes "Projected RTS"

Today the "Mira RTS" column on /owner/private is the formula projection (spec 122/124, `RtsProjection`). The owner, 2026-10-23: the analyst (an AI assistant working through an API key) should enter that value herself after analysing the data, with a reason, and the owner will train her by correcting her. The formula stays visible as the reference.

## Decisions
| Decision | Why |
|---|---|
| **Storage**: nullable columns on the existing `page_item_settings` (no new table): `analyst_rts_pct` decimal(5,2), `analyst_rts_reason` text, `analyst_rts_at` timestamp. One migration, nullable only. Every change also writes a row to `page_item_settings_log` (source `analyst_rts`, old/new value, the reason in the comment) so history and training data exist. | Lean, history kept. |
| **Write API** for the analyst: `POST /api/owner/analyst-rts` with the same kind of read/write key handling as the rts-check feed but its OWN key `ANALYST_RTS_KEY` (env, hash_equals, 401 otherwise, throttled). Body: list of `{page, item, rts_pct (0–100), reason (≤500 chars)}`; matches page+item the same way `page_item_settings` rows are keyed (canonical item names as RtsProjection uses); unknown page+item → per-row error, others still saved; returns counts. Never touches `rts_pct` (the staff Set RTS). | The analyst inputs values. |
| **/owner/private**: rename the current formula column label to **"Projected RTS"** (keep its id/CF rules working) and add a column **"Mira RTS"** (new id, e.g. `analyst_rts`) showing `analyst_rts_pct`; hover shows the reason, when it was set, and the previous value. Empty shows "—". Add it to the column catalog and Conditional Formatting catalog; add catalog columns `analyst_gap_pts` (Set − analyst) and `analyst_gap_abs` so rules can colour it. Seed one default CF rule for the new column only if none exists: red when `analyst_gap_abs` ≥ 5, amber when ≥ 3 (never overwrite saved rules; reuse spec 124's seeding approach). | Owner sees her value beside the staff value. |
| **Feed** `/api/owner/rts-check` adds `analyst_rts`, `analyst_reason`, `analyst_at`. | The analyst reads her own last values. |
| No other number changes; profit columns keep using what they use today. | Lean. |

## Done when
- Tests: migration columns exist and are nullable; the write API needs its key, saves value+reason+timestamp, logs a history row with source `analyst_rts`, rejects out-of-range and over-long input, reports unknown page+item without failing the rest, and never changes `rts_pct`; /owner/private shows "Projected RTS" (formula) and "Mira RTS" (analyst value with hover reason); CF catalog has the new ids; default rule seeded once; feed carries the analyst fields.
- `vendor/bin/phpunit tests/Feature/RtsProjection tests/Feature/OwnerPrivate tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes (re-pin render hashes only for the intended change).
- Result: migration file, API contract, catalog ids, seeded rule, commits.
