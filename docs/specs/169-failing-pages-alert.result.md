# Result 169: marketing sees a failing page at once

## What shipped
- **Reasons** (`BotHealth::REASONS`, the reason SQL is now `reasonSql()`): `restricted` = "Page restricted by Facebook" (error text "temporarily restricted", or subcode 1893063 in the error or the raw Botcake answer) and `permission` = "Permission error (#2022 / code 10 / #200)" ("(#2022)", "(#200)" or "does not have permission" in the error; or `"code":10` in the raw answer when it is not about the window). The order is restricted → unreachable → permission (text) → window "(#10)" → permission (raw code 10) → botcake → timeout → other, so a "(#10) … window" error stays "Outside 24-hour window". Every place that uses the reasons picks them up: the tiles, the "Why replies failed" table, Compare bots, the failed-sends list and the daily chart (two new bar colours). Without the `raw` column (before its migration) only the error text is read.
- **Pages with problems** (top of `/chatbot/health`, `BotHealth::problems()`): per page, last 60 minutes, `kind = send`. The table shows sends, failed (with the number not reachable), failed % (without "customer not reachable"), the top reason, and the first and last failure (customer-not-reachable failures left out). Red: ≥ 3 restricted or permission failures, or ≥ 30% failed with ≥ 10 sends. Amber: ≥ 1 restricted or permission failure, or ≥ 10% failed with ≥ 10 sends. Red rows come first. Skipped sends (a person paused the bot, emergency stop) are not sends of the page and are left out; a `partial` send counts as a send, not a failure. Clicking a page opens its latest 20 failed sends from the last 24 h (time, reason, error cut to 150 characters, "Customer in Inbox" link). With no problem pages the table reads "No page problems in the last hour".
- **Thresholds:** three new rows on the existing form (CEO only, same validation that amber comes before red): `page_blocked` (1 / 3), `page_failed_pct` (10 / 30) and `page_min_sends` (10). Saving clears the problems cache.
- **Banner** (`layouts/chatblast.blade.php`, the layout of every Chatbot and Chatblast screen): red, `role="alert"`, for roles that have the Chatbot menu (`/chatblast` nav access): "⚠ <page>: <top reason> since <HH:MM of the first failure>; … +N more — open Health". It lists at most 3 red pages and links to `/chatbot/health#page-problems`. The result is cached 60 s, lights included, so a cached screen runs no extra query. If anything fails the screen shows no banner and the error is reported.
- **Inbox:** `/chatbot/inbox?page_id=…&psid=…` selects the page and opens that customer's conversation. Only digits and a page in the list are accepted.
- Nothing about sending, flows or sequences changed. No automatic pause.

## Case table
| Case | Test | Result |
|---|---|---|
| S-169.1 | `test_S_169_1_a_restricted_page_is_red_and_the_banner_names_it_with_the_since_time`, `test_S_169_1_restricted_and_permission_failures_get_their_own_reason` | pass (red before the code: `Call to undefined method BotHealth::problems()`; the reasons test failed with the new errors counted as "Other") |
| S-169.2 | `test_S_169_2_failed_percent_of_a_page_with_enough_sends_makes_it_red_or_amber_and_the_thresholds_are_editable` | pass (red: `Call to undefined method BotHealth::problems()`) |
| S-169.3 | `test_S_169_3_customer_not_reachable_does_not_count_toward_failed_percent` | pass (red: same) |
| S-169.4 | `test_S_169_4_no_problems_means_no_banner_and_an_empty_table` | pass (red: same) |
| S-169.5 | `test_S_169_5_a_row_opens_the_latest_failures_of_its_page` | pass (red: same) |
| S-169.6 | `test_S_169_6_roles_without_the_chatbot_menu_see_no_banner` | pass (red: no banner for the role with the Chatbot menu) |
| S-169.7 | existing Health tests plus `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` | pass: 29be5f6a `Tests: 1343, Skipped: 1`, OK; this branch `Tests: 1351, Skipped: 1`, OK (the 8 new tests; 58 PHPUnit deprecations on both) |
| S-169.8 (P2) | 390 px: not run in a browser (this session has no browser on a running app with data). By markup: the banner is a full-width block that wraps; the table sits in the same `overflow-x-auto` card as the other Health tables; the failure details are a wrapping list (`break-words`), not a wide table | not run |
| S-169.9 | `test_S_169_9_the_banner_lists_at_most_three_pages_and_is_cached_up_to_a_minute` | pass (red: same as .1) |

## Story changes
- Added S-169.9 (at most 3 pages plus "+N more", and the 60 s cache). Both are in the spec's decision 3 but had no case.
- S-169.4 also covers failures older than 60 minutes and skipped sends, which are not counted.

## Review
Skeptic reviewer, standard depth. **1 major, fixed:** the banner's table check ran outside the cache, so each Chatbot and Chatblast screen made one more query. That broke `PageSettingsGridTest::test_the_query_count_stays_flat_from_5_to_60_pages` (11 > 10) in the combined suite run. The check is now inside the cached closure (7a7e2bbb). **Minors:** two are fixed (the amber note now names the sends needed; tests pin the exact 30% boundary and a partial send). The rest are accepted with reasons in `TODO.md`: amber at 1 blocked failure, the "PSID nnn" title, the extra keys in the JSON thresholds, the compact-JSON assumption, and five untested one-line edges.

## Conflicts
- None between CLAUDE.local.md and the spec. Deviations:
  - The brief's decisions served as the plan's "go"; no separate plan round.
  - The existing `ChatbotHealthTest::test_S_134_3` expected-reasons array gained the two new keys (`restricted`, `permission`, both 0), as decision 1 requires.
  - The Inbox deep link is JavaScript with no test: the repo has no JS test setup. It is not checked in a browser either.
  - Amber at ≥ 1 blocked failure is not in the spec; the form needs an amber number for that rule (see `TODO.md`).

## Deploy notes
- No migration and no config change. Files: `app/Services/Chatbot/BotHealth.php`, `app/Http/Controllers/ChatbotHealthController.php`, `resources/views/layouts/chatblast.blade.php`, `resources/views/chatbot/health.blade.php`, `resources/views/chatbot/inbox.blade.php`.
- Clear the view cache after the deploy (`php artisan view:clear`, or the usual `optimize:clear`). The banner appears within 60 s of a page turning red.
- The new reasons also recount history: past failures with "temporarily restricted" or permission errors move from "Other" to their new reasons on Health.
- Thresholds already saved keep their values; the three page rules start at their defaults (1/3, 10/30, 10 sends) until the CEO changes them.
- Applies to Incepxion as well (same codebase and files).
- Does not conflict with the files of 164, 167 or 168 (no change to BotcakeSendPool/Queue, ChatblastSender, FlowEngine or the owner/transfer screens).
