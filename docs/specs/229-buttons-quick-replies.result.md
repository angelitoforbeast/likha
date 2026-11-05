# Result 229: buttons and quick replies in Send message

## Status

Plan written and revised after a spec review (`skeptic-reviewer`, spec-review mode: go with changes; its five
"needs revision" items are folded into the plan below). Waiting for the reviewer's "go". No code yet.

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
replies on an item that is not the last (an item added after it while editing must not fail the autosave).
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

(after the build)

## Checks (command and output)

(after the build)

## Rulings

(after the build)

## Story changes

Proposed with the plan (to be confirmed with the "go"):
- **Added S-229.1.10** (edge): a non-https web_url (e.g. `javascript:`) in a draft posted to the save API → the
  sender drops the button and the Test tab draws no link. Why: the Test tab runs drafts, which never passed
  Publish's https check.
- **Added S-229.2.7** (edge): text or titles that only break Meta's limits after variables are filled (text over
  640, duplicate titles) → cut or dropped at send, never a failed send that a waiting step re-sends forever.
- **Where 1.4, 1.5 and 3.3 refuse:** at Publish, not on save (Open question 2).
- **Slices 4 and 8:** characterisation cases, no red line (Open question 1).

## Open questions

1. **Red lines for slices 4 and 8.** Taps already continue a waiting step (title saved, `reply` exit, dedupe,
   lock, late taps to Start). Those cases are characterisation tests and pass on today's code, so slice 8 has
   no red line and slice 4's red line comes from 4.5 only. I plan to record "passes on today's code
   (characterisation)" per case instead of a forced red. Accept?
2. **Empty title, non-https URL and quick replies not on the last item refused at Publish, not on save**
   (cases 1.4, 1.5, 3.3). The draft saves on Done and on every canvas change, and one refused item blocks
   every later draft save, so a half-made button must not be refused on save. The editor shows the problem on
   the row at once, and the sender drops a bad button anyway. Accept?
3. **Echoes.** A button message's Meta echo may not carry the same text, so the echo-based Health counts
   ("not answered", double reply) could misread it once the gate is on. Not touched here; I propose it as a
   follow-up unless you want it checked in this spec.

## Proposed tasks

(after the build)
