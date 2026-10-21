# Result 093: {{ALL USER INPUT}} holds the customer's messages

## What shipped

- `FlowRun::vars()` (the one place every variable is resolved: AI prompts, send text, conditions, sheet cells) adds a built-in `all user input` when the merged botfields and customer fields leave it empty (or spaces). FlowEngine is not touched.
- The built-in is this customer's (page + PSID) messages from `botcake_chats`, `id <=` the current chat, the newest 50, oldest first, joined with a newline; empty messages are skipped; then the last 4,000 characters are kept (`mb_substr(…, -4000)`, so the oldest kept message can be cut at the start). Stored text (`[Photo]`, 👍) is used as is.
- One read per run, cached on the FlowRun. The read happens only when the flow graph or a botfield mentions `all user input` (any case, space or `_`), so flows that don't use it cost no query.
- A run with no current chat (sequences, wait timeouts) takes the customer's newest messages up to now.
- **Test runs: the current test message.** Spec 091's test history is not in the code on `main`, so the Test tab gets only the message just typed.
- Editor: `ALL USER INPUT` is added to the run variables (`ChatbotFlowController::RUN_VARS`), so it shows as a chip; the chip has the tooltip "All the customer's messages (built in)" (inline script in `chatbot/editor.blade.php`, no Vite build needed).
- Sheet cells go through the existing `ChatbotVars::sheetCell`, so a cell with the variable always starts with `'` (never a formula).

## Case table

| Case | Test | Result |
|---|---|---|
| S-093.1 | `test_S_093_1_lists_this_customers_messages_on_this_page_in_order_up_to_the_current_chat` | Pass |
| S-093.2 | `test_S_093_2_the_50_messages_and_4000_characters_limits_keep_the_newest` | Pass |
| S-093.3 | `test_S_093_3_a_non_empty_field_or_botfield_named_all_user_input_takes_precedence` | Pass |
| S-093.4 | `test_S_093_4_works_in_an_ai_user_prompt_and_in_a_sheet_cell_with_one_query_per_run` | Pass |
| S-093.5 | `test_S_093_5_a_test_run_uses_the_current_test_message` | Pass |
| S-093.6 | `test_S_093_6_the_editor_shows_the_chip_with_its_tooltip` | Pass |

Red run: all 6 failed on the missing behaviour before the code (e.g. S-093.1 `Failed asserting that two strings are identical`, actual `''`).

## Runs

- `vendor/bin/phpunit tests/Feature/Chatblast/ChatbotAllUserInputTest.php`: OK (6 tests, 15 assertions).
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: OK, 717 tests, 4835 assertions, 1 skipped.
- Full suite: 2072 tests, 1 error, 1 failure, 4 skipped. Both are known and not from this change (same two in spec 015's result):
  - `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`: `storage/app/credentials.json` does not exist in a fresh worktree.
  - `ExampleTest::test_the_application_returns_a_successful_response`: 302, not 200 (the home page redirects).
- `php -l` clean on the changed PHP files.

## Story changes

- New section S-093 in `qa/stories.md`, written from the spec's "Done when" (the spec brought no stories; no story-writer run, the cases are the spec's list one to one, plus S-093.5 for the Test-tab decision and S-093.6 for the editor chip).

## Notes and suggestions

- No reviewer pass or plan gate ran: this was a small, briefed task started with "Start now". Risk: medium (business logic on an existing path; the customer text was already untrusted and goes only into prompts and guarded sheet cells).
- The test's helper flow must mention the variable, since the read only happens when the flow or a botfield uses it.
- When spec 091's test history lands, `FlowRun::allUserInput()` can read the test customer's session messages instead of the current message (one line in the `$this->test` branch).
- No browser check (no browser tools in this session).

## Commits

- `68ac9f5f` docs: brief for spec 093, ALL USER INPUT built-in variable
- `6828be4c` fix: {{ALL USER INPUT}} is the customer's own messages on the page, one read per run (spec 093)
- this commit: docs: result for spec 093
