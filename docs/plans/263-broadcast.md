# Plan 263: Broadcast to a chosen audience (/chatblast/broadcast)

Spec: docs/specs/263-broadcast.md. Slice 1 (S1–S4) first, committed and reported before slice 2 (S5).

## Schema choice (spec decision 5)

Precedent checked: a sequence is a `chatblast_campaigns` row whose `kind` keys its `botcake_messages`; the global
chatblast pause lives in `app_settings`; per-page facts live in `page_settings` (Page | Key | Value).

Chosen: **no new table.**

- The broadcast definition is a `chatblast_campaigns` row with `mode = 'broadcast'` (kind `broadcast-<id>`,
  `pages` = chosen pages, `days` = `[[text]]`, `statuses` = `[]`, `window_days` = 0, `active` = false). The rest
  (filters, flow name, per-page flow ids, hourly cap, state, per-page pause reasons, audience counts, queueing cursor)
  goes in **one new nullable JSON column `broadcast`** on that small table. Overloading `send_range` or `daily_cap`
  would hide meaning in columns that mean something else to the sequence code.
- Every existing sequence query must never see a broadcast row (build, auto, sending pages, flow info, lists). A
  global scope on `ChatblastCampaign` (`mode <> 'broadcast'`) does that in one place; `ChatblastBroadcast` is a second
  model on the same table with the opposite scope. `active = false` is a second guard.
- Sends are `botcake_messages` rows: `kind = 'broadcast'`, `campaign_id` = the broadcast id, `waybill = 'BROADCAST'`,
  `vars` = `{flow_id, flow_name}`, `text` = the content, `dedupe_key = broadcast:<id>:<page_id>:<psid>` (unique, so a
  double click, a retry or a re-run of the queueing job never makes a second row). The Facebook page id is used for
  `<page>`: it is stable and keeps the key under the 140-character column.
- One index on `botcake_messages (campaign_id, page_name, status, queued_at)` for the progress GROUP BY, the feeder's
  "waiting" pick and its "handed out in the last hour" count. Online on MySQL (ALGORITHM=INPLACE, no write lock).

## Audience and count (S1)

Per page, two index-ranged branches joined by UNION (so a PSID is counted once, and no OR across indexes):
`page_id = <id>` and `page_id IS NULL AND LOWER(TRIM(page)) = <key>` (the rows not yet backfilled), both on the
existing `(page_id, psid)` index, plus `status IN ('available','verified')` and the optional filters:
subscribed / last interaction before and on-or-after a Manila date, phone has / has none (`phone_numbers` JSON
length), with / without an order (`EXISTS` on `macro_output.botcake_psid` (indexed) for the same page key).
`SELECT COUNT(*) FROM (… UNION …)`. EXPLAIN goes in the result.

## Queueing in batches (S4)

`Start` only flips the state and dispatches `QueueBroadcast` (queue `chatblast`, chatblast connection). The job
walks each page by keyset on the index order: `WHERE page_id = ? AND psid > :last ORDER BY psid LIMIT 1000`
(then the same for the NULL-page_id branch), and `insertOrIgnore`s 1,000 rows at a time with status `waiting`. The
cursor (page, branch, last psid) is saved in the JSON after each batch; after ~120 s the job dispatches itself again
from the cursor, so a 1 M audience never runs in the web request and a killed job resumes where it stopped. No
`INSERT … SELECT`, no CTAS.

## Pacing and the shared pool (S4)

`waiting` rows are not sent by anything until the feeder hands them out. The feeder (`chatblast:broadcast-feed`,
every minute, and right after each queueing batch, on resume) moves, per broadcast and page,
`min(cap − handed out in the last hour, 20 − in flight)` rows from `waiting` to `queued` and dispatches the normal
`SendChatblastMessage` job for each. So:

- the per-page cap is a rolling hour on `queued_at` (default 300, editable);
- at most 20 broadcast rows per page are in the chatblast queue or the send pool at any time, so the waybill
  chatblast and the chatbot keep their share: the job goes through the same `botcake:send-pool` ticket (priority 2,
  behind the bot's replies and Delivering), the same lane per token, `chatblast_share`, customer lock and gap, and
  the Botcake 429 backoff; nothing in the pool changes except that the flow id comes from the row;
- broadcast rows do not count toward the chatblast page daily cap, and the global chatblast Pause stops them too.

`ChatblastSender` gets a broadcast branch: no sequence, waybill or daily-cap checks; it checks the broadcast state
(stopped → cancelled; paused or page paused → stays queued), the token, the customer lock and gap, `CustomerReach`,
then the same three steps. `stepper()` sends `vars.flow_id` for a broadcast row. A broadcast test send does not mark
the page Ready (another flow may have been tested).

Auto-pause per page: a fail streak counter per broadcast and page (20 `failed`/`field_mismatch` in a row), or a
restricted/blocked answer (#2022, 1893063, "restricted"/"blocked from messaging"), or 401/403, pauses that page with
the reason; the others go on. Stop cancels `waiting` and `queued`; a row already sent stays sent. Resume clears the
pauses and re-dispatches the broadcast's `queued` rows.

## Build order

1. Tests for S1 (audience count, per-page, UNION dedupe, order filter per page, no page, no filter warning).
2. Migration + models + `Broadcast` service (audience, flows, save, preview).
3. Tests for S2/S3, then flow resolution and test send.
4. Tests for S4, then queue job, feeder, sender branch, pause/resume/stop, progress.
5. Controller, routes (same middleware and throttles as the chatblast send routes), view, nav link.
6. Full suite against the base 3191ed41; commit; report.
