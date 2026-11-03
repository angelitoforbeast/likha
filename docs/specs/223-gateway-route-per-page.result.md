# Result 223: gateway address per page, and why a send got no answer

**Branch:** `feat/223-gateway-route-per-page` from `ece5a6a3`. **Status:** done, not pushed, not deployed.

## What shipped

- **S1** `services.gateway.urls` (`GATEWAY_URLS`, `key=url` separated by spaces or commas) and
  `services.gateway.force_ipv4` (`GATEWAY_FORCE_IPV4`, default off). `Gateways::routes()`: `forbeast` is always
  `GATEWAY_URL`, then the list; bad entries are skipped; path prefixes kept, trailing `/` dropped.
- **S2** Page row `chatbot.gateway_app` (system row, reserved so it is never a botfield). `Gateways::route($page)` and
  `Gateways::pageUrl($gateway, $page)` are used by every call under `/v1/pages/{id}`: sends (`GatewayProvider`, and the
  sequence pool through `GatewayProvider::request`), typing, comment reply and private reply, the token connect, probe,
  retest and disconnect, and the watcher's health check. Site calls (`/v1/site/...`), `ForbeastSendHealth` and the
  "is the gateway set up" checks keep `GATEWAY_URL`. An unknown key uses the default and logs one warning per page and
  value per day.
- **S3** A 409 whose error code is `app_not_connected` (string `error`, `error.code` or `code`) is a failed send "This
  page is not connected to the <key> Meta app on the gateway (HTTP 409)". It is an answer: the watcher counts it as a
  refused send (failed_sends), never as no answer. No retry, no other address.
- **S4** `/chatbot/data` gives `gateway_app` per row and `gateway_apps` (key => name), both only when more than one address is
  configured; `POST /chatbot/gateway-app` (same access and throttle shape as `/chatbot/gateway`, all or nothing, key
  must be configured) saves it with the user. The Pages screen shows an "App: …" select beside the gateway select when
  more than one address is configured and the page's gateway is Forbeast.
- **S5** No watcher change needed: the move to Botcake and back writes `chatbot.provider` only; a test pins that the
  address survives and the next send uses it.
- **S6** On a `ConnectionException` (and the sequence pool's rejected promise) the failure reads "Gateway did not answer
  (HTTP 0) · cURL error 28: … · connect 0.01 s, total 10.00 s", from the Guzzle handler context (or the `cURL error N:`
  text of the message, without the URL), and one `gateway send: no answer` warning is logged with page, gateway, route,
  host, errno, error and times. Never the token, key or message text. `GATEWAY_FORCE_IPV4=true` adds
  `force_ip_resolve => v4` (cURL `CURLOPT_IPRESOLVE` V4) to sends, the pool, typing, comment replies, page setup and
  health checks.

## Case table

| Case | Test | Result |
|---|---|---|
| S-223.1.1–1.3 | `test_S_223_1_the_addresses_come_from_config_and_without_a_list_nothing_changes` | pass |
| S-223.2.1–2.2 | `test_S_223_2_a_page_on_incepxion_makes_every_page_call_there_and_a_page_without_the_setting_uses_the_default` | pass |
| S-223.2.3 | `test_S_223_2b_an_unknown_key_uses_the_default_and_warns_once` | pass |
| S-223.2.4 | `test_S_223_2c_changing_the_setting_takes_effect_on_the_next_call` | pass |
| S-223.2.5, 6.4 | `test_S_223_2d_and_6c_a_sequence_send_uses_the_page_address_ipv4_only_and_records_why_it_got_no_answer` | pass |
| S-223.3.1–3.2 | `test_S_223_3_a_409_app_not_connected_is_an_answer_with_a_clear_reason_and_no_retry` | pass |
| S-223.4.1–4.3 | `test_S_223_4_the_pages_screen_shows_and_sets_the_address_only_with_more_than_one` | pass |
| S-223.5.1 | `test_S_223_5_the_move_to_botcake_and_back_keeps_the_address` | pass |
| S-223.6.1–6.2 | `test_S_223_6_a_send_without_an_answer_records_the_curl_error_and_times_never_the_token_or_text` | pass |
| S-223.6.3 | `test_S_223_6b_force_ipv4_makes_the_gateway_calls_ipv4_only` | pass |

Red run (before the code): 9 of 9 failed for the missing behaviour, first line
`Error: Call to undefined method App\Services\Chatbot\Gateways::routes()`. The sequence pool test was written after
the code (it passed on its first run).

## Full suite

Base `ece5a6a3` (clean export, same vendor, no .env): **4348 tests, 76620 assertions, 2 failures, 6 skipped**: `ExampleTest::test_the_application_returns_a_successful_response` and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`.

This branch, with no `GATEWAY_URLS`: **4358 tests, 76708 assertions, 2 failures, 6 skipped**, the same two tests. No new failures. The first branch run caught `ChatbotDataSpeedTest` S-207.9 (the Pages data JSON snapshot) because the row gained `gateway_app`; the fix sends `gateway_app` and `gateway_apps` only when more than one address is configured, so the data is byte-for-byte as before without `GATEWAY_URLS`.

Lint: `php -l` clean on every changed PHP file.

## Env lines to add

Likha (`likhaaitech.com`), next to `GATEWAY_URL`, with the same path prefix `GATEWAY_URL` carries:

```
GATEWAY_URLS="incepxion=https://incepxionbot.com/api"
GATEWAY_FORCE_IPV4=false
```

The Incepxion site, the same two lines, when its replies go through the Forbeast gateway (`GATEWAY_URL` set there). If
that site runs only on spec 194's second gateway (`INCEPXIONBOT_URL`, no `GATEWAY_URL`), these lines change nothing
there: the address is a choice inside the Forbeast gateway.

To test the IPv6 question, set `GATEWAY_FORCE_IPV4=true` for a while and compare the `gateway send: no answer` lines.

## Deploy notes

- No migration, no new table, no queued job payload change (`QueuedJobCompatTest` unchanged and green).
- After the env change: `php artisan config:cache` (and `route:cache` if routes are cached, for `/chatbot/gateway-app`),
  `php artisan view:clear`, `php artisan queue:restart`, and restart the long-running pools (`chatbot:sequence-pool`,
  `botcake:send-pool`, the AI pool) under supervisor so they read the new config.
- No asset build: the Pages screen script is inline in the Blade view.
- With no `GATEWAY_URLS`, every request goes where it goes today; the select is hidden.
- Pages move one at a time from the Pages screen ("App: Incepxion"); each page needs its token for the Incepxion app on
  the gateway first, or its sends fail with the 409 reason.

## Rulings

- **The address is a layer inside the `forbeast` gateway, not a new gateway.** Spec 194 (live in `ece5a6a3`) already has
  `chatbot.gateway` = `forbeast` | `incepxionbot` with separate env, tokens, webhook and automatic switch. This spec's
  address shares the key and page token, so it lives in `services.gateway` and applies only when the page's gateway is
  `forbeast`. The `incepxionbot` gateway is untouched.
- **`forbeast` is always `GATEWAY_URL`;** a `forbeast=` entry in `GATEWAY_URLS` is ignored, so the default can never
  drift from today's.
- **Address names on screen** are the key in title case ("Incepxion"); the 409 reason uses the key itself.
- **409 `app_not_connected` counts toward `failed_sends`** like any other refusal (5 in a row moves the page to Botcake),
  never toward "no answer".
- **IPv4 through Guzzle's `force_ip_resolve`**, which sets `CURLOPT_IPRESOLVE` to V4 on the cURL handler.
- **The token setup (connect, test, disconnect) also follows the address,** since it calls `/v1/pages/{id}`.

## Story changes

- Added S-223.2.5 and S-223.6.4 (the sequence pool path of S2 and S6) to cover the second send path.

## Accepted (in `TODO.md`)

- The watcher's burst hold is per gateway, not per address.
- A comment reply answered with 409 `app_not_connected` shows the gateway's code, not the send wording.
- Spec 194's second gateway and this spec's address can both reach incepxionbot.com.

## Not done

- No skeptic review and no browser check were run in this session; the reviewer's code review is next.
