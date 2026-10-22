# Brief 106: the page check also catches the wrong item, the wrong price and the wrong ad video

## Why

`/chatbot/pages` already has a Check column (spec 099, `ChatbotPageCheck`): missing values, broken images, Seq/Seq Img pairs, unknown variables. It cannot tell that a page's content is about the WRONG product: a Main Flow written for another item, a PICTURE of another item, a price that does not match what the page actually sells, or an ad video for a different item. The owner, 2026-10-21: "baka kasi maling main flow, maling item, mali sa video ng ads".

What the app already knows per page (verify each in code before relying on it):
- the page's botfield values (`PageSettings`, provider `chatbot`: Main Flow, Main Flow 2, Fill-Up Form, PICTURE, Seq N, Seq Img N …), with version ids (spec 099/100);
- the item the page sells and its price: orders in `macro_output` (`PAGE`, `ITEM_NAME`, `COD`, `ts_date`), the CPP page's per-page "mode pair" of item + COD (`CPPController`, `item_pairs`), `ads_manager_reports.item_name` and campaign/ad names per page, and `/owner/private` item settings (`page_item_settings`: page_name, item_name, promo, mode_cod_int, effective_date);
- ad video analyses in `gpt_video_analyses` (file_name, file_sha256, transcript, summary, item_name, description, model_used, cost_estimate_php) — find whether and how a video links to an ad or page (e.g. ad creative video id, file name, `ad_campaign_creatives`); if there is no reliable link, say so in the plan and build the video check only for the links that exist.
- the app's existing OpenAI integration (find how other features call it, which model, and how costs are logged, e.g. `ai_checker_logs`).

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **The page's expected item and price** = the most common ITEM_NAME + COD of its orders in the last 3 days (`macro_output`), falling back to the latest ads `item_name` and then `page_item_settings`. Show which source was used. | Ground truth is what customers actually order. |
| **Text check (AI, cached):** read Main Flow, Main Flow 2 and Fill-Up Form, extract the item name(s) and price(s) they promise (AI with a strict JSON answer; cheap model). Flag when the item clearly differs from the expected item (allow synonyms/variants: the AI judges "same product?" with a confidence) and when the stated item price differs from the order COD minus shipping/insurance (read how Main Flow lists Item Price / Shipping / Insurance; compare like with like). | Wrong Main Flow, wrong price. |
| **Image check (AI vision, cached):** PICTURE (and Seq Img N, max 5 per page) — does the image show the expected item? | Wrong picture. |
| **Ad video check:** for the page's ads whose video has a `gpt_video_analyses` row, compare that analysis's item_name/summary with the expected item. No new video analysis in this spec. | Wrong ad video, using what exists. |
| **Results join the existing Check column** as new reasons, e.g. "Main Flow: SEAT COVER ≠ orders: FLEXIBLE CLIP PHONE HOLDER (90%)", "Price ₱199 ≠ orders ₱299 COD", "PICTURE: looks like LED STRIP, orders: SEAT COVER", "Ad video 'xyz.mp4': NIGHT LAMP ≠ SEAT COVER". Low-confidence results show as a warning (amber), not an X. | One place to look. |
| **Cost and load:** each AI answer is cached by (value version id or image URL hash, expected item); re-asked only when the value or the expected item changes. A full run over all pages is a queued job (`chatbot:content-check`, also nightly), never inside a page view. Log AI cost the way existing AI features do; print the estimated cost of a full run in the result. | Cheap and fast pages. |
| **Read-only:** never changes page values. JSON for Mira: include the content findings in the existing check JSON / an artisan `--json` output. | Mira's analysis. |

## Done when

- Tests (AI faked): expected item/price from orders, fallbacks, and the shown source; text mismatch → X, synonym → pass, low confidence → warning; price comparison like-with-like; image check result shown; video check with and without a link; cache hit/miss by version; the job and the nightly schedule; JSON output shape.
- Browser check of `/chatbot/pages` Check column with the new reasons at 1366 and 390 px.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, plus the new tests; the full suite adds no new failures.
- The result lists: how videos link to ads (or why not), the model(s) and the estimated cost of a full run, any migration (its release needs the owner's yes), and the commits.
