# Brief 061: one J&T source, an hourly track-sync that chatblast reads from the database

## Why

The owner, 2026-10-18, confirmed the plan in his own words: "i-automate ang /jnt/track-sync bawat oras pagkatapos ng J&T upload, isulat sa DB ang pinakahuling scan, rider, at numero, at tatanggalin ang sariling tracking ng chatblast para DB na lang ang babasahin nito, iisa na lang ang source ng datos para magamit din ng ibang pages ... eto lang talaga dapat".

How it works today:
- **/jnt/track-sync** (`JntTrackSyncController`, job `SyncFromJntStatusByTrack`) is MANUAL. It runs TRACKQUERY for non-final `from_jnts` rows (not Delivered/Returned) in a submission-date range, through the shared `App\Services\Jnt\JntTracker`. It maps the latest scan to `from_jnts.status` with `status_logs`. Unmapped scans (Problematic and similar) are kept for review and NOT written.
- **Chatblast** calls `JntTracker` itself:
  - during the build or refresh (Delivering candidates, newest first, with a time budget);
  - at send time for Delivering rows (re-track when older than 30 min).

  It uses the latest scan to require "On Delivery", and it fills the rider name and phone for the `{rider}` and `{rider_phone}` placeholders.
- `from_jnts` already has `rider_name` and `rider_phone` columns.
- `from_jnts` is a BIG table on both sites. An `ALTER` on it locks the hourly imports, so the owner's rule is: night only, one combined `ALTER`, and tell him the lock time first.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| **The hourly automatic track-sync.** It runs every hour at :20 Manila, from 06:20 to 23:20, about 15 minutes after the hourly J&T upload, plus one run at 02:20. It covers every non-final `from_jnts` waybill with `submission_time` in the last 60 days, newest first. It is gated by a setting `services.jnt.track_sync_auto` (env `JNT_TRACK_SYNC_AUTO`, default false, so the release can switch it on). It uses a lock, so runs never overlap. It has a per-run time cap of about 40 minutes and resumes the remaining waybills on the next run. It respects the existing 2 calls per second and the 20-per-call limits. It runs from the Laravel scheduler on Likha. On Incepxion there is no scheduler, so drive it from `chatblast:auto` (cron every 5 minutes) with the same hourly claim pattern spec 056 used for the refresh. Each run is recorded in `jnt_track_sync_runs` with a kind of `auto`, so it shows on /jnt/track-sync. | The owner |
| **What it writes.** <br>• The status mapping into `from_jnts.status`, as today. <br>• The **latest scan**: its type, time and place, written for every waybill, including Problematic and other unmapped scans. Problematic does not change the status. <br>• `rider_name` and `rider_phone` from the latest delivery scan, when present. <br>**Where the latest scan lives:** do NOT add columns to `from_jnts` (big table). Store it in a new narrow table `jnt_latest_scans`: `waybill` (unique), `scan_type`, `scan_time`, `scan_place` (nullable), `rider_name`, `rider_phone`, `checked_at`. The `from_jnts` rider columns are also updated when they change. | One source, no big-table ALTER |
| **Chatblast reads the database only.** Remove chatblast's own `JntTracker` calls, both in the build/refresh and at send time. Its rules use the database: <br>• **In transit:** `from_jnts.status` is In Transit. <br>• **Delivering:** `from_jnts.status` is Delivering AND the `jnt_latest_scans.scan_type` maps to On Delivery AND that scan is from today (Manila). <br>• `{rider}` and `{rider_phone}` come from `jnt_latest_scans`, falling back to `from_jnts`. <br>• If a Delivering row's latest scan is older than 90 minutes at send time, the row is skipped for that send time with "J&T data is stale; waiting for the next track-sync" (it is NOT sent, and it stays ready for the next due time). <br>Keep every other chatblast rule unchanged. | DB only, no blind sends |
| **/jnt/track-sync page.** It shows the automatic runs (kind, start, done, counts, errors) and the next run time, and offers an on/off toggle for CEO only that writes a `page_settings`-style setting or a cache flag (don't edit `.env` from the web). The manual run stays as it is. All text is English. Other pages may read `jnt_latest_scans` later; this spec only provides it. | Visibility |
| **No migration on `from_jnts`.** The one migration creates `jnt_latest_scans`. | Big-table rule |

## Tests

- The schedule and the hourly claim (Likha scheduler and the Incepxion `chatblast:auto` path).
- The 40-minute cap and resume.
- A Problematic scan is written to `jnt_latest_scans` but not to the status.
- Rider fields are written.
- Chatblast:
  - builds Delivering only with an On Delivery scan from today;
  - never calls J&T (assert there are no HTTP calls);
  - skips a stale scan;
  - fills rider placeholders from the database.
- The toggle and its access.
- The full suite passes except the known `ExampleTest` and `ImportStartTest`.

## Final message

- The commits.
- The migration.
- The schedule on each site.
- How the cap and resume work.
- What chatblast reads.
- An estimate of the run time for 20k and 80k non-final waybills.
- The test lines and the suite result.
- The review findings.
- Anything the brief got wrong.
