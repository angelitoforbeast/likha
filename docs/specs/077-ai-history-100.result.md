# Result 077: AI steps remember up to 100 messages, 100 by default

Branch `fix/077-ai-history-100`, cut from `main` at `0d574830`. Nothing was pushed.

## Commits

| Commit | What |
|---|---|
| `cc4a5232` | docs: the brief (verbatim) and the S-077 stories |
| `212d6c01` | fix: `MAX_HISTORY` 100, a default of 100 for a new AI step, the editor default and form text, the tests |
| (this commit) | docs: result, stories, TODO |

## What changed

- **`FlowGraph::MAX_HISTORY`** is now 100 (it was 20).
- **`FlowGraph::cleanConfig('ai', …)`:**
  - A config without a `history` key (a new step) gets 100.
  - An explicit value is kept: 0 stays 0, and a saved 5 stays 5.
  - Out of range is clamped, as before: 101 or more becomes 100, below 0 becomes 0.
  - Saved flows always carry the key, because `cleanConfig` has always written it, so no saved flow changes.
- **Editor:**
  - A new AI step starts with `history: 100`.
  - The field reads "Remember the last N messages of this customer on this page (0 = off, max 100)", with `max` 100.
- **`ChatbotHardeningTest` S-069.H6** pinned the old cap (99 → 20). It now checks 150 → 100.

## Places the history passes through

| Place | Cap or guard | Now |
|---|---|---|
| `FlowGraph::cleanConfig` | `MAX_HISTORY` | 100 |
| `FlowEngine::history()`, customer side | `botcake_chats` for this `page_id` + `psid`, before the current chat, `ORDER BY id DESC LIMIT N` | unchanged: bounded, one query |
| `FlowEngine::history()`, our side | `chatbot_replies` for this `page_id` + `psid`, `kind = send`, `status = sent`, `ORDER BY id DESC LIMIT N` | unchanged: bounded, one query |
| Merge | both sides sorted by time, the newest N kept, oldest first | unchanged |
| Indexes | both tables have (`page_id`, `psid`, `id`), from spec 074 | unchanged |
| Test tab | no history (the test customer has no real conversation) | unchanged |
| `ChatbotAi::complete` | no cap on messages; only `max_tokens` (answer length, up to 4000) and a 30 s timeout | unchanged; prompt size noted in TODO.md |

At N = 100, each AI step makes two indexed queries of at most 100 rows each.

## Case table

`vendor/bin/phpunit tests/Feature/Chatblast/ChatbotAiHistoryTest.php`: 3 tests, all pass.

| Case | Test | Result |
|---|---|---|
| S-077.1 | `test_S_077_1_a_new_ai_step_remembers_100_messages` | ✔ pass |
| S-077.2 | `test_S_077_2_100_is_accepted_and_out_of_range_is_clamped` (100, 101, 5000, −5) | ✔ pass |
| S-077.3 | `test_S_077_3_the_request_carries_the_newest_n_of_this_customer_on_this_page_in_time_order` (120 chats and 120 replies of this customer, plus another customer and another page; the request carries in 71 … out 120, oldest first) | ✔ pass |

Red runs, before the change:
- S-077.1: `Failed asserting that 0 is identical to 100.`
- S-077.2: `history 100 — Failed asserting that 20 is identical to 100.`
- S-077.3: `An expected request was not recorded.` This first red was also partly my own test setup: the test had no fake OpenAI key, so the step never called. Once the key was set, it passed with the new default of 100.

`vendor/bin/phpunit tests/Feature/Chatblast`: 394 tests, 2559 assertions, all pass (PHPUnit deprecations only). I used `vendor/bin/phpunit` rather than `php artisan test`: this worktree has no environment file, and one must not be created.

## Story changes

New section S-077 (S-077.1 to .3).

## Notes for the owner

These are in TODO.md.
- **Prompt size:** 100 messages can make a large prompt (around 50,000 tokens per AI call at worst). There is no character budget on the history.
- **Manual replies:** replies typed by staff in the inbox are not part of the AI history, only the bot's own replies. That is unchanged, as the brief asked.
