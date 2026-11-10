# 263 result: Broadcast to a chosen audience of PSIDs

Status: **slice 1 (S1–S4) done**, committed on `feat/263-broadcast`. Slice 2 (S5) not started: waiting for the go-ahead.

## Files

New:
- `app/Services/Broadcast.php`: input checks, audience SQL and count, flows by name, draft/preview, start, queueing step,
  feeder, pause/resume/stop/cap, test send, progress, and the sender hooks (hold, fail streak, page pause).
- `app/Models/ChatblastBroadcast.php`: the broadcast row (chatblast_campaigns, `mode = broadcast`, its own scope).
- `app/Jobs/QueueBroadcast.php`: queues the rows in batches (and cancels them after Stop), 120 s per run, re-dispatches itself.
- `app/Console/Commands/FeedBroadcasts.php`: `chatblast:broadcast-feed`, every minute (`routes/console.php`).
- `app/Http/Controllers/ChatblastBroadcastController.php`, `resources/views/chatblast/broadcast.blade.php`, routes under
  `/chatblast/broadcast` (same group, `auth` + `allowed_ip`, same NavAccess check, chatblast throttles), nav item "Broadcast".
- `database/migrations/2026_11_19_100000_add_broadcast_to_chatblast_campaigns.php`.
- `tests/Feature/Chatblast/BroadcastTest.php` (32 tests).
- `docs/plans/263-broadcast.md`.

Changed:
- `app/Services/ChatblastSender.php`: a broadcast branch (no sequence, waybill, Ready-per-row or daily-cap checks;
  `broadcastMaySend` = Broadcast::hold, in the job and when the pool starts the row); the flow step sends the row's
  `vars.flow_id` for a broadcast; a broadcast test does not set `test_ok`; `finish()` calls the fail-streak hook; on a
  401/403 broadcast rows are never turned into `ready`, the page pauses in its broadcasts; broadcast rows do not count
  toward the chatblast page daily cap.
- `app/Models/ChatblastCampaign.php`: global scope `mode <> 'broadcast'`, so no sequence query (build, auto, sending
  pages, flow info, lists, find by id) ever sees a broadcast.
- `app/Services/ChatblastReady.php`: a sent broadcast is not proof that the page's own setup works (it may use another flow).
- `app/Http/Controllers/ChatblastController.php`: History "Retry failed" leaves broadcast rows out (they go by the
  broadcast's own cap).
- `app/Support/ChatblastNav.php` + `tests/Feature/Chatblast/ChatblastToSendTest.php` (the nav list now has "Broadcast").

## Schema choice and migration

No new table (decision 5). A broadcast is a `chatblast_campaigns` row, `mode = 'broadcast'`, `kind = broadcast-<id>`,
`active = false`, `pages` = the chosen pages, `days = [[text]]`; the rest (filters, flow name, cap, state, per-page
page_id / flow id / count / audience / paused reason, queue cursor) is in **one new nullable JSON column `broadcast`**.
Sends are `botcake_messages` rows: `kind = 'broadcast'`, `campaign_id`, `waybill = 'BROADCAST'`, `vars = {flow_id, flow_name}`,
`dedupe_key = broadcast:<id>:<facebook page id>:<psid>` (the page id instead of the name: stable and under 140 chars).
Row statuses: `waiting` (in the broadcast, not handed out), then the chatblast ones (`queued`, `sending`, `sent`, `failed`,
`field_mismatch`, `unknown`, `skipped`, `no_config`), and `cancelled` after Stop.

Migration: `chatblast_campaigns.broadcast` JSON (small table, instant) and the index
`botcake_messages_broadcast_index (campaign_id, page_name, status, queued_at)` for progress, the feeder's waiting pick
and its last-hour count. On MySQL 8 InnoDB adding a secondary index is online (INPLACE, no write lock). Timing: not
measured on production (no production access from here; see below). As a guide, InnoDB builds a 4-column secondary
index at very roughly 0.5–1 M rows per 10 s on a small VPS, so a few seconds to under a minute for botcake_messages.
**No index on botcake_psids** (2.3 M rows): the count uses the existing `(page_id, psid)` index.

## Count query and EXPLAIN

Per page (MySQL form):

```sql
SELECT COUNT(*) AS n FROM (
  SELECT b.psid FROM botcake_psids b
   WHERE b.status IN ('available','verified') AND b.subscribed_at < '2026-08-01 00:00:00'
     AND NOT EXISTS (SELECT 1 FROM macro_output mo WHERE mo.botcake_psid = b.psid AND LOWER(TRIM(mo.PAGE)) = 'page a')
     AND b.page_id = '1001'
  UNION
  SELECT b.psid FROM botcake_psids b
   WHERE b.status IN ('available','verified') AND b.subscribed_at < '2026-08-01 00:00:00'
     AND NOT EXISTS (SELECT 1 FROM macro_output mo WHERE mo.botcake_psid = b.psid AND LOWER(TRIM(mo.PAGE)) = 'page a')
     AND b.page_id IS NULL AND LOWER(TRIM(b.page)) = 'page a'
) u;
```

The UNION makes each PSID count once and keeps each branch on one index (no OR across indexes).

EXPLAIN QUERY PLAN (SQLite, the test database, same SQL from the query builder):

```
CO-ROUTINE u
  COMPOUND QUERY
    LEFT-MOST SUBQUERY
      SEARCH b USING INDEX botcake_psids_page_id_psid_index (page_id=?)
      CORRELATED SCALAR SUBQUERY 1
        SEARCH mo USING INDEX macro_output_botcake_psid_idx (botcake_psid=?)
    UNION USING TEMP B-TREE
      SEARCH b USING INDEX botcake_psids_page_id_psid_index (page_id=?)
      CORRELATED SCALAR SUBQUERY 4
        SEARCH mo USING INDEX macro_output_botcake_psid_idx (botcake_psid=?)
SCAN u
```

**Not done: EXPLAIN on production MySQL.** This session could not reach the server (access was refused, as it
should be for a builder). To confirm before use, run `EXPLAIN` on the statement above with a real page id and name:
expected `ref` on `botcake_psids_page_id_psid_index` for both branches and `ref` on `macro_output_botcake_psid_idx`
for the subquery. The cost of the second branch is the number of rows with `page_id IS NULL` (it scans them all once
per page); if that number is large on production, run the page_id backfill first.

The queueing reads the same branches by keyset on the same index (`… AND b.psid > :last ORDER BY b.psid LIMIT 1000`).

## Queueing in batches

`Start` re-counts; the confirmed total must match (else the new preview is saved and the start refused). Draft →
sending happens in one locked step, so a second Start does nothing. `QueueBroadcast` (chatblast queue) reads 1,000 PSIDs
at a time per page and branch and `insertOrIgnore`s them as `waiting`; the cursor is saved after each batch; after 120 s
it dispatches itself again. No `INSERT … SELECT`, no CTAS, nothing in the web request.

## How pacing shares the pool

`chatblast:broadcast-feed` (every minute, and after each queue run and on Resume) moves per page
`min(cap − rows handed out in the last hour, 20 − rows in flight)` from `waiting` to `queued` and dispatches the normal
`SendChatblastMessage`. From there it is the chatblast path: the `chatblast` queue, the `botcake:send-pool` ticket at
priority 2 (behind the bot's replies at 0 and Delivering at 1), the same lane per token with `chatblast_share`, the
customer lock and gap, CustomerReach, and BotcakeClient's 429 backoff. At most 20 broadcast rows per page are ever
waiting in that queue or pool, so waybill updates and the chatbot keep their share. The global chatblast Pause also
stops the feeder and the rows. Broadcast sends do not use up the chatblast page daily cap (3,000).

Auto-pause per page: 20 `failed`/`field_mismatch` in a row (counter per broadcast and page, reset by a `sent`), an
answer with #2022 / 1893063 / "restricted|blocked from messaging", a 401/403, or the page not Ready; the page's reason
shows in progress, the other pages go on. Resume clears the page pauses and re-dispatches the `queued` rows; `unknown`
is never re-sent.

## Tests

`tests/Feature/Chatblast/BroadcastTest.php`: 32 tests, 170 assertions, all pass. Every case of S1–S4 has a test
(S1.1–S1.5, S2.1–S2.3, S3.1–S3.2, S4.1–S4.5), plus: other filters, removed flows, text rules, text exactly as written,
count changed since preview, restricted page, stop while a row waits in the job, pause/resume, unknown not retried,
chatblast pause, a 1,005-row audience queued in batches with only 20 handed out, isolation from sequences and the
daily cap, access, page render.

Full suite, PHP 8.4, sqlite:
- base 3191ed41: 4960 tests, 2 failures (the two known: ExampleTest::test_the_application_returns_a_successful_response,
  ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order), 6 skipped.
  That run also had 5 errors in SpeedPrewarmTest, caused only by running it with `-d memory_limit=-1` (the command's own
  `ini_set` then fails); without the flag they pass, as on this branch.
- this branch: 4992 tests (+32), 2 failures (the same two), 0 errors, 6 skipped. **No new failures.**

## S5 answer shape

Not checked yet (slice 2).

## Story changes

- Pages that are not Ready for chatblast (ChatblastReady) are listed with the reason and left out, like a page
  without the flow; a page that stops being Ready while sending pauses.
- A broadcast text may not contain `{…}`: nothing fills placeholders in a broadcast.
- Date filters: "before" is strictly before the day (Manila), "on or after" includes it. Rows without the date are out
  when that filter is set. "Phone in Botcake" is the length of `phone_numbers`.
- The dedupe key uses the Facebook page id for `<page>`.
