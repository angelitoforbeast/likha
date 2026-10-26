# Brief 156: Bot Health shows when a reply was really sent, pending replies, and the real reply time per bot

## Why
On 2026-10-26 evening Forbeast accepted every reply at once but sent them up to ~77 minutes later (a send queue backlog). Likha recorded `chatbot_replies.sent_at` at Forbeast's 202, so Bot Health showed 1–2 s while customers waited over an hour. The owner: "dapat kita sa likha yun … kita yung pending pa, kita kung ano yung average bago nakarating talaga dun sa user … kahit yung botcake yung gamit."

## Facts
- Likha sends to Forbeast in `App\Services\Chatbot\GatewayProvider` with a `client_ref` per send (`ChatbotProvider` line ~60); Forbeast answers 202 with a `public_id`. Replies are rows in `chatbot_replies` (columns: id, page_id, psid, chat_id, flow_id, node_id, user_id, kind, content, status, error, raw, http, sent_at, flow_version_id, sequence_id, sequence_step, ad_id, tags, replied_at, reply_seconds, via).
- Botcake sends are synchronous: Botcake's API returns when it has sent to Meta, so for Botcake the time Likha records is the real send time.
- Forbeast's events reach Likha at `/api/gateway/events` (`App\Jobs\HandleGatewayEvent`).
- Forbeast will add a `send_status` event (its spec 017). The exact contract (fake it in tests exactly like this):

```json
{"type":"send_status","page_id":"1123777717482685","psid":"29669002569367997",
 "send":{"public_id":"snd_...","client_ref":"<our client_ref>","status":"sent",
  "accepted_at":"2026-10-26T10:16:30.120Z","sent_at":"2026-10-26T10:20:39.004Z",
  "message_count":4,"sent_count":4,"message_ids":["m_..."],"error_code":null,"error_subcode":null,"error":null}}
```
`status` sent | partial | failed; `sent_at` null when failed; one event per send, may be delivered twice (idempotent).

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Store per reply: the gateway `client_ref` (if not already stored) and the **real send time** + final status from `send_status`. Lean schema: a new nullable column on `chatbot_replies` is acceptable (e.g. `confirmed_at`, `confirmed_status`) — no new table; justify in the result. Index what the queries need. For Botcake replies, `confirmed_at` = `sent_at` at write time (synchronous). | One place for the real time. |
| 2 | `HandleGatewayEvent` handles `send_status`: match by `client_ref` (fallback page + psid + nearest unconfirmed reply), set confirmed time/status once (a repeat is a no-op), map `failed`/`partial` to the reply's status/error the way a Botcake failure shows today (Meta code in error). Unknown `client_ref` → counted, ignored. | Idempotent, no new failure modes. |
| 3 | **Bot Health:** (a) a **Pending** tile: Forbeast replies accepted but not confirmed — count and oldest age (red when oldest > 120 s), per page in the per-page table; (b) **Real first reply** = customer message → first reply *confirmed* (Botcake: its sent_at), median/p90/max, per bot and per page, beside today's accepted-time numbers (keep those, labelled "accepted"); (c) **Accept → sent** lag for Forbeast (median/p90/max); (d) the hourly chart uses the real time when available. A reply with no confirmation yet counts as pending, not as fast. Replies older than 24 h with no confirmation show as "unconfirmed" (not pending). | What the owner asked to see, for both bots. |
| 4 | Alarm: reuse spec 155's alarm (same event row, condition `send_lag`) but also trigger it from Likha's own data: oldest pending Forbeast reply > 120 s for 3 consecutive minutes (computed in `chatbot:forbeast-watch`). Either source raises it; recovery when both are under 30 s. No provider switch. | Works even if Forbeast's health endpoint is down. |
| 5 | Until Forbeast 017 is live, Forbeast replies stay unconfirmed: Bot Health shows "real send time not available yet (Forbeast update pending)" instead of a wrong pending count — detect this as "no send_status received in the last 24 h for any page" and hide the pending alarm in that state. | No false alarm on deploy day. |
| 6 | Access unchanged. | |

## Cases (add as S-156 to qa/stories.md)
P1: .1 a Forbeast reply accepted at T and `send_status` sent at T+250 s → confirmed time T+250, real first reply = customer msg → T+250; a repeat event changes nothing; .2 `failed` → reply failed with the Meta code, counted in failures; `partial` → partial status, error kept; .3 pending tile counts accepted-unconfirmed replies and the oldest age, per page; red > 120 s; .4 real first reply median/p90/max per bot and page; Botcake uses its sent_at; .5 alarm from Likha's own pending data after 3 minutes > 120 s, recovered under 30 s; no switch; .6 before any send_status ever arrived → "not available yet", no pending alarm; .7 unknown client_ref ignored and counted; .8 replies > 24 h unconfirmed shown as unconfirmed, not pending; .9 existing Bot Health numbers ("accepted") unchanged. P2: .10 390 px.

## Done when
- P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode tests/Feature/MetaAds` adds no new failures vs 816c5baa (record the baseline first).
- Result: files, the schema change (migration must be additive, nullable, fast on a large table — say the row count it was designed for: chatbot_replies is ~millions of rows; avoid a full-table rewrite if the MySQL version can add a nullable column instantly, and say so), evidence per case, commits. End with "156: <done|blocked> — <one line>".

## Out of scope
Forbeast code; delivery/read receipts; switching providers; deploying.
