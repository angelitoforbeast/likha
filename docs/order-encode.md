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
| `compare_sheet` | the AFTER MACRO spreadsheet (link or ID): the DATABASE tab to compare with, and its LocationData |
| `macro_sheet` | the macro spreadsheet (link or ID): its LocationData |

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
5. `php artisan order-encode:sync-locations` (daily 04:10 Manila, or by hand) stores the LocationData of the macro
   sheet (A2:D) and of AFTER MACRO (B2:D) as files in `storage/app/order-encode/`, in sheet order. It says whether the
   two lists are identical. An empty or failed read never overwrites a file. Run it once before the first shadow order.

**Live** (`mode = live`, built, not switched on): the Sheet step records a sheet reply with status `skipped` and the note
"encoded in Likha", continues on its normal port, and `EncodeOrder` creates the `macro_output` row the two imports would
have made (`OrderIdentity` prevents a duplicate). The job is `done` only after that row exists, so a failed write is retried. If the page is
not ready (no `macro_sheet` / `compare_sheet`, no location files, no key), or the encode cannot be queued, or it ends `failed`, the row is
written to the sheet as before. The `encode` worker must be running before any page goes live.

## Deploy checklist (by hand, after the owner's yes)

1. `php artisan migrate --force` (adds `order_encodes`; nothing existing changes).
2. Supervisor program `deploy/supervisor/likha-encode.conf`: `php artisan queue:work database --queue=encode --sleep=3 --tries=2 --timeout=600 --max-time=3600`.
3. `php artisan config:clear` so `services.openai.order_encode_key` is read; set `ORDER_ENCODE_OPENAI_KEY` (or rely on `OPENAI_API_KEY`).
4. Set `compare_sheet` and `macro_sheet` for the page, run `php artisan order-encode:sync-locations`, then set `mode` = `shadow`.
