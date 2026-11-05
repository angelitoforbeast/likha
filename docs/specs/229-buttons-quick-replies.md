# Spec 229: buttons and quick replies in Send message

**Project:** Likha (Laravel; the same code runs incepxion.pro) — the chatbot flow editor and its sender.
**Shape:** change · **Weight:** architectural (editor, saved graph, sender, tap handling) · **Risk tier:** high (it sends to live customers; taps are untrusted input)

## Why
The flow editor's Send message step can only send Text or Image link. Staff want Messenger buttons and quick replies, so customers can answer with one tap ("Oo po", "Order na").

Replies go out through Botcake or through the Forbeast gateway (Bot 1 at app.forbeast.app, Bot 2 at incepxionbot.com). A companion gateway change (Forbeast spec 049) adds buttons and quick replies to the gateway's send API. Botcake's send is not known to support them. So this spec has two jobs:
- show buttons and quick replies on gateway pages;
- send the text alone, with a warning, on Botcake pages.

## What the code shows today (read before planning)
- **Editor:** resources/views/chatbot/editor.blade.php, around lines 570–605. The item Type is text or image. "Show this message to the AI" is at about line 583. "Wait for the reply (exits on "reply")" is at about 594, and the skip timer at about 602.
- **Saving:** `FlowGraph::cleanConfig('send')` keeps only `items[{type,value,ai?}]`, `wait`, `save_to` and `skip_after`, and drops anything else. `MAX_ITEMS` is 10 and `MAX_TEXT` is 2000.
- **Ports:** `FlowGraph::ports()` gives a send step `continue`, or `reply` / `reply`+`skipped` when it waits.
- **Publish:** `publishProblems` / `publishNotice` exist (today they cover Encode order) and are called by `ChatbotFlowController::publish`.
- **Sending:** `FlowEngine::send` builds `$messages` (`{type:text,text}` or `{type:image,url}`, each with an `ai` mark). It goes through `BotcakeSendQueue`, `handOffSequence` or `$outbox->send`. `BotcakeOutbox::send` picks the provider (`ChatbotProvider::for`), and `wire()` strips `ai` and `ai_text`.
- **Providers:** `GatewayProvider::request` posts `{psid, messages, messaging_type, client_ref}`. `BotcakeProvider` posts `send_content` v2.
- **Taps:** `HandleGatewayEvent` already stores a postback (chat text = title, or the payload if there is no title; `payload.postback`) and a quick reply (text, plus `payload.quick_reply`). Postbacks are in `CHATS`, so a tap continues a run like a message.
- **Waits:** chatbot_waits rows hold flow_id, node_id and due_at. `ChatbotRunner::skip` handles expiry.
- **Test tab:** `TestOutbox::send`. Read how the Test tab draws messages before planning it.

## Gateway message shape (from spec 049; send exactly this)
- A text with buttons: `{type:"buttons", text, buttons:[{title, type:"postback", payload} | {title, type:"web_url", url}]}`.
- Quick replies: an optional `quick_replies:[{title, payload}]` on a text or image message.
- Limits: buttons 1 to 3 and text up to 640 characters; quick replies up to 13; titles up to 20 characters; payloads up to 1000. No duplicate titles or payloads in one message. Buttons and quick replies never on the same message.

## Decisions already made (settled with the owner; don't reopen them unless something is actually broken)
| # | Topic | Decision |
|---|---|---|
| 1 | Where a tap goes | The existing `reply` exit. No new port per button in this spec. A Condition step after it branches on the saved field. |
| 2 | What is saved | The tapped title goes into the step's "Save the reply into" field. The payload stays on the stored chat (payload.postback / payload.quick_reply), as today. |
| 3 | Payloads | Generated from the title by default (stable and readable: the title, upper-cased, with non-alphanumerics as _). Staff may override it in the editor. Duplicates in one message are refused. |
| 4 | Wait | Adding a postback button or a quick reply turns "Wait for the reply" on, with a short note. A web_url-only message does not. Publish warns if a postback or quick reply step does not wait. |
| 5 | Title length | 20 characters, counted in characters. The editor stops typing at 20. At send time, after variables are filled, a longer title is cut to 20, and the cut is noted in chatbot_replies. |
| 6 | Botcake pages | Botcake pages never get buttons or quick replies: the text (or image) goes out alone. The editor shows a warning on that message ("Botcake pages get the text only"). Publish lists the Botcake pages using the flow in a notice, not a block. chatbot_replies records that they were left out. Pages on Bot 1 or Bot 2 get them. |
| 7 | A gate until spec 049 is live | A config value lists which gateway routes accept buttons: `services.gateway.buttons` from env `GATEWAY_BUTTONS` (comma list of route keys, e.g. "forbeast,incepxion"; empty by default). A route not on the list is treated like Botcake (decision 6). The owner turns it on after the gateway deploy. |
| 8 | Old and late taps | A tap after the wait ended, or for a step that no longer exists, is handled as a new message (it already is stored as a chat). There is no second exit from the old step and never an error. |
| 9 | Where they go | Buttons (postback and web_url, up to 3) on a Text item. Quick replies (up to 13) on a Text or Image item, and only on the last item of the step, because Messenger shows quick replies only on the last message. Never both on one item. |
| 10 | The AI | When "Show this message to the AI" is ticked, the AI sees the text plus the titles: "Text [Yes \| No]". A customer's tap is seen as the title. |
| 11 | Records | The chatbot_replies `content` holds the buttons and quick replies as sent, with titles filled in. |

## Stories
Add these to qa/stories.md under `## Slice 229 – Buttons and quick replies in Send message`, in that file's format, with the slice summary line counting the cases. Write one failing test per case first, named with its case ID.

S-229.1 Staff add buttons or quick replies in the editor (P1)
- .1 happy: a Text item with "Yes" and "No" (postback) and "Website" (web_url) → save, reload → the same 3 with title, type and payload or url.
- .2 happy: add 3 quick replies, edit the 2nd, remove the 1st → save, reload → 2 remain, in order.
- .3 happy: a title "Order {{first_name}}" keeps the variable, and the checker treats it like text.
- .4 negative: an empty title → refused with a clear message.
- .5 negative: a web_url that is not an https URL → refused (same check style as Image link).
- .6 negative: a graph posted to the save API with an unknown button type or extra keys → cleanConfig keeps only title, type and payload/url.
- .7 edge: Filipino or emoji titles → unchanged after save, reload and send.
- .8 edge: an Image item offers quick replies but no buttons.
- .9 edge: a flow saved before this change → opened and saved unchanged (no new keys added).

S-229.2 Meta's limits in the editor and the saved flow (P1)
- .1 happy: 3 buttons → accepted.
- .2 negative: a 4th button → Add disabled; a posted graph with 4 is refused by cleanConfig.
- .3 negative: a 14th quick reply → refused the same way.
- .4 negative: a 21-character title → refused on save. Exactly 20 → accepted.
- .5 edge: a title that only gets long after variables are filled → cut to 20 at send, and the cut is noted in chatbot_replies.
- .6 edge: button text over 640 characters → refused on save.

S-229.3 Never both on one message (P1)
- .1 happy: an item with buttons hides or disables quick replies, and the reverse.
- .2 negative: a posted graph with both on one item → refused.
- .3 edge: quick replies on any item except the last → refused, with a message.
- .4 edge: remove all buttons → quick replies can be added.

S-229.4 A tap continues the flow (P1)
- .1 happy: a waiting step with a postback "Yes" and a save_to field → the tap leaves by `reply`, and the field holds "Yes".
- .2 happy: the same for a quick reply.
- .3 happy: a Condition after `reply` on the field → the right branch is taken.
- .4 negative: a web_url button → no event, and the step keeps waiting.
- .5 negative: adding a postback button turns Wait on; Publish warns if Wait was turned off again.
- .6 edge: the customer types instead of tapping → `reply` with the typed text.
- .7 edge: the same tap delivered twice → the flow moves once.
- .8 edge: a postback whose title and payload differ → the field holds the title, and the Inbox shows the title.

S-229.5 Botcake pages and the gate (P1)
- .1 happy: a page on Bot 1 or Bot 2 whose route is in GATEWAY_BUTTONS → the gateway body carries the 049 shape and nothing else new.
- .2 negative: a page on Botcake → the text goes out alone, chatbot_replies records that buttons were left out, and the editor shows the warning.
- .3 negative: a gateway route not in GATEWAY_BUTTONS → the same as Botcake.
- .4 negative: a Botcake page and a postback step that waits → the step still leaves by `reply` when the customer types, and the skip timer still works (the customer is never stuck).
- .5 edge: a page switched between Botcake and gateway → the provider is read at send time.
- .6 edge: the gateway refuses the message (4xx) → chatbot_replies shows failed with the error, and it is not retried.
- .7 edge: the async send pool and the sequence pool → the buttons survive queueing.

S-229.6 Publish catches broken button messages (P1)
- .1 happy: a valid button message → publishes with no extra notice (on a gateway-only flow).
- .2 negative: buttons with empty text → Publish blocked, naming the step.
- .3 negative: a postback with an empty payload → blocked.
- .4 negative: duplicate payloads in one message → blocked.
- .5 edge: a draft with a problem → the draft saves; only Publish blocks.
- .6 edge: Publish lists the Botcake pages that use the flow as a notice.

S-229.7 Test tab and records (P2)
- .1 happy: the Test tab shows the text and the buttons or quick replies, with variables filled.
- .2 happy: clicking a shown button in Test continues the flow as a real tap would.
- .3 happy: a real send's chatbot_replies content holds the buttons or quick replies with filled titles, via and route.
- .4 negative: a Test run sends nothing to Messenger.
- .5 edge: the Inbox shows the sent message, or at least does not break on the new keys.
- .6 edge: the editor at 390 x 844 with 3 buttons → no sideways scroll.

S-229.8 Late and old taps (P2)
- .1 happy: a tap while the wait is still open (under the 23-hour cap) → `reply`.
- .2 negative: a tap after the wait skipped → a new message; no second exit and no double run.
- .3 negative: the flow was republished, or the step was deleted → no error and no stuck wait.
- .4 edge: a tap while another run holds the lock → queued, not lost.
- .5 edge: a tap at the same moment the wait skips → exactly one wins.

S-229.9 The AI (P2)
- .1 happy: ticked → the AI sees "Text [Yes | No]".
- .2 negative: not ticked → hidden as today.
- .3 negative: ai and ai_text are still stripped before the send.
- .4 edge: after a tap, the AI sees the title as the customer's turn.

## Constraints
**Threat model.**
- Untrusted: gateway events (tap titles and payloads) and anything posted to the flow save API.
- Trusted: staff-entered flow content once saved and validated, config, the repo.
- A major finding needs a one-line realistic scenario. Fix loops stop after two.

**Rules**
- Don't start other Claude Code sessions; subagents inside this session are fine.
- No commands that start with an environment variable.
- The `GATEWAY_BUTTONS` value is read through config/services.php; tests set it with config(). Do not edit any .env file.
- Run the chatbot tests often and the whole suite once at the end. State any failure already red on vps2/main as pre-existing, with its name.

## Budget
- Attempts: at most 2 tries at the same step, then stop and report.
- Size: large. If it grows past that, stop and report before going on.

## Done when
- [ ] Cases S-229.1 to S-229.9 pass, each with a test named after its case ID. UI cases are tested on the rendered editor HTML or the save API, the way the existing chatbot tests do it.
- [ ] The result has a red line (test name and first failure line) for each slice, watched before the code.
- [ ] The full suite adds no new failures compared with vps2/main.
- [ ] With GATEWAY_BUTTONS empty, nothing changes on the wire for any page (a test proves it).
- [ ] qa/stories.md has slice 229.
- [ ] The result is filled in, with every ruling and how the owner turns the gate on.

## Out of scope
- A port per button.
- Conditions on the payload.
- Carousels and generic templates.
- Buttons on Botcake pages.
- The gateway change itself (spec 049).
- Deploying: the reviewer releases it.

## Report back
Fill in docs/specs/229-buttons-quick-replies.result.md and commit it with the work. Include:
- every ruling: `Ruling: <decision> — <why> — <cost if wrong>`;
- any story changes;
- Merge danger: one-way or two-way door, blast radius, how to revert.

Then end with the "229:" line.
