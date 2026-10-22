# Plan 111: each chatbot page runs through Botcake or the Forbeast Gateway, chosen per page

## What exists (read in code)

- **Live outbox:** `Chatbot\BotcakeOutbox::send` (flows, AI replies, sequence steps; all through `FlowEngine::send`/`ai` with the live outbox injected in `ChatbotRunner` and `ChatbotSequences`) reads the Botcake token and calls `BotcakeClient::shared()->sendContent`, then `outcome()` + `record()` (chatbot_replies + tags).
- **Async pool:** `BotcakeSendQueue::async()` decides whether a live send becomes a `botcake_send_requests` ticket for `botcake:send-pool` (Botcake rate slots, Guzzle pool on `BotcakeClient::BASE`). The pool is Botcake's transport.
- **Inbox manual send:** `ChatbotInboxController::send` calls `BotcakeClient::shared()->once()->sendContent` directly.
- **AI queue:** `ChatbotAiQueue::async` checks `instanceof BotcakeOutbox`: that is "live outbox", not "Botcake transport"; it stays true for gateway pages, and the AI reply is sent through the same outbox.
- **Inbound:** `BotcakeChatController::receive` stores a `botcake_chats` row, then: sequence exit on reply, `MarkChatbotReplied`, `RunChatbotFlow` (when the page has a flow). Pages are found by `BotcakeChat::pageMap()` (botcake.page_id → page name). `botcake.page_id` is the id Botcake puts in `{{page_id}}` and in its API paths (`/pages/{page_id}/…`); Botcake keys pages by the Facebook page id, so it is the id the gateway uses. Checked in code only (no live data here).
- **PSID columns:** `botcake_chats.psid` string(64) (fine for any id); `chatbot_replies.psid`, `chatbot_customer_values.psid`, `botcake_send_requests.page_id` are unsigned big integers (break for non-numeric ids; Facebook PSIDs are numeric, 15–17 digits).

## Design

- **Seam** (`app/Services/Chatbot/`):
  - `MessagingProvider` interface: `send(string $pageName, string $pageId, string $psid, array $messages, string $clientRef): array` → `{status: sent|failed, error, http, raw?}` (the `BotcakeOutbox::outcome` shape).
  - `BotcakeProvider`: today's code moved as is: no token → failed `NO_TOKEN`; else `BotcakeClient::shared(sha1(token), 15)` (+ `once(15)` for the Inbox) `->sendContent()` → `BotcakeOutbox::outcome($r, $token)`.
  - `GatewayProvider`: `POST {GATEWAY_URL}/v1/pages/{pageId}/messages` with `Authorization: Bearer GATEWAY_KEY`, body `{psid, messages, messaging_type: RESPONSE, client_ref}`, timeout `GATEWAY_TIMEOUT` (default 10 s), no retry (the ref makes a resend safe later). 2xx → sent (accepted by the gateway). Else failed: `Gateway said "<error.message>" (HTTP n)`, `Gateway refused it (HTTP n)`, or `Gateway did not answer (HTTP 0)`; raw body kept like Botcake's (key masked). Empty URL or key → failed "The Forbeast Gateway is not set up (GATEWAY_URL or GATEWAY_KEY is empty)", no call.
  - `ChatbotProvider` (resolver): `name($page)` = `gateway` only when `chatbot.provider` is `gateway`, else `botcake`; `for($page, bool $once = false)`; `gatewayReady()` = `GATEWAY_URL` not empty.
  - `client_ref` = `likha-` + a ULID per send (≤ 100 chars).
- **Routing:**
  - `BotcakeOutbox::send` → `ChatbotProvider::for($run->pageName)->send(...)` (so flows, AI replies and sequences follow the page). Switch-off and logging unchanged.
  - `BotcakeSendQueue::async` → also requires the page's provider to be `botcake`: gateway pages send directly (the gateway queues, orders per psid and rate-limits itself). A ticket already queued at the moment of a switch still goes out through Botcake (seconds; noted).
  - Inbox manual send → `ChatbotProvider::for($page, once: true)->send(...)`.
  - Chatblast and the pool's Botcake requests: unchanged.
- **Inbound** `POST /api/gateway/events` (`GatewayEventController`): no secret configured → 503 (fail closed, nothing queued); body over 64 KB → 413; `X-Gateway-Signature` (`sha256=<hex>`) checked with `hash_equals` over the raw body → 401 when missing or wrong; not JSON → 400; else queue `HandleGatewayEvent` on the chatbot-high queue and answer `{ok: true}`.
  - `HandleGatewayEvent`: `kind` other than `event`, or `type` echo/delivery/read/standby → counter only. Unknown page → counter. Page on `botcake` → counter `botcake_page` (the shadow count), nothing stored. Bot Off → counter. Dedupe key = `mid`, or `gw-event-<event_id>` when there is no mid: an atomic cache add plus "no `botcake_chats` row has this `message_id`" (so also a Botcake push that stored the same mid). Then store the chat (`message_id` = the key; name from `botcake_psids` for page + psid, else the latest named chat, else blank; message = text, postback title, or `[Photo]`/`[Video]`/… for attachments; payload `source: gateway`, `ad_id` (digits only), `image_url`, `postback`, `quick_reply`) and run the shared after-store step.
  - Shared after-store step: the three blocks of `BotcakeChatController` after `BotcakeChat::create` moved into `ChatbotInbound::afterStore($chat, $page)`, called by both (no fork).
  - Counters: `gateway-events:<date>:<what>` in the cache, 3 days, like the guard's counters.
- **Botcake push on a gateway page:** after the Bot Off check, `{ok: true}`, nothing stored, guard counter `gateway_page` (label "Page uses the gateway") and a log line.
- **UI** (`/chatbot/pages`): `data` gives each row `provider` and the list `gateway_ready`; the Active cell gets a small select "Replies via: Botcake / Forbeast Gateway" (gateway disabled when not ready). `POST /chatbot/provider` (same access as a cell; `botcake`|`gateway`; gateway refused when not ready) writes `chatbot.provider` with `PageSettings::set` (history). `provider` joins `ChatbotFields::RESERVED` (not a botfield column); Changes shows it as "Provider" (it already lists every chatbot key not hidden).
- **Config:** `services.gateway.{url, key, webhook_secret, timeout}` from `GATEWAY_URL`, `GATEWAY_KEY`, `GATEWAY_WEBHOOK_SECRET`, `GATEWAY_TIMEOUT`; names added to `.env.example`.
- **No migration.**

Threat model: the inbound event body is untrusted until its signature checks, and its text stays untrusted data after (stored like a Botcake chat, never run as instructions). The provider select is staff input behind the existing access check, validated against two values. Config and env are trusted. The gateway key and secret are never logged or returned.

## Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | Seam: interface, Botcake and Gateway providers, resolver; outbox, async queue and Inbox route through it | backend | high (new outbound integration) | S-111.1, S-111.2, S-111.3, S-111.9 |
| 2 | Inbound: route, signature, job, shared after-store, dedupe, counters; Botcake push skip on gateway pages | backend | high (new untrusted surface) | S-111.4, S-111.5, S-111.6, S-111.7 |
| 3 | Provider select: endpoint, data, view, Changes | backend + frontend | medium | S-111.8 |
| 4 | Result, stories, `.env.example`, full suite | both | low | all |

## Cases

| Case | Given / When / Then |
|---|---|
| S-111.1 | Given a page on `gateway`, when its flow sends, then one POST goes to `{GATEWAY_URL}/v1/pages/{page_id}/messages` with the bearer key and `{psid, messages, messaging_type: RESPONSE, client_ref: likha-…}`, nothing goes to Botcake, and the reply row is sent with its tags; on a Botcake page the send is as before |
| S-111.2 | Given a page on `gateway`, when the gateway answers 422, then the reply row is failed with `Gateway said "…" (HTTP 422)`; with `GATEWAY_URL` empty nothing is sent and the row is failed "…not set up…" |
| S-111.3 | Given a live send pool, when a gateway page's flow sends, then no `botcake_send_requests` ticket is made and the send goes straight to the gateway |
| S-111.4 | Given a page on `gateway`, when a signed `message` event arrives, then one chat is stored (name from botcake_psids, message_id = mid) and one `RunChatbotFlow` is queued; the same mid again, or a mid already stored by a Botcake push, stores and queues nothing |
| S-111.5 | Given a page on `botcake`, when a signed `message` event arrives, then nothing is stored or queued and the `botcake_page` counter goes up; echo/delivery/read only count |
| S-111.6 | When the signature is wrong or missing → 401; when no webhook secret is configured → 503; nothing is queued either way |
| S-111.7 | Given a page on `gateway`, when Botcake pushes a chat for it, then the answer is 200 `{ok: true}`, no chat is stored, no run is queued, and `gateway_page` is counted |
| S-111.8 | When staff pick Forbeast Gateway in a row, then `chatbot.provider` is saved (the old value kept as history) and appears in Changes; another value → 422; with `GATEWAY_URL` empty, `data` says `gateway_ready: false` and `gateway` is refused |
| S-111.9 | Given a page on `gateway`, when staff send from the Inbox, then it goes through the gateway and is logged as manual |
