# Result 194: two gateways, each page replies through one and switches on its own

Status: done. Branch `feat/194-gateway-per-page`, based on 79189d57. Not pushed, not deployed. No real gateway was called (all HTTP faked in tests).

## What shipped

1. **Gateways are configured** (`App\Services\Chatbot\Gateways`).
   - `forbeast` = the existing `services.gateway` + `services.forbeast_site` (same env names, unchanged).
   - `incepxionbot` = `services.incepxionbot`, env `INCEPXIONBOT_*` (mirrors forbeast). A gateway with an empty URL is "not set up".
   - The auto-switch and echo thresholds (`GATEWAY_SWITCH_*`, `GATEWAY_ECHO_*`) stay shared.
   - No new table.
2. **Per page** (Page | Key | Value).
   - `chatbot.gateway` = `forbeast` | `incepxionbot` says which gateway replies when the page's provider is `gateway`.
   - Missing (or naming a gateway that is not set up) = `forbeast`. When `forbeast` itself is not set up, it is the only set-up gateway; this is how Incepxion with only incepxionbot works.
   - Per-gateway page rows: `forbeast.*` as before, and `incepxionbot.token`, `.webhook_secret`, `.token_status`, `.permissions` (secret or system keys like the forbeast ones).
   - `ForbeastPageSetup::on($gateway)` connects, tests and clears either set. Its webhook URL is `/api/gateway/events` for forbeast and `/api/gateway/events/incepxionbot` for incepxionbot.
3. **Inbound from both, processed once.**
   - `/api/gateway/events` is forbeast (unchanged). `/api/gateway/events/incepxionbot` is incepxionbot and returns 503 while that gateway is not set up.
   - Each path checks only its own gateway's secrets (the page's secret on that gateway, else that gateway's global secret), so a body signed with the other gateway's secret gets 401.
   - The job carries the gateway, and a chat from incepxionbot has `payload.gateway = incepxionbot` (forbeast chats are unchanged).
   - The same message from both gateways: the first arrival is processed and the second is counted as `duplicate`.
     - With a mid: deduped by mid, as before.
     - Without a mid, when two gateways are set up: also by a gateway-independent key (page, customer, event type and Meta's timestamp).
     - The ref of an incepxionbot event without a mid is `gw-incepxionbot-event-<id>`, so the two gateways' event ids never collide.
   - Comments (unique comment id) and echoes (mid) were already once.
   - Page values (`/api/gateway/pages/{id}/values`) accept each set-up gateway's page secret, each checked on its own.
   - Site calls (`/api/gateway/site/...`) accept each linked gateway's site secret. `adopt` writes the token under the gateway that signed the call, connects through it, and sets `chatbot.gateway` when that gateway is not the default.
   - `release` deactivates the page's rows on both gateways.
4. **Outbound only via the active gateway.**
   - Sends (`GatewayProvider`), typing (`GatewayTyping`, typing is on or off per gateway) and comment replies (`GatewayComments`) resolve `Gateways::active($page)` and use that gateway's URL and page token (else its global key). Never both.
   - `chatbot_replies.via` records the gateway that sent (`forbeast`, `incepxionbot` or `botcake`). New migration `2026_11_13_100000_widen_via_on_chatbot_replies` widens the column from 10 to 20 characters ("incepxionbot" is 12).
   - Site calls (Owners screen) all use one registry: the first linked gateway (forbeast when both are linked). See "Deviations".
5. **Automatic switch** (`ForbeastWatch`, `chatbot:forbeast-watch`). See "Switch rules".
6. **Screens.**
   - **Pages:** a "via Forbeast / via Incepxionbot" select under the Bot select, shown when the page is on a gateway and more than one gateway is set up with a working token. It saves through `POST /chatbot/gateway` (all or nothing, with history by user). The Bot label, the Bot filter and the Connected column use the gateway name. The auto-switch note says "Auto-switched to Incepxionbot".
   - **Connections popup:** an Incepxionbot card (Save and test, Test, Clear) when incepxionbot is set up.
   - **Speed and Bot Health:** per-bot rows list Botcake, Forbeast and (when set up) Incepxionbot. A page counts under its active gateway's name. Real send times count both gateways' `send_status`. "Switched to Botcake" counts only switches to Botcake.
   - **Results:** has an Incepxionbot "via" row and filter when it is set up.
   - **Replies and Inbox:** show "Incepxionbot".
   - **Live page:** it names no bot, so nothing changed there.
   - Echoes from the Incepxion V2 app (`INCEPXIONBOT_APP_ID`) show as "Bot (Incepxionbot)".
7. **Not changed:** flows, AI, orders, sequence logic, broadcast splitting, existing data.

## Env names to add (names only)

Only for incepxionbot. Nothing changes for a forbeast-only site.

- `INCEPXIONBOT_URL`: the base URL. Empty means not set up, the same as `GATEWAY_URL`.
- `INCEPXIONBOT_KEY`: the global key, the same as `GATEWAY_KEY`.
- `INCEPXIONBOT_WEBHOOK_SECRET`: the global webhook secret, the same as `GATEWAY_WEBHOOK_SECRET`.
- `INCEPXIONBOT_TIMEOUT`: default 10.
- `INCEPXIONBOT_TYPING`: default true.
- `INCEPXIONBOT_SITE_KEY` and `INCEPXIONBOT_SITE_SECRET`: the same as `FORBEAST_SITE_KEY` and `FORBEAST_SITE_SECRET`.
- `INCEPXIONBOT_APP_ID`: the Meta app id of Incepxion V2, used only to label its echoes.

All are listed in `.env.example`.

## Page keys added

- `chatbot.gateway`: `forbeast` | `incepxionbot`. A system key, reserved: not a botfield column and not an ad-group value.
- `incepxionbot.token` (secret), `incepxionbot.webhook_secret` (secret, system), `incepxionbot.token_status` (system), `incepxionbot.permissions` (system).

## Switch rules

- **Stalls.** The active gateway's stall conditions are the same as before, now counted per gateway: failed sends in a row, no answer, a Meta connection error, and failing health checks. The other gateway's health is measured each minute but never switches the page.
- **Where a stalled page goes:**
  1. To the other gateway, when it is set up, the page's token there is "ok", and its last health check was good. If the watcher left that gateway within the last 30 minutes (`back_minutes`), it also needs a full 30-minute healthy streak. This stops a page flipping between two gateways whose sends both fail while their health checks pass.
  2. Otherwise to Botcake, as today.
  3. Otherwise (no Botcake token) no switch, and a `cannot_switch` event once an hour.
- **Inbound.** "Botcake got chats, no gateway copy" goes straight to Botcake: both gateways deliver to the same queue, so the other one did not deliver either.
- **History.** Every switch is an `auto_switch` row in the page history.
  - A switch to the other gateway is `{result: switched, from: forbeast, to: incepxionbot, gateway: forbeast, chosen: forbeast, condition, error}`.
  - A switch to Botcake adds `gateway` and `chosen` to today's event.
  - The watcher's switch writes `chatbot.gateway` (or `chatbot.provider`) with no user, before the event.
- **Answers after a switch to the other gateway.**
  - Standby (Botcake) copies run as before.
  - Gateway chats whose reply failed in the last 15 minutes (`standby_minutes`) are run once more through the new gateway: the last such chat per customer, as a new chat, with the old one marked.
  - A customer is skipped when they have a newer chat, were already answered, still have an AI step or send pending, or the runner has not reached them yet.
  - The reply whose failure triggered the switch is recorded after the switch, so it is answered by the next minute's watcher.
- **Switch back.**
  - The page goes back to its chosen gateway (the gateway before the first automatic switch) after 30 minutes of that gateway's own healthy streak.
  - This works from the other gateway and from Botcake.
  - At most one switch back per `back_gap_minutes`, as today.
  - The event is `switched_back`, with `gateway` set to the chosen gateway.
- **Manual changes always win.** A person who changes `provider` or `gateway` after the event stops the switch back.
- **Ignored when finding the switch to undo:** `held` and `cannot_switch` events, written while the page is on the other gateway.
- **A late failure is not evidence.** A failure from a gateway that is no longer active does not count. The switch re-reads the active gateway from the table under the lock, so another worker's late failure cannot move the page again.

## Deploy notes

- **Forbeast-only (Likha today): no config change needed.**
  - Run `php artisan migrate --force` for the `via` widening. It is a metadata-only change of a varchar on MySQL, and it is needed before incepxionbot is turned on.
  - With only forbeast set up, everything behaves exactly as before, and the existing suites are green.
- **To turn on incepxionbot:**
  1. Set the `INCEPXIONBOT_*` env names and run `config:cache`.
  2. Paste each page's Incepxionbot token in the Connections popup. Likha tests it and registers the webhook `<APP_URL>/api/gateway/events/incepxionbot` with a fresh per-page secret.
  3. Pick the gateway per page on /chatbot/pages if a page should not use the default.
- **Incepxion with only incepxionbot:** set only the `INCEPXIONBOT_*` names. Every gateway page uses incepxionbot.

## What incepxionbot's Forbeast instance must provide

These are the same API as Forbeast, on `INCEPXIONBOT_URL`.

**Calls Likha makes to it:**

- `POST /v1/pages/{page_id}/messages` `{psid, messages, messaging_type, client_ref}`, with the page token (Bearer). Sends.
- `POST /v1/pages/{page_id}/typing` `{psid}`. Typing and seen.
- `POST /v1/pages/{page_id}/comments/{comment_id}/reply` `{message}` and `…/private-reply` `{text}`. Comment replies.
- `GET /v1/pages/{page_id}`. Probe and health: `name`, `id`, `webhook.url`, `permissions`, `connected`, `can_send`, `inbound_lag_seconds`.
- `PUT /v1/pages/{page_id}/webhook` `{url, secret}` and `DELETE /v1/pages/{page_id}/webhook`.
- Site calls, only when it is the site gateway (forbeast not linked): `GET /v1/site/pages`, `GET /v1/site/transfers?page=`, `POST /v1/site/pages/{id}/take`, `POST /v1/site/pages/{id}/transfers`, and `POST /v1/site/transfers/{id}/{accept|decline|cancel}`, with the site key.

**What it must send to Likha:**

- Events to `POST <APP_URL>/api/gateway/events/incepxionbot`.
  - Signed `X-Gateway-Signature: sha256=<HMAC-SHA256(raw body)>` with the page's webhook secret (or `INCEPXIONBOT_WEBHOOK_SECRET`).
  - The same event shape as Forbeast: `mid`, `timestamp` (Meta's, ms), `app_id` on echoes, `send_status` with `client_ref`, `comment` events.
- Page values to `GET/PUT /api/gateway/pages/{id}/values`, signed with the page's incepxionbot webhook secret.
- Site calls to `/api/gateway/site/pages/{id}/…`, signed with `INCEPXIONBOT_SITE_SECRET`.

## Deviations and open questions for the owner

- **Owners (site calls) use one registry,** the first linked gateway (forbeast when both are linked), not the page's active gateway as decision 4 reads.
  - Why: per-page routing split the pending transfers across two registries, which the review found. Each site would see only half of them.
  - Inbound site calls are still accepted from either gateway, and adopt writes the token for the gateway that called.
  - Open: should the two Forbeast instances share one ownership registry?
- **Missing `chatbot.gateway` falls back to the only set-up gateway when forbeast is not set up.** The decision says "missing = forbeast". This refinement only applies where forbeast is meaningless (Incepxion), and it is what makes S-194.9 work.
- **Not verified, needs a live check before relying on failover:**
  - Whether both Meta apps receive `message` events (and not `standby` events under Meta's handover protocol) when a page is connected to both apps.
  - Whether both carry the same `timestamp` for events without a mid.

## Case table

| Case | Test (`tests/Feature/Chatblast/GatewayPerPageTest.php`) | Result |
|---|---|---|
| S-194.1 | `test_S_194_1_without_a_second_gateway…` + the existing suites | pass |
| S-194.2 | `test_S_194_2_a_page_on_incepxionbot_sends_types_and_replies…`, `test_S_194_2b_a_flow_reply_records_the_gateway…` | pass |
| S-194.3 | `test_S_194_3_the_same_message_from_both_gateways_is_processed_once`, `test_S_194_3b_page_values_site_calls_and_echoes…` | pass |
| S-194.4 | `test_S_194_4_a_stalled_gateway_moves_the_page…`, `4b_a_manual_choice…`, `4c_a_late_failure…`, `4d_customers_whose_reply_failed…`, `4e_a_held_event…` | pass |
| S-194.5 | `test_S_194_5_when_both_gateways_stall…`, `test_S_194_5b_sends_failing_on_both_with_good_health_checks…` | pass |
| S-194.6 | `test_S_194_6_an_event_signed_with_the_other_gateways_secret_is_401` | pass |
| S-194.7 | `test_S_194_7_a_page_without_a_working_token…` | pass |
| S-194.8 | `test_S_194_8_the_pages_screen_shows_and_sets_the_gateway` (also the Connections card and its Test) | pass |
| S-194.9 | `test_S_194_9_incepxion_with_only_incepxionbot…`, `test_S_194_9b_a_page_with_only_an_incepxionbot_token…` | pass |

`GatewayPerPageTest`: 17 tests and 187 assertions, all OK.

## Suites: baseline vs final

Command: `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/NightRun`. NightRun is the only other folder with gateway tests.

| Run | Tests | Assertions | Failures | Skipped | PHPUnit deprecations |
|---|---|---|---|---|---|
| Baseline 79189d57 | 1960 | 57328 | 0 | 1 | 67 |
| Final | 1977 | 57515 | 0 | 1 | 67 |

The 17 extra tests are the new ones. No new failures.

## Review

A skeptic review (adversarial on the webhook, signature and watcher paths) found five majors. All were fixed once, with tests:

1. The page flipped between two gateways whose sends both fail while health passes. Fixed: a gateway the watcher left within 30 minutes needs a full healthy streak.
2. A page with only an incepxionbot token was missing from /chatbot. Fixed: the list reads every gateway's token rows.
3. Site calls were split across two registries. Fixed: one registry.
4. A stale second switch could move the page off the new gateway. Fixed: the switch passes the failing gateway and re-reads the active one from the table under the lock.
5. Failed replies were not answered on a gateway-to-gateway switch. Fixed: they are re-run once.

Accepted minors are in `TODO.md` under "194", with reasons. The review found no mixed secrets and no double reply from the two gateways.

## Story changes

S-194 added to `qa/stories.md` from the spec's cases. The extra variants (2b, 3b, 4b–4e, 5b, 9b) came from the code and the review.

## Notes

- **TDD:** the S-194 tests were written before the code, but their red run was not kept. Each later test came with its fix.
- **Kit conflict:** the kit asks for a plan "go" before code. The brief said to plan and proceed, so no reviewer go was awaited.

194: done — pages reply through Forbeast or Incepxionbot (chatbot.gateway), events from both are processed once, sends go only through the active gateway, and the watcher switches gateway → other gateway → Botcake and back after 30 healthy minutes; suites 1977/0 failures vs baseline 1960/0.
