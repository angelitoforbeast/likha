# Brief 145: the chatbot page checker also checks SHOP DETAILS, prices/items in prompts and sequences, and the bot setup

## Why
On /chatbot/pages the CHECK column (value checks plus `app/Services/Chatbot/PageContentCheck.php`: Main Flow texts by AI, form fields, images, videos, price vs COD; nightly `chatbot:content-check` 02:40 and "Check again") missed: Tech Flask Ph's SHOP DETAILS says "PAGE: Vanessa Reyes | ITEM: CHRISTMAS LIGHTS" while its ITEM NAME IN JNT DASHBOARD is UNISEX SOCKS (SHOP DETAILS goes into the order). On incepxion.pro, Daily Selection had the bot On but no "Chat to site" block (no `chat.key`) and later no flow, so customers got no reply while the column said "All values pass". The owner: "gawin ang 1 to 3".

## Decisions
| Decision | Why |
|---|---|
| **1. SHOP DETAILS check (no AI)**: parse the `shop_details` value's `PAGE:`, `ITEM:`, `PRICE:`, `QUANTITY:` lines (case-insensitive, ₱ and commas tolerated). X when PAGE ≠ the page name, ITEM ≠ ITEM NAME IN JNT DASHBOARD (case/space-insensitive), PRICE ≠ the PRICE field, or QUANTITY ≠ the QUANTITY field; missing SHOP DETAILS or a missing line = warning. Each finding names the field and the two values. Runs with the value checks (on save, on "Check again", and in the nightly run). | Catches the Tech Flask case. |
| **2. Price and item in Prompt 1–3 and Seq 1..N** (`prompt_*`, `seq_*` texts): find peso amounts and item mentions; X when a peso amount that looks like the selling price/total contradicts PRICE (or PRICE + shipping total shown elsewhere on the page) or an item clearly different from the page's item is named. Do it the way Main Flow texts are checked today (reuse `PageContentCheck` text checking and its AI only for new/changed values, same model and cost logging), so the nightly run asks the AI only about changed texts. | Wrong prices in sequences reach customers. |
| **3. Bot setup check (no AI)**: when the page's bot is On: X if it has no active flow (`chatbot.flow_id`); for pages whose replies go through Botcake, X if there is no `botcake.chat.key` (the "Chat to site" block was never turned on). Finding text says what to do ("pick a flow", "turn the chat block on with the bot toggle"). | Daily Selection case. |
| Findings appear in the existing CHECK column and its detail with the existing levels (ok / warn / x); English text; no horizontal scroll. Nothing else on the page changes. | Same UI. |

## Cases (S-145 in qa/stories.md with tests)
P1: .1 SHOP DETAILS with another page's PAGE and ITEM → X naming both; matching → ok; .2 PRICE/QUANTITY mismatch → X; .3 missing SHOP DETAILS → warn; .4 a Seq text with a wrong price → X (fake AI); a correct one → ok; unchanged texts not re-asked at night; .5 a Prompt naming a different item → X; .6 bot On with no flow → X; Botcake page bot On without chat.key → X; bot Off → no setup finding; .7 the nightly command includes the new checks. P2: .8 ₱1,198 and "198 pesos" parse; .9 Forbeast-gateway pages skip the chat.key check.

## Done when
- P1 tests pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` adds no new failures.
- Result: files, the parsing rules, AI usage/cost note, evidence, commits. End with "145: <done|blocked> — <one line>".

## Out of scope
Fixing any page's values; deploying.
