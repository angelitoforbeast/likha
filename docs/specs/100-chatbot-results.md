# Brief 100: record which value was sent, whether it got a reply, an order and a delivery, and show it per flow version

## Why

The owner wants to change the chatbot flow ("Main Flow (from CBB)") and its page values (Main Flow, Prompt 1, Fill-Up Form, Seq 1–20 …) and **see whether the change worked**:
- which messages get replies;
- which lead to orders;
- which sequence steps work;
- whether orders get delivered.

Later, Mira and an AI analysis read the same data. The owner, 2026-10-21: "kelangang maprepare yung data … alin nirerespondan … inoorderan … alin sa sequence … need ko magkaroon ng data, kasi iibahin namin flow, ichecheck kung gumana", and on delivered, "ikaw bahala".

**Today:**
- `chatbot_replies` stores each send's final text, node and flow, but not **which botfield and which version** of it made each message, nor the **flow version**.
- "Replied" isn't recorded.
- No "order" event exists.
- Sequence steps run as flows named "<SEQUENCE> step N".
- Orders live in `macro_output` (with `botcake_psid`, `PAGE`, waybill, `ts_date`).
- J&T status is in `jnt_latest_scans` and the J&T tables (see `Chatblast` and `ChatblastSender` for how status is read).
- One send node can hold several messages: n10 sends {{PICTURE}}, {{Main Flow}}, {{Main Flow 2}} and {{Fill-Up Form}} as four messages.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Tag every send at the moment it is made,** on every path: live, send pool, sequence steps, Inbox manual. For each message of the send, record the botfields (and customer fields) its template used, with the **version of each botfield**: the id of the active `page_settings` row at that moment. Record per send: `flow_id`, **flow version** (the published `chatbot_flow_versions` id, or a stable hash of the published graph if no version row exists; check what 074/075 stores), node id, sequence id and step when sent by a sequence, and `ad_id` from the customer's first chat or `botcake_psids`. Store this in the reply's JSON (e.g. `content.meta`), or in nullable columns if they are needed for indexing; your plan decides. | Which value and which version was sent. |
| **Replied.** For each sent message group (one send = one group), `replied_at` is the customer's first message on that page after `sent_at`, within 24 h. Fill it with a light job when a chat arrives (the latest unreplied send of that customer gets it), plus an hourly backfill for safety. Also store `reply_seconds`. | Response rate and speed. |
| **Order event.** A new option on Send, Set field and Sheet nodes: **"Counts as an order"**. The owner will tick it on n43 (sheet) or n45 ("Order Confirmed"). When a run passes a ticked node, write a `chatbot_events` row of type `order` with: the run, the customer, the flow version, the last N sends before it (ids), and the sequence step the customer was on, if any. One order per customer per 24 h, so repeats don't double-count. Show the option in the node form with a short help line. **Don't edit the live flow.** | Orders tied to what came before. |
| **Delivered (the owner said "ikaw bahala").** Link an order event to a real order when one exists: a `macro_output` row with the same page and `botcake_psid`, with `ts_date` from 1 day before to 3 days after the event; the closest one wins. Read that order's waybill status the way chatblast does (`jnt_latest_scans` / J&T status): delivered, RTS/returned, in transit, or other. Compute this at report time, with no copy of order data. If no `macro_output` match is found, the order shows as "unmatched". | Delivered vs RTS without new pipelines. |
| **Results page:** `/chatbot/results` (a new tab "Results" in the Chatbot nav, same access as `/chatbot`). | — |
| *Filters* | page, date range, flow, **flow version** (a list with publish date and who published). |
| *Funnel* | chats → sent → replied % → orders % → delivered % (and RTS %). |
| *By message* | node, botfield, version (with "changed on <date>"), sent, replied %, median reply time, orders after, delivered. A version's value shows on hover or click (folded). |
| *By sequence step* | step, sent, replied %, unsubscribed, orders after, delivered. |
| *Compare versions* | pick two flow versions (or two versions of one botfield) and show the same metrics side by side, with counts, so small samples are visible. |
| *By ad* | ad_id, chats, orders, delivered, using the `ads_manager_reports` names when they match. |
| **Mira and AI access.** A read-only JSON endpoint, `/chatbot/results.json`, with the same filters and numbers, behind the same auth, plus an artisan command `chatbot:results --page= --from= --to= --json` that prints the same JSON for Mira's analysis. No message text of customers in these outputs: only counts, botfield names and versions, and the bot's own template values. | Mira's analysis. |
| **Load.** Tagging adds no extra query per message beyond what the send already reads (use the 098 page settings cache). Reports use indexed queries and cache 5 minutes. Add the indexes the report needs. | Volume. |

## Done when

- **Tests:**
  - tags on live, pool, sequence and Inbox sends (a 4-message node gives 4 tags with the right botfield versions);
  - a botfield edit makes a new version id;
  - flow version recorded;
  - `replied_at` set by the next chat and not by the bot's own sends;
  - order event on a ticked node, deduped in 24 h;
  - order to `macro_output` match and the J&T status mapping (delivered, RTS, unmatched);
  - each Results table with seeded data;
  - compare versions;
  - JSON and command output shape;
  - permissions.
- **Browser check:** filters, funnel, the four tables, and compare, at 1366 and 390 px. No page-level horizontal scroll.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, and the full suite adds no new failures.
- The result lists any migration (for the owner's yes) and what the owner must tick in the live flow ("Counts as an order" on n43 or n45).

## Brief rules (from the start prompt)

- Branch `feat/100-chatbot-results` from `main`. Spec 098 (Botcake send paths, rate limiter, raw failure bodies) is built at the same time on `fix/098-load-fixes`: keep send-path changes small and separate (a single tagging call per send) so the two merge cleanly. Other workers may run 099 (`/chatbot/pages`) and 101 (flow editor canvas); don't touch those files.
- Never push. Never touch remote hosts. No attribution lines.
- Plan in `docs/plans/100-chatbot-results.md` (committed first), result in `docs/specs/100-chatbot-results.result.md`.
- Headless browser check of the new page at 1366 and 390 px, screenshots in `docs/specs/100-chatbot-results/`.
- Budget: 2 attempts per failing step.
- A migration that only adds nullable columns or indexes to `chatbot_replies` or `chatbot_events`, or creates a new table, is allowed; the result says so, and its release needs the owner's yes.
- Threat model: untrusted = report filters (query string, command options), customer chats and their text; trusted = flow graphs and botfield values written by staff, repo files, config.
