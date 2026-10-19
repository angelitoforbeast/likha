# Brief 074: `/chatbot/inbox`, a Pancake-style conversation view

## Why

`/chatbot/chats` today is a table of incoming customer messages only. The owner wants a conversation inbox like Pancake's:
- pick a page;
- see its customers;
- open one customer and read the whole conversation, both what the customer sent and what our bot sent;
- see that customer's values on the right.

He said "Ayusin mo itsura": it must look good.

## Data (exists)

- **Incoming:** `botcake_chats`.
  - Columns: page_id, page_name, psid, name, message, payload JSON (`source`, `ad_id`, `page_name`, `image_url` for "[Photo]"), received_at.
  - The Like is stored as "👍". Photos are stored as "[Photo]", with the URL in `payload.image_url`.
- **Outgoing:** `chatbot_replies`.
  - Columns: page_id, psid, chat_id, flow_id, node_id, kind (send / sheet), content JSON (the messages sent: text and image URLs), status (sent / failed / skipped), error, sent_at.
- **Customer fields:** `chatbot_customer_values` (page_id, psid, key, value, updated_at).
  - Reserved keys `__wait` and `__last_chat` are internal; show `__wait` only as "Waiting for reply at step nX".
- **Customer record:** `botcake_psids`.
  - Columns: page, psid, fb_name, status, source, ad_id, phone_numbers, subscribed_at, last_interactive_at.
- **Page botfields and flow:** `page_settings`, provider `chatbot` (via `ChatbotFields`) and `chatbot.flow_id`.

## Stories

- **S-074.1:** As the owner, I open `/chatbot/inbox`, a new tab in the /chatbot nav placed after Chats, and see three columns.
  - **Left:** a page selector at the top (pages with a Botcake ID; remember the last choice in localStorage), a search box (name or PSID), and the conversation list for that page.
    - Newest activity first.
    - Each row: the customer's name (or "PSID …1234"), the last message preview from either side (with "Bot:" for ours), a relative time, and a small dot when the last message is from the customer and the bot has not answered after it.
  - **Middle:** the selected conversation, as chat bubbles.
    - Customer messages on the left (grey), our bot's sends on the right (brand color).
    - Images are shown as thumbnails that open full size. "👍" shows as a large emoji.
    - Each bubble shows its time on hover or tap.
    - Day separators ("Today", "Yesterday", a date).
    - Failed or skipped bot sends show a small red or grey label with the reason.
    - Sheet rows (kind = sheet) show as a small system line, "Order row written to sheet" or "failed: …".
    - The view opens scrolled to the newest message, and "Load older" loads more.
  - **Right:** the customer panel.
    - Name, PSID, ad_id, first seen, source.
    - Bot state: CHAT STATUS, and the waiting step if any.
    - **All customer fields** (key → value). Long values open in a modal. The CEO can edit or clear a field, as on /chatbot/chats today.
    - Below them, collapsible: **the page's botfields**, read-only, with a link to Page settings.
    - A link to the flow in the editor.
- **S-074.2:** The open conversation and the list refresh by themselves every 5 seconds, without losing the scroll position. New messages appear at the bottom; if I have scrolled up, show a "New messages ↓" pill.
- **S-074.3:** Phone width: the list, the conversation and the panel become one-at-a-time views, with back buttons. There is no horizontal page scroll.
- **S-074.4:** Read-only for messages. There is no manual reply box in this spec.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Built for about 1M chats a day.**<br>• The conversation list is built from the last 7 days by default (a filter offers 24h / 7d / 30d), using `(page_id, received_at)` and `chatbot_replies (page_id, sent_at)`, and grouped by psid with a LIMIT, never over all rows.<br>• Add any index you need in an additive migration, e.g. `botcake_chats (page_id, psid, id)` and `chatbot_replies (page_id, psid, sent_at)`. Say what you added.<br>• The thread loads 50 items at a time, merged from both tables by time.<br>• Polling asks only for items newer than the last one shown. | Owner's volume. |
| **New route `/chatbot/inbox`.** `/chatbot/chats` stays. | Owner: "gawa ka siguro new route". |
| **Access:** same as /chatbot (NavAccess). Editing customer fields needs CEO. | — |
| **English UI.** Sticky headers, and only the inner lists scroll. Never show tokens or keys. | House rules. |

## Done when

- Feature tests:
  - the list for a page, newest first, with the unanswered dot rule;
  - the thread merges incoming and outgoing in time order, including failed / skipped / sheet lines;
  - "Load older" and "newer than" polling;
  - the right panel shows the fields, `__wait` humanized, and `__last_chat` hidden;
  - CEO-only field edits;
  - 403 without access;
  - a query count or EXPLAIN check that the list does not scan whole tables. A test that asserts the query uses a bounded date range is fine.
- `php artisan test tests/Feature/Chatblast` passes. The full suite adds no new failures (the known ones are ExampleTest and NightRun ImportStartTest).
- A browser check with seeded data at 1366 and 390 px, with screenshots in `docs/specs/074-chatbot-inbox/`.

## Out of scope

- Sending messages by hand.
- Tags and assignment.
- Incepxion.

## Report back

Report the commits, any migration, the routes, the test lines with the suite result, the review findings, the screenshots, and anything the brief got wrong.
