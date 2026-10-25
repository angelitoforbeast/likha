# 148 Connections popup: result

## Plan

Access for every new route: `ChatbotController::checkAccess` (NavAccess `/chatblast`, 403 for others), and the page must be on the chatbot list (422). After review, the Botcake save and Fix chat block also need the CEO (see Open points).

| Task | Covers | Tier | Side |
|---|---|---|---|
| T1 Connections backend: `ChatbotConnections` service and routes `GET /chatbot/connections`, `POST /chatbot/connections/{botcake,forbeast,chat-block}`. The token is tested before it is saved (Botcake: the custom_fields test; Forbeast: `GET /v1/pages/{id}` with name and id compare). Then the takeover guard (Botcake: `GET default_reply` JSON API hosts; Forbeast: `webhook.url`). Saves go through `PageSettings`, followed by `BotcakeChatBlock::turnOn` (key kept) or `ForbeastPageSetup::connect`. Answers carry the last 4 only. | .1 .2 .3 .4 .5 .6 .13 | high (new routes, provider data) | backend |
| T2 Connected and Check: a `connected` list per row in `/chatbot/data`. `PageContentCheck::setup` adds "Botcake: token not working" and "Forbeast: token not working / not connected". | .9 | medium | backend |
| T3 Nightly `chatbot:connections-check`, after `botcake:setup-check`. | .12 | medium | backend |
| T4 UI: the Connections button and popup; the row's Forbeast buttons and prompt removed; the page settings Forbeast lines replaced by a link; the Connected column; the Bot select limited to complete connections; Flow as text + ✎ popup with search and confirm; header filters; sticky header; 390 px. | .1 .7 .8 .10 .11 .14 .15 | medium | frontend |

The repo has no JS test runner. The PHPUnit tests pin the server side and the markup; the browser parts are listed under Evidence.

## Progress
- [x] T1: 1 review loop (1 blocker and 4 majors fixed, re-check clean)
- [x] T2: reviewed with T1
- [x] T3: reviewed with T1, fixed in the same loop
- [x] T4: standard review, no blocker or major; 3 minors fixed, the rest in TODO.md
- [ ] Browser check: not run. The dev server needs the local database, which is not running in this worktree (`schedule:list` could not reach pgsql). S-148.8, .11, .14 and .15 need it before release.

## Files
- New: `app/Services/Chatbot/ChatbotConnections.php`, `app/Console/Commands/ChatbotConnectionsCheck.php`, `tests/Feature/Chatblast/ChatbotConnectionsTest.php`.
- Changed:
  - `app/Services/Chatbot/ForbeastPageSetup.php`: `probe()` tests a token without writing; `retest()` is the nightly test, with no new secret and no PUT.
  - `app/Http/Controllers/ChatbotController.php`: `connections()`, and `connected` in `data()`.
  - `app/Services/Chatbot/PageContentCheck.php`: the setup reasons.
  - `app/Services/Chatbot/ChatbotPageCheck.php`: the cache fingerprint includes `forbeast` rows, so a Forbeast status change refreshes Check.
  - `routes/web.php`, `routes/console.php`.
  - `resources/views/chatbot/index.blade.php`, `resources/views/page_settings/index.blade.php`.
  - `qa/stories.md` (S-148), `TODO.md`.

## Routes added
- `GET /chatbot/connections?page_name=…` (`chatbot.connections`): returns both cards (Page ID, token as `••••last4`, status, chat block, connected). Throttle 120/min.
- `POST /chatbot/connections/{provider}` (`chatbot.connections.save`), provider `botcake` | `forbeast` | `chat-block`. Body: `page_name`, `page_id`, `token` (blank keeps the saved one), `confirm`. Answers:
  - 200: ok;
  - 422: invalid, mismatch, or a bad Page ID; nothing saved;
  - 409: `{status: confirm, host, message}`; nothing saved until it is sent again with `confirm: true`;
  - 502: the provider did not answer;
  - 403: no access, or the Botcake / chat-block route for a non-CEO.

  Throttle 30/min.
- Schedule: `chatbot:connections-check` daily at 22:15 Manila (after `botcake:setup-check` at 21:30), `withoutOverlapping(60)`, in the background.

## Removed
- /chatbot/pages: the row's Forbeast box (status line, Add token / New token, Test, Clear) and its `prompt()`. Also removed: `forbeastSave`, the `data-forbeast-token-url` attribute, and the Flow dropdown in the row.
- Page settings: the `forbeast.token` and `forbeast.token_status` lines in the Botcake cell and the "Test Forbeast" button (with its click branch). One line now links to /chatbot/pages: "Forbeast: set up in Chatbot pages → Connections". **The Botcake ID/token lines in page settings are kept as they are** (smallest change; they still run their own tests).
- The routes `chatbot.forbeast-token` and `page-settings.forbeast-test` still exist and stay tested, but the UI no longer calls them. Cleanup is listed in TODO.md.

## Takeover guard
- **Forbeast:** the API exposes the current webhook. Spec 113 documents `GET /v1/pages/{id}` → `webhook: {url, set_at} | null`. When `webhook.url` is not this app's `/api/gateway/events`, the save answers 409, e.g. "Connected to incepxion.pro now. Move it to this site?". The nightly test marks such a page `error · Webhook points to <host>`, or `error · Webhook not set`, and does not re-register it. Not checked live (no real calls).
- **Botcake:** the guard reads `GET /pages/{id}/default_reply` and collects every `json_api` card URL.
  - A host other than ours → 409 with the host.
  - Our host, or no JSON API block → no question.
  - An unknown answer (non-2xx, `success:false`, not JSON, or a URL with no host) → 409 "Could not check whether this page's Default Reply points to another site. Replace it anyway?".

  "Fix chat block" runs the same guard. **That endpoint is not verified against the live Botcake API.** If it does not exist, every Botcake save asks the "could not check" confirm, and the nightly never re-applies a block. That is safe, but the endpoint should be checked with one read-only call before release.

## Story changes
- S-148.5: added "or a Default Reply that cannot be read" (the guard fails closed after review).
- S-148.6: Fix chat block also asks the confirm when the reply points elsewhere.
- S-148.12: "missing chat block" now means our block is gone from the Default Reply of a page switched On here (`chatbot.active = 1`, which only `turnOn` writes). It does not mean an empty chat.key: chat.key is never cleared, and an unset ACTIVE row covers pages never set up here, whose Default Reply must not be overwritten.
- S-148.13: added the boundary "a /chatblast user who is not CEO can read and save Forbeast but gets 403 on Botcake and chat block".
- S-148.3 (Botcake half): there is no name compare, because Botcake returns no name. The Botcake half is "the token fails for this Page ID".
- S-148.8, .10 (popup), .11, .14, .15 are browser cases. No JS runner exists, so they are marked "not run".

## Evidence per case
Final run of `phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode`: **OK, Tests: 1450, Assertions: 8381, PHPUnit Deprecations: 74, Skipped: 1**. Baseline at d1bdca9f: OK, 1439 tests, 74 deprecations, 1 skipped. No new failures.

In the first full run, `ChatbotProviderTest::test_S_111_11` failed because it pins the literal `text: 'Forbeast'` option label. The label is kept again in `720612db`, and the test passes now.

Kit hook tests (`node --test 'test/hooks/*.test.mjs'`): 1026 pass, 5 fail, all symlink cases of `format-on-edit` (Windows cannot create the symlinks). This branch does not touch `test/hooks`.

| Case | Test | Result |
|---|---|---|
| S-148.1 | `test_S_148_1_the_popup_data_has_both_cards_with_only_the_last_4_of_each_token` | pass |
| S-148.2 | `test_S_148_2_a_valid_botcake_token_is_tested_saved_and_the_chat_block_turned_on_with_the_same_key` | pass |
| S-148.3 | `test_S_148_3_a_token_that_does_not_match_the_page_is_red_and_not_saved` | pass |
| S-148.4 | `test_S_148_4_a_forbeast_token_connects_and_the_page_shows_forbeast_connected` | pass |
| S-148.5 | `test_S_148_5_a_page_pointing_at_another_site_needs_a_confirm_before_it_moves` | pass |
| S-148.6 | `test_S_148_6_fix_chat_block_re_applies_with_the_same_key` | pass |
| S-148.7 | `test_S_148_7_the_row_has_a_connections_popup_instead_of_the_forbeast_buttons_and_page_settings_links_to_it` (markup) | pass |
| S-148.8 | none (JS); code reviewed: options = current + complete connections, text when one, confirm "Switch X from A to B?" | not run in a browser |
| S-148.9 | `test_S_148_9_connected_lists_only_complete_connections_and_check_gives_the_reason_for_the_rest` | pass |
| S-148.10 | `test_S_148_10_a_flow_picked_in_the_popup_is_linked_and_changes_shows_who_changed_it` (server path); popup search and confirm reviewed only | pass (server) |
| S-148.11 | none (JS); markup in S-148.7 (Check all, Uncheck all); logic reviewed | not run in a browser |
| S-148.12 | `test_S_148_12_the_nightly_check_retests_tokens_and_re_applies_a_missing_chat_block_only_on_active_botcake_pages` | pass |
| S-148.13 | `test_S_148_13_the_new_routes_need_chatblast_access_botcake_writes_need_the_ceo_and_a_listed_page` | pass |
| S-148.14 | none: the table scrolls inside `.cb-scroll`; the popups and filter panel are capped at `100vw - 1rem` | not run in a browser |
| S-148.15 | none: `thead th { position: sticky; top: 0 }` already existed and is kept | not run in a browser |

Red runs, one line each:
- T1: every S-148 route test failed with "Expected response status code [200] but received 404" (no route).
- T3: "The command "chatbot:connections-check" does not exist."
- Loop 1: on HEAD~1's `app/`, S-148.5 got "Expected 409 but received 200", S-148.12 got "two arrays are identical" (block written on the wrong pages), and S-148.13 got "Expected 403 but received 422".
- T4: S-148.7 failed with "contains id="conn"".
- S-148.9 and S-148.10 passed on their first run. S-148.9's code was written in T1 before its test; S-148.10 pins an existing path. Neither had a red run.

## Commits
- `cfc7c135` docs: spec 148 connections popup and plan
- `4ee48217` feat: connections popup backend, Connected list and setup reasons (spec 148)
- `cf1cb98b` feat: nightly chatbot connections check (spec 148)
- `3e884013` fix: takeover guard fails closed, Botcake writes CEO-only, nightly restores only removed blocks (spec 148)
- `f0d70c25` feat: Connections popup, Connected column, Bot and Flow pickers and header filters on chatbot pages (spec 148)
- `0982f689` fix: connections popup ignores stale answers, Botcake card read-only for non-CEO, filter panel fits the screen (spec 148)
- `6602f4d5` docs: S-148 stories and accepted review minors (spec 148)
- `720612db` fix: keep the Forbeast option label of the Bot select (spec 148)
- (this result)

## Open points
1. **For the owner: Botcake returns no page name.** The Botcake card cannot do decision 2's name compare. A Botcake token is page-scoped, so a token for another page fails on this Page ID. A matching Page ID and token of another, unlisted Facebook page would still be accepted. Accept, or name a Botcake call that returns the page name.
2. **Botcake `GET /pages/{id}/default_reply` is unverified** (see Takeover guard). Check it with one read-only call before release.
3. **Access is narrower than the brief's "same as today's token routes".** The Botcake save and Fix chat block publish the Default Reply and switch the bot On, which today is CEO-only (On/Off, + Page). So those two need the CEO. Forbeast keeps today's /chatblast rule. Non-CEO users see the Botcake card read-only.
4. **The Forbeast Page ID is prefilled and editable, but it must equal the Botcake ID.** Inbound gateway events are matched to pages by the Botcake page id, so another id would drop every chat. Changing the Botcake Page ID clears the Forbeast status, so the page drops out of Connected until the Forbeast token is saved again.
5. **An untested Botcake token is not in Connected and has no Check reason** until the first nightly run (the S-145 checks pin "no reasons" for such pages). Pages that are Off get no setup reasons, as today.
6. **Conflict between the workflow and this brief:** the kit says to wait for the reviewer's "go" on the plan. The brief said to write the plan and continue, so I continued.
7. The browser check is still to do: S-148.8, .11, .14 and .15 at 390 px and on a desktop.
8. Not deployed and not pushed. After deploy there are no migrations; the new schedule entry runs at 22:15.

148: done — the Connections popup, Connected column, Bot and Flow pickers, header filters and nightly re-test are built with all server cases green (1450 tests, no new failures). The browser cases and the live check of Botcake `default_reply` are still open.

## Amendment 148-1

| Change | Who decided, why |
|---|---|
| **Keep a Test button.** Each card in the Connections popup (Botcake and Forbeast) has a **Test** button that re-tests the saved token at any time (Botcake: token and chat block; Forbeast: connect check), shows the result and the time of the last test. Pasting a new token still tests it at once (decision 2 stands). This replaces the line in the brief that said there is no separate Test click. | The owner, 2026-10-25: "dapat di mawawala yung test button diba?" |
| **Access: CEO, Marketing OIC and Marketing.** The Connections popup (save/clear token, Test, Fix chat block, takeover confirm), the **On/Off** toggle (`ChatbotController::active`, today `checkCeo()`), and **+ Page** (`addPage`, today CEO only) are allowed for the roles CEO, Marketing OIC and Marketing (match the role names the way the controller's existing role check does; find the exact stored names for the Marketing roles in the code/seeders and reuse them). Everyone else keeps today's rule. Update the comment above the /chatbot route group accordingly. | The owner: "pwede marketing OIC or marketing, sila naman maghahandle niyan e, as long as may complete logs pa din". |
| **Complete logs.** Every one of these shows in the page's Changes list (spec 146) with who and when: On and Off; token saved / replaced / cleared for Botcake and Forbeast (never the token itself, only "(hidden)"); every Test with its result (ok / invalid / error + short reason, no secrets); Fix chat block; the nightly re-test and any chat block it re-applied (by "system"); Bot (provider) switch; Flow change. If Test results are not page_settings rows today, record them the smallest way that makes them appear in Changes (e.g. the existing *_status setting rows with the user id), and say how in the result. | Same owner line: "as long as may complete logs". |

Tests to add: a Marketing and a Marketing OIC user can use On/Off, + Page and the popup (and a role outside these three still gets 403); each logged action above appears in Changes with the right user; the Test button re-tests a saved token and updates the last-test time. Re-run the brief's test folders (no new failures). Update the result (Files, Evidence, Story changes: S-148 cases for access and logs) and end with "148-1: <done|blocked> — <one line>".

## Amendment 148-2

| Change | Who decided, why |
|---|---|
| **Read the Default Reply content from the right endpoint.** Checked live (read-only) on 2026-10-25: `GET /pages/{id}/default_reply` returns only `{success, settings{is_published, unlimit_default_reply, ...}, page_id, assistants}` with no blocks, so `ChatbotConnections::botcakeReply` always finds no URL. `GET /pages/{id}/default_reply/content` returns `{name, status: "published", blocks: [{cards: [{plugin_id: "json_api", config: {method, url: "https://likhaaitech.com/..."}, headers: [{key: "X-Chat-Key", value}], ...}]}]}` and has no `success` key. Use `/default_reply/content` for the takeover guard and for the nightly "is the block still there" check (our block = a json_api card whose config.url host is this app's host and whose X-Chat-Key header equals the page's chat.key); use `/default_reply` only for `settings.is_published`. Update the fakes in the tests to these real shapes. | The reviewer's live read-only check; the worker's Open point 2. |
| Open points 1, 4, 5 of the result are accepted as they are (Page ID is the identity for Botcake; the Forbeast Page ID must equal the Botcake ID; a never-tested token is listed after its first test or nightly run). Open point 3 is replaced by Amendment 148-1 (Marketing OIC and Marketing may also use Botcake saves, Fix chat block, On/Off and + Page, with complete logs). | The reviewer's decision; the owner's access rule. |

End with "148-2: <done|blocked> — <one line>".
