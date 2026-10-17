# Brief 045: Sync lahat that knows what each page needs, can pause and resume, and shows what it did

## Why

The owner pressed "Sync lahat" (spec 042) and it works (12 pages done, each a full 45-day window read via spec 044, about 10 to 16 minutes per page, about 2,000 orders matched per page). But: (1) every press walks every page from the top of the list again, even a page synced minutes ago; there is one button and no choice; (2) there is no pause, resume or stop: removing the cache state did not stop it, because the running job wrote the state back; (3) the grid shows little about the last run: no totals, no history, nothing about what the next sync of a page will do. The owner: "dapat maidentify mo kung ano mga need namin, partial lang ba, full ba, etc. alangan namang everyday, whole wide ng botcake customer kukunin? ... wala ata nung pause, wala ding resume. wala ding data nung last sync".

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| Each page's next sync is decided by `BotcakeCustomerSync::plan()` as built in spec 044 (window read when never completed / after cap / unfinished; continue from batch N; otherwise incremental). Sync lahat adds a freshness rule on top: a page whose last successful api run ended less than N hours ago (config `services.botcake.sync_all_fresh_hours`, env `BOTCAKE_SYNC_ALL_FRESH_HOURS`, default 6) and whose plan is incremental is SKIPPED by Sync lahat (counted as "skipped: fresh"). Pages that need a window read or a continue are never skipped. The row's own Sync now ignores the freshness rule | The owner: "alangan namang everyday, whole wide ng botcake customer kukunin?" |
| Before pressing, the grid shows per page what its next sync would do, as a read-only column "Susunod na sync": `window (45 araw, buo)`, `tuloy mula batch N`, `incremental`, or `laktaw (sariwa, X oras na)` for Sync lahat, and `walang ID/token` / `token invalid`. Above the grid, a one-line plan of Sync lahat: e.g. `12 window, 3 tuloy, 20 incremental, 6 laktaw (sariwa), 18 walang ID/token`. Computed with grouped queries (no query per page) | "dapat maidentify mo kung ano mga need namin, partial lang ba, full ba" |
| Sync lahat run state is stored in the database, not only the cache, so it survives cache clears and deploys and keeps history: reuse an existing table where it fits (e.g. a `botcake_psid_import_runs` row with its own `source`, plus nullable columns for the page list, index and totals); a new table only if reuse is clearly worse, with the reason in the result. Any migration touches small tables only (never `macro_output` or `from_jnts`) | Lean schema; the cache-only state could not be stopped |
| Controls: Pause (the page now running finishes, no next page is queued; status `paused`), Resume (continues the same run from the next page), Stop (ends the run, status `stopped`; a new press starts a new run). The job checks the stored status before queuing the next page, so Pause/Stop always win over a running job. One run at a time as before. CEO and the roles allowed on /page-settings (NavAccess) may use them, same as the other buttons | "wala ata nung pause, wala ding resume" |
| Run panel above the grid: current or last Sync lahat run: status, started by and when, finished when, pages done / failed / skipped / remaining, current page with its kind and batches read so far, totals (new customers, updated customers, orders matched, ad ids filled), duration; and a link or small table of the last 10 runs. Updated by the existing poll | "wala ding data nung last sync" |
| Each row's "Huling sync" also shows the last run's counts in short form (e.g. `+9,985 bago · 1,279 na-match`) besides time, kind and stop reason | Same |
| Deploy safety: the release will reach a server where a Sync lahat run from the old code is in progress (state in the cache key `botcake-sync-all`, one job per page). The new code must adopt that in-flight run (import it into the stored run state on first sight and continue it), or let it finish untouched; it must never run two pages at once or restart from the first page. Say in the result which you chose and test it | Live run in progress |
| Incepxion runs the same code (one worker, `--timeout=120` on `default`; job-level timeouts still apply). Nothing in this spec may depend on a scheduler cron | Shared codebase |

## Tests

Freshness skip (incremental fresh page skipped; window/continue never skipped; Sync now ignores it); plan column and summary line per kind with grouped queries (query count flat for 60 pages); pause stops after the running page and survives a cache flush; resume continues from the next page; stop ends it; a second press during a run starts nothing; adoption of an old cache-state run; run panel totals and the last-10 history; row counts in Huling sync; access for CEO and a NavAccess role, 404 for others. Red first where natural. Whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

Turning on the automatic schedule; matching rules; customers/orders views; Incepxion-specific code.

## Final message

Commits; the freshness and plan rules as built; where the run state lives (and any migration); pause/resume/stop behaviour; how the in-flight old run is handled at deploy; query counts; test lines and suite result; review findings and fixes; anything the brief got wrong.
