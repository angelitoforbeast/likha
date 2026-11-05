# Result 237: an exit for each button and quick reply

## Status

Done, not pushed, not deployed. No migration. Branch `feat/237-button-ports` from `b2b355ea`. T0–T4b built after the reviewer's "go"; one fix loop (`0676497e`); no blocker or major open; accepted minors in `TODO.md`. Merge danger and live waiting customers: see Summary.

## Plan

### What the code adds up to (read before the plan)

- `FlowGraph::ports()` gives a waiting send step `reply` (+ `skipped`). `FlowGraph::clean()` checks every wire
  against `ports()` of the **cleaned** node, so a wire to a port the cleaned config does not produce is refused
  with a 422. That rule already gives S-237.1.5, and it also covers S-237.1.4 on the server, once ports come from
  the button ids.
- `cleanTaps` keeps only `title`, `type`, `payload`/`url` on each button and quick reply. The id has to be added to
  that allow-list. Without it every save would drop the id, and with it the wire.
- `FlowEngine::taps()` builds the sent list fresh (`title`, `type`, `payload`/`url`, `cut`). An `id` in the graph
  never reaches the wire, `chatbot_replies.content` or the AI history unless it is added there, and it won't be.
- A tap reaches the engine only as `FlowRun::$input` (the title). The payload is on the stored chat
  (`payload.postback` from `HandleGatewayEvent`, `payload.quick_reply` for a quick reply). Both are trimmed and
  cut to 1000 on arrival (`HandleGatewayEvent::fields`). A Botcake chat's `payload` holds whatever extra params
  the page's Botcake webhook sends (`BotcakeChatController`, up to 50 keys), so a param that happens to be named
  `postback` would land there too. Only a chat whose `payload.source` is `gateway` is a real tap. `ChatbotRunner::run`
  builds one `FlowRun` per pending chat from `$c`, so it can hand the run that chat's payload.
- The editor autosaves the whole graph. `clean()` is all or nothing, so one wire the server refuses blocks every
  later save of the flow. The editor must never hold a port the server won't produce.
- `FlowEngine::resumePoint()` is the one place a waiting step resumes. It saves `$run->input` into `save_to` and
  returns `$edges[node:reply]`. Skip and the "question not delivered" re-ask go out before that line and stay
  unchanged.
- The editor: `ports(n)` in `editor.blade.php` mirrors `FlowGraph::ports`. Node cards draw one `.nd-p` row per
  port with `portLabel(n, p)`. "Done" (`form-apply`) already drops every wire whose port is gone. The outline
  sorts wires by `ports(n)` order. Both new ports and deleted ones come for free once `ports(n)` knows the ids.
- The Test tab's tap button calls `say(t.title)`, which posts only `message` to `testSend`. The sent message in
  the test result already carries `payload`, so the click can send it along.

### Design

**The id** (decisions 2, 7). Every button (postback **and** web_url) and every quick reply may carry `id`, a
string matching `/^[a-z0-9]{6,16}$/`. A web_url keeps its id but has no port, so switching a Link to Postback
(or back) keeps the id and brings the port back with its wire. The rule lives in one place per side:
`FlowGraph::TAP_ID` and `ChatbotButtons.ID`, pinned equal by a test. The editor makes ids with
`ChatbotButtons.newId()`: exactly 8 characters, each picked from `[0-9a-z]` with `crypto.getRandomValues`
(never `toString(36)` of a number, which can come out shorter). `ChatbotButtons.withIds(graph)` gives a new id
to every button and quick reply whose id is missing or fails `ID`, and a new one to the second of two equal ids
in one step. It runs:
- when a button or quick reply is added;
- on every graph that enters the editor through the initial load or `replaceGraph` (Restore, Discard), before
  the first undo snapshot is taken, so Undo never goes back past the ids.

Loading alone does not mark the draft dirty. The flow's next save stores the ids (decision 7). `cleanTaps` keeps
an `id` only when it is a string matching `TAP_ID`, and drops anything else (that entry then has no port).
Refused on save (422): the same id twice in one step. After `withIds` the editor can't reach that state, and
Duplicate step copies ids onto a new node, where ports are per node. The **server never makes ids**: an old
graph, or one posted without ids, cleans byte for byte the same as today (S-237.3.3), and the ids the editor
shows are the ones it saves.

**Ports** (decision 1, 9). Port name `tap_<id>`. `FlowGraph::ports()` for a waiting send step returns `reply`,
then one `tap_<id>` per postback button and quick reply that has an id, in item order and in form order within
the item (buttons, then quick replies), then `skipped` when a skip time is set. A port comes from the id and the
type alone (postback or quick reply), never from the title or payload, on both sides, so an empty title still has
its port. A step that does not wait keeps
`continue` only. Buttons there have no port, and Publish already warns about that (spec 229). The JS mirror goes
into `chatbot-buttons.js` (`ChatbotButtons.tapPorts(items)` → `[{port, title}]`) so node tests cover it, and the
editor's `ports(n)` and `portLabel(n, p)` call it. The label is the title as typed, `(no title)` when empty.
The existing `truncate` class keeps long labels inside the 220 px card.

**Wires** (decisions 2, 5). Renaming or reordering keeps the id, so the port and its wire stay. Deleting a button
removes its id, so "Done" drops the wire (the existing `keep` filter), and a posted graph that still has it is
refused by `clean()` (S-237.1.4, S-237.1.5). One wire per port stays as it is.

**The tap** (decisions 3, 4, 8). `FlowRun` gets `public ?string $tap = null`, the tap's payload.
`ChatbotRunner::run` sets it only for a gateway chat (`payload.source === 'gateway'`, the value `HandleGatewayEvent`
writes): `payload.postback` when it is a non-empty string, else `payload.quick_reply` when it is one. A Botcake
chat never gets a tap, whatever its params are called. `ChatbotRunner::skip` leaves it null. In `resumePoint`,
after the existing `save_to` (unchanged: the field still gets the title, from `$run->input`), the engine looks
for the first postback button or quick reply of the waiting step, in port order, whose graph payload, normalised
the way the gateway normalises the tap (`mb_substr(trim(...), 0, 1000)`), equals `$run->tap` (strict compare,
non-empty), and which has an id. The Test tab's `str()` trims the same way. When that `tap_<id>` has a wire, the path
gets `port: tap_<id>, note: Tapped "<title>"` and the run goes there. Otherwise it is `reply`, as today, with
the existing note. So: a typed text, an unknown payload, a button with no id or no wire, and a Botcake chat all
go to `reply`. A tap after the wait ended has no `__wait`, so `resumePoint` returns false (Start), as today.
Payloads are literal (a spec 229 ruling: no variables in a payload), so "as filled at send time" is the graph
payload. The match is on the payload alone, so a tap on an older message from another step with the same
payload follows the waiting step's port (decision 4: matched by payload).

**Publish** (decision 5). `publishNotice` adds, never blocking, and only for waiting steps:
- `Send message step n3 has button or quick reply exits with no wire (Order na, Magtanong): a tap there leaves
  by "reply".`
- `Send message step n3 has two buttons or quick replies with the same payload on different messages (Oo): a
  tap always takes the first one's exit.` Spec 229 already blocks duplicates inside one message; across
  messages of one step this is a notice.

**Test tab** (decision 6). `taps(m)` → `say(t.title, t.payload)`. The request gains `tap`
(`$this->str($request, 'tap', 1000)`, empty → null), and `testSend` sets `$run->tap`. A typed message sends no
`tap`. Test runs still use `TestOutbox`.

**Not changed.** `HandleGatewayEvent`, `ChatbotInbound`, the dedupe (`mid`/`event_id`, `LAST_CHAT`: S-237.2.7 is
a characterisation test), skip, the sender, the wire shape, `chatbot_replies`, the AI history and the Inbox.

**Live waiting customers at deploy.** Nothing changes for them until staff save and publish a flow with ids and
tap wires. Published graphs have no ids yet, so a waiting customer's tap goes to `reply`, as today. A customer
who is still waiting when the new graph is published resumes on the new graph (as for any republish). Their tap
on the message sent earlier carries the same graph payload, so it follows the new tap wire. A step deleted or
turned off Wait goes to Start, as today.

### Tasks (in order; one commit each, test and code together; none parallel-safe: T1 and T2 share FlowGraph tests, T3 and T4b share chatbot-buttons.js and the blade layer)

| # | Task | Side | Tier | Cases | Review |
|---|---|---|---|---|---|
| T0 | Stories: slice 237 into `qa/stories.md` (the spec's stories, as given) | docs | low | — | lint only |
| T1 | Graph: `id` in `cleanTaps` (string matching `TAP_ID`, any button or quick reply, a duplicate in a step refused), `tap_<id>` in `ports()` for postback and quick reply only, the wire check, old graphs unchanged, both Publish notices | backend | high (save API) | 1.1, 1.2, 1.3 (save: rename, and a reordered posted graph), 1.4 (save), 1.5, 3.1, 3.3 (save) | skeptic adversarial |
| T2 | Engine: `FlowRun::$tap` (gateway chats only), `ChatbotRunner` sets it from the chat payload, `resumePoint` follows the matched wire (trimmed compare) or falls back to `reply`; ids stay off the wire | backend | high (tap payloads, live flows) | 2.1–2.7, 3.3 (tap), plus the new cases below | skeptic adversarial |
| T3 | Editor: `ChatbotButtons.ID` / `newId` / `withIds` / `tapPorts`, ids on add, load, Restore and Discard (before the first snapshot), the id kept across a type switch, `ports(n)`/`portLabel` with titles, wires kept on rename and dropped on delete | frontend | medium (UI) | 1.1–1.4 (editor; 1.3 as "remove button 1 of 3: 2 and 3 keep their wires", since the editor has no reorder control), 3.4 (markup) | skeptic standard |
| T4a | Test tab server: `tap` on `testSend` | backend | medium | 3.2 (server) | skeptic standard, with T4b |
| T4b | Test tab page: the click sends the title and the payload | frontend | medium | 3.2 (page) | skeptic standard, with T4a |

Tests: `tests/Feature/Chatblast/ChatbotButtonPortsTest.php` (T1, T2, T4a) and
`ChatbotButtonPortsEditorTest.php` (T3, T4b: rendered HTML plus the node script tests through `tests/js/call.cjs`,
like `ChatbotButtonsEditorTest.php`). One test per case, named `test_S_237_x_y_…`. Where a case has a server and
a page part (1.1–1.4, 3.2), the second test carries the same ID plus `_editor` or `_page`.

New cases from the spec review. They go into slice 237 in `qa/stories.md` and are listed under Story changes
once the plan has its "go":
- **S-237.2.8** negative: a Botcake chat whose params include `postback` (or `quick_reply`) matching a button →
  `reply`.
- **S-237.2.9** edge: a graph payload with a leading or trailing space → the (trimmed) tap still follows its
  port.
- **S-237.2.10** edge: a send from a step whose buttons have ids → the gateway body and `chatbot_replies.content`
  are byte-identical to the same graph without ids.
- **S-237.1.6** edge: switching a button Link → Postback keeps its id, so its port and wire come back; every
  generated id matches the server rule.
- **S-237.3.5** edge: two messages of one waiting step share a payload → a Publish notice, and a tap takes the
  first exit.

Characterisation: S-237.2.3, 2.6, 2.7 and 3.3 (tap part) pass on today's code, since typing, a late tap,
dedupe and an old flow already go to `reply` or Start. Their red line will say so instead of showing a failure.

S-237.3.4 (no sideways scroll at 390 × 844) can't be proved on rendered HTML. The test pins the classes (the port
rows `truncate`, the form rows `flex-wrap min-w-0`). The real check is the browser check, once after T4b, if
`browser-checker` can reach a running app. If it can't, the result says the case is proved on markup only.

This session has no `backend-developer` / `frontend-developer` agents, so the main session builds each task red
first, as in spec 229. `skeptic-reviewer` reviews per the table above. After the last task: the chatbot tests,
then the full suite once, compared with the pre-existing failures the spec names. `npm run build` is not
needed: `chatbot-buttons.js` is a plain file under `public/js`, loaded with `asset()`. No push, no deploy.

### Merge danger (planned; confirmed in the Summary)

A two-way door with catches once staff have drawn tap wires. Blast radius before anyone adds tap wires: none on
the wire, since ids never leave the graph. After: only taps on waiting steps that have wired button exits.

If the commits are reverted after tap wires exist:
- **Draft save, Publish and History → Restore** of a graph with tap wires are refused by the old `clean()`
  ("Node n3 has no port tap_…"). Fix: open the step and press Done, which drops wires to ports that are gone,
  or delete those wires.
- **Live flows** keep running: the old engine ignores the unknown edges. But a tap goes to `reply`, which may be
  unwired if staff replaced the Condition step with tap wires. Those customers then get no answer until the
  flow is rewired.
- Emptying `GATEWAY_BUTTONS` stops new button sends at once. It doesn't help customers already waiting on a
  sent button.

## Summary

What shipped, per task (one commit each, test and code together):

| Task | Commit | What |
|---|---|---|
| T0 | `7b38668f` | Slice 237 in `qa/stories.md`: the spec's 16 cases plus the 5 accepted from the spec review. |
| T1 | `80bcbe41` | `FlowGraph`: `TAP_ID`, `tapPorts()`, `tap_<id>` ports in `ports()` (between `reply` and `skipped`, only while waiting), `cleanTaps` keeps a well-formed `id` on every button and quick reply, a repeated id in a step is refused (422), `tapPayload()`, and two Publish notices. |
| T2 | `d42d4efb` | `FlowRun::$tap`; `ChatbotRunner::tap()` reads it from a gateway chat only (`payload.source === 'gateway'`, postback, else quick reply); `FlowEngine::resumePoint` follows the first matching tap port when it is wired, else `reply`. `save_to` still gets the title. |
| T3 | `77a494e3` | `chatbot-buttons.js`: `idRule`, `newId` (always 8 of `[a-z0-9]`), `withIds`, `tapPorts`. The editor: `ports(n)` and `portLabel` draw a port per button with its title, new buttons and quick replies get an id, and `withIds` runs on load (before the first undo snapshot) and in `replaceGraph` (Restore, Discard). |
| fix loop 1 | `0676497e` | `TAP_ID` gets `/D` (a trailing newline was accepted by PHP's `$`); id length bounds and a link/postback repeated id tested; the tap half of S-237.3.5 pinned; the same-payload notice reworded (see Rulings). |
| T4a | `a1226768` | `ChatbotTabsController::testSend` reads `tap` (trimmed, up to 1000) and sets `$run->tap`. |
| T4b | `2514606d` | The Test tab's button click sends `say(t.title, t.payload)`; a typed message sends no `tap`. |

Not changed: `HandleGatewayEvent`, `ChatbotInbound`, the sender (`FlowEngine::taps()` builds each sent button fresh, so ids never reach the gateway or `chatbot_replies`, pinned by S-237.2.10), skip, the Inbox, the AI history. No migration. `npm run build` not needed: `chatbot-buttons.js` is a plain file under `public/js` loaded with `asset()` and a content hash.

### Merge danger

A two-way door until staff draw tap wires; after that, reverting has the costs below.

- **Blast radius after merge and before any flow is resaved:** none. Published graphs have no ids, so every port, tap and send behaves as today (S-237.3.3, both halves).
- **After staff save and publish tap wires:** only taps from gateway chats on waiting steps with wired button exits change route. Typed replies, Botcake pages, unwired buttons and unknown payloads still go to `reply`.
- **Revert after tap wires exist:**
  - The old `clean()` refuses draft saves, Publish and History → Restore of a graph with tap wires ("Node n3 has no port tap_…"). Fix: open the step and press Done (it drops wires to ports that are gone), or delete those wires.
  - Live flows keep running (the old engine ignores unknown edges), but taps go to `reply`, which may be unwired if a Condition step was replaced by tap wires; those customers get no answer until the flow is rewired.
  - Emptying `GATEWAY_BUTTONS` stops new button sends at once, but not taps on buttons already sent.

### Live waiting customers at deploy time

Nothing changes for them at deploy. Their waiting step is in a published graph without ids, so a tap goes to `reply`, as today. If staff later publish the same step with ids and tap wires while a customer is still waiting, that customer resumes on the new graph (as for any republish). Their tap on the message sent earlier carries the same graph payload, so it follows the new wire. A step that was deleted or had Wait turned off sends the next message to Start, as today.

## Checks

All runs use `"/c/Users/Forbeast/.config/herd/bin/php.bat" vendor/bin/phpunit`. `php artisan test` prints a `file_get_contents` warning on every test in this worktree, old and new alike; plain phpunit runs them clean, so the warning comes from the artisan printer, not the code.

- `php -l` on every changed PHP file: no syntax errors. `node --check public/js/chatbot-buttons.js`: ok.
- New test files: `ChatbotButtonPortsTest` 9 tests, `ChatbotButtonPortsTapTest` 12, `ChatbotButtonPortsEditorTest` 6, `ChatbotButtonPortsTestTabTest` 2: all pass.
- `tests/Feature/Chatblast` after T3: `OK, but there were issues! Tests: 2076, Assertions: 56359, PHPUnit Deprecations: 53, Skipped: 2.`
- Full suite, once, on `2514606d`: `FAILURES! Tests: 4650, Assertions: 78006, Failures: 2, PHPUnit Deprecations: 95, Skipped: 6.` The two red tests are the pre-existing ones the spec names: `TestsFeaturexampleTest::test_the_application_returns_a_successful_response` and `TestsFeatureItemItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`. No new failures; the flaky BigBroadcastTest and AstraAddressRulesTest S-26.18 passed this run.

### Red lines (watched before the code)

| Slice | Test | First failure line |
|---|---|---|
| T1 | `test_S_237_1_1_…` | `Failed asserting that two arrays are identical.` (no `tap_` ports) |
| T1 | `test_S_237_1_2_…` | `Failed asserting that two arrays are identical.` |
| T1 | `test_S_237_1_3_…`, `1_4`, `3_1` | `Expected response status code [200] but received 422.` (the wire to `tap_ordr0001` refused) |
| T1 | `test_S_237_1_6_…` | `Undefined array key "id"` (the id was dropped) |
| T1 | `test_S_237_3_5_…` (notice) | `Node n2 has no port "tap_oo000001".` |
| T2 | `test_S_237_2_1_…`, `2_7`, `2_9` | `Failed asserting that two arrays are identical. -    1 => 'n4', +    1 => 'n5',` (the tap went to `reply`) |
| T2 | `test_S_237_2_2_…` | `-    1 => 'n7', +    1 => 'n5',` |
| T3 | `test_S_237_1_1_editor_…`, `1_2`, `1_3`, `1_4` | `tapPorts: no function: tapPorts` |
| T3 | `test_S_237_1_6_editor_…` | `idRule: no function: idRule` |
| T4a | `test_S_237_3_2_a_test_tab_tap_…` | `Failed asserting that an array contains Array &0 [` (the path had `reply`) |
| T4b | `test_S_237_3_2_page_…` | `Failed asserting that '<!DOCTYPE html>…` contains the click with the payload |
| loop 1 | `test_S_237_1_5_…` | `expected a refusal: an id with a trailing newline` |
| loop 1 | `test_S_237_1_6_editor_…` | `Failed asserting that two strings are identical.` (PHP `/D` vs the JS rule) |
| loop 1 | `test_S_237_3_5_…` (notice) | the old "on different messages" wording |

**Passes on today's code** (recorded as the reviewer asked, instead of a red run): `test_S_237_1_5_a_wire_to_a_port_that_does_not_exist_is_refused` (first version; the newline row was red in loop 1), `test_S_237_2_3_typing_instead_of_tapping_leaves_by_reply`, `test_S_237_2_4_tapping_a_button_whose_port_has_no_wire_leaves_by_reply`, `test_S_237_2_5_a_tap_with_an_unknown_payload_leaves_by_reply`, `test_S_237_2_6_a_tap_after_the_wait_ended_is_a_new_message`, `test_S_237_2_8_a_botcake_chat_whose_params_look_like_a_tap_leaves_by_reply`, `test_S_237_2_10_ids_never_reach_the_gateway_or_the_reply_record`, `test_S_237_3_3_a_flow_saved_before_this_change_cleans_unchanged_with_no_new_ports`, `test_S_237_3_3_a_flow_saved_before_this_change_sends_taps_to_reply`, `test_S_237_3_4_the_port_rows_and_the_button_rows_fit_a_phone`, `test_S_237_3_5_a_payload_shared_by_two_messages_takes_the_first_exit_or_reply_when_only_the_second_is_wired`. These are the "nothing changes" cases: today every tap goes to `reply`. They still guard the new code: the T2 reviewer confirmed that removing the `source` guard makes 2.8 fail, and 2.10 fails if an id is ever copied into the sent button.

### Case table

| Case | Test | Result |
|---|---|---|
| S-237.1.1 | `test_S_237_1_1_postbacks_get_a_port_each_between_reply_and_skipped_and_the_link_gets_none`, `test_S_237_1_1_editor_the_node_draws_a_port_per_postback_labelled_with_its_title_and_none_for_the_link` | pass |
| S-237.1.2 | `test_S_237_1_2_three_quick_replies_get_three_ports_in_order`, `test_S_237_1_2_editor_three_quick_replies_draw_three_ports_in_order` | pass |
| S-237.1.3 | `test_S_237_1_3_renaming_or_reordering_buttons_keeps_their_wires_after_save_and_reload`, `test_S_237_1_3_editor_renaming_or_removing_the_first_of_three_keeps_the_other_ports` | pass |
| S-237.1.4 | `test_S_237_1_4_a_deleted_button_has_no_port_and_a_wire_left_to_it_is_refused`, `test_S_237_1_4_editor_a_removed_button_has_no_port_and_done_drops_its_wire` | pass |
| S-237.1.5 | `test_S_237_1_5_a_wire_to_a_port_that_does_not_exist_is_refused` | pass |
| S-237.1.6 | `test_S_237_1_6_a_link_keeps_its_id_so_switching_to_postback_brings_the_port_back_and_ids_repeat_nowhere_in_a_step`, `test_S_237_1_6_editor_ids_follow_the_server_rule_are_made_on_add_load_restore_and_discard_and_survive_a_type_switch` | pass |
| S-237.2.1 | `test_S_237_2_1_tapping_a_wired_button_continues_at_its_step_and_saves_the_title` | pass |
| S-237.2.2 | `test_S_237_2_2_tapping_a_wired_quick_reply_continues_at_its_step_and_saves_the_title` | pass |
| S-237.2.3 | `test_S_237_2_3_typing_instead_of_tapping_leaves_by_reply` | pass (passes on today's code) |
| S-237.2.4 | `test_S_237_2_4_tapping_a_button_whose_port_has_no_wire_leaves_by_reply` | pass (passes on today's code) |
| S-237.2.5 | `test_S_237_2_5_a_tap_with_an_unknown_payload_leaves_by_reply` | pass (passes on today's code) |
| S-237.2.6 | `test_S_237_2_6_a_tap_after_the_wait_ended_is_a_new_message` | pass (passes on today's code) |
| S-237.2.7 | `test_S_237_2_7_the_same_tap_delivered_twice_moves_the_run_once` | pass |
| S-237.2.8 | `test_S_237_2_8_a_botcake_chat_whose_params_look_like_a_tap_leaves_by_reply` | pass (passes on today's code) |
| S-237.2.9 | `test_S_237_2_9_a_graph_payload_with_spaces_still_matches_the_trimmed_tap` | pass |
| S-237.2.10 | `test_S_237_2_10_ids_never_reach_the_gateway_or_the_reply_record` | pass (passes on today's code) |
| S-237.3.1 | `test_S_237_3_1_an_unwired_button_exit_publishes_with_a_notice` | pass |
| S-237.3.2 | `test_S_237_3_2_a_test_tab_tap_follows_its_port_and_typed_text_leaves_by_reply`, `test_S_237_3_2_page_the_shown_button_sends_its_title_and_payload` | pass |
| S-237.3.3 | `test_S_237_3_3_a_flow_saved_before_this_change_cleans_unchanged_with_no_new_ports`, `test_S_237_3_3_a_flow_saved_before_this_change_sends_taps_to_reply` | pass (passes on today's code) |
| S-237.3.4 | `test_S_237_3_4_the_port_rows_and_the_button_rows_fit_a_phone` | pass, **markup only** (accepted by the reviewer; no browser check, see Rulings) |
| S-237.3.5 | `test_S_237_3_5_two_messages_of_one_step_with_the_same_payload_get_a_publish_notice`, `test_S_237_3_5_a_payload_shared_by_two_messages_takes_the_first_exit_or_reply_when_only_the_second_is_wired` | pass |

### Reviews

- Spec review (skeptic-reviewer, spec-review mode): needs revision; items 1–3 fixed in the plan before "go".
- T1 (adversarial): no blocker or major; 4 minors. Fixed in loop 1: the trailing-newline id (untrusted save path), the missing boundary and link-repeat tests, the notice wording. The fourth (a docblock that became true with T3) needed no change.
- T2 (adversarial): 1 major (the tap half of S-237.3.5 untested), fixed in loop 1 and confirmed closed on re-check; minors to `TODO.md`.
- T3 + T4a + T4b (standard): go, 4 minors, all to `TODO.md`.
- One fix loop in all; no finding open at blocker or major.

## Rulings

- Ruling: a postback's tap matches on payload only, first in port order, and an unwired first match goes to `reply` rather than to a later wired button with the same payload. Decision 4 matches by payload, and the Publish notice tells staff. Cost if wrong: two messages in one step sharing a payload send every tap to the first one's exit.
- Ruling: the tap is read only from gateway chats (`payload.source === 'gateway'`). Botcake webhook params land in the same `payload`, and decision 8 says a Botcake customer can only type. Cost if wrong: none for customers; a Botcake setup sending `source=gateway` could forge a tap (trusted path, in `TODO.md`).
- Ruling: the graph payload is compared after `trim` and a cut to 1000, the way the gateway stores the tap. Otherwise a trailing space typed by staff would send every tap to `reply`. Cost if wrong: two payloads differing only in outer spaces match the same tap.
- Ruling: the server never makes ids; the editor does, on add and on load. An old or API-posted graph then cleans byte for byte the same (decision 7), and the ids the editor shows are the ones it saves. Cost if wrong: a graph posted without the editor has no button ports until it is opened and saved.
- Ruling: web_url buttons keep an id (no port), so a Link → Postback switch brings the port and its wire back. Cost if wrong: none; a link's id is never a port.
- Ruling: `TAP_ID` is `/^[a-z0-9]{6,16}$/D`. Without `/D`, PHP's `$` accepts a trailing newline that the JS twin refuses, and the editor would regenerate that id and get its own wire refused on every autosave. Cost if wrong: none.
- Ruling: the same-payload notice says "with the same payload (…)", not "on different messages" as the plan wrote. Publish's existing same-message check (spec 229) compares raw payloads, so `OO` and `OO ` on one message reach the notice too. Cost if wrong: wording only.
- Ruling: the existing S-229.7.2 test's assertion on the click handler changed from `say(t.title)` to `say(t.title, t.payload)`. It pinned the line this spec changes, and its behaviour (the click continues the flow) still holds.
- Ruling: commit `a1226768` (T4a) carries the T4b page test, which only passes from `2514606d`. Every other commit is green on its own. Cost if wrong: a bisect landing exactly on `a1226768` sees one red test; `/ship` can tidy it.
- Ruling: no browser check. Running the app locally needs the app's environment file and database, which this session may not touch, so S-237.3.4 is proved on the markup only (the reviewer accepted that). Cost if wrong: a long port title or many ports could still overflow at 390 px; the port label is `truncate` inside the 220 px card, and the canvas pans.
- Ruling: the kit's `backend-developer` / `frontend-developer` agents are not installed in this session, so the main session built each task red first (as in spec 229), and `skeptic-reviewer` reviewed per tier: T1 and T2 adversarial, T3 + T4 standard (sonnet).
- Conflict between CLAUDE.local.md and the spec: none.

## Story changes

- Added (from the spec review, accepted by the reviewer): S-237.1.6, S-237.2.8, S-237.2.9, S-237.2.10, S-237.3.5. The reasons are in the Plan ("New cases from the spec review").
- S-237.1.3: the editor half is "remove the first of three: the other two keep their ports", because the editor has no reorder control. Reordering is proved on the server with a reordered graph posted to the save API.
- No other case was changed.

## Open questions

None open. The reviewer answered the plan's questions: the five added cases accepted, "passes on today's code" recorded instead of a red run, S-237.3.4 on the markup.

## Proposed tasks

1. **Strip `source` (and other system keys) from Botcake webhook params** in `BotcakeChatController`, so a Botcake param can never look like a gateway chat (`TODO.md`).
2. **Show the button title instead of `tap_<id>` in the Test tab path** (`TODO.md`).
3. **Make spec 229's same-message duplicate check compare trimmed payloads**, like the tap match does (`TODO.md`).
4. **A browser check of the editor at 390 × 844 with 3+ button ports**, once a session can run the app.
