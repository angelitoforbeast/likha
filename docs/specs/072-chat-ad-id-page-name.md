# Brief 072: the chat block also sends the ad and the page name; "+ Page" needs only the Botcake ID and token

## Why

The "Chat to site" Default Reply block (`App\Services\BotcakeChatBlock::BODY`, written by the /chatbot switch and by `botcake:chat-setup`) sends `page_id`, `psid`, `name`, `message` and `source`. A live test on 2026-10-19 showed that Botcake also fills `{{page_name}}` with the Facebook page's name: "Karla Chavez" arrived. The Botcake docs also list `{{ad_id}}`, the ad the customer came from.

The owner wants:
- **the ad ID kept** for every chat, and on the customer row the first time they chat;
- **"+ Page" to ask only for the Botcake ID and token.** The Botcake API has no way to read a page's name. A system field like `page_name` is only filled when a customer passes the block. So the name is optional, and the first chat fills it in.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| `BODY` gains `"ad_id":"{{ad_id}}"` and `"page_name":"{{page_name}}"`. Switching On (and `botcake:chat-setup`) writes the new body. Re-running the command on a page that is already set up rewrites the block with the same key (no rotation). | One block for all pages; the switch fixes a page. |
| **Receiving:** `ad_id` and `page_name` stay in `botcake_chats.payload`. No new columns. An empty value or a literal `{{ad_id}}` (unfilled) is stored as nothing. | Lean schema. |
| **The first chat of a new customer:** the `botcake_psids` row created by the PSID check (`source` `chat_verify`) also gets `ad_id`, from the chat. When an existing `botcake_psids` row for that page and PSID has an empty `ad_id` and the chat carries one, fill it. Never overwrite a non-empty `ad_id`. | The customer's ad source, as soon as they chat. |
| **"+ Page":** the Botcake ID and token are required; the page name is optional. The rest is the same as today: the token test, refusing an ID another page uses, and attaching to an existing page that has no ID yet. A **new** page with no name gets the placeholder name `Page <BotcakeID>`. | Owner. |
| **Auto-name:** when a chat arrives for a page whose name is still the placeholder `Page <its BotcakeID>`, and the chat carries a real `page_name`, rename the page in `page_settings` to that name. This is a single transaction that updates `page_name` on that page's rows. Skip the rename and log it when another page already uses that name. Rename once; after that, never rename automatically. | The name arrives with the first chat. |
| **The chat endpoint's other rules stay.** That covers the key, the IP, the PSID check and "bot off". | Security from 067/068. |
| All screen text in English. Never show tokens or keys. | House rules. |

## Done when

- Tests (Http::fake):
  - On and the command write the new body (the exact JSON);
  - the command on a set-up page keeps the key;
  - a chat with an `ad_id` fills a new `chat_verify` row and fills an empty `ad_id` on an existing row, but does not overwrite one;
  - an unfilled `{{ad_id}}` stores nothing;
  - "+ Page" works with only the ID and token;
  - a placeholder page is renamed by its first chat, and a name already used is skipped.
- `php artisan test tests/Feature/Chatblast` passes. The full suite adds no new failures (the known ones are ExampleTest and NightRun ImportStartTest).
- Report the commits, the test lines, the review findings, and anything this brief got wrong.
