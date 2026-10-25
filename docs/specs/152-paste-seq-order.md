# Brief 152: the Edit modal paste puts follow-up texts and images into Seq 1…N in sheet order

## Why
On /chatbot/pages the Edit modal's paste box (spec 141: `PasteFieldAi`, `POST /chatbot/paste-map`, the preview with a dropdown per cell) asks the cheap content-check model to map each pasted cell to a field. On 2026-10-26 the owner pasted a row: the AI set Seq 1 for the first follow-up text, then marked the following follow-up texts "None (skip) · Not sure" (short emoji-led lines that look like benefit bullets), so Seq 2… stayed empty. The owner: follow-up texts must go to Seq 1 to N (any N), not only a few.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **Sheet order is the prior.** Send the AI, per cell, the field the header/position mapping would give (from "Starting at field" or the header line) as `position_guess`, and change the prompt: keep the position guess unless the cell clearly belongs elsewhere; the cells and header stay untrusted data. | The owner's sheets have a fixed column order. |
| 2 | **Sequence continuation (rule, after the AI answer):** once a text cell maps to `seq_k`, every following text cell that is unassigned or "none"/low and is not clearly another field (not an image URL, not a price/number, not the fill-up form) becomes `seq_{k+1}`, `seq_{k+2}`, … in sheet order, marked confidence "medium" with the note "in order after Seq k". Same for images: Picture, then `seq_img_1…N` in order. Works for any N. | "di lang 1 to 5, sequence 1 to n". |
| 3 | **More texts/images than Seq columns:** list them in the preview ("2 more follow-up texts: no Seq 11, Seq 12 column") with a button **"Add Seq 11–12"** that creates the missing botfield columns the same way "+ Column" does (same name pattern and kind as existing Seq/Seq Img columns, same permission), then maps the cells to them. Nothing is saved until the user's Apply + Save, except the new empty columns (say so in the button's confirm). | N can exceed today's columns. |
| 4 | **Stronger model for this mapping only**: a setting `chatbot_paste_map_model` (default the current OpenAI general model the app uses for quality tasks, e.g. the order-encode checker model setting's default; pick the existing config name, not a new key provider), falling back to the content-check model when unset; cost logged as today. | The cheap model mis-maps long rows; the call is rare. |
| 5 | **Item mismatch warning**: if the pasted texts name an item different from the page's item (ITEM NAME IN JNT DASHBOARD / SHOP DETAILS item, simple normalised word match), show a yellow note above Apply: "The pasted texts mention <X>; this page's item is <Y>. Check you copied the right row." No AI call for this. | The 2026-10-26 paste was BEDSHEET STRAP texts on a UNISEX SOCKS page. |

## Cases (add as S-152 to qa/stories.md, table like S-151)
P1: .1 a row whose follow-up texts the (fake) AI marks none after Seq 1 → preview shows Seq 2…k in order with the note; .2 images: Picture then Seq Img 1…N in order; .3 12 follow-up texts on a page with Seq 1–10 → 2 listed as missing with "Add Seq 11–12"; the button creates seq_11/seq_12 columns and maps them; nothing else saved before Save; .4 the position guess is sent to the AI and kept when the AI agrees; an AI answer that contradicts it with high confidence wins; .5 a price/number cell or the form text is never swept into Seq by the continuation rule; .6 the model setting is used, fallback to the content-check model, cost logged; .7 item mismatch note shown / not shown; .8 untrusted cells cannot write values or change other fields (regression of 141.7). P2: .9 390 px usable.

## Done when
- P1 cases pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` adds no new failures vs f4520a00.
- Result: files, the prompt change, the continuation rule, evidence per case, commits. End with "152: <done|blocked> — <one line>".

## Out of scope
Other modals; the sheet itself; deploying.
