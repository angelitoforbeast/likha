# Result 162: Forbeast can read and edit a page's bot values

Status: done. Branch `feat/162-page-values-api`, cut from `da1d158f`.

## What shipped

- **API (contract part B).** `GET` and `PUT /api/gateway/pages/{meta_page_id}/values` (`GatewayPageValuesController`, `Chatbot\PageValues`).
  - **Page lookup:** the page is found by its Botcake / Meta page id (`botcake.page_id`, the same map gateway events use).
  - **Signature:** `X-Gateway-Signature` is `sha256=` plus the HMAC of `<X-Gateway-Timestamp>.<raw body>`, with an empty body for GET. Only the page's own `forbeast.webhook_secret` is used, with no global fallback, and the timestamp may be at most 300 s off.
  - **Errors:** an unknown page gets 404 `unknown_page`. Any signature problem gets 401 `bad_signature`, including a page that has no secret of its own.
  - **Rate limit:** 60 requests a minute per page gets 429 `rate_limited`. Only signed requests count, so knowing a public page id is not enough to lock Forbeast out.
- **Fields.** The order is the contract's. Only columns that exist on the site are returned; seq_N and seq_img_N come from the site's own columns, which is 20 each here.
  - Labels come from `ChatbotFields::columns()`, the same labels the /chatbot screen shows.
  - The type is `image`, `textarea` (the long fields) or `text`.
  - `shop_details` is built by `ShopDetails::build` and has `editable: false`.
  - A whitelist means prompt_*, flow / flow_id, AI settings, auto_switch, provider, secrets and botcake.* can never appear.
- **PUT.**
  - `X-Forbeast-User` is required and may be at most 200 characters.
  - `values` must be an object. A key that isn't editable gets 422 `field_not_editable`, with `key` and `errors` naming it.
  - The values get the same check as the /chatbot screen. The rule now lives in `ChatbotFields::valueError` and the screen calls it, with image links limited to https here. A bad value gets 422 `invalid_value` with `errors` per key.
  - Writes run in one transaction through `PageSettings::set` / `deactivate`, so the old row is kept as history. A null or blank value clears the field, as a blank cell does.
  - The response is the GET body with the fresh values.
- **Audit.**
  - **New columns:** the migration adds nullable `source` (the actor who wrote the row) and `replaced_source` (the actor who cleared it) to page_settings. No new table. `created_by` and `replaced_by` hold user ids, so a text actor doesn't fit there, and a clear needs its own column.
  - **Where it shows:** "via Forbeast: <actor>" appears wherever Likha shows who changed a value: the page-settings grid cell and its history, /chatbot history and /chatbot Changes, including cleared values.
- **Caches.** After a write, the labels cache from spec 161 and the page's settings cache are cleared, so the bot's next run uses the new values.

## Case table

| Case | Test (`tests/Feature/Chatblast/GatewayPageValuesTest.php`) | Result |
|---|---|---|
| S-162.1 | `test_S_162_1_get_returns_the_fields_in_contract_order_and_never_the_system_ones` | pass |
| S-162.2 | `test_S_162_2_bad_auth_gets_the_contract_errors` (8 rows) | pass |
| S-162.3 | `test_S_162_3_put_saves_with_history_and_actor_and_the_bot_uses_the_new_values` | pass |
| S-162.4 | `test_S_162_4_put_refuses_non_editable_keys_and_invalid_values_and_saves_nothing` (9 rows), `test_S_162_4_put_needs_the_actor_and_a_values_object` | pass |
| S-162.5 | `test_S_162_5_shop_details_is_rebuilt_when_item_price_or_quantity_change` | pass |
| S-162.6 | `test_S_162_6_sixty_requests_per_minute_per_page`, which also covers unsigned requests not counting and 300 s still being accepted | pass |
| S-162.7 | `test_S_162_7_page_settings_and_chatbot_screens_show_via_forbeast` | pass |

The new file: `OK (23 tests, 272 assertions)`. Red run: the first run had all 23 erroring because the migration and route were missing, then only S-162.7 failed ("system" instead of "via Forbeast: …") before the display change.

Done-when suites (`tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings`):
- **Baseline at `da1d158f`:** 1286 tests, OK, 1 skipped, 54 PHPUnit deprecations.
- **After:** 1309 tests, OK, 1 skipped, 54 deprecations. No new failures, and no red test.

## Story changes

- S-162 was added to `qa/stories.md` from the spec's cases. The added detail:
  - A timestamp ahead of time, a missing timestamp and a body signed without the timestamp all get the same 401.
  - A missing or too-long actor, and `values` that isn't an object, get 422 `invalid_value`.
  - Requests that aren't signed don't count toward the rate limit.

## Where the contract left a choice (my side only, contract unchanged)

- **Page without its own secret:** 401 `bad_signature`. The contract names no other code for this.
- **Missing or over-200-character `X-Forbeast-User` on PUT:** 422 `invalid_value` with `errors["X-Forbeast-User"]`.
- **Error bodies:** `{"error": "<code>", "message": "<text>"}`, the same shape as part A. `field_not_editable` also carries `key` and `errors`.
- **413 `too_large`:** returned for a body over 4 MiB. This is hardening that isn't in the contract; a full page of values is at most about 3.3 MB.
- **https only for image fields:** the contract asks for https. The /chatbot screen still accepts http, unchanged.
- **shop_details `type`:** `textarea`, because the contract doesn't say.

## Review

The skeptic-reviewer ran at standard depth, checking auth and validation adversarially. It found no blockers or majors.

Minors fixed:
- The `source` and `replaced_source` writes are guarded with `Schema::hasColumn`.
- `ChatbotFields::columns()` is read once per request.
- Tests were added for: requests that aren't signed don't count toward the rate limit, a timestamp exactly 300 s off is accepted, and a non-JSON body or `{"values":"x"}` gets 422.

The other minors are accepted with reasons in `TODO.md` under 162.

Browser check: not run. There is no running app in this worktree, and the only UI change is the "by" text that the server already sends to the existing screens.

## Deploy notes (both sites: Likha and Incepxion)

1. Deploy the code, then run `php artisan migrate --force`. This runs `2026_11_12_100000_add_source_to_page_settings`: two nullable columns, guarded and safe to run again. Until it runs, Forbeast writes still save, but without the actor.
2. No new environment variables. The API uses each page's existing `forbeast.webhook_secret`, set by the Forbeast token Test. A page whose token hasn't been tested since it got a webhook secret gets 401 until it is.
3. On Incepxion, seq_N / seq_img_N follow that site's own `ChatbotFields` columns. No change is needed.

162: done — Forbeast can GET and PUT a page's bot values through `/api/gateway/pages/{id}/values`, signed per page, with history, the actor shown as "via Forbeast", and the bot using the new values at once.
