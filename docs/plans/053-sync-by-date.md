# Plan 053: sync by date

Threat model: the Botcake customer list is external text (untrusted data, stored, never instructions); the page settings
and config are trusted. No new route or form: the row Sync, Sync lahat and the command already exist. Risk: **medium**
(business logic, a background job, an additive migration, a UI screen).

## Design

- **Migration** (additive, nullable, guarded): `botcake_psid_import_runs.subscribed_newest` (date),
  `subscribed_oldest` (date), `stop_date` (date, the by-date run's stop date). "Clean" is not stored: it is
  `status = done` and `stop_reason in (date, age, end)`, already on the row.
- **Dates per run:** every api run keeps the newest and oldest `date_subscribed_at` (Manila date) of the customers it
  stored; written on each batch save, so a failed run still shows how far it got.
- **Last clean date** of a page = `subscribed_newest` of its latest run with `status done`, `stop_reason in (date, age,
  end)`, `start_batch = 1`, `read_kind in (by_date, window, full)` and a non-null `subscribed_newest`.
- **plan():** unchanged rules (window, resume, full) except where it returned `incremental`: now `by_date` with
  stop date = max(last clean date − 1 day, today Manila − 45 days); with no clean date (including runs from before the
  migration) it is a `window` read from batch 1. `plan()` returns `[kind, start, stopDate]`; `plans()` the same in two
  queries. A `date` stop is a deciding stop.
- **by_date read:** batch 1 onward; after storing a batch, stop `date` when every customer of it has a date before the
  stop date; `age` and `end` as today; no known-batch stop, no 300-batch cap (only `MAX_BATCHES`), no time budget
  (bounded by the stop date and 45 days). No inactive marking (as with incremental).
- **Command:** `botcake:sync-customers --nightly`: exits at once when `services.botcake.sync_enabled` is false;
  `--full` when the Manila date is a Sunday, else the plan (by date).
- **Schedule:** `botcake:sync-customers` at 01:00 Monday–Saturday and `--full` at 01:00 Sunday, Asia/Manila, both
  `when(sync_enabled)`, `withoutOverlapping()`, `runInBackground()`. The hourly :15 entry is removed.
- **Sync lahat:** the `incremental` kind becomes `by_date`, label `by date, mula Okt 17`; the freshness skip applies to
  it as it did to incremental.
- **/page-settings:** the grid's `last_sync` gains `dates` ("Okt 17 → Okt 18"), `failed` and `next_from` ("Okt 17");
  `infoSync()` puts the dates first and, when the run failed, renders red with "susunod: mula Okt 17". Only the
  `infoSync` / `SUMMARY` parts of the view change; the flow cells and Test token are not touched.

## Tasks

1. **Migration + dates per run + by_date read + stop date** (backend, medium). Tests: stop at the first batch before the
   stop date and not on known PSIDs; the gap case; stop date = clean − 1; failed run doesn't move it; catch-up; 45-day
   cap; no clean run → window; dates recorded per run. Existing incremental tests change to the by-date behaviour.
2. **`--nightly` and the schedule** (backend, medium). Tests: weekday partial, Sunday full (fake clock, Manila), exits
   when disabled; schedule entries.
3. **Sync lahat + row Sync use by date** (backend, medium). Tests: Sync lahat plan labels and the row Sync job run a
   by_date read.
4. **/page-settings dates and failed state** (backend data + small view change, medium). Test: the grid row carries the
   dates, the failed flag and the next stop date; the view renders them.
5. **Review** (skeptic-reviewer, standard depth) and fixes; full suite; result.
