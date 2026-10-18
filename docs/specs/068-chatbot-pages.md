# Brief 068: `/chatbot`, the page list for our own chatbot, with an On/Off switch and botfields

## Why

Since spec 067, every Likha Botcake page sends its customer chats to our site through a "Chat to site" block in its Botcake Default Reply. The block is written and published by `php artisan botcake:chat-setup` (see `App\Console\Commands\BotcakeChatSetup`), and each page has its own `chat.key`.

The owner wants this to become **our own chatbot system** under a new section, `/chatbot`. The first screen is the page list:
- connect pages (a new one, or one already in page settings);
- turn the bot On or Off per page;
- set every **botfield** value for the page.

Botfields are the values a reply flow will use later. A later spec will build the flow builder that replies with Botcake `send_content`.

Today the owner keeps these values in a Google Sheet, one row per product. He will now keep **one row per page** on our site, almost the same as his sheet:

`PAGE | ACTIVE | FLOW | PRICE | QUANTITY | ITEM NAME IN JNT DASHBOARD | Main Flow | Main Flow 2 | Fill-Up Form | Loop 3 Form | Prompt 1 | Prompt 2 | Prompt 3 | PICTURE | Seq 1 … Seq 20 | Seq Img 1 … Seq Img 20`

## Stories

- **S-068.1:** As the owner, on `/chatbot` I see every page that has a Botcake ID and token, with: ACTIVE (On/Off), FLOW, the botfield columns, the last chat time, and today's chat count (from `botcake_chats`).
- **S-068.2:** I can add a page in two ways:
  - **manually**, with a page name (as PAGE in orders), a Botcake ID and a token, which are saved to page settings exactly as `/chatblast/page-settings` saves them;
  - **from an existing page**: a page in page settings that is not on the list yet.
- **S-068.3:** **Switching ACTIVE On** does what `botcake:chat-setup` does for that one page:
  - create the page's `chat.key` if it has none;
  - write the "Chat to site" Default Reply block;
  - publish with `unlimit_default_reply: true`.

  The row shows the result (On, or the Botcake error in plain English).
- **S-068.4:** **Switching ACTIVE Off** only unpublishes the Default Reply (`is_published: false`). It keeps the block and the key, so On works again in one click. The site then refuses chats for that page as "bot off".
- **S-068.5:** I can type any botfield value in its cell. It saves by itself, like `/chatblast/page-settings`. Long text opens in a modal editor. Image columns (PICTURE, Seq Img N) take a URL and show a small preview.
- **S-068.6:** I can add a new botfield column at any time (`+ Column`). It appears for every page.
- **S-068.7:** FLOW holds the name of the flow template the page uses. It is free text for now; the flow builder will turn it into a dropdown later.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| **Storage.** Use the existing `page_settings` table (page / provider / key / value), with provider `chatbot`. Each botfield is a key. ACTIVE is the key `active` (`1`/`0`). FLOW is the key `flow`. The list of botfield columns is the set of keys in use under provider `chatbot`, plus the default columns above. No new table. | The owner's rule: one row per value, new columns any time. |
| **Default columns.** The ones in the header above, in that order. They show even when empty. | They match his sheet. |
| **On** reuses the same code as `botcake:chat-setup`. Move the per-page logic into a service that both the command and the switch call. **Off** sets `is_published:false` only. | One source of truth; Off can be undone. |
| **Bot off refuses chats.** The `/api/botcake/chat` guard refuses a page whose `chatbot` `active` is `0`, as a new counted reason, "bot off". A page with no `active` row counts as On. | All 59 pages are On today, set up by the command. |
| **Layout.** A sticky top bar, filters and table header (only the data scrolls). PAGE, ACTIVE and FLOW stay sticky on the left. The botfield columns scroll sideways **inside the table box**; the page itself never scrolls sideways. | The owner asked for a sheet-like screen; the house rule is no page-level horizontal scroll. |
| **Who can use it.** Same access rule as `/chatblast` (NavAccess). Changing ACTIVE or adding a page needs CEO. | It changes live Botcake pages. |
| **Text.** All screen text in English. Never show a token or key. | Owner rule. |
| **Scope.** Likha only. | Incepxion comes later. |

## Done when

- `/chatbot` loads for the CEO. It returns 302 when signed out and 403 without access.
- Tests (Http::fake) show:
  - On calls content, then settings, with the exact block from spec 067;
  - Off calls only the settings update with `is_published:false`;
  - a Botcake error shows on the row and changes nothing locally;
  - a botfield edit writes one `page_settings` row;
  - `+ Column` adds a key that shows for all pages;
  - the chat guard refuses "bot off" pages and counts them;
  - adding a page manually and from an existing page both work.
- `botcake:chat-setup` still passes its 067 tests.
- `php artisan test tests/Feature/Chatblast` passes. The full suite adds no new failures (the known ones are ExampleTest and NightRun ImportStartTest).
- There is a short browser check at desktop and phone width. Put screenshots in the result.

## Out of scope

- The flow builder and any auto reply.
- Importing from the Google Sheet.
- Incepxion.
- The 1M-chats-per-day storage work.

## Report back

Report the commits, the routes, the service you extracted, the test lines with the suite result, the review findings, the screenshots, and anything this brief got wrong.
