# Spec 237: an exit for each button and quick reply

**Project:** Likha (the same code runs incepxion.pro): the chatbot flow editor and FlowEngine. **Shape:** change · **Weight:** architectural (graph, editor, engine) · **Risk tier:** high (live flows)

## Why
Spec 229 added buttons and quick replies to Send message. A tap leaves by the step's single `reply` port and saves the tapped title into the step's "Save the reply into" field, so the flow needs a Condition step to branch.

The owner wants the button itself to be the branch: "Order na" goes to step A, and "Magtanong" goes to step B. Read docs/specs/229-buttons-quick-replies.md and its result first. The relevant code:
- FlowGraph (ports, cleanConfig, publishProblems);
- the editor's Send message form and node drawing (resources/views/chatbot/editor.blade.php, public/js/chatbot-buttons.js);
- FlowEngine, where the waiting step resumes on a reply;
- HandleGatewayEvent and ChatbotInbound, which store `payload.postback` and `payload.quick_reply`;
- the Test tab.

## Decisions already made (settled with the owner; don't reopen them unless something is actually broken)
| # | Decision |
|---|---|
| 1 | Every postback button and every quick reply of a waiting Send message step gets its own output port on the node, labelled with its title. web_url buttons get no port, since a link sends no tap. The existing `reply` port stays; with buttons it means "the customer typed something instead". `skipped` stays as today. |
| 2 | A port's identity is a stable id stored on the button or quick reply in the graph (generated once, never derived from the title or position), so editing a title or reordering keeps the wire. Deleting a button removes its port and its wire. |
| 3 | A tap whose payload matches a button or quick reply of the waiting step leaves by that button's port. If that port has no wire, it leaves by `reply`, as today. The tapped title is still saved into "Save the reply into" when that field is set. |
| 4 | Matching uses the payload stored in the graph for that button, as filled at send time. A tap that matches none (an old message, a changed payload) leaves by `reply`. A tap after the wait ended is a new message, as today. |
| 5 | One wire per port, as for every other port. Publish lists unwired button ports as a notice (they fall back to `reply`), not a block. |
| 6 | The Test tab: clicking a shown button follows its port exactly as a real tap does. |
| 7 | Flows saved before this change are unchanged and behave as they do now. A button gets an id only when the flow is next saved; until then it has no port and taps go to `reply`. |
| 8 | Botcake pages still get the text only (spec 229). A customer there can only type, so `reply` is taken. Keep the existing warning. |
| 9 | The editor shows the ports on the node in the same order as the buttons in the form, and fits at 390 × 844. |

## Stories
Add these to qa/stories.md under `## Slice 237 – An exit for each button and quick reply`, with one failing test per case first, named with its case ID.

S-237.1 Each button and quick reply has a port (P1)
- .1 happy: a waiting step with postback "Order na", postback "Magtanong" and web_url "Site" → ports `reply`, plus one for each postback, plus `skipped` when a skip time is set, and none for the link.
- .2 happy: 3 quick replies → 3 ports in order.
- .3 edge: renaming a button keeps its port and wire; reordering keeps the wires.
- .4 edge: deleting a button removes its port and wire.
- .5 negative: a posted graph with a wire to a port that doesn't exist → refused by cleanConfig, like other bad wires.

S-237.2 A tap follows its port (P1)
- .1 happy: tapping "Order na" (wired to A) → the run continues at A, and the field holds "Order na".
- .2 happy: the same for a quick reply.
- .3 negative: typing text instead → `reply`.
- .4 edge: tapping a button whose port has no wire → `reply`.
- .5 edge: a tap with an unknown payload → `reply`.
- .6 edge: a tap after the wait ended → a new message, as today.
- .7 edge: the same tap delivered twice → the run moves once.

S-237.3 Publish, Test tab and old flows (P1)
- .1 happy: Publish with an unwired button port → a notice, and it publishes.
- .2 happy: the Test tab button click → follows the port.
- .3 edge: a flow saved before this change → unchanged, and taps go to `reply`.
- .4 edge: the editor at 390 × 844 with 3 button ports → no sideways scroll.

## Constraints
- High tier: untrusted input is the tap payloads and graphs posted to the save API.
- Run the chatbot tests often and the full suite last. Known pre-existing failures: ExampleTest and ItemTableFitScriptTest; BigBroadcastTest and AstraAddressRulesTest S-26.18 are flaky.

## Budget
At most 2 tries at the same step. Size: medium to large.

## Done when
- [ ] The S-237 cases pass, each with a test named after it.
- [ ] The full suite adds no new failures.
- [ ] qa/stories.md has slice 237.
- [ ] The result has Merge danger, and says what happens to live waiting customers at deploy time.

## Out of scope
Ports for Botcake-only steps, and deploying.
