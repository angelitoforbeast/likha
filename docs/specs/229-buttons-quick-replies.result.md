# Result 229: buttons and quick replies in Send message

## Status

Done, not pushed, not deployed. No migration. Branch `feat/229-buttons-quick-replies` from vps2/main `b2ca0dba`.
T0 to T7 built after the reviewer's "go"; three reviews (T1+T2 and T3 adversarial, T4–T7 standard), one major
(fixed in `37e02825`), minors fixed on untrusted paths or accepted in `TODO.md`. Nothing changes on the wire until
the owner sets `GATEWAY_BUTTONS` (see Summary).

Merge danger: a two-way door. Blast radius with the gate empty: the editor form, the Test tab, Publish (new
blocks for button messages only, new notices), and the AI history text of messages that have buttons; every
send is byte-identical to today (tested). With the gate on: the listed gateway routes get the 049 shape, so a
gateway without spec 049 live would refuse those sends (failed, recorded, not retried) — turn it on only after
049. Revert: empty `GATEWAY_BUTTONS` (instant), or revert the commits; flows saved with buttons keep their
extra keys, which the old code drops on the next save and never sends.

## Plan

### What the code adds up to (read before the plan)

- A tap already works end to end as a message: `HandleGatewayEvent` stores a postback with the title as the
  chat text (payload in `payload.postback`), a quick reply with its text (payload in `payload.quick_reply`),
  dedupes by `mid` / `event_id`, and `FlowEngine::resumePoint` saves `$run->input` into `save_to` and leaves by
  `reply`. A late tap (wait cleared, step deleted, other flow) falls back to Start. The customer lock and
  `ChatbotRunner::skip` already decide between a tap and a skip. So S-229.4 (except .5) and all of S-229.8 are
  **characterisation tests** of today's code: they pass at once. Their red lines will say so (see Open questions).
- Every send path builds `$messages` once in `FlowEngine::send`, queues them as they are (send pool, sequence
  pool, retry), and converts them only at the wire: `BotcakeOutbox::wire()` is called by `BotcakeProvider`, the
  Botcake send pool, the sequence pool's Botcake branch and `GatewayProvider::request` (which the sequence pool's
  gateway branch also uses). Every path records through the one `BotcakeOutbox::record(..., $via, ..., $route)`.
  Those two functions are the only places the gate and the "left out" note need.
- The variable checker (`ChatbotPageCheck::names`) walks every string in the graph, so `{{vars}}` in titles are
  seen like text with no change.
- The editor saves the draft on Done and on canvas changes (not per keystroke), and `FlowGraph::clean` is all or
  nothing: one refused item blocks every later draft save. So only states the editor itself cannot reach may be
  refused on save; anything a half-made button can have (empty title, a URL being typed, an item added after
  the quick-reply item) is a Publish block.
- A failed send on a waiting step sets `WAIT_UNSENT`, and `resumePoint` re-sends the same message on the next
  customer message. A message that can never pass the gateway's checks would loop there, so the sender must
  make every message valid after filling variables (cut, drop duplicates), not only check templates on save.

### Design

**Saved graph** (`FlowGraph::cleanConfig('send')`). An item may carry, only when non-empty:
- `buttons: [{title, type: "postback", payload} | {title, type: "web_url", url}]` (Text items only);
- `quick_replies: [{title, payload}]` (Text or Image, last item only).

Each entry keeps only those keys; an entry with an unknown `type` is dropped; `title` and `payload` are strings
(titles kept with emoji and Filipino as typed; payload up to 1000, url up to 2000). An item without them gets no
new keys, so an old graph saves byte-for-byte the same. **Refused on save** (InvalidArgumentException → the
existing 422 with a message naming the step): more than 3 buttons; more than 13 quick replies; a title over 20
characters (`mb_strlen` of the template as typed); buttons on an Image item; both on one item; text over 640
characters on an item with buttons. A new public
`FlowGraph::payloadFor(title)` (upper-case, every run of non-alphanumerics → `_`, up to 1000) is the default
payload; the editor uses the same rule in JS.

**Publish** (`FlowGraph::publishProblems`, blocks, names the step): buttons with empty text; an empty title; a
postback or quick reply with an empty payload; a web_url that is not an `https://` URL (`FlowEngine::isUrl` plus
the https scheme, literal: no variables in a url); duplicate titles or duplicate payloads in one item; quick
replies on an item that is not the last (an item added after it while editing must not block the draft save).
`publishNotice` (no block) adds: a step with a postback or quick reply that does not wait (and that a sequence
step never waits, so a tap there starts the page's main flow). The controller adds a second notice line from a
new `ButtonPages::textOnly($flowId)`: the pages that reach the flow, found by the walk `ChatbotPageCheck`
already does (main flow, ad group flow, and flows reached through sequence steps; reused, not rewritten), whose
send would drop buttons today (Botcake, or a gateway route not on the list), only when the graph has buttons or
quick replies. Notices are joined into the one `notice` string the editor already shows.

**The gate.** `config/services.php` → `'gateway' => [..., 'buttons' => env('GATEWAY_BUTTONS', '')]`, read by a
new `Gateways::buttonsOn(?string $route): bool` (comma list, trimmed, lower-case; empty → false for every
route). `.env.example` gets `GATEWAY_BUTTONS=` with a comment. The route key is the one recorded in
`chatbot_replies.route`: `forbeast` (Bot 1's default address), the `GATEWAY_URLS` keys (e.g. `incepxion`), and
`incepxionbot` (Bot 2).

**Sending.** Internally the message keeps today's shape (`type: text|image`) with optional `buttons` /
`quick_replies` beside `text`/`url`, so every reader of `chatbot_replies.content` that looks at `type`, `text`
and `url` (Inbox, Live, the echo hash, the Test tab) keeps working. `FlowEngine::send` fills variables in each
title (`$run->text`), cuts a title over 20 characters to 20 and marks that entry `cut: true`, and then makes the
message valid for the gateway whatever the variables held: it drops entries whose title is empty, entries whose
title or payload repeats an earlier one in the message (after filling and cutting), and web_url buttons whose url
is not an `https://` URL (the same way it drops a bad image link today); the key goes when no entry remains. A
text with buttons over 640 characters after filling is cut to 640 and marked `text_cut: true`. `BotcakeOutbox::
wire($messages, ?string $route = null)`: strips `ai`, `ai_text`, `text_cut` and `left_out` from the message and
`cut` from every button and quick reply (nested, not only the top level); when `Gateways::buttonsOn($route)` a
text with buttons becomes `{type: "buttons", text, buttons}` and `quick_replies` stay; otherwise both are
removed and the text (or image) goes out alone. Only `GatewayProvider::request` passes its route; every Botcake
path passes none. The paths that reach `wire()`: `BotcakeProvider` (outbox and Inbox manual sends), the Botcake
send pool, both branches of the sequence pool, and `chatbot:heal-send-confirmations` (which replays stored
content through `request`, so the strip of `left_out` / `cut` matters there too). With `GATEWAY_BUTTONS` empty,
`wire()` returns exactly what it returns today for every message, and stored `content` of an old graph's send is
byte-identical. `record()`, for `kind = send` only, marks each message that had buttons or quick replies with
`left_out: true` unless `$via` is a gateway and `Gateways::buttonsOn($route)` (the `$route` argument, not the
stored column, which is kept only for forbeast), so `content` holds the buttons as built, with filled titles,
plus the note. No retry change: a gateway 4xx is already failed and not retried (`retrySend` retries only 5xx /
no answer).

**The AI.** `TestHistory::text` (used by both live and test history): for a message the AI may see (`ai` true or
an old row), append ` [Yes | No]` from the button or quick reply titles, except when the message is marked
`left_out` (the customer never saw them). `ai_text` messages and `ai: false` messages are unchanged.

**Editor** (`editor.blade.php` send form, plus the pure rules in a new `public/js/chatbot-buttons.js`, global
`ChatbotButtons`, tested in node through the existing `tests/js/call.cjs`, like `chatbot-paste.js`). Under each
item: "+ Button" (Text only, disabled at 3 or when the item has quick replies) and "+ Quick reply" (last item
only, disabled at 13 or when the item has buttons). A button row: Title (`maxlength=20`), Type (Postback /
Link), Payload (filled from the title until staff edit it) or URL. So that no save refusal is reachable from the
editor: a text item with buttons gets `maxlength=640`, and "+ Button" is disabled with a note while the text is
over 640; switching an item from Text to Image removes its buttons (quick replies stay), with a note. The nested
rows sit in their own container with their own Remove buttons, and `rowsEditor`'s remove binding (today every
`<button>` in a message row, `querySelectorAll('button')`) is narrowed to the row's own Remove button, so "+
Button" or a button's Remove never deletes the whole message. Adding a postback or quick reply turns Wait on and
shows "Wait turned on: a tap is a reply. The step's continue wire is removed." (turning Wait on replaces
`continue` with `reply`). When the flow is
used by a text-only page (passed to the editor as a data attribute from `ButtonPages`), each item with buttons
or quick replies shows "Botcake pages get the text only". When an item with quick replies stops being the last
(a new item added after it), its quick replies are kept and the row shows "Quick replies only on the last message"; Publish blocks it, naming the step. Rows wrap (`flex-wrap`, `min-w-0`) for 390 px.

**Test tab** (`test.blade.php`): under a text or image bubble, the buttons and quick replies as small buttons
with filled titles. A postback or quick reply click sends its title as the test message (the same `testSend`
the input box uses, so it continues the flow as a tap would). A link button is an `<a target="_blank"
rel="noopener">` whose `href` is set only for an `https://` url (the Test tab runs drafts, which have not passed
Publish; the sender already drops other urls, this is the second guard). Test runs use `TestOutbox`, which sends
nothing.

**Not changed.** `HandleGatewayEvent`, `ChatbotRunner`, `resumePoint`, `ports()`, the Inbox (it reads `text` and
`url`; a test proves the new keys do not break it), the pools' queue tables (the payload is JSON of the same
messages).

### Tasks (in order; each one commit, test and code together; no task is parallel-safe: T1–T3 share FlowGraph / BotcakeOutbox and T6–T7 share the blade layer)

| # | Task | Side | Tier | Cases | Review |
|---|---|---|---|---|---|
| T0 | Stories: slice 229 into `qa/stories.md` (spec's stories as given) | docs | low | — | lint only |
| T1 | Saved graph: `cleanConfig` keys and save refusals, `payloadFor`, old graphs unchanged, checker sees title vars | backend | high (save API is untrusted) | 1.1, 1.2, 1.3, 1.6, 1.7 (save), 1.9, 2.1, 2.2 (save), 2.3, 2.4, 2.6, 3.2 | skeptic adversarial |
| T2 | Publish: problems and notices, `ButtonPages::textOnly` | backend | high | 1.4, 1.5, 3.3 (publish), 4.5 (publish), 6.1–6.6 | skeptic adversarial |
| T3 | Sending: fill and cut titles and text, drop duplicates and non-https links, the gate in `wire()` (nested strip), `left_out` in `record()`, every path incl. the heal command | backend | high (live sends) | 1.7 (send), 1.10, 2.5, 2.7, 5.1–5.7, 7.3, gate-off wire test | skeptic adversarial |
| T4 | Taps and late taps (characterisation) and the Inbox title | backend | medium | 4.1–4.4, 4.6–4.8, 8.1–8.5 | skeptic standard |
| T5 | The AI view of titles | backend | medium | 9.1–9.4 | skeptic standard |
| T6 | Editor: `chatbot-buttons.js` rules and the send form, the narrowed `rowsEditor` remove | frontend | medium (UI) | 1.1/1.2 (UI), 1.8, 2.2 (UI), 2.3 (UI), 3.1, 3.3 (UI), 3.4, 4.5 (UI), 5.2 (warning), 7.6 (markup only) | skeptic standard |
| T7 | Test tab: draw and click (https-only href); Inbox does not break | frontend | medium | 1.10 (Test tab), 7.1, 7.2, 7.4, 7.5 | skeptic standard |

New cases from the spec review (story changes, added to slice 229 in `qa/stories.md` and listed under Story
changes): **S-229.1.10** edge: a draft posted to the save API with a `javascript:` (or any non-https) web_url →
the sender drops that button and the Test tab draws no link. **S-229.2.7** edge: text with buttons that only
passes 640 characters, or titles that only become duplicates, after variables are filled → the message is cut
or the duplicate dropped, the gateway gets a valid message, and a waiting step does not loop on a failed send.

Case 7.6 (no sideways scroll at 390 px) cannot be proved on rendered HTML: the test pins the wrapping classes,
and the real check is the browser check (or it is accepted as unproved if the session has no browser tools).

Then the full suite once (and the same suite on `vps2/main` for the pre-existing failures), `npm run build`,
the optional browser check of the editor and Test tab at 390 x 844 if the session has browser tools (otherwise
said in the result), and the result filled in. No push and no PR from here: the reviewer ships.

Tests: `tests/Feature/Chatblast/ChatbotButtonsGraphTest.php` (T1, T2), `ChatbotButtonsSendTest.php` (T3, T5),
`ChatbotButtonsTapTest.php` (T4), `ChatbotButtonsEditorTest.php` (T6: rendered HTML plus the node script
tests), `ChatbotButtonsTestTabTest.php` (T7). One test per case, named `test_S_229_x_y_…`.

The kit's `backend-developer` / `frontend-developer` agents are not installed in this session's agent list, so
the main session builds each task itself, red first; `skeptic-reviewer` reviews per the tier table.


## Summary

Built T0 to T7 as planned, after the reviewer's "go" and its decisions on the three open questions. Not
pushed, not deployed, no migration. Nothing changes for any page until the owner sets `GATEWAY_BUTTONS`.

| Commit | Task | What shipped |
|---|---|---|
| `300347df`, `c171857b`, `46df11e0` | — | the spec, the plan, revised after the spec review |
| `4d60838e` | T0 | slice 229 in `qa/stories.md` (57 cases) |
| `f2f65e87` | T1 | `FlowGraph::cleanConfig('send')`: `buttons` (Text only) and `quick_replies` kept only when non-empty, each entry only `title`, `type`, `payload` or `url`; unknown types dropped. Save refuses: more than 3 buttons, more than 13 quick replies, a title over 20 characters (code points, as typed), buttons on an Image, both on one item, text over 640 with buttons. `FlowGraph::payloadFor()`. |
| `666c8923` | T2 | Publish blocks (naming the step): buttons or quick replies with no text (or image link), empty title, empty payload, a link that is not a literal `https://` URL, duplicate titles or payloads in one message, quick replies not on the last message. Publish notices: a tap step that does not wait; the pages that get the text only (`ButtonPages`: main flow, ad group flow, flows reached through sequence steps; Botcake or a route not on the list). Config `services.gateway.buttons` (`GATEWAY_BUTTONS`), `Gateways::buttonsOn()`, `Gateways::sendRoute()`, `.env.example`. |
| `37e02825` | fix loop 1 (T1+T2 review) | a title with broken UTF-8 posted as a form is cleaned (it used to wipe the draft with a 500); quick replies on an empty message block Publish; a misplaced docblock. |
| `c0c06e31` | T3 | `FlowEngine::send` fills variables in titles, cuts a title over 20 (mark `cut`), drops empty or repeated titles, repeated payloads and non-https links, cuts text with buttons over 640 (mark `text_cut`), keeps quick replies on the last message only. `BotcakeOutbox::wire($messages, $route)`: strips our marks (also nested `cut`); 049 shape only for a route on the list, the text or image alone everywhere else. `record()` marks `left_out` on a send whose buttons did not go out. `GatewayProvider::request` passes its route. |
| `cb5945bf` | T4 | tap tests from the gateway event through `ChatbotRunner` (no code change: the behaviour already existed). |
| `30162116` | T5 | `TestHistory::text`: "Text [Yes \| No]" for a message the AI may see, not when `left_out`. |
| `b7f1c8b3` | T6 | the Send message form: "+ Button" / "+ Quick reply" rows (title, type, payload that follows the title until edited, or link), the rules in `public/js/chatbot-buttons.js`, Wait turned on by a postback or quick reply with a note, the Botcake warning (`data-text-only-pages`), Image switch removes buttons, `rowsEditor` removes only its own row. |
| `64d951dc` | T7 | the Test tab draws the buttons and quick replies; a click sends the title like a tap; links only for `https://`. |
| `ae219b3e` | final wave | tests for Bot 2, the stored-content replay (`chatbot:heal-send-confirmations` path) and quick replies off the last message. |

### How the owner turns the gate on

After the gateway's spec 049 is live on a gateway, set in the server's environment
`GATEWAY_BUTTONS=<route keys>` (comma list), then `php artisan config:cache`. The keys are the route as
recorded in `chatbot_replies.route`: `forbeast` (Bot 1's default address), a `GATEWAY_URLS` key such as
`incepxion`, and `incepxionbot` (Bot 2). Example: `GATEWAY_BUTTONS=forbeast,incepxion,incepxionbot`.
Empty (the default) = every page gets the text only, exactly as today. Turning it off again is the same
change back; nothing is stored that needs undoing.

## Checks (command and output)

How the tests were run: `vendor` in this worktree is a junction to the `fix-228` worktree, so Composer
loads `App\` from there and a plain `php artisan test` runs the other worktree's code (seen: the first red
run said `Call to undefined method FlowGraph::payloadFor()` even after the method was written). Every run
below uses a bootstrap kept outside the repo that loads this worktree's `App\`, `Database\` and `Tests\`
first: `php.bat artisan test --bootstrap=C:/Users/Forbeast/AppData/Local/Temp/likha229-bootstrap.php …`.
`vendor` was not touched. The baseline ran the same way in a throwaway worktree at `b2ca0dba` (vps2/main)
with its own bootstrap.

Every run shows "warnings" from `file_get_contents(...)`: the same warning appears on existing tests and on
vps2/main, so it is the environment, not this change.

### Red lines (watched before the code)

| Slice | Test | First failure line |
|---|---|---|
| 1 (save) | `test_S_229_1_1_three_buttons_come_back_…` | `Call to undefined method App\Services\Chatbot\FlowGraph::payloadFor()` (then, with the method, `Undefined array key "buttons"` in 1.2, 1.3, 1.6) |
| 1 (UTF-8, fix loop) | `test_S_229_1_6_b_a_title_with_invalid_utf8_…` | `Expected response status code [200] but received 500.` (`Malformed UTF-8 characters`) |
| 2 | `test_S_229_2_2_a_posted_graph_with_four_buttons_is_refused` (and 2.3, 2.4: `ErrorException`, `AssertionFailedError`) | `Call to undefined method App\Services\Chatbot\FlowGraph::payloadFor()` (the test's own helper; 2.3 failed with `AssertionFailedError`: no refusal) |
| 3 | `test_S_229_3_2_…_on_one_item_is_refused` | `Call to undefined method App\Services\Chatbot\FlowGraph::payloadFor()` |
| 4 | `test_S_229_4_5_a_tap_step_that_does_not_wait_publishes_with_a_warning` | failed in the first T2 run (`⨯ s 229 4 5 …`; the notice did not exist); its exact first line was not captured. The other slice 4 cases: see below. |
| 5 | `test_S_229_5_2_a_botcake_page_gets_the_text_alone_…` | `Undefined array key "left_out"` |
| 6 | `test_S_229_6_2_buttons_with_empty_text_block_publish_naming_the_step` | `Expected response status code [422] but received 200.` |
| 7 | `test_S_229_7_1_the_test_tab_shows_…` | `Failed asserting that '<!DOCTYPE html>…' contains "[...(m.buttons \|\| []), ...(m.quick_replies \|\| [])]"` |
| 8 | none: every case passes on today's code (characterisation, accepted by the reviewer) | — |
| 9 | `test_S_229_9_1_a_message_shown_to_the_ai_carries_the_titles` | `Failed asserting that two strings are identical. -'Order na po? [Yes \| No]…' +'Order na po?…'` |
| editor (T6) | all 11 in `ChatbotButtonsEditorTest` | `node: … Error: ENOENT: no such file or directory, open '…public\js\chatbot-buttons.js'` |

Honest note on T6: `public/js/chatbot-buttons.js` was written before its tests. To watch a real red, the
file was moved aside and the tests run (the ENOENT line above), then put back and run green. The rules
in it were not changed after that run, so the tests did not drive its first draft.

Passes on today's code (characterisation), per case, also run on vps2/main `b2ca0dba` with the same test
file copied in temporarily (12 passed, then the copy removed):
S-229.4.1, 4.2, 4.3, 4.4, 4.6, 4.7, 4.8 and S-229.8.1, 8.2, 8.3, 8.4, 8.5 (`ChatbotButtonsTapTest`). Also
already true before the change and pinned here: 5.4 (wait and skip on a Botcake page), 5.6 (a 4xx is not
retried), 9.2 and 9.4.

### Runs

| Run | Command (with the bootstrap above) | Output |
|---|---|---|
| Baseline, vps2/main `b2ca0dba` (throwaway worktree, before any code) | `php.bat artisan test --bootstrap=…/likha229-base-bootstrap.php` | `Tests: 2 failed, 4212 warnings, 206 passed (77015 assertions)` · `Duration: 816.02s` (4420 tests) |
| Final, this branch at `b2422baf` | `php.bat artisan test --bootstrap=…/likha229-bootstrap.php` | `Tests: 2 failed, 4284 warnings, 206 passed (77414 assertions)` · `Duration: 826.95s` (4492 tests: the 72 new ones, all passing) |
| Chatblast folder after T3 | `… tests/Feature/Chatblast` | `Tests: 1918 warnings, 17 passed (55725 assertions)` (no failure) |
| Publish/graph-related tests after T2 | 58 files that use `FlowGraph::clean`, publish or notices | `Tests: 708 warnings (15744 assertions)` (no failure) |
| Kit guard hooks | `node --test 'test/hooks/*.test.mjs'` | `tests 1075, pass 1026, fail 5`: the 5 are `EPERM … symlink` (this Windows account cannot create symlinks); the hook files are local to this clone and untouched |
| Lint | `php.bat -l` on every changed PHP file | `No syntax errors detected` for each |
| Inline scripts | `new Function(...)` over the `<script>` blocks of `editor.blade.php` and `test.blade.php` | all parse |

**Pre-existing failures** (red on vps2/main too, same message, not touched by this change):
`Tests\Feature\ExampleTest > the application returns a successful response` (`Expected response status code [200] but received 302.`) and
`Tests\Feature\Item\ItemTableFitScriptTest > every catalog column id has a minimum width and a place in the drop order` (`Failed asserting that actual size 57 matches expected size 45.`).

"Warnings" in every run are the environment's `file_get_contents(...)` warning, present on vps2/main too.

**Gate off proves nothing changes on the wire:** `test_with_gateway_buttons_empty_nothing_changes_on_the_wire_for_any_page` (Botcake, Bot 1 default address, Bot 1 `incepxion` address): an old graph goes out byte-for-byte as before and its record has no new key; a graph with buttons sends the text alone. Passes.

**Browser check:** skipped. A served app from this worktree would run the other worktree's code (the `vendor` junction), and there is no `node_modules` here. S-229.7.6 (390 x 844) is pinned on the markup only (wrapping classes); the real check at phone width is left for the reviewer's browser pass.

## Case table

| Case | Test | Result |
|---|---|---|
| S-229.1.1 | `test_S_229_1_1_the_editor_has_the_button_row_with_title_type_and_payload_or_link`, `test_S_229_1_1_three_buttons_come_back_with_title_type_and_payload_or_url_after_save_and_reload` | pass (final run) |
| S-229.1.2 | `test_S_229_1_2_the_row_editor_removes_only_its_own_row_and_not_a_nested_button`, `test_S_229_1_2_two_quick_replies_remain_in_order_after_edit_remove_save_and_reload` | pass (final run) |
| S-229.1.3 | `test_S_229_1_3_a_title_keeps_its_variable_and_the_variable_checker_sees_it_like_text` | pass (final run) |
| S-229.1.4 | `test_S_229_1_4_an_empty_title_blocks_publish_with_a_clear_message` | pass (final run) |
| S-229.1.5 | `test_S_229_1_5_a_link_that_is_not_https_blocks_publish` | pass (final run) |
| S-229.1.6 | `test_S_229_1_6_unknown_button_types_are_dropped_and_extra_keys_are_not_kept`, `test_S_229_1_6_b_a_title_with_invalid_utf8_posted_as_a_form_is_saved_cleaned_and_the_draft_survives` | pass (final run) |
| S-229.1.7 | `test_S_229_1_7_filipino_and_emoji_titles_are_unchanged_after_save_and_reload`, `test_S_229_1_7_filipino_and_emoji_titles_go_out_unchanged` | pass (final run) |
| S-229.1.8 | `test_S_229_1_8_an_image_item_offers_quick_replies_but_no_buttons` | pass (final run) |
| S-229.1.9 | `test_S_229_1_9_a_flow_saved_before_this_change_saves_unchanged_with_no_new_keys` | pass (final run) |
| S-229.1.10 | `test_S_229_1_10_a_link_that_is_not_https_is_never_sent`, `test_S_229_1_10_the_test_tab_never_links_to_a_url_that_is_not_https` | pass (final run) |
| S-229.2.1 | `test_S_229_2_1_three_buttons_are_accepted` | pass (final run) |
| S-229.2.2 | `test_S_229_2_2_and_2_3_add_is_disabled_at_3_buttons_and_at_13_quick_replies`, `test_S_229_2_2_a_posted_graph_with_four_buttons_is_refused` | pass (final run) |
| S-229.2.3 | `test_S_229_2_3_a_posted_graph_with_fourteen_quick_replies_is_refused_and_thirteen_is_accepted`, `test_S_229_2_2_and_2_3_add_is_disabled_at_3_buttons_and_at_13_quick_replies` | pass (final run) |
| S-229.2.4 | `test_S_229_2_4_the_title_stops_at_20_characters_counting_an_emoji_as_one`, `test_S_229_2_4_a_21_character_title_is_refused_and_exactly_20_is_accepted` | pass (final run) |
| S-229.2.5 | `test_S_229_2_5_a_title_that_gets_long_after_filling_is_cut_to_20_and_the_cut_is_noted` | pass (final run) |
| S-229.2.6 | `test_S_229_2_6_button_text_over_640_characters_is_refused_and_without_buttons_it_is_not` | pass (final run) |
| S-229.2.7 | `test_S_229_2_7_text_and_titles_that_break_the_limits_only_after_filling_still_make_a_valid_message` | pass (final run) |
| S-229.3.1 | `test_S_229_3_1_and_3_4_buttons_and_quick_replies_exclude_each_other_until_removed` | pass (final run) |
| S-229.3.2 | `test_S_229_3_2_a_posted_graph_with_buttons_and_quick_replies_on_one_item_is_refused` | pass (final run) |
| S-229.3.3 | `test_S_229_3_3_quick_replies_are_offered_only_on_the_last_message_and_flagged_elsewhere`, `test_S_229_3_3_quick_replies_on_a_message_that_is_not_the_last_block_publish`, `test_S_229_3_3_b_quick_replies_on_a_message_that_is_not_the_last_are_not_sent` | pass (final run) |
| S-229.3.4 | `test_S_229_3_1_and_3_4_buttons_and_quick_replies_exclude_each_other_until_removed` | pass (final run) |
| S-229.4.1 | `test_S_229_4_1_a_postback_tap_leaves_by_reply_and_saves_the_title` | pass (final run) |
| S-229.4.2 | `test_S_229_4_2_a_quick_reply_tap_leaves_by_reply_and_saves_the_title` | pass (final run) |
| S-229.4.3 | `test_S_229_4_3_a_condition_on_the_saved_field_takes_the_right_branch` | pass (final run) |
| S-229.4.4 | `test_S_229_4_4_a_link_button_sends_no_event_and_the_step_keeps_waiting` | pass (final run) |
| S-229.4.5 | `test_S_229_4_5_a_postback_or_quick_reply_turns_wait_on_and_a_link_alone_does_not`, `test_S_229_4_5_a_tap_step_that_does_not_wait_publishes_with_a_warning` | pass (final run) |
| S-229.4.6 | `test_S_229_4_6_typing_instead_of_tapping_leaves_by_reply_with_the_typed_text` | pass (final run) |
| S-229.4.7 | `test_S_229_4_7_the_same_tap_delivered_twice_moves_the_flow_once` | pass (final run) |
| S-229.4.8 | `test_S_229_4_8_a_postback_whose_title_and_payload_differ_saves_the_title_and_the_inbox_shows_the_title` | pass (final run) |
| S-229.5.1 | `test_S_229_5_1_a_gateway_route_on_the_list_gets_the_049_shape_and_nothing_else_new`, `test_S_229_5_1_b_bot_2_on_the_list_gets_the_049_shape_and_its_record_has_no_left_out`, `test_S_229_5_1_c_a_replay_of_stored_content_never_carries_our_marks` | pass (final run) |
| S-229.5.2 | `test_S_229_5_2_the_editor_warns_that_botcake_pages_get_the_text_only`, `test_S_229_5_2_a_botcake_page_gets_the_text_alone_and_the_reply_records_the_buttons_were_left_out` | pass (final run) |
| S-229.5.3 | `test_S_229_5_3_a_gateway_route_not_on_the_list_is_treated_like_botcake` | pass (final run) |
| S-229.5.4 | `test_S_229_5_4_a_botcake_page_and_a_waiting_postback_step_leave_by_reply_when_typed_and_the_skip_timer_is_set` | pass (final run) |
| S-229.5.5 | `test_S_229_5_5_the_provider_is_read_at_send_time` | pass (final run) |
| S-229.5.6 | `test_S_229_5_6_a_gateway_4xx_is_recorded_failed_with_the_error_and_not_retried` | pass (final run) |
| S-229.5.7 | `test_S_229_5_7_the_buttons_survive_the_send_pool_and_the_sequence_pool` | pass (final run) |
| S-229.6.1 | `test_S_229_6_1_a_valid_button_message_on_a_gateway_only_flow_publishes_with_no_notice` | pass (final run) |
| S-229.6.2 | `test_S_229_6_2_buttons_with_empty_text_block_publish_naming_the_step` | pass (final run) |
| S-229.6.3 | `test_S_229_6_3_a_postback_or_quick_reply_with_an_empty_payload_blocks_publish` | pass (final run) |
| S-229.6.4 | `test_S_229_6_4_duplicate_payloads_or_titles_in_one_message_block_publish` | pass (final run) |
| S-229.6.5 | `test_S_229_6_5_a_draft_with_a_problem_saves_and_only_publish_blocks` | pass (final run) |
| S-229.6.6 | `test_S_229_6_6_publish_lists_the_pages_that_get_the_text_only_as_a_notice` | pass (final run) |
| S-229.7.1 | `test_S_229_7_1_the_test_tab_shows_the_text_and_the_buttons_with_variables_filled` | pass (final run) |
| S-229.7.2 | `test_S_229_7_2_clicking_a_shown_button_continues_the_flow_as_a_real_tap` | pass (final run) |
| S-229.7.3 | `test_S_229_7_3_a_real_send_records_the_buttons_with_filled_titles_via_and_route` | pass (final run) |
| S-229.7.4 | `test_S_229_7_4_a_test_run_sends_nothing_to_messenger` | pass (final run) |
| S-229.7.5 | `test_S_229_7_5_the_inbox_shows_a_sent_button_message_without_breaking_on_the_new_keys` | pass (final run) |
| S-229.7.6 | `test_S_229_7_6_the_button_rows_wrap_on_a_phone` | pass (final run) |
| S-229.8.1 | `test_S_229_8_1_a_tap_while_the_wait_is_open_under_the_23_hour_cap_leaves_by_reply` | pass (final run) |
| S-229.8.2 | `test_S_229_8_2_a_tap_after_the_wait_skipped_is_a_new_message_with_no_second_exit` | pass (final run) |
| S-229.8.3 | `test_S_229_8_3_a_tap_for_a_step_that_was_deleted_starts_over_with_no_error_and_no_stuck_wait` | pass (final run) |
| S-229.8.4 | `test_S_229_8_4_a_tap_while_another_run_holds_the_lock_is_kept_and_runs_after` | pass (final run) |
| S-229.8.5 | `test_S_229_8_5_a_tap_at_the_moment_the_wait_skips_exactly_one_wins` | pass (final run) |
| S-229.9.1 | `test_S_229_9_1_a_message_shown_to_the_ai_carries_the_titles` | pass (final run) |
| S-229.9.2 | `test_S_229_9_2_a_message_not_shown_to_the_ai_stays_hidden` | pass (final run) |
| S-229.9.3 | `test_S_229_9_3_ai_and_ai_text_are_stripped_before_the_send` | pass (final run) |
| S-229.9.4 | `test_S_229_9_4_after_a_tap_the_ai_sees_the_title_as_the_customers_turn` | pass (final run) |

## Rulings

- Ruling: internally a message keeps `type: text|image` with `buttons` / `quick_replies` beside it; only the wire turns a text with buttons into `type: buttons` — every reader of `chatbot_replies.content` (Inbox, Live, echo hash, Test tab, AI history) keeps working unchanged — cost if wrong: a reader that wants the 049 shape would have to convert, a small change.
- Ruling: the gate is applied in `BotcakeOutbox::wire()` from the route `GatewayProvider::request` passes; every Botcake path passes no route — one place covers the outbox, both pools, the Inbox manual send and the heal command — cost if wrong: a new send path that bypasses `wire()` would send without the gate (none exists today; the T3 review checked every path).
- Ruling: `left_out` is noted per message in `content`, decided in `record()` from `$via` and the `$route` argument — every path records through `record()` — cost if wrong: a row could say left out when the buttons went out (only for a gateway not in `Gateways::ALL`, none today).
- Ruling: an empty title, a non-https link and quick replies off the last message block Publish, not the draft save (reviewer's decision) — one refused item blocks every later draft save — cost if wrong: none for customers; the sender also drops these.
- Ruling: save refusals are only states the editor cannot reach (the form caps titles at 20 code points, caps text with buttons at 640, disables "+ Button" at 3 or over 640 and "+ Quick reply" at 13 or off the last message, and an Image switch removes buttons) — cost if wrong: a crafted save API call gets a 422, which is the point.
- Ruling: titles count code points (PHP `mb_strlen`, JS `Array.from`), not UTF-16 units — the spec says "counted in characters" — cost if wrong: if spec 049 counts UTF-16 units, a title with emoji near 20 could be refused by the gateway (a failed send, recorded with the error). Proposed task 3.
- Ruling: payloads are literal (variables are not filled in a payload); the default payload is the title upper-cased with each run of non-letters/digits as `_`, trimmed, `BUTTON` when nothing is left (`👍`) — stable and readable — cost if wrong: two emoji-only titles get the same default payload and Publish blocks until staff edit one.
- Ruling: a web_url must be a literal `https://` URL (no variables) — the "same check style as Image link" plus the https rule — cost if wrong: staff cannot link per-customer pages yet.
- Ruling: after filling variables the sender makes every message valid (cut titles to 20, cut text with buttons to 640, drop empty or repeated titles, repeated payloads, non-https links, quick replies off the last message) instead of failing the send — a waiting step re-sends a failed question on every customer message, so a message the gateway refuses would loop — cost if wrong: a cut or dropped button, noted in the record.
- Ruling: the 640 cut applies to a text with buttons on every page, also Botcake pages that get the text alone — the cut happens when the message is built, before the provider is known — cost if wrong: a Botcake page loses text past 640 on a button message whose variables make it that long (staff templates are already capped at 640). In `TODO.md`.
- Ruling: Wait is turned on in the editor's form copy when a postback or quick reply is added; Done applies it, and the step's `continue` wire is removed as for any Wait (the note says so) — the editor's existing port rule — cost if wrong: staff rewire one exit.
- Ruling: `ButtonPages` walks main flow, ad-group flow and sequence steps itself instead of reusing `ChatbotPageCheck`'s walk, which is private and tied to its cache prints — cost if wrong: two walks to keep in step. Sequences subscribed from the Inbox or the address question, and Botcake-source chats answered through Botcake on a gateway page, are not listed (the notice is advisory). In `TODO.md`.
- Ruling: the AI sees the titles only for a message it may see (`ai: true` or an old row) and not when `left_out` — the customer never saw them — cost if wrong: the AI misses a choice that was shown.
- Ruling: the tests run with a bootstrap outside the repo because of the `vendor` junction — never deleting or reinstalling `vendor` — cost if wrong: none for the code; a plain `php artisan test` in this worktree tests the other worktree's code.
- Ruling: `npm run build` not run — this worktree has no `node_modules` (installing would be a download), and no Vite source changed (`chatbot-buttons.js` is a plain file under `public/js`, loaded with `asset()` and a hash, like `chatbot-paste.js`) — cost if wrong: none expected.
- Ruling: the kit's developer agents are not installed here, so the main session built every task; `skeptic-reviewer` reviewed T1+T2 and T3 at adversarial depth and T4–T7 at standard depth, after the code instead of per task where tasks were small — cost if wrong: findings came one task later.

## Story changes

- **Added S-229.1.10** (edge): a non-https web_url (e.g. `javascript:`) in a draft posted to the save API → the sender drops it and the Test tab draws no link. Why: the Test tab runs drafts, which never passed Publish (spec review).
- **Added S-229.2.7** (edge): text or titles that break Meta's limits only after variables are filled → cut or dropped at send. Why: a refused message on a waiting step would be re-sent forever (spec review).
- **S-229.1.4, 1.5 and 3.3 refuse at Publish, not on save** (reviewer's decision): the draft keeps a half-made button; the editor shows the problem on the row.
- **S-229.6.2 widened**: quick replies on an empty message (or an image without a link) also block Publish, because that message is never sent (T1+T2 review).
- **Slices 4 and 8**: characterisation cases, "passes on today's code" per case instead of a red line (reviewer's decision).
- Extra tests beyond one per case, each for a review finding: `1_6_b` (broken UTF-8), `3_3_b` (quick replies off the last message at send), `5_1_b` (Bot 2), `5_1_c` (stored-content replay).

## Open questions

None open. The three from the plan were answered by the reviewer: (1) characterisation per case, (2) those
three checks block Publish, (3) the Health echo counts are a follow-up (Proposed tasks).

## Proposed tasks

1. **Health's echo-based counts once the gate is on.** `ChatEchoes` and the Inbox match our reply to Meta's
   echo by the text hash (`Inbox.php` around line 274). A button message's echo may arrive as a template
   attachment without our text. What breaks once a route is on `GATEWAY_BUTTONS`: on /chatbot/health "Not
   answered" can count a customer the bot did answer with a button message, "Double reply" can pair the
   echo with another app, and the Inbox can show a second "[Template]" bubble beside our reply. Needs the
   gateway's echo shape for buttons (spec 049) first.
2. **A port per button / conditions on the payload** (out of scope here): today a Condition on the saved
   title is the way to branch.
3. **Confirm how spec 049 counts 20 characters** (code points or UTF-16 units). If UTF-16, cut titles by
   UTF-16 units in `FlowEngine::taps` and the editor.
4. **`ButtonPages` and `ChatbotPageCheck`** could share one "pages that reach a flow" walk (including
   sequences subscribed from the Inbox and the address question).

## Left on this machine

- `.claude/worktrees/base-229` (detached at `b2ca0dba`, the baseline run): its `vendor` is a junction to the
  `fix-228` worktree's `vendor`. Remove the junction first (`rmdir vendor` in cmd, which removes only the
  link), then `git worktree remove .claude/worktrees/base-229`; removing the folder with the junction still in
  it could delete `fix-228`'s `vendor`.
- The two test bootstraps in the user's Temp folder (`likha229-bootstrap.php`, `likha229-base-bootstrap.php`),
  outside the repo.
