# Brief 124: the Mira RTS colours come from the existing Conditional Formatting, not from code

Spec 122 colours the Mira RTS cell red with hard-coded rules (`RtsProjection::FLAG_POINTS = 5`, `MIN_MATCHED_FLAG = 30`, inline style in resources/views/owner/private.blade.php ~1059). Likha already has per-column **Conditional Formatting** for the Page Summary at /owner/column-settings (`OwnerColumnSettingsController`, `_col_format_section.blade.php`, JSON in app_settings key `owner_private_col_format`, rule groups per column, values can reference other columns). The owner, 2026-10-23: use the conditions setup we already have, and base the condition on how sure the projection is.

## Decisions
| Decision | Why |
|---|---|
| **New catalog columns** for the Page Summary so CF rules can target or reference them: `mira_rts_pct` (exists), `rts_gap_pts` = Set RTS − Mira RTS (signed), `rts_gap_abs`, `mira_rts_error_pts` = expected error = `sqrt(e_age² + (100·sqrt(p(1−p)/matched))²)` with e_age the existing age band (≈3 / 1 / 0.5) and p = Mira/100, `rts_gap_ratio` = rts_gap_abs ÷ mira_rts_error_pts, `mira_rts_matched`. Add them to the column catalog (hidden by default) the same way other computed columns are. | The owner sets the condition himself; the error makes "5 points" mean the right thing per row. |
| **Remove the hard-coded colour** on the Mira RTS cell; it is coloured only by CF rules. **Seed default CF rule groups** for `mira_rts_pct` (only if the owner has no rule for that column yet, never overwrite saved rules): amber when `rts_gap_ratio` > 1 and `mira_rts_matched` ≥ 30; red when `rts_gap_ratio` > 2 and `rts_gap_abs` ≥ 5 and `mira_rts_matched` ≥ 30. If the CF engine has no AND between conditions, implement the closest equivalent it supports and say exactly what you did. | Same behaviour as today, now editable. |
| **Feed / daily list** (`/api/owner/rts-check`): add `error_pts`, `gap_ratio` and a `level` (none / yellow / red). Compute `level` server-side from the **same saved CF rules for `mira_rts_pct`** if the CF rules are evaluable in PHP (reuse any server evaluator; if CF is evaluated only in JS, add a small PHP evaluator for the operators the seeded rules use and document the limits). Keep `flag` = level==red. Sort red, yellow, then gap. | Mira's daily report follows the owner's conditions. |
| No migration. Keep `MIN_MATCHED_FLAG`/`FLAG_POINTS` only as seed defaults or delete them. | Lean. |

## Done when
- Tests: the new columns are in the catalog with correct values (error grows with fewer orders / earlier age); the seed adds the two default rules once and never overwrites saved rules; with the defaults a row is amber/red as described; changing a saved rule changes the cell colour data and the feed `level`; no hard-coded colour remains for the Mira RTS cell.
- `vendor/bin/phpunit tests/Feature/RtsProjection tests/Feature/OwnerPrivate tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes (re-pin render hashes only for the intended change).
- Result: files, catalog ids, seeded rules (as JSON), how the feed evaluates them, commits.
