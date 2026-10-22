# Result 117: a Forbeast page switches itself to Botcake when Forbeast fails

Branch `feat/117-auto-switch-botcake`, cut from `feat/113-forbeast-page-token` (1c894481) with the live main merged in (`0a88dcc8`, merge `16659421`, no conflicts). The local `main` branch was stale (a94dd287), so the live commit from `origin/main` was merged. Not pushed. It releases together with 113.

## What shipped

- **`App\Services\Chatbot\ForbeastWatch`**, the watcher. It handles one gateway page at a time.
  - **`afterSend`**: runs inline after every Forbeast send, called from `GatewayProvider` and wrapped so it never blocks the send. It handles three conditions:
    - **(1)** 5 failed sends in a row. A sent message resets the count.
    - **(2)** A Meta connection code switches at once. The codes are in `CONNECTION_CODES` = 190, 200, 2022, 613, 4, 32.
    - **(3)** 3 sends in a row with no answer (timeout or connection error, or 5xx). Any other kind of failure breaks the streak.
    - Customer-side errors never count. These are `CUSTOMER_CODES` 551 and 10, and `CUSTOMER_SUBCODES` 1545041, 2018001, 2018108 and 2018278 (person unavailable, no matching user, blocked, outside the window).
    - "Not set up" (no call made) doesn't count.
    - The Meta code is read from the body's `error`, `meta`, `meta_error` or root `code` / `error_subcode`, otherwise from `#123` in the text.
  - **`health`**: implements **(5)** `GET {GATEWAY_URL}/v1/pages/{id}` with the page's Forbeast token, or `GATEWAY_KEY` when there is none.
    - 3 failures in a row switch the page.
    - `connected=false` or `can_send=false` switches at once.
    - Timeouts are 3 s to connect and 5 s in total.
  - **`inbound`**: implements **(4)** If the page has ≥3 standby Botcake chats in the last 5 minutes and 0 Forbeast chats, it switches.
    - Standby chats newer than 60 s don't count, because their Forbeast copies may still be queued.
    - It runs from the scheduled command only.
  - **`switch`**:
    - Runs under a per-page cache lock and re-checks that the page is still on gateway.
    - Sets `chatbot.provider` = `botcake` through `PageSettings::set`, the same path and history as the `/chatbot/pages` select. It records the event in the same transaction, resets the counters and runs the standby chats.
    - A page with no Botcake token is not switched. It gets a `cannot_switch` event, at most one per page per hour.
    - Nothing ever switches a page back to Forbeast.
- **Standby chats** (no migration). On a gateway page, the Botcake push (`BotcakeChatController` step 6c) still counts `gateway_page`, but now stores the chat with `payload._standby = true` and runs nothing: no flow, no replied-marking, no sequence exit.
  - The system sets the marker. A Botcake param with that name is removed.
  - `ChatbotRunner::handle` and `skip()` ignore standby rows.
  - `HandleGatewayEvent`'s mid dedupe ignores standby rows, so the Forbeast copy is still stored and run.
  - When a Forbeast reply to a chat is **sent** (`BotcakeOutbox::send` → `ForbeastWatch::answered`), the standby twin is deleted. The twin is the row with the same mid, or the same customer and text within 2 minutes.
  - On a switch, the standby chats of the last 15 minutes are re-created as new chats, oldest first, and the standby rows are deleted, so each runs once. A standby is skipped when its Forbeast twin already has a sent reply. The new chat gets a new id, so it passes the customer's `__last_chat` and runs through `ChatbotInbound::afterStore`.
- **Events** are stored where the change history lives: `page_settings` rows `chatbot.auto_switch`, written by the system. The value is JSON: `{result: switched|cannot_switch, from: gateway, to: botcake|null, condition, error, at}`.
  - `condition` is one of `failed_sends`, `connection_error`, `no_answer`, `inbound` or `health`.
  - `error` holds only the HTTP status and the Meta code, or the health or inbound fact. It never contains Forbeast's response text.
  - No new table: the page settings history already keeps every row.
- **Events feed**: `GET /api/chatbot/provider-events?since=<id>`.
  - Header `X-CHATBOT-EVENTS-KEY`, compared with `hash_equals` against `services.chatbot.events_key` (`CHATBOT_EVENTS_KEY`). A missing or wrong key, or no key configured, gets 401.
  - Throttled 30/min under its own limiter.
  - Returns `{"events": [{id, at (Asia/Manila ISO 8601), page, result, from, to, condition, error}]}`, oldest first, up to 100 events after `since` (digits only, default 0). Poll with the last id you received.
  - No secrets and no customer text.
- **messaging_type**: `MessagingProvider::send` takes `RESPONSE` or `UPDATE`.
  - `BotcakeOutbox` sends `RESPONSE` when the run has a chat and no `startedBy`. That covers the reply and the AI/send resumes, which keep the chat id.
  - It sends `UPDATE` otherwise: sequence steps, and the "no reply in …" skip.
  - The Inbox manual send stays `RESPONSE`, because staff are answering the customer's message.
  - Botcake ignores the value. No message tags anywhere.
- **`/chatbot/pages`**: on a page that was switched automatically, the provider cell shows "Auto-switched <Y-m-d H:i>" as a keyboard-reachable `<details>` with the reason inside. The data comes from `rows[].auto_switched` `{at, reason}` in `/chatbot/data`.
  - The note shows until a provider row newer than the event exists, that is, someone switches the page manually. The row updates at once after a single or bulk switch.
  - **Changes** shows the auto-switch rows as "Switched to Botcake · <reason>" (or "Cannot switch: no Botcake · …"), by "auto-switch". The provider row written by the switch also shows by "auto-switch".
  - `chatbot.auto_switch` is not a botfield column (`ChatbotFields::RESERVED`), is a system key, and is not a column on the page settings grid.

## Scheduled command

`chatbot:forbeast-watch` runs every minute, with `withoutOverlapping(10)`, in the background. For each page whose `chatbot.provider` is `gateway` it runs the health check and the inbound check. With `GATEWAY_URL` empty it does nothing.

## Config keys

In `config/services.php`, with the env names in `.env.example`:

- `services.gateway.auto_switch.failed_sends` = 5 (`GATEWAY_SWITCH_FAILED_SENDS`)
- `no_answer` = 3 (`GATEWAY_SWITCH_NO_ANSWER`)
- `health_fails` = 3 (`GATEWAY_SWITCH_HEALTH_FAILS`)
- `inbound_chats` = 3 (`GATEWAY_SWITCH_INBOUND_CHATS`)
- `inbound_minutes` = 5 (`GATEWAY_SWITCH_INBOUND_MINUTES`)
- `standby_minutes` = 15 (`GATEWAY_SWITCH_STANDBY_MINUTES`)
- `services.chatbot.events_key` (`CHATBOT_EVENTS_KEY`): **the owner sets this on the server** and gives the same value to the feed's poller.

The defaults work without any env lines.

## Migration

None. The standby marker uses the existing `botcake_chats.payload` JSON, and the events use `page_settings`.

## Files

- New:
  - `app/Services/Chatbot/ForbeastWatch.php`
  - `app/Console/Commands/ChatbotForbeastWatch.php`
  - `app/Http/Controllers/ChatbotProviderEventsController.php`
  - `tests/Feature/Chatblast/ForbeastAutoSwitchTest.php`
- Changed:
  - `app/Services/Chatbot/GatewayProvider.php`: messaging_type, and the watcher hook (outcome split into a private method)
  - `MessagingProvider.php`, `BotcakeProvider.php`: the messaging_type parameter
  - `BotcakeOutbox.php`: RESPONSE/UPDATE, and `answered`
  - `ChatbotRunner.php`: skip standby in `handle` and `skip`
  - `app/Http/Controllers/BotcakeChatController.php`: standby instead of dropping the chat
  - `app/Jobs/HandleGatewayEvent.php`: dedupe ignores standby
  - `app/Http/Controllers/ChatbotController.php`: `auto_switched`, and Changes "who" and value
  - `app/Services/PageSettings.php`, `ChatbotFields.php`, `PageSettingsGrid.php`: the system key, not a column
  - `resources/views/chatbot/index.blade.php`: the note
  - `routes/api.php`, `routes/console.php`, `config/services.php`, `.env.example`
  - `qa/stories.md`, `TODO.md`
  - `tests/Feature/Chatblast/ChatbotProviderTest.php`, `ChatbotWaitSkipTest.php`

## Story changes

- **S-111.7** now says the Botcake push for a gateway page is stored on standby. Before, nothing was stored. This is the spec's own change, and the test was renamed to match.
- **S-117.4**: the inbound condition waits 60 s and runs only from the every-minute check. This came from a review finding: the queue race would otherwise switch healthy pages.
- **S-117.9**: a standby is skipped only when its Forbeast copy got a **sent** reply, and the standby stays until then. This came from a review finding: with the copy removed as soon as the event arrived, customers whose Forbeast replies failed went unanswered. The spec's "mid not already handled from Forbeast" is read as "answered", because that matches its purpose: no customer left unanswered.
- **S-117.12** was added: a standby copy after a "no reply in …" wait doesn't hold the skip. It came from a review finding.

## Case table

`vendor/bin/phpunit tests/Feature/Chatblast/ForbeastAutoSwitchTest.php tests/Feature/Chatblast/ChatbotWaitSkipTest.php tests/Feature/Chatblast/ChatbotProviderTest.php` → `Tests: 50, Assertions: 289`, all pass.

| Case | Test | Result |
|---|---|---|
| S-117.1 | `test_S_117_1_five_failed_sends_in_a_row_switch_the_page_once`, `test_S_117_1b_a_sent_message_resets_the_failed_count` | pass |
| S-117.2 | `test_S_117_2_a_meta_connection_error_switches_at_once` | pass |
| S-117.3 | `test_S_117_3_three_sends_without_an_answer_switch_the_page` | pass |
| S-117.4 | `test_S_117_4_botcake_chats_without_forbeast_chats_switch_the_page`, `test_S_117_4b_a_forbeast_chat_in_the_window_keeps_the_page_on_forbeast` | pass |
| S-117.5 | `test_S_117_5_the_health_check_switches_after_three_failures_or_at_once_when_not_connected` | pass |
| S-117.6 | `test_S_117_6_customer_side_errors_never_count` | pass |
| S-117.7 | `test_S_117_7_a_page_without_botcake_is_not_switched_and_gets_one_cannot_switch_event` | pass |
| S-117.8 | `test_S_117_8_nothing_switches_back_by_itself_and_a_manual_switch_clears_the_note` | pass |
| S-117.9 | `test_S_117_9_standby_chats_are_stored_without_running_and_run_once_after_the_switch`, `test_S_117_9b_a_forbeast_event_for_a_standby_mid_runs_once_and_the_standby_copy_goes_after_the_reply_is_sent` | pass |
| S-117.10 | `test_S_117_10_the_events_feed_needs_its_key_and_returns_events_since_an_id` | pass |
| S-117.11 | `test_S_117_11_sequence_sends_go_with_update_and_replies_with_response` | pass |
| S-117.12 | `ChatbotWaitSkipTest::test_S_117_12_a_standby_botcake_chat_after_the_wait_does_not_hold_the_skip` | pass |
| S-111.7 (changed) | `ChatbotProviderTest::test_S_111_7_a_botcake_push_for_a_gateway_page_is_accepted_and_kept_on_standby_without_running` | pass |

**Red runs** (before the code):
- 11 of 14 failed on missing behaviour. Examples:
  - `S_117_1` "Failed asserting that two strings are identical" (still `gateway`)
  - `S_117_10` "404 is identical to 401" (no route)
  - `S_117_11` "two arrays are identical" (RESPONSE for the sequence)
  - `S_117_4b` / `S_117_5` "command not found"
- `S_117_1b`, `S_117_6` and `S_117_9b` passed before the code, because they pin "no switch" or the existing single-run behaviour. They became meaningful once the switch existed.
- Fix loop: all 6 new or changed tests failed first. `S_117_1` was the old error text, `S_117_4`/`4b` switched too early, `S_117_9` ran nothing, `S_117_9b` had 1 row instead of 2, and `S_117_12` was missing "Still there?".

## Suites

- **The spec's set** `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: **Tests: 932, Assertions: 6475, Skipped: 1**, no failures. The skip was there before.
- **The full suite**, run once on the feat commit: `Tests: 2324, Errors: 1, Failures: 1, Skipped: 4`. Both failures come from the local environment, not this change:
  - `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` needs a local `storage/app/credentials.json`, which this worktree doesn't have.
  - `ExampleTest` gets 302 (login redirect) from `/`.
  - Neither touches a file in this diff.

## Review

- **Adversarial `skeptic-reviewer` pass on the feat commit.** It found 3 majors, all fixed test-first in one loop (`225decc4`):
  - the standby copy was deleted before Forbeast's reply was known, so customers went unanswered after a switch;
  - condition 4 raced the queue, so a busy worker could switch a healthy page;
  - `ChatbotRunner::skip()` counted standby rows as customer replies.
- One minor on an untrusted path was fixed in the same loop: the event error carried Forbeast's response text, which could contain message content. The health timeout was also shortened.
- **Re-check:** all majors fixed, no new blocker or major.
- **Accepted minors**, with reasons in `TODO.md` under "117":
  - Forbeast's error format is assumed;
  - "auto-switch" is inferred from a missing user;
  - the note survives a no-op pick;
  - the health check is sequential;
  - the GET logic is duplicated;
  - standby rows linger for flows that send nothing;
  - Botcake mid vs text matching;
  - a run in flight or an Inbox manual reply at the switch can mean a second reply;
  - `skip()` loads rows;
  - missing test variants.
- Not done:
  - **Browser check**: skipped. The UI change is one `<details>` note, and the page HTML is asserted in S-117.8.
  - **Plan gate**: the brief's decisions table served as the plan, because this session had no reviewer to send a plan to and the brief asked for a lean job.

## Commits

- `16659421` Merge commit '0a88dcc8' into feat/117-auto-switch-botcake
- `d6d7b8d7` docs: save spec 117 (auto-switch to Botcake)
- `d79b3c3d` feat: Forbeast page switches itself to Botcake when Forbeast fails, standby chats, events feed, messaging_type (spec 117)
- `225decc4` fix: standby kept until Forbeast replies, inbound check waits for the queue, wait skip ignores standby, event error without response text (spec 117)
- (this result and the TODO entries) docs: result for auto-switch to Botcake (spec 117)

## Merge danger

- **Deploy order:** the command is scheduled every minute as soon as this deploys. It only acts on pages already on `gateway`, so pages on Botcake are untouched.
- **Behaviour change for gateway pages:** Botcake pushes are now stored (standby) instead of dropped, so they show in the chats and Inbox screens until their Forbeast reply is sent.
- **The feed returns 401 until `CHATBOT_EVENTS_KEY` is set** on the server.
- **Forbeast's error body format is unverified.** If it reports Meta codes differently, condition 2 falls back to conditions 1 and 3, and customer errors could count toward condition 1 (see TODO).

## Amendment 1: standby chats are invisible outside the watcher and the switch

- **One shared scope:** `BotcakeChat::active()` (`app/Models/BotcakeChat.php`) is `whereNull('payload->_standby')`. Laravel compiles the JSON path per driver (mysql, pgsql, sqlite). The two readers that use `DB::table` write the same `whereNull('payload->_standby')` inline.
- **Readers changed** (each reads real chats only now):
  - `FlowEngine::history`: the AI history, so the customer's message is not seen twice.
  - `Inbox::conversations`, `Inbox::thread` (the in and out queries), the "last activity" lookup (~L249), the first/last chat lookups (~L267–268) and the photo lookup (~L350).
  - `ChatbotResults::chats`: the Results funnel chat count (a `DB::table` query). The "response rate" and "replied" counts come from `chatbot_replies`, which has no standby rows, so they follow.
  - `ChatbotController::chatStats`: "today" and "last chat" on `/chatbot/pages`.
  - `ChatblastChatController`: the `/chatblast/chats` list and the per-customer page.
  - `ChatbotSheetController`: the recent-customers picker and the chat lookup of the test.
  - `ChatbotSequences`: the last chat used for the 24-hour window of a step.
  - `ChatbotTags`: the first chat for the ad id, and the replied-backfill (the next chat after a send).
  - `FlowRun::forgetAi`: the chat id stored as the "Clear AI memory" marker.
- **Left as they are, on purpose:**
  - `ChatbotRunner` and `HandleGatewayEvent`: they already skip standby rows in memory.
  - `ForbeastWatch`: it needs the standby rows.
  - `BotcakeChatController::isDuplicate`: Botcake's own retry of a chat must still be seen as a duplicate.
  - Name and page-id lookups (`HandleGatewayEvent::name`, `ChatbotSequenceController`, `BotcakeChat::pageIds`): they only read a name or a page id, which a standby row carries truthfully.
  - `BotcakeStressPage`, `NormalizeBotcakeChats`, `BotcakeChat::prune` (CLI, normalising and the 90-day delete).
- **`{{ALL USER INPUT}}`:** no variable of the chatbot is built from `botcake_chats` other than the AI history above (`all_user_input` belongs to the orders table). Nothing else needed a filter.
- **Prune:** `ForbeastWatch::prune()`, called first by `chatbot:forbeast-watch` (also when `GATEWAY_URL` is empty), deletes standby rows older than `standby_minutes + 5` (20 minutes by default). The scheduled command runs it every minute.
- **No migration, no new config key.**

| Case | Test | Result |
|---|---|---|
| S-117.13 | `ForbeastStandbyHiddenTest::test_S_117_13_a_standby_twin_is_not_in_the_ai_history` | pass |
| S-117.14 | `…::test_S_117_14_a_standby_twin_is_not_in_the_inbox_list_nor_the_thread` | pass |
| S-117.15 | `…::test_S_117_15_a_standby_twin_is_not_counted_in_results_nor_the_chat_stats` | pass |
| S-117.16 | `…::test_S_117_16_the_watcher_prunes_standby_older_than_twenty_minutes_and_keeps_the_rest` | pass |

Red run before the code: S-117.13 "Failed asserting that false is true" (the history held both copies), S-117.14 "two arrays are identical", S-117.15 "2 is identical to 1", S-117.16 "two arrays are identical" (nothing deleted). The first run of S-117.13 errored on a missing import in the test, fixed before counting it as red.

`vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` → **Tests: 936, Assertions: 6487, Skipped: 1**, no failures; the existing 117 tests pass. Not run on mysql or pgsql (sqlite only): the JSON path comes from Laravel's compiler, as `payload->source` already does in `ChatbotResults`.
