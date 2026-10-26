# Brief 163: each chat costs Likha less work

## Why
A stress test showed Likha's 4 CPUs full at about 18 chats/s while Forbeast (the gateway) had room. Measured per chat: about 24 database queries reading the page's settings and flow; about 49 chatbot_events rows per customer per day (last 24 h: 447,117 rows for 9,067 customers — run_started 126,585, run_ended 126,585, sequence 98,176, field 35,145, waiting 17,728, ai 13,980, ai_pending 13,977, condition 13,056, …); and every bot reply comes back from Forbeast as 1–2 separate `send_status` POSTs (spec 156/017), each booting the whole app (in the test: 20,400 send_status for 9,900 chats — about two thirds of incoming gateway requests). The owner wants it lighter, but nothing may break and debugging must stay as easy (the Inbox timeline shows lines like "Flow Main Flow started (resumed after wait)", "Custom field value changed — …", "Condition n20 → no match", "AI step n25 → waiting for the answer", "Run ended (waiting for the AI answer)").

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **Accept batched send_status** per the contract below (single events keep working). Process each event exactly as a single one is processed today (same job, same confirmed_at from the event's own sent_at); idempotent per event. | About two thirds fewer incoming requests. |
| 2 | **Cache the page's bot configuration per page** (page settings of provider chatbot/botcake used on the chat path, the flow graph/version used by RunChatbotFlow, the sequence definitions) in the cache store for 60 s, keyed per page/flow version, and **forget it on every write path** (Likha's screens, the 162 Forbeast values API, imports/paste, flow publish, sequence edit). Measure queries per chat before/after in a test (target ≤ 8 for a cached page). A value saved is used by the next message (no 60 s staleness after a save on any path). | Rarely changes, read every message. |
| 3 | **One row per flow run instead of two:** write a single `run` event when the run ends, carrying `started_at`, `ended_at`, the start reason (e.g. "resumed after wait") and the end reason; if a run throws, the failure path writes the row with end reason "failed: <class>". The Inbox timeline, Bot Health, Results and every other reader must show the same lines as today ("… started (…)" and "Run ended (…)") — render both lines from the one row. Readers of old rows (run_started/run_ended) keep working (no data migration). Before changing, list every reader of chatbot_events (grep types) in the result. | Halves the biggest event pair without losing debugging. |
| 4 | **Don't double-write `sequence` events** if the same fact is already in chatbot_replies (sequence_id/sequence_step/flow): render the timeline line from the reply row instead — only if every reader of `sequence` events can be switched safely; otherwise keep them and say why in the result. | Second biggest type. |
| 5 | Keep field, waiting, ai, ai_pending, condition, order, encode/upload events as they are. | Owner: debugging stays easy. |
| 6 | Sequence follow-up logic and timings are not changed (owner: "wag na galawin"), they only benefit from the cache. | Owner. |

## Cases (S-163)
P1: .1 a batch of N send_status events (one page, valid signature) → 200 accepted N, each reply confirmed with its own sent_at; a single event still works; a duplicate event changes nothing; bad signature → 401; mixed pages in one batch → 422; .2 queries per chat on a cached page ≤ 8 (test counts), and a save through each write path is used by the next message; .3 a flow run writes one `run` row; the Inbox timeline renders the same two lines as before for it; old run_started/run_ended rows still render; Bot Health/Results counts unchanged on a fixture with both old and new rows; a run that throws writes "failed"; .4 sequence timeline lines still show (from replies or kept events); .5 existing Chatblast/Botcake/PageSettings suites pass. P2: .6 a local benchmark (1,000 synthetic chats, faked AI/sends) before/after: queries, rows written, chats/s.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` adds no new failures vs 839431d2. Result: readers list, before/after numbers, deploy notes (no migration expected; cache), and the order (this before Forbeast's batching). End with "163: <done|blocked> — <one line>".

## Out of scope
Sequence logic/timing changes; deleting old events (a separate cleanup); Forbeast; deploying.

## Contract (shared with Forbeast spec 023, built in parallel — build only your side)
# Contract: batched send_status from Forbeast to the partner (Likha / Incepxion)

- Only `send_status` events (spec 017) are batched. Message, postback, comment and every other event type stay one POST each, exactly as today (no added latency for customers' messages).
- Forbeast collects a system's pending send_status deliveries and POSTs them together when either 50 are waiting or the oldest has waited 2 seconds (whichever first), to the same webhook URL, signed exactly as today (`X-Gateway-Signature: sha256=<hex HMAC-SHA256 of the raw body>` with the receiving system's secret). Header `X-Gateway-Batch: 1`.
- Body: `{"kind": "batch", "events": [<event>, <event>, ...]}` where each `<event>` is byte-for-byte the same JSON object Forbeast sends today for a single send_status event (same fields: kind/type/page_id/psid/send{public_id, client_ref, status, accepted_at, sent_at, …}, event id). The partner reads `page_id` from each event, not from the top level.
- Signature check for a batch: the partner verifies the signature with the secret of the page of the batch's events. Forbeast only puts events of ONE page in one batch (so one secret applies); different pages → different batches.
- Partner answers 200 when it has accepted every event in the batch (queued), `{"ok": true, "accepted": <n>}`; 4xx/5xx = the whole batch is retried by Forbeast with today's backoff (020's 2-tries rule for 401/403 still applies). The partner must be idempotent per event (the same send_status twice must not change the result: dedupe by the event id or by send public_id + status).
- Timestamps: each event keeps its own `sent_at` / `accepted_at`; the partner records those, never the batch's arrival time.
- Compatibility: the partner accepts both single events (today) and batches. Deploy the partner first; Forbeast's batching is behind env `FORBEAST_SEND_STATUS_BATCH` (default on in production after both are live; off = today's single POSTs).
