# 288 result: live Meta sync status, smaller Meta requests, hourly CPP timeline

Base: live ce591677. Branch `feat/288-sync-status-hourly-timeline`.

## Plan

### SS-2 Smaller requests and retry (`Sync`, `Insights`, `Graph`)
- **Unit: one account, one day.** `Sync` loops account → day (from..to). Each account/day is one ad-level insights request with the same fields and `limit` 100 (was one 7-day request, `limit` 500). The day's rows go through `withPageNames()` and `importRows()` exactly as today, so how rows are written does not change (spec 280's updated_at rule stays inside the importer).
- **Ad details once per account per run.** `Insights` gets `dayInsights()` (raw rows of one day) and `details()` (ad set / campaign / creative for ad ids not fetched yet in this run, 50 per call, as today). Without this, per-day requests would ask Meta for the same ads 7 times. `Insights::rows()` stays for Compare (hidden, unchanged).
- **Transient retry, opt-in.** A new `GraphException::transient()`: code 1 or 2, `is_transient: true`, or HTTP 5xx with no code, or no answer (connection error / timeout). `Graph::get()`/`all()` take a `$transientTries` argument (default 1 = today's behaviour for every other caller: connect, app test, AccountHealth, AdGroups). Only the sync's insights requests pass 3 (waits 2 s, 8 s through `Sleep`, as the rate-limit waits). A failing page of a paged list is retried as that page, not from page 1.
- **Fallback: Meta's async insights job for that account/day.** If the day still fails transiently, `Insights::asyncDay()` POSTs the same query to `act_<id>/insights`, polls `/<report_run_id>` (every 5 s, up to 24 polls = 2 min) until `Job Completed`, then reads `/<report_run_id>/insights` with paging. `Graph` gets a `post()` beside `get()` with the same header token, appsecret_proof and `clean()`. A failed or timed-out job leaves that account/day without data.
- **Permanent errors are not retried:** any other code (100, 200, 10, 803…, HTTP 400). Because a permission error repeats for every day, the account's remaining days are skipped and named, saving calls. 190 marks the token expired as today. Rate limit (4/17/613/800xx) keeps today's 4 tries with 2/8/32 s waits; after that the account's remaining days are skipped ("rate limited, skipped").
- **Partial** only when some account/day has no data (or a connection needs Reconnect, as today). Ad rows skipped for a missing page name stay a note, not Partial (as today).
- **Expected calls, 13 accounts × 7 days, healthy:** 91 insights calls (+1 per extra page when an account has more than 100 ads with delivery on a day) + about 13–26 ad-detail calls (50 new ads per call, once per account) ≈ **105–120**. Today it is 13 + the same detail calls ≈ 26–40, but each request is 7× heavier. Worst case per failing account/day: 3 tries + 1 POST + up to 24 polls + result pages. Rate-limit handling unchanged.

### SS-1 Live status (`MetaAdsController`, `_meta_ads.blade.php`)
- **Progress while running:** after each account/day the run writes `processed_rows`, `inserted`, `updated`, `skipped` to its upload_logs row (rows so far), and a small cache entry `meta_ads.sync.progress.<log id>` (accounts total / done, current account, days done; 2 h TTL). CACHE_STORE is `database` on both sites, so the queue worker and the web request see the same entry; if the cache is unreadable the card still shows status and rows. No schema change.
- **One line per ad account.** `error_message` keeps today's `act_<id>: <reason>` lines, one per account, joined by newlines instead of `; ` (e.g. `act_111: no data for Nov 12, Nov 13 (Graph error 2: Service temporarily unavailable)`; a single-day rate limit stays exactly `act_111: rate limited, skipped`). The last error per account only, never per retry. The long "No page name, skipped: ad … (page …)" list becomes one line per Facebook page id with the ad count.
- **Status route:** `GET /ads_manager/meta/sync/status?id=<log id>` (no id = latest run, manual or scheduled), in the same place in `routes/web.php` as the other `/ads_manager/meta/*` routes, behind the same `checkAccess()`. JSON: id, state (queued / running / done / partial / failed), range, who (person or "scheduled"), accounts done/total, rows, inserted, updated, skipped, started_at, finished_at, duration, and `problems` as `[{account, name, error}]` (name from `Connections::accounts()` / ad_accounts, read on the web side).
- **Card:** a "Last run" box under Sync now, rendered from the same data on page load and refreshed by a small script polling every 3 s while the run is queued or running, stopping when it ends. Problems list: first 3 lines shown, the rest in a `<details>` ("N more"). The API runs table shows each run's lines the same way (collapsed when more than 3). Scheduled runs show in the same box, labelled "scheduled".

### TL-2 Hourly spend source: (a) Meta hourly breakdown
- **Why (a):** (b) can only snapshot what the sync last wrote, and the sync runs 3 times a day, so an hourly snapshot would show flat spend with jumps at sync times, not spend per hour, and it can never fill past days. (a) is correct per hour, fills past days, and costs one request (+ pages) per account per day.
- **Request:** `act_<id>/insights`, `level=ad`, `breakdowns=hourly_stats_aggregated_by_advertiser_time_zone`, `fields=ad_id,spend,impressions,actions`, one day, `filtering` spend > 0, `limit` 500, with the same transient retry. Ads are mapped to pages through `ads_manager_reports` (ad_id → page_name of that day, already synced); an unmatched ad goes to an "Unknown ad" row. The ad account's time zone is assumed to be Asia/Manila (the daily rows already rely on it); the account's `timezone_name` is checked and a non-Manila account is named in the run note rather than shifted silently.
- **Storage, no new table:** `cpp_snapshots` rows with `snapshot_bucket` `H00`…`H23` per (date, page), **cumulative from 00:00 to the end of that hour**: `amount_spent`, `cpi` (₱ per 1,000 impressions) and `cpm` (₱ per message), as this page already defines them. Cumulative storage keeps CPI/CPM exact for any hour (the raw counts are summed before dividing); a single hour's spend is the difference of two cells. `orders`/`cpp` are left 0/null there: orders are read live from macro_output (late-encoded orders count), as the bucket view does. Re-runs upsert by the existing unique key (date, bucket, page). `snapshot_at` = fetch time; `saved_by_*` null (system).
- **When it is fetched:** new command `meta-ads:hourly {--date=} {--from=} {--to=}` (max 31 days) and a schedule at minute 10 of every hour for today (and yesterday on the 00:10 run, to close it). Same gate as the sync: write mode on and accounts selected (the command does nothing otherwise). Past days: `meta-ads:hourly --from=… --to=…`. The page never calls Meta.
- **The bucket view keeps its own rows:** `timelineData()` reads only the four bucket labels from cpp_snapshots (`whereIn`), so H-rows never mix into bucket totals.

### TL-1 Hourly view (`cpp_timeline.blade.php`, `CPPController`)
- **Toggle, bucket view kept:** a "Buckets | Hourly" switch at the top. The bucket view is unchanged (it spans many days and shows saved Copy-Table snapshots, which the hourly view does not replace). Hourly uses a single date picker (default today).
- **Data:** `GET /ads_manager/cpp/timeline/hourly?date=` in the same CPP route group with the same `checkAccess()`. Per page with spend that day: 24 cells with cumulative spend, cumulative orders (macro_output TIMESTAMP parsed in PHP for that one day, so it works on MySQL, Postgres and the sqlite tests), CPP = spend ÷ orders, CPI, CPM, and the hour's own added spend and orders; plus a Total row. Hours after the current Manila hour today are blank.
- **Cell and tooltip:** the cell shows CPP as a whole peso number; the `title` tooltip shows cumulative spend, orders, CPI, CPM to the end of that hour, then "this hour: +₱spend, +orders". **Cumulative, because** a single hour often has 0–1 orders, so per-hour CPP is mostly blank or extreme; cumulative CPP at 15:00 means the same as the 3PM bucket, so the two views agree.
- **Colours:** the timeline's existing cell tints: white = Meta spend and orders (CPP), amber = orders but no hourly spend stored yet, empty = no data / future hour (see question 1).
- **No horizontal scroll at ≥1280 px:** the hourly table is `table-layout: fixed; width: 100%` inside a wrapper without `overflow-x`; a `<colgroup>` gives the page column 128 px and 24 hour columns share the rest (≈44 px each inside the 1280 px `max-w-7xl` box); 10–11 px text; page names truncate with an ellipsis and a full-name `title`. With fixed layout the column widths never grow with content, so the table is never wider than its box. The 24 hour headers (`00`…`23`) are rendered on the server, so a feature test can count them and check the CSS.

### Tests (fake Graph only, Sleep faked)
- SS-2: code 2 then success on the same account/day → rows written, run Done, 2 insights calls; code 1 three times → async job (POST, poll, result) → rows, Done; async fails → Partial naming account and day; permanent 400 (code 100) → 1 call, not retried, remaining days skipped, Partial; 7-day run = one insights request per account per day with `limit` 100 and ad details asked once; existing rate-limit and 190 tests unchanged.
- SS-1: status JSON for queued / running (progress from cache) / done / partial with one problem line per account and names; 404 for a role without access; the card renders the last-run box and collapsed lines; a scheduled run is shown with "scheduled".
- TL-2: `meta-ads:hourly` writes cumulative H-rows per page from a fake hourly breakdown, maps ads to pages, upserts on re-run, does nothing when write mode is off; bucket timeline totals ignore H-rows.
- TL-1: hourly JSON for a seeded day (spend + macro_output orders) → 24 cells per page with the expected cumulative CPP, CPI, CPM; future hours blank today; the page renders 24 hour headers, `table-layout: fixed`, and no `overflow-x` on the hourly wrapper; access 404 for other roles.
- Verify: `tests/Feature/MetaAds` and `tests/Feature/CPP` green; full suite compared with base ce591677.

### Questions
1. "The existing colours": this page has no CPP colour scale today (only the saved / inferred / estimate tints; /cpp colours TCPR only). Use those tints as planned, or do you want CPP thresholds (give the ₱ cut-offs)?
2. Gate the hourly fetch behind write mode, as the sync is (planned), or let it run whenever accounts are selected?
3. Schedule the hourly fetch every hour (≈13 calls per run, ≈310 per day) as planned, or only at fewer times?

## What shipped

(to fill)

## Evidence

(to fill)

## Rulings

(to fill)

## Merge danger

(to fill; known so far: other readers of `cpp_snapshots` that sum by date without a bucket filter would count the new H-rows; `Graph::get()`/`all()` gain an argument; the sync's `error_message` separator changes from `; ` to newlines.)
