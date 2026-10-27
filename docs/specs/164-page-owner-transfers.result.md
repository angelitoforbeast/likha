# Result 164: page owners and transfers between the partner sites

Status: done. The escalated major (copied follow-ups and the 24 h window) is resolved by the reviewer's ruling; contract amendments 2 (the hold) and 3 (status on the catch-up, the new order, `held_at`, the Owners hold note) are built on this side. Branch `feat/164-page-owner-transfers`, cut from `87cb0e1e`.

## What shipped

- **Part P, the endpoints Forbeast calls** (`GatewaySiteController`, `Chatbot\PageTransfers`). Six endpoints: `POST /api/gateway/site/pages/{id}/adopt`, `GET …/export`, `POST …/import`, `POST …/hold` (contract amendment 2, below), `POST …/release` and `POST …/activate`.
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
  - **`sequences`:** `{psid, sequence (name), current_step, next_send_at, enrolled_via, last_message_at}`. `last_message_at` is the customer's last message time (below). It covers active subscriptions, those paused because the page is Off and those held by a hold here, never rows still held by a transfer to this site. `?since=` gives the catch-up (contract amendment 2, below).
- **import:** all or nothing per call (one transaction). Idempotent. At most 500 items, else 422.
  - **values:** creates the page when it is new here (its name comes from the `name` item, with the same 409 clash rule). The flow is matched by name; a missing name → 422 `missing_names`, nothing saved. Values are checked like the 162 API (`ChatbotFields::valueError`, https images) and saved through `PageValues::save` (history kept). Botfields this site lacks (e.g. Incepxion's extra seq columns) are skipped and listed in `skipped`.
  - **fields:** upserts by (page, psid, key). The newer `updated_at` wins, so a value changed on the target after the switch is not overwritten by the catch-up copy. `__*` keys → 422.
  - **sequences:** matched by sequence name; a missing name → 422 `missing_names` with the names under `names`, nothing saved (the same for a missing flow in `values`). Rows are written **held** (below). A row already running here (active, or paused because the page is Off) is left alone. `last_message_at` is recorded per customer (below).
- **Held subscription (decision 4, which marking): `status = paused` + `exit_reason = "transfer <id>"`, the existing columns.**
  - **No sends:** the tick takes only `active`, and `resumePaused` takes only `exit_reason = 'page off'`, so nothing is sent while held.
  - **Enrolled:** `subscribe()` treats paused as already enrolled, so a flow can't restart the customer at step 1. Exit-on-reply ends the row.
  - **activate:** turns `active` only the rows held by its own transfer (`X-Gateway-Transfer`), keeping `current_step` and `next_send_at`. Rows left by an earlier failed transfer stay held. The only change to the sequence code is the time source of the 24 h check (below).
- **release:** `chatbot.active` = 0 with source "transferred to <site>".
  - **Subscriptions:** the page's active and paused subscriptions (held ones too) → `unsubscribed`, exit_reason `transferred`, keeping their step and send time (contract amendment 2, below).
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

## Resolved: copied follow-ups and the 24 h window

**The problem (escalated earlier):** `ChatbotSequences::runStep` sends a step only when the customer wrote in the last 24 hours, and the new site has no chats for customers who wrote to the old one. So a copied step was skipped and ended "outside 24h". Example: the customer writes at 10:00, the page moves at 12:00, and step 2 is due at 14:00.

**The ruling, as built:** the customer's last message time travels with the copy; the 24 h rule itself is unchanged.
- **Export:** each `sequences` item carries `last_message_at`, ISO 8601 with offset, or null. It is the later of the customer's latest chat on this page here (`botcake_chats`, without standby chats, the source the 24 h check reads) and a `__last_message_at` an earlier move brought. So a page moved twice within the window keeps the time.
- **Import:** recorded per customer as the run-state value `__last_message_at` in `chatbot_customer_values` (no new table; `Y-m-d H:i:s`, app time). A newer stored value is never replaced, so a repeated or older import changes nothing. A time ahead of this site's clock is stored as now, so a wrong clock on the other site cannot keep the window open. A bad time → 422, nothing saved. `__*` keys stay out of the `fields` part.
- **runStep:** the 24 h check uses the later of the latest chat here and `__last_message_at`. Same 24 h, same timing, same skip and end. A customer who writes on the new site is unaffected, because their chat is newer.
- **"Clear all fields"** keeps `__last_message_at`, like `__last_chat`, so a flow clearing fields does not shorten the window.
- **Known gap (in `TODO.md`):** until the customer writes here, a follow-up run on the imported time has no customer name.

**Contract addition (for the contract copy; Forbeast relays the items unchanged, so it needs no change):**
- `GET …/export?part=sequences` items: `+ last_message_at: string|null` (ISO 8601 with offset).
- `POST …/import?part=sequences` items: `+ last_message_at: string|null`, optional.

Still for the owner: `__bot_paused` is a `__*` key, so a customer a person took over on the old site is answered by the bot on the new one.

## Checked against Forbeast's contract notes

- **Refusal codes on the Owners screen:** 404 `unknown_page` / `unknown_transfer`, 409 `not_owned` / `not_set_up` / `too_many_pending`, 422 `invalid_request` and 429 `too_many_requests` each show a fixed plain message (Forbeast's text is not shown for these). Other refusals still show "Forbeast said …". Fixed in this round.
- **`missing_names`:** the 422 body now lists the names under `names` (was `missing_names`). Fixed in this round.
- **accept / decline / cancel answer `{"id"}`:** the screen reads only the status of these answers. The test fake now answers `{"id"}`. No change needed.
- **`picture_url` always null:** the screen shows no picture then (the fakes already had null pictures). No change needed.
- **adopt PUTs the webhook before answering 200:** `adopt` runs `ForbeastPageSetup::connect` and answers 200 only when it worked (502 otherwise). No change needed.

## Contract amendment 2: the source holds follow-ups during the move

**The problem (joint QA):** the sequences were copied in step 2, but the source kept sending until release in step 4. A step sent in between went out again from the target after activate. Also, a customer who subscribed on the source after the copy was never copied.

**As built (this site's side):**
- **`POST /api/gateway/site/pages/{id}/hold` `{"hold": true|false}`:** signed like the other Part P calls. `X-Gateway-Transfer` is required here (missing or malformed → 422), because `hold:false` belongs to one transfer. Unknown page → 404. A `hold` that is not true/false → 422. Answers 200 `{"held": n}`, the page's rows still held after the call. `hold:true` also answers `"held_at"`, this site's time (ISO 8601). It's an addition: Forbeast can use it as the catch-up's `since`, so the two clocks don't need to agree.
- **One transfer owns the hold:** a `hold:true` from a newer transfer takes over the flag and the rows. A `hold:false` from any other transfer (for example a late retry of a cancelled move) changes nothing.
- **`hold: true`:** every **active** subscription of the page becomes `status = paused`, `exit_reason = "hold <transfer id>"`. Those are the existing columns, with a different reason from the imported `transfer <id>` so the copy still exports them. `current_step` and `next_send_at` are kept, and `updated_at` is not touched. The page gets a page_settings flag (`transfer.hold = <id>`, history kept). While the flag is on:
  - **New subscriptions** (and restarts of ended ones) are created held the same way.
  - **`resumePaused`** does not bring back the page's page-off rows; otherwise they would send during the copy.
  - **Idempotent:** a second call changes nothing and answers the same `held`.
  - **The tick** takes only `active`, so nothing is sent for the page.
- **`hold: false`:** the flag is cleared (history kept). Every `hold …` row of the page goes back to `active` with the same step and send time. One that came due during the hold is sent on the next tick.
- **A step being sent when the hold lands:** `runStep` checks the status and then sends; the hold can land in between. `ChatbotSequences::advance` then still records the step on a row held by a move's hold (it stays held), instead of losing it. It does so only when the usual `active` update matched no row, so pages that never move run no extra query. A last step is recorded as one past the end with a send time of now: on whichever site it next runs, it completes without sending. The copy, `hold:false` and the catch-up then carry the advanced step, and the step is not sent again. This is the only change to the sequence code in this round, and it touches only rows held by a move.
- **`release`:** ends held rows `transferred` as before, and clears the flag. Three changes, all for the catch-up:
  - **Step and send time kept:** `transferred` rows keep `current_step` and `next_send_at`; before, it nulled `next_send_at`. Otherwise the catch-up would copy a null send time over the target's held row, and that follow-up would never send. The tick reads only `active`, so a kept time on an ended row does nothing. The old site's subscribers screen and Inbox now show a "next send" time on these ended rows (display only, in `TODO.md`).
  - **`updated_at` not touched:** so the catch-up carries only rows made or changed since the hold. A customer who replied on the new site after the switch stays ended there.
  - **Stale import-held rows:** rows held here by an earlier failed move *to* this site (`transfer <id>`) are ended `transfer not activated` and are not copied.
- **`export?part=sequences&since=<ISO>`:** subscriptions with `updated_at >= since` (`>=` so a row made in the same second as the hold isn't missed; the import is idempotent). With `since` only, it also lists rows ended `transferred`, because step 5 comes after step 4. Without `since`, nothing changes.
- **Tests (fakes count sends per customer per step across both pages, with page 1002 standing in for the target).** Each table row lists, by hand, every send it expects:
  - **Full move:** hold → copy → a new subscriber while held → tick (nothing) → release → catch-up → activate. Every step is sent once, after the hold only from the target.
  - **Failed copy:** hold → copy → `hold:false`. Everything is sent from the source, once.
  - **Reply after the switch:** the customer replies on the new page after the switch; the catch-up does not bring the row back.
  - **Mid-send:** the hold lands while step 2 is being sent (hooked on the HTTP response), for a completed and a failed move. Step 2 is sent once, and the rest comes from one page.

**Gaps for the contract, not fixable on one side (in `TODO.md`):**
- **Ended on the source during the move:** a customer whose subscription ends on the source between the copy and release (exit-on-reply, an unsubscribe from a flow or the inbox) stays held on the target and is activated there. The `sequences` item has no status, and an import can't end a row. Suggested contract addition: catch-up items with `"status": "ended"`, and the target ends its held row. The same applies to a row made or changed during the hold whose customer then replies on the new site before the catch-up.
- **Clock skew:** if Forbeast uses its own hold time as `since` and its clock is ahead of this site's, a subscription made just after the hold can be missed. Suggested: use `held_at` from the hold answer.
- **A hold that never ends:** only `release` and `hold:false` clear the flag. If Forbeast's `hold:false` never arrives, the page's follow-ups stay held and no screen shows why. The flag is visible in page_settings history (`transfer.hold`).

## Contract amendment 3: status on the catch-up (this site's side)

The gaps above are closed by the reviewer's ruling. The end of the move is now: check → hold source → copy values, sequences, fields → switch (adopt on target) → **sequences catch-up** → release source → fields catch-up → activate target. Forbeast drives the order; this site's endpoints follow it.

**As built:**
- **`export?part=sequences` items carry `status`.**
  - Every item is `"status": "active"` (running, page-off paused, or held by the hold).
  - With `since` only, a row ended on the source since the hold (`unsubscribed`, `completed` or `error`, e.g. exit on reply, an unsubscribe from a flow or the inbox, finished) also comes, as `"status": "ended"` with `"exit_reason"`; a finished one has `exit_reason: "completed"`.
  - Rows ended by release (`transferred`) or by an earlier failed move here (`transfer not activated`) are never `ended`. Asked after release, a `transferred` row still comes as `active`, as before, so a late catch-up keeps working.
- **Import of `sequences`:**
  - `status` is optional (absent = `active`); anything other than `active`/`ended` → 422 `items.N.status`, nothing saved.
  - An `ended` item ends this transfer's held row for that sequence and customer (`unsubscribed`, or `completed` when the reason is `completed`; the reason kept, up to 60 characters; no send time). It is never sent and activate skips it. With no held row it does nothing and makes no row.
  - An `active` item upserts held as before. A row this site ended **after the switch** (e.g. the customer replied on the new site) is never re-opened. The switch is recorded by `adopt` as a page setting `transfer.adopted = <transfer id>` (history kept; its `created_at` is the switch time, and a retried adopt keeps it). The first copy comes before the switch, so a stale ended row from an older move away is still brought back then, as before.
- **Hold answer:** `{"held": n, "held_at": "<ISO 8601 with offset>"}`. `held_at` is now taken just before the hold, on this site's clock. `hold:false` is unchanged and idempotent.
- **Owners screen:** a page held here shows "Follow-ups paused for transfer #<id> since <time>" (Manila time) from the active `transfer.hold` setting. No expiry timer; Forbeast keeps retrying `hold:false` on its stuck/failed path.
- **No migration.** The marker is a page_settings row.

## PageSettings name match with "Ñ" (QA on SQLite)

`PageSettings::pageQuery` compares `LOWER(TRIM(page_name))` with PHP's `mb_strtolower`. **On MySQL it cannot mismatch; no code change.**
- **MySQL:** production runs MySQL with utf8mb4 and `utf8mb4_unicode_ci` (`config/database.php`). MySQL's `LOWER()` uses the column's Unicode case mapping for utf8mb4 (`LOWER('Ñ')` = `ñ`), and the `=` comparison is case-insensitive under that collation anyway, so both sides match.
- **SQLite:** its built-in `LOWER()` folds ASCII only, so `Ñ` stays `Ñ` and the match fails. That is what QA saw, and it affects only QA and test databases.
- **PostgreSQL:** `lower()` follows the database locale. With a C/POSIX locale non-ASCII letters may not fold, so the same mismatch is possible there.
- **Not run against a live database:** this worktree's local database is PostgreSQL and was not running, and the local MySQL needs credentials this session doesn't hold. The MySQL behaviour above comes from MySQL's documented utf8mb4 case mapping and collation.
- **Suggestion for the owner (not built, legacy rule):** the SQLite/pgsql path could compare `page_name` in PHP, or keep a lower-cased key, if QA keeps running on SQLite.

## Story changes

- **S-164.3:** added that a name `_all` → 422 (from the review: the Setup box name), and that a taken page is listed on `/chatbot/pages` with the bot Off (needed for "until the CEO sets it up and turns it on").
- **S-164.5:** added "while held, a new subscribe does not restart them at step 1 and exit-on-reply ends them" (from the review).
- **S-164.6:** activate releases only the rows held by its own transfer (from the review).
- **S-164.5:** a missing name lists the names under `names` (Forbeast's contract notes).
- **S-164.9 (new):** copied follow-ups keep the customer's last message time (the ruling on the escalation), including "clear all fields" and a future time (from the review).
- **S-164.10 (new):** each Forbeast refusal code shows a plain message (Forbeast's contract notes).
- **S-164.11 (new):** the hold on the source, held-on-create, and `hold:false` resuming as before (contract amendment 2). Added from the code: page-off rows are not resumed while held.
- **S-164.12 (new):** `sequences?since=` for the catch-up, also after release; no step sent twice in a full or a failed move (contract amendment 2). Added from the code: rows held by an earlier failed move to this site are not copied.
- **S-164.12 (order changed):** the end of the move is now switch → catch-up → release → activate (contract amendment 3). The move test now uses the hold answer's `held_at` as `since`.
- **S-164.13 (new):** catch-up items carry `status`; an ended item ends the held row; a release ending is never `ended`; no re-open after the switch; the Owners hold note (contract amendment 3). Added from the code: an `ended` item without a held row makes none, and a customer who replies on the old page, is subscribed again during the hold, and then replies on the new page stays ended.
- **S-164.4 (shape):** the exact export item now ends with `"status": "active"`.
- **S-164.6 (wording unchanged, behaviour narrowed):** release still ends subscriptions `transferred`, and now keeps their step and send time for the catch-up.
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
| S-164.9 | `ChatbotSequenceEngineTest::test_S_164_9_a_copied_follow_up_uses_the_customer_last_message_time_from_the_old_site` (4 rows: 10:00 / 12:00 / 14:00 → sent; older than 24 h → skipped "outside 24h"; none → skipped; cleared all fields → sent), `GatewaySitePagesTest::test_S_164_9_sequences_carry_the_customer_last_message_time_and_the_import_keeps_the_newer_one` | pass |
| S-164.10 | `PageOwnersScreenTest::test_S_164_10_each_forbeast_refusal_code_is_shown_as_a_plain_message` (7 rows) | pass |
| S-164.11 | `GatewaySitePagesTest::test_S_164_11_hold_pauses_the_page_follow_ups_new_ones_are_created_held_and_hold_false_resumes_them_as_they_were` | pass |
| S-164.12 | `GatewaySitePagesTest::test_S_164_12_export_sequences_since_the_hold_lists_the_ones_created_or_changed_since_then_also_after_release`, `ChatbotSequenceEngineTest::test_S_164_12_a_page_move_never_sends_a_step_twice` (7 rows: completes; copy fails → hold:false; reply on the new page after the switch; unsubscribed on the old page during the hold; replied and subscribed again on the old page then replied on the new; hold mid-send + completes; hold mid-send + copy fails) | pass |
| S-164.13 | `GatewaySitePagesTest::test_S_164_13_the_catch_up_carries_each_status_ended_since_the_hold_but_never_a_release_ending`, `…an_ended_item_ends_the_held_row_and_a_row_ended_here_after_the_switch_is_never_reopened`, `PageOwnersScreenTest::test_S_164_13_a_page_held_here_for_a_transfer_says_its_follow_ups_are_paused_since_when`, and the two new rows of `test_S_164_12_a_page_move…` | pass |
| S-164.8 (P2) | `PageOwnersScreenTest::test_S_164_8_the_screen_is_cards_that_fit_a_phone` | pass: markup only (no wide table, `min-w-0` cards, full-width inputs, viewport meta) |

Red runs:
- **Part P:** every Part P test failed with 404 (no routes) before the code.
- **Taken-page listing:** the test failed with `['Page Uno']` instead of `['Page Tres', 'Page Uno']`.
- **Screen:** its tests were run red by taking the new code out: 404 on every case. Then the code was put back, and they passed.
- **Review fix:** 5 failures before the fix (the `_all` rows, the held status, activate scoping).
- **The 24 h ruling and the contract notes:** 12 failures before the code. The 7 refusal rows showed Forbeast's raw text. The export had no `last_message_at`. The import stored no time. The two `missing_names` bodies had no `names`. The 10:00 / 12:00 / 14:00 row sent nothing (`[]` instead of `['Seq 2']`). The "older than 24 h" and "none" rows passed before and after: they pin today's rule.
- **Review minors fixed:** 2 failures before the fix. The "cleared all fields" row sent nothing. A future time was stored as `2026-12-31 00:00:00` instead of now.
- **Contract amendment 2:** both S-164.11 and S-164.12 (GatewaySitePagesTest) failed with 404 (no hold route) before the code. The first green run of S-164.11 then caught a page-off row resumed during the hold ("The unexpected RunChatbotSequenceStep job was pushed"), and `resumePaused` was fixed. The stale-row case failed ("two arrays are identical") with the release clean-up disabled. The move test's first two rows were written with the code. They were run red against two mutations, no held-on-create and release nulling the send time, and each failed.
- **Review fix (amendment 2):** 5 failures before the fix.
  - The reply row sent `1002 7001 Seq 2` / `Seq 3` after the reply: the catch-up brought the row back.
  - Both mid-send rows sent `Seq 2` twice.
  - The since export also listed 7001, changed only by release.
  - The hold answer had no `held_at`.
- **Contract amendment 3:** 7 failures before the code (after one test fix: the move test passed the ISO `held_at` straight to `export()`, which the controller normally converts to local time).
  - The "unsubscribed on the old page" row sent `1002 7001 Seq 2` / `Seq 3`.
  - The "subscribed again" row sent `1002 7001 Seq 1`–`Seq 3`: the catch-up re-opened the row.
  - The catch-up export had no `status` (ErrorException on the missing key).
  - The import accepted `"status": "gone"` (200 instead of 422).
  - The Owners screen had no hold note.
  - Two expectations of mine were wrong and were corrected, not the code: without `since` the export lists a row held by the hold as `active` (as before), and the Owners cards are sorted by name.

## Suite

`php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings`

- **Baseline at 87cb0e1e:** 1335 tests, OK (1 skipped).
- **First run on the branch:** 1375 tests, 3 failures. All three were the tab-list expectations above, since updated.
- **After the first review fix:** 1378 tests, OK (1 skipped, the same as the baseline); no new failures.
- **Final, after the 24 h ruling, the contract notes and the second review:** 1390 tests, 0 failures, 0 errors, 1 skipped (the same as the baseline); no new failures. PHPUnit also reports 57 runner deprecations. They are doc-comment test metadata in older test files (e.g. `BotcakeAsyncSendTest`), none in the 164 tests.
- **After contract amendment 2 (hold):** 1394 tests, 0 failures, 1 skipped.
- **After amendment 2's review fix:** 1397 tests, 9375 assertions, 0 failures, 0 errors, 1 skipped, 57 runner deprecations.
- **Final, after contract amendment 3:** 1402 tests, 9402 assertions, 0 failures, 0 errors, 1 skipped (the same as the baseline), 57 runner deprecations as before. No new failures against 87cb0e1e.

## Review

**Contract amendment 3:** `skeptic-reviewer`, standard depth, one pass on `c92cb27a..6217de52`. No blocker or major; the three affected test files pass. Minors:
- **Fixed:** S-164.13 had no story and the S-164.12 story described the old order (`qa/stories.md`, this result).
- **Accepted, in `TODO.md`:** the move test sets `transfer.adopted` directly; an `error` source row ends as `unsubscribed`; one settings query per page on the Owners screen; a one-second precision on "ended after the switch".

**Earlier rounds:**

`skeptic-reviewer`, adversarial depth, opus, on 87cb0e1e..HEAD: request changes, with 3 majors.

Fixed in one loop:
- **Held rows:** they were not seen by subscribe or exit-on-reply. They are now `paused` + `transfer <id>`.
- **Activate:** it released stale held rows from a failed transfer. It is now scoped to its own transfer id.
- **The `_all` page name** (a minor on an untrusted path): now refused.

Escalated: the 24 h window. It is now resolved by the ruling (above).

**Second pass:** `skeptic-reviewer`, adversarial depth, opus, on `72104a87..6edee632` (the ruling and the contract notes). Verdict: approve, minors only.
- **Fixed** (commit `b3ae597e`, test-first):
  - "clear all fields" erased `__last_message_at`, so a copied step after such a flow was skipped.
  - A future `last_message_at` was stored as sent. Now it is capped at now.
  - S-164.9 and S-164.10 were missing from the stories and the case table.
- **Accepted, with reasons in `TODO.md`:**
  - no customer name until they write here;
  - the time pattern appears twice;
  - concurrent identical imports can answer 500 once (Forbeast retries).
- **No change:** a note that the export takes the latest chat by time while `runStep` takes it by id; both give the same answer in practice.

**Third pass (contract amendment 2):** `skeptic-reviewer`, adversarial depth, opus, on `5fac5aa4..18241400`, one pass as asked; no re-check of the fix. It found 3 majors and 9 minors.
- **Fixed in one loop** (commit `f7993413`, test-first, 5 red rows first):
  - **Major, mid-send:** a step being sent when the hold lands was sent but not advanced, so it was sent again by the target, or by the source after `hold:false`.
  - **Major, release bump:** release bumped `updated_at`, so the catch-up carried every row and brought back rows the new site had ended (a reply after the switch).
  - **Minors on the new route:**
    - `hold:false` is now scoped to its own transfer, and a newer hold takes over;
    - the transfer id is required;
    - `held_at` is answered, against clock skew;
    - the unlisted `released` key is dropped;
    - the restart-while-held and stale-row status tests were added.
- **Major, open (contract gap, needs both sides):** a source-side exit during the move is not carried to the target. See "Gaps for the contract" above and `TODO.md`.
- **Accepted, with reasons in `TODO.md`:**
  - the hold flag never expires;
  - a 2 s settings memo can let a subscription made right at the hold start un-held;
  - "next send" is shown on ended `transferred` rows;
  - the rarer tests: unsigned hold, and `hold:false` after release.

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

164: done — catch-up items carry active/ended with exit_reason, an ended item ends the held row and nothing ended on the new site after the switch is re-opened, held_at is the since, and the Owners screen shows a held page; 1402 tests green, no new failures vs 87cb0e1e.
