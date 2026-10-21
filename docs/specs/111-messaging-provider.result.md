# Result 111: each chatbot page runs through Botcake or the Forbeast Gateway, chosen per page

## What shipped

- **Seam** (`app/Services/Chatbot/`): `MessagingProvider` (send for page + psid with a `client_ref`; result in the `BotcakeOutbox::outcome` shape), `BotcakeProvider` (today's code moved as is: token check, `shared(…, 15)`, `once(15)` for the Inbox, `outcome()`), `GatewayProvider` (`POST {GATEWAY_URL}/v1/pages/{page_id}/messages`, Bearer key, `{psid, messages, messaging_type: RESPONSE, client_ref}`, no retry; empty URL or key → failed "The Forbeast Gateway is not set up…", no call), `ChatbotProvider` (resolver: `chatbot.provider` = `gateway`, else `botcake`; `client_ref` = `likha-<ULID>`).
- **Inbound:** `POST /api/gateway/events` (`GatewayEventController`): no secret → 503, body > 64 KB → 413, bad/missing `X-Gateway-Signature` → 401, not JSON → 400, else queued `HandleGatewayEvent` (chatbot-high queue) and 200. The job stores message/postback/referral for gateway pages as a `botcake_chats` row (name from `botcake_psids`, else the latest named chat, else blank; `message_id` = mid or `gw-event-<event_id>`; payload `source: gateway`, `ad_id`, `image_url`, `postback`, `quick_reply`) and runs `ChatbotInbound::afterStore`, the after-store step moved out of `BotcakeChatController` and shared by both paths (sequence exit, `MarkChatbotReplied`, `RunChatbotFlow`). Dedupe: atomic cache add + no existing `message_id` (so also against a Botcake push). Botcake pages, echo/delivery/read/standby, unknown pages, Bot Off and events with no id only count (`gateway-events:<date>:<what>`, 3 days).
- **Botcake push on a gateway page:** `{ok: true}`, nothing stored, guard counter `gateway_page` ("Page uses the gateway") and a log line (`BotcakeChatController.php:128`).
- **UI:** `/chatbot/pages` Active cell has a "Replies via" select (Botcake / Forbeast Gateway; gateway disabled with "(not set up)" when `GATEWAY_URL` is empty), saved by `POST /chatbot/provider` (same access as a cell, throttled 30/min) with `PageSettings::set` (history). `provider` joined `ChatbotFields::RESERVED`, so it is no botfield column, variable or check value; Changes shows it as "Provider".

## Send paths and how they route now

| Path | Where | Route |
|---|---|---|
| Flow send node, AI reply (live) | `FlowEngine.php:243/247`, `:394/398` → `BotcakeOutbox::send` `BotcakeOutbox.php:34` | `ChatbotProvider::for(page)` → Botcake or Gateway |
| Async send pool | `BotcakeSendQueue::async` `BotcakeSendQueue.php:45` | only for Botcake pages; gateway pages send directly through the outbox (the gateway queues, orders per psid and rate-limits) |
| Pool's own requests | `BotcakeSendPool.php:297` | unchanged, Botcake only (only Botcake pages reach it) |
| Resumed runs (after AI / after pool send) | `ChatbotRunner.php:69/135/214` → same outbox | follows the page |
| Sequence steps | `ChatbotSequences.php:314` → same outbox | follows the page |
| Inbox manual send | `ChatbotInboxController.php:151` | `ChatbotProvider::for(page, once: true)` |
| Chatblast (send_flow + custom field) | `ChatblastSender.php:432`, pool | unchanged, Botcake (out of scope) |
| Test tab | `TestOutbox` | unchanged, no real send |

## Env keys (names only; set on the server, `.env.example` updated)

`GATEWAY_URL`, `GATEWAY_KEY`, `GATEWAY_WEBHOOK_SECRET`, `GATEWAY_TIMEOUT` (default 10). The gateway's webhook URL for this system: `https://likhaaitech.com/api/gateway/events`.

## Migration

None.

## Checks done in code only

- `botcake.page_id` is the id Botcake puts in `{{page_id}}` and its API paths; Botcake keys pages by the Facebook page id. Not compared with live data (no production access here): compare one page with the gateway's `GET /v1/pages` before switching it.
- Botcake's `message_id` being the Facebook mid is unconfirmed; it only matters during a switch (see TODO).
- PSIDs: new code keeps them as strings (`botcake_chats.psid` is a string). Integer columns that would break for non-numeric ids: `chatbot_replies.psid`, `chatbot_customer_values.psid`, `botcake_send_requests.psid`/`page_id`. Facebook PSIDs are numeric, so nothing breaks today. The existing `(int)` casts that feed these columns stay, and are listed in TODO.md.

## Case table

Test file: `tests/Feature/Chatblast/ChatbotProviderTest.php`. Its last run: OK (15 tests, 93 assertions).

| Case | Test | Result |
|---|---|---|
| S-111.1 | `test_S_111_1_a_gateway_pages_flow_sends_through_the_gateway_and_a_botcake_page_as_before` | pass |
| S-111.2 | `test_S_111_2_a_gateway_refusal_or_missing_setup_fails_the_send_with_a_clear_reason` | pass |
| S-111.3 | `test_S_111_3_a_gateway_page_skips_the_botcake_send_pool` | pass |
| S-111.4 | `test_S_111_4_…runs_the_flow_once`, `test_S_111_4b_…` (5 shapes: referral without mid, postback, photo, like, video + bad ad id), `test_S_111_4c_…` (bot off, no ids, 400, 413) | pass |
| S-111.5 | `test_S_111_5_a_gateway_event_for_a_botcake_page_or_an_echo_only_counts` | pass |
| S-111.6 | `test_S_111_6_a_bad_or_missing_signature_is_401_and_no_secret_fails_closed` | pass |
| S-111.7 | `test_S_111_7_a_botcake_push_for_a_gateway_page_is_accepted_but_stores_and_runs_nothing` | pass |
| S-111.8 | `test_S_111_8_the_provider_select_saves_with_history_shows_in_changes_and_needs_the_gateway_set_up` | pass |
| S-111.9 | `test_S_111_9_an_inbox_manual_send_on_a_gateway_page_goes_through_the_gateway` | pass |

Red runs: before code, all 9 first tests failed on the missing behaviour (route 404, `Undefined array key "provider"`, Botcake called instead of the gateway, a pool ticket made). `S_111_4c` failed with "Failed asserting that 1 is identical to 0" (an event with no id was stored) before its fix.

## Suites

- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` on the final tree: **OK, 884 tests, 6247 assertions, 1 skipped.**
- Full suite: 2271 tests, with the same two reds as the baseline on `main` (before any change): `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (failure) and `Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (error). No new failures. The full run started before the fix commit; the 6 tests that commit added pass in the file run and the suite run above.
- `ChatbotTagsTest::test_S_100_5` failed once in a suite run and passed in 3 reruns. It is time-sensitive (no frozen clock) and not on a path this spec changed.

## Review

One `skeptic-reviewer` pass at adversarial depth over the whole diff (one pass instead of one per task, to keep the brief lean). No blockers. One major: the inbound branches were untested (referral/postback/attachments/`ad_id`/Bot Off/400/413). Fixed test-first in `9c358ffd`; that fix also covers the minor "no mid and no event_id becomes a fixed key" (now skipped and counted `no_id`). The minor "`catch (Throwable)` hides bugs" is also fixed: non-connection errors are reported. The fix was not sent back for a reviewer re-check, because it is test-proven and small. These are accepted in TODO.md with reasons: no throttle on the webhook, the switch window, Botcake `message_id` vs mid, page id checked in code only, the remaining PSID casts and the in-flight pool ticket at a switch.

## Notes

- A gateway reply row is "sent" when the gateway **accepted** it (202). The final delivery (`GET /v1/sends/{id}`, `status_callback`) is not read in this spec.
- Browser check: not run (no browser tools in this session). The select is plain DOM in the existing row render; the test proves `data-provider-url` is on the page.
- Story changes: none. The stories were written from the spec in `qa/stories.md` (S-111), since the spec brought none, without a separate story-writer run.
- Conflict with the local CLAUDE.md: the plan was committed and work started without a separate "go", because the brief said "Start now".

## Commits

- `8c25bc54` docs: spec and plan for per-page messaging provider (spec 111)
- `d90ebec9` feat: choose Botcake or the Forbeast Gateway per chatbot page (spec 111)
- `9c358ffd` fix: gateway events without an id are not stored; inbound shapes tested (spec 111)
- this commit: docs: result (spec 111)
