# Result 115: an "After Order" sequence starts by itself when the order is uploaded

## What shipped

- **Trigger "Order uploaded".** `chatbot_sequences.starts_on` (`order_uploaded` or null). `ChatbotSequences::subscribeNewOrders()` reads `macro_output` rows after a mark (`app_settings` key `chatbot.sequence_order_mark`, last processed id), keeps rows with a numeric `botcake_psid` and a `PAGE` that matches a page with a `botcake.page_id` and the bot On, and subscribes the customer (`enrolled_via` = `order`) to every active order-triggered sequence. One pass per order row (the mark only moves forward; a customer already active in the sequence is `already`). The first ever run only sets the mark to the current last id (old orders are never subscribed), and a row older than one day is skipped even if the mark was lost. It runs at the start of `chatbot:sequence-tick` (every minute, already scheduled), in a try/report so a failure cannot block due steps. No hook in the order import path: the minute job is cheap and the import has no single place.
- **Stop on reply.** Already per sequence (`exit_on_reply`, default on, and `exitOnReply` already skips sequences with it off), so no new column for it. Pinned with a test.
- **24-hour guard.** A step with no customer message in the last 24 h now sends nothing and ends the subscription: status `unsubscribed`, exit reason `outside 24h`, event "step N skipped (outside 24h)". Before, it skipped the step and went on to the next one. The old engine test was changed to the new rule.
- **Empty value sends nothing.** Verified: the send node already drops items whose text is empty after variables; pinned with a test.
- **Fields.** Columns After Order Img 1–4 / After Order 1–4 on `/chatbot/pages` (after Seq 20, image before text like Seq), also in the Preview items. `{{After Order 1}}` etc. resolve like Seq.
- **Check column.** Seq 11–20, Seq Img 11–20 and every After Order field are never reported Missing (`ChatbotPageCheck::OPTIONAL`). Seq 1–10 are unchanged.
- **UI places.** Sequence editor (`/chatbot/sequences/{id}`): new checkbox "Start by itself when the customer's order is uploaded". `/chatbot/pages`: the new columns. Nothing else.

## Migration

`2026_11_03_100000_add_starts_on_to_chatbot_sequences.php`: one nullable string column `starts_on` on `chatbot_sequences`, guarded by `hasTable`/`hasColumn`. Must be run by hand on release (`php artisan migrate --force`); the owner's yes first. Until it runs, the order job and the editor checkbox do nothing and saving a sequence still works.

## Setup the owner still does (data, not code)

Create the sequence "After Order Sequence" with 4 steps (a published flow each that sends `{{After Order Img N}}` / `{{After Order N}}`), tick "Start by itself…" and untick "Exit when the customer replies", then fill the page values.

## Case table

| Case | Test | Result |
|---|---|---|
| New order subscribes once; only new rows | `ChatbotAfterOrderSequenceTest::test_S_115_1…` | pass |
| Bot off / no psid / page without Botcake: nothing | `…test_S_115_2…` | pass |
| Reply: keeps the no-exit sequence, exits the default | `…test_S_115_3…` | pass |
| Past 24 h: nothing sent, ends `outside 24h` | `…test_S_115_4…` | pass |
| Empty value sends nothing | `…test_S_115_5…` | pass |
| Tick command subscribes then sends | `…test_S_115_6…` | pass |
| Seq 11–20 and After Order not Missing, Seq 10 is | `ChatbotPageCheckTest::test_S_115_7…` | pass |

Changed existing tests: `ChatbotSequenceEngineTest` S-078.2 (new 24 h rule), `ChatbotPagesTest` S-081 (new columns).

`vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 902 tests, all pass (1 skipped as before).

## Story changes

None (the brief carried no stories).

## Suggestions for the owner

- The older plan doc `docs/plans/070-chatbot-sequences.md` still describes the 24 h skip-and-continue; left as is.
- Red run: `test_S_115_1…` failed first with "Call to undefined method ChatbotSequences::subscribeNewOrders()"; `test_S_115_4…` with status `completed` instead of `unsubscribed`.
