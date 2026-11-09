# 253: Data health flags only uploaded accounts the API cannot read, checked after each upload

## Why
Staff upload the Ads Manager export by hand every morning (around 10 AM). The owner's rule: that upload is the baseline. If an ad account in the upload has rows or spend but the Meta API cannot read it, it must be shown; if there is none, there is no problem and no warning. Today DataHealth (app/Services/MetaAds/DataHealth.php around lines 60-95) marks an account "Not readable by API" whenever it is not in the API, even with 0 upload rows and P0 spend (8 such accounts were flagged on 2026-11-09, all 0 rows), so the red notice on /ads_manager/report is noise. The daily recorded check runs at a fixed 12:00 (routes/console.php:263, services.meta_ads.data_health_at), but the upload time varies.

## Stories
**S1 (P1)** NOT_READABLE only when the account has upload rows (or spend) for the checked day and is not readable by the API.
- Account not in API, 0 upload rows that day: not flagged as needing attention (choose QUIET or a non-attention status; it must not appear in the red notice).
- Account not in API, has upload rows that day: "Not readable by API" and in the red notice.
- Account readable by the API: unchanged statuses (OK, UNTICKED, MISSING, NEW as today).
- MISSING (had spend in the prior days, none yesterday) and NEW keep their current rules.
**S2 (P1)** The recorded check also runs right after an Ads Manager upload finishes processing (the upload job's successful end), so the red notice reflects the latest upload without waiting for a clock time. Keep the fixed 12:00 run as a fallback. Avoid duplicate heavy work: if several files are uploaded in a row, it is fine to run once per finished upload, or debounce (e.g. dispatch a unique/queued job); choose the simplest safe way and say which.
**S3 (P2)** The Data health card on /ads_manager/report shows when the last check ran and whether it was after an upload or at the scheduled time.

## Decisions already made
| Decision | Why |
|---|---|
| The upload is the baseline; only uploaded accounts the API cannot read are a problem | The owner's rule. |
| Check after each upload, keep 12:00 as fallback | The owner is not sure of the upload time; an event-based check needs no fixed time. |
| No new table | Keep the schema lean; reuse how the check is already recorded. |

## Constraints
- Hook the after-upload trigger at the end of the upload job's successful path in app/Jobs/ProcessAdsManagerReportsUpload.php (outside upsertCreativesForChunk, which another change edits) or in whatever completes the upload log; do not run it for the Meta sync's own internal importer use (Sync.php creates `new ProcessAdsManagerReportsUpload('')`) unless that is harmless and cheap — say what you chose.
- Threat model: no new input. Risk tier: low.

## Done when
1. Tests for S1 (four cases above) and S2 (finishing an upload records a check; the sync path behaves as you decided).
2. MetaAds tests pass; full suite adds no new failures vs 840da5b6 (name the known ones).
3. No migration unless unavoidable (say why if so).
4. Result file docs/specs/253-data-health-uploaded.result.md: files changed (file:line), what the 8 zero-row accounts would now show, test counts, final sha.
5. Branch pushed.

## Out of scope
The ad link, the report page redesign, changing the sync schedule, any deploy.
