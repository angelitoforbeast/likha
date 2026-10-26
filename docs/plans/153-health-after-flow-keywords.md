# Plan 153: Bot Health after the flow; the Botcake Test flags active keywords

Spec: `docs/specs/153-health-after-flow-keywords.md`. Threat model: the Botcake answers (`/keywords`, `/flows`: ids, flags and flow names) are untrusted text from an outside service: shown as text only, cut to the stamp's 250 characters, never HTML. Page settings rows and chats in our database are trusted data. No new route: the existing Connections routes and roles carry the keyword check.

## Task 1: Bot Health counts chats only while the page had a flow (backend, medium)
Cases: S-153.1, .2, .3.
- `BotHealth::inbound()` keeps today's query (pages with a sent reply in the range) and then keeps a chat only when its page had a `chatbot.flow_id` row with a non-empty value whose `created_at <= received_at` and whose `replaced_at` is null or `> received_at`.
- The flow rows (active and history) are read once per call, mapped to page ids through `BotcakeChat::pageMap()` (page name → Botcake ID, the same map Health uses for its page list). The chat query is limited to those page ids; the time check is in PHP on the rows already read (the values may be secret-encrypted, so the non-empty check is on `plainValue()`).
- Everything built from `inbound()` follows (Answered %, not answered, reply times, hourly, slow, compare). Sends, failures and AI are unchanged.
- The existing Health tests get a flow row from before their chats (their pages had a flow in the scenario they describe).

## Task 2: the Botcake Test reads the keywords (backend, medium)
Cases: S-153.4, .5, .6, .7, .9.
- `ChatbotConnections::keywords($client, $pageId, $token)`: GET `/keywords`; on success the activated ones; their flow names from GET `/flows` (id → trimmed name; a missing name = "flow <id>"). Returns `['on', names]`, `['none', null]` or `['unknown', note]`.
- `testBotcake()` runs it after the token check passes (also when the chat block is unknown), stamps `botcake.keywords` (`on 2026-… · ON DELIVERY SCRIPT`), and adds `keywords` and `keywords_note` to the answer. `token_status` stays ok.
- `saveBotcake()` (the automatic test after a token is saved) runs the same check and returns the same keys. The nightly `chatbot:connections-check` already calls `testBotcake()`, so it stamps too.
- `info()` gives the card `keywords_status` (the last stamp). Changes shows `botcake.keywords` as "Botcake keywords".

## Task 3: show it (frontend + check, medium)
Cases: S-153.4 (popup line), .5, .8, .10.
- `PageContentCheck::setup()`: when the last `botcake.keywords` stamp starts with `on`, an X "Botcake: may naka-on na keyword (<flow names>) — i-off sa Botcake".
- The popup: after Test or Save, a second line under the message: red with the spec's text when on, green "Walang naka-on na keyword" when none, grey note when unknown. The Botcake card state says "Keyword on: not working yet" in red. The line uses `break-words` so it wraps at 390 px.
- The /chatbot/pages row: `botcake_keywords` (on/none/unknown/null); the Connected cell adds a red "Botcake: keyword on" when on.

## Review
One skeptic-reviewer pass, standard depth, on the whole diff at the end.
