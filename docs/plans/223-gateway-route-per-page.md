# Plan 223: gateway address per page, and why a send got no answer

## What is already there (base `ece5a6a3`)

Spec 194 already chooses a **gateway** per page (`chatbot.gateway` = `forbeast` | `incepxionbot`), each with its own
env set (`GATEWAY_*`, `INCEPXIONBOT_*`), its own page tokens (`forbeast.token`, `incepxionbot.token`), its own events
webhook and its own automatic switch. This spec is different: one gateway (the `forbeast` one, `services.gateway`)
answering on more than one **address**, with the same API key and the same page token. So the new setting is a layer
inside the `forbeast` gateway, and the `incepxionbot` gateway is untouched.

## Design

- **Config (S1).** `services.gateway.urls` = `GATEWAY_URLS`, a list of `key=url` separated by spaces or commas, e.g.
  `GATEWAY_URLS="incepxion=https://incepxionbot.com/api"`. Keys are lower-case `[a-z0-9_-]`, URLs `http(s)://`, the
  trailing `/` is dropped and a path prefix is kept, like `url`. The key `forbeast` is always `services.gateway.url`
  (`GATEWAY_URL`): a `forbeast=` entry in the list is ignored, so the default can never drift from today's. Bad entries
  are skipped. `Gateways::routes()` returns `[key => url]`, `forbeast` first.
- **Page route (S2).** Row `chatbot.gateway_app` (system row, like `chatbot.gateway`; added to `ChatbotFields::RESERVED`
  so it is never a botfield). `Gateways::route($page)` = the key when it is in `routes()`, else `forbeast`; an unknown
  key logs one warning per page and value per day. `Gateways::pageUrl($gateway, $page)` = the route's URL for the
  `forbeast` gateway, `url($gateway)` for any other. Used by every call under `/v1/pages/{id}`: `GatewayProvider::request`
  (sends and the sequence pool), `GatewayTyping`, `GatewayComments` (reply, private reply), `ForbeastPageSetup`
  (connect, probe, retest, disconnect) and `ForbeastWatch::health`. Site calls (`/v1/site/...`: orders, AI usage/load,
  owners), `ForbeastSendHealth` and "is the gateway set up" checks keep `url`.
- **409 `app_not_connected` (S3).** `GatewayProvider::outcome()` gains an optional route: a 409 whose error code is
  `app_not_connected` (string `error`, `error.code` or `code`) is `failed` with "This page is not connected to the <key>
  Meta app on the gateway (HTTP 409)". It is an answer, so the watcher counts it as a refused send (failed_sends), never
  as no answer. No retry anywhere (there is none today).
- **UI (S4).** `/chatbot/data` adds `gateway_app` per row and `gateway_apps` (`key => label`) when more than one
  address is configured. New `POST /chatbot/gateway-app` (same access and throttle as `/chatbot/gateway`, all or
  nothing over 1..N listed pages, key must be configured), written with `Auth::id()`. On the Pages screen an
  "App: …" select next to the gateway select, shown only with more than one address and when the page's active gateway
  is `forbeast`.
- **Watcher (S5).** No change needed: the move to Botcake and back writes `chatbot.provider` (and `chatbot.gateway`)
  only. A test pins that `chatbot.gateway_app` survives and the next send uses its address.
- **No answer detail (S6).** `GatewayProvider::noAnswer(Throwable)` reads the cURL errno, error text and
  connect/total time from the Guzzle handler context (falling back to the `cURL error N: …` text of the message), and
  never the URL query, token or body. The failure becomes "Gateway did not answer (HTTP 0) · cURL error 28: … ·
  connect 0.01 s, total 10.00 s" and one `Log::warning('gateway send: no answer', …)` with page, gateway, route, host,
  errno, error and times. Same for the sequence pool's rejected promise. `GATEWAY_FORCE_IPV4` (default off) adds
  Guzzle's `force_ip_resolve => v4` (= `CURLOPT_IPRESOLVE` V4) to every gateway page call through
  `Gateways::httpOptions()`.

Jobs: no queued payload changes (`GatewayAfterSend` keeps its shape), so `QueuedJobCompatTest` needs no new case.

## Tasks

| # | Task | Cases | Tier | Side |
|---|---|---|---|---|
| 1 | Routes config, page route, page URL in all page calls | S1, S2 | high | backend |
| 2 | 409 app_not_connected outcome | S3 | medium | backend |
| 3 | Route select: data, endpoint, Pages screen | S4 | medium | backend + frontend |
| 4 | Watcher keeps the route | S5 | medium | backend |
| 5 | No-answer detail and IPv4 switch | S6 | medium | backend |

Tests: `tests/Feature/Chatblast/GatewayRoutePerPageTest.php`, one test per case, named `S_223_<n>`.

## Accepted for now

- The burst hold of the watcher is per gateway, not per address: a burst on one address also holds pages on the other.
  Noted in `TODO.md`.
- Comment replies answered with 409 `app_not_connected` show the gateway's code ("said app_not_connected"), not the
  send wording; the spec asks the wording for sends.
