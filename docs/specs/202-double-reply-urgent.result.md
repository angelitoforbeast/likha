# Result: spec 202

**Status:** done
**Date:** 2026-10-30
**Branch / PR:** `feat/202-double-reply-urgent` (local, cut from 8506b25c; not pushed, no PR)
**Preview or run link:** none (not deployed; no running app with production-like data here). Evidence is the test runs below.

## Summary

Double replies are split inside the existing cached echo build (`ChatEchoes::build()`):

- **Main-flow double** (orange): a NEW customer of the page answered by CBB (Chatbot Builder or Botcake) and by the Likha
  bot's main flow. Panel line: `Main flow double: N new customers answered by both CBB and the Likha bot in the last 60 min
  (new customers: X, answered by CBB: Y)`, with up to 3 links `Example 1..3` (inbox links only, no names or text) and the
  Health link.
- **Leftover** (white, not urgent): every other double pair. Panel line: `N leftover CBB replies to old customers
  (sequence/loop) in the last 60 min`, with the latest example's inbox link and the Health link; it carries the
  `(new customers: X, answered by CBB: Y)` numbers when the page has no orange double line (on a Karla-like page it reads
  `answered by CBB: 0`).
- **Bot Health → Who answered**: the "Double replies" columns are replaced by Main flow doubles (count + example links),
  Leftover doubles (count + examples with senders) and New customers (`X new, Y answered by CBB`), from the same cached
  array, so the numbers are the panel's. The help text under the table and a help line at the end of the panel list say
  how "new" is measured, including that a customer already known to Chatbot Builder but new to Likha counts as new.
- Red (unanswered), failed sends, slow, quiet and setup lines are untouched.

Commits (oldest first):

- `ff11a6f9` docs: spec and result skeleton for urgent double replies (spec 202)
- `aff14795` docs: plan and stories for urgent double replies (spec 202)
- `51586e22` feat: a double reply is urgent only when CBB and the Likha main flow both answer a new customer (spec 202)
- `6d3559c2` fix: the problems panel explains new customers only when it has a double line (spec 202)
- the last docs commit: this result and the stories' status

## Case table

Test file: `tests/Feature/Chatblast/DoubleReplyUrgentTest.php`.

| Case | Test | Result |
|---|---|---|
| S-202.1a (P1) new customers answered by CBB + Main Flow → orange line with count, 3 example links, help text in the page | `test_S_202_1a_new_customers_answered_by_cbb_and_the_main_flow_make_the_page_orange_with_three_example_links` | pass |
| S-202.1b (P1) a sequence send, another flow or no flow is never main flow; an active ad group's flow is; a Page-based page's group flow is not | `test_S_202_1b_a_sequence_send_or_another_flow_is_never_the_main_flow_but_an_active_ad_group_flow_is` | pass |
| S-202.2a (P1) old customers (earlier chat; CBB loop echo before the window; Botcake echo in the window before the first chat) → white leftover, no orange, urgent 0 | `test_S_202_2a_old_customers_in_a_cbb_loop_get_a_white_leftover_line_and_no_orange` | pass |
| S-202.3a (P1) Bot Health shows the same split and numbers as the panel | `test_S_202_3a_bot_health_shows_the_same_split_with_the_same_numbers` | pass |
| S-202.4a (P1) Karla-like page: 10 new customers CBB never answers, 6 leftover doubles → no orange, urgent 0 | `test_S_202_4a_a_page_whose_cbb_never_answers_new_customers_is_never_orange_for_doubles` | pass |
| S-202.5a (P1) build query count equal for 2 pairs and 42 pairs | `test_S_202_5a_the_build_query_count_does_not_grow_with_pairs_or_customers` | pass |
| S-202.6a (P2) "new customers: 3, answered by CBB: 2" on the line | `test_S_202_6a_the_line_shows_new_customers_and_how_many_cbb_answered` | pass |
| S-202.7 (P1) existing ChatEchoes / ProblemsPanel tests kept; full tests/Feature adds no new failures | `ChatEchoTest`, `ProblemsPanelTest`, `tests/Feature` | pass (see Tests) |

Red run, before any code (the 7 new tests on 8506b25c code):
`Tests: 7, Assertions: 6, Errors: 4, Failures: 3` — the errors are `ErrorException: Undefined array key "main_doubles"` /
`"new_customers"` (1b, 3a, 5a, 6a); the failures are the old orange "N double replies" line where the new text was
expected (1a, 2a, 4a).

Green run:

```
$ php vendor/bin/phpunit --testdox --colors=never tests/Feature/Chatblast/DoubleReplyUrgentTest.php
 ✔ S 202 1a new customers answered by cbb and the main flow make the page orange with three example links
 ✔ S 202 1b a sequence send or another flow is never the main flow but an active ad group flow is
 ✔ S 202 2a old customers in a cbb loop get a white leftover line and no orange
 ✔ S 202 3a bot health shows the same split with the same numbers
 ✔ S 202 4a a page whose cbb never answers new customers is never orange for doubles
 ✔ S 202 5a the build query count does not grow with pairs or customers
 ✔ S 202 6a the line shows new customers and how many cbb answered
OK (7 tests, 35 assertions)

$ php vendor/bin/phpunit tests/Feature/Chatblast/ChatEchoTest.php      → Tests: 12, Assertions: 61 (no failures; 1 PHPUnit deprecation, already on the base)
$ php vendor/bin/phpunit tests/Feature/Chatblast/ProblemsPanelTest.php → Tests: 11, Assertions: 52 (no failures; 1 PHPUnit deprecation, already on the base)
```

## Story changes

- The spec's stories .1–.6 and its "Cases to test" are written as cases S-202.1a, .1b, .2a, .3a, .4a, .5a, .6a and
  S-202.7 (existing tests and the suite) in `qa/stories.md`. The "sequence send never counts" case became S-202.1b and also
  covers a flow that is not the page's main flow, a reply with no flow, and an active ad group's flow (counts).
- No assertion of the existing `ChatEchoTest` or `ProblemsPanelTest` changed: the per-page `doubles` and
  `double_examples` keys stay (the total of all pairs), and no existing panel test had a double line.

## Done-when checklist

- [x] P1 cases pass — Case table, green run above.
- [x] The full tests/Feature suite adds no new failures vs 8506b25c — Tests section (same 2 failures as the base).
- [x] Rulings: how "new" and "main flow" are identified in the code — Rulings.
- [x] Deploy notes (no migration; cache keys) — Deploy notes.
- [x] Final line — end of this file.

## How to run

```
cd .claude/worktrees/double-202
php vendor/bin/phpunit tests/Feature/Chatblast/DoubleReplyUrgentTest.php
php vendor/bin/phpunit tests/Feature/Chatblast/ChatEchoTest.php tests/Feature/Chatblast/ProblemsPanelTest.php
php vendor/bin/phpunit tests/Feature
```

On the app: open any Chatbot screen and expand "⚠ N pages with problems", or /chatbot/health → Who answered.

## Rulings

1. **"New" customer** (`ChatEchoes::newCustomers()`): the page+psid's earliest `botcake_chats.received_at` (all rows, also
   standby, so the `page_id, psid, received_at` index answers it; 90 days are kept) falls in the 60-min window, and there
   is no `chatbot_builder` or `botcake` echo to that psid before it (before first chat − 30 s, the echo early margin the
   build already uses). The echo check uses the window's echoes already loaded plus one lookup over the 30 days of echoes
   kept. Likha has no separate store of ad referrals or open-thread starts: the gateway stores `message`, `postback` and
   `referral` events as chats (`HandleGatewayEvent::CHATS`), so an ad or open-thread start already is the first chat. Data
   limits are said in the help text: a customer known to CBB before Likha's records (or before the 30 days of echoes)
   counts as new.
2. **Main flow**: `chatbot_flows` has no main-flow flag. A page's main flow is what `ChatbotPage::flow()` runs for a new
   customer: the page setting `chatbot.flow_id`, plus the `flow_id` of the page's active ad groups (only on an Ad-based
   page; a Page-based page's group flows do not run). A Likha main-flow reply is a `chatbot_replies` row with
   `kind = send`, `status = sent`, `sequence_id IS NULL` and `flow_id` in that set. A failed or skipped send did not
   answer, so it does not count.
3. **Same reply window**: both the CBB echo and the Likha main-flow reply must fall in [first chat − 30 s, first chat +
   `unanswered_minutes` (5)], the same window that counts a message as answered on Bot Health. A main-flow double does not
   need the two replies to be within `double_seconds` of each other: the spec's own test showed timing alone does not
   separate the cases, and a CBB main flow that answers 3 minutes later is still both main flows on.
4. **CBB** = sender `chatbot_builder` or `botcake` (as the spec says). The Likha bot's own sends through Botcake are not
   affected: Botcake does not forward its echoes to the gateway (existing comment in `ChatEchoes`).
5. **Leftover** = every double pair whose customer is not a main-flow double, also a pair with no CBB side (e.g. a person
   and the bot). The line keeps the spec's wording; the senders of each pair are on Bot Health's Leftover doubles column.
6. **Counts**: the orange line counts customers (story .1 "N new customers"); the white line counts pairs (story .2 "N
   leftover CBB replies"), the same unit as the old double count. `doubles` stays the total of pairs.
7. **Where the .6 numbers go**: on the orange line; on the white line only when there is no orange line, so they are not
   shown twice.
8. A page row on Bot Health is still created only when it has unanswered customers, a double pair or a main-flow double;
   `new_customers` / `cbb_new` are filled on those rows.

## Deferred minors

- The leftover line says "CBB replies" also when a pair has no CBB side (Ruling 5). A separate white line for non-CBB
  doubles would be more exact; not asked for.
- No browser check was done (no running app with data in this worktree); the panel and Health HTML are asserted in the
  tests (links, headers, data attributes, help text).

## Merge danger

- `resources/views/layouts/chatblast.blade.php` is the shared layout of every Chatbot and Chatblast screen: a help line
  added at the end of the problems list (`data-help="doubles"`, shown only when the panel has a double line, via the
  panel's new `doubles` flag). Other open branches that touch the panel markup may conflict there. Tests that compare
  the whole banner text (`PageProblemsTest`, `SetupProblemsBannerTest`) rely on the line being absent without doubles.
- `qa/stories.md`: the S-202 section is appended at the end of the file; parallel branches appending there conflict
  textually (keep both sections).
- `app/Services/Chatbot/ChatEchoes.php`, `ProblemsPanel.php`, `resources/views/chatbot/health.blade.php`: changed in the
  echo build, the echo lines and the Who answered table only.

## Deploy notes

- **No migration.** The indexes used already exist: `botcake_chats (page_id, psid, received_at)`, `chatbot_events (page_id,
  psid, created_at)`, `chatbot_replies (page_id, psid, sent_at)`.
- **Cache keys**: the echo build's key moves from `bot-health:echoes` to `bot-health:echoes:v2` (60 s), so a page served
  right after the deploy never reads the old array shape; the old key expires by itself in 60 s, nothing to clear.
  `chatbot:problems-panel` (the panel's own sources) is unchanged.
- Blade views changed: the normal deploy's view cache refresh (`php artisan view:cache` or `view:clear`) applies.
- **Performance** (measured, SQLite in memory, 3 pages × 500 customers in the hour, 1,500 double pairs, half the
  customers old): build median 193–198 ms on 8506b25c vs 212–250 ms with this change (the machine was also running the
  full suite, so the numbers are noisy). Queries per build: 5 → 10 on SQLite, of which 3 are data queries (first chat
  grouped 3.6 ms, older CBB echoes 0.5 ms, Likha replies 1.6 ms) and 2 are the `sequence_id` column check (one query on
  MySQL). The count is fixed: the older-echo lookup runs only when the window has new customers, the reply lookup and
  column check only when CBB answered a new customer. The spec's limit was "at most one extra grouped query for first
  inbound per psid": that is the one grouped query; the other two are plain indexed lookups on small IN lists, needed by
  the spec's own definitions (CBB echo before the first inbound; the Likha main-flow reply).

## Tests

- Baseline on 8506b25c (+ the spec docs commit), `php vendor/bin/phpunit tests/Feature`: `Tests: 3674, Assertions: 70841,
  Failures: 2, PHPUnit Deprecations: 92, Skipped: 4` — the 2 known failures: `ExampleTest::test_the_application_returns_a_successful_response`
  and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order` (14 m 48 s).
- First full run after `51586e22`: `Tests: 3681, Failures: 6` — the 2 known ones plus 4 new: `PageProblemsTest::test_S_169_1…`
  and three `SetupProblemsBannerTest::test_S_171_…`, which compare the whole banner text and saw the panel help line
  that was then always shown. Fixed in `6d3559c2` (the help line shows only when the panel has a double line); those two
  files then: `PageProblemsTest` OK (8 tests, 47 assertions), `SetupProblemsBannerTest` OK (5 tests, 18 assertions).
- Final full run at `6d3559c2`, `php vendor/bin/phpunit tests/Feature`: `Tests: 3681, Assertions: 70876, Failures: 2,
  PHPUnit Deprecations: 92, Skipped: 4` (12 m 31 s) — the failures are exactly the base's two: `ExampleTest::test_the_application_returns_a_successful_response`
  and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`. 3681 =
  3674 + the 7 new tests. No new failures.

## Open questions

None.

## Process suggestions

- The panel's double line now depends on Likha's own records only; if Botcake's API could say whether a page's CBB main
  flow is on, that would be a direct signal (out of scope here: no Botcake API calls).

## Proposed tasks

- Watch the white leftover counts drain on the pages that were orange on 2026-10-30 (e.g. Katie Santos); a page whose
  orange "Main flow double" line stays after CBB's main flow is turned off would point at a missed old customer.

## Progress

- 2026-10-30: spec and result skeleton committed; baseline recorded (2 known failures); plan and stories committed;
  7 tests red for the right reason; classification, panel lines, Bot Health split and help text built; 7 new tests green,
  ChatEchoTest and ProblemsPanelTest green; first full run found 4 banner-text tests broken by the always-shown help
  line, fixed (help only with a double line); final full tests/Feature run: only the base's 2 known failures. Status:
  done.

202: done — a double is orange only when CBB and the Likha main flow both answer a new customer; leftovers are white, Bot Health shows the same split
