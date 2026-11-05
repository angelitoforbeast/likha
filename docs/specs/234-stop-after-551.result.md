# Result 234: stop messaging a customer after Meta says they can't be reached

## Status

Plan ready, waiting for "go". Branch `fix/234-stop-after-551` from `f175cb2f`. No code yet. High tier: the spec
review (skeptic-reviewer, spec-review mode) returned **needs revision** with three majors. The spec is not edited
after the start, so those majors are carried below as proposed Rulings (R1–R3) and Story changes for the reviewer
to confirm with the "go".

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

(pending: filled in after the work)

## Checks

(pending)

## Rulings

Proposed. Each one needs the reviewer's yes with the "go".

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

Proposed, applied to `qa/stories.md` only after the "go":

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

(pending: suggestions for the owner, found during the work)
