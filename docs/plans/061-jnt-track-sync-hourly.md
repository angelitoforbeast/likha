# Plan 061: hourly J&T track-sync, chatblast reads the database

Threat model: the J&T answers are external text (data only; written to the database, never used as instructions). The
toggle is a new web action (CEO only, CSRF, POST). Everything else runs from the scheduler or cron (trusted).

## Design

- **Table `jnt_latest_scans`** (the one migration, guarded with `Schema::hasTable`): `waybill` unique, `scan_type`,
  `scan_time`, `scan_place` nullable, `rider_name`, `rider_phone`, `checked_at`, timestamps. One row per waybill that J&T
  answered: the raw latest scan (details[0], also Problematic), the rider of the latest On Delivery scan (null when the
  answer has none), and `checked_at` = when the answer came. A failed call leaves the old row untouched, so `checked_at`
  never says "fresh" for data that was not refreshed. The same migration adds a nullable `kind` to the small
  `jnt_track_sync_runs` table (auto runs: `auto`). Nothing on `from_jnts`.
- **`JntTracker::refresh()`** (no caller left after chatblast stops tracking) becomes the engine of the auto run: the
  same 20 per call, single retries, 2 calls per second; it also upserts `jnt_latest_scans` per chunk, counts unmapped and
  unchanged, records `kind = auto`, and writes progress to the run every 25 chunks.
- **`App\Services\Jnt\JntAutoTrackSync::runIfDue()`**: off (setting) → nothing. Due when the Manila hour is 02 or
  06–23 and the minute is 20 or later; the hour is claimed with `Cache::add('jnt-auto-track-slot:Y-m-d H')` (spec 056
  pattern), then a lock (`Cache::lock`, 50 min) so runs never overlap. The list: distinct waybills of non-final
  `from_jnts` rows (status NULL or not Delivered/Returned) with `submission_time` in the last 60 days, newest first
  (`submission_time` desc, `id` desc).
- **Cap and resume:** 40 minutes per run (no new J&T call after 40 min minus the 10 s call timeout). When the cap stops a
  run, the run's `result_sample.resume` keeps the position (`submission_time`, `id`) of the first waybill not reached.
  The next run starts there (the rest, still newest first) and then wraps to the newest ones, until the cap again.
- **Command `jnt:track-sync-auto`** calls `runIfDue()`.
  - Likha: `Schedule::command('jnt:track-sync-auto')->cron('20 2,6-23 * * *')`, Manila, background, without overlapping.
  - Incepxion (no scheduler): `chatblast:auto` (cron every 5 min), when the hour is due and not yet claimed, starts
    `php artisan jnt:track-sync-auto` as a detached background process (Laravel `Process`), so a 40-minute run never
    holds up chatblast's sends. On Likha this path runs too (the scheduler runs `chatblast:auto`); the claim inside the
    command makes the second start exit at once.
- **The setting:** `services.jnt.track_sync_auto` (env `JNT_TRACK_SYNC_AUTO`, default false), overridden by
  `app_settings.jnt_track_sync_auto` ('1' / '0') once the CEO uses the toggle on /jnt/track-sync.
- **Chatblast** loses every `JntTracker` call (build, refresh, send). It reads `jnt_latest_scans` (and `from_jnts`
  for the rider fallback):
  - campaigns with "requires On Delivery" build a waybill only when its scan is On Delivery and `scan_time` is today
    (Manila); unsent rows whose scan is not (any more) On Delivery today are skipped with the reason, as before;
  - `{rider}` / `{rider_phone}`: `jnt_latest_scans` first, else the waybill's `from_jnts` rider columns;
  - at send time: no scan row or `checked_at` older than 90 minutes → the row stays `ready` with "J&T data is stale;
    waiting for the next track-sync" (not sent); not On Delivery today → skipped (key released), as before; else the
    text is rebuilt from the DB rider.
  - `build()` loses its `$trackBudget` parameter (and `TRACK_BUDGET`, `SEND_TRACK_BUDGET`, `RIDER_MAX_AGE`,
    `WEB_TRACK_BUDGET`); `tracked` in the build result = waybills with a DB scan; `rider_pending` goes.
- **/jnt/track-sync page:** the top card lists the last automatic runs (kind, start, done, checked, status changed,
  unmapped, no answer, rider, error, capped), the next run time and On/Off; CEO sees a toggle button (POST
  `/jnt/track-sync/auto`, 403 for other roles). English text. The manual run is unchanged.

## Tasks

| # | Task | Side | Risk | Cases |
|---|------|------|------|-------|
| 1 | Migration, `JntTracker::refresh` writes `jnt_latest_scans`, `JntAutoTrackSync` (setting, slot claim, lock, list, cap, resume), command, Likha schedule, `chatblast:auto` start | backend | medium | S-061.1–S-061.6 |
| 2 | Chatblast reads the DB: build, refresh, send; no J&T calls | backend | medium (raised: it decides what customers receive) | S-061.7–S-061.10 |
| 3 | /jnt/track-sync: automatic runs, next run, CEO toggle | backend + frontend (one Blade card) | high (new POST route) | S-061.11 |

Tasks share `JntTracker` and the test base, so they run in order, not in parallel. One review (skeptic-reviewer,
adversarial depth on the toggle, standard on the rest) after task 3, before the result.

## Run time estimate

Per call: J&T latency (measured nowhere here; assume 0.5–1.5 s) but at least 0.5 s (2 calls per second), plus about
20–40 ms of DB writes per 20 waybills. 20 waybills per call when all answer; each dropped waybill costs one more call.

- 20k waybills = 1,000 calls: about 8–25 minutes. One run.
- 80k waybills = 4,000 calls: about 35–100 minutes. Usually capped at 40 minutes; the rest is checked first in the next
  run, so every waybill is checked within two runs (about 2 hours).
