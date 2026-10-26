# Result 160: hide the bot pause controls in the Inbox

## What shipped
- `config/chatbot.php`: new flag `chatbot.inbox_bot_pause` (env `CHATBOT_INBOX_BOT_PAUSE`, default false; added to `.env.example`).
- `ChatbotInboxController`: `send` ignores `pause=1` and `pause` ignores `paused=1` while the flag is off; `paused=0` always clears `__bot_paused`. The view gets `botPause`.
- `chatbot/inbox.blade.php`, flag off: no Human/Bot tabs, no "Human / Bot" filter condition (a saved one in localStorage is dropped by `readConds`), no bot on/off switch, no "Pause the bot for this customer?" confirm on send. The roles dialog title reads "Who can reply". A customer who is already paused shows a "Bot paused" badge with an "Unpause" button (staff who can send). The list's "Human" badge stays as an indicator. Flag on: the page is exactly as before.
- Kept: the endpoints, `BOT_PAUSED`, `InboxFilters` handler / `tab=human` on the server, and the runner and sequence checks.

## Case table
| Case | Test | Result |
|---|---|---|
| S-160.1 | `ChatbotInboxBotPauseTest::test_S_160_1_the_inbox_page_has_no_pause_controls_unless_the_flag_is_on` | pass |
| S-160.2 | `ChatbotInboxBotPauseTest::test_S_160_2_a_pause_request_is_ignored_but_the_reply_is_sent_and_unpause_still_works` | pass |
| S-160.3 | `ChatbotInboxActionsTest` (all, including the S-074 R3/R4/E3 pause tests) with the flag on in `setUp` | pass |
| S-160.4 | `ChatbotInboxBotPauseTest::test_S_160_4_an_already_paused_customer_shows_paused_and_can_be_unpaused` | pass |
| S-160.5 (P2) | 390 px | not checked: no browser or running app in this worktree |

Red run before the change: 3 tests, 3 failures. `.1`: the page still contained the controls. `.2`: "Failed asserting that '1' is null." `.4`: there was no "Unpause" in the page.

## Suite
`php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast`
- Baseline e0bbbdb7: Tests 1045, Failures 1 (`ChatbotViaTest::test_S_149_25_the_inbox_thread_and_the_replies_page_show_via`), Skipped 1.
- This branch: Tests 1048, Assertions 7092, Failures 0, Skipped 1. No new failures. S-149.25 passed here, so the baseline failure looks flaky; this change doesn't touch it.

## Review
`skeptic-reviewer` at standard depth found no blockers and no majors. It raised 4 minors, all accepted with reasons in `TODO.md`:
- the S-160.4 HTML assertion is weak because the JS doesn't run;
- 390 px is unchecked;
- Unpause reloads the current conversation;
- the controller has a nested one-line `if`.

## Story changes
None. `qa/stories.md` is not touched; the brief's cases are used as given.

## Deploy note
There is no migration. If production caches its config, run `php artisan config:cache` after the deploy. The default (false) is the state we want, so no environment variable needs setting.

160: done — the Inbox no longer shows or accepts a bot pause (flag `chatbot.inbox_bot_pause`, default off); a customer who is already paused still shows "Bot paused" with Unpause.
