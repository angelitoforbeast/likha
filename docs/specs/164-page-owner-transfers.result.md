# Result 164: page owners and transfers between the partner sites

Status: done, with one open major escalated (copied follow-ups and the 24 h window, below). Branch `feat/164-page-owner-transfers`, cut from `87cb0e1e`.

## What shipped

- **Part P, the endpoints Forbeast calls** (`GatewaySiteController`, `Chatbot\PageTransfers`). Five endpoints: `POST /api/gateway/site/pages/{id}/adopt`, `GET …/export`, `POST …/import`, `POST …/release` and `POST …/activate`.
  - **Signature:** `X-Gateway-Signature: sha256=HMAC("<timestamp>.<raw body>", FORBEAST_SITE_SECRET)` (the body is empty for GET), with the timestamp at most 300 s off; `hash_equals`.
  - **Errors:** when `FORBEAST_SITE_KEY`, `FORBEAST_SITE_SECRET` or `GATEWAY_URL` is missing → 503 `not_linked`. Any bad signature → 401 `bad_signature`. A body over 16 MB → 413. Errors are `{"error","message"}`.
  - **Transfer id:** `X-Gateway-Transfer` is the transfer id and goes into the history.
  - **No secrets in logs:** no token, secret or value is logged (`PageSettings` logs keys only).
- **adopt:**
  - **What it saves:** `botcake.page_id` = the Meta page id, `chatbot.provider` = gateway, and `forbeast.token` (secret). Then it runs the existing `ForbeastPageSetup::connect`: a new webhook secret and a PUT of the webhook.
  - **Take:** sets `chatbot.active` = 0.
  - **Names:** a page this site already has (by Meta id) keeps its own name. A name used here by a different Meta page id → 409 `name_taken`, nothing saved. The name `_all` → 422.
  - **Connect failing:** → 502 `connect_failed`. The token stays saved, so Forbeast's retry works.
  - **Never returned:** the token is never in the answer.
  - **History rows:** `source` = `transfer <id> from <site>`, or `taken by <actor>` for a take. Adopt has no actor in the contract, so an optional `actor` in the body is used; without it the source is "taken by Forbeast (transfer <id>)".
- **export:** at most 500 items a call; `next` is the last row id, or null.
  - **`values`:** `name`, `active` ("0"/"1"), `flow` (the name of the flow in `chatbot.flow_id`, which is the Main Flow the bot runs), then the editable 162 fields. Unset fields are sent as null, so the target clears them. Never sent: prompts, flow_id, AI settings, secrets, tokens or `shop_details`.
  - **`fields`:** `{psid, key, value, updated_at}`. Skipped: `__*` keys (filtered in PHP, because `_` is a wildcard in LIKE) and the Test-tab customer, PSID 0. `?since=<ISO>` limits it to rows changed after that time.
  - **`sequences`:** `{psid, sequence (name), current_step, next_send_at, enrolled_via}`. It covers active subscriptions and those paused because the page is Off, never rows still held by a transfer.
- **import:** all or nothing per call (one transaction). Idempotent. At most 500 items, else 422.
  - **values:** creates the page when it is new here (its name comes from the `name` item, with the same 409 clash rule). The flow is matched by name; a missing name → 422 `missing_names`, nothing saved. Values are checked like the 162 API (`ChatbotFields::valueError`, https images) and saved through `PageValues::save` (history kept). Botfields this site lacks (e.g. Incepxion's extra seq columns) are skipped and listed in `skipped`.
  - **fields:** upserts by (page, psid, key). The newer `updated_at` wins, so a value changed on the target after the switch is not overwritten by the catch-up copy. `__*` keys → 422.
  - **sequences:** matched by sequence name; a missing name → 422 `missing_names`, nothing saved. Rows are written **held** (below). A row already running here (active, or paused because the page is Off) is left alone.
- **Held subscription (decision 4, which marking): `status = paused` + `exit_reason = "transfer <id>"`, the existing columns.**
  - **No sends:** the tick takes only `active`, and `resumePaused` takes only `exit_reason = 'page off'`, so nothing is sent while held.
  - **Enrolled:** `subscribe()` treats paused as already enrolled, so a flow can't restart the customer at step 1. Exit-on-reply ends the row.
  - **activate:** turns `active` only the rows held by its own transfer (`X-Gateway-Transfer`), keeping `current_step` and `next_send_at`. Rows left by an earlier failed transfer stay held. None of the sequence code was changed.
- **release:** `chatbot.active` = 0 with source "transferred to <site>".
  - **Subscriptions:** the page's active and paused subscriptions (held ones too) → `unsubscribed`, exit_reason `transferred`.
  - **Forgotten:** the page's `chatbot_waits`, its `__wait` / `__ai` values, and its pending AI requests and chatbot send requests (→ `resumed`, error "page transferred"). Chatblast send rows are untouched.
  - **Forbeast connection:** the token, webhook_secret, token_status and permissions rows are deactivated with history kept. No DELETE call, because Forbeast already moved the webhook.
  - **Caches:** the page settings cache and the page's flow in `BotConfigCache` are cleared. Idempotent. An unknown page → 404.
- **Part S, the CEO screen** (`/chatbot/owners`, `ChatbotOwnersController`, `Chatbot\ForbeastSite`).
  - **Access:** `EnsureCeo` on both routes. The "Owners" tab is shown to the CEO only; other roles get 403 and no tab.
  - **The list:** one card per page with its picture, name, id and owner ("This site (Likha)", the other site, or "Unowned"). Pending requests show the direction, who asked and when. The history comes from `GET site/transfers?page=` (`?history=<id>`).
  - **Actions:** Take (unowned), Move to <other> (owned here), Ask for this page (owned there), Accept / Decline (the other site's requests), Cancel (this site's requests).
  - **Confirmation:** Take, Move, Ask and Accept need the CEO's own password, checked with `Hash::check` before any call to Forbeast and never sent. Then the phrase shown on the card: `TAKE <page> TO <SITE>` / `TRANSFER <page> TO <SITE>`, with the site in upper case and the destination for transfers.
  - **Fresh read before writing:** before a write, the page, its owner and its pending requests are read again from Forbeast; the form's values are never trusted. The call carries `actor` = "<name> <email>".
  - **Limits and errors:** writes are limited to 10 a minute per user. Forbeast's refusals are shown ("Forbeast said …"). Without the key and secret the screen says the site is not linked and makes no call.
  - **Escaping:** all Forbeast text is escaped, and a picture shows only when it is https.
- **`/chatbot/pages` lists a taken page.** `ChatbotController::pages()` now also lists pages with a Meta page id and a Forbeast token but no Botcake token. Otherwise a taken page could not be set up and turned on. This is the only change to existing behaviour.
- **Config:** `services.forbeast_site.key` / `.secret`. `.env.example` has `FORBEAST_SITE_KEY=` and `FORBEAST_SITE_SECRET=`.

## Contract reading and open points

- **Paths:** Part S paths are `GATEWAY_URL + /v1/site/...`, the same base as today's `/v1/pages/...` calls. With `GATEWAY_URL` ending in `/api` this is `/api/v1/site/...`, as the contract says.
- **This site's name for Part P** (adopt, import and release carry none) comes from the APP_URL host: `incepxion` in the host means incepxion, anything else likha. The screen uses the `site` of `GET site/pages`.
- **Phrase:** the site part is upper case (`TAKE Page Uno TO LIKHA`) and spaces in the page name are squashed. Forbeast 024 must build the same text, or every write gets 422 `wrong_phrase`.
- **Ids:** pending request ids are assumed to be numbers. Confirm with Forbeast 024.
- **"Any flow-named field":** the only flow a page links to is `chatbot.flow_id` (the Main Flow); `main_flow` and the other botfields are text, copied as text.

## Escalated: copied follow-ups end "outside 24h" on the new site (open major)

`ChatbotSequences::runStep` sends a step only when `botcake_chats` has a message from the customer in the last 24 hours. The new site has no chats for customers who wrote to the old site, so after activate a copied PREORDER step is skipped and the subscription ends. Example: the customer writes at 10:00, the page moves at 12:00, and step 2 was due at 14:00.

Not fixed here, for two reasons:
- The fix needs the customer's last message time to travel with the copy, which is a contract change with Forbeast 024 (for example `last_message_at` on each `sequences` item, recorded on import).
- Changing the 24 h check would change sequence logic, which the spec forbids.

The owner decides. Also for the owner: `__bot_paused` is a `__*` key, so a customer a person took over on the old site is answered by the bot on the new one.

## Story changes

- **S-164.3:** added that a name `_all` → 422 (from the review: the Setup box name), and that a taken page is listed on `/chatbot/pages` with the bot Off (needed for "until the CEO sets it up and turns it on").
- **S-164.5:** added "while held, a new subscribe does not restart them at step 1 and exit-on-reply ends them" (from the review).
- **S-164.6:** activate releases only the rows held by its own transfer (from the review).
- **Older tests updated:** three tests pinned the CEO's exact Chatbot tab list (`ChatbotInboxTest` S-074.5, `ChatbotSequencePagesTest` S-070.1, `ChatbotTabsTest` S-069.T1). They now end with "Owners", the intended new CEO-only tab.

## Cases

| Case | Test | Result |
|---|---|---|
| S-164.1 | `PageOwnersScreenTest::test_S_164_1_the_ceo_sees_every_page…`, `…other_roles_get_403_and_no_owners_tab`, `…a_site_without_its_key_says_it_is_not_linked` | pass |
| S-164.2 | `PageOwnersScreenTest::test_S_164_2_each_action_needs_the_right_password_phrase_and_side…` (17 rows), `…a_refusal_from_forbeast_is_shown_and_writes_are_limited_to_10_a_minute` | pass |
| S-164.3 | `GatewaySitePagesTest::test_S_164_3_adopt_saves_the_page…`, `…a_taken_page_with_only_a_forbeast_token_is_listed…`, `…a_name_used_by_another_meta_page_is_409…`, `…bad_signatures_and_bad_bodies_are_refused…` (9 rows), `…without_its_key_and_secret_answers_503` | pass |
| S-164.4 | `GatewaySitePagesTest::test_S_164_4_export_values…`, `…export_fields_pages_with_cursors…`, `…export_sequences_by_name…` | pass |
| S-164.5 | `GatewaySitePagesTest::test_S_164_5_import_fields_is_idempotent…`, `…import_sequences_by_name_held_until_activate…`, `…import_values_maps_the_flow_by_name…` | pass |
| S-164.6 | `GatewaySitePagesTest::test_S_164_6_release_stops_the_page_here…`, `…activate_releases_only_the_rows_held_by_its_own_transfer` | pass |
| S-164.7 | Chatblast + Botcake + PageSettings suites | pass (see below) |
| S-164.8 (P2) | `PageOwnersScreenTest::test_S_164_8_the_screen_is_cards_that_fit_a_phone` | pass: markup only (no wide table, `min-w-0` cards, full-width inputs, viewport meta) |

Red runs:
- **Part P:** every Part P test failed with 404 (no routes) before the code.
- **Taken-page listing:** the test failed with `['Page Uno']` instead of `['Page Tres', 'Page Uno']`.
- **Screen:** its tests were run red by taking the new code out: 404 on every case. Then the code was put back, and they passed.
- **Review fix:** 5 failures before the fix (the `_all` rows, the held status, activate scoping).

## Suite

`php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings`

- **Baseline at 87cb0e1e:** 1335 tests, OK (1 skipped).
- **First run on the branch:** 1375 tests, 3 failures. All three were the tab-list expectations above, since updated.
- **Final, after the review fix:** 1378 tests, OK (1 skipped, the same as the baseline); no new failures.

## Review

`skeptic-reviewer`, adversarial depth, opus, on 87cb0e1e..HEAD: request changes, with 3 majors.

Fixed in one loop:
- **Held rows:** they were not seen by subscribe or exit-on-reply. They are now `paused` + `transfer <id>`.
- **Activate:** it released stale held rows from a failed transfer. It is now scoped to its own transfer id.
- **The `_all` page name** (a minor on an untrusted path): now refused.

Escalated: the 24 h window (above).

The other minors are accepted with reasons in `TODO.md` (164 section):
- the signature doesn't cover method and path (the contract's choice)
- a half-made page after a failed move
- skipped botfields
- the nav vs EnsureCeo role trim
- a duplicated squash helper
- numeric ids
- the missing edge tests

## Not done

- **Browser check:** no browser check at 390 px. Opening the screen in a running app needs a live or faked Forbeast and a signed-in CEO, so S-164.8 is checked by markup only.
- **No deploy, no push** (out of scope).

## Deploy notes (both sites; Incepxion gets the same commits)

- **No migration.** No new tables or columns.
- **Env, per site:** set its own site system from Forbeast (spec 024): `FORBEAST_SITE_KEY=fbs_…` (that system's key) and `FORBEAST_SITE_SECRET=…` (that system's webhook secret). `GATEWAY_URL` must already be set (it is, for the Forbeast pages). Then run `php artisan config:cache`.
- **Each site's identity:** the site system's `webhook_url` in Forbeast is the site's origin (`https://likhaaitech.com`, `https://incepxion.pro`). Each site's `APP_URL` must keep its own host: Part P names this site from it.
- **Order:** deploy after Forbeast 024 is live, or before it. Without the env lines the screen only says "not linked" and the endpoints answer 503.

164: done — the Owners screen, the five Forbeast endpoints and the held follow-up copy all work, but copied follow-ups still end "outside 24h" on the new site until the contract carries the customer's last message time (escalated).
