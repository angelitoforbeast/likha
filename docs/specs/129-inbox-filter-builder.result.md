# Result: 129 Inbox filter builder

## Files
- `app/Services/Chatbot/InboxFilters.php` (new): `parse()` validates the conditions; `apply()` adds them to the list queries.
- `app/Services/Chatbot/Inbox.php`: `conversations()` / `listWithCounts()` take `$conditions`; the result also has `total` (matches before the 200-row cut).
- `app/Http/Controllers/ChatbotInboxController.php`: reads `conditions` (422 with a short message when invalid); new `fieldKeys()`.
- `routes/web.php`: `GET /chatbot/inbox/field-keys` (`chatbot.inbox.field-keys`, throttled).
- `resources/views/chatbot/inbox.blade.php`: "+ Condition" builder under the Status select, "Clear conditions", count line ("N conversations (showing 200)"), last filter remembered per page in localStorage (try/catch).
- `tests/Feature/Chatblast/ChatbotInboxFilterBuilderTest.php` (new).

## Request format
`GET /chatbot/inbox/conversations?page_id=…&window=…&tab=…&status=…&q=…&conditions=<JSON list, max 10>`. Each item:
- `{"type":"field","key":"gpt phone number","op":"has_value|empty|is|contains|not_contains","value":"…"}` (value required for is/contains/not_contains, up to 200 chars; key is normalised like the field names; keys starting with `__` are internal and refused)
- `{"type":"ordered","value":"yes|no","from":"Y-m-d","to":"Y-m-d"}` (dates optional; without them the window is used, as the Status presets do)
- `{"type":"last_message","from":…,"to":…}` and `{"type":"first_chat","from":…,"to":…}` (at least one date)
- `{"type":"bot_reply","value":"sent|failed"}` (the latest bot send in the window)
- `{"type":"handler","value":"human|bot"}`

Invalid JSON, unknown type/operator/value, a bad date, from after to, an empty or `__` key, or more than 10 conditions: 422. `GET /chatbot/inbox/field-keys?page_id=…` returns `{keys:[{key,label}]}` (label = upper case, e.g. GPT PHONE NUMBER), cached 60 s.

## How it works
- All conditions combine (AND) with page, search, window and the Human/Bot tab, and with the Status preset.
- The Status presets are unchanged and stay as a separate select (they keep working as before and combine with the builder); their counts are over the customers left after the conditions.
- Field, ordered, bot reply, handler and first chat are EXISTS / NOT EXISTS added to both list queries (botcake_chats and chatbot_replies), so the 500-per-table limit applies after the filter. Missing field row counts as empty and as "doesn't contain". `is` compares the whole trimmed value, case-insensitive; `contains` uses an escaped LIKE (`%`, `_` are literal).
- Ordered uses chatbot_events type `order` in [from, to] or since the window start; first chat uses botcake_chats without standby rows (earliest chat inside the range).
- Last message date is the latest activity of the conversation (incoming or outgoing, as shown in the list) and is filtered on the collected rows, because it spans two tables; it is still limited by the time window.
- Casts for the psid comparison are branched for mysql / pgsql / sqlite. No migration.

## Case table
| Case | Test | Result |
|---|---|---|
| S-129.1 each field operator | `test_S_129_1_each_operator_on_a_customer_field` | pass |
| S-129.2 ordered yes/no with a date range | `test_S_129_2_ordered_yes_no_with_a_date_range` | pass |
| S-129.3 last message / first chat ranges | `test_S_129_3_last_message_and_first_chat_date_ranges` | pass |
| S-129.4 last bot reply, human/bot | `test_S_129_4_last_bot_reply_failed_vs_sent_and_human_bot` | pass |
| S-129.5 AND, tab, search, preset, counts and total | `test_S_129_5_conditions_are_anded_and_combine_with_the_tab_search_status_and_counts` | pass |
| S-129.6 invalid input rejected, field keys | `test_S_129_6_invalid_conditions_are_rejected_and_the_field_keys_are_listed` | pass |

Spec 126 presets: `ChatbotInboxTest::test_S_126_1…` still passes unchanged.
`vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 948 tests, OK (1 skipped, 52 deprecations, as before).

## Notes
- Not run in a browser: the builder script was only syntax-checked (`node --check`); please click through it once.
- No `qa/stories.md` entry (the brief carried none). The field-key list is not checked against the page on the server (an unknown key just matches nobody for "has value").
- Suggestion: the list still caps at 200 rows; an export of the matches would be the next step for follow-up lists.
