# Brief 049: card counter-check of Meta ad payments against the card statement

## Why

Meta ad payments are imported every day (~10:00, by an automation account) from Meta's "Invoice Summary" CSV into `payment_activity_ads_manager` (date, transaction_id, amount, ad_account, payment_method like "Visa ···· 5003"). The owner wants to check them against what the credit cards were actually charged: upload the card statement (PDF, or a screenshot), and see what tallies and what doesn't. A manual test on 2026-10-18 with an RCBC statement screenshot (lines like `09/09/26 09/10/26 FACEBK *34SCA52HS4 DUBLIN IR 6,278.17`) matched 13 of 13 non-100 charges to Meta records of the same card by exact amount on the same date, and the 100.00 charges tallied by count per day (3, 4, 3, 1). The FACEBK code is NOT in Meta's export (it has only Date, Transaction ID, Amount, Currency), so there is no shared id.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| A page `/ads_manager/payment/reconcile`, **CEO only** (404 for every other role, even if checked in nav settings; do not use NavAccess here) | The owner: "ceo lang pwede magaccess" |
| Upload a card statement: PDF (text PDF: extract text with `smalot/pdfparser`, already in composer, or `pdftotext` if present) or an image (PNG/JPG screenshot: read it with the vision model the app already uses for OpenAI calls (see how existing code calls OpenAI; config/services.php), asking for strict JSON lines {sale_date, post_date, description, amount, is_credit}). Parse RCBC's layout first (SALE DATE, POST DATE, DESCRIPTION, AMOUNT; credits end with "-"; skip PREVIOUS STATEMENT BALANCE, CASH PAYMENT and other non-FACEBK lines but list them as "not ads"). Other banks: keep the parser per bank layout pluggable, with a generic fallback (any line with a date, a FACEBK description and an amount) | RCBC is the usual bank; others later |
| Card: the user picks which card the statement is for from the payment methods present in Meta records (e.g. "Visa ···· 5003"), and the page also suggests the card that matches most amounts. Full card numbers are never stored or shown: before anything is saved, any 13-19 digit run is masked to its last 4. The uploaded file itself is not kept after parsing (or deleted after 24 hours); only the parsed lines are stored | Privacy: statements show the full card number |
| Matching, per card: (1) exact amount on the same date (sale date first, then post date, then ±2 days), one-to-one, closest date first; (2) amounts that repeat on a day (e.g. 100.00) are tallied as a group per day: statement count vs Meta count, and their totals; (3) result groups: Tugma (matched), Nasa Meta pero wala sa card (Meta charge not found on the statement within the statement's date range), Nasa card pero wala sa Meta (FACEBK charge with no Meta record), and per-day totals (card total vs Meta total, difference). Only Meta records inside the statement's date range (first..last sale date) count for "wala sa card" | Tally without a shared id, as tested |
| Storage: a small new table for uploaded statements (card label, bank, period, uploaded_by, totals, status) and one for their parsed lines (sale_date, post_date, description with the FACEBK code, amount, is_credit, matched Meta record id or group key, match kind). Never alter `payment_activity_ads_manager` beyond what is needed (no ALTER on it in this spec) | Lean, auditable |
| The page: upload form; a list of uploaded statements (card, period, lines, matched %, difference total); a statement view like a clean sheet: summary chips (Tugma N · ₱X, Wala sa card N · ₱X, Wala sa Meta N · ₱X, 100-groups tallied/not), per-day table (date, card total, Meta total, difference highlighted), and the line table with status per line and the matched Meta transaction id/ad account; filters by status. A parse preview before saving, so a bad read of a screenshot can be seen and corrected (edit a line's amount/date inline before matching) | Easy to see what tallies |
| Also: list Meta records whose date is clearly wrong (e.g. a year beyond today: one live row has 2028-07-05, likely a day/month swap) in a small "suriin" box on the page; do not change them automatically | Data quality |

## Tests

RCBC text parsing (dates, credits with "-", skipped non-ads lines, masked card numbers), generic fallback, the vision path with a fake OpenAI answer (strict JSON, bad JSON rejected), card suggestion, matching (exact same day, ±2 days, one-to-one, 100.00 groups equal and unequal, totals per day), "wala sa card" limited to the statement's range, CEO only (404 for Marketing even when checked in nav settings), the file not kept. Red first where natural. Whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

Fetching Meta receipts or reference numbers; editing Meta records; other banks' exact layouts beyond the fallback.

## Final message

Commits; tables and migration; the parsers (PDF, image) and what they need on the server (pdftotext? OpenAI key name and model); the matching rules as built; the page; test lines and suite result; review findings and fixes; anything the brief got wrong.
