# Plan 194: two gateways, each page replies through one and switches on its own

Spec: `docs/specs/194-gateway-per-page.md`. Base 79189d57. Threat model: event, page-values and site requests are
untrusted until their signature checks out against one gateway's own secret; env, config and the owner's page rows are
trusted.

## Design

- **Gateways registry** (`App\Services\Chatbot\Gateways`): `forbeast` (the existing `services.gateway` and
  `services.forbeast_site` config, env names unchanged) and `incepxionbot` (`services.incepxionbot`, env
  `INCEPXIONBOT_*`: `URL`, `KEY`, `WEBHOOK_SECRET`, `TIMEOUT`, `TYPING`, `SITE_KEY`, `SITE_SECRET`, `APP_ID`). A gateway
  with an empty URL is not set up. Shared thresholds (`services.gateway.auto_switch`, `echo`) stay shared.
- **Page rows** (Page | Key | Value): `chatbot.gateway` = `forbeast` | `incepxionbot` (system key, reserved, not a
  group value). Missing = `forbeast`, or the only set-up gateway when `forbeast` is not set up (Incepxion).
  Per-gateway token rows: `forbeast.*` as today, `incepxionbot.token|webhook_secret|token_status|permissions`.
  `ForbeastPageSetup::on($gateway)` serves either set.
- **Inbound**: `/api/gateway/events` is forbeast (unchanged); `/api/gateway/events/incepxionbot` is incepxionbot (the
  webhook URL Likha registers for that gateway). Each checks only its own gateway's secrets, so a body signed with the
  other gateway's secret is 401. The event carries `gateway`; chats record it in `payload.gateway` (only when not
  forbeast, so forbeast data is unchanged). The same message from both is processed once: mid as today; without a mid,
  a gateway-independent key (page, customer, type and Meta's timestamp) before the per-gateway event id. Comments are
  already once per comment id, echoes once per mid. Page values and site calls: verified against each set-up
  gateway's own secret in turn (no mixing), the matching one is the caller.
- **Outbound**: `GatewayProvider`, `GatewayTyping`, `GatewayComments` and the per-page site calls use the page's
  active gateway, its URL and that gateway's page token (or its own global key). `chatbot_replies.via` = the gateway
  name (column widened to 20 by a new migration, needed before incepxionbot is turned on).
- **Watch**: health per page per gateway (counters keyed by gateway; forbeast keys unchanged). A stall of the active
  gateway (same conditions as today) moves the page to the other gateway when it is set up, the page is ready there
  (token ok) and its last health check was good; otherwise to Botcake as today. `inbound` (Botcake got chats, no
  gateway copy) goes to Botcake: both gateways deliver to the same queue, so the other one did not deliver either.
  Every switch is an `auto_switch` event with `gateway` (the one left) and `chosen` (the page's own choice). Switch
  back to the chosen gateway after `back_minutes` of its own healthy streak, only while the last event is a returnable
  switch and nobody changed `provider` or `gateway` since (a manual choice wins). Standby chats run on a gateway to
  gateway switch too (same skip rules), so customers whose reply failed are answered once.
- **Screens**: Pages shows and sets the gateway (only set-up gateways where the page's token works); the Connections
  popup gets an Incepxionbot card when that gateway is set up. Speed, Bot Health, Replies, Results show the gateway
  name; per-bot rows list the set-up gateways.

## Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | Gateways registry, config and env example, page rows and keys, `ForbeastPageSetup::on()` | backend | medium | S-194.1, .9 (setup) |
| 2 | Inbound per gateway: events path and secrets, gateway recorded, dedupe without mid, page values and site calls | backend | high | S-194.3, .6 |
| 3 | Outbound via the active gateway: sends, typing, comment replies, site calls, `via` | backend | medium | S-194.2, .9 |
| 4 | Watch: per-gateway health, switch to the other gateway, Botcake fallback, switch back, history | backend | medium | S-194.4, .5, .7 |
| 5 | Pages screen gateway select and endpoint, Connections card | backend + frontend | medium | S-194.8 |
| 6 | Speed, Bot Health, Replies, Results labels and per-gateway rows | backend + frontend | low | decision 6 |
| 7 | Baseline vs final suites, skeptic review, result | - | - | all |

Tasks share files (the watch, the provider, the controller), so they run in order in this worktree. Each stage is
committed when its tests pass.

## Baseline

`tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/NightRun` on 79189d57: recorded in
the result.
