# Spec 246: Hook Rate, Hold Rate and Hold Rate 50% per ad, and the Ads Manager upload requires the video columns

Type: change. Weight: bounded. Risk tier: medium (the upload is how the team loads the daily Ads Manager report; a wrong required-column check blocks every upload). Budget: medium job, at most 2 attempts per step.

## Why

Spec 244 (live at 52f85bbb) stores `video_plays_3s`, `video_thruplays` and `video_p50` per ad and day from the Meta Ads API sync and shows them in the Campaigns Manager. The owner wants three rates computed from them, shown in the ads rows of the Campaigns Manager and of `/owner/private` (which share the campaigns column catalog in `OwnerColumnSettingsController::CATALOG`), and wants the Excel/CSV upload of the Ads Manager report to carry the same three video numbers, required.

## Stories

S1 (P1). Three new columns in the campaigns column catalog, computed per row (ad level, and on the totals/summary rows the same way, from summed numbers, never by averaging rates):
- **Hook Rate** = 3-second plays ÷ Impressions.
- **Hold Rate** = ThruPlays ÷ 3-second plays.
- **Hold Rate 50%** = 50% views ÷ 3-second plays.
- Shown as a percentage with one decimal (e.g. 23.4%), sortable like the other percentage columns.
- Edge: a divisor that is 0 or null, or a numerator that is null (no video): "—", never 0% and never an error.
- The columns appear wherever the existing video columns (and other computed rates such as conversion or welcome message rate) appear for ads: the Campaigns Manager ads tab and the ads rows on `/owner/private`, plus the ads CSV export after "50% views".

S2 (P1). Visibility is decided only in `/owner/column-settings/campaigns`, never hard-coded: the three columns are catalog entries like any other, CEO sees them by default, other roles only when checked there (the existing rule for new columns). Place them right after the three video columns, including in an older saved order (as spec 244 did with `PLACED_AFTER_PREVIOUS`).

S3 (P1). The Ads Manager report upload (`ProcessAdsManagerReportsUpload` and its header mapping) requires three more columns, with the names Ads Manager uses in its export: "3-second video plays", "ThruPlays", and "Video plays at 50%". Match headers the same tolerant way the importer already matches others (case, spacing, the currency or attribution suffixes it already strips); check the exact export header names in Meta's Ads Manager export and say in the result which variants are accepted.
- Happy: a file with the three columns uploads as before and fills `video_plays_3s`, `video_thruplays`, `video_p50` on the rows it writes; a blank or "—" cell is null.
- Negative: a file missing any of the three is refused before any row is written, with a message naming exactly the missing column(s), in the same place and style as the importer's existing missing-column errors.
- Edge: the API sync and the upload write the same columns; neither overwrites a number with null.

## Decisions already made (do not reopen unless something is actually broken)

| Decision | Reason |
|---|---|
| Hold rates divide by 3-second plays, not impressions. | The owner confirmed these formulas: hold measures who stayed among those hooked. |
| Rates on totals are computed from summed numbers. | Averaging percentages misweights small ads. |
| Visibility only through column settings. | The owner: check them in column settings, not hard-coded. |
| The upload requires the three columns. | The owner wants every upload to carry the video numbers. |
| No new table; no new stored column for the rates (computed). | The owner prefers no new tables; rates derive from stored numbers. |

## Threat model

Untrusted: uploaded files (validate numbers; a non-numeric cell is null, never an error that writes partial rows). Trusted: the database and Meta's API data already stored.

## Constraints

- Tests first: rate math including zero/null cases and totals; catalog/visibility via column settings (non-CEO hidden until checked); upload accepted with the columns, refused (nothing written) without each one, header variants; CSV export columns.
- Pin tests that render the shared owner pages may need re-pinning; diff the renders and re-pin only when the difference is the new columns (as spec 244 did).
- English UI text. Code comments explain the reason itself. Commit messages carry no Co-Authored-By, Claude-Session or "Generated with Claude Code" lines and name no people.
- No `.env`, server or deploy changes.

## Done when

1. Feature tests prove every case of S1, S2 and S3.
2. The full suite adds no new failures compared with `52f85bbb` (2 known failures: ExampleTest, ItemTableFitScriptTest).
3. No migration (or, if one is truly needed, it is explained in the result).
4. Branch `feat/246-hook-hold-rates` from `52f85bbb`, pushed to `origin`; the result lists its head commit.

## Out of scope

Other video metrics; changing the sync; backfilling old uploads; Compare.

## Report back

In `docs/specs/246-hook-hold-rates.result.md`: plan, done-when evidence, the accepted upload header names, files changed, head commit, and anything to check before release (for example, telling the team their next Ads Manager export must include the three columns).
