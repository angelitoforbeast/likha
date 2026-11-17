# 288: live Meta sync status, smaller Meta requests, and an hourly CPP timeline

## Why (owner, 2026-11-17)
1. On `/ads_manager/report`, clicking **Sync now** only shows "Sync queued: #id". Staff must refresh to see whether it finished, and the error list is a huge wall of repeated lines.
2. On the second site, Sync now (run 97977, Nov 11–17, 17 min) came back PARTIAL. For 6 of 13 ad accounts, every try failed with Meta "Graph error 2: Service temporarily unavailable" or "Graph error 1: An unknown error occurred", so those 6 got no rows. Meta returns these codes for insights requests that are too heavy (a 7-day ad-level range with many fields). They are usually cured by asking for less data at once (one day per request, smaller pages) or by Meta's async insights report jobs.
3. On `/ads_manager/cpp/timeline` the owner wants **every hour**, not only the 10AM / 3PM / 7PM / 11:59PM buckets. It must stay compact: all 24 hours visible at once, **no horizontal scroll**.

## Stories
**SS-1 Live sync status (P1).** After Sync now, the Meta card shows the run's live state without a page refresh: Queued → Running (accounts done N of M, rows so far) → Done / Partial / Failed, with inserted/updated counts and duration. Poll a small JSON status route every few seconds (same middleware/permission).
- The error area shows **one line per ad account** (name if known, and the last error), not every retry repeated.
- Long lists collapse.
- The scheduled runs show the same last-run summary.

**SS-2 Smaller requests and retry (P1).**
- Sync requests insights per account **per day** (or another smaller unit you justify), with a smaller page size.
- On code 1/2 (and any transient 5xx), retry with backoff and, if still failing, a smaller unit or Meta's async insights job for that account/day.
- A permanent error (for example HTTP 400 or permission) is not retried.
- The run ends Partial only if an account/day still has no data, and the summary names those account/days.
- Keep total calls reasonable and respect existing rate-limit handling. State the expected call count for 13 accounts × 7 days.
- No change to how rows are written (spec 280 kept updated_at on unchanged rows).

**TL-1 Hourly timeline (P1).** `/ads_manager/cpp/timeline` can show CPP per page **per hour** (00–23, Asia/Manila) for a chosen day: hourly columns, rows per page.
- Each cell shows CPP with the existing colours, and a tooltip with spend, orders, CPI and CPM for that hour (or cumulative to that hour; state which and why).
- The 24 columns fit the page width with no horizontal scroll at common desktop widths (≥1280 px), with narrow cells and small text.
- Keep the existing bucket view available (a toggle), or replace it only if the hourly view covers it; say which.

**TL-2 Hourly data source (P1).** Orders per hour come from order time (macro_output timestamps, as the page already does for buckets). For hourly spend, pick the cheapest correct source and justify it:
- (a) Meta insights with the hourly breakdown (`hourly_stats_aggregated_by_advertiser_time_zone`), one call per account per day; or
- (b) an hourly scheduled snapshot of the existing synced spend.

Lean schema: store hourly figures in the existing tables (for example cpp_snapshots with an hourly bucket, or ads rows) unless that is impossible; ask before adding a table. Past days fill from Meta only if (a).

## Constraints
- Same middleware and permissions.
- Lean schema.
- Additive migrations only.
- The same code must work on both sites.
- The whole `tests/Feature/MetaAds` folder and the CPP tests must pass. Full suite: no new failures against base `ce591677` (known base failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest).

## Done when
- Tests for SS-1, SS-2 and TL-1/TL-2 pass, including a fake Graph returning code 2 then success, and a permanent 400 that is not retried.
- A seeded hourly timeline renders 24 columns without horizontal overflow; show the evidence (HTML or CSS reasoning and a test).
- The branch is pushed.
- The result file has evidence and a merge-danger section.

## Budget
Medium-large. At most 2 attempts at a failing step, then report.
