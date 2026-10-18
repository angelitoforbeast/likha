Amendment 072-1. Your run was stopped on purpose so this change could be added. Your uncommitted work is still in the worktree; keep it, apart from what this amendment removes.

Save this text verbatim as `docs/specs/072-chat-ad-id-page-name.amendment-1.md`, commit it, and treat it as part of the spec. The result must list these changes.

| Change | Who decided, and why |
|---|---|
| **Remove the auto-name.** Drop the placeholder name `Page <BotcakeID>` and drop renaming a page from a chat's `page_name`. Never rename a page automatically. `page_name` from Botcake is only kept in `botcake_chats.payload`, as information. | The owner, 2026-10-19: the page ID is the reference ("possible magkaroon ng same page name"), and the name in our system must match the PAGE name in orders, which the Facebook name may not. |
| **"+ Page" requires all three fields: page name, Botcake ID and token.** The page name must be the one used in orders (say so in the dialog's help text). Choosing an existing page from page settings still works; then the name is already known and only the missing ID or token is asked. The checks stay: the token test, and refusing an ID that another page uses. | The owner: "manual na lang, page name, botcake id + token". |
| **Adding a page also sets it up.** After it saves and the token test passes, "+ Page" runs the same code as the On switch (`BotcakeChatBlock::turnOn`): it creates the key, writes the block with the new body (with `ad_id` and `page_name`) and publishes. The row shows On. If Botcake fails, the page is still saved, stays Off, and the row shows the error. | The owner: "para masetup maaayos din yung page". |
| **Unchanged:** the new body with `ad_id` and `page_name`; `ad_id` in the payload; `ad_id` on the `chat_verify` row and filled into an empty `ad_id` (never overwriting); the command and switch rewriting the block with the same key. | — |

Update the tests to match: no rename test; "+ Page" without a name gives 422; "+ Page" success means On with the exact block posted; Botcake failure on add means saved but Off, with the error shown. Then continue to the review and the result as in the brief.
