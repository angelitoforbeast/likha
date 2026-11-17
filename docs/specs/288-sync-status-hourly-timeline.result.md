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

No migration, no new table, no config change. One new scheduled command. Routes are added to the existing groups with the same middleware (`web`, `auth`, `allowed_ip`) and the same in-controller permission checks.

**SS-2: smaller requests and retry**
- `GraphException`:
  - `transient()`: code 1/2, Meta's `is_transient`, HTTP 5xx without a Graph code, or no answer.
  - `blocked()`: code 368.
  - The constructor also keeps the HTTP status and the transient flag.
- `Graph`:
  - New `retrying()` returns a copy that tries a transient error `TRANSIENT_TRIES` = 3 times (waits 2 s, then 8 s).
  - `get()` / `all()` keep their signatures: the plan's extra argument broke a test class that extends `Graph` (`AdGroupRunTest`), and any subclass would have the same problem.
  - Every caller that does not use `retrying()` behaves as before. The sync's insights, ad details, async job and the hourly call use it.
  - Rate-limit waits are unchanged (4 tries, 2/8/32 s).
  - A failing page of a paged list is retried as that page.
  - New `post()` method (same token header, appsecret_proof and `clean()`), used only for the async job.
- `Insights`:
  - `dayRows()`: one account and one day, `limit` 100, ad details fetched only for ads not yet seen in the run.
  - `dayInsights()`: after 3 transient failures, falls back to `asyncReport()` (POST `act_<id>/insights`, poll `/<report_run_id>` every 5 s up to 24 times, then read `/<report_run_id>/insights`).
  - `hourly()`: the hourly breakdown (TL-2).
  - `rows()` (used by Compare) is unchanged.
- `Sync::runAccounts()`:
  - Loops account → day.
  - A transient failure leaves only that day without data.
  - A rate limit, block or permanent error stops that account; its remaining days are named, not asked.
  - 190 behaves as before.
  - The run is Partial only when an account/day has no data (or a connection needs Reconnect, as before).
  - `importRows()` is called as before, so how rows are written is unchanged.
  - `error_message`: one line per account, `act_<id>: no data for Oct 20, Oct 21 (<last error>)`. A whole range becomes `Oct 11 to Oct 17`. A whole-range rate limit stays `act_<id>: rate limited, skipped`. Lines are joined by newlines.
  - "No page name" skips are one line per Facebook page.
- **Calls for 13 accounts × 7 days (healthy):** 91 insights calls, plus 1 per extra page when an account has more than 100 ads with delivery on a day, plus about 13–26 ad-detail calls (each ad once per run, 50 per call). That is about **105–120 calls**.
  - Per failing account/day: at most 3 tries, then 1 POST, up to 24 polls and the result pages.
  - A permanent error costs 1 call for the whole account.

**SS-1: live status**
- Progress while running:
  - The run writes `processed_rows` / `inserted` / `updated` / `skipped` after each account/day.
  - `Sync::progress()` keeps accounts done/total and the current account in the cache (`meta_ads.sync.progress.<id>`, 2 h). The cache is the database store on both sites. Writing it is best-effort.
- `GET /ads_manager/meta/sync/status?id=` (`MetaAdsController::syncStatus`):
  - Same `checkAccess()` as the card.
  - Without an id it returns the latest run, manual or scheduled.
  - It returns the summary (state, range, who / "scheduled", accounts N of M, rows, inserted, updated, skipped, duration, problems with account names) and the box's HTML.
- `_meta_sync_run.blade.php` is the one template for the page and the poll.
- The card (`_meta_ads.blade.php`):
  - Shows the "Last sync" box under Sync now. It polls every 3 s while the run is queued or running, then stops.
  - Shows 3 problem lines; the rest are behind "N more".
  - In the API-runs table, each run's lines are split (runs from before 288 that used `; ` are split too) and collapse after 3.
  - The uploads table on the report keeps newlines (`whitespace-pre-line`).

**TL-2: hourly spend**
- `HourlySpend::run(days, pause)`:
  - Runs only when write mode is on and accounts are selected.
  - One request per selected account per day: `level=ad`, `breakdowns=hourly_stats_aggregated_by_advertiser_time_zone`, `fields=ad_id,spend,impressions,actions`, spend > 0, `limit` 500.
  - A rate limit or block on any account stops the run as `skipped`, with no retry; it is logged in `upload_logs` (type `meta_ads_hourly`) and the app log.
  - Each run is one `upload_logs` row.
- `HourlySpend::store()`:
  - Writes cumulative rows `H00`…`H23` per (date, page) into `cpp_snapshots`: `amount_spent`, `cpi` (₱ per 1,000 impressions) and `cpm` (₱ per message), the page's own definitions.
  - `orders` = 0 and `cpp` = null; orders are counted live.
  - Pages come from `ads_manager_reports` (same day first, then the ad's latest page); an unknown ad goes to an "Unknown ad" row.
  - No hours after the current Manila hour.
  - When every account was read, the day's hourly rows are replaced. When one failed, rows are upserted so the stored pages keep their last figures.
- `meta-ads:hourly`:
  - `--scheduled` reads today; the 00:10 run also reads yesterday.
  - `--date`, or `--from/--to` (at most 31 days), with `--pause` (default 3 s) between requests.
  - Scheduled `hourlyAt(10)` Manila, behind write mode, `withoutOverlapping(50)`.

**TL-1: hourly view**
- `GET /ads_manager/cpp/timeline/hourly?date=` (`CPPController::timelineHourly`), in the CPP route group with the same `checkAccess()`:
  - Pages: those with hourly spend plus those with report spend that day.
  - Orders: macro_output for that day (`ts_date`, else the TIMESTAMP suffix), parsed in PHP so it works the same on MySQL, Postgres and sqlite.
  - 24 cells per page, cumulative: spend, orders, CPP, CPI, CPM, what the hour added, and the source (meta / orders only).
  - A Total row.
  - Hours after now (today) are null.
- `cpp_timeline.blade.php`:
  - A Buckets | Hourly toggle; the bucket view is kept.
  - Hourly view: day picker, server-rendered 24 hour headers, `<colgroup>`, cells showing whole-peso CPP, and a tooltip with the cumulative figures plus "This hour: +₱…, +n orders".
- CPP colours in both views: green below ₱30, cyan ₱30 to under ₱50, orange ₱50 to under ₱70, red ₱70 and up, grey when there are no orders.
  - Bucket view: the state became an edge plus a tooltip (inferred = amber inner edge, estimate = dashed blue outline, saved = none). The legend matches.
  - Hourly view: "orders but no hourly spend yet" is a thin amber edge.
- `CPPController::BUCKETS` (the 4 labels):
  - `timelineData()` reads only those rows.
  - `timelineDetail()` refuses any other bucket with 422.
  - `$buckets` uses the constant.

## Evidence

**New tests (all pass)**
- `tests/Feature/MetaAds/MetaAdsSyncPerDayTest.php` (13 tests).
  - SS-2:
    - One request per account per day with `limit` 100; each ad detail is asked once.
    - Code 2 / code 1 (HTTP 500) / a 503 without a code, then success: 2 calls, one 2 s wait, run Done.
    - 3× code 2 → one async POST (no `limit`), a poll, the result read, rows written, Done.
    - Async "Job Failed" → Partial with exactly `act_111: no data for Oct 20 (Graph error 2: Service temporarily unavailable; async report: job failed)`; the other day and the other account are written.
    - A permanent 400 (code 100) is asked once: no wait, no async. The other days are not asked; the line is `act_111: no data for Oct 20 to Oct 22 (Graph error 100: Invalid parameter)`.
    - A second sync with no change leaves `updated_at` alone.
  - SS-1:
    - The status JSON and HTML for queued, then running ("Accounts 1 of 3 · rows so far 120 · +100 new / ~20 updated · reading Account 222", "1 min 15 s").
    - After a partial run, 4 failing accounts give 4 lines (not one per retry or day). The box shows `Account 111 (act_111): no data for Oct 20, Oct 21 …`, the 4th line sits behind "1 more", and the page includes the poll.
    - A `meta-ads:sync` run shows as "scheduled" in the same box.
    - Data Encoder gets 404; a guest gets 401.
    - API-runs table: new and old (`; `) runs split into lines, collapsed after 3.
- `tests/Feature/MetaAds/MetaAdsHourlyTest.php` (7 tests).
  - One request per account with the hourly breakdown query.
  - Cumulative rows per page and hour (P1 H09 ₱100 / CPI 100 / CPM 20; H10 ₱180 / CPI 100 / CPM 22.5), an "Unknown ad" row, live orders not stored, 39 rows logged.
  - Today stops at the current hour; a re-run replaces the day.
  - The 00:10 run also reads yesterday.
  - Write mode off: no call, logged as skipped.
  - Code 17 and code 368: asked once, run skipped, stored rows kept, no sleep.
  - Backfill of 3 days × 2 accounts: 6 calls with five 3 s pauses; more than 31 days is refused.
  - The schedule is `10 * * * *` Asia/Manila, behind write mode.
- `tests/Feature/CPP/CppHourlyTimelineTest.php` (7 tests).
  - Hourly JSON: 24 cells, cumulative CPP 50 → 60, what each hour added, an orders-only page, the Total, empty hours after now.
  - **24 columns without horizontal overflow**: the rendered table has exactly 24 `<th class="tl-hour-h">` (00…23), 1 page `<col>` plus 24 hour `<col>`s, and the CSS checked is `.tl-hour { table-layout: fixed; width: 100%; …}`, `.tl-hour-wrap { width: 100%; overflow-x: hidden; }`, `nowrap` and `ellipsis`.
  - Colours and tooltip, run in Node from the page's own script: tones at ₱29.99 / 30 / 49.99 / 50 / 69.99 / 70; orders-only = grey plus amber edge; the exact tooltip text.
  - Bucket view: CPP fill with the state as an edge.
  - The bucket data and the popup ignore H-rows (`timeline/detail?bucket=H10` → 422; the 7PM totals are only the 7PM row).
  - Data Encoder gets 404.
- **Why there is no horizontal overflow:**
  - With `table-layout: fixed` and `width: 100%`, column widths come only from the `<colgroup>` and the table width, never from cell content. Content that does not fit is clipped (`overflow: hidden; text-overflow: ellipsis; white-space: nowrap`), and the wrapper has no `overflow-x: auto`.
  - At a 1280 px window the box is 1280 − 64 (page padding) − 32 (card padding) = 1184 px. The page column takes 128 px, leaving 24 × 44 px. A 3-digit CPP in 10 px monospace is about 19 px.
  - A narrower box only narrows the hour columns.

**Suites**
- `tests/Feature/MetaAds` + `tests/Feature/CPP`: OK (323 tests, 1659 assertions). The 296 tests that already existed pass unchanged.
- Full suite on a130827a (19 min): 5383 tests, 3 failures, 6 skipped. The 3 failures are exactly the known base failures (ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest), so there are no new failures against ce591677.
- The first full run found one break: `AdGroupRunTest` extends `Graph` and overrides `get()` with the old signature. Fixed by keeping the signatures and adding `retrying()`; `AdGroupRunTest` then passed with MetaAds + CPP (370 tests OK) before the full run above.

## Rulings

1. **Colours.** CPP colours by amount, the team's CPP report cut-offs: green below ₱30, cyan ₱30 to under ₱50, orange ₱50 to under ₱70, red ₱70 and up. A cell with no orders yet is neutral grey. The saved / inferred / estimate state is shown in the tooltip or as a thin border, not as the fill. Applied to both views.
2. **Write mode.** The hourly fetch runs only when write mode is on, like the sync (the schedule's `when` and the command both check).
3. **Frequency.** Every hour at :10, all 24 hours.
   - Only the selected ad accounts, one call per account per run.
   - On a rate limit or block, that run is skipped and logged, with no retry within the hour.
   - The `--from/--to` backfill is throttled. Call count: one request (plus pages of 500 ad-hours) per selected account per day, so 13 accounts give 13 calls per hourly run (26 on the 00:10 run, which closes yesterday) and about 320 a day. A 30-day backfill is 390 calls with 3 s pauses, about 20 minutes.
   - A transient answer (code 1/2, 5xx) on an hourly call is still tried up to 3 times, as SS-2 allows. A rate limit or block is never retried.
4. **Merge danger, readers of `cpp_snapshots` inside this repo.** Every reader was found and checked by searching the whole repo (PHP, Blade, JS, Python and SQL; `vendor` and `storage` excluded) for `cpp_snapshots` and `snapshot_bucket`:
   - `app/Http/Controllers/CPPController.php:504`, `timelineData()` (bucket grid): now `whereIn('snapshot_bucket', self::BUCKETS)` (line 506).
   - `app/Http/Controllers/CPPController.php:923`, `timelineDetail()` (popup): only the 4 buckets are accepted (line 919, else 422).
   - `app/Http/Controllers/CPPController.php:436–440`, `saveSnapshot()` (/cpp Copy Table): writes and reads only the bucket from `bucketFor()` (10AM / 3PM / 7PM), so it never touches H-rows. Unchanged.
   - `app/Http/Controllers/CPPController.php:988`, `timelineHourly()`: the hourly view, reads only `HourlySpend::buckets()`.
   - `app/Services/MetaAds/HourlySpend.php:149–157`: the writer; deletes and upserts only H-rows.
   - `resources/views/ads_manager/cpp.blade.php:1008–1033`: calls `saveSnapshot()` only (a toast with the bucket label); it does not read the table.
   - No export, command, hold report, stock report, seeder, config or JS file reads `cpp_snapshots`.
   - Readers outside the repo are the reviewer's to handle.

## Merge danger

- **`cpp_snapshots` gains about 24 × pages rows per day** (bucket `H00`…`H23`, `saved_by_*` null). Inside the repo every reader is filtered (list under Rulings 4). Anything outside the repo that sums `cpp_snapshots` by date without a bucket filter would count them.
- **`Graph`**: the signatures are unchanged, so subclasses still compile. It gains `retrying()` (a copy) and `post()`. **`GraphException`**: the constructor takes 2 more optional arguments.
  - One behaviour difference: a connection failure inside `get()` is now a transient `GraphException` (same message as before). It is retried only on a `retrying()` copy (the sync's insights, ad details, async job and the hourly call).
- **Sync `error_message`** lines are joined by newlines instead of `; `. Anything that splits on `; ` should split on newlines too. The card and the API-runs table read both forms.
- **Sync call count** rises from about 26–40 to about 105–120 per 7-day run (lighter requests). The rate-limit handling is the same; a rate-limited account now also skips its remaining days.
- **The hourly schedule** adds about 320 Meta calls a day, only with write mode on.
- **Bucket view look:** cells are now filled by CPP colour, and the saved / inferred / estimate tints became edges plus a tooltip (Ruling 1).
- The CPP timeline JS gets new state (`view`, `hourDate`, `hourly`). The bucket view's `cellClass()` returns an extra tone class.
- No migration. The cache entries for progress expire after 2 h.
