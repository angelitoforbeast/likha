# Result 234: stop messaging a customer after Meta says they can't be reached

## Status

Done, not pushed, not deployed. No migration. Branch `fix/234-stop-after-551` from `f175cb2f`. All 18 cases pass
(31 tests). The full suite result is in Checks. The plan got its "go" with R1–R5 approved and a 30-day expiry for the mark.

**Merge danger: high, but quick to turn off.**
- **What changes:** after deploy, every scheduled send (sequence steps, follow-ups, wait timeouts, Chatblast) to a
  customer whose last failed send said #551, or #10 outside the window, is skipped until that customer writes or
  30 days pass. On incepxion.pro that is most of the 11% of sends that failed on 2026-11-05.
- **Blast radius:** every live page on both sites and both routes. Answers to customer messages, manual inbox sends,
  comment replies and the Test tab are not affected.
- **Marks start empty:** customers who failed before the deploy are marked on their next failed send, not before.
- **Off switch:** set `CHATBOT_STOP_UNREACHABLE=false` in the server's environment and run `php artisan config:cache`.
  That stops new marks and all skips at once; stored marks stay unused. It is a two-way door.
- **Revert:** revert the merge. The `__unreachable` rows in `chatbot_customer_values` are then unused and harmless.
  `DELETE FROM chatbot_customer_values WHERE \`key\` = '__unreachable'` removes them; this is optional.
- **Health:** the failed numbers drop on deploy day, because these skips no longer count as failed.

## Plan

### Design in one paragraph

A new small service `App\Services\Chatbot\CustomerReach` (the same shape as `PageRestriction`) owns the mark. It
**marks** a customer when a send fails with #551 or the window/customer variant of #10. It **checks** before every
send the customer did not just ask for, using one fresh indexed read. It **clears** the mark on any inbound chat.
The mark is a run-state key `__unreachable` in `chatbot_customer_values` (CustomerFields), with a value of
JSON `{"code":551,"at":"Y-m-d H:i:s"}`. A config switch `services.chatbot.stop_unreachable`
(env `CHATBOT_STOP_UNREACHABLE`, default `true`) turns marking and skipping off at once.

### Where each part goes

- **Mark (3 set points):**
  - `BotcakeOutbox::record`, on `status = failed`, for both routes' synchronous failures and the pools' finish
    functions.
  - `SendStatus::receive`, on the gateway's later `failed` send_status. The gateway first answers "sent", meaning
    accepted; the #551 arrives afterwards.
  - `ChatblastSender::finish`, on failed, from the step responses, the same way `ChatblastNeedsSetup::isUnreachable`
    reads them. The page ID comes from the page map.
- **Parser:** `CustomerReach::code(?string $error): ?int`. It returns 551 for `(#551)`, `code 551` (gateway
  "sendMessage failed (code 551…)") and "person isn't available" (straight or curly apostrophe). It returns 10 only
  for the window/customer variant of #10, see R1. Otherwise it returns null. Matching uses anchored regexes with
  `\b`, so `#100` and `#1000` never match, and input length is capped. Error text is never logged.
- **Check (one method, `CustomerReach::skipReason(pageId, psid): ?string`), called at:**
  1. `BotcakeOutbox::send`, only when the type is `UPDATE`. An answer to a customer message (`RESPONSE`: chat set,
     not started by a sequence) is never skipped.
  2. `SequenceSendPool`, right before `calledSequence`, including the 429 retry (`attempt > 0`, a gap the review
     found). On skip it calls `finishSequence(... 'skipped', reason)`, which writes the chatbot_replies row and
     resumes, so the step finishes as done.
  3. `BotcakeSendPool`, for `chatbot` tickets with no `chat_id` (wait timeouts). On skip it calls `finishChatbot`
     with skipped.
  4. `ChatblastSender::deliver` (non-test, after the campaign checks) and `ChatblastSender::poolStart`. On skip it
     calls `finish($m, 'skipped', reason)` in `botcake_messages`, **without** nulling `dedupe_key`, so no new row is
     made on every pull.

  The read is a fresh `SELECT value … WHERE page_id=? AND psid=? AND key='__unreachable'` on the unique index. It
  never uses `$run->fields`, because those are loaded at run start and go stale during AI and pool waits.
- **Race (S-234.2.4):** tickets of one customer are claimed one at a time per kind (`BotcakeSendQueue::claim`), and
  each ticket checks right before its own call. In `finishSequence` and `finishChatbot`, the mark is stored
  **before** the ticket status update that frees the next ticket. On gateway pages, sends between "accepted" and
  the send_status are not caught; nothing can catch them earlier, and this is stated in Merge danger.
- **Clear:**
  - `ChatbotInbound::afterStore`, first thing. This is the shared path for Botcake `/api/botcake/chat` and the gateway
    `HandleGatewayEvent` (message, postback, quick reply; referral and standby copies excluded).
  - Also the two `botOff` early returns (`BotcakeChatController` line 122, `HandleGatewayEvent` line 150), after
    the page and key checks have passed. Without these, a customer on a page whose bot is Off would stay marked
    forever.
  - One indexed DELETE.
- **FlowEngine::afterSend:** a skip because the customer can't be reached gets its own event reason (not "replies
  off"). A skipped question with a wait sets `WAIT_UNSENT` like a failed one, so the customer's next message is not
  taken as the answer to a question they never got.
- **CustomerFields::clearAll:** `__unreachable` joins the keep-list, so "clear all fields" in a flow does not
  unmark.
- **Health (S-234.4):**
  - New `BotHealth` count per page for today: chatbot_replies `status = skipped` with error starting with the
    reason prefix `Customer can't be reached`, plus the `botcake_messages` skipped rows with that prefix, shown as
    two numbers.
  - `replies()`, `hourly()` and `failures()` leave out these skip rows, see R3.
  - Shown as a small table on `/chatbot/health`. No banner line.
- **Skip reason text:** `Customer can't be reached (#551) since 2026-11-05 14:03`. `reasonSql` must not class
  these rows, because R3 leaves them out of every failed query.

### Tasks (all sequential except where marked)

| # | Task | Cases | Tier | Side | Files |
|---|---|---|---|---|---|
| T1 | `CustomerReach` (parser, mark, read, clear) + config switch + `.env.example`; mark from `BotcakeOutbox::record`, `SendStatus::receive`, `ChatblastSender::finish`; `clearAll` keep-list | S-234.1.1–.4 | high | backend | new `app/Services/Chatbot/CustomerReach.php`, `BotcakeOutbox.php`, `SendStatus.php`, `ChatblastSender.php` (finish only), `CustomerFields.php`, `config/services.php`, `.env.example` |
| T2 | The check at the four send points + `afterSend` skip handling + mark-before-release in the finish functions | S-234.2.1–.4 | high | backend | `BotcakeOutbox.php`, `SequenceSendPool.php`, `BotcakeSendPool.php`, `BotcakeSendQueue.php`, `ChatblastSender.php` (deliver, poolStart), `FlowEngine.php` |
| T3 | Clear on inbound: `ChatbotInbound::afterStore` + both bot-Off returns | S-234.3.1–.3 | high | backend | `ChatbotInbound.php`, `BotcakeChatController.php`, `HandleGatewayEvent.php` |
| T4 | Health count per page today (both sources) + leave the skip rows out of the failed queries | S-234.4.2 | medium | backend | `BotHealth.php`, `ChatbotTabsController.php` or the health controller |
| T5 | Health screen shows the count per page | S-234.4.1 | medium | frontend | `resources/views/chatbot/health.blade.php` |
| T6 | `qa/stories.md` slice 234 (with the story changes below), measurement, result, merge danger | — | low | docs | `qa/stories.md`, this file |

Notes on order and sharing:

- T3 shares no files with T2 and can run parallel-safe after T1. T5 follows T4.
- Tests go in `tests/Feature/Chatblast/` beside the existing chatbot tests, in a new `CustomerReachTest.php` plus
  pool and Health tests where the existing helpers live. Each test is named after its case ID.
- Models per tier: high tasks get an opus developer and an adversarial opus reviewer; medium tasks get sonnet with a
  standard review.
- **Overlap with feat/233-psid-page-id:** none of these files read or write `botcake_psids`. `ChatblastSender` is
  touched only in `deliver` (one check), `poolStart` and `finish`, not in its PSID or customer sync code.
- **Measurement:** the query count per send (expect +1 SELECT per non-answer send, +1 DELETE per inbound chat), and
  the median time of the lookup over 10,000 runs on sqlite and on a local MySQL if available. Both go in Checks.

## Summary

Built in the main session: the kit's developer agents are not installed in this session. Each slice was written
test first, with a red run per slice (below). `skeptic-reviewer` reviewed every slice. T1–T3 got an adversarial
review on opus, T4–T5 a standard review on sonnet, and there was one fix loop.

| Task | Commit | What shipped |
|---|---|---|
| T1 (high, backend) | `515e476e` | `app/Services/Chatbot/CustomerReach.php`: `code()` parser, `note()`, `skipReason()`, `clear()`, 30-day expiry, switch. The mark comes from `BotcakeOutbox::record` (failed), `SendStatus::receive` (gateway failed) and `ChatblastSender::finish` (step answers). `CustomerFields::clearAll` keeps the mark. Config `services.chatbot.stop_unreachable` + `.env.example`. The Chatblast skip in `deliver` and `poolStart` was written in this commit too. |
| T2 (high, backend) | `37013e17` | Skip checks in `BotcakeOutbox::send` (UPDATE only), `ChatbotSequences::sendCheck` (code `unreachable`, recorded like switched-off), `SequenceSendPool` 429 retry, and `BotcakeSendPool::skipNow` + 429 retry (tickets without a chat). `BotcakeSendQueue::finishChatbot/finishSequence` store the mark before freeing the ticket. In `FlowEngine::afterSend`, the skip event gets the reason, and a skipped question with a wait sets `WAIT_UNSENT`. |
| T3 (high, backend) | `7f4c1c81` | Clears in `ChatbotInbound::afterStore`, in `BotcakeChatController`'s bot-Off return, and in `HandleGatewayEvent` (Botcake-route and bot-Off returns; referral excluded). |
| T4 (medium, backend) | `a142452c` | `BotHealth::unreachableSkips` (per page today, bot + Chatblast). `notUnreachableSkip` keeps these skips out of `replies()`, `hourly()` and `failures()`. |
| Fix loop 1 | `36b73725` | Chatblast reads the page ID through `PageSettings::get` (case-insensitive). The gateway clear moved after the dedupe (no second DELETE). Tests added for the case difference, the echo and the Botcake-route gateway page. |
| T5 (medium, frontend) | `e24fcb6f` | `/chatbot/health`: the "Skipped today: customer can't be reached" table, and the help sentence updated. No new CSS classes, so no `npm run build`. |
| T6 (low, docs) | this commit | `qa/stories.md` slice 234, `TODO.md` accepted minors, this result. |

**Measurement (one cheap lookup per send).**
- **Query count:** `CustomerReach::skipReason` runs 1 query per check, an indexed `SELECT value` on the unique
  (page_id, psid, key). It runs only on sends that are not answers. Each inbound chat adds 1 indexed `DELETE`.
- **Timing:** a throwaway bench (deleted, not committed) used sqlite in memory with 100,000 rows in
  `chatbot_customer_values`, 25,000 of them marks across 50 pages. Over 10,000 lookups: median 38.6 µs, p99 110.9 µs,
  1 query per call.
- **Not measured:** MySQL, since no local MySQL was used. The same unique index serves the same equality lookup.

**Red runs (one per slice, first failure line):**
- T1: `CustomerReachTest`, `Class "App\Services\Chatbot\CustomerReach" not found`, the module about to be made.
  Chatblast (run against the original `ChatblastSender.php`): `Failed asserting that null is identical to Array`.
- T2: `CustomerReachSendTest` S-234.2.1, `Failed asserting that two arrays are identical`. S-234.2.3:
  `actual size 2 matches expected size 1`.
- T3: `CustomerReachInboundTest` S-234.3.1, `Failed asserting that true is false`.
- T4: `CustomerReachHealthTest` S-234.4.2, `Undefined array key "unreachable_skips"`, and
  `Failed asserting that two arrays are identical`.
- Fix loop: S-234.2.2 (page name in another case), `Failed asserting that null is not null`.
- T5: S-234.4.1, `Failed asserting that '<!DOCTYPE html>…' contains "Skipped today…"` (assertSeeInOrder).

**Not touched:** `botcake_psids` readers and writers (the parallel 233 branch). Only the inbound test creates a
`BotcakePsid` row, the same way existing tests do.

**Browser check:** skipped, because this session has no browser tools. The screen change is one table and one
sentence, and the S-234.4.1 test checks the rendered page.

## Checks

**Full suite** (`php.bat artisan test`), on the tree at `e24fcb6f` plus the docs of this commit:

```
  ⨯ the application returns a successful response
  ⨯ every catalog column id has a minimum width and a place in the drop order
   FAILED  Tests\Feature\ExampleTest > the application returns a successful response
   FAILED  Tests\Feature\Item\ItemTableFitScriptTest > every catalog column id has a minimum width and a place in th…
  Tests:    2 failed, 4362 warnings, 206 passed (77628 assertions)
  Duration: 835.55s
```

- **No new failures:** the 2 red tests are the known pre-existing `ExampleTest` and `ItemTableFitScriptTest`.
  `BigBroadcastTest` and `AstraAddressRulesTest` S-26.18 passed this time.
- **Warnings:** the warnings are PHPUnit's `file_get_contents(…)` warnings of this worktree's environment, plus the
  doc-comment deprecation notices. They show on untouched tests too.
- **After the suite:** one tidy of `BotHealth.php` from the T4/T5 review (a docblock moved back above `hourly()`,
  and `Schema::hasTable` moved out of the loop). After it, `CustomerReachHealthTest` and the `ChatbotHealth*Test`
  files were rerun and passed (25 tests, 151 assertions). The full suite was not run again for this no-behaviour change.
- **Lint:** `php.bat -l` passes on every changed PHP file. No PHP linter or formatter is configured.
- **Kit hook tests:** `node --test 'test/hooks/*.test.mjs'` was not run; no kit files changed.

**Case table** (all from the full-suite run, plus the T5/T4 rerun above):

| Case | Test(s) | Result |
|---|---|---|
| S-234.1.1 | `test_S_234_1_1_a_send_that_fails_with_551_marks_the_customer_with_the_time_and_551`, `test_S_234_1_1_a_chatblast_send_flow_refused_with_551_marks_the_customer` | pass |
| S-234.1.2 | `test_S_234_1_2_a_send_that_fails_with_the_window_10_marks_the_customer_with_10` (3 rows) | pass |
| S-234.1.3 | `test_S_234_1_3_other_errors_do_not_mark` (11 rows, incl. `sendMessage failed (code 10, subcode 1893063)` in both gateway forms) | pass |
| S-234.1.4 | `test_S_234_1_4_the_botcake_and_gateway_wordings_are_both_recognised` (3 rows), `test_S_234_1_4_the_gateway_send_status_failure_marks_and_the_1893063_one_does_not` | pass |
| S-234.1.5 | rows of the S-234.1.2 and S-234.1.3 tables | pass |
| S-234.2.1 | `test_S_234_2_1_a_sequence_step_to_a_marked_customer_is_skipped_recorded_and_not_called` | pass |
| S-234.2.2 | `test_S_234_2_2_a_chatblast_to_a_marked_customer_is_skipped_with_the_reason_and_no_botcake_call`, `…_the_pool_skips_a_chatblast_whose_customer_was_marked_while_it_waited`, `…_a_page_name_from_the_order_that_differs_only_in_case_still_marks_and_skips`, `…_a_follow_up_sent_by_the_waiting_job_is_skipped_too`, `…_a_wait_timeout_send_without_a_chat_is_skipped_and_the_question_counts_as_unsent`, `…_the_reply_pool_skips_a_wait_timeout_ticket_of_a_marked_customer` | pass |
| S-234.2.3 | `test_S_234_2_3_a_customer_marked_on_page_a_is_still_messaged_on_page_b` | pass |
| S-234.2.4 | `test_S_234_2_4_after_a_551_the_next_sends_of_that_customer_in_the_pool_are_all_skipped`, `test_S_234_2_4_a_429_retry_after_another_send_stored_the_mark_is_skipped` | pass |
| S-234.2.5 | `test_an_answer_to_the_customer_is_never_skipped_even_while_marked`, `test_the_switch_off_sends_to_a_marked_customer`, `test_the_switch_off_stops_new_marks`, `test_a_mark_older_than_30_days_no_longer_skips` | pass |
| S-234.3.1 | `test_S_234_3_1_a_marked_customer_who_writes_to_the_page_is_unmarked_and_the_bot_answers`, `test_S_234_3_1_the_mark_is_cleared_even_when_the_page_bot_is_off` | pass |
| S-234.3.2 | `test_S_234_3_2_after_the_customer_wrote_a_later_sequence_step_is_sent_normally` | pass |
| S-234.3.3 | `test_S_234_3_3_a_gateway_postback_or_quick_reply_also_clears_the_mark` (5 rows: message, postback, quick reply, echo, referral), `…_a_gateway_message_to_a_page_on_the_botcake_route_clears_the_mark`, `…_a_gateway_message_clears_the_mark_on_a_page_whose_bot_is_off` | pass |
| S-234.3.4 | `test_S_234_3_4_clear_all_fields_keeps_the_mark` | pass |
| S-234.4.1 | `test_S_234_4_1_health_shows_the_skipped_sends_per_page_today` | pass |
| S-234.4.2 | `test_S_234_4_2_the_count_per_page_today_matches_the_skipped_rows_with_that_reason`, `test_S_234_4_2_these_skips_are_not_counted_as_failed_sends` | pass |

**Environment note:** an earlier local test run hung on a test of mine. Its second Chatblast send hit the customer's
gap and requeued without end; the test now moves the clock past the gap. A `php.exe` (PID 176896, about 590 MB and
growing) was still running after the hung run was stopped. I could not confirm what it was, so I left it running.
If nothing else uses PHP on this machine, it is safe to end.

## Rulings

All approved with the "go". R1 as narrowed by the reviewer: #10 marks only with "outside of allowed window" or
subcode 2018278. Subcode 1893063 (page temporarily restricted: all 723 #10s on Likha in the last 24 h) never
marks, and a test uses the exact gateway text. The open question was ruled: the mark expires after 30 days
(`CustomerReach::EXPIRE_DAYS`).

- **R6 (made during the work):**
  - **What:** a gateway event clears the mark itself only where it would not be stored (a Botcake-route page, or
    the bot is Off). A stored chat clears in `afterStore`, after the duplicate check.
  - **Why:** a duplicate event can't clear a newer mark, and gateway pages run one DELETE, not two.
- **R7 (made during the work):** the Botcake bot-Off clear needs the chat key, which already fits the page. It does
  not wait for the PSID check, because that check calls Botcake.

The approved texts follow.

- **R0 (decision 4): the store is CustomerFields / `chatbot_customer_values`.** Key `__unreachable`, with a unique
  index on (page_id, psid, key). The run-state "__" keys never show as variables. No new table, no migration.
- **R1 (decision 1, spec review major): #10 marks only for its customer or window variant.**
  - The variant is subcode 2018278, 2018108 or 2018001, or text about the window ("outside of allowed window",
    "24 hour"), which matches what `BotHealth::reasonSql` classes as 'window'.
  - The other #10s are left out: page-level "does not have permission", the restriction (1893063 / "temporarily
    restricted"), and bare `"code":10` without window text.
  - Reason: when a page loses permission or gets restricted, every send fails with #10. Without this rule every
    customer contacted that day would be marked, and their scheduled sends would stay stopped after the page is
    fixed, until each customer writes. That is a page-wide loss of sends, not a customer state.
  - Adds case S-234.1.5.
- **R2 (decisions 2 and 6, spec review major): Chatblast skips are recorded in `botcake_messages`, not in
  `chatbot_replies`.** They get status `skipped` and the same reason.
  - Reason: Chatblast has never written to chatbot_replies. Its screens, caps and dedupe read botcake_messages.
    Mixing the two would add Chatblast rows to the bot's Replies, Results and Health.
  - The Health count shows both sources.
  - Story changes to S-234.2.2 and S-234.4.2 follow.
- **R3 (decision 6, spec review major): the skip rows are left out of Health's failed numbers.** This covers the
  failed % lights, the hourly failed graph and the failures list; they get their own count instead.
  - Reason: Health counts every non-sent row as failed today. Thousands of skips a day would turn the "Failed
    replies" light red. A skip reason containing "(#10)" would also fall into 'window', which the light does not
    subtract.
  - Other skipped rows (replies switched off) keep today's behaviour.
- **R4: what is skipped.**
  - Skipped: every send with type UPDATE (sequence steps, follow-ups, wait timeouts), Chatblast (not test sends),
    and big broadcasts, which are Chatblast.
  - Not skipped: answers to a customer message (RESPONSE, including AI-pool resumes, which carry their chat).
  - Not touched: manual inbox sends (a person chose to send; they don't go through `record`), comment private
    replies, and the Test tab.
  - A skipped sequence step finishes as done, so the sequence moves on, the same as today's switched-off skip.
- **R5: the switch.** `services.chatbot.stop_unreachable` (default on) is read at every set point and every check
  point. Off means no new marks and no skips. Existing marks stay, unused, and are cleared by inbound as usual.

## Story changes

Applied to `qa/stories.md`, slice 234. Besides those approved with the plan (below), two changes were made during
the work:

- **New S-234.2.5 negative:** an answer is never skipped, the switch off sends and stops marks, and a mark older than
  30 days no longer skips. Reason: R4, R5 and the 30-day ruling each need a case.
- **S-234.2.2 and S-234.3.3 grew:**
  - S-234.2.2 adds the case-insensitive page name and the wait timeout through the reply pool.
  - S-234.3.3 adds that an echo does not clear and that a Botcake-route page's gateway event clears.

  Reason: the review findings showed these paths.

Approved with the plan:

- **S-234.1.3:** adds "a page permission #10 ('does not have permission') and a restriction #10 → no mark" (R1).
- **New S-234.1.5 edge:** #10 with the window subcode 2018278 or window text marks; #10 without it does not (R1).
- **S-234.1.4:** "isn't available" alone is not enough. It must be "person isn't available" or carry (#551), because
  the error is untrusted text.
- **S-234.2.2:** for Chatblast, the skip is recorded in `botcake_messages` (status skipped, the reason, dedupe_key
  kept), not in chatbot_replies (R2).
- **S-234.2.4:** adds the 429 retry: a retried send after the mark was stored is skipped too.
- **S-234.3.1:** adds "also when the page's bot is Off (the chat is not stored, the mark is still cleared)".
- **S-234.4.2:** the count matches chatbot_replies skipped rows with that reason, plus botcake_messages skipped rows
  with that reason, per page today. Health's failed % is unchanged by them (R2, R3).
- **New S-234.3.4 edge:** "clear all fields" in a flow does not clear the mark.

## Open questions

None left open.
- Question 1 below was ruled: the mark expires after 30 days.
- Question 2 was ruled: R1–R5 yes.
- Conflict with CLAUDE.local.md: none. The spec's line "All sends are recorded through BotcakeOutbox::record" is not
  true for Chatblast; R2 handles this.

The original questions, kept for the record:

1. **A page with no inbound to Likha at all** (Botcake has no chat block pointing at Likha). Such a page's marked
   customers can never be cleared, so Chatblast to them stops for good, even after they write to the page in
   Messenger. Options:
   - (a) accept it;
   - (b) apply the check only on pages that delivered an inbound chat to Likha in the last 7 days;
   - (c) the mark expires after N days (config, e.g. 30).

   I recommend (c) with 30 days. It is one comparison in the same lookup, and it bounds the damage of any wrong
   mark.
2. R1 to R5 above: yes or no for each.

## Proposed tasks

Suggestions for the owner, found during the work:

1. **Decide on the missed Chatblast message.** After a cleared customer writes, should they get the message that was
   skipped? Today the skipped row keeps its dedupe key, so they don't (TODO.md).
2. **Check the Chatblast 429 retry for the mark too** (TODO.md, small).
3. **After deploy, read the new Health table for a week.** If one page shows thousands of skips a day, its list of
   customers is mostly unreachable, and that is worth a look at the page itself.
4. **Many `file_get_contents` warnings in this worktree's test run** come from the environment (they also appear on
   untouched tests such as `SendStatusBatchTest`), likely the Vite manifest missing here. They are not new, but they
   hide real warnings.

### Review findings and where they went

- **Spec review:** 3 majors, all turned into R1–R3 and approved.
- **T1+T2 adversarial review:** 1 major (Chatblast page name case), fixed in `36b73725` and re-checked: fixed, no new
  findings. Its minors went to TODO.md, apart from the duplicated lookup, which the fix removed.
- **T3 adversarial review:** no majors. Fixed: the clear before dedupe (also removes the double DELETE) and the echo
  and Botcake-route tests. The rest went to TODO.md.
- **T4+T5 standard review:** no majors. Fixed after the suite: the orphaned `hourly()` docblock, and `Schema::hasTable`
  moved out of the loop. The rest went to TODO.md.
