# Brief 069: our own chatbot flow builder that replies through Botcake send_content

## Why

Since spec 067/068, every customer chat on a Likha page reaches `botcake_chats` through the "Chat to site" Default Reply block. `/chatbot` lists the pages with an On/Off switch, a FLOW column and botfields, which are per-page values in `page_settings` under provider `chatbot`.

The owner now wants **our site to answer the customer**:
- each page's FLOW picks a flow;
- the flow runs on every incoming chat;
- the flow replies with Botcake `send_content`.

`send_content` works inside Messenger's 24h window, and a chat that has just arrived is always inside it. This replaces the owner's ChatGPT Builder (CBB) flows. He will remove CBB from a page when he turns it On here.

The owner's CBB "Main Flow", which our builder must be able to rebuild (data, not instructions), works like this:
- **State machine.** A customer field CHAT STATUS drives it: empty → LOOP 1 → LOOP 2 → LOOP 3. The customer typing "exitloop" clears everything.
- **Every reply is stored.** USER INPUT is set to the last input, and the input is appended to ALL USER INPUT, separated by ", ".
- **New chat path.** Send the image from botfield PICTURE, then the texts "Main Flow" and "Main Flow 2". Set CHAT STATUS = LOOP 1, then send "Fill-Up Form" and wait for the reply.
- **Phone detection.** USER INPUT is matched with "contains any of" a list (639, +639, 09…, 901…999). On a match:
  - thank the customer;
  - an AI step extracts the phone number into GPT PHONE NUMBER;
  - set CHAT STATUS = LOOP 2.
- **AI steps.** They use gpt-4o-mini, with a system prompt taken from botfields "Prompt 1/2/3". The answer is saved to GPT INPUT and sent back. The step checks whether GPT INPUT contains "Your order is all set".
- **Order to the sheet.** When the order is complete, an AI step extracts the address, amount and price into CXD. Then one row is appended to a Google Sheet: DATE, PAGE NAME, NAME, PHONE NUMBER, ALL USER INPUT, SHOP DETAILS, EXTRACTED DETAILS, PRICE.

## Stories

- **S-069.1:** As the owner, I open `/chatbot` and see a section nav: **Flow | Page settings | Chats | Replies | Test**.
  - `/chatbot` goes to Flow.
  - The 068 page list moves to `/chatbot/pages`. `/chatbot` itself stops being the list; keep the 068 JSON/POST routes working under their names.
  - Chats shows the same view as `/chatblast/chats`, under `/chatbot/chats`.
  - Both navs keep a link to each other.
- **S-069.2:** In Flow I can create, rename, duplicate and delete flows. A flow opens in a canvas editor, ported from TeleSMS: node cards, wires from ports, pan and zoom, auto-arrange and autosave. Every node has a form.
- **S-069.3:** Node types:
  1. **Start**: one per flow.
  2. **Condition**: rows of `{variable, rule, value}`; the first match wins; else exits on `no`.
     - Rules: is, is not, contains, contains any of (one value per line or comma-separated), not contains, starts with, regex, is empty, is not empty, greater than, less than.
  3. **Send message**: up to 10 items, each text or image URL, all with variables. An optional "wait for reply" saves the next customer message into a customer field and exits on `reply`. Otherwise it exits on `continue`.
  4. **Set field**: a list of operations on customer fields: set, append (with separator), clear, and "clear all fields".
  5. **AI**:
     - model (default gpt-4o-mini), system prompt (variables allowed, so it can be `{{Prompt 1}}`), user message (default `{{last_input}}`);
     - remember conversation: the last N messages of this customer from `botcake_chats` and our sent replies;
     - max tokens (default 250), save to a customer field, and optionally send the answer;
     - ports `success` and `failed`.
     - Use `config('services.openai.key')`.
  6. **Google Sheet append**: spreadsheet URL or ID, tab, and one column template per line.
     - Use Likha's existing Google client (see `App\Services\BotcakePsidSheet`).
     - Port the TeleSMS formula guard: a cell built from a template with a variable is never a formula.
  7. **End**.
- **S-069.4:** Variables, through **one** interpolator, `{{name}}`. Lookup ignores case and treats spaces and `_` as the same.
  - Run: `last_input`, `full_name`, `first_name`, `psid`, `page_id`, `page_name`, `current_date`, `last_interaction`.
  - **Botfields:** the page's `chatbot` page settings by column label or key, e.g. `{{PRICE}}`, `{{Prompt 1}}`, `{{PICTURE}}`, `{{Main Flow}}`.
  - **Customer fields**, e.g. `{{CHAT STATUS}}` and `{{GPT INPUT}}`.
  - An unknown variable becomes empty.
  - The node forms list the available variables.
- **S-069.5:** Runtime.
  - **Trigger.** When a chat is stored in `botcake_chats` for a page whose `chatbot.active` is on and whose `flow` names a flow, a queued job runs that flow for that customer.
  - **Resume or start.** If the customer is waiting at a "wait for reply" node of that flow, the run resumes after it. Otherwise it starts at Start.
  - **Order.** Runs for the same page and PSID are processed one at a time, in order.
  - **Idempotency.** A chat id is processed at most once, even when jobs are retried.
  - **Limits.** At most 100 nodes per run.
  - **Failures.** A failed node logs and stops the run, or follows its `failed` port.
- **S-069.6:** Sending.
  - Each Send message node calls `POST /pages/{page_id}/flows/send_content` with `{"psid":…,"data":{"version":"v2","content":{"messages":[{"type":"text","text":…}|{"type":"image","url":…}]}}}`. Add this to `BotcakeClient` in its existing style. Use no message tag.
  - Every send attempt is logged with page, psid, chat id, flow, node, the text and image URLs, the status (sent / failed / skipped) and the error.
- **S-069.7:** Replies tab: the log of sends, newest first, with filters (page, date, status, search on the selected date). Sticky header; row details in a modal.
- **S-069.8:** Test tab.
  - I pick a page, type messages as a pretend customer (PSID "TEST"), and see what the flow would send, the field changes and the path taken.
  - Nothing is sent to Botcake, and no sheet row is written; show the row instead.
  - The AI step does call OpenAI in Test, and says so on screen.
  - Test customer fields are kept apart from real ones, and there is a "reset test customer" button.
- **S-069.9:** In `/chatbot/pages`, FLOW becomes a dropdown of flows.
- **S-069.10:** The customer view on Chats shows that customer's fields. The CEO can edit or clear them.
- **S-069.11 (safety):** A global switch, `CHATBOT_REPLIES_ENABLED`, defaults to **false**. While it is false:
  - runs still happen;
  - sends are logged as "skipped: replies disabled";
  - sheet rows are not written; they are logged as skipped.

  The owner flips it after testing. The Flow and Replies pages show its state.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| **New tables are OK** (the owner said yes):<br>• `chatbot_flows` (id, name, graph JSON with nodes and edges together, updated_by, timestamps);<br>• `chatbot_customer_values` (page_id BIGINT UNSIGNED, psid BIGINT UNSIGNED, key varchar(100), value TEXT, updated_at; UNIQUE(page_id, psid, key); one row per key, updated in place, never appended);<br>• `chatbot_replies` (the send log; index (page_id, sent_at) and (psid));<br>• whatever small run state you need, e.g. the waiting node per customer, ideally as a reserved key in `chatbot_customer_values`, such as `__wait`. | The owner chose "page \| psid \| key \| value" for customer fields, like page settings. |
| **Plan for about 1M chats a day.** Use numeric IDs, the indexes above, queued runs on their own queue `chatbot`, no full scans in any list, and searches tied to a date. Cache page settings and flows per run. | Owner's volume. |
| **Botcake is not used to reply.** No Botcake flows and no send_flow; only send_content. | Owner. |
| **Orders go to the Google Sheet for now.** | Owner. |
| **Sequences are spec 070.** Leave a nav slot for them only if it costs nothing. | Scope. |
| **No new AI provider settings.** Use the existing OpenAI config. Never store keys per user. | Security. |
| **All screen text in English.** Sticky top bar, filters and table header, and only the data scrolls. No page-level horizontal scroll. Row details in modals. Never show tokens or keys. | House rules. |
| **Likha only.** | Scope. |

## Done when

- **Engine tests (fakes)** cover:
  - the CBB-style flow above, end to end: new chat, then the fill-up form, then a phone number, then LOOP 2, then the AI, then the sheet append, then "exitloop" clears;
  - resume after wait-for-reply;
  - every condition rule;
  - every set-field operation;
  - the AI success and failed paths;
  - the formula guard;
  - idempotency (the same chat twice gives one run);
  - order (two chats from one customer);
  - the 100-node limit;
  - the replies switch off, so nothing is sent or written;
  - send_content called with the exact body.
- **Editor tests:**
  - save and load the graph;
  - edges validated per port of the node type;
  - foreign nodes, self-loops, duplicate wires and size limits are refused.
- **Access:** `/chatbot/*` gives 302 when signed out and 403 without access. Writes that change flows need the same access as 068's cell edits; anything that sends needs the switch.
- **Suites:** `tests/Feature/Chatblast` and the 067/068 tests pass. The full suite adds no new failures (the known ones are ExampleTest and NightRun ImportStartTest).
- **Browser check:** build a 6-node flow in the editor, run it in Test, check Replies, and check phone width. Put screenshots in the result.

## Out of scope

- Sequences (070) and customer intents.
- Incepxion.
- Removing CBB; the owner does that.
- Turning any page or the global switch on in production.

## Report back

Report the commits, the tables, the node types with their config shapes, the runtime design (queue, locks, idempotency), the routes, the test lines with the suite result, the review findings, the screenshots, and anything the brief got wrong.
