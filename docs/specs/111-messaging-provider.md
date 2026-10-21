# Brief 111: each chatbot page can run through Botcake or through our own Forbeast Gateway, chosen per page

## Why

The chatbot (flows, AI, sequences, results) receives chats from Botcake (`POST /api/botcake/chat`, pushed by a JSON API block in each page's Default reply) and sends replies through Botcake's API (`BotcakeClient::sendContent`, `BotcakeOutbox`, `BotcakeSendQueue` + `botcake:send-pool`, the Inbox manual send). The owner now has his own gateway (the "Forbeast Gateway", a separate app at api.forbeast.app): it holds the Meta app permissions and does only messages in/out for permitted systems. He wants to choose per page whether a page's chatbot uses Botcake or the gateway, so Likha keeps working if Botcake fails, and later switches automatically. The owner, 2026-10-22: "may switch na ba tayo kung botcake or forbeast gagamitin?" … "yes".

A read-only survey of this repo (2026-10-21) found: all outbound goes through `app/Services/BotcakeClient.php` (`sendContent` for chatbot replies), except `BotcakeSendPool` which builds its own Guzzle request from `BotcakeClient::BASE` and reuses `slot()`/`sentAt()`/`retryWait()`/`mockAnswer()`; `Chatbot\Outbox` (implemented by `BotcakeOutbox`) is a half-seam, but `FlowEngine::send`, sequences and the runner type-hint `BotcakeOutbox`, `ChatbotAiQueue` checks `instanceof BotcakeOutbox`, and `ChatbotInboxController` and `BotcakeChatGuard` call the client directly; PSIDs are cast to int in places (`BotcakeChatController.php:157`, chatbot tables). Verify each before relying on it.

## Gateway API (the other side; treat as the contract)

- Auth: `Authorization: Bearer gw_…` (per system key).
- Send: `POST {GATEWAY_URL}/v1/pages/{fb_page_id}/messages` JSON `{psid, messages:[{type:"text"|"image"|"video"|"file", text|url}], messaging_type:"RESPONSE", client_ref}` → `202 {id, status}`; `GET /v1/sends/{id}` for status; sends are idempotent on `client_ref`; per-psid order and a 40/s per-page limit are enforced by the gateway; 422 when the page is not in `send` mode for this system. `GET /v1/pages` lists the system's pages and modes.
- Inbound: the gateway POSTs normalised events to the system's webhook URL, signed `X-Gateway-Signature` = HMAC-SHA256 of the raw body with the system's webhook secret: `{page_id, psid, type: message|postback|referral|echo|delivery|read|standby, text, attachments, referral:{ad_id, ref, source}, mid, timestamp, is_echo, app_id}`. Retries on non-2xx.
(The gateway repo is at C:/mira/mira/projects/forbeast-gateway — read its docs/specs/001-gateway-mvp.result.md "API" section for exact shapes; do not modify that repo.)

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **One seam:** a `MessagingProvider` interface (send messages for a page+psid with a client_ref; read the send outcome in the shape `BotcakeOutbox::outcome` gives today) with `BotcakeProvider` (today's behaviour, unchanged: wraps BotcakeClient/outbox/send-pool) and `GatewayProvider` (the API above). Route every chatbot send path through it: FlowEngine sends, the async send queue/pool, sequences, the Inbox manual send. Chatblast (delivery updates via Botcake flow + custom field) stays on Botcake in this spec. | Switchable, no behaviour change on Botcake pages. |
| **Per-page switch:** page setting `chatbot.provider` = `botcake` (default) or `gateway`, stored via `PageSettings` (history kept, like other keys). Shown and editable on `/chatbot/pages` as a small select in the row (same access as editing a cell), with its change in the existing Changes log. The page's Facebook page id for the gateway = the page's existing Botcake `page_id` (same FB page id) — verify that they are the Facebook page ids. | The owner chooses per page. |
| **Inbound from the gateway:** `POST /api/gateway/events` (signature verified with `GATEWAY_WEBHOOK_SECRET`, fail closed; 200 fast; queue the work). For `type=message`/`postback`/`referral` on a page whose provider is `gateway`: create the same `botcake_chats` row the Botcake push creates (name from `botcake_psids`/earlier chats by page+psid when known, else blank) and dispatch the same jobs (`RunChatbotFlow`, replied marking, sequence exit on reply) — reuse the code path, don't fork it. Dedupe by `mid` (also against a Botcake push of the same message). For a page whose provider is `botcake`: store nothing that runs a flow (optionally count it for a later shadow comparison — a counter or a light log row, your plan decides; no flow runs). Echo/delivery/read events: ignore for now except a counter. | Same pipeline, no double replies. |
| **Botcake pushes for a `gateway` page** are accepted (200) but do not start a run (logged as skipped), so a page never gets two replies. | No double replies. |
| **PSIDs as strings** end to end in any code this spec touches (no `(int)` casts on psid); keep DB columns as they are if they already hold the values (they are numeric today); note any column that would break for non-numeric ids. | Gateway sends real PSIDs; keep it safe. |
| **Config/env (names only):** `GATEWAY_URL`, `GATEWAY_KEY`, `GATEWAY_WEBHOOK_SECRET`, `GATEWAY_TIMEOUT`. If `GATEWAY_URL` is empty, the `gateway` option is disabled in the UI and sends fail closed with a clear reason. | Safe until configured. |
| **Results/tags (spec 100)** keep working for gateway sends (same reply rows and tags). | Data stays complete. |
| **Out of scope:** automatic switching, chatblast through the gateway, Utility templates, renaming tables. | Next specs. |

## Done when

- Tests (gateway HTTP faked): Botcake pages behave exactly as before (existing suites green); a page switched to `gateway` sends replies via the gateway with client_ref and records reply rows; inbound gateway message on a gateway page creates the chat and runs the flow once (dedupe by mid, also vs a Botcake push); Botcake push on a gateway page does not run a flow; signature bad → 401/403, missing secret → fail closed; provider select saves with history and appears in Changes; UI disables `gateway` when GATEWAY_URL is empty; Inbox manual send uses the page's provider.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, plus the new tests; full suite: no new failures.
- The result lists every send path and how it now routes (file:line), the env keys, any migration (none expected; release needs the owner's yes if any), and the commits.
