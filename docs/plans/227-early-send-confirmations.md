# Plan 227: a send confirmation that arrives before its reply row

Spec: `docs/specs/227-early-send-confirmations.md`. Base: `b8fc9d63`.

## S1 findings: the cause is the race

**Confirmed from the code (both send paths write the reply row only after the gateway's HTTP answer):**

1. Sequence sends (`chatbot:sequence-pool`, `SequenceSendPool`):
   - `take()` makes the `client_ref` (`ChatbotProvider::ref()`), `issue()` writes it to the *ticket*
     (`botcake_send_requests.result` via `BotcakeSendQueue::calledSequence`) and only then fires the async POST.
   - The `chatbot_replies` row (with its `client_ref`) is written in `respond()` →
     `BotcakeSendQueue::finishSequence()` → `BotcakeOutbox::record()`, when the pool's loop harvests the
     gateway's 202. The pool runs up to 100 sends at once (30 per page); each answer costs several queries
     (ticket update, reply insert, tags, resume dispatch) and they are handled one after another in one
     process, so in a burst the last row of a batch is written seconds after its 202 arrived.
2. Flow replies (`BotcakeOutbox::send` → `GatewayProvider::send`, synchronous): the row is written by
   `record()` right after the POST returns. Same order, smaller window.
3. The gateway (`SendController::store` in the Forbeast repo) stores the send, dispatches the drain and
   answers 202. The drain worker can send to Meta and emit `send_status` (batched,
   `DeliverSendStatusBatch`) before Likha has processed that 202. On faster gateway servers the send
   takes ~1-3 s, so for sequence bursts the event regularly wins.
4. `GatewayEventController` queues `HandleGatewayEvent` on the high queue; its `send_status` branch calls
   `SendStatus::receive()`. `match()` looks the row up by `page_id` + `kind` + `client_ref`; with no row
   yet it falls back to page + PSID for rows *without* a `client_ref` (none today), returns null, and
   `receive()` returns `send_status_unknown`. The job ends; nothing keeps or retries the event, and the
   gateway does not resend it (it got a 200).
5. Why it is not something else: the unconfirmed rows *do* carry the event's `client_ref`, `page_id`
   and `kind = send`, so the same lookup a moment later finds them. The only way the lookup misses a row
   that matches on every column is that the row was not there yet. This also explains the 640-of-670
   share of sequence sends (the pool's serial handling of burst answers).
6. `ForbeastSendHealth::pendingCheck` → `RealSendTime::pending()` counts every unconfirmed gateway reply
   of the last 24 h (unless a later reply to the same PSID is confirmed), so each lost confirmation keeps
   the "slow to send" alarm up for up to 24 h.

**Not verified from here (no server access in this task):** the counters. They are per Manila day in the
cache only (`gateway-events:<YYYY-MM-DD>:send_status_unknown`), not per hour. The decisive check on the
live database, for any unconfirmed row (example ref from the spec):

```sql
-- the reply row's sent_at is Likha's write time (record() uses now()); the event was delivered at 20:00:19 Manila
SELECT id, sent_at, confirmed_status, sequence_id FROM chatbot_replies WHERE client_ref = 'likha-01M6GMG1Q2BNSXFAY2ANR4E5RW';
-- the ticket: called_at (before the POST) and done_at (when the 202 was handled and the row written)
SELECT called_at, done_at FROM botcake_send_requests WHERE result LIKE '%likha-01M6GMG1Q2BNSXFAY2ANR4E5RW%';
```

If `sent_at` / `done_at` is later than the event's delivery, the race is proven for that row. Today's
count: `php artisan tinker --execute="echo cache()->get('gateway-events:'.now('Asia/Manila')->toDateString().':send_status_unknown');"`.

## Design

### S2 keep and retry (backend, medium)

- `SendStatus::receive()` returns a new internal outcome `send_status_not_yet` when the event has a
  `client_ref` and no reply matches. Without a `client_ref` (old shape) it stays `send_status_unknown`.
- `HandleGatewayEvent` (send_status branch): on `not_yet`, re-dispatch the same event with a delay and a
  try number **inside the event array** (`send_try`), so no new job property and old queued payloads
  still run (QueuedJobCompatTest). Delays 5, 15, 40, 60 s (two minutes in all). `queued_at` is dropped
  from the retried event so the delay is not read as queue lag (`ForbeastWatch::ingested`). Each
  re-dispatch counts `send_status_retry`; after the last try it counts `send_status_unknown` as today.
- A repeat (row already confirmed) still returns `send_status_repeat` at once, no retry.

Cases: S-227.2.1 an early event is applied when the row appears; S-227.2.2 after the last try it is
counted unknown and not retried again; S-227.2.3 a repeat changes nothing and is not retried;
S-227.2.4 an event without a client_ref is unknown at once; S-227.2.5 the retried event carries no
`queued_at` and an event from the live release (no `send_try`) is try 0.

### S3 heal the last 24 h (backend, medium)

The gateway has **no read by `client_ref`**: `GET /v1/sends/{id}` takes the gateway's public send id,
which Likha does not store. The only lookup by `client_ref` is the duplicate answer of
`POST /v1/pages/{page_id}/messages`: a `client_ref` this system used before returns 200
`{duplicate: true, status, sent, messages, error, sent_at, …}` and sends nothing. Validation runs first,
so the probe must be a valid send body.

Command `chatbot:heal-send-confirmations {--hours=24} {--apply} {--sleep-ms=500}`:

- Candidates: gateway replies (`via` in Gateways::ALL), `kind` send/manual, `status = sent` with
  `http` 2xx (the gateway answered 202, so it stored the send), `confirmed_status` null, `client_ref`
  set, `sent_at` in the window.
- Dry run (default): counts only, per gateway; no network.
- `--apply`: one POST per candidate to the row's gateway with the original messages (`content`), the
  same PSID and `client_ref`. Only a 200 with `duplicate: true` and a final status (`sent`, `failed`) is
  applied, through `SendStatus::receive()` (the same write, repeat guard and unanswered-question rule as
  a live event). A `queued` / `sending` duplicate is left (its event is still coming). Any other answer
  is counted; a 202 (the gateway did not know the ref and queued it) stops the run at once.
- Paced (`--sleep-ms`, default 500 ms ≈ 120 calls/min) because the probes share the per-system API limit
  with live sends. Prints counts only.

Risk, for the owner's call before `--apply`: if the gateway does not know a `client_ref` that Likha saw
accepted (lost row, or the page's gateway token now belongs to another system), the probe is a real send
of the original message, late. The command stops at the first such answer.

### S4 (P2) not done

The final state is not cheaply known: it costs one gateway call per reply. Per the spec the alarm is
left as is; S2 removes the cause.

## Tasks

1. S2 keep and retry: `SendStatus`, `HandleGatewayEvent`, new test `EarlySendStatusTest` (S-227.2.*). Medium.
2. S3 heal command: new `ChatbotHealSendConfirmations` command and its test (S-227.3.*). Medium.
