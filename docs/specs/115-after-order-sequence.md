# Brief 115: an "After Order" sequence starts by itself when the customer's order is uploaded

The owner, 2026-10-22: a new "After Order Sequence" with 4 messages from new page values; it starts when the customer's order is uploaded; it must NOT stop when the customer replies; it may only send within 24 hours after the customer's last interaction; empty values send nothing ("matic naman na to sa sequence … yung preorder, ganto na"). Also: Seq 11–20 are optional ("no need namang may value").

The sequence engine already exists (`ChatbotSequences`, `chatbot_sequences`, `chatbot_sequence_steps`, `chatbot_sequence_subscriptions`, `RunChatbotSequenceStep`, `chatbot:sequence-tick`, exit on reply via `ChatbotSequences::exitOnReply`). Orders live in `macro_output` (`PAGE`, `botcake_psid`, `ts_date`, `id`, created_at). Botfields are page values (`PageSettings` provider `chatbot`), added as columns on `/chatbot/pages`; steps send `{{After Order 1}}` etc. like Seq steps send `{{Seq N}}`.

## Decisions
| Decision | Why |
|---|---|
| **Sequence trigger "Order uploaded":** a sequence can be marked to start when an order is uploaded (a field on the sequence, set in the Sequences UI). A light scheduled job (every minute, plus a hook in the order import path if there is one cheap place) finds new `macro_output` rows since its last mark that have a `botcake_psid` and a `PAGE` with the bot on, and subscribes that customer (page_id from the page's `botcake.page_id`, psid) to that page's order-triggered sequence(s) — once per order row (dedupe on the order id). | Starts by itself. |
| **Per-sequence "Stop when the customer replies"** (default ON = today's behaviour); OFF for After Order: `exitOnReply` skips sequences with it off. | "hindi titigil". |
| **24-hour window guard for every sequence step:** before sending, if more than 24 h have passed since the customer's last message on that page (`botcake_chats.received_at`, latest), skip the step and end the subscription with exit reason `outside 24h` (logged, visible in the subscription list). | Facebook's window; the owner's rule. |
| **Empty value = nothing sent** for that step (no blank message), as Seq steps already do — verify. | Optional fields. |
| **Check column (`ChatbotPageCheck`):** Seq 11–20 / Seq Img 11–20 and After Order 1–4 / After Order Img 1–4 never count as Missing. (Small change in the missing-values rule only.) | Optional. |
| Data: nullable columns on `chatbot_sequences` (trigger, stop_on_reply) — migration allowed (nullable/defaulted only); say so in the result. | Release needs the owner's yes. |

## Done when
- Tests: a new macro_output row for a bot customer subscribes them once to the order-triggered sequence; not for a page with the bot off or no psid; a reply does not exit a stop_on_reply=false sequence but does exit a default one; a step past 24 h is skipped and the subscription ends with `outside 24h`; empty value sends nothing; Seq 11–20 and After Order fields not reported Missing.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes.
- Result: migration, the UI places, commits.
