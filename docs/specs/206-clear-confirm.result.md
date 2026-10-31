# Result: spec 206 clear confirm and Botcake Clear

**Status:** done
**Date:** 2026-10-31
**Branch / PR:** `feat/206-clear-confirm` (from 53478a0b), not pushed, no PR
**Preview or run link:** n/a (not run in a browser; the page scripts were run in node from the rendered HTML)

## Summary
The keys that connect a page are one rule, `PageSettings::clearWarning(full, secret)`: any `*.page_id`, any `*.token`,
any secret, `botcake.chat.key` and `chatbot.flow_id`; it returns what stops working, or null for other keys. On
/chatblast/page-settings the grid columns carry that text as `important`; an emptied important cell is not saved on the
typing pause, and leaving it asks "Clear <key> of <page>? Current value: <value> <what stops working>" (the value is the
cell's display value, so a secret shows its last 4 only); Cancel sends nothing, yes posts with `clear: true`.
`saveCell` refuses a blank on an important key without `clear: true` (422, nothing written), and the ⋯ history now says
who cleared a value. Connections → Botcake has a Clear (route `botcake-clear`, same access and throttle as
`forbeast-clear`): after a confirm naming the page and the Botcake page ID it deactivates that page's `botcake.page_id`,
`botcake.token` and `botcake.chat.key` with the user in one transaction and clears the page map cache, with no call to
Botcake and no change to `chatbot.active` or the chat block. With amendment 206-1, the Connections data lists the
gateways that have a token (`botcake.gateways`: Forbeast, Incepxionbot) and the Botcake Clear confirm adds one line naming
only those, since they find the page's chats by the same Botcake page ID.

## Amendments applied
- **206-1:** the Botcake Clear confirm adds one line when the page has an active Forbeast and/or Incepxionbot token,
  naming only the gateways with a token, and no line without one. The text is built in the page (there is no server
  409 for this Clear), from `botcake.gateways` in the Connections data. New test
  `test_S_206_4_the_confirm_names_the_gateways_that_stop_too` covers no gateway, Forbeast only and both; the earlier
  confirm-text test is unchanged (its page has no gateway token, so no line). Red first: `no gateway token — Failed
  asserting that null is identical to Array &0 []`.

## Case table
| Case | Test | Result |
|---|---|---|
| S-206.1 | `ClearConfirmTest::test_S_206_1_emptying_the_botcake_id_and_leaving_asks_first_and_cancel_sends_nothing` | pass — `✔ S 206 1 emptying the botcake id and leaving asks first and cancel sends nothing` |
| S-206.2 | `ClearConfirmTest::test_S_206_2_confirming_clears_the_id_with_the_user_and_the_history_shows_who_and_when` | pass — `✔ S 206 2 confirming clears the id with the user and the history shows who and when` |
| S-206.3 | `ClearConfirmTest::test_S_206_3_a_blank_on_an_important_key_without_the_clear_flag_is_refused` (3 rows), `…_the_refusal_holds_with_two_active_rows_and_odd_case_or_spaces`, `…_a_blank_on_any_other_key_still_clears_without_the_flag_and_a_secret_blank_changes_nothing`, `…_the_important_keys_are_one_rule` | pass — `✔ S 206 3 …` (6) |
| S-206.4 | `BotcakeClearTest::test_S_206_4_clearing_the_duplicate_frees_the_id_for_the_real_page`, `…_the_confirm_names_the_page_and_the_id_and_says_when_the_bot_is_on`, `…_the_clear_has_the_access_of_the_forbeast_clear`, `…_the_confirm_names_the_gateways_that_stop_too` (206-1) | pass — `✔ S 206 4 …` (4) |
| S-206.5 | `BotcakeClearTest::test_S_206_5_the_clear_calls_nothing_and_leaves_the_bot_switch_and_the_chat_block_as_they_are` | pass — `✔ S 206 5 the clear calls nothing and leaves the bot switch and the chat block as they are` |
| S-206.6 | `BotcakeClearTest::test_S_206_6_the_full_token_never_reaches_the_answer_or_the_page`, `ClearConfirmTest::test_S_206_6_the_question_shows_only_the_last_4_of_a_secret_and_the_page_never_has_the_token` | pass — `✔ S 206 6 …` (2) |
| S-206.7 (P2) | `BotcakeClearTest::test_S_206_7_no_botcake_id_no_clear` | pass — `✔ S 206 7 no botcake id no clear` |
| S-206.8 | full `tests/Feature` | pass — 3780 tests, the same 2 failures as the baseline (ExampleTest, ItemTableFitScriptTest), 4 skipped; no new failures |

Red runs (before the code): the first 14 tests failed for the missing behaviour, e.g. `Call to undefined method
App\Services\PageSettings::clearWarning()`, `the helper is on the page — Failed asserting that 0 is identical to 1`,
`Expected response status code [422] but received 200` (S-206.3), `Expected response status code [200] but received 404`
(botcake-clear). The review fix (a Clear without the confirmed ID) failed first with `Expected response status code [422]
but received 200`. The two-active-rows / odd-case test was added on review and passed on its first run (it pins
behaviour the guard already had).

## Story changes
- Added section S-206 to `qa/stories.md` (the spec's cases .1 to .7, plus S-206.8 for the full-suite check, as earlier
  slices do).
- S-206.3 extended: two active rows and odd case or spaces in provider and key are refused too; a blank on a secret stays
  "no change" (reason: review finding, and the secret ruling below).
- S-206.4 extended: a missing or different confirmed page ID clears nothing; Changes shows the clear with the user
  (reason: review finding; the history part of the spec's access decision).
- S-206.2 Then made concrete: the ⋯ history entry carries `replaced_by` (who cleared); before this change it showed only
  when.
- S-206.4 extended by amendment 206-1: the gateway line in the confirm (no gateway, one, both); now 4 tests.

## Done-when checklist
- [x] P1 pass with tests, failing first, then passing — case table and red runs above.
- [x] tests/Feature no new failures vs the baseline on 53478a0b — see Tests.
- [x] The rendered confirm text for .1 and .4 — node run of the helpers cut from the rendered HTML (below).
- [x] Deploy notes — below (no migration).
- [x] The final line.

Evidence for the rendered confirms. The pages were rendered by a throwaway test with the live case (both rows with
Botcake page ID 1085660977960440, the duplicate's bot On), and the helpers between the `// clear-ask:start/end` and
`// botcake-clear:start/end` markers of that HTML were run in node:

```
== S-206.1 confirm (page settings)
Clear botcake.page_id of Alaga Natural Collective?
Current value: 1085660977960440
Botcake chats of this page stop reaching the bot here, and Chatblast and the Botcake sync stop for it.
== S-206.1 Cancel sends
null
== S-206.1 while typing sends
null
== S-206.2 yes sends
{"page_name":"Alaga Natural Collective","provider":"botcake","key":"page_id","value":"","is_secret":false,"clear":true}
== S-206.4 confirm (Connections, bot On)
Remove Botcake page ID 1085660977960440 from 4RO Alaga Natural Collective? This page stops receiving Botcake chats here. Botcake itself is not changed.
The bot of this page is On: it stays On, with no Botcake chats coming in here.
== S-206.7 Clear shown with ID / without
[true,false]
```

With amendment 206-1, the same live case plus a Forbeast token on the duplicate row (rendered page and
`/chatbot/connections` data, run in node):

```
gateways: ["Forbeast"] · bot On: true
Remove Botcake page ID 1085660977960440 from 4RO Alaga Natural Collective? This page stops receiving Botcake chats here. Botcake itself is not changed.
The bot of this page is On: it stays On, with no Botcake chats coming in here.
Forbeast also stops for this page: it finds its chats by this Botcake page ID.
-- same page, bot Off:
Remove Botcake page ID 1085660977960440 from 4RO Alaga Natural Collective? This page stops receiving Botcake chats here. Botcake itself is not changed.
Forbeast also stops for this page: it finds its chats by this Botcake page ID.
```
(The Forbeast token is in neither the rendered page nor the Connections JSON.)

The same texts are asserted exactly in `test_S_206_1…`, `test_S_206_4_the_confirm_names…` and
`test_S_206_4_the_confirm_names_the_gateways_that_stop_too`, which run the helpers from the rendered page in node.

## How to run
```
git checkout feat/206-clear-confirm
composer install
php vendor/bin/phpunit tests/Feature/PageSettings/ClearConfirmTest.php tests/Feature/Chatblast/BotcakeClearTest.php
php vendor/bin/phpunit tests/Feature
```
Node must be on PATH for the cases that run the page scripts (they skip without it).

## Rulings
- Ruling: a blank on a secret cell stays "no change" (200 unchanged), not a 422 — the grid already never clears a secret on a blank (only the ⋯ clear with the flag does), an existing test pins it, and a 422 would mark every secret cell red on focus and leave — cost if wrong: a scripted blank to a secret gets "unchanged" instead of 422; the value stays either way.
- Ruling: an emptied important cell is not saved on the typing pause; it asks only on leaving the cell (blur, Enter, or moving to another cell) — the spec says "emptying … and leaving it shows the confirm"; asking mid-typing would interrupt replacing an ID — cost if wrong: none for data; the question comes a moment later.
- Ruling: the question text is built in the page from the column's `important` text sent by the server, so the list stays in PHP only — cost if wrong: a column that first appears through the poll has no text and gets the server's 422 until reload (TODO.md).
- Ruling: the ⋯ history entries now carry `replaced_by` and the line reads "… → <when> by <who>" — S-206.2 asks for who and when, and the history showed only when — cost if wrong: one more short field in the modal.
- Ruling: the Botcake Clear requires the confirmed page ID (`page_id` in the POST must equal the current one, else 422, nothing removed) — the threat model names stale tabs and scripted POSTs; the popup always sends it — cost if wrong: a caller without the ID gets a 422.
- Ruling: the Botcake Clear refuses a page with no Botcake page ID (422) and deactivates the three rows in one transaction — no empty "clear" calls, no half-cleared page — cost if wrong: none.
- Ruling: the Botcake Clear button is hidden (not disabled) without a page ID — the spec allows either — cost if wrong: cosmetic.
- Ruling: after the Clear the page leaves the /chatbot list (it has no Botcake ID); the response still carries the refreshed Connections info by the page name given — cost if wrong: none; the row comes back when an ID is saved again from page settings or + Page.
- Ruling: the "bot is On" line reads "The bot of this page is On: it stays On, with no Botcake chats coming in here." — the spec says the confirm says so but gives no words — cost if wrong: wording.
- Ruling (206-1): the gateway line agrees in number — one gateway: "Forbeast also stops for this page: it finds its chats by this Botcake page ID."; both: "Forbeast and Incepxionbot also stop for this page: they find its chats by this Botcake page ID." — the amendment's "and/or" sentence is plural only — cost if wrong: wording.
- Ruling (206-1): the gateway is named as on its own Connections card ("Incepxionbot", `Gateways::LABELS`), not "IncepxionBot" — the confirm names the card the staff see beside it — cost if wrong: one letter's case.
- Ruling (206-1): "has a token" = an active `forbeast.token` / `incepxionbot.token` row, whether or not the gateway is set up on this server or the token tested ok — the amendment says an active token; that row is what routes chats once the gateway runs — cost if wrong: the line can name a gateway that is not live yet.
- Ruling: the full-suite baseline on 53478a0b ran while the first edits were made in the same worktree (it started after the spec commit and took 13 minutes); its result is exactly the two known failures of the earlier slices (ExampleTest, ItemTableFitScriptTest), so it was used as the baseline — cost if wrong: a baseline failure caused by this change would be hidden; neither of the two touches these files.

## Deferred minors
Accepted review minors, each in `TODO.md` ("Clear confirm and Botcake Clear (spec 206), accepted review minors"):
- A partial edit of an important cell still auto-saves on the typing pause (a stray backspace saves a shorter ID) — outside the spec's decision for this page, which keeps auto-save except for an emptied cell; proposed below.
- A column that first appears through the poll has `important: null` — rare; the server still refuses.
- `Schema::hasColumn` per history request — same pattern as `PageSettings::deactivate`.

## Merge danger
Two-way door. Blast radius if wrong: page settings refuse a blank on a page ID or free token until confirmed (staff see a
red "!" with the message), and the Botcake Clear removes one page's Botcake ID, token and chat key (all three stay in the
history and can be saved again; Botcake itself is untouched). Revert: revert the four code commits; no data or schema
to undo.

## Deploy notes
No migration, no new config, no queue or worker change. Views changed (`page_settings/index`, `chatbot/index`): the
usual `php artisan view:clear` (or the deploy's view cache step) on Likha and Incepxion. Routes changed (one more value
in the `connections.save` `whereIn`): `php artisan route:clear` / `route:cache` if routes are cached. No asset build
(inline scripts only). Same code on Likha and Incepxion; nothing per site.

## Tests
- New: `tests/Feature/PageSettings/ClearConfirmTest.php` and `tests/Feature/Chatblast/BotcakeClearTest.php`: 15 tests, 132 assertions, all pass.
- Affected neighbours rerun green: `tests/Feature/PageSettings` (148 tests), `ChatbotConnectionsTest` (29), `ForbeastPageTokenTest`, `ChatbotPagesTest`, `ChatblastSetupCheckTest`, `BotcakeMockTest`, `ChatbotHardeningTest`, `ChatbotFbLinkTest`.
- Full `tests/Feature`: baseline on 53478a0b: 3765 tests, 2 failures (`ExampleTest::test_the_application_returns_a_successful_response`, `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`), 4 skipped. On the branch (46c49f29 code): 3780 tests (+15 new), 71952 assertions, the same 2 failures, 4 skipped, 11:15. No new failures.
- Skeptic review (standard depth) on the 422 guard and the Botcake Clear: one major (the Clear did not require the confirmed ID) fixed in one loop and re-checked: approve.
- Amendment 206-1 run: the 206 tests 16 tests, 144 assertions, OK; `tests/Feature/Chatblast` 1553 tests, OK (1 skipped); `tests/Feature/PageSettings` 149 tests, OK on the second run — the first run had one failure in `FlowNamesTest::test_S_052_7_a_refresh_with_the_same_answers_writes_no_new_row` (`11 is identical to 10`), a time-of-second flake in a test and code this change does not touch (it passes alone 3 of 3 and in the folder rerun, and passed in the earlier full run).

## Open questions
None open.
1. Answered by amendment 206-1: the Botcake Clear confirm now names the Forbeast / Incepxionbot gateways that have a token, since they stop too (they match chats by the same `botcake.page_id`). Built and tested.

## Process suggestions
- None.

## Proposed tasks
- Important cells save only on leaving the cell — a partial edit (one backspace) on `botcake.page_id` still auto-saves the shorter ID on the typing pause, the same risk as the blank — normal.
- Check the flow picker on /chatbot/pages (`chatbot.flow_id` via /chatbot/cell) for a blank POST without a confirm flag; it has its own "Change flow" confirm in the page only — low.

206: done — page settings ask before clearing a page's ID, token or key and the server refuses a blank without the confirm; Connections has a Botcake Clear that frees a duplicate's ID without touching Botcake
