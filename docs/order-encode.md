# Order encode (spec 130)

Likha encodes and checks the orders of its own chatbot itself, a copy of the two Google Apps Scripts
(`docs/specs/130-order-encode-shadow/`: AUTO ENCODE and 1.0 OUTPUT). Step one runs **in shadow**: the flow still
writes the row to the sheet, Likha encodes the same row in the background and `/order-encode` compares the two.
A page can later go **live** (no sheet row; Likha creates the `macro_output` row itself). Nothing here touches Astra,
Checker 1, the night run, MacroChecker, the imports or the J&T list.

## Switching a page (Page settings, provider `order_encode`)

| Key | Value |
|---|---|
| `mode` | `off` (default) · `shadow` · `live` |
| `compare_sheet` | the AFTER MACRO spreadsheet (link or ID): the DATABASE and DATABASE - MIRRORED tabs to compare with |

Global (`app_settings`): `order_encode_model_extract` (default `gpt-4o-mini`), `order_encode_model_checker`
(default `gpt-5.2`). Key: env `ORDER_ENCODE_OPENAI_KEY`, else `OPENAI_API_KEY`. Prices for the cost estimate: `config/order_encode.php`.

## How it runs

1. The flow's Sheet step appends the row (as before). Only the Sheet step that writes to the tab **All Orders** takes part; any other
   Sheet step of the flow is left alone. For a `shadow` page it then creates one `order_encodes` row
   (spreadsheet id + tab + the row number of the append result) and queues `EncodeOrder` on queue `encode`.
   The flow never waits for it and never fails because of it. Test runs never encode.
2. `EncodeOrder` reads the row (by the header names of the tab when it can, else the column order A date · B page ·
   C name · D phone · E all user input · F shop details · G extracted details · H price), runs the ported scripts and
   writes `order_encodes` only: outputs, `steps` (every step and GPT call: model, prompt hash, status, raw answer cut to
   2,000 chars, tokens, ms; the key is never stored) and `usage`.
3. `php artisan order-encode:compare` (every 10 minutes) reads the DATABASE row with the same row number (and
   DATABASE - MIRRORED O/P) for rows older than 15 minutes and younger than 48 hours, and stores per field
   `same | different | sheet_blank | likha_blank`. It waits while the sheet's R or S is blank, marks `row_mismatch`
   when the row holds another order (timestamp or page differ), and `sheet_not_done` after 48 hours.
4. `/order-encode` (CEO, Marketing - OIC, Marketing; read-only) shows the counts, % equal per field, GPT calls and
   estimated cost per order, and one line per order with both values side by side.
5. The address list is Likha's own J&T file `resources/views/macro_output/jnt_address.txt` (`PROVINCE|CITY|BARANGAY`, one per line), read
   only, in file order, for both steps (the match and the checker's lists); the sheet's column A key is built in code and Ñ = N in all
   matching. Nothing is read from Google Sheets for it, and nothing needs syncing.

**Live** (`mode = live`, built, not switched on): the Sheet step records a sheet reply with status `skipped` and the note
"encoded in Likha", continues on its normal port, and `EncodeOrder` creates the `macro_output` row the two imports would
have made (`OrderIdentity` prevents a duplicate). The job is `done` only after that row exists, so a failed write is retried. If the page is
not ready (no `compare_sheet`, no address file, no key), or the encode cannot be queued, or it ends `failed`, the row is
written to the sheet as before. The `encode` worker must be running before any page goes live.

## Deploy checklist (by hand, after the owner's yes)

1. `php artisan migrate --force` (adds `order_encodes`; nothing existing changes).
2. Supervisor program `deploy/supervisor/likha-encode.conf`: `php artisan queue:work database --queue=encode --sleep=3 --tries=2 --timeout=600 --max-time=3600`.
3. `php artisan config:clear` so `services.openai.order_encode_key` is read; set `ORDER_ENCODE_OPENAI_KEY` (or rely on `OPENAI_API_KEY`).
4. Set `compare_sheet` for the page, then set `mode` = `shadow`.

## The Encode order step (spec 132)

The way to go live is a step in the bot flow: **Encode order** (flow builder → Add → Encode order). It replaces the Google Sheet step for
orders. It works whatever the page's `order_encode.mode` is (the page setting stays `off` or `shadow`; `live` is still accepted but
means nothing for the step). No flow uses it until someone adds it and publishes.

Config (all in the step's panel): the order fields `date, page, name, phone, input, shop, extracted, price` (A to H of the row; the same
variables as the Sheet step; I is left empty), `also_sheet` (write the row to the Google Sheet too, default off) with `sheet` and `tab`,
`on_failure` = `sheet` (write the row to `sheet` instead; needs a link, checked at Publish) or `continue` (go on without saving, with a warning),
and "Counts as an order". The models in use are shown read-only (`app_settings` keys above).

Run: the step builds the row, optionally appends it to the sheet (same Outbox call as the Sheet step), creates an `order_encodes` row
(mode `live`, `run_id`, `input.node`, `spreadsheet_id` = the fallback sheet or `flow-node`, `row_number` empty), queues `EncodeOrder` and
continues on **next**. The same run reaching the step again does nothing. If the order cannot be queued (no key, no address file, no table) it
writes the row to the fallback sheet and continues; with `continue` it continues with a warning; if that sheet also fails the step leaves on
**failed**. The Test tab never encodes. Reply log: kind `sheet`, status `skipped`, note "encoded in Likha" (as in 130 live); events:
`encode_order` (result `queued`, `already_queued`, `sheet_instead`, `not_saved`, `failed`, `test`, `skipped`) and, when ticked, `order`.
Results and the Inbox count orders from the `order` event only, so nothing changes there. `/order-encode?mode=live` lists these orders
with a link to their `macro_output` row.
