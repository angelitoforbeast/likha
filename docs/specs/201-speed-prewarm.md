# Spec 201: the Speed page opens fast — today's numbers kept warm, Sequences loaded after the page

## Why
The owner: "bat ambagal magload neto? https://likhaaitech.com/chatbot/speed". Measured on production (read-only): `ResponseSpeed::output()` rebuilds two days (the chosen day and the previous one) from the raw tables whenever its Redis cache (`response-speed:v2:<md5 of filters>`, TTL 120 s for today, 1800 s for a past day) has expired. A cold build costs about 11.5 s for today (203 queries, 7.6 s SQL, ~3.9 s PHP, 109 MB peak) and about 34.5 s for a past day (379 queries). Warm: under 10 ms. The biggest piece is the Sequences part: `stepHistory()` (`chatbot_events` type=sequence with page_id IN (~30) and psid IN (chunks of 500), filesort) ~16 s on a past day, plus the two hourly sequence passes ~8 s. php-fpm has max_execution_time=30 and memory_limit=128M. `MetaReplySpeed` (Forbeast HTTP, 5 s timeout, cached) is not the cause now. The owner approved both fixes ("go").

## Stories (S-201)
P1
- .1 Today's default view (no page filter, no bot filter, today) is always served from cache: a scheduled command rebuilds it before it expires (e.g. every 2 minutes, `withoutOverlapping`, `onOneServer` if used elsewhere) and writes the same cache entry the page reads; opening /chatbot/speed with the default filters never triggers a cold build while the scheduler runs. Yesterday's default view (the reference) is kept warm too (it changes rarely: refresh it less often).
- .2 The page itself no longer waits for Sequences: it renders replies, push, AI and Meta sections (and everything else it shows today) from their own reads, and the Sequences section loads from a separate JSON endpoint after the page (same filters, same permission, own cache), with a visible loading state and an error state with retry; nothing else on the page changes.
- .3 A past day or a filtered view still works: the main page opens without the Sequences cost; the Sequences endpoint may take longer but must finish under php-fpm's 30 s limit on the largest recent day (measure on a copy-sized local dataset or explain how it is bounded: e.g. narrower IN lists, one pass instead of two, or `too_large` fallback as today) and keeps spec 198's memory budget.
- .4 Every number the page shows today is unchanged for the same filters (compare old vs new output in a test on seeded data: same JSON for the non-sequence parts, same Sequences result from the endpoint).
- .5 The warm-up command is safe: it skips when the previous run is still going, logs one line per run with its time, never runs heavy work twice at once, and an exception in it does not break the page (the page falls back to building on demand).
P2
- .6 Measure and record in the result: cold and warm time and query count for the main page and for the Sequences endpoint, today and a past day (local, with realistic seeded volumes), before vs after.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Pre-warm the default today view (and yesterday's) from the scheduler. | Owner approved; the person who opens it should never pay the 12 s. |
| 2 | Load Sequences after the page from its own endpoint. | Owner approved; it is about two-thirds of the cold cost. |
| 3 | No new index on chatbot_events in this spec (912 MB, constant writes); if you find an index would be the real fix, write it under Proposed tasks with the EXPLAIN evidence. | Big-table ALTERs are night-only and need the owner. |
| 4 | Same codebase for Likha and Incepxion; the scheduler entry follows how routes/console.php schedules other jobs. | Releases go to both. |

## Threat model and risk
Trusted: signed-in staff with the existing Speed permission (the new endpoint uses the same middleware/permission). Untrusted: query parameters (date, page, bot). Risk tier: medium (a new scheduled job on a busy server; reads on large tables).

## Done when
P1 cases pass with tests; the full tests/Feature suite adds no new failures vs 8506b25c; the result has the measurements, the scheduler entry, deploy notes (no migration expected; scheduler picks it up; any cache key change), and the final line.

## Out of scope
Changing what the page measures or how; new indexes; other pages; deploying.

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
