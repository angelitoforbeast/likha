# Result 166: moving a page to Forbeast does not bounce it back to Botcake

## What shipped
- `ForbeastWatch::inbound()` reads the time the page moved to Forbeast (the `created_at` of the active `chatbot.provider` row; the same save re-done writes no row, so it does not restart the window). A standby Botcake copy with no Forbeast twin, received less than `SETTLE` = 180 s after that time, is no longer evidence for the inbound switch. Thresholds, the grace, the other conditions, and never switching back are unchanged.
- Those late copies would otherwise never be answered: under the existing rules a standby copy only runs when the page switches to Botcake. So when no switch happens, the watch runs them on Forbeast after their 120 s grace, with the same per-chat rules as the switch (`runStandby`'s loop extracted into `runChats`: skip if the customer was answered since, or if a Forbeast run of its twin is still pending). They run under the page's switch lock, with the rows read again inside it, so each copy runs once. When a switch does happen, `runStandby` answers them as before.
- Tests: `ForbeastAutoSwitchTest` gains S-166.1, .2, .4. Its `setUp` now dates the provider row 3 h back, so the existing cases model a page on Forbeast for hours (.3); their bodies are unchanged.

## Case table
| Case | Test | Result |
|---|---|---|
| S-166.1 | `test_S_166_1_botcake_copies_inside_the_settle_window_after_a_move_never_switch_the_page` | pass (red before the fix: `Failed asserting that two strings are identical`, the page switched to botcake) |
| S-166.2 | `test_S_166_2_botcake_copies_after_the_settle_window_switch_the_page_as_today` | pass (passes before and after: today's behaviour) |
| S-166.3 | the 32 existing `ForbeastAutoSwitchTest` cases, bodies unchanged | pass |
| S-166.4 | `test_S_166_4_botcake_copies_inside_the_settle_window_are_answered_once_on_forbeast` | pass (red before the fix: `Failed asserting that false is true`, no run queued) |
| S-166.5 | `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Unit` | pass: e7b09402 `Tests: 1448, Skipped: 1`, OK; this branch `Tests: 1451, Skipped: 1`, OK (the 3 new tests; 58 PHPUnit deprecations on both) |

## Story changes
- S-166.4 is read as "answered once on Forbeast after the grace". Under today's rules a standby copy is answered only when the page switches, so without a switch the customers in the incident would get no answer. The decision's "handled exactly as standby chats are today (replied if no twin per the existing standby rules)" is met with the same per-chat rules, run without the switch.

## Review
Skeptic reviewer, standard depth: no blockers or majors. 6 minors are accepted with reasons in `TODO.md` (no lower bound on the window, the shared switch lock, 4 untested edges).

## Conflicts
- None between CLAUDE.local.md and the spec. Deviation: the existing test file's `setUp` changed (one line plus a comment) to make .3 true; the test bodies did not change.

## Deploy notes
- No migration and no config change. One file in `app/`: `app/Services/Chatbot/ForbeastWatch.php`.
- The watch runs from the scheduler every minute (`chatbot:forbeast-watch`, run in the background), so it loads the new code on its next run; no worker restart is needed for it. Restart the queue workers as usual after a deploy.
- After the deploy, move Jenny De Guzman, Xavia Aquino and Chelsea Mercado back to Forbeast by hand (out of scope here).
- Applies to Incepxion as well (same codebase and file).

166: done — late Botcake copies within 180 s of a move to Forbeast no longer switch the page back, and they are answered once on Forbeast.
