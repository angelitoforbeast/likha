# Result 191: show "typing…" while the bot is thinking

Status: done. Branch `feat/191-typing-while-thinking`, based on fa8c71ee (live on Likha and Incepxion). There is no migration and no new dependency. Flows, sending and every other screen are unchanged.

## What shipped
- **New class `app/Services/Chatbot/GatewayTyping.php`.** `GatewayTyping::start($pageName, $pageId, $psid)` makes one `POST {GATEWAY_URL}/v1/pages/{page_id}/typing` with the JSON body `{"psid": "<psid>"}`.
  - **Authentication** is the same as the send (`GatewayProvider`): the page's own Forbeast token, otherwise the global `GATEWAY_KEY`, sent as Bearer.
  - **URL:** `GATEWAY_URL` already carries the `/api` prefix (the send posts to `$url . '/v1/pages/{id}/messages'`). So this is the contract's `{gateway}/api/v1/pages/{page_id}/typing`.
  - **One place to change:** the path and body live only in the private `call()` method, so an amendment to the Forbeast 028 path or body is a one-line change.
- **Where the call is made:** `ChatbotRunner::handle()` (the run of `RunChatbotFlow`, one per customer chat). It is placed inside the loop over the customer's unrun chats, just before the chat's `FlowRun` is built and `FlowEngine::run` starts, so it comes before the first flow or AI step.
  - The call comes after every skip, so there is no call for:
    - an ignored attachment (`_ignored`);
    - a page with no flow, or a page whose bot is Off (`ChatbotPage::flow` returns null; a Forbeast event for a bot-Off page also stops earlier, in `HandleGatewayEvent`);
    - a paused customer;
    - a chat that is too old (30 min);
    - a Botcake standby copy.
  - `GatewayTyping` itself also skips:
    - a page whose provider is not `gateway`, so a Botcake page gets no call (this is the same check that picks the send route);
    - the case where replies are switched off (`CHATBOT_REPLIES_ENABLED=false`);
    - the case where Forbeast is not set up (no URL, or no token and no key).
  - Paths that never reach the call:
    - Test runs (`/chatbot/test`, `ChatbotTabsController`);
    - sequences and chatblast (`ChatbotSequences`, campaigns);
    - the AI and send resumes (`resumeAi`, `resumeSend`);
    - wait skips (`skip`).
- **Once per customer per 15 s:** `Cache::add('gateway-typing:{page_id}:{psid}', 1, 15)`.
- **Never blocks or fails the run:**
  - The connect and total timeouts are both 1.5 s.
  - A connection error or timeout is ignored silently.
  - Any other error is `report()`ed and ignored.
  - A non-2xx answer only counts as failed.
  - Setting or cache errors before the call are reported, and the run continues.
- **Synchronous, not queued:** the call is synchronous with a 1.5 s bound, and that is deliberate. "Typing…" must reach Forbeast before the first send of the same run. The worst case is +1.5 s on the first message of a customer, at most once per 15 s, and only while Forbeast is slow or unreachable. A normal 2xx takes one round trip.
- **Counts, with no text:** daily counters (Manila day, kept 3 days) `gateway-typing:{Y-m-d}:calls` and `gateway-typing:{Y-m-d}:failed` in the cache. This is the same pattern as `gateway-events:`.

## Config key
`services.gateway.typing` reads env `GATEWAY_TYPING`, default `true` (on). `GATEWAY_TYPING=false` turns the call off with no deploy of code: set the env, then `php artisan config:cache`.

In `phpunit.xml`, `GATEWAY_TYPING=false`, so the other test suites never make an outbound typing request through a partial `Http::fake`. `GatewayTypingTest` turns it on.

## Case table (S-191)
| Case | Test | Result |
|---|---|---|
| S-191.1 one call with page and psid, before the first step | `test_S_191_1_a_customer_message_on_a_gateway_page_calls_typing_once_before_the_first_step`; `test_S_191_1_a_page_with_its_own_forbeast_token_calls_typing_with_that_token` | pass. The URL is `…/v1/pages/1001/typing`, the body is `{"psid"}`, and the Bearer is the global key or the page token, the same as the send. The order is typing, then messages. Both timeouts are ≤ 1.5 s. |
| S-191.2 two messages within 15 s → one call | `test_S_191_2_two_messages_from_the_same_customer_within_15_seconds_make_one_call` | pass. Another customer gets their own call. A new call comes after 16 s. Every message is still answered. |
| S-191.3 Botcake, bot Off, ignored attachment, Test, sequence → no call | `test_S_191_3_no_call_for_botcake_bot_off_ignored_attachment_paused_test_run_or_sequence`; `test_S_191_3_no_call_when_replies_are_off_or_forbeast_is_not_set_up` | pass. A paused customer, replies Off, no key and no URL also get no call. The sequence step is shown to send through Forbeast. |
| S-191.4 timeout or 500 → the reply is sent as before | `test_S_191_4_a_typing_timeout_or_500_does_not_stop_the_reply`; `test_S_191_4_a_non_connection_error_is_reported_and_the_reply_still_sent` | pass. The reply is sent and no run fails. The counters show calls 2, failed 2. |
| S-191.5 switch off → no call | `test_S_191_5_the_switch_off_means_no_call`; `test_S_191_5_the_switch_is_on_by_default` | pass. With the env unset, the config default is true. `GATEWAY_TYPING=false` gives false. |
| S-191.6 existing suites | `tests/Feature/Chatblast tests/Feature/Botcake` | pass. No new failures (see Tests). |

## Tests
`php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake`:

| Run | Tests | Failures | Skipped |
|---|---|---|---|
| Baseline at fa8c71ee | 1463 | 0 | 1 |
| After the feature, with typing on by default in every test (before the `phpunit.xml` switch) | 1469 | 0 | 1 |
| Final | 1472 | 0 | 1 |

The 58 PHPUnit deprecations are the same in all three runs.

## Story changes
- I added S-191 to `qa/stories.md`.
- S-191.1 also pins the page's own Forbeast token, so the key matches the send.
- S-191.3 also covers a paused customer, replies Off, and Forbeast not set up.
- S-191.4 also covers a non-connection error.

## Review
A skeptic review found no blocker and no major. I fixed these:
- The minors:
  - non-connection errors are now reported, not swallowed;
  - typing is off in the other tests through `phpunit.xml`;
  - tests were added for the page token, replies Off, no URL or key, and a non-connection error.
- The nit: the default-on test now really checks the config default.

Accepted as the spec intends: a run whose flow ends without sending (for example a condition leading to an end, or a wait) still shows "typing…" until Meta turns it off (about 20 s). "Will answer" here means "not skipped".

## Deploy notes
- **Deploy after Forbeast 028 is live.** Deploying earlier is harmless:
  - Before 028, the endpoint answers 404 (or something else that is not 2xx), and that is only counted as failed.
  - A connection error is ignored.
  - The extra cost per customer is one fast request (one round trip to Forbeast) at most every 15 s.
- No migration. Steps: `php artisan config:cache`, then restart the queue workers (Horizon) so `ChatbotRunner` loads the new code.
- **Incepxion:** this applies there too. It is the same code, and it acts only on pages whose `chatbot.provider` is `gateway`. Pages on Botcake are untouched.
- **Rollback without a code deploy:** `GATEWAY_TYPING=false`, then `config:cache`.

## Commits
- 07f67f8e docs: save the typing while thinking brief (spec 191)
- cf83ceae feat: show typing on Forbeast pages at the start of a bot run, once per customer per 15 s (spec 191)
- a5c88f4b fix: typing reports non-connection errors, stays off in other tests, pins token, setup and default cases (spec 191)

191: done — a customer message on a Forbeast page with the bot On now makes one typing call (`POST /v1/pages/{id}/typing`, 1.5 s bound, deduplicated per customer for 15 s, errors ignored) at the start of the run in `ChatbotRunner`, switchable with `GATEWAY_TYPING`. The Chatblast and Botcake suites have no new failures (1463 → 1472, 0 failures).
