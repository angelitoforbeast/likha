# Brief 044: the first sync of a page reads its whole 45-day window

## Why

An incremental sync (`BotcakeCustomerSync::syncPage`, `$full = false`) stops at the first batch whose customers are all known, at the 45-day age window, or at the cap of 300 batches (3,000 customers, `services.botcake.sync_max_batches`). On a busy page the FIRST sync hits the cap, and every later incremental run starts again at batch 1, finds a known batch and stops: customers between 3,000 and the 45-day boundary are never read. Live: a page with about 3,000 customers stored, last stop reason `cap`. Only the weekly `--full` reaches them, and the schedule is off in production.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| A page's sync reads its whole 45-day window (no 300-batch cap, no known-batch stop; stops at `age` or `end`, with the existing `MAX_BATCHES` safety) when the page has never completed a window read (no api run of that page with stop reason `age` or `end`), or when its last api run stopped at `cap` or ran out of time (below). Otherwise it stays incremental as today. This applies to every caller: the row's Sync now, Sync lahat, the scheduled sync and the command. | The owner, 2026-10-17: "Oo, ayusin mo: sa unang sync ng page o kung cap ang huling takbo, basahin ang buong 45 araw nang walang 3,000 na limit." |
| Time budget: the queue worker kills a job at 1800 s (`--timeout=1800`, one worker on `default`). A window read stops itself after about 25 minutes with stop reason `time`, saving how far it got, and the next sync of that page continues from that batch (minus about 5 batches of overlap, since new subscribers push the list down) instead of batch 1, still as a window read, until one reaches `age` or `end`. Store what you need on `botcake_psid_import_runs` (e.g. a nullable `start_batch` column; a small table, a migration is fine). | A busy page can hold more than 25 minutes of batches at 2 requests per second |
| The 30-day "inactive" marking runs only after a window read that covered the whole window in one go from batch 1 (as today for `--full`); a resumed or partial read never marks anyone inactive | A partial read has not seen everyone |
| The run message and the grid's "Huling sync" show the kind of read (incremental / window / window, continued from batch N) and the stop reason (`time` included) | The owner should see why a page is still filling |
| Incremental stays as it is for pages that completed a window read | Fast hourly runs |

## Tests

First sync reads past 300 batches to `age`/`end`; a page whose last run stopped at `cap` gets a window read; a page whose last run stopped at `age`/`end`/`known_batch` stays incremental; the time budget stops with `time` and the next run continues from the saved batch minus the overlap; inactive marking only after a complete window read from batch 1; Sync lahat and Sync now use the same rule; fake clock for time. Red first where natural. Whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

Turning on the schedule; matching; the grid layout beyond the Huling sync text; customers and orders views.

## Final message

Commits; the rule as built (how "never completed" and "continue from" are decided); the migration; longest single job run; test lines and suite result; anything the brief got wrong.
