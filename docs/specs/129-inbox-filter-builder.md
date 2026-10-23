# Brief 129: the chatbot Inbox gets a condition-based filter builder

/chatbot/inbox (`resources/views/chatbot/inbox.blade.php`, `App\Services\Chatbot\Inbox::conversations()`, spec 126 added a Status select: Ordered, Loop 1/2/3, Reply failed, Phone no order, with counts). The owner, 2026-10-23: "ayusin mo naman filter, yung meron din maseset na condition, ex: gpt phone number has value, ordered date etc?"

## Decisions
| Decision | Why |
|---|---|
| **Filter builder** under the existing filters: "+ Condition" adds a row; all rows must match (AND). Conditions: **Customer field** (select from the field keys that exist for the page in `chatbot_customer_values`, shown by their display names, e.g. GPT PHONE NUMBER, CHAT STATUS, CXD) with operators **has value / is empty / is / contains / doesn't contain** (case-insensitive, trimmed); **Ordered** yes/no with optional **ordered date from–to** (same order source as Results / spec 126); **Last message date** from–to; **First chat date** from–to; **Last bot reply** sent / failed; **Human / Bot** (same meaning as the tabs). | Flexible follow-up lists. |
| The spec-126 Status options stay as one-click presets (they just fill the builder or keep working as today — your choice, document it). Results show the **count**. Combine with the page, search and window controls. | Keep what works. |
| Performance: one query plan per page, no N+1; field conditions via EXISTS/NOT EXISTS on `chatbot_customer_values` (page+psid+key); fine on a page with tens of thousands of chats. Validate every input server-side (allowed operators/keys only, dates parsed, max 10 conditions). | Safe and fast. |
| Remember the last filter per page in the browser (localStorage, wrapped in try/catch). No server-side saved views in this spec. No migration. English UI text. | Lean. |

## Done when
- Tests: each operator on a customer field (has value, empty incl. missing row, is, contains, doesn't contain), ordered yes/no with date range, last/first message date ranges, last bot reply failed vs sent, human/bot, AND of several conditions, invalid key/operator rejected, counts match the list, spec-126 presets still return the same customers.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` pass.
- Result: files, the request format of the conditions, commits.
