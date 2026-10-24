# Result 145: the page checker also checks SHOP DETAILS, prices/items in prompts and sequences, and the bot setup

Branch `feat/145-page-check-more`, from `7754345f`. Not pushed, not deployed.

## What shipped

| Part | Where | AI |
|---|---|---|
| 1. SHOP DETAILS check | `PageContentCheck::shop()`, run inside `PageContentCheck::run()` | none |
| 2. Price and item in Prompt 1–3 and Seq N | the text loop of `PageContentCheck::run()` (same prompt, cache, model and cost logging as Main Flow) | only for new or changed texts |
| 3. Bot setup check | `PageContentCheck::setup()`; `ChatbotPageCheck::run()` also uses it on the no-flow and bad-flow early returns | none |

Every new check runs wherever the content check runs: on each page view or save (`ChatbotPageCheck`, which puts the X findings into reasons and the warnings into warnings), on "Check again", in the nightly `chatbot:content-check` job, and in its `--json`. The UI did not change: the findings show up in the existing CHECK column and its detail, levels ok / warn / x, English text.

## Files

- `app/Services/Chatbot/PageContentCheck.php`: `setup()`, `shop()`, `peso()`, the Prompt/Seq texts in the text loop, and `price()` gets an optional PRICE field.
- `app/Services/Chatbot/ChatbotPageCheck.php`: bot On with no flow is now X (it used to be a warning), and the setup reasons are added when the run stops early.
- `app/Jobs/RunChatbotContentCheck.php`: comment only (what gets asked).
- `tests/Feature/Chatblast/ChatbotContentCheckTest.php`, `tests/Feature/Chatblast/ChatbotPageCheckTest.php`: S-145 tests; both setUps now give the two test pages a `chat.key`.
- `qa/stories.md`: S-145.

## Parsing rules

- **SHOP DETAILS** (the added `shop_details` column, with `{{Botfield}}` filled in): the first `PAGE:`, `ITEM:`, `PRICE:` and `QUANTITY:` line, case-insensitive. Each value runs to the next `|` or line break and is cut to 80 characters for display.
  - PAGE is compared with the page name after `pageKey` (case and spaces ignored).
  - ITEM is compared with ITEM NAME IN JNT DASHBOARD in upper case with spaces collapsed.
  - PRICE and QUANTITY are compared with the PRICE and QUANTITY fields by the first number (`peso()`). When either side has no number, the text is compared in upper case with spaces collapsed.
  - A mismatch is X, worded `SHOP DETAILS: ITEM CHRISTMAS LIGHTS ≠ ITEM NAME IN JNT DASHBOARD UNISEX SOCKS`.
  - No value, or a missing or empty line, is a warning.
  - A comparison is skipped when the page's field is empty or a `{{…}}` is still unfilled.
  - When nothing is wrong, one ok finding.
  - The check only runs when the SHOP DETAILS column exists, so installs without that column get no warning.
- **`peso()`**: the first `\d[\d,]*(\.\d+)?` in the text, with the commas dropped. `₱1,198` gives 1198, `198 pesos` gives 198, `PHP 1,299.50` gives 1299.5, `free` gives null.
- **Prompt 1–3 and Seq N** (`prompt_1..3`, `seq_N`, every one that has a value):
  - The AI answer is handled as for Main Flow: another item at confidence ≥ 70 is X, below 70 is a warning.
  - When the text names no item (empty `found_item`), there is no item finding.
  - The price passes when an offer's total, or item price plus stated fees, matches the order COD (±₱1), or when the item price alone matches the PRICE field (±₱1). Otherwise it is X, e.g. `Seq 1: price ₱399 ≠ orders ₱299 COD, PRICE ₱249`. A text with no price gets no price finding.
  - Main Flow keeps its old price rule, COD only.
- **Setup** (bot On, meaning `chatbot.active` ≠ `0`, as the toggle shows it):
  - Empty `chatbot.flow_id` is X: "No flow: pick a flow in FLOW".
  - On a page that is not on the Forbeast Gateway (`chatbot.provider` ≠ `gateway`), no `botcake.chat.key` is X: "No chat block (chat.key): turn the chat block on with the bot toggle".
  - Bot Off: no setup finding.

## AI usage and cost

The Prompt and Seq texts use the same `TEXT_PROMPT`, the same `ContentAi` model, and the same `chatbot_content_answers` cache (key: kind, setting version id plus text hash, expected item), with model, tokens and cost logged per answer. Only new or changed texts, or a new expected item, are asked. A page view never asks; the nightly job and "Check again" do.

The first nightly run after deploy asks once for each filled Prompt and Seq on every page (up to 23 more texts per page, at about 1000 + 100 tokens each, about $0.0002 each in the test fakes' cost formula). After that, only edits are asked. The job's 1300-second budget still applies: pages it does not reach are done the next night.

## Case table

Run: `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast/ChatbotContentCheckTest.php tests/Feature/Chatblast/ChatbotPageCheckTest.php --filter S_145`

| Case | Test | Result |
|---|---|---|
| S-145.1 | `ChatbotContentCheckTest::test_S_145_1_shop_details_naming_another_page_and_item_is_an_x_and_a_matching_one_passes` | pass |
| S-145.2 | `test_S_145_2_a_shop_details_price_or_quantity_other_than_the_fields_is_an_x` | pass |
| S-145.3 | `test_S_145_3_no_shop_details_or_a_missing_line_is_a_warning` | pass |
| S-145.4 | `test_S_145_4_a_seq_price_other_than_the_cod_and_price_is_an_x_and_unchanged_texts_are_not_asked_again` | pass |
| S-145.5 | `test_S_145_5_a_prompt_naming_another_item_is_an_x_and_a_seq_naming_no_item_gives_no_finding` | pass |
| S-145.6 | `ChatbotPageCheckTest::test_S_145_6_a_bot_that_is_on_needs_a_flow_and_on_botcake_the_chat_block` | pass |
| S-145.7 | `test_S_145_7_the_nightly_run_and_its_json_include_shop_details_seq_and_setup_findings` | pass |
| S-145.8 | `test_S_145_8_peso_amounts_parse` (4 rows) | pass |
| S-145.9 | `ChatbotPageCheckTest::test_S_145_9_a_forbeast_gateway_page_skips_the_chat_block_check` | pass |

Red runs, before the code: S-145.1–.5 and .7 failed with "Failed asserting that two arrays are identical" (no findings); .8 failed with "Call to undefined method PageContentCheck::peso()"; .6 failed with "bot On, no flow, no chat.key: Failed asserting that two arrays are identical". S-145.9 already passed before the code because there was no chat.key check at all; it now guards the gateway skip.

## Evidence

- `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings`: `OK, but there were issues! Tests: 1017, Assertions: 6974, PHPUnit Deprecations: 52, Skipped: 1`. No failures. The deprecations and the skip were there before this work.
- `php -l` is clean on every changed PHP file.

## Story changes

- S-120.1 and S-099.1 (bot On with no flow gave the warning "No flow: the bot only receives chats", status none): this is now X "No flow: pick a flow in FLOW", as decision 3 requires. Both tests were updated to match.
- Test fixtures: both test classes now give their pages a `chat.key`, so the new chat-block check does not change the older cases.

## Notes

- SHOP DETAILS is an added column (`chatbot.columns`), not a default one. When the column does not exist, nothing is checked and there is no warning.
- The setup and SHOP DETAILS findings are also in `chatbot:content-check --json`. It lists only the Botcake pages (`page_id` + `token`), as before.
- Suggestion for the owner, not done: Main Flow could also accept a match on the PRICE field, like Prompt and Seq now do.

## Commits

- `c523c0d5` docs: spec 145 page checker checks more
- `33a31baa` feat: page check covers SHOP DETAILS, Prompt and Seq prices and items, and the bot setup (spec 145)
- this result (docs)

145: done — SHOP DETAILS, Prompt/Seq price and item, and bot setup (flow, chat.key) checks added to the CHECK column and the nightly run; 1017 tests pass.
