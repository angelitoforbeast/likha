# Brief 113: Forbeast is set up per page with a token, exactly like Botcake

The owner, 2026-10-22: "wala na dapat iset sa forbeast page. need likha/incepxion website mag-ayos, basta maiadd yung token, need maggenerate ng token per page dapat, same sa botcake".

The Forbeast side (its spec 009, built in parallel) follows this contract — treat it as fixed:

(CONTRACT — copied from the shared file)
Base URL `GATEWAY_URL` (e.g. https://app.forbeast.app/api). Auth `Authorization: Bearer <page token>` (prefix `fbp_`), valid only for its own page.
- `GET /v1/pages/{fb_page_id}` → `200 {id, name, connected, webhook:{url,set_at}|null, can_send}` (token test).
- `PUT /v1/pages/{fb_page_id}/webhook` `{url, secret}` → 200; Forbeast then forwards that page's events to url signed `X-Gateway-Signature: sha256=<hex HMAC-SHA256(raw body, secret)>` (same event JSON as today) and runs none of its own automations there.
- `DELETE /v1/pages/{fb_page_id}/webhook` → 204.
- `POST /v1/pages/{fb_page_id}/messages`, `GET /v1/sends/{id}` as today, with the page token.

## Decisions (do not reopen)
| Decision | Why |
|---|---|
| **Per-page key `forbeast.token`** (secret, never shown again — last 4 only, like `botcake.token`) stored with `PageSettings` under the Botcake page settings grid (`/chatblast/page-settings`, provider botcake keys area) AND editable from `/chatbot/pages` row (same access as editing a cell). The page's Facebook page id = its existing `botcake.page_id`. | Same place and feel as the Botcake token. |
| **On save (and a "Test" button):** call `GET /v1/pages/{id}`; show ok + page name or the error (like Botcake token status, store `forbeast.token_status`). If ok, generate a per-page webhook secret (random 64 chars, stored secret `forbeast.webhook_secret`) and `PUT /v1/pages/{id}/webhook` with url `{APP_URL}/api/gateway/events` and that secret. Show "Forbeast connected" in the row. Clearing the token calls DELETE (best effort) and removes both keys. | One paste, everything set up — like Botcake's chat-setup. |
| **Inbound verification per page:** `/api/gateway/events` looks up the page by `page_id` in the event, verifies the signature with that page's `forbeast.webhook_secret`; falls back to the global `GATEWAY_WEBHOOK_SECRET` only if the page has no per-page secret (keeps today's Tech Flask setup working). Unknown page or bad signature → 401. | Each page its own key. |
| **Sending:** `GatewayProvider` uses the page's `forbeast.token` when present, else the global `GATEWAY_KEY`. "Replies via: Forbeast" (single and bulk) is allowed only for pages with a working token (or the global key configured); otherwise disabled with "Add the Forbeast token first". | Safe switching. |
| No migration (page settings rows). `GATEWAY_URL` stays a global env. | Lean. |
| Incepxion runs the same code, so it gets the same behaviour with its own pages and tokens. | Same everywhere. |
## Done when
- Tests (Forbeast HTTP faked): save token → test ok → webhook PUT with the right url and a stored secret; bad token → status shows the error, nothing registered; inbound verified with the per-page secret, wrong page secret → 401, fallback to global for a page without one; send uses the page token; provider switch disabled without a token; clearing the token calls DELETE and removes keys; secrets never rendered (only last 4).
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes.
- Result: files changed, the UI places, and the commits.
