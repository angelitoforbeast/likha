# Brief 126: Inbox filters — ordered, loop status, failed reply, phone without order — with counts

The chatbot Inbox (/chatbot/inbox; `resources/views/chatbot/inbox.blade.php`, `App\Services\Chatbot\Inbox::conversations($pageId, $window, $q, $tab)`) filters today by page, search (name/PSID), time window and tabs All / Human / Bot. The owner wants to find, per page: who ordered, where each customer is in the flow, whose last bot reply failed, and who gave a phone number but has no order yet.

## Decisions
| Decision | Why |
|---|---|
| **Filter "Status"** (one select, combinable with the existing tab, search and window): All · **Ordered** · **Loop 1** · **Loop 2** · **Loop 3** · **Reply failed** · **Phone, no order**. | One place, simple. |
| **Ordered** = the customer passed a send step marked "Counts as an order" in the window — the same source and rule the Results page uses (chatbot_events type `order`). | Same number as Results. |
| **Loop 1/2/3** = the customer's current `CHAT STATUS` field value (`CustomerFields`, values like "LOOP 1"; case/space-insensitive). | Where they are in the flow. |
| **Reply failed** = the customer's latest bot send (`chatbot_replies` kind send) in the window has status failed. | Who needs a human now. |
| **Phone, no order** = the customer has a non-empty `GPT PHONE NUMBER` field and is not Ordered in the window. | Follow-up list. |
| **Counts**: each option shows its count for the selected page + window (+ current tab/search), e.g. "Ordered (23)". One query per kind per chunk, index-friendly, no N+1. | See at a glance. |
| Respect standby rows (`BotcakeChat::active()`). English UI text. No migration. | Consistency. |

## Done when
- Tests: each status returns exactly the right customers on a fixture; counts match the lists; combining with the Human/Bot tab and search narrows correctly; existing Inbox tests pass.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` pass.
- Result: files, how each status is computed, commits.
