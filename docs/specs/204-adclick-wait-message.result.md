# Result: spec 204

**Status:** done
**Date:** 2026-10-30
**Branch / PR:** `feat/204-adclick-wait-message` (worktree `.claude/worktrees/adclick-204`), cut from 8506b25c. Not pushed, no PR.
**Preview or run link:** none. This is a backend change; the evidence is the tests below.

## Summary
A Forbeast `referral` (the customer opened a Click-to-Messenger ad) no longer starts the bot.
- The referral's chat row is still stored with its ad, plus a new payload mark `_ad_open: true`.
- That row gets no run, no sequence exit and no Results reply.
- The customer's first message or postback starts the Main Flow from Start. So the opening (image + greeting) is the first thing they receive.
- `AdGroups::latestAd` (unchanged) gives that first reply the ad's group.

Amendments 204-1, 204-2 and 204-3 are included. 204-3 replaced 204-2's 2-second hold with an ordering guarantee:
- The referral is stored inside the POST, before the 200.
- Forbeast posts the next event of the same customer only after that 2xx.
- So the referral row always exists before the message's queued job runs.

204-3 A3 is also in. When a send of the step the customer is waiting on comes back failed (or partial with nothing delivered), it is marked `__wait_unsent`. The next message then re-asks that step instead of being taken as its answer.

## Case table
Command: `php vendor/bin/phpunit --testdox --filter "S_204|S_184_5|S_194_4d|S_194_3|S_111_4b" tests/Feature/Chatblast`. Output: 34 tests, 157 assertions, OK (the deprecation notices are the base's own).

| Case | Test(s) | Result | Red run (behaviour missing) |
|---|---|---|---|
| S-204.1 | `AdOpenWaitTest::test_S_204_1_an_ad_open_alone_is_recorded_with_its_ad_and_is_not_a_reply_or_a_run` | pass | Base: `Undefined constant ChatbotInbound::AD_OPEN`. With afterStore restored for referrals: "The unexpected [App\Jobs\RunChatbotFlow] job was pushed." |
| S-204.2 | `test_S_204_2_the_first_message_after_the_ad_open_starts_the_main_flow_from_its_first_step_with_the_ad` | pass | Base: "Failed asserting that actual size 2 matches expected size 1" (the referral also queued a run). |
| S-204.3 | `test_S_204_3_a_referral_after_the_message_or_a_repeated_referral_does_not_start_or_double_the_flow` | pass | Base: "Failed asserting that two arrays are identical" (extra run / sends). |
| S-204.4 | `test_S_204_4_a_referral_inside_a_running_flow_changes_nothing_in_the_run`; `test_S_204_4_the_wait_timeout_measures_the_24_hour_window_from_the_last_message_not_from_an_ad_open` | pass | Base: arrays differ, because the ad open counted as a reply. With the `skip()` `$last` line reverted: "Still there?" was sent outside the window. |
| S-204.5 | `test_S_204_5_a_postback_starts_the_flow_as_today_and_the_botcake_webhook_path_is_unchanged` | pass | Characterisation: it passes on the base by design. |
| S-204.6 | `AdOpenReadersTest::test_S_204_6_an_ad_open_is_not_a_customer_message_on_health_speed_echoes_or_live`; `AdOpenReadersTest::test_S_204_6_an_ad_open_is_not_a_reply_for_results_or_a_burst_on_health`; `ProblemsPanelTest::test_S_184_5…` (one row added); `GatewayPerPageTest::test_S_194_4d…` (one row added) | pass | Each reader was reverted one at a time, and each failed its assertion: BotHealth inbound, ResponseSpeed, ChatEchoes, LiveFeed in and active, ProblemsPanel quiet, ForbeastWatch rerun ("once per customer"), ChatbotTags backfill, BotHealth slow burst. |
| S-204.7 | `AdOpenReadersTest::test_S_204_7_health_shows_the_customers_who_opened_an_ad_and_never_wrote_per_day` | pass | Base: "Undefined array key" (no figure). |
| S-204.8 | `test_S_204_8_the_first_message_gets_the_reply_of_the_customers_latest_ad` (6 rows) | pass | Base: 4 of 6 rows fail, because the referral's run sent first. |
| S-204.9 | `test_S_204_9_the_referral_is_stored_before_the_200_so_the_queued_message_finds_its_ad`; `test_S_204_9_the_event_jobs_in_either_order_give_the_ads_reply_and_one_start` (2 rows); `test_S_204_9_a_referral_that_fails_to_store_answers_500_and_its_retry_is_stored_once` | pass | Controller reverted: the row is not stored before the 200 ("Failed asserting that two arrays are identical"). |
| S-204.10 | full `tests/Feature` | pass (no new failures) | see Tests |
| S-204.11 | `test_S_204_11_a_question_that_comes_back_failed_is_asked_again_on_the_next_message` (4 rows); `…_asked_again_once_and_a_late_or_manual_failure_marks_nothing`; `…_a_failure_written_while_a_run_is_in_progress_does_not_outlive_the_wait` | pass | SendStatus reverted: 2 of 4 rows fail. Guards removed: the late/manual test fails. CustomerFields reverted: the race test fails. |

## Story changes
**Stories.** The S-204 stories were added to `qa/stories.md`, then updated for the amendments:
- S-204.9 now says "stored before the 200" instead of the 2 s hold of 204-2.
- S-204.11 is new (204-3 A3).
- S-204.4 gained the 24-hour-window case.
- S-204.6 names every reader that was changed.
- Tests added after the review: S-204.4 (24 h window), S-204.9 (failure path), S-204.11 (asked again once, late failure, manual failure, race) and S-204.6 (Results backfill and Health burst).

**Changed assertions in existing tests:**
- `ChatbotProviderTest::test_S_111_4b…`, row "referral without mid, from an ad": the stored payload now has `_ad_open: true`. For that row the test asserts that RunChatbotFlow is not pushed (it was pushed once before). The other rows are unchanged.
- `GatewayPerPageTest::test_S_194_3_the_same_message_from_both_gateways_is_processed_once`: `RunChatbotFlow` is pushed 1 time, not 2. Only the message runs; the referral is still stored once.
- `GatewayPerPageTest::test_S_194_4d…`: an ad-open row is added after the first customer's failed reply. The re-run must still answer that customer.
- `ProblemsPanelTest::test_S_184_5…`: an ad-open row is added inside the last 30 minutes. "No chats" must still fire.

## Done-when checklist
- [x] **P1 cases pass.** See the case table (34 tests OK).
- [x] **The full `tests/Feature` run adds no new failures vs 8506b25c.** Same 2 known failures; see Tests.
- [x] **The list of referral readers.** See "Referral readers, before / after" under Rulings.
- [x] **Deploy notes.** No migration, restart the queue workers, and how to watch the first hour.
- [x] **The final line.** It is at the end of this file.

## How to run
Run from the worktree:
- The new tests: `php vendor/bin/phpunit tests/Feature/Chatblast/AdOpenWaitTest.php tests/Feature/Chatblast/AdOpenReadersTest.php`
- The full run: `php vendor/bin/phpunit tests/Feature`

PHP is Herd's `C:\Users\Forbeast\.config\herd\bin\php84\php.exe`.

## Rulings
1. **The marker.** The referral row gets `payload._ad_open = true` (`ChatbotInbound::AD_OPEN`). 204-3 gave `kind: referral` only as an example. A `_`-prefixed key matches the existing `_ignored` / `_standby` marks that readers already skip.
2. **Ordering instead of a hold (204-3 A1).** For a single non-echo `referral` event, `GatewayEventController` calls `HandleGatewayEvent::handle` directly inside the request and only then answers 200.
   - It does not use `dispatchSync`, because that would go through the queue.
   - If the store throws, the controller answers 500. The dedupe keys are released as before, so the gateway's retry is stored exactly once.
   - Batches never carry referrals; they are already refused with 422.
   - `queued_at` is dropped on this path, so the queue-lag watch is not fed zeros.
3. **No time window for the ad (204-1 A1).** The first reply's ad follows today's rule, unchanged:
   - the newest `ad_id` among the customer's last 50 chats on the page, else `botcake_psids.ad_id`;
   - the newest wins, so a newer referral for another ad switches the ad;
   - a message that carries its own referral object stores that ad_id on the message row, as today.

   A 24 h limit was not added: it would change which ad is used compared with today's referral-started reply.
4. **A referral is not a reply (204-3 A2).** It gets no `afterStore` at all: no sequence exit, no `MarkChatbotReplied`, no run. The hourly `chatbot:replied-backfill` also skips ad opens.
5. **Runner.** `_ad_open` rows are passed over like `_ignored`: `__last_chat` moves past them and they are never run. In the wait timeout (`skip()`):
   - an ad open is not "the customer replied";
   - the last chat used for the input and for the 24-hour check is the last real message.
6. **A question that came back failed (204-3 A3).** `SendStatus::receive` marks `__wait_unsent = flow:node` only when all of these hold:
   - the status is `failed`, or `partial` with `sent_count = 0`;
   - the reply is a flow `send` (not manual);
   - the customer is still waiting on that same `flow:node`.

   The existing `FlowEngine` path then re-asks once. `CustomerFields` now deletes `__wait_unsent` whenever `__wait` is cleared and whenever a new `__wait` is set. So a mark that a send_status writes during a run cannot outlive its wait. A partial with something delivered counts as delivered; see Open questions.
7. **Botcake-webhook pages (.5).** Their chats come from `/api/botcake/chat`, one row per customer message, with the ad already on the message. They never get a gateway referral, so their path (`ChatbotInbound::afterStore`) is unchanged. Gateway events for a page on Botcake are only counted, as before.
8. **Conflict between CLAUDE.local.md and the start prompt.** CLAUDE.local.md wants a reviewer's "go" on the plan before code. The start prompt says to build without waiting. I followed the start prompt and committed the plan first.

### Referral readers, before / after (.6)
- **Before:** each referral row was followed by its own bot run, with the opening sent about 0.7 s after the click.
- **After:** the row is stored with `_ad_open` and no run follows it. The customer's message 2–9 s later gets the reply.

| Reader (file) | Shows | Before | After |
|---|---|---|---|
| `ChatbotRunner::handle` | runs the customer's unrun chats | the referral ran with empty input; for a returning customer, the message's job would also run it | passed over, never run; the message runs once from Start |
| `ChatbotRunner::skip` (wait timeout) | "customer replied" / 24 h window | an unrun referral counted as a reply, and its time opened the window | ignored; the window is measured from the last real message |
| `AdGroups::latestAd`, `forCustomer` (Ad-based pages) | group of the reply | the referral's ad (newest ad_id) | same rule; the first message finds the referral's ad (204-1) |
| `AdGroups` ads seen / overview | ads seen per page | counts the row | unchanged: still counts it as an ad open |
| `ChatbotTags::adId` | ad_id on every send | the first chat's ad, which is the referral's | unchanged: the referral is still the first chat |
| `ChatbotTags::markReplied` (`MarkChatbotReplied`) | Results replied_at | the click marked earlier sends as replied | not called for a referral |
| `ChatbotTags::backfill` | hourly replied_at fill | the click counted as a reply | skips ad opens |
| `ChatbotSequences::exitOnReply` | leaving "exit on reply" sequences | the click exited them | not called for a referral |
| `BotHealth::inbound` (Health inbound, answered %) | customer messages and their first reply | 2 inbound per ad click (row + message); the referral was answered by its own run | 1 inbound (the message); an ad open is never "not answered" |
| `BotHealth::slow` ("burst" in the slowest list) | burst label | n/a (the referral had its own reply) | an ad open just before the message is not a burst |
| `BotHealth::metrics` (new) | "Ad opens without a message" per day | none | customers with an ad open that day and no message from 60 s before it until now |
| `ResponseSpeed::build` / `pageSeconds` (Speed page, problems "Slow") | messages, answered, seconds | the referral counted as a message with its own fast reply | skipped |
| `ChatEchoes::build` (problems "not answered") | customers with no echo after their chat | the referral was covered by its own reply's echo | skipped: a click with no message is not unanswered |
| `ProblemsPanel::quiet` / `usual` chats | "No chats in the last N min" | an ad open counted as a chat | not counted |
| `LiveFeed::tally` in / `sources('chat')` (Live inquiries, "Inquiry received") | inquiries and incoming events | 2 per ad click | 1 (the message) |
| `LiveFeed::active` | customers who messaged in the last 5 min | ad-only customers counted | not counted |
| `ForbeastWatch::rerunFailed` | re-run of a failed reply | a later ad open was a "newer chat", so no re-run | an ad open is not a newer chat |
| `Inbox` list / thread | conversations | an empty incoming bubble, then the bot's reply | an empty incoming bubble; a customer who only clicked shows as waiting (blue dot, blank preview) |
| `Inbox::insideWindow`, `ChatbotSequences` 24 h check, `PageTransfers::lastMessages` | 24 h window | the window starts at the click | unchanged (see Deferred minors) |
| `ChatbotResults::chats`, `ChatbotController::chatStats` | chats per customer / per day | ad-only customers counted | unchanged |
| `ChatbotResults::metrics` / `byAd` | sends, replied % | the referral's opening sends counted, often "replied" 2–9 s later | fewer sends (no opening to clickers who never write); replied % changes accordingly |
| `ChatEchoes` store, `BotcakeSendPool`, `BotcakeCustomerMatcher`, `FlowEngine::history`, `FlowRun` | other | not affected (they need text, a chat_id or an echo) | not affected |

## Deferred minors
All are in `TODO.md`, section "Ad open waits for the customer's message (spec 204)":
- The "not a customer message" rule is decided separately in each reader. A shared scope is the next step.
- The 24-hour window still starts at an ad open in sequences, the Inbox and page transfers.
- Two-gateway window: if the referral insert fails on gateway A after B's copy was answered as a duplicate, the first message can run before the referral row exists.
- `SendStatus` writes `__wait_unsent` without the customer's run lock. The CustomerFields change limits the effect.
- There is no test that ad-group counts still count ad opens.
- `ChatbotResults::chats` and `chatStats` are unchanged.

Not in TODO.md: in the Inbox, a customer who only clicked shows as waiting. This is unchanged code, and a person seeing them is arguably right.

## Merge danger
- **Shared files.** These files are shared with the other open specs:
  - `HandleGatewayEvent.php`, `GatewayEventController.php`, `ChatbotInbound.php`, `ChatbotRunner.php`
  - `CustomerFields.php`, `SendStatus.php`, `ChatbotTags.php`
  - `BotHealth.php`, `LiveFeed.php`, `ResponseSpeed.php`, `ProblemsPanel.php`, `ChatEchoes.php`, `ForbeastWatch.php`
  - `resources/views/chatbot/health.blade.php`, `TODO.md`, `qa/stories.md`

  Speed 201 and double-reply 202 likely touch `ResponseSpeed`, the runner or the send path. The edits here are small, line-level additions: an extra `whereNull('payload->_ad_open')` or one skip condition. Conflicts should be textual and easy to resolve.
- **202 (double reply).** If it changes the runner's pending loop or `ChatbotInbound`, re-check that `_ad_open` rows are still passed over and that referrals still skip `afterStore`.

## Deploy notes
- **No migration.** The mark lives in the existing `payload` JSON column.
- **Restart the queue workers (Horizon / supervisor) after the deploy.** `HandleGatewayEvent`, `ChatbotRunner` and `SendStatus` need to run the new code; an old worker would still run referral rows.
- **Transition (about the first 30 minutes):**
  - `RunChatbotFlow` jobs queued before the deploy for referral rows (no mark) still run and send the opening on the click.
  - An unmarked referral row that has not run yet is run first in the pending loop of that customer's next message.
  - Both drain with the old jobs.
  - The Health figure "Ad opens without a message" is 0 for days before the deploy, because old rows have no mark.
- **How to watch the first hour:**
  1. Bot Health failures: the "Customer not reachable (#551)" share should fall, from about 29% of post-referral sends toward the 0.4% of post-message sends.
  2. Health "Ad opens without a message" for today: expect about 1–2% of ad-open customers. The production check found 1.2% of 4,476.
  3. 500s on `/api/gateway/events` should stay near 0. A referral that fails to store now answers 500 and is retried by the gateway, which holds that customer's next message behind it.
  4. Health "Answered %" and Speed should not drop. They may rise slightly, since ad opens are now excluded.
- **Rollback:** redeploy 8506b25c and restart the workers. Rows already marked `_ad_open` are harmless to the old code.

## Tests
- **Baseline 8506b25c, `tests/Feature`:** 3674 tests, 70841 assertions, 2 failures:
  - `ExampleTest::test_the_application_returns_a_successful_response`
  - `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
- **This branch (abd697e8), `tests/Feature`:** 3699 tests (25 new), 70955 assertions, 2 failures. They are the same two as the baseline (ExampleTest, ItemTableFitScriptTest), so there are no new failures. Command: `php vendor/bin/phpunit tests/Feature`. Last line: "Tests: 3699, Assertions: 70955, Failures: 2, PHPUnit Deprecations: 92, Skipped: 4."
- **`tests/Feature/Chatblast`:** 1494 tests OK. Only deprecations remain, as on the base.
- **Review:** a skeptic reviewer did one adversarial pass and a re-check of fix loop 1. Five majors were found and fixed: the backfill, the stale `__wait_unsent`, the Health query per page, and two missing tests (500 path, re-ask guards). One more major was this result and the stories, done now. The minors are fixed or accepted in TODO.md.

## Open questions
1. **Partial sends.** A `partial` send_status with some messages delivered counts as delivered, so the customer's next message is taken as the answer. In a multi-message step the question is usually the last message, so it may be the part that never arrived. Should a partial also re-ask the step? That resends the messages that were already delivered.
2. **24-hour window.** Sequence steps, Inbox manual replies and page transfers still measure the 24-hour window from an ad open, as before. If Meta does not open the window on a referral alone, those sends can still be refused with 551 for customers who never wrote. Should they move to the last real message, as the wait timeout now does?

## Process suggestions
- One shared query scope for "rows that are customer messages" (`_ignored`, `_standby`, `_ad_open`) would stop each new mark from needing a dozen reader edits. The review found two readers that the first pass missed.

## Proposed tasks
- Measure the 24-hour window from the last real customer message in `ChatbotSequences`, `Inbox::insideWindow` and `PageTransfers` (Open question 2).
- Add `BotcakeChat::customerMessages()` and move the readers to it, as a separate legacy refactor spec.

## Progress
- Run 1: spec, result skeleton, plan and stories committed. Then the trigger path, the readers, the Health figure, amendments 204-1 to 204-3, the skeptic review and fix loop 1 (re-checked: no blocker or major left). Full tests/Feature: 3699 tests, only the 2 known failures. Status: done.

204: done — the ad open is recorded only (stored before the 200, not a reply); the customer's first message starts the Main Flow with the ad's reply, failed questions are re-asked, and Health shows ad opens without a message
