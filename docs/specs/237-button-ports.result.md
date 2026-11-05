# Result 237: an exit for each button and quick reply

## Status

Plan written and revised after the spec review (skeptic-reviewer, spec-review mode: needs revision, items 1–3 fixed in the plan, minors 4–7 and 10 taken in, 8 noted). Waiting for "go". No code yet. Branch `feat/237-button-ports` from `b2b355ea`.

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

To be written when the work is done.

## Checks

To be written when the work is done.

## Rulings

None yet.

## Story changes

None yet.

## Open questions

None yet.

## Proposed tasks

None yet.
