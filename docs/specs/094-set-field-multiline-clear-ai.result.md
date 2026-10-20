# Result 094: Set field takes multi-line values, and a "Clear AI memory" step

Branch `feat/094-set-field-multiline-clear-ai`, cut from `release/090`. Not pushed.

## Commits

| Commit | What |
|---|---|
| `146bbb40` | docs: the brief, saved verbatim |
| `5adf2d3e` | feat: the multi-line Set field value and the Clear AI memory step, with their tests |
| `50d3ede2` | fix: the clamped Set field card no longer shows a sliver of a 4th line (found in the browser check) |
| `0bc9203e` | fix: review findings: removes a dead field filter, adds tests for the same-run reset and the field cleaning |
| (this commit) | docs: result, TODO and screenshots |

## What changed

**Multi-line Set field value (editor only).** The backend already kept `\n` in a Set value: `FlowGraph::cleanConfig` doesn't strip it, and `ChatbotVars::interpolate` fills each variable where it stands. Only the editor's one-line input blocked line breaks.
- In `resources/views/chatbot/editor.blade.php`, the Value of a Set operation is now a textarea that grows from 2 to 8 rows as lines are added (`grow()`).
- The Set card on the canvas already used `white-space: pre-line`. It is now clamped to 3 lines (`-webkit-line-clamp: 3`). The bottom padding moved to a margin so no part of a 4th line shows.
- Old single-line values are unchanged.

**The "Clear AI memory" step (`clear_ai`).**
- `FlowGraph`: it is a new type with one port, `next`. Its config is `{fields: [...]}`: customer field names, normalised, de-duplicated, at most 20.
- `FlowEngine::clearAi()`: calls `FlowRun::forgetAi()`, empties the chosen fields, writes the `ai_cleared` event (`fields` in the detail when there are any), and goes to `next`.
- `FlowRun::forgetAi()`, live: sets the reserved field `__ai_since` for that page and PSID to `{"at": now, "chat": the run's chat id (or the customer's latest chat), "reply": the customer's latest chatbot_replies id}`. It also resets the run's cached `{{ALL USER INPUT}}`, so later steps in the same run see it emptied.
- `FlowRun::forgetAi()`, Test: clears `__test_history` and records how many sends the run had made so far (`aiClearedAt`).
- `FlowEngine::history()`, live: takes only `botcake_chats` with an id above the marker chat and `chatbot_replies` with an id above the marker reply.
- `FlowEngine::history()`, Test: leaves out the run's sends from before the step.
- `FlowRun::allUserInput()`, live: takes only chats after the marker chat. In Test it is empty once the step has run.
- `TestHistory::record()`: after a clear, the test message and the sends before the step are not recorded.
- `ChatbotEvents::text`: "AI memory cleared", plus "(also cleared GPT INPUT, CXD)" when fields were chosen.
- `CustomerFields`: there is a new `AI_SINCE` constant. "Clear all fields" keeps the marker, as it keeps `__last_chat`; otherwise a "clear all" step would bring the forgotten conversation back.
- Nothing is deleted. Inbox, Chats and Replies read their tables as before.
- The editor:
  - "Clear AI memory" is in the Add bar right after AI.
  - The card reads "Forgets the conversation so far", plus "clears …" when fields are chosen.
  - The form explains the step and has "Also clear these fields (optional)": a list of field inputs with the usual suggestions, using "+ Field".

**Note (merge onto `release/094`):** the 093 built-in was reverted by the owner, so this no longer applies: Clear AI memory does not reset `{{ALL USER INPUT}}`, which is a plain customer field again, filled and cleared only by Set field steps (or the step's "Also clear these fields" option). The `forgetAi()` cache reset and the `allUserInput()` lines above are gone; S-094.1 to S-094.3 were updated to match.

## Tests

New: `tests/Feature/Chatblast/ChatbotClearAiTest.php`, with 7 tests and 24 assertions. Red runs:
- Before the code, S-094.2 to S-094.6 failed with `InvalidArgumentException: Node n2 has an unknown type.`, or with an empty ports array for `clear_ai`.
- S-094.7 failed on the Add-bar regex.
- S-094.1 passed from the start. It pins existing behaviour (the backend already kept line breaks).

| Case | Test | Result |
|---|---|---|
| A Set field with a multi-line template stores and interpolates line breaks | `test_S_094_1_a_set_field_value_keeps_its_line_breaks_and_interpolates_each_line` | pass |
| The clear step sets the marker and writes the event (one output; config cleaning) | `test_S_094_2_the_clear_step_sets_the_marker_writes_the_event_and_has_one_output` | pass |
| History after the marker excludes older messages and sends (live); another customer unaffected; nothing deleted | `test_S_094_3_after_the_marker_live_history_only_has_newer_messages_and_sends_of_that_customer` | pass |
| History after the marker, in Test | `test_S_094_4_in_test_the_step_forgets_the_test_conversation_and_the_sends_before_it` | pass |
| The optional fields are cleared, the others stay | `test_S_094_5_the_step_also_empties_the_chosen_fields_only` | pass |
| "Clear all fields" keeps the marker | `test_S_094_6_clear_all_fields_keeps_the_marker` | pass |
| The editor: the step next to AI, the Set value textarea, the 3-line clamp | `test_S_094_7_the_editor_has_the_step_in_the_add_bar_and_a_multi_line_set_value` | pass |
| An old flow without the step behaves as before | the existing suites, unchanged: `ChatbotAiHistoryTest`, `ChatbotAllUserInputTest`, `ChatbotTestTabTest`, `ChatbotEngineTest` and the rest of `tests/Feature/Chatblast` | pass |

I checked that the same-run assertion has teeth: with the cache reset removed from `forgetAi()`, S-094.2 fails; restored, it passes.

**Suites:**
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 749 tests, all pass (1 skipped, as before).
- Full suite: 2,104 tests, with 1 failure and 1 error: `ExampleTest` and `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. Both fail the same way on `release/090` (checked on that branch), so there are no new failures.
- `php -l` passes on every changed PHP file.

## Browser check

There was no built-in browser in this session, so I drove the installed Chrome headless over its DevTools protocol, with a script outside the repo (Node 24, no packages). The setup:
- The app ran with `php artisan serve` on a temporary sqlite database outside the repo, with settings passed as environment variables. No env file was created.
- Assets were copied from the main checkout's `public/build`, which is ignored by git.
- The data: one flow, "Main Flow (from CBB)": Start → Set field (`all user input = {{ALL USER INPUT}}` + line break + `{{last_input}}`) → AI.

Result: **14/14 checks passed**, with no console errors. Screenshots are in `docs/specs/094-set-field-multiline-clear-ai/`.

| # | Check | Width | Evidence |
|---|---|---|---|
| 1 | The Set card shows the value on separate lines (`pre-line`) and stays within 3 lines | 1366, 390 | `1366-canvas.png`, `390-canvas.png` |
| 2 | The Value is a textarea holding the line break. It grows to 8 rows at 10 lines and shrinks to 3 rows at 3 lines; the page does not scroll sideways | 1366, 390 | `1366-set-form.png`, `390-set-form.png` |
| 3 | After Done, the card shows the 3 lines, clamped with an ellipsis | 1366, 390 | `1366-canvas-clear-ai.png` |
| 4 | "Clear AI memory" is in the Add bar right after AI, and visible | 1366, 390 | `1366-canvas.png`, `390-canvas-after.png` |
| 5 | Its form explains the step and offers "Also clear these fields" | 1366 | `1366-clear-ai-form.png` |
| 6 | The new step is on the canvas with one output, "next", and shows "clears GPT INPUT" | 1366 | `1366-canvas-clear-ai.png` |
| 7 | The draft saves `{"fields":["gpt input"]}` and the multi-line value | 1366 | the graph JSON after "Draft saved" |
| 8 | No console errors | both | |

Found and fixed during the check: the clamped card showed a sliver of a 4th line inside its bottom padding (commit `50d3ede2`).

## Review

`skeptic-reviewer`, standard depth (medium tier: engine logic and UI behind the existing role check). Result: no blockers, no majors, 4 minors.

| Finding | Outcome |
|---|---|
| No test ran a clear and then `{{ALL USER INPUT}}` in the same live run | Fixed: S-094.2 now reads the variable before and after the clear in one run (`0bc9203e`). |
| `clear_ai` field cleaning untested (`__` names, duplicates) | Fixed with a test, which showed that `ChatbotVars::norm` already turns `__wait` into the plain field `wait`. The `__` filter I had added was dead code, so I removed it. Run state can't be reached through this option. |
| S-094.7 checks source strings, not behaviour | Accepted in `TODO.md`. The browser check covers the behaviour, and there is no JS test runner. |
| A reply queued before the clear and sent after it stays forgotten (id marker) | Accepted in `TODO.md`. It follows from the chat and reply marker in the brief. |

## Story changes

None. The brief brought its test list instead of stories, and every item maps to a case above. `qa/stories.md` was not changed.

## Notes and conflicts

- **Marker contents.** The brief says the marker holds "the current time and the current chat id". It also holds the customer's latest reply id, because a time filter can't separate a bot send made in the same second as the clear. History filters by ids, and `at` is kept for people reading the field.
- **A run without a chat** (a sequence step): the marker takes the customer's latest chat id.
- **"Also clear these fields"** is a list of field inputs with suggestions, not a checkbox multi-select. The reason is in `TODO.md`.
- **The workflow gate.** The start prompt said to start now, so there was no separate plan round. I took the brief as the go.
- **No conflicts** between the brief and CLAUDE.local.md.
