# Brief 065: the Chats "Botcake setup" box shows the Botcake variables that actually work

## Why

The setup box on /chatblast/chats (built in 059) tells the owner to put this in the Botcake JSON API block:
`{"page_id":"<the page's ID>","psid":"{{user.id}}","name":"{{user.full_name}}","message":"{{last_text_input}}"}`.

A live test on one page showed:
- `{{user.id}}` and `{{user.full_name}}` arrive as literal text. They are not Botcake variables.
- `{{psid}}`, `{{page_id}}`, `{{user_full_name}}` (also `{{full_name}}`), `{{user_first_name}}`, `{{user_last_name}}` and `{{last_text_input}}` arrive filled in.

The Botcake dynamic block docs list these subscriber variables: user_first_name, user_last_name, user_full_name, psid, ad_id, page_name, email, phone, address, current_date, CONVERSATION_LINK. So Botcake DOES have a page variable, and the block can be the same text on every page.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| The box shows one body for every page: `{"page_id":"{{page_id}}","psid":"{{psid}}","name":"{{user_full_name}}","message":"{{last_text_input}}"}`. The GET Params rows show the same four values. | Proven by the live test. |
| The page filter no longer changes the body. Remove the "type each page's own Botcake ID" wording, and say the same block works on any page. | `{{page_id}}` is filled by Botcake. |
| Remove `{{user.id}}` and `{{user.full_name}}` from the hint text, and do not suggest them anywhere. | They do not work. |
| Keep the receiving endpoint (`BotcakeChatController`) accepting its current aliases. You may add `user_full_name` to the name aliases. Update its comment that says Botcake has no page-id variable. | Old blocks keep working. |
| All page text is English. | Owner rule. |

## Done when

- The box on /chatblast/chats shows the body above, with and without a page selected.
- Neither `user.id` nor `user.full_name` appears in the chats view or in `ChatblastChatController`. Check with `git grep -n "user\.id\|user\.full_name" -- resources/views/chatblast app/Http/Controllers/ChatblastChatController.php`.
- A feature test asserts the shown body, and a test posts `user_full_name` (if you add the alias) and stores it as the name.
- `php artisan test tests/Feature/Chatblast` passes. The full suite adds no new failures; the known failures are ExampleTest and NightRun ImportStartTest.

## Out of scope

Anything else on the Chats page or in the endpoint.

## Report back

Report the commits, the changed files, the test lines, and anything this brief got wrong.
