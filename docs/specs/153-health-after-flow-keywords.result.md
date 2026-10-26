# Result 153: Bot Health counts a page's chats only while it has a flow; the Botcake Test flags active Botcake keywords

## Plan
`docs/plans/153-health-after-flow-keywords.md` (3 tasks, all medium: Health after the flow, the keyword check, showing it).

## Progress
- [x] Task 1: Bot Health after the flow
- [x] Task 2: the Botcake Test reads the keywords
- [x] Task 3: show it (popup, row, Check)
- [x] Review: skeptic-reviewer, standard depth. No blocker or major. 3 minors fixed (a failed token now wins over the keyword mark in the popup card; Forbeast pages get no row mark; a test for the nightly retest). The rest are accepted in `TODO.md` with reasons.

## Files
- `app/Services/Chatbot/BotHealth.php`: `inbound()` filters by `flowSpans()` / `hadFlow()`
- `app/Services/Chatbot/ChatbotConnections.php`: `checkKeywords()` / `keywords()`, called from `testBotcake()` and `saveBotcake()`; `info()` gains `keywords_status`
- `app/Services/Chatbot/PageContentCheck.php` (`setup`): the keyword X
- `app/Http/Controllers/ChatbotController.php`: the Changes label "Botcake keywords"; the `/chatbot/data` row gains `botcake_keywords`
- `resources/views/chatbot/index.blade.php`: the popup line `.conn-kw` (`kwLine()`), the card state "Keyword on: not working yet", and the row mark "Botcake: keyword on" under Connected
- `tests/Feature/Chatblast/ChatbotHealthAfterFlowTest.php` (new); `ChatbotConnectionsTest.php` (S_153 tests, the `/keywords` and `/flows` fakes, `Http::preventStrayRequests()`); `ChatbotHealthTest.php` and `ChatbotHealthByBotTest.php` (a flow row seeded before their chats, so they keep testing what they tested)
- `qa/stories.md` (S-153), `TODO.md` (accepted minors), `docs/specs/153-*`, `docs/plans/153-*`

### The inbound change
The SQL is the same as before, with two changes: the page list is limited, and `c.page_id` is selected and grouped:
```php
$answered = /* pages with a sent reply in the range (unchanged) */;
$spans = self::flowSpans();                       // page ID => [[from, until|null], ...]
$answered = array_values(array_intersect($answered, array_keys($spans)));
// botcake_chats c LEFT JOIN chatbot_replies r (send/sent) WHERE c.page_id IN ($answered) AND received_at in range AND not _ignored
//   GROUP BY c.id, c.page_id, c.received_at  SELECT c.id, c.page_id, c.received_at, MIN(r.sent_at)
foreach ($rows as $r) if (!self::hadFlow($spans[(string) $r->page_id], $r->received_at)) continue; ...
```
- `flowSpans()` reads every `page_settings` row with provider `chatbot` and key `flow_id`, both active and history. It skips a row whose `plainValue()` is empty. It maps the page name to the page's Botcake ID through `BotcakeChat::pageMap()`, compared by `pageKey`.
- `hadFlow()` is `created_at <= received_at && (replaced_at === null || replaced_at > received_at)`. Both values are Asia/Manila strings.
- The time check runs in PHP because the value may be stored encrypted. The cost is one extra query per Health build.
- The filter that keeps only pages with a sent reply in the range stays as it was. A chat counts only if its page passes both that filter and the flow filter.
- Everything built from `inbound()` follows: Answered %, not answered, reply times, hourly, slow and compare. Sends, failures and AI are unchanged.

### The keyword check
- **Reading the keywords:** `GET /pages/{id}/keywords` goes through `BotcakeClient::call`.
  - A non-2xx answer, a body that is not an array, `success: false` or no `data` list → `unknown` with the note "could not read the keywords". An exception gives the same result and is reported.
  - Keywords with `is_activated === true` → `on`. Their flow names come from `GET /flows` (`data.flows[].name`, trimmed), joined with ", ". An unknown id becomes "flow <id>" and a null flow becomes "no flow".
  - No active keyword → `none`, and `/flows` is not called.
- **Stamp:** `stamp($page, 'keywords', …)` writes `on 2026-10-26 09:00:00 · ON DELIVERY SCRIPT`, with the user. `token_status` is never touched, and nothing is written to Botcake.
- **Where it runs:** the Test button (`testBotcake`, after the token passes and the chat block is checked), the save in the popup (`saveBotcake`), and the nightly `chatbot:connections-check` (it calls `testBotcake`).
- **What the answer carries:** `keywords` (on / none / unknown) and `keywords_note`.
- **What the user sees:**
  - The popup shows the spec's red line, the green "Walang naka-on na keyword", or a grey "Keywords: could not read the keywords".
  - The card state says "Keyword on: not working yet" in red while the token is ok.
  - The row shows "Botcake: keyword on" in red, except on Forbeast pages.
  - The Check column shows the X "Botcake: may naka-on na keyword (<names>) — i-off sa Botcake" (not on Forbeast pages). The Check cache refreshes because a new botcake stamp changes `prints()`.

## Story changes
- S-153.7 also covers the nightly `chatbot:connections-check`. Reason: the spec names "any existing scheduled Botcake retest", and the command exists.
- S-153.8 adds the row mark, and adds that a Forbeast page gets neither the X nor the mark. Reason: messages on a Forbeast page do not pass through Botcake keywords; the existing chat-block X has the same guard.

## Evidence per case
Baseline at 0c516e4a: `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` gave **1639 tests, 0 failures, 1 skipped**. That run overlapped my first `BotHealth` edit, but it showed no failures, while the edit alone fails 10 Health tests, so the numbers are the baseline's.

Final run, same folders, at 943b2483: **1650 tests, 9501 assertions, 0 failures, 1 skipped** (11 new tests). After the fix commit, `ChatbotConnectionsTest.php` gave 28 tests, 277 assertions, OK.

| Case | Test | Red run (first failure) | Status |
|---|---|---|---|
| S-153.1 | `ChatbotHealthAfterFlowTest::test_S_153_1_chats_before_the_page_got_its_flow_are_not_counted` | `Failed asserting that 4 is identical to 2.` | passed |
| S-153.2 | `…::test_S_153_2_a_removed_flow_stops_the_count_and_a_page_without_a_flow_counts_none` | `Failed asserting that 5 is identical to 2.` | passed |
| S-153.3 | `…::test_S_153_3_the_shape_of_a_page_getting_its_flow_late_gives_the_answered_share_of_chats_after_flows` (95 %, was 38 %) | `Failed asserting that 50 is identical to 20.` | passed |
| S-153.4 | `ChatbotConnectionsTest::test_S_153_4_an_active_keyword_marks_the_page_not_working_yet_and_the_token_stays_ok` | `Failed asserting that an array has the subset` (no `keywords`) | passed |
| S-153.5 | `…::test_S_153_5_only_inactive_keywords_give_none` | same | passed |
| S-153.6 | `…::test_S_153_6_unreadable_keywords_are_unknown_…` (HTTP 500, success false) | same | passed |
| S-153.7 | `…::test_S_153_7_the_test_after_saving_a_token_also_checks_the_keywords` (and the nightly command) | same | passed |
| S-153.8 | `…::test_S_153_8_check_and_the_row_show_an_active_keyword_until_a_test_finds_none` | `Failed asserting that two strings are identical.` | passed |
| S-153.9 | `…::test_S_153_9_the_keyword_check_has_the_tests_access_and_shows_in_changes_with_the_user` | subset failure (no `keywords`) | passed |
| S-153.10 | `…::test_S_153_10_the_keyword_line_wraps_on_a_phone` (markup: `conn-kw … break-words`) | `Failed asserting that '<!DOCTYPE html>…` matches | passed (markup) |

For the JS, the rendered `/chatbot/pages` inline scripts were parsed with `new Function` in node: both parse. There was no browser check, because this worktree has no running app.

## Commits
- cb01af71 docs: spec, plan and stories (spec 153)
- 703a2fd7 feat: Bot Health counts a page's chats only while it had a flow (spec 153)
- 68311eb4 feat: the Botcake Test and the token save read the page's keywords and stamp an active one (spec 153)
- 943b2483 feat: Check, the pages row and the Connections popup show an active Botcake keyword (spec 153)
- 8e86d7ce fix: the keyword mark gives way to a failed token and is not shown for Forbeast pages; the nightly retest is covered (spec 153)
- (this commit) docs: result, stories and accepted minors (spec 153)

## Open points
- **"Not working yet" mark.** The mark is the red popup line, the card state, the row mark and the Check X. `botcakeConnected()` and the Connected column do not change, so the Bot picker and the Connected filter still treat the page as connected. The reason is Decision 4: the token stays ok. Say so if the owner wants the Connected column to drop Botcake while a keyword is on.
- **`unknown` after `on`.** An `unknown` result after an `on` clears the marks until the next Test, because the last stamp decides (see `TODO.md`).
- **Live check still to do.** The live test with a staff message, and a Test against a real page with an active keyword (read-only), are still to do after deploy.

153: done — Bot Health counts only chats that arrive while the page has a flow, and the Botcake Test, the token save and the nightly retest flag an active Botcake keyword in the popup, the row and Check. The full suite has no new failures (1650 tests, 0 failures, 1 skipped).
