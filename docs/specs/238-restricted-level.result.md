# Result 238: "Page restricted" is red only when it is big

Status: done. Branch `fix/238-restricted-level`, cut from `b2b355ea`. Not pushed, not deployed.

## What shipped

One slice (risk tier medium), commit `fix: page restricted is red only by count 10 or 5% of sends, editable (spec 238)`:

- `app/Services/Chatbot/BotHealth.php`:
  - `PAGE_LIGHTS` has a new `page_blocked_pct` ("Page: restricted or permission failures, % of sends", unit %, amber 0, red 5).
  - The `page_blocked` defaults are now amber 1, red 10.
  - In `buildProblems()`, a page's send light is red when blocked ≥ the red count, or when the page has `page_min_sends` sends and blocked % ≥ the red %. Otherwise it is amber when blocked ≥ the amber count. Blocked is still restricted plus permission failures. The failed % rules are unchanged.
- Thresholds: `thresholds()` already starts from the defaults, so a saved setting without the new key takes 5%. The save route already accepts every `PAGE_LIGHTS` key, so the controller is unchanged.
- `resources/views/chatbot/health.blade.php`:
  - The thresholds table has a `page_blocked_pct` row. Its rule reads "≥ 5% red, with 10+ sends; amber follows the count", and it has an editable red input.
  - Decision 3 doesn't use the amber % for the level, so that row's amber is a hidden input. It still posts the stored value, because the existing validator requires an amber for every posted key.
  - The "Pages with problems" note lists the new red rule.
- The banner text is unchanged. Only the level changes.
- What counts as urgent is unchanged: in ProblemsPanel, every page that isn't white is urgent.

## Case table

Command: `php.bat vendor/bin/phpunit tests/Feature/Chatblast/PageProblemsTest.php` → `OK (14 tests, 62 assertions)`.

| Case | Test (`PageProblemsTest::`) | Result |
|---|---|---|
| S-238.1.1 | `test_S_238_1_1_three_of_303_restricted_is_amber_not_red` | pass |
| S-238.1.2 | `test_S_238_1_2_ten_of_303_restricted_is_red_by_count` | pass |
| S-238.1.3 | `test_S_238_1_3_six_of_100_restricted_is_red_by_percent` | pass |
| S-238.1.4 | `test_S_238_1_4_with_too_few_sends_the_percent_does_not_count_and_the_count_decides` | pass |
| S-238.1.5 | `test_S_238_1_5_saved_thresholds_without_the_new_key_take_the_default_and_the_form_shows_and_saves_it` | pass |
| S-238.1.6 | `test_S_238_1_6_a_page_going_from_red_to_amber_turns_orange_and_orange_still_counts_as_urgent` | pass |

Red run, before the code:
- S-238.1.1, .1.4 and .1.6 failed on the level, for example `Failed asserting that '⚠ 1 page with problems (1 urgent) Patricia Manalo 🔴 …' starts with "… 🟠"`.
- S-238.1.5 failed with `Undefined array key "page_blocked_pct"`.
- S-238.1.2 and .1.3 already passed, because the old red limit was 3. They pin the new rule.

Health and ProblemsPanel tests:
- ChatbotHealthTest: OK (17 tests).
- ChatbotHealthAfterFlowTest: OK (3).
- ChatbotHealthByBotTest: OK (2).
- CustomerReachHealthTest: OK (3).
- ForbeastSendHealthTest: OK (9).
- ProblemsPanelTest: OK (17), with 1 PHPUnit deprecation that was there before.
- SetupProblemsBannerTest: OK (5).
- ChatbotSequenceEngineTest, CustomerReachTest, ForbeastAutoSwitchTest and UnknownMetaCodeTest: OK.

Older tests adjusted. They pinned the rule this spec changes: 3–5 restricted out of 3–5 sends was red, and now it is amber.
- `PageProblemsTest::test_S_169_1_…`: 5 successful sends were added, so the page stays red by percent (5 of 10 = 50%). The banner now reads "5 of 10 sends failed".
- `PageProblemsTest::test_S_169_6_…`: this test is about roles. It now expects 🟠.
- `ProblemsPanelTest::test_S_184_1_…` and `SetupProblemsBannerTest::test_S_171_6_…`: 7 successful sends were added, so the page stays red by percent (3 of 10 = 30%). The text reads "3 of 10".

## Full suite

`php.bat vendor/bin/phpunit`: `Tests: 4627, Assertions: 77844, Failures: 2, PHPUnit Deprecations: 95, Skipped: 6.` The two failures are the known ones:
- `ExampleTest::test_the_application_returns_a_successful_response`
- `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`

There are no new failures.

Lint: `php.bat -l` passes on BotHealth.php and on the three changed test files. `npm run build` is not needed: the only front-end change is Blade markup, with no new classes or JS.

## Review

`skeptic-reviewer`, standard depth: no blocker or major findings. It accepted both design choices: the hidden amber input, and the adjusted older tests.

One minor was accepted into `TODO.md`: a red % of 0 turns any page with enough sends and any counted failure red. The reason for accepting it is that only the CEO sets this, on a trusted path, and it is the same `≥` rule the other limits use.

## Story changes

- S-238.1.6: the spec says "the urgent count in the banner drops when a page goes from red to amber". It also says to keep today's rule for "urgent", and today orange counts as urgent. So the count stays the same (1 urgent), and the page changes from 🔴 to 🟠. The case's Then was written that way.
- S-238.1.5: the case also checks that saving 10% turns a 6-of-100 page amber. This proves the saved value is used.

## Merge danger

- A low-volume page that is almost fully restricted is now only orange when it has fewer than 10 sends and fewer than 10 restricted in the hour. For example, 5 of 5 restricted is orange. This follows from decision 3, because the percent needs `page_min_sends`. It is still listed and still counts as urgent.
- Nothing changes in the stored settings and there is no migration. A saved threshold row that has `page_blocked` red 3 keeps 3 until it is edited. Only the defaults moved to 10, so live sites with saved thresholds still turn red at 3 restricted until the CEO changes that value on the Health screen.

## Not done

- Browser check: skipped. This session has no browser tools, and the screen change is one table row and one sentence.
- No push, no PR, no deploy, per the start prompt.
