# Plan 206: clearing a page's ID, token or key asks first; Botcake gets a Clear in Connections

Small bounded change, built in this one worktree in order (the tasks share no files except the stories and the
result, but are small enough not to split into worktrees). Test-first per task.

| # | Task | Side | Tier | Cases | Files |
|---|---|---|---|---|---|
| 1 | The important-key list in one place: `PageSettings::clearWarning(full, secret)` returns what stops working when the key is cleared, or null for any other key. Important = any `*.page_id`, any `*.token`, any secret, `botcake.chat.key`, `chatbot.flow_id`. | backend | medium | S-206.3 (rule) | `app/Services/PageSettings.php` |
| 2 | `saveCell`: a blank on an important key without `clear: true` is refused (422, plain message, nothing written); with the flag it clears as today. Other keys keep clearing on a blank. The grid columns carry `important` (the warning text, or null) so the page knows which cells ask first. | backend | medium | S-206.2, .3 | `app/Http/Controllers/PageSettingController.php`, `app/Services/PageSettingsGrid.php`, `tests/Feature/PageSettings/ClearConfirmTest.php` |
| 3 | Page settings grid: emptying an important cell does not auto-save on the typing pause; leaving it (blur or Enter) asks "Clear <key> of <page>? Current value: <value>. <what stops working>" (the value is the cell's display value, so secrets show the last 4 only). Cancel keeps the old value and sends nothing; yes posts with `clear: true`. The ⋯ "Clear the value" keeps its confirm. | frontend | medium | S-206.1, .6 | `resources/views/page_settings/index.blade.php` |
| 4 | Connections → Botcake Clear: route `botcake-clear` under the same access and throttle as `forbeast-clear`; `ChatbotConnections::clearBotcake` deactivates the page's active `botcake.page_id`, `botcake.token` and `botcake.chat.key` rows with the user, clears the page map cache, never calls Botcake, leaves `chatbot.active` and `botcake.chat_block` as they are; 422 when the page has no Botcake page ID. Returns the refreshed connection info (masked token). | backend | medium | S-206.4, .5, .6 | `routes/web.php`, `app/Services/Chatbot/ChatbotConnections.php`, `app/Http/Controllers/ChatbotController.php`, `tests/Feature/Chatblast/BotcakeClearTest.php` |
| 5 | Connections popup: a Clear button on the Botcake card (same look as Forbeast's), hidden when the page has no Botcake page ID; the confirm "Remove Botcake page ID <id> from <page>? This page stops receiving Botcake chats here. Botcake itself is not changed." plus a line when the bot is On; the result message names the page ID. | frontend | medium | S-206.4, .7 | `resources/views/chatbot/index.blade.php` |
| 6 | Evidence and review: node run of both confirm builders taken from the rendered HTML, skeptic review of the 422 guard and the Botcake Clear, stories in `qa/stories.md`, full `tests/Feature` vs the baseline, result. | both | low | all | `qa/stories.md`, result |

## Design details the spec leaves open

- A blank on a secret cell already means "no change" (the grid never clears a secret on a blank; only the ⋯ clear
  with the flag does), so the 422 applies where a blank means a clear today: important keys that are not secret
  (`*.page_id`, a free `*.token` that is not secret). Secrets keep answering "unchanged" to a blank.
- `botcake.chat.key` and `chatbot.flow_id` are system keys: the grid already refuses to clear them (422). They are in
  the list so the rule is complete and the Botcake Clear uses the same list.
- The Botcake Clear also needs the page ID present (422 otherwise), so a stale popup cannot "clear" an empty page.
- Pages that drop off the /chatbot list after the clear (no Botcake ID any more) still get the refreshed info in the
  response, from the page name as given.
- Order of checks in `saveCell` stays: nothing active → unchanged; system key → 422; then the new important-key 422.
