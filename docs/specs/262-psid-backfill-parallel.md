# 262: read every Botcake customer of every page, all time, several pages at once

## Why
The owner wants every PSID of every Likha page, with no 45-day limit.

A one-off run started on 2026-11-10 19:18 Manila: `BOTCAKE_SYNC_MAX_AGE_DAYS=100000 php artisan botcake:sync-customers --full`. It is still running, and after 2 hours it is on page 2 of 94. Alexa Angeles alone is at 11,834 batches with 96,075 new customers, at about 0.3–0.6 s per batch.

Three things make it slow:
- BotcakeCustomerSync::withLock is ONE global lock, so only one page runs at a time.
- `--full` always restarts at batch 1.
- MAX_BATCHES = 20000 stops a big page with stop reason "cap".

The owner's words: "edi sabay sabay pero different page".

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Add an all-time backfill mode to the existing command: `botcake:sync-customers --all-time [--shard=K/N] [--page=X]`. It reads each page from batch 1 to the end ("end"), with no age stop and no MAX_BATCHES cap. A failed or killed run resumes from its last reached batch (minus the existing RESUME_OVERLAP) on the next start of the same mode. It never restarts at batch 1 while an unfinished all-time chain exists for that page. | One pass per page, resumable. |
| 2 | Use the existing storage only: botcake_psids and botcake_psid_import_runs, with read_kind "all_time" or a similar new value. Add no new table. Writes and the matching behave exactly as in the existing full read (same upsert, same inactive rule only after a clean read to the end, same matching of orders). | Same data rules. |
| 3 | Lock per page, not global, for all modes. Two processes never read the same page at once. Different pages run in parallel. The nightly scheduled sync (01:00) still works: it skips a page another process holds and goes on. | Parallel by page. |
| 4 | `--shard=K/N` takes the pages whose index in the stable page list (the same order `pages()` returns) satisfies `index % N == K-1`. Without `--shard`, it takes all pages. Pages already finished in all-time mode (a clean "end" chain) are skipped, unless `--again`. | Run N processes, for example 6 at night. |
| 5 | Rate safety: on Botcake 429 or 5xx, back off (reuse the existing handling). Add an optional `--sleep-ms` between batches. Log per-page summary lines like the existing output. | Several tokens at once. |
| 6 | Keep the existing modes (by_date, window, full) unchanged except for the per-page lock. | No behaviour change for the nightly job. |

## Done when
- Tests:
  - two pages run in parallel (the lock is per page);
  - the same page is refused by a second process;
  - all-time resumes after a failure, from its reached batch;
  - no MAX_BATCHES cap in all-time;
  - the shard split is correct and stable;
  - finished pages are skipped;
  - the nightly mode still skips a locked page.
  Each test fails first.
- Full suite: no new failures against 7de7176f (2 known failures).
- The result gives the exact commands to stop the current one-off run safely and to start 6 shards with nohup as the app user. It also says how a page that the current one-off run already started (Alexa Angeles) continues without starting again from batch 1, if the data allows it. Otherwise it says that the page restarts and the de-duplication keeps the data right.
- From production counts, it estimates the total batches left. Use the number of customers per page if available, or the subscribed_oldest dates of earlier runs.

## Out of scope
Changing the nightly schedule, deploying, starting the run.

## Budget
Medium job. At most 2 attempts per failing step.
