# Brief 046: `/chatblast` sends order updates to customers through Botcake, and shows every message like a sheet

## Why

The owner sends order updates ("Idedeliver po ngayon ang order niyo...") to customers through Botcake. The old Google Sheet did it but failed: customers had no PSID and writing the Botcake custom field by "get row by value" failed. Both are solved now: orders carry `macro_output.botcake_psid` (specs 039-045), and writing a custom field through the API, reading it back and then sending a flow is proven: on 2026-10-17 a test to the owner's own PSID on one page arrived correctly as the page's approved utility template "May update na po sa order nyo po❤️\n\n{{1}}" with {{1}} = the page's custom field.

Per page, `page_settings` (provider `botcake`) already holds: `page_id`, `token` (secret), `flow.shipping` = the flow "CHATBLAST - SHIPPING STATUS (post_order)" that uses that template, and now `field.message` = the custom field behind {{1}} (filled for 38 of 59 pages). One flow + one field per page carries every kind of message; only the field's text changes.

## Proven send sequence (use exactly this; `BotcakeClient` may already have parts)

Base `https://botcake.io/api/public_api/v1/pages/{page_id}`, header `access-token: <token>`:
1. `POST /customer/{psid}/customer_fields` body `{"data":[{"id":"<field.message>","value":"<text>"}]}` → `success: true`.
2. `GET /customer/{psid}/customer_fields` → find the row whose `id` (or `custom_field_id`) equals the field id; its `value` must equal the text exactly. Otherwise do NOT send (status `field_mismatch`).
3. `POST /flows/send_flow` body `{"psid":"<psid>","flow_id":<flow.shipping as int>}` → `success: true`.
Record HTTP codes and response bodies of each step on the message row. 429/5xx: back off and retry (max 3); 401: token invalid (mark the page's token_status as the sync does) and stop that page.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| One table for the messages (a new table is justified: it is the send log; check `CLAUDE.local.md` and existing tables first), e.g. `botcake_messages`: page, macro_output id, waybill, kind (`delivering` first), customer FB name, psid, text, status (`ready`, `no_psid`, `no_config`, `queued`, `sending`, `sent`, `failed`, `field_mismatch`, `skipped`), attempts, last http code, step responses (JSON, trimmed), sent_at, created_by / sent_by, test flag. Unique (waybill, kind) for real sends, so a customer never gets the same kind twice | Debuggable, no double sends |
| Kind `delivering`: an order whose waybill's J&T status (`from_jnts.status`, same waybill join as the rest of the app) is `Delivering` (seen live: In Transit, Delivered, For Return, Delivering, Returned). Building the list is a button "Kunin ang mga bago" (and an artisan command for later automation, NOT scheduled in this spec): it adds rows for such orders of the last 7 days (order `ts_date`) that have no row of that kind yet: `ready` when the order has a PSID and the page has `token`, `flow.shipping` and `field.message`; else `no_psid` or `no_config` (which setting is missing, in the row) | The owner: the trigger is easy; "Idedeliver po ngayon" = out for delivery |
| Text: a per-kind template editable on the page, stored in `app_settings` (no new table), default for `delivering`: `Hello Ma'am/Sir {name}, Idedeliver po ngayon ang order niyo. Maraming salamat po sa pag-order, ingat po kayo at salamat ulit sa tiwala!`. Placeholders `{name}` (order FB name or receiver), `{item}`, `{cod}`, `{waybill}`, `{page}`. The row stores the final text it will send. No emoji in the default (an earlier test showed one as "�") | The owner's sheet text |
| Sending is manual in this spec: "Send" on a row, "Send selected", "Send lahat ng ready" (respecting the current filters), "Retry failed", and a Pause/Resume for the sending queue. Before any real send, "Send test": sends a chosen row's text to a test PSID instead of the customer: the test PSID is picked per page from that page's `botcake_psids` by searching a name (the owner's own account), stored as `botcake.test_psid` in page_settings (not secret); test sends are rows flagged test and never block the real send | Sending to customers is outward; the owner checks first |
| Sends run on a dedicated queue `chatblast` (one job per message, or small batches), never on `default` (the Botcake sync holds `default` for hours). Rate: at most 2 requests/second per page token; a daily cap per page (config, default 3000) | Throughput and isolation |
| The page `/chatblast`: looks and works like a clean sheet. Top: counts per status for the current filter as clickable chips (e.g. `ready 420 · sent 1,203 · failed 3 · walang PSID 35 · walang config 12`), the sending state (running / paused, queue size, sent in the last hour), and the action buttons. Filters: date range (default today), page, kind, status; search by name or waybill. Table, 100 rows a page, sticky header: time, page, customer, waybill, J&T status, PSID (last 4 only), message (first line, full on hover), status badge, attempts, HTTP, sent at. Clicking a row opens a side panel with everything to debug: full text, each step's request (without the token) and response body, attempts and timestamps, and the reason for `no_psid`/`no_config`/`failed`. Polls every few seconds while anything is queued or sending. Fast at 10k rows a day (indexed, paginated, grouped counts) | The owner: "gandahan mo, yung madali ko makita, mavisualize at madali madebug" |
| `/page-settings`: show the `Message field ID` and `Test PSID` columns (field.message already appears as a known column; add `botcake.test_psid` as a known, non-secret column) | Settings in one place |
| Access: `/chatblast` and its actions follow `NavAccess::allows(role, '/chatblast')` (CEO always; other roles when checked in /owner/nav-settings), like /page-settings. Add a nav link for /chatblast visible to the CEO by a migration seeding `nav_links` (small table) | Same as page settings |
| Shared code runs on Likha and Incepxion; any migration touches small or new tables only (never macro_output or from_jnts) | Shared codebase; big-table ALTERs lock imports |

## Tests

List building per status (ready / no_psid / no_config with the missing key) and no duplicates on a second build; template placeholders; the send sequence with fake HTTP: success, write fail, read-back mismatch (no send_flow), send_flow fail, 429 retry then success, 401 marks the token and stops the page; unique (waybill, kind) prevents a second real send; test send goes to the test PSID and does not block the real one; pause stops new jobs; daily cap; counts and filters with grouped queries (query count flat for 1,000 rows); side-panel data never contains the token; NavAccess (CEO, a checked role, 404 for others). Red first where natural. Whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

Automatic schedule of building or sending; kinds other than `delivering` (but keep kind a column so more come later); the 21 pages without a field (they show `no_config`); Incepxion-specific code.

## Final message

Commits; the table and migration(s); how the list is built; the send job, retries and rate/cap; the page and its queries (counts); what a debug side panel shows; the supervisor program the release must add for the `chatblast` queue (exact command line, numprocs 1, timeout) on both servers; test lines and suite result; review findings and fixes; anything the brief got wrong.
