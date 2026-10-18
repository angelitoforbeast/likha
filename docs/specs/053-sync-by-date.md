# Brief 053: daily partial Botcake sync by subscription date, weekly 45-day read, automatic

## Why

The owner wants the Botcake customer sync automatic and short: a partial read every day, by subscription date, and the larger read once a week. Each page should know which date to start from and which date to read down to.

Facts checked live on Likha (read-only, two largest pages, batches 1, 2, 3, 10 and 30):
- `GET /pages/{id}/customer?page=N` returns **10 customers per call**.
- The list is ordered by `date_subscribed_at`, newest first, with **zero out-of-order** in every batch sampled. `date_subscribed_at` is a **date only** (no time).
- Within one date, the order roughly follows `last_interactive_at` (UTC), but not exactly: 1 to 4 out of order per batch.
- Keys returned: `id, tags, phone_number, full_name, psid, last_interactive_at, disable_bot, disable_at, date_subscribed_at, ad_ids, ad_id`.

The sync runs of the last 7 days on Likha:

| Read | Runs | Batches | Time |
|---|---|---|---|
| window / age | 39 | avg 1,096 | avg 556 s, max 955 s |
| incremental / known_batch | 12 | about 1 | 3–4 s |
| cap (300 batches) | 28 | | about 153 s |

`BOTCAKE_SYNC_ENABLED` is false, so nothing runs by itself. The scheduled commands are in `routes/console.php`: hourly at :15 for incremental, and Sunday 01:00 for `--full`.

The current incremental read has a gap. It stops at the first batch where every PSID is already known. Inside one subscription date the order follows the last interaction, so a new subscriber can sit below a full batch of known customers who chatted again later, and get skipped.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| **A new partial read, "by date", replaces the incremental read** wherever `plan()` would pick incremental: the scheduled daily run, the row **Sync** button and **Sync lahat**. It reads from batch 1 and stops at the first batch whose customers are all subscribed **before** the stop date. It does not stop on a batch of known PSIDs. Stop date = the page's **last cleanly finished date minus 1 day**. A run that hits the 45-day limit stops there, as today. | Ordered by subscription date (checked live); the date-only field needs the overlap; the known-batch stop can skip new subscribers |
| **Each page knows its dates.** Every api run records the newest and the oldest `date_subscribed_at` it read, and whether it finished cleanly down to its stop date (stop reason `date`, `age` or `end`, with no error). Store these in two or three **additive nullable columns on `botcake_psid_import_runs`**. It is a small table, and spec 044 already added `read_kind` and `start_batch` the same way. No new table. "Last cleanly finished date" = the newest date read by the page's latest clean run that started at batch 1 (by date, window or full). | The owner: "alam dapat yung date na sisimulan at last date na kukunin per page"; lean schema |
| **Catching up:** if runs failed for days, the next by-date read simply goes back to the last clean date minus 1 day, never past 45 days. A page with no clean run yet does the 45-day window read, as today (window resume rules unchanged). | No manual Sync lahat needed after an outage |
| **Schedule (Asia/Manila):** **every day at 01:00, the by-date partial** for all pages, and **Sunday at 01:00, the 45-day read** (`--full`) instead of the partial; one run that night, not both. Remove the hourly :15 run. Both stay behind `services.botcake.sync_enabled`. Use `withoutOverlapping()` and `runInBackground()`, so a long Sunday run never delays other scheduled jobs (chatblast auto starts at 05:00). | The owner: daily partial at 1 AM, Sunday 45 days |
| **Incepxion has no `schedule:run` cron.** It runs `chatblast:auto` from the www-data crontab every 5 minutes. Make the command usable from a plain cron line: one command such as `botcake:sync-customers --nightly` that does the 45-day read on Sundays and the partial on other days (Manila date), and exits at once when `sync_enabled` is false. Write the exact cron line in the result. The release adds it on Incepxion; you never touch servers. | Shared codebase, different cron setup |
| **/page-settings shows the dates per page** in the existing sync info of the row: "Huling sync: Okt 17 → Okt 18 · by date · 01:04". In red if the latest run failed, with its reason, and the next stop date ("susunod: mula Okt 17"). Keep it inside the existing row info. Do not touch the flow cells. | Easy to see and debug |
| Matching, inactive marking (only after a clean full or window read from batch 1), the Sync lahat pause/resume/stop, the freshness skip, locks and the token-status writes stay as they are. | Unchanged behaviour |

## Tests

- The by-date read stops at the first batch older than the stop date, and does not stop on known PSIDs.
- The new subscriber below a known batch is stored (the gap case).
- The stop date = last clean date minus 1 day; a failed run doesn't move it; catch-up after failed days; capped at 45 days.
- A page with no clean run does the window read.
- The dates are recorded per run.
- `--nightly` runs the partial on a weekday and the 45-day read on Sunday (fake clock, Manila), and exits when disabled.
- The schedule entries (daily 01:00, Sunday `--full`, no hourly entry).
- The row Sync and Sync lahat use the by-date read.
- The /page-settings row shows the dates and the failed state.

Write tests red first where natural. The whole suite must pass, except the known `ExampleTest` and, in a fresh worktree, `ImportStartTest`.

## Out of scope

Turning on `BOTCAKE_SYNC_ENABLED` (the release does it), server crontabs, any Botcake API other than the customer list, and changes to the flow cells or Test token.

## Final message

- the commits;
- the migration;
- how the stop date is picked;
- the `--nightly` command and the exact Incepxion cron line;
- the schedule;
- what /page-settings shows;
- the test lines and the suite result;
- the review findings and fixes;
- anything the brief got wrong.
