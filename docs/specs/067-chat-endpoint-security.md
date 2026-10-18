# Brief 067: the Botcake chat endpoint only accepts real chats from our own pages

## Why

`/api/botcake/chat` (specs 059 and 065) stores customer chats that Botcake posts from each page's Default Reply or keyword "Call JSON API" block. Today one shared key (`BOTCAKE_CHAT_KEY`) protects it.

Anyone with Botcake access to a page can read that key. They could then post fake rows for any page_id or PSID, or flood the table. Blocking by IP alone is not enough either, because Botcake's IPs are shared by every Botcake account. Someone could copy our key and page_id into their own Botcake page, and the request would still come from a Botcake IP.

The owner approved three layers:
1. an IP allowlist;
2. a key per page, bound to the page_id;
3. a once-per-customer check that the PSID really belongs to that page.

## What we know (verified live on 2026-10-19)

**The request**
- Botcake posts from 203.171.22.6. That is the only Botcake IP seen so far; there may be others.
- The working body, the same on every page, is `{"page_id":"{{page_id}}","psid":"{{psid}}","name":"{{user_full_name}}","message":"{{last_text_input}}"}`. Default Reply blocks also send `"source":"default_reply"`.
- The key goes in the header `X-Chat-Key`.

**Our data**
- `page_settings` holds each page's `botcake.page_id` and `botcake.token` (secret). Use `App\Services\PageSettings`.
- `botcake_psids` (columns include `page` = the page name, `psid`, `source`, `botcake_customer_id`) is synced nightly. It holds about 502k PSIDs over 59 pages.

**Botcake API**
- `GET https://botcake.io/api/public_api/v1/pages/{page_id}/customer/{psid}/info` with header `access-token`.
- It answers `success:false` with `message` "Not found customer" or "psid wrong format" when the PSID is not on that page. Add a method for it to `App\Services\BotcakeClient`, following its existing call and rate-limit style.

**Writing a page's Default Reply via the API**
- Content: `POST /pages/{page_id}/default_reply/content` with `{"name":"Default reply","blocks":[...]}`. Sending `blocks: []` clears it.
- Settings: `POST /pages/{page_id}/default_reply` with `{"update":{"is_published":true,"unlimit_default_reply":true}}`.
- The working block has this shape:
  ```
  {"key":"chat_to_site","title":"Chat to site","message_tag":"OTHER","next_step":true,"gotos":[],
   "cards":[{"plugin_id":"json_api","config":{"method":"post","url":"<APP_URL>/api/botcake/chat"},
     "headers":[{"key":"X-Chat-Key","value":"<key>"},{"key":"","value":""},{"key":"","value":""}],
     "params":[3 x {"key":"","value":""}],
     "body":"<the JSON body as a string>","body_preview":<the same JSON decoded>,
     "response":[3 x {"custom_field_id":null,"key":""}],"buttons":[]}]}
  ```
- Without `body_preview` and the 3-row padding, Botcake sent an empty body.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| **1. IP allowlist.** A config list of IPs/CIDRs, `BOTCAKE_CHAT_IPS`, defaulting to `203.171.22.6`. A mode switch, `BOTCAKE_CHAT_IP_MODE`: `log` (accept, but log and count requests from IPs not on the list) or `enforce` (refuse with 403). The default is `log`. | Other Botcake IPs may exist. We watch first, then enforce. |
| **2. Key per page.** Store each page's key as a secret page setting, `botcake` / `chat.key` (random, at least 32 characters). The request's `page_id` must map to a page whose `chat.key` matches `X-Chat-Key` (`hash_equals`). Otherwise refuse with 401. The old shared key stays accepted only while `BOTCAKE_CHAT_SHARED_KEY_OK=true` (default true). | Rollout without breakage. Turn it off after every page has its own key. |
| **3. PSID check.** Once per page + PSID pair. First look in `botcake_psids` for that page name and PSID. If it is missing, call the customer-info API once with the page's token. On success, insert a `botcake_psids` row with `source='chat_verify'`, so the next chat needs no call. On "Not found customer" or "psid wrong format", do not store the chat; answer `{"ok":true}` and count it. Cache a negative result for 1 hour, so repeats do not call Botcake. If Botcake is down or times out (5 seconds), accept and store the chat, and log it. | Stops a key copied into another Botcake account, because a foreign PSID is not on our page. Do not lose real chats when Botcake is slow. No new table (owner rule). |
| **Counting.** Count refusals per reason (bad IP, bad key, unknown page, PSID not found) with a cache counter per day. Show the counts in the Chats page's "Botcake setup" box. | The owner can see whether anyone is trying. |
| **A command, `botcake:chat-setup {page?} {--all} {--rotate} {--dry-run}`.** For each page that has a Botcake ID and token: create `chat.key` if missing (or always, with `--rotate`), then write the Default Reply block above with that key and publish it with `unlimit_default_reply:true`. It prints page names and statuses only, never a key. `--dry-run` prints what it would do. It refuses `--all` without `--dry-run` unless `--confirm` is given. | Rollout and rotation are done via the API, page by page, and only when the owner says. |
| **The Setup box.** It no longer shows a copyable shared key for new setups. It says the block is written by the command and shows, per page, whether a `chat.key` exists (yes/no, never the value). | The keys stay out of screens. |
| All page text in English. | Owner rule. |

## Done when

- **Tests (Http::fake for Botcake) show:**
  - IP in `log` mode is accepted and counted; in `enforce` mode it is refused with 403.
  - A per-page key for the wrong page gives 401.
  - The shared key works only while the flag is on.
  - A PSID already in `botcake_psids` makes no API call.
  - An unknown PSID makes one API call and inserts a row; a second chat makes no call.
  - "Not found customer" stores nothing and is counted, and a repeat within an hour makes no call.
  - A Botcake timeout stores the chat.
  - The command's `--dry-run` writes nothing, and its real run posts the exact block shape and the settings update.
- No test or log line contains a key value.
- `php artisan test tests/Feature/Chatblast` passes. The full suite adds no new failures; the known ones are ExampleTest and NightRun ImportStartTest.

## Out of scope

- Running the command on live pages.
- Changing what the Chats page lists.
- Incepxion.

## Report back

Report the commits, the auth order you implemented, the command usage, the test lines with the suite result, the review findings, and anything this brief got wrong.
