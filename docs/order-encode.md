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

## Shadow for every page, judged against the final order (spec 133)

The flow shadow of 130 only sees pages whose orders pass through the bot's Sheet step. This part covers **every page**: the Likha import
(`ImportLikhaFromGoogleSheet`, tab TO WEBSITE A2:I) already receives all orders, so when it **creates** a new `macro_output` row it also
queues a shadow encode of that row. The import never waits for it and never fails because of it (dispatch only, errors are reported and
the import goes on). Updates of existing rows encode nothing.

| Setting (`app_settings`) | Default | Meaning |
|---|---|---|
| `order_encode_import_shadow` | `off` | `on` turns the import hook on (global; Incepxion runs the same code and stays off until the owner says) |
| `order_encode_import_daily_cap` | 2000 | most import encodes queued per day; past it orders are skipped and counted (`order_encode_import_skipped` keeps today's counts) |
| `order_encode_import_skip_pages` | empty | comma list of page names (case-insensitive) that are never encoded |

All three are edited on `/order-encode/final` by the CEO only (no history: the project has no history table for app settings).

**The row.** `order_encodes` with `mode = import`, `spreadsheet_id` = the order spreadsheet, `tab` = `All Orders`, `row_number` = the sheet row (TO WEBSITE row =
All Orders row), `macro_output_id` = the new row, `input.row` = the raw cells A to H exactly as read, `input.source = import`. If an `order_encodes` row with
that key exists already (the flow hook of 130 got there first) nothing is encoded again; only its `macro_output_id` is set. `EncodeOrder` reads
import rows by column order A to H (no header read) and writes `order_encodes` only, never `macro_output`.

**Compare with the final order.** `php artisan order-encode:compare-final` (every 15 minutes) takes `import` rows and `shadow` rows with a
`macro_output_id` that are `done` and not yet compared. It waits until the `macro_output` row is filled by the Macro import (STATUS, APP SCRIPT CHECKER or
PROVINCE not blank), or marks it `sheet_never_finished` after 72 hours (`order_encode.final_give_up_hours`). Per field it stores `same | different | final_blank | likha_blank`
for province, city, barangay (Ñ = N, case and spaces ignored), address line (lowercase, punctuation and repeated spaces folded), item, COD, `proceed` (Likha's final O = ✅
against `STATUS` = PROCEED) and `checker` (Likha's final O against APP SCRIPT CHECKER); it records `edited` and the `edited_*` flags set, and the STATUS at compare time.
Storage: the one nullable JSON column `order_encodes.final_compare` (migration `2026_11_05_100000_add_final_compare_to_order_encodes_table.php`, additive). The sheet compare's
`compare` / `compare_state` are not touched, so a flow shadow row can have both.

**Page.** `GET /order-encode/final` (tab "All pages (vs final order)" next to the sheet compare; CEO, Marketing - OIC, Marketing read it): the switch, cap, skip list, today's encoded and
skipped counts and the estimated GPT cost; date range and page filter; tiles (orders, % location equal, % address equal, % item and COD equal); the PROCEED table (final: PROCEED untouched /
PROCEED after staff edit / not PROCEED, against Likha would PROCEED / would hold); the per-page table (sortable); and the disagreements, Likha value (L) over the final value (F), the steps log on hover.

**Queue.** Same `encode` queue. A burst (an import can create hundreds of rows) just waits in the queue; the backlog estimate is in `docs/specs/133-encode-shadow-all-pages.result.md`.

## Safer rules and learned address notes (spec 135)

The backtest of 1,487 orders showed where the copy of the two scripts goes wrong. Spec 135 adds five rules and a memory of past corrections. Nothing here changes Validate, Astra, MacroChecker or the imports.

**Rule switch** (`app_settings.order_encode_rules`, set on `/order-encode/final` by the CEO): `improved` (default) or `sheet` (the exact spec-130 behaviour: no rule below applies, no `rules`/`hold` in the outputs, no extra step). With `improved` the outputs carry `rules`, `hold` (`{rule, reason}` or null) and `note_hit`, and the steps log the rule that fired (`brgy_fix` / `city_fix` with `rule: R1`, `rule_hold` `HOLD:R2|R4|R5`, `note_hit`).

| Rule | What it does |
|---|---|
| R1 | Before a GPT city or barangay fix: a written City / Brgy that equals (whole value, normalised) a city of the province / barangay of the city in the J&T list is kept and the GPT call is skipped. |
| R2 | A written province that resolves to a J&T province other than the result holds the order ("Province differs from what the customer wrote") and "Province only → ✅" does not apply. A written province that resolves to nothing does not count. |
| R3 | Among the candidates of the word-by-word match: the written barangay first, then the written city, then list order. |
| R4 | Barangays are listed and written as the J&T file spells them (no "BARANGAY …" rewrite on output; a GPT answer is written as the list label). The order is held ("Not a J&T address combination") unless the exact province / city / barangay is a line of `jnt_address.txt`. |
| R5 | Held ("Possible duplicate: <ids>") when another order of the same day (`macro_output.ts_date`, or an encode of the day not yet in `macro_output`) has the same phone, or the same name + barangay + city. A phone in the Validate whitelist is skipped; `CANNOT PROCEED` rows and the order itself are not counted. |

**Written keys.** The customer's "written" place is the Province / City / Brgy of the extracted details read by `Parse::addressParts` (spec 130). It is normalised by `Written`: lowercase, Ñ = N, punctuation off, `MacroChecker::normBrgyKey` / `normCityKey` as the base (roman numerals to digits, sta/sto/gen/pob/bgy/brgy expanded), then for a barangay the words brgy / barangay / bgy / pob / poblacion are dropped (kept if nothing else is left), and for a city the words city / municipality and a leading province name are dropped. `written_key` = `barangay|city|province` (each part cut at 80 characters, empty parts kept).

**Address notes** (`address_notes`, migration `2026_11_06_100000_create_address_notes_table.php`, additive): one row per `written_key` with the J&T answer, `source` (comma list of `staff_fix`, `astra_fix`, `delivered`, `manual`), `seen`, `delivered`, `conflict` + `conflicts` (the other answers with counts), `status` (`pending` / `approved` / `rejected`), the review fields, `order_ids` (20 examples) and a `ledger` (order id → answer:delivered, so an order is counted once). No names or phone numbers.

- `php artisan order-encode:notes-build --days=60` (scheduled daily 05:30 Manila with `--days=3`) reads orders with a PROVINCE / CITY / BARANGAY line in HISTORICAL LOGS (staff), `ai_checker_logs.outcome = 'fixed'` (Astra / AI Fix) and `from_jnts.status = 'Delivered'` (by `updated_at`) and adds them to the notes if the final PROVINCE / CITY / BARANGAY is a J&T combination. Safe to repeat.
- **Auto-approve:** a pending, never-reviewed note becomes `approved` (reviewed_by `auto`) when delivered ≥ 2, there is no conflict and the sources include `staff_fix` or `astra_fix`. If it later gets a conflict it goes back to pending. A note with `reviewed_at` (a person) is never changed by the build.
- **Use:** with `improved`, an approved note for the exact written key sets province / city / barangay, skips the GPT location fixes and logs `NOTE_HIT` (the checker, R2 and R4 still run, then R5); otherwise up to five approved notes with the same written city or province (closest by shared words) go into the GPT fix prompts as "Past corrections (customer wrote → correct J&T address)". Lookups use the indexes of `address_notes` only.
- **Review:** `/order-encode/notes` (CEO only): search, filters (status, source, conflict, delivered ≥ n, seen ≥ n), the examples (links to Checker 1 on the order's date), the conflict breakdown, approve / reject / edit the answer (only to a J&T combination) with a note, bulk approve (ticked, or the pending notes without a conflict that match the filter). `php artisan order-encode:notes-review --approve-where="source=staff_fix,conflict=no,delivered>=2" --reviewer="Name" [--note=…] [--dry-run]` does the same from the command line; notes with a conflict are never approved in bulk.
- A held order in `live` mode is written to `macro_output` with APP SCRIPT CHECKER `TO FIX`, no STATUS and `HOLD R…: reason` in AI ANALYZE (a ✅ there would let the Validate button make it PROCEED).
- `/order-encode/final` shows the rules switch, the NOTE_HIT and "held by R…" counts, and both on each disagreement.

**Backtest.** `php artisan order-encode:backtest --source-mode=backtest2 [--limit=N]` re-encodes the stored inputs of an earlier test (rows with that mode) under the current rules into a new mode `backtest-<time>` (`tab` = the new mode, so the unique key does not clash), reading the cells A to H as an import row (no Google read). It prints, for Likha and for `tmp_sheet_out` (skipped if the table is missing): orders, PROCEED, correct, wrong, pending and blank address, all after `MacroChecker::validateRow` (the same arguments as the Validate button, phone as 10 digits). Real GPT calls happen only where the OpenAI key is set (the server).
