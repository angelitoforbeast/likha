# Result 073: botfields hold long prompts

## Commits

| Commit | What |
|---|---|
| `c3161580` | docs: the brief, saved verbatim |
| `b1c3eaea` | fix: the new limits, tests and stories |
| (this commit) | docs: result |

## The limit chosen

**At most 50,000 characters and at most 60,000 bytes.** `ChatbotFields::MAX_VALUE` is 50000 and the new `ChatbotFields::MAX_BYTES` is 60000. Both are checked in the cell endpoint, and each has its own 422 message:
- over 50,000 characters: "At most 50000 characters."
- over 60,000 bytes: "Too long: at most 60000 bytes. Accented letters and emoji take 2 to 4 bytes each."

Why: MySQL `TEXT` holds 65,535 **bytes**, not characters. 50,000 characters of ñ, € or emoji would need 100–200 KB. With `strict => true` in `config/database.php`, MySQL would refuse the insert with a 500 error. On a server without strict mode it would cut the value silently. The byte check refuses the value first with a clear message, so a value is never cut. 60,000 bytes leaves a margin under 65,535. The owner's prompts are mostly ASCII, so 14,113 characters is about 14–15 KB, well inside. Botfields are not secret, so no encryption makes them longer. PostgreSQL `text` has no such cap.

## Every place a botfield value passes through

| Place | Cap before | Now |
|---|---|---|
| `ChatbotController::cell`: request read (`str(…, MAX_VALUE + 1)`) and the length check | 10,000 characters | 50,000 characters + 60,000 bytes |
| `PageSettings::validate` / `set` | no length check | unchanged |
| `page_settings.value` column | `TEXT` (65,535 bytes on MySQL) | unchanged, protected by the byte check |
| Request size: `public/.user.ini` sets `post_max_size = 200M` | far above | unchanged |
| /chatbot cell input and the long-text modal (`#long-text`) | no `maxlength` | unchanged |
| `ChatbotPage::botfields()` (reads the values for a run) | no cut | unchanged |
| `ChatbotVars::interpolate` (the `{{Prompt 2}}` expansion) | no cut; only the variable *name* is limited to 100 | unchanged |
| `FlowGraph::MAX_PROMPT` = 10,000 | **confirmed:** it cuts only the template typed in the AI step (`system`, `user`) when the flow is saved, not the expanded value | unchanged |
| `FlowEngine::ai` → `ChatbotAi::complete` | no cut on the system or user message | unchanged |
| The editor's AI-step textareas (`maxlength: 10000`) | the template only | unchanged |

The only real limit was `MAX_VALUE`. S-073.2 passed before the fix, which confirms that the engine passes the whole expanded prompt through.

Not checked: a web server body limit on production (for example nginx `client_max_body_size`, whose default is 1 MB). It is outside the repo, and a 60 KB value is far below that default.

## Case table

`vendor/bin/phpunit tests/Feature/Chatblast/ChatbotLongPromptTest.php`:

| Case | Test | Result |
|---|---|---|
| S-073.1 | `test_S_073_1_a_14113_character_botfield_is_stored_and_read_back_unchanged` | ✔ pass |
| S-073.2 | `test_S_073_2_the_ai_step_receives_the_full_expanded_prompt` | ✔ pass |
| S-073.3 | `test_S_073_3_a_value_over_the_limit_gets_422_with_a_clear_message` (over 50,000 characters; over 60,000 bytes) | ✔ ✔ pass |
| S-068.12 | the boundary moved from 10,000 / 10,001 to 50,000 / 50,001 | ✔ pass |

Red runs, before the fix:
- S-073.1: `Expected response status code [200] but received 422.`
- S-073.3, both data sets: `Failed asserting that two strings are equal.` (the old message)
- S-073.2 passed at once, because the engine never cut the value. It now pins that.

`tests/Feature/Chatblast`: 353 tests, 2163 assertions, all pass (PHPUnit deprecations only). I ran it with `vendor/bin/phpunit` rather than `php artisan test`: in this worktree `artisan test` warns on every test because the worktree has no environment file, and one must not be created.

## Story changes

- New section S-073 (S-073.1 to .3).
- S-068.12 now says 50,000 characters and 60,000 bytes instead of 10,000.

## Review

Low tier: one constant, one check and a message, on a route that was already validated. As the kit's low tier says, I ran the lint and tests instead of `skeptic-reviewer`: `php -l` on both changed PHP files, and the Chatblast suite. No findings.

## Notes

- The brief says `/chatbot/pages`; the botfield endpoint is `/chatbot/cell`. `/chatbot/pages` is "+ Page".
- No browser check: there was no screen change.
