# Brief 093: {{ALL USER INPUT}} holds the customer's messages, as in Chatbot Builder

## Why

The live flow "Main Flow (from CBB)" was copied from Chatbot Builder, where `ALL USER INPUT` is filled by the platform with everything the customer typed.
- In Likha it is only a botfield or customer-field name, which nothing fills, so it is always empty.
- On 2026-10-20 the owner ordered on page Joy Villaluna:
  - AI step n22 (system "extract the phone number", user `{{ALL USER INPUT}}`) got an empty question and gave an empty answer ("OpenAI gave an empty answer"), so `gpt phone number` stayed empty.
  - The Google Sheet row (n43) had empty PHONE NUMBER and ALL USER INPUT columns.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **A built-in variable.** `{{ALL USER INPUT}}`, matched case-insensitively like the other variables (also `{{all_user_input}}`), resolves to the customer's own messages on this page from `botcake_chats`, oldest first, joined with a newline, up to and including the current chat. | CBB parity. |
| **Limits.** At most the last 50 messages and 4,000 characters (keep the newest). Like `[Photo]` and 👍, the stored message text is used as is. | Bounded prompts and sheet cells. |
| **Precedence.** If a customer field or botfield named `ALL USER INPUT` has a non-empty value, that value wins, so a flow that sets it on purpose keeps working. Otherwise the built-in applies. | No surprise for flows that set it. |
| **Test runs.** In the Test tab the built-in uses the test customer's messages of that session, if spec 091's test history exists in the code. Otherwise it is the current test message. Say which in the result. | Test matches live. |
| **Editor.** `{{ALL USER INPUT}}` appears in the variable chips with the tooltip "All the customer's messages (built in)". | Discoverable. |
| **One query per run**, cached on the run (FlowRun), so many uses in one run cost one read. | Load. |

## Done when

- **Tests:**
  - the variable lists the customer's messages in order and stops at the current chat;
  - the 50 and 4,000 limits keep the newest;
  - another customer's or another page's messages are never included;
  - a non-empty field named ALL USER INPUT takes precedence;
  - the variable works in an AI user prompt (OpenAI faked: assert the request text) and in a sheet cell (formula guard applied);
  - one query per run.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, and the full suite adds no new failures.
- Report the commits.
