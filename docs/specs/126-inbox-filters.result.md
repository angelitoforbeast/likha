# Result: 126 Inbox filters

## Files
- `app/Services/Chatbot/Inbox.php`: `conversations()` takes a `$status`; new `listWithCounts()` returns `rows` and `counts`; new private `statusSets()`; `STATUSES` constant.
- `app/Http/Controllers/ChatbotInboxController.php`: reads `status` (whitelisted against `Inbox::STATUSES`), returns `{rows, counts}`.
- `resources/views/chatbot/inbox.blade.php`: Status select; option labels show the count, e.g. "Ordered (23)".
- `tests/Feature/Chatblast/ChatbotInboxTest.php`: `S-126.1` test, plus the events migration in setUp.

## How each status is computed (for the customers of the list after page, window, search and tab)
- Ordered: `chatbot_events` type `order`, `created_at` >= window start (same source as Results).
- Loop 1/2/3: `chatbot_customer_values` key `chat status`, value lowercased with whitespace removed equals `loop1`/`loop2`/`loop3`.
- Reply failed: latest `chatbot_replies` kind `send` id per psid in the window has status `failed`.
- Phone, no order: `chatbot_customer_values` key `gpt phone number` non-blank, minus Ordered.
- Counts: from the same sets, so they equal the list length for that status. Per 500-customer chunk: one events query, one values query, two replies queries. Standby rows excluded as before.

## Case table
| Case | Test | Result |
|---|---|---|
| S-126.1 each status, counts, tab and search narrowing | `test_S_126_1_status_filter_returns_the_right_customers_with_matching_counts` | pass |

Full run: Chatblast 713 tests, PageSettings 140, Botcake files all pass, except `BotcakePsidScheduleTest.php`, which has a BOM before `<?php` on main (fatal parse error, not from this work; suggest removing the BOM).

## Notes
- The counts cover the customers in the list (up to 500 per table, as the list already is), not every chat on the page.
- No `qa/stories.md` entry added (brief carried none).
