Amendment 074-1. Your run was stopped on purpose so this could be added. Keep your work so far.

Save this text verbatim as `docs/specs/074-chatbot-inbox.amendment-1.md`, commit it, and treat it as part of the spec. S-074.4 ("read-only, no reply box") and "Sending messages by hand" in Out of scope are replaced by:

| Change | Who decided, and why |
|---|---|
| **A reply box under the conversation.** It has a text area and an "Attach image URL" field (http/https only, with a preview). Send posts to a new endpoint, which calls `BotcakeClient::sendContent` (the same body as the bot: text and/or image, up to 10 messages, no message tag). Enter sends; Shift+Enter makes a new line. The sent message appears at once as a right-side bubble labelled "You" (or the user's name), as against "Bot". | The owner, 2026-10-19: "idagdag mo na syempre". It is a Pancake-style inbox. |
| **24-hour rule.** Allow sending only when the customer's last message in `botcake_chats` for that page is under 24 hours old. Otherwise disable the box and show "Outside Messenger's 24-hour window: the customer must message first." Check this on the server too. | Facebook rejects message tags. send_content only works inside the window. |
| **Log.** Every manual send goes to `chatbot_replies` with `kind = manual`, the user id, the content, the status and the error, exactly like bot sends. It shows in Replies and in the thread. | One log. |
| **Pause the bot for this customer.** A switch in the right panel, "Bot for this customer: On/Off". It is stored as the reserved customer value `__bot_paused = 1`. While it is paused, the engine skips this customer's runs: it marks the chat as handled and sends nothing. Sending a manual reply asks once: "Pause the bot for this customer?" (yes / no). | Otherwise the bot answers on top of a human. |
| **Who can send.** Users with /chatbot access (NavAccess) whose role is CEO, or a role the CEO allows. Add a setting `chatbot.reply_roles` in app_settings, default `["CEO"]`, editable by the CEO on the inbox (a small gear menu). Throttle sends to 30 a minute per user. | Live customer messages. |
| **Tests.** Cover:<br>• a send inside the window posts the exact body and logs `kind = manual`;<br>• outside the window gives 422 and sends nothing;<br>• an image URL is validated;<br>• the role check;<br>• the throttle;<br>• pausing the bot means the engine sends nothing for that customer and the next chat is marked handled;<br>• unpausing resumes. | — |

Continue the rest of spec 074 as written: the review, the browser check and the result. The result lists these changes.
