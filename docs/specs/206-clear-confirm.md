# Spec 206: clearing a page's ID, token or key asks first; Botcake gets a Clear in Connections

## Why
Two places, one problem (seen live on 2026-10-31 on the Incepxion site, which runs this same code).
1. /chatblast/page-settings (Page | Provider | Key | Value grid, `PageSettingController::saveCell`): emptying a cell and leaving it saves at once as a clear (`$value === '' && !$secret` → deactivate), with no confirmation. One stray backspace on a `botcake.page_id` or `chat.key` cuts a page off the bot. Only "Clear the value" inside the ⋯ history modal asks first (`confirm('Clear … of …?')`). The history itself is fine: every change keeps the old row with who and when.
2. /chatbot/pages → Connections modal: Forbeast and IncepxionBot have a Clear (`forbeast-clear`, `incepxionbot-clear` in `connections.save`; `ChatbotConnections::clearForbeast`), Botcake has none, and a blank Botcake page ID cannot be saved. The live case: two page rows held the same Botcake page ID 1085660977960440 (a duplicate row "4RO Alaga Natural Collective" created 4 seconds after the real "Alaga Natural Collective"); inbound chats resolved to the duplicate (BotcakeChat::pageMap, the later row wins), the real row showed "Botcake ID … is already used by 4RO …", and the only way to free the ID was to blank the cell on /chatblast/page-settings. Using the duplicate row's Off switch or "Fix chat block" instead unpublishes or rewrites the Default Reply of the Botcake page both rows share.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Important keys = any `*.page_id`, any token or secret (`is_secret`, `*.token`), `botcake.chat.key` (or the key holding the chat key), and `chatbot.flow_id`. Keep the list in one place in code. | The values that connect a page; losing one silently stops the bot or a sync. |
| 2 | On /chatblast/page-settings, emptying an important key's cell does not save until the user confirms: "Clear <key> of <page>? Current value: <value> (secrets: last 4 only). <what stops working>". Cancel puts the old value back in the cell and saves nothing. Other keys keep today's auto-save. The ⋯ "Clear the value" keeps its confirm. | The owner asked for a confirmation before important values are erased. |
| 3 | The server also refuses a clear of an important key that does not carry an explicit `clear: true` (the flag the confirmed path sends), with a 422 and a plain message — so a stale page, a paste or a script cannot clear one by sending a blank. | The browser confirm alone can be skipped. |
| 4 | Connections → Botcake gets a "Clear" button (same look as Forbeast's), with a confirm naming the page and the Botcake page ID: "Remove Botcake page ID <id> from <page>? This page stops receiving Botcake chats here. Botcake itself is not changed." On yes it deactivates that page's `botcake.page_id`, its Botcake token and its chat key, records who did it (as `deactivate($setting, $userId)` does), clears the page map cache (`forgetChatPageMap`/`forgetPageMap`), and returns the refreshed connection info. | A safe way to free a duplicate's Botcake ID from where staff work. |
| 5 | The Botcake Clear never calls the Botcake API: it does not unpublish or rewrite the Default Reply, and does not change `chatbot.active`. If the page's bot is On, the confirm says so, and the clear still proceeds on yes. | The Default Reply can belong to another row sharing the same Botcake page; touching it breaks the real page. |
| 6 | Same access as today's Forbeast Clear on that route; history shows the clear with the user, as other changes do. | No new permission model. |

## Stories / cases (S-206)
P1:
- .1 page-settings: emptying `botcake.page_id` of a page and leaving the cell shows the confirm with the page, key and current value; Cancel restores the value and nothing is written (no request, or the row is unchanged).
- .2 page-settings: confirming clears it: the setting row is inactive with replaced_by = the user; ⋯ history shows the clear with who and when.
- .3 page-settings: a POST to the cell route with a blank value for an important key and no `clear: true` is refused (422, the value stays); with `clear: true` it clears. A non-important key (e.g. a reply value) still clears on a blank without the flag (regression).
- .4 Connections → Botcake → Clear on the duplicate row of the live case (two pages, same Botcake page ID): after yes, only that row's page_id/token/chat key are inactive; the other row keeps its ID; the page map now resolves the ID to the other row; the other row's Save no longer says "already used by".
- .5 The Botcake Clear makes no outgoing HTTP call (assert with Http::fake / preventStrayRequests) and leaves `chatbot.active` and the chat block settings unchanged.
- .6 Secrets in the confirm show last 4 only; the full token never reaches the page HTML or JSON.
P2: .7 Clear is hidden or disabled when the page has no Botcake page ID.

## Threat model and risk
Trusted: signed-in staff with access to these pages. Untrusted: a stale tab or a scripted blank POST (decision 3). Risk tier: medium (connections that route live chats). Run a skeptic reviewer on decision 3 and the Botcake Clear.

## Done when
P1 pass with tests (failing first, then passing); tests/Feature no new failures vs the baseline on 53478a0b; the rendered confirm text for .1 and .4 in the result (HTML or a node run of the page script); deploy notes (no migration expected); the final line.

## Out of scope
Removing whole pages, merging duplicate rows, a duplicate-ID warning on "+ Page" (already exists), any Botcake API change, the /chatbot/pages check speed, deploying.

## Budget
Small job. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
