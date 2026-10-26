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
have made (one row per customer per day, see spec 136 below). The job is `done` only after that row exists, so a failed write is retried. If the page is
not ready (no `compare_sheet`, no address file, no key), or the encode cannot be queued, or it ends `failed`, the row is
written to the sheet as before. The `encode` worker must be running before any page goes live.

## Deploy checklist (by hand, after the owner's yes)

1. `php artisan migrate --force` (adds `order_encodes`; nothing existing changes).
2. Supervisor program `deploy/supervisor/likha-encode.conf`: `php artisan queue:work database --queue=encode-live,encode --sleep=3 --tries=2 --timeout=600 --max-time=3600`.
   `encode-live` is the express lane: the encodes of a customer who is waiting (the live encode of the legacy step and of a live Sheet step,
   and every Encode order step). A worker always takes it before `encode` (shadow, import). The Astra worker (`likha-astra`, see
   `docs/night-run.md`) likewise takes `--queue=astra-live,astra`: `astra-live` has the Astra of the Encode order step and `RunSpotAstra`.
   The queue names are in `config/order_encode.php` (`queues`).
3. `php artisan config:clear` so `services.openai.order_encode_key` is read; set `ORDER_ENCODE_OPENAI_KEY` (or rely on `OPENAI_API_KEY`).
4. Set `compare_sheet` for the page, then set `mode` = `shadow`.

## The Encode order step (spec 132; "Encode order (legacy)" since spec 137)

The way to go live is a step in the bot flow: **Encode order** (flow builder → Add → Encode order). It replaces the Google Sheet step for
orders. It works whatever the page's `order_encode.mode` is (the page setting stays `off` or `shadow`; `live` is still accepted but
means nothing for the step). No flow uses it until someone adds it and publishes.

Config (all in the step's panel): the order fields `date, page, name, phone, input, shop, extracted, price` (A to H of the row; the same
variables as the Sheet step; I is left empty), `also_sheet` (write the row to the Google Sheet too, default off) with `sheet` and `tab`,
`on_failure` = `sheet` (write the row to `sheet` instead; needs a link, checked at Publish) or `continue` (go on without saving, with a warning),
and "Counts as an order". The models in use are shown read-only (`app_settings` keys above).

The page's `{{SHOP DETAILS}}` (the usual `shop` field) is built, not typed: `PAGE: <page>\nITEM: <ITEM NAME IN JNT DASHBOARD>\nPRICE: ₱<PRICE>\nQUANTITY: <QUANTITY>`
(`App\Services\Chatbot\ShopDetails`); a missing field leaves its line out and is an X in the page Check. An old typed `shop_details` row stays in
History but is never read; `php artisan chatbot:shop-details-diff` lists the pages where it differs from the built text.

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
| R5 | Only with `app_settings.order_encode_duplicate_hold` = `on` (default `off`, CEO only on `/order-encode/final`): held ("Possible duplicate: <ids>") when another order of the same day (`macro_output.ts_date`, or an encode of the day not yet in `macro_output`) has the same phone, or the same name + barangay + city. A phone in the Validate whitelist is skipped; `CANNOT PROCEED` rows and the order itself are not counted. With the setting `off` the order is not held (Validate catches duplicates); the found ids go to the steps as `duplicates_seen` (`ids`). |

**Written keys.** The customer's "written" place is the Province / City / Brgy of the extracted details read by `Parse::addressParts` (spec 130). It is normalised by `Written`: lowercase, Ñ = N, punctuation off, `MacroChecker::normBrgyKey` / `normCityKey` as the base (roman numerals to digits, sta/sto/gen/pob/bgy/brgy expanded), then for a barangay the words brgy / barangay / bgy / pob / poblacion are dropped (kept if nothing else is left), and for a city the words city / municipality and a leading province name are dropped. `written_key` = `barangay|city|province` (each part cut at 80 characters, empty parts kept).

**Address notes** (`address_notes`, migration `2026_11_06_100000_create_address_notes_table.php`, additive): one row per `written_key` with the J&T answer, `source` (comma list of `staff_fix`, `astra_fix`, `delivered`, `manual`), `seen`, `delivered`, `conflict` + `conflicts` (the other answers with counts), `status` (`pending` / `approved` / `rejected`), the review fields, `order_ids` (20 examples) and a `ledger` (order id → answer:delivered, so an order is counted once). No names or phone numbers.

- `php artisan order-encode:notes-build --days=60` (scheduled daily 05:30 Manila with `--days=3`) reads orders with a PROVINCE / CITY / BARANGAY line in HISTORICAL LOGS (staff), `ai_checker_logs.outcome = 'fixed'` (Astra / AI Fix) and `from_jnts.status = 'Delivered'` (by `updated_at`) and adds them to the notes if the final PROVINCE / CITY / BARANGAY is a J&T combination. Safe to repeat.
- **Auto-approve:** a pending, never-reviewed note becomes `approved` (reviewed_by `auto`) when delivered ≥ 2, there is no conflict and the sources include `staff_fix` or `astra_fix`. If it later gets a conflict it goes back to pending. A note with `reviewed_at` (a person) is never changed by the build.
- **Use:** with `improved`, an approved note for the exact written key sets province / city / barangay, skips the GPT location fixes and logs `NOTE_HIT` (the checker, R2 and R4 still run, then R5); otherwise up to five approved notes with the same written city or province (closest by shared words) go into the GPT fix prompts as "Past corrections (customer wrote → correct J&T address)". Lookups use the indexes of `address_notes` only.
- **Review:** `/order-encode/notes` (CEO only): search, filters (status, source, conflict, delivered ≥ n, seen ≥ n), the examples (links to Checker 1 on the order's date), the conflict breakdown, approve / reject / edit the answer (only to a J&T combination) with a note, bulk approve (ticked, or the pending notes without a conflict that match the filter). `php artisan order-encode:notes-review --approve-where="source=staff_fix,conflict=no,delivered>=2" --reviewer="Name" [--note=…] [--dry-run]` does the same from the command line; notes with a conflict are never approved in bulk.
- A held order in `live` mode is written to `macro_output` with APP SCRIPT CHECKER `TO FIX`, no STATUS and `HOLD R…: reason` in AI ANALYZE (a ✅ there would let the Validate button make it PROCEED).
- `/order-encode/final` shows the rules switch, the NOTE_HIT and "held by R…" counts, and both on each disagreement.

**Backtest.** `php artisan order-encode:backtest --source-mode=backtest2 [--limit=N]` re-encodes the stored inputs of an earlier test (rows with that mode) under the current rules into a new mode `backtest-<time>` (`tab` = the new mode, so the unique key does not clash), reading the cells A to H as an import row (no Google read). It prints, for Likha and for `tmp_sheet_out` (skipped if the table is missing): orders, PROCEED, correct, wrong, pending and blank address, all after `MacroChecker::validateRow` (the same arguments as the Validate button, phone as 10 digits). Real GPT calls happen only where the OpenAI key is set (the server).

## One row per customer per day, Astra on the spot, the address question (spec 136)

Applies to `live` orders (the Encode order step, and a `live` page's Sheet step). Shadow, import and Test-tab orders are unchanged.

**The customer's row.** A customer is PAGE + PSID (`macro_output.botcake_psid`); an imported row without a PSID matches on PAGE + fb_name
on the same `ts_date`. Before the encode (and so before R5), `EncodeOrder` looks for the customer's row:

| Row found | What happens |
|---|---|
| final on the order's day: STATUS set and not CANNOT PROCEED, or a waybill | **Not accepted.** The row is not touched; the order is `done` with the note "second order not accepted: row <id>" (on `/order-encode`, and an `encode_order` chat event `not_accepted`). No GPT, Astra, sequence or sheet. |
| open (STATUS blank) with an order time within 24 hours of this one (also across midnight) | **Linked** (`order_encodes.macro_output_id`, `input.linked`) and re-encoded into that row. TIMESTAMP and ts_date stay as they were. |
| none, or only CANNOT PROCEED rows | A new row; `ts_date` is the day of the order time (cell A, Manila). |

Runs of one customer never run side by side: `EncodeOrder` holds a cache lock per page + PSID (`order_encode.customer_lock_wait`, 240 s, then the
job retries). R5 skips the customer's own rows and encodes (same page + PSID) and uses the linked row's day.

**What an update changes.** ADDRESS, PROVINCE, CITY, BARANGAY, ITEM_NAME, COD, all_user_input, extracted_details / CXD, AI ANALYZE, APP SCRIPT CHECKER, and
STATUS to PROCEED when the encode PROCEEDs, in one UPDATE that only applies while STATUS is blank. A field with its `edited_*` flag or a person's line in
HISTORICAL LOGS is kept (and then no PROCEED for a place the encode did not check). Each changed field gets one HISTORICAL LOGS line `time|Likha encode|FIELD|old|new`;
the Checker 1 summary and `order-encode:notes-build` skip the user "Likha encode". A failed re-encode leaves the row as it was, writes no sheet row and ends
`failed` ("The re-encode left row <id> unchanged."). A failed first encode keeps the sheet fallback of spec 132.

**The conversation.** The input cell may be `{{ALL USER INPUT}}` (the customer's last 50 messages), so it can still hold an earlier order. Each run keeps what it
read (`input.row`). The encode reads the lines after the longest overlap with the previous run of the customer; a run linked to an open row adds them to that
row's earlier conversation (`input.fields.input`), and its extracted details to the earlier ones. The text is cut to the newest 8,000 characters.

**Astra on the spot.** A live order that ends without PROCEED (except an R5-only hold: Astra cannot fix a duplicate) queues `RunSpotAstra` (queue `astra`): the night run's `AiCheckerRowRunner` with Astra's own prompt and
rules, `only_when_status_blank`, source `spot`, user "Likha encode", one `ai_checker_logs` row. Transient OpenAI errors are retried (3 tries); the result is on the
order (`input.astra`, note "Astra on the spot: …"). A **row lock** (`macro-row:<id>`, cache) is held by it, by the encode's update and by `RunNightAstraRow`; the
night job waits up to `order_encode.night_row_lock_wait` (200 s), then ends the row `skipped` "Row busy". The night run still runs Astra on these rows.

**The address question.** After Astra (or right after the encode when Astra is off, or when Astra fails), a row still held **because of the address** (R2, R4, R6,
the address checker not ✅, Astra's final code not ✅, or a blank PROVINCE, CITY, BARANGAY or ADDRESS; never R5, never a PROCEED row) subscribes the customer to the
chosen sequence through `ChatbotSequences::subscribe` (via `order-encode`), at most once per row (`input.asked` on any order of the row). `already` counts as asked;
`refused` or no sequence leaves the row for staff with the reason as a note. The subscribe writes the `sequence` chat event.

| Setting (`app_settings`, `/order-encode/final`, CEO only) | Default | Meaning |
|---|---|---|
| `order_encode_duplicate_hold` | `off` | `on` = R5 holds a possible duplicate again; `off` = it only notes `duplicates_seen` in the steps |
| `order_encode_spot_astra` | `on` | `off` = no Astra on the spot (kill switch); the question is decided from the encode's hold |
| `order_encode_address_sequence` | empty | chatbot sequence id of the address question; empty = no question; an id that is not a sequence is refused |

**Sheet.** With "Also write to Google Sheet" the step writes one sheet row per customer per 24 hours: a later run of the customer is marked `sheet_written` and
neither the step nor the fallback writes it again.

No migration. Deploy: the `astra` worker (already running for the night run) also takes `RunSpotAstra`.

## Upload to macro, then Encode order (spec 137)

Two flow steps replace the one above for new flows. The old step stays as **Encode order (legacy)**: stored flows load, publish and run it as
in 136, but its palette button is hidden. Nothing converts a flow; the owner swaps the steps himself. Both new steps ignore the page's
`order_encode.mode`, write no Google Sheet and never subscribe a sequence on their own.

**Upload to macro** (`upload_macro`, ports next and failed) writes the raw order row at once, without encoding. It uses the A–H templates
of the old step.
- The row: TIMESTAMP, ts_date, PAGE, fb_name, FULL NAME (the FB name), PHONE NUMBER (the flow's phone, 09… when valid), ITEM_NAME/COD from
  the shop details (else the price cell), shop details, all_user_input (this order's messages, the 136 conversation rule), extracted
  details/CXD and botcake_psid. STATUS and the address stay blank.
- **Insert** always makes a new row. R5 does not run here.
- **Update** takes the customer's open row (PAGE + PSID, no STATUS, within 24 hours; the day from the order time, Manila) and appends this
  order's messages to its conversation. It changes only all_user_input, extracted_details and CXD, never a column with a person's log
  line, and each change gets a "Likha encode" log line.
  - A PROCEED, courier-status or waybill row of the order's day is never touched: the note "second order not accepted: row <id>" and an
    event, then next.
  - No open row: **If not found** Insert, or Skip (next, event `upload_skipped`).
  - Uploads of one customer run one at a time (the 136 customer lock, a short wait, then failed).
- The upload is idempotent per run + step. A DB error goes to failed and leaves no half row.
- Each upload is an `order_encodes` row with status `uploaded` and `input.upload` set: that is "the row of this run" for Encode order.
- "Counts as an order" counts only when a row was written or updated.

**Encode order** (`encode_macro`, ports next and failed; setting "Run Astra when the encode does not PROCEED", default on). Since spec
143 it never waits: it queues the encode in the background and the flow goes on with next at once, in any run (main flow or sequence
step). The result is read later with the `order_*` variables (below).
- The target row, the first that applies:
  - the row Upload to macro wrote in this run;
  - else the customer's open row: same PAGE and PSID, STATUS and waybill blank, TIMESTAMP within 24 hours (crossing midnight);
  - else failed with the note "No order row to encode"; nothing is queued.
- The row is final (PROCEED, a courier status or a waybill): nothing is changed, next.
- The row already has an encode queued or running (its Astra included): nothing is queued again, next ("already being encoded").
- No key or no address file: failed, nothing queued.
- Otherwise it queues `EncodeOrder` on `encode-live` and goes on with next. For the open-row fallback the encode uses the row's
  last upload that was not encoded yet, else a new `order_encodes` row (`input.upload` = `encode`) with the conversation of the row's last
  encode or upload (a row without one is read from its own columns).
- The encode is the 136 encode (improved rules, notes, R5 counting other customers only), run on the upload's conversation. It writes
  ADDRESS, PROVINCE, CITY, BARANGAY, AI ANALYZE, APP SCRIPT CHECKER and STATUS = PROCEED (first-encode rules) into that row only, plus:
  - **PHONE NUMBER:** the phone of the chat (`OrderPhone::forOrder`: the encode's phone, else the only number in the conversation, else in
    the extracted details, as 09XXXXXXXXX) when it is valid and differs.
  - **FULL NAME:** the name the encode read, in Unicode NFC, when it differs and is not worse. Worse means blank, with a digit, without a
    letter, "unknown"/"n/a", or only a part of the current name.
- The write skips staff-edited fields (`edited_*` or a person's log line), never blanks a filled field and changes nothing on a row with a
  STATUS or a waybill.
- When the row is not PROCEED (and the hold is not R5 alone), the box is on and `order_encode_spot_astra` is not `off`, Astra runs on the same row in its own job, `RunEncodeAstra`
  on the express lane `astra-live` (one try): the spot path (`AiCheckerRowRunner`, source `spot`, user "Likha encode", under the row lock
  without waiting). Its result is kept on the order (`input.astra`).
- A failed encode job leaves the row held for staff (`order_encodes` status `failed`).
- The Test tab notes "Test: would encode" and queues nothing.
- Publish shows a non-blocking hint when an Encode order has no Upload to macro before it (it then encodes the open row, or fails).
- Removed in spec 143: the wait (tickets in `chatbot_ai_requests`), "Wait up to" (an old `wait` key is dropped when the flow is saved and
  ignored when it runs) and the `encode_*` variables. A wait ticket left from before the deploy is still finished by the sweep; the run
  then just goes on with next.

**`order_*` variables** (`OrderVars`, spec 143): every flow (main and sequence) can read them in a Condition and in `{{...}}` templates
and AI prompts. They come from the customer's newest `macro_output` row on this page (PAGE = the page name, `botcake_psid` = the
customer's PSID, `ts_date` within the last 7 days; the newest row of the last 24 hours is that same row whenever there is one), read once
per run and again after Upload to macro or Encode order. With no row they are blank and `order_encode_state` is `none`. They are read only in a flow whose steps or botfields name one of them, so other runs make no extra query. While a new encode is queued or running, the other variables still show the row as it is (the previous result): read `order_encode_state` = `done` first when the fresh result matters. A `queued` or `running` encode that has not moved for 30 minutes (a lost job) counts as `failed` and the row can be queued again.

| Variable | Value |
|---|---|
| `order_row_id` | the row id |
| `order_status` | STATUS (PROCEED, CANNOT PROCEED or blank) |
| `order_checker` | APP SCRIPT CHECKER |
| `order_hold_reason` | blank when the row is final or not encoded yet; else the first of `address`, `duplicate`, `name`, `phone`, `price`, `other`: from the row's last encode (and its Astra) by the 137 rule, or, for a row without one, from "HOLD Rn" in AI ANALYZE and APP SCRIPT CHECKER |
| `order_province`, `order_city`, `order_barangay`, `order_address`, `order_name`, `order_phone` | PROVINCE, CITY, BARANGAY, ADDRESS, FULL NAME, PHONE NUMBER |
| `order_encode_state` | from the row's latest live `order_encodes` row: `none` (none, or an upload not encoded yet), `queued`, `running` (the encode job started, or its Astra is queued or running), `done`, `failed` |

The builder's variable picker lists them under "Order". Their values are never logged.

No migration. The setting `order_encode_address_sequence` (the 136 address question) is used by the legacy step only.
