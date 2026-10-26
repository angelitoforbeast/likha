# Brief 158: SHOP DETAILS is built automatically from the page's fields

## Why
Orders take their item and COD from the page's SHOP DETAILS text (`LiveOrder::insertRaw` → `Parse::itemPrice`, `PRICE: ₱…`). Staff type SHOP DETAILS by hand, and it drifts from the page's own fields: on 2026-10-26, 27 of 33 bot pages matched the rule below; AllStock Mart said "REF CLEANER, 2" while its fields said "EASY REF CLEANER I, 1", and Tech Flask Ph named another page and item. In the owner's old builder SHOP DETAILS was a formula. The owner: "base na lang dun sa ibang value, wag na yung shop details … mali yata yun."

## Rule (exact)
```
PAGE: <page name as stored in page_settings.page_name>
ITEM: <botfield item_name_in_jnt_dashboard, trimmed>
PRICE: ₱<botfield price: digits/commas/decimal kept, a leading ₱/PHP/P and spaces removed, then "₱" prepended>
QUANTITY: <botfield quantity, trimmed>
```
Lines joined with "\n". Example: Alexa Angeles, GLOW TAPE, "₱198" or "198", 2 → `PAGE: Alexa Angeles\nITEM: GLOW TAPE\nPRICE: ₱198\nQUANTITY: 2`.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | SHOP DETAILS is **computed** wherever it is read for a page: the flow variables (`{{SHOP DETAILS}}` / `{{shop_details}}` in `ChatbotPage` botfields), order creation inputs, the content check, the paste mapping, exports. Implement it once (one service/method) and route every reader through it; a stored `shop_details` row is ignored. | One source of truth. |
| 2 | /chatbot/pages: the SHOP DETAILS column/modal field becomes **read-only** and shows the computed text with a note "Auto: ITEM NAME IN JNT DASHBOARD + PRICE + QUANTITY". It can't be edited, pasted into, or bulk-set; the paste mapping no longer offers it as a target. | No more hand-typed mistakes. |
| 3 | A missing part (no item, price or quantity) → the computed text still shows the parts it has, and the Check column shows an X "SHOP DETAILS: missing <field>" (orders would get no item/COD). | Visible gaps. |
| 4 | Keep the old stored `shop_details` rows (history; Changes still lists them) but never read them. A one-off read-only command `chatbot:shop-details-diff` lists pages where the old stored text differs from the computed one (page, old, new) so staff can double-check the fields. | Safe switch; the owner will have the fields checked. |
| 5 | Same code for Likha and Incepxion; no new table, no new setting rows. | Lean. |

## Cases (S-158 in qa/stories.md)
P1: .1 the rule for a normal page (price with and without ₱, with commas, "PHP 1,299" → `₱1,299`); .2 a flow run's `{{SHOP DETAILS}}` and the order created by "Upload to macro" use the computed text (ITEM_NAME "2 x GLOW TAPE", COD 198) even when a stale stored shop_details row says otherwise; .3 the pages grid/modal shows it read-only with the note; editing/pasting/bulk set refused (server-side too); .4 missing quantity → computed without it and a Check X; .5 the diff command lists a page whose old text differs and not one that matches; .6 Incepxion-style page (same code) works. P2: .7 390 px.

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` adds no new failures vs 816c5baa (record the baseline first). Result with the list of readers changed (file:line), evidence, commits; end with "158: <done|blocked> — <one line>".

## Out of scope
Changing ITEM NAME IN JNT DASHBOARD/PRICE/QUANTITY values; the Google Sheet imports (they keep reading the sheet's own columns); deploying.
