# Brief 059: receive customer chats from Botcake's JSON API block

## Why

The owner wants each customer's Messenger messages to reach the website. The chosen route is Botcake's "JSON API" block (the "Edit Group Request" dialog), placed in a page's Default reply flow:

- The dialog has a method dropdown, a URL, Headers (key/value), Params (key/value), a test "Send" button, the Response, and "Save Respond" (JSON path → custom field).
- Botcake fills Params values with its variables. Their exact syntax is still being confirmed, so accept whatever arrives.

The owner said "gawa ka muna", so the receiving side is built first. The Botcake side gets configured after.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| **The endpoint is `POST /api/botcake/chat`** and also accepts GET, in case the block is left on GET. It is outside the session, CSRF and IP middleware, and is authenticated by a shared key: header `X-Chat-Key` OR param `key`, checked with `hash_equals` against config `services.botcake.chat_key` (env `BOTCAKE_CHAT_KEY`). A wrong or missing key returns 401 with no detail. It is rate limited per IP, generously enough for about 170 pages. | Simple to paste into Botcake |
| **Fields.** It accepts JSON, form or query params. Known fields: `page_id`, `psid`, `name`, `message`, `message_id`, `sent_at`. Any other params are kept too: the full payload is stored as JSON, minus the key. Names are matched case-insensitively, and the aliases `user_id`/`fb_id` → `psid`, `text`/`last_input`/`last_user_input` → `message` and `full_name` → `name` are accepted. | We don't know Botcake's variable names yet |
| **Storage: a new small table `botcake_chats`** with id, page_id, page_name (resolved from page_settings `botcake.page_id`), psid, name, message (text), message_id (nullable, indexed), payload (json), received_at, and indexes on (page_id, psid, received_at) and received_at. A row with the same message_id, or the same page_id + psid + message within 10 seconds, is not stored twice. | Chats are a new kind of data; nothing existing fits |
| **The response is fast and small:** `{"ok":true}`. It never sends anything back to the customer. Storing must not depend on Botcake or J&T calls. | Botcake waits on the response |
| **A "Chats" page in the Chatblast app nav** (`/chatblast/chats`), with the same access as /chatblast. It lists the latest messages (time, page, customer name, the message) with filters for page, date (view only) and a search over name or text. Clicking a customer shows their messages in time order. It also has a **Setup box** (a pop-up, not sticky) with the exact values to paste into Botcake: Method POST, URL `{APP_URL}/api/botcake/chat`, Header `X-Chat-Key` = the configured key (shown masked, with a copy button for CEO only), and Params `page_id`, `psid`, `name`, `message`, with a note that the values are Botcake variables. | The owner needs copy-paste values |
| **Retention:** keep 90 days. A daily prune runs from the scheduler or from chatblast:auto on Incepxion. Works on Likha and Incepxion. | Bounded table |

## Tests

- The key is checked, and a wrong key gets 401.
- JSON, form and GET all work.
- Aliases and extra fields are kept.
- Duplicates are dropped.
- The page name is resolved from the page id.
- Rate limit.
- The Chats page and its access.
- The Setup box shows the values, with the key masked.
- The prune.
- The full suite passes, except the known `ExampleTest` and `ImportStartTest`.

## Final message

- the commits;
- the migration;
- the endpoint contract (an example curl);
- the env key to set at release;
- the Chats page;
- test lines and the suite result;
- review findings.
