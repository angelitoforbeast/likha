# Result 113: Forbeast is set up per page with a token, exactly like Botcake

Branch `feat/113-forbeast-page-token`, cut from `main` at a94dd287. Not pushed.

## What shipped

- **Per-page keys** (page settings rows, no migration):
  - `forbeast.token`: a secret, shown as •••• plus the last 4.
  - `forbeast.webhook_secret`: a secret and a system key. It is never a column and never sent to the browser.
  - `forbeast.token_status`: a system key. It reads `ok · Forbeast connected (<name>)`, `invalid · …` or `error · …`.
  - The Facebook page id is the page's `botcake.page_id`.
- **`App\Services\Chatbot\ForbeastPageSetup`:**
  - `connect`: calls `GET /v1/pages/{id}` with the page token. When that is ok, it makes a new 64-character secret and calls `PUT /v1/pages/{id}/webhook` with `{url: APP_URL/api/gateway/events, secret}`. The secret is stored only after the PUT returns 2xx. A refused GET (401/403/404) is `invalid`, anything else is `error`, and a failed PUT is `error · Webhook not set: …`.
  - `disconnect`: calls `DELETE …/webhook` (best effort), then removes the token, the secret and the status.
  - `info` and `ready`: back the row display and the provider gate.
- **Inbound** (`GatewayEventController`): the controller finds the page by the event's `page_id` (the cached `BotcakeChat::pageMap`). It verifies the signature with that page's own secret, or with `GATEWAY_WEBHOOK_SECRET` when the page has none. An unknown page or no `page_id` gets 401. A bad signature still gets 401, and no secret at all still gets 503. A body that isn't JSON is checked against the global secret and then gets 400, as before.
- **Sending** (`GatewayProvider`): uses the page's `forbeast.token` when there is one, otherwise `GATEWAY_KEY`. The token is also the string redacted from the stored raw error.
- **Replies via Forbeast** (`POST /chatbot/provider`, single and bulk): refused with `Add the Forbeast token first: <pages>` unless every page has an ok token or `GATEWAY_KEY` is set. It still needs `GATEWAY_URL`, as before.

## UI places

- **`/chatblast/page-settings`**, Botcake cell of each row:
  - Two new lines, "Forbeast" (the token, editable; paste to save) and "Forbeast st." (the status, read only).
  - Saving the token, or changing the Botcake ID of a page that has a token, tests and connects at once. A notice says "Forbeast connected (name)" or shows the error.
  - A **Test Forbeast** button in the row actions (`POST /chatblast/page-settings/forbeast-test`, throttled 30/min).
  - Clearing the token cell, or deactivating it, disconnects.
- **`/chatbot/pages`**, Active cell of each row, under "Replies via":
  - The line "Forbeast connected (••••abcd)", or the status, or "No Forbeast token".
  - Buttons **Add token / New token** (paste prompt), **Test** and **Clear**, all on `POST /chatbot/forbeast-token` (same access as a cell, throttled 30/min).
  - The Forbeast option is disabled with "(add the token first)" until the page is ready. Bulk Apply shows the same reason.

## Files changed

- `app/Services/Chatbot/ForbeastPageSetup.php` (new)
- `app/Http/Controllers/GatewayEventController.php`
- `app/Http/Controllers/PageSettingController.php`
- `app/Http/Controllers/ChatbotController.php`
- `app/Services/Chatbot/GatewayProvider.php`
- `app/Services/PageSettings.php`: secret and system keys
- `app/Services/PageSettingsGrid.php`: two known columns; the secret is no column
- `resources/views/page_settings/index.blade.php`
- `resources/views/chatbot/index.blade.php`
- `routes/web.php`: two routes
- `routes/api.php`: comment
- `config/services.php`: comment
- `tests/Feature/Chatblast/ForbeastPageTokenTest.php` (new)
- `tests/Feature/PageSettings/PageSettingsGridTest.php`: the known column list now has the two Forbeast columns
- `qa/stories.md`: S-113
- `TODO.md`: accepted minors

## Case table

`vendor/bin/phpunit tests/Feature/Chatblast/ForbeastPageTokenTest.php` gives OK (10 tests, 77 assertions).

| Case | Test | Result |
|---|---|---|
| S-113.1 | `test_S_113_1_saving_a_token_tests_it_then_registers_the_webhook_with_a_stored_page_secret` | pass |
| S-113.2 | `test_S_113_2_a_bad_token_shows_the_error_and_registers_nothing` (also PUT refused after a good GET) | pass |
| S-113.3 | `test_S_113_3_the_test_button_retests_and_reconnects_from_page_settings_and_from_the_chatbot_row` | pass |
| S-113.4 | `test_S_113_4_the_token_is_saved_from_the_chatbot_row_with_the_same_access_as_a_cell` | pass |
| S-113.5 | `test_S_113_5_inbound_is_verified_with_the_pages_own_secret_and_falls_back_to_the_global_one` | pass |
| S-113.6 | `test_S_113_6_a_send_uses_the_pages_token_and_otherwise_the_global_key` | pass |
| S-113.7 | `test_S_113_7_replies_via_forbeast_needs_a_working_token_or_the_global_key` | pass |
| S-113.8 | `test_S_113_8_clearing_the_token_deletes_the_webhook_and_removes_both_keys`, `test_S_113_8b_clearing_still_removes_the_keys_when_forbeast_does_not_answer` | pass |
| S-113.9 | `test_S_113_9_secrets_are_never_rendered_only_the_last_four` | pass |

**Red runs** (before the code): all ten failed because the behaviour was missing:
- The routes `/chatblast/page-settings/forbeast-test` and `/chatbot/forbeast-token` returned 404.
- No `forbeast` key in the cell answer or the rows.
- An event signed with the page secret got 401.
- The send went out with `GATEWAY_KEY`, not the page token.
- Clearing left the secret in place.

## Test runs

- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` gives OK: 904 tests, 6366 assertions, 1 skipped, 52 PHPUnit deprecations (the same as before).
- The full suite gives 2297 tests with 1 error and 1 failure, and **both fail on `main` a94dd287 too**:
  - `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` errors because the local `storage/app/credentials.json` is missing.
  - `ExampleTest::test_the_application_returns_a_successful_response` fails.
  
  Neither is related to this change.
- `php -l` is clean on every changed PHP file. `npm run build` was not needed: only Blade inline scripts changed.

## Review

`skeptic-reviewer` ran at standard depth (medium tier) on 0a0deafc..a523014a. It found no blockers and no majors, and it checked:
- The signature order.
- That the global secret is never used for a page that has its own.
- That an unknown page and a bad signature return the same 401 body.
- That secrets are masked everywhere.
- That spec 111 still passes.

Two of the tests it said were missing are added: an event without a `page_id` gets 401, and a PUT refused after a good GET stores no secret. The remaining minors are accepted with reasons in `TODO.md` under "113":
- An invalid page token is still used, and the page counts as ready, when the global key is set. This is what the spec says.
- A skipped connect leaves the old status in place.
- Two Tests at the same moment have no lock between them.
- `chatbot.provider` can be set through a plain grid cell. This was already possible before this spec.
- Clearing the token doesn't switch the provider back to Botcake.
- `/chatbot/data` does a few cached reads per row.

## Story changes

- New section S-113 in `qa/stories.md`, with cases S-113.1 to S-113.9. The spec's "Done when" list was turned into cases; the spec brought no separate stories.
- S-113.2 also covers a PUT that is refused after the token is accepted. Its result is `error · Webhook not set: …`, and no secret is stored.

## Notes and conflicts

- **"Unknown page → 401"** changes one case from spec 111: a signed event whose `page_id` isn't in page settings used to be accepted and counted as `unknown_page`. It now gets 401. Spec 111's tests all still pass.
- **"Forbeast connected"** is part of the stored ok status (`ok · Forbeast connected (<name>)`), so both grids show it without extra code.
- **No browser check was run.** The UI was checked by test (rendered text and JSON) only.
- **Owner / deploy:** no new env names.
  - `GATEWAY_KEY` and `GATEWAY_WEBHOOK_SECRET` are now fallbacks. Pages without a token work as today.
  - `GATEWAY_URL` must be set for any of this to run.
  - `APP_URL` must be the public URL (`https://likhaaitech.com`), because that is the webhook URL sent to Forbeast.
  - No migration.

## Commits

- `0a0deafc` docs: save spec 113 (Forbeast page token)
- `a523014a` feat: Forbeast token per page, tested and connected on save (spec 113)
- `f6da0a2c` test: Forbeast webhook refused and event without page id; accepted review minors (spec 113)
- `c0a422f6` docs: result for Forbeast page token (spec 113), plus this commit list
