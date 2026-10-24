# Result 146: the chatbot pages "Changes" list shows every change

Branch `fix/146-all-page-changes`, cut from `7754345f` (live Likha main). Not pushed, not deployed.

## Files
- `app/Http/Controllers/ChatbotController.php`: `changes()` uses the new clear rule; a write's "before" is "(none)" when the field had been cleared before it; `active` is listed as ACTIVE with On/Off (and is in the field filter); the On/Off switch and Add page pass the signed-in user to the `active` write.
- `app/Services/BotcakeChatBlock.php`: `turnOn()` / `turnOff()` take an optional user id for the `active` row (default null, so the CLI caller is unchanged).
- `resources/views/chatbot/index.blade.php`: "(none)" is shown italic grey, not struck through (inline script; no Vite asset change).
- `tests/Feature/Chatblast/ChatbotAllChangesTest.php`: S-146.1–.7.
- `qa/stories.md`: S-146 section.

## Clear-detection rule
A row is a **clear** event (at `replaced_at`, by `replaced_by`, "system" when null) when it is inactive, `replaced_at` is set, and no row for the same page + provider + key with a higher id was created at or before `replaced_at + 5 seconds`. A later value no longer hides it. The 5 seconds (`CLEAR_GAP`) is written per driver: MySQL `DATE_ADD(... INTERVAL 5 SECOND)`, PostgreSQL `+ interval '5 seconds'`, SQLite `datetime(..., '+5 seconds')`.
A **write**'s "before" is "(none)" when the previous row was deactivated more than 5 seconds before this write; otherwise the previous row's value; null ("(new)" in the UI) when there is no previous row.

FLOW: `flow_id` stays hidden (it moves together with `flow`, so one line per event, no duplicate). `mock_ai*` stay hidden; secrets stay "(hidden)".

## Case table
| Case | Test | Result |
|---|---|---|
| S-146.1 | `test_S_146_1_a_removed_flow_stays_listed_after_it_is_set_again` | pass |
| S-146.2 | `test_S_146_2_a_replacement_is_one_line_without_a_clear` | pass (also passed before the change: pins today's behaviour) |
| S-146.3 | `test_S_146_3_the_bot_switch_shows_on_and_off_with_the_user` | pass |
| S-146.4 | `test_S_146_4_the_user_filter_finds_the_remover_and_the_field_filter_finds_every_event` | pass |
| S-146.5 | `test_S_146_5_the_date_filter_uses_the_time_of_the_clear` | pass (also passed before: pins today's behaviour) |
| S-146.6 | `test_S_146_6_secret_values_stay_masked_in_writes_and_clears` | pass |
| S-146.7 | `test_S_146_7_paging_across_writes_and_clears_is_stable` | pass |

Red run before the code: 5 of 7 failed with "Failed asserting that two arrays are identical" (.1, .3, .4, .6, .7).

## Evidence
- `ChatbotAllChangesTest`: `OK (7 tests, 44 assertions)`.
- `php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast tests/Feature/PageSettings`: before `Tests: 911, Assertions: 6541, Skipped: 1` (OK); after `Tests: 918, Assertions: 6585, Skipped: 1` (OK, 46 PHPUnit deprecations as before). No new failures.
- `php -l` clean on the changed PHP files.

## Story changes
- S-146.1: the first set (16:07) shows "(new) → Main Flow", not "(none) → Main Flow": a write with no earlier row keeps today's `old: null` (shown "(new)"), which existing tests S-099.8 and S-111.8 pin. "(none)" is used when the field had been cleared before the write.

## Notes and suggestions
- Old `active` rows written by the switch before this change have no user and still show "system"; only new switch presses carry the user.
- Not done: a browser check (no browser session here), a skeptic review (single medium-risk slice, not run in this session).

## Commits
- `f79239f3` docs: spec 146 all page changes
- `6be94991` fix: chatbot Changes lists every write and clear, with the flow and the bot switch (spec 146)
- this result commit

146: done — Changes now lists every write and every clear (never hidden by a later value), with "(none)" before a re-set, FLOW and the ACTIVE switch with the user; 918 tests green.
