# Result 115: an "After Order" sequence starts by itself when the order is uploaded

## What shipped

- **Stop on reply.** Already per sequence (`exit_on_reply`, default on, and `exitOnReply` already skips sequences with it off), so no new column for it. Pinned with a test.
- **24-hour guard.** A step with no customer message in the last 24 h now sends nothing and ends the subscription: status `unsubscribed`, exit reason `outside 24h`, event "step N skipped (outside 24h)". Before, it skipped the step and went on to the next one. The old engine test was changed to the new rule.
- **Empty value sends nothing.** Verified: the send node already drops items whose text is empty after variables; pinned with a test.
- **Fields.** Columns After Order Img 1–4 / After Order 1–4 on `/chatbot/pages` (after Seq 20, image before text like Seq), also in the Preview items. `{{After Order 1}}` etc. resolve like Seq.
- **Check column.** Seq 11–20, Seq Img 11–20 and every After Order field are never reported Missing (`ChatbotPageCheck::OPTIONAL`). Seq 1–10 are unchanged.

## Case table

| Case | Test | Result |
|---|---|---|
| Reply: keeps the no-exit sequence, exits the default | `…test_S_115_3…` | pass |
| Past 24 h: nothing sent, ends `outside 24h` | `…test_S_115_4…` | pass |
| Empty value sends nothing | `…test_S_115_5…` | pass |
| Seq 11–20 and After Order not Missing, Seq 10 is | `ChatbotPageCheckTest::test_S_115_7…` | pass |

Changed existing tests: `ChatbotSequenceEngineTest` S-078.2 (new 24 h rule), `ChatbotPagesTest` S-081 (new columns).

`vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 902 tests, all pass (1 skipped as before).

## Story changes

None (the brief carried no stories).

## Suggestions for the owner

- The older plan doc `docs/plans/070-chatbot-sequences.md` still describes the 24 h skip-and-continue; left as is.

## Amendment 1

The "Order uploaded" trigger was removed completely: the `starts_on` column and its migration (the branch has **no migration**), `subscribeNewOrders()` and its call in `chatbot:sequence-tick`, the `chatbot.sequence_order_mark` setting use, the editor checkbox, and tests S-115.1, S-115.2 and S-115.6. Kept: the 24 h guard (ends `outside 24h`), the After Order columns and `{{After Order N}}`, the optional Seq 11–20 / After Order check, and the empty-value and exit-on-reply tests. The result sections above that mention the trigger, the migration or the order job no longer apply.

Setup is now: create the sequence (4 steps sending `{{After Order N}}`, "Exit when the customer replies" off), then add a subscribe step to the flow that should start it. The page values are filled on `/chatbot/pages`.

Tests: `tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 898 tests, all pass (1 skipped as before).
