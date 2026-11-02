# Result: spec 216 who set a value and when, on hover; no AI usage sent for pages outside Forbeast

## Status

done (no open question)

## Date

2026-11-02

## Branch / PR

`feat/216-set-by-on-hover` (worktree `.claude/worktrees/hover-216`), based on `vps/main` c6c704e1. Not pushed, no PR.

## Summary

**A. Who set the value, and when (/owner/private).**

- `/owner/private/item-summary` rows carry new fields:
  - `item_value_set_by` and `item_value_set_at`: who set the Item Value (COGS) shown, and when.
  - `item_value_ceo_set_by` and `item_value_ceo_set_at`: the same for the CEO value. They are filled only for a CEO in the CEO view and are null otherwise.
  - `rts_set_at`: the time beside the existing `rts_set_by`.
- Times are Manila time, formatted on the server as `M j, Y g:i A` (for example "Nov 2, 2026 3:05 PM").
- **Source.** `page_item_settings_log` joined to `users` and `employee_profiles`, as the Edit Audit Logs screen does. No new table, column, route or migration.
  - The lookup (`cogsSetByMap`) is one query per data load: `l.*` for the dates of the cogs and cogs_ceo rows shown.
  - Items resolve with `ItemAliasResolver::canonicalKey`, and the latest id wins.
  - A setter is named only when that log line's value equals the value shown (`setByFor`). Otherwise there is no "Set by" (old values, apply-through cascades, the cogs_ceo mirror), and nothing errors.
- **Who.**
  - The name comes from the employee profile or the user; failing both, the email.
  - No email and source `cogs_sync` gives "system (cogs sync)". No email otherwise gives "system".
- **RTS.** `rtsSetByMap` keeps the `created_at` of the row that started the value's run, so the RTS "Set by" now has its time.
- **Hover** (`owTip`, the one place the lines are built, in `resources/views/owner/private.blade.php`):
  - RTS: `Set by U · T`.
  - Item Value: `cogs`, then `Set by U · T`, then `🔒 CEO value set by U · T` in the CEO view only. That line sits inside `@if(!empty($effectiveIsCEO))`, so it is not in the page source of the Marketing view.
  - Compact mode: the existing cell hover (`data-ow-tip`).
  - Normal mode: a `title` on the RTS and Item Value cell content (`:title="!owCompact ? owTip(…) : null"`).

**B. AI usage only for pages served through a gateway.**

- `ChatbotAiUsage::gatewayOf()` now returns null unless `ChatbotProvider::name($page) === 'gateway'`. That is the check `ChatbotProvider::for()` (and so `BotcakeOutbox::send`) uses to pick the gateway or Botcake.
- What a Botcake page gets now:
  - Its AI rows keep their `cost_micros` and get `no_gateway`, and nothing is sent to Forbeast.
  - `ChatbotAiLoad` never asks about it (state, the per-minute refresh, the page note), so its AI never stops for load.
- The status is decided when the row is written, so rows from before a move between Botcake and the gateway keep theirs.

**Files changed**
- `app/Services/Chatbot/ChatbotAiUsage.php`
- `app/Http/Controllers/OwnerPrivateController.php`
- `resources/views/owner/private.blade.php`
- `tests/Feature/OwnerPrivate/SetByHoverTest.php` (new)
- `tests/Feature/OwnerPrivate/SetByHoverViewTest.php` (new)
- `tests/Feature/Chatblast/AiUsageGatewayPagesTest.php` (new)
- `tests/Feature/OwnerPrivate/RtsSetByMapTest.php`: the map value is now `['name', 'at']`.
- `tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php`: the `/owner/private` render is re-pinned (see Rulings).
- The spec 210 tests `AiKeyPoolTestCase.php`, `AiLoadTest.php`, `AiKeyCostTest.php` and `AiUsageSenderTest.php`: their pages go on the gateway (see Rulings).
- `qa/stories.md`, `TODO.md`, and `docs/plans/216-set-by-on-hover.md`.

No file of spec 215 is touched.

## Case table

| Case | Test | Result |
|---|---|---|
| S-216.1.1 | `SetByHoverTest::test_S_216_1_1_a_manual_item_value_on_owner_private_shows_who_set_it_and_when` | pass |
| S-216.1.2 | `SetByHoverTest::test_S_216_1_2_a_value_from_item_cogs_or_cogs_sync_names_its_setter_and_time` | pass |
| S-216.1.3 | `SetByHoverTest::test_S_216_1_3_the_rts_cell_says_who_and_when` | pass |
| S-216.1.4 | `SetByHoverViewTest::test_S_216_1_4_who_and_when_show_on_hover_in_compact_and_normal_mode` (CEO, CEO as Marketing, Marketing) | pass |
| S-216.1.5 | `SetByHoverTest::test_S_216_1_5_a_ceo_value_shows_its_setter_and_time_in_the_ceo_view`, `SetByHoverViewTest::test_S_216_1_5_the_ceo_view_adds_the_ceo_value_line` | pass |
| S-216.1.6 | `SetByHoverTest::test_S_216_1_6_the_marketing_view_and_non_ceo_users_get_nothing_about_the_ceo_value` (JSON: Marketing, Marketing-OIC, CEO as Marketing), `SetByHoverViewTest::test_S_216_1_6_the_page_source_has_no_ceo_value_line_outside_the_ceo_view` | pass |
| S-216.1.7 | `SetByHoverTest::test_S_216_1_7_a_value_without_a_matching_log_line_shows_no_set_by_and_no_error` | pass |
| S-216.1.8 | `SetByHoverTest::test_S_216_1_8_the_latest_log_line_for_the_value_wins_and_aliases_resolve` | pass |
| S-216.1.9 | `SetByHoverTest::test_S_216_1_9_a_renamed_or_removed_user_shows_the_current_name_or_the_email` | pass |
| S-216.1.10 | `SetByHoverTest::test_S_216_1_10_the_lookup_adds_at_most_two_queries_per_data_load_and_none_per_row` (plus the time measured below) | pass |
| S-216.2.1 | `AiUsageGatewayPagesTest::test_S_216_2_1_a_gateway_page_gets_pending_usage_and_it_is_sent_as_today` | pass |
| S-216.2.2 | `AiUsageGatewayPagesTest::test_S_216_2_2_a_botcake_page_keeps_its_cost_but_gets_no_gateway_and_nothing_is_sent` | pass |
| S-216.2.3 | `AiUsageGatewayPagesTest::test_S_216_2_3_a_botcake_page_is_never_load_checked_and_its_ai_never_stops_for_load` | pass |
| S-216.2.4 | `AiUsageGatewayPagesTest::test_S_216_2_4_a_page_moved_between_botcake_and_the_gateway_follows_the_new_path_and_older_rows_stay` | pass |

## Checks (command and output)

PHP is Herd's (`php.bat`), and tests run on sqlite in memory per `phpunit.xml`.

- `php.bat vendor/bin/phpunit tests/Feature/OwnerPrivate/SetByHoverTest.php` → `OK (9 tests, 51 assertions)`
- `php.bat vendor/bin/phpunit tests/Feature/OwnerPrivate/SetByHoverViewTest.php` → `OK (5 tests, 24 assertions)`
- `php.bat vendor/bin/phpunit tests/Feature/OwnerPrivate/RtsSetByMapTest.php` → `OK (4 tests, 8 assertions)`
- `php.bat vendor/bin/phpunit tests/Feature/OwnerPrivate/ItemSummaryProfitMTest.php` → `OK (9 tests, 32 assertions)`
- `php.bat vendor/bin/phpunit tests/Feature/OwnerPrivate/ItemSettingsLogTest.php` → `OK (18 tests, 108 assertions)`
- `php.bat vendor/bin/phpunit tests/Feature/OwnerPrivate/FitToWidthTest.php` → `OK (14 tests, 149 assertions)`
- `php.bat vendor/bin/phpunit tests/Feature/OwnerPrivate/ClaudeActionViewTest.php` → `OK (6 tests, 310 assertions)`
- `php.bat artisan test` for AiUsageGatewayPages, AiLoad, AiKeyCost, AiUsageSender, AiKeyFailover and AiKeyMigration → `Tests: 43 warnings (446 assertions)`, with no failure.
  - The warnings come from `artisan test`'s own printer (`file_get_contents` on this worktree path) and show on these files before the change too.
  - `phpunit` directly gives `OK (4 tests, 19 assertions)` for the new file.
- `php.bat -l` on `ChatbotAiUsage.php` and `OwnerPrivateController.php` → `No syntax errors detected`.
- Full suite: `php.bat vendor/bin/phpunit` → see "Full suite" below.

**S-216.1.10, queries.** One data load issues 4 queries on `page_item_settings_log` before this spec and 5 after, for 1 page and for 6 pages alike. The new lookup is a single select with no per-row query.

**S-216.1.10, time.** The fixture has 60 pages, a 30-day range, and 3 log lines per page (one of them a cogs sync line). Each run is 50 loads of `/owner/private/item-summary?refresh=1` as CEO. The "before" runs use the controller swapped back to c6c704e1, alternating with "after":

```
before round 1: median 78.8 ms, mean 79.5 ms over 50 loads (60 pages, 30-day range)
after round 1:  median 78.2 ms, mean 78.2 ms over 50 loads (60 pages, 30-day range)
before round 2: median 89.1 ms, mean 89.8 ms over 50 loads (60 pages, 30-day range)
after round 2:  median 85.4 ms, mean 89.1 ms over 50 loads (60 pages, 30-day range)
```

Every "after" run is within 10% of its "before" run (the difference is noise).

**Rendered hover text (S-216.1.1, .2, .5) from a local run.** The rows come from a local `/owner/private/item-summary` load on the test fixture (sqlite). `owTip()` was taken from the rendered Blade source: the `@if($effectiveIsCEO)` body is kept for the CEO view and dropped for the Marketing view. It was run in node on those rows.

```
== S-216.1.1 (CEO view; Item Value set on /owner/private)
[Item Value hover]
cogs
Set by Ana Reyes · Nov 2, 2026 3:05 PM
[RTS hover]
from 2026-10-01
💬 set
Set by Ana Reyes · Oct 1, 2026 6:40 PM
== S-216.1.5 (CEO view; CEO value set too)
[Item Value hover]
cogs
Set by Ana Reyes · Nov 2, 2026 3:05 PM
🔒 CEO value set by Dina Santos · Oct 5, 2026 11:00 AM
== S-216.1.5 / 1.6 (Marketing view, same data)
[Item Value hover]
cogs
Set by Ana Reyes · Nov 2, 2026 3:05 PM
== S-216.1.2 (later set on /item/cogs)
[Item Value hover]
cogs
Set by Ana Reyes · Oct 30, 2026 9:15 AM
🔒 CEO value set by Dina Santos · Oct 5, 2026 11:00 AM
== S-216.1.2 (then a cogs sync line for the same date)
[Item Value hover]
cogs
Set by system (cogs sync) · Oct 31, 2026 12:10 AM
🔒 CEO value set by Dina Santos · Oct 5, 2026 11:00 AM
```

No browser screenshot. The page needs the full live data set (macro_output, ads, J&T) to render rows, so the hover text above is the rendered evidence.

### Full suite

I ran `php.bat vendor/bin/phpunit` once, on the tree at 890ff9ca:

```
Time: 31:03.359, Memory: 808.00 MB
Tests: 4091, Assertions: 44536, Failures: 6, PHPUnit Deprecations: 94, Skipped: 4.
1) BigBroadcastTest::test_S_167_1_5000_messages_on_50_pages_all_go_out_without_one_failed_for_waiting   (baseline)
2) ExampleTest::test_the_application_returns_a_successful_response                                     (baseline)
3) ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order (baseline)
4) AstraAddressRulesTest::test_S_26_18_…                                                                (baseline)
5) AstraAddressRulesTest::test_S_26_23_… "Failed asserting that 28.7615231 is less than 25.0."         (time budget under full-suite load)
6) SharedPagesBasePinTest::test_S_42_4_… with data set "/owner/private" "nagbago ang render ng /owner/private"
```

Two of these are not in the baseline list:

- **5, `S_26_23`.** This is the test's own 25 s timing assertion. It went over during the 31-minute full run. Run alone on this branch, it passes: `php.bat vendor/bin/phpunit --filter S_26_23 tests/Feature/NightRun/AstraAddressRulesTest.php` gave `OK (1 test, 16 assertions)`. This spec touches no Astra or NightRun file.
- **6, the `/owner/private` render pin.** This is the guard that is meant to catch any change to that page.
  - With the c6c704e1 Blade, the render hashes to the pinned `e0c4d13c…`. With this branch, the diff of the two renders is exactly this spec's lines: the two `:title="!owCompact ? owTip(…) : null"` attributes and the owTip lines.
  - The pin was re-pinned to `30ebdc74…`, with the docblock saying so, in commit 5010b776. That commit also moved the `@if`/`@endif` around the CEO line to column 0, so the rendered JS keeps its indentation.
  - After it: `SharedPagesBasePinTest` gave `OK (2 tests, 8 assertions)`, `SetByHoverViewTest` `OK (5 tests, 24 assertions)` and `FitToWidthTest` `OK (14 tests, 149 assertions)`.

No other new failure.

## Red lines

Each red was watched before the code it drove.

- **fix (usage only for gateway pages).** `test_S_216_2_2…`: "Failed asserting that two arrays are identical. -1 => 'no_gateway' +1 => 'pending'".
  - `test_S_216_2_3…` failed the same way, on an `/v1/site/ai-load` GET that was not expected.
  - `test_S_216_2_4…` failed with "- 'no_gateway' + 'pending'" on the first row.
  - `test_S_216_2_1…` passed before the change; it pins today's gateway path.
- **feat (who and when in the rows).** `test_S_216_1_1…`: "Failed asserting that two arrays are identical. -0 => 'Ana Reyes'" (the field was missing).
  - `test_S_216_1_3…` failed with `Undefined array key "rts_set_at"`.
  - 1.2, 1.5, 1.6, 1.8 and 1.9 failed the same way (field missing).
  - 1.7 and 1.10 are guards. 1.7 failed on the missing fields; 1.10 passed before (4 log queries, constant) and pins the limit.
- **feat (hover lines, compact and normal).** `test_S_216_1_4…`: "CEO rts_set — Failed asserting that 0 is identical to 1" (no normal-mode hover).
  - `test_S_216_1_5_the_ceo_view_adds_the_ceo_value_line` failed: the line was not in the source.
  - `test_S_216_1_6_the_page_source…` passed before; it pins that the line never appears for Marketing.

## Done-when checklist

- [x] Cases S-216.1.1 – S-216.2.4 pass, each with a test named after it (case table above).
- [x] The result has a red line for every feat/fix slice, watched before the code (Red lines).
- [x] The full suite adds no new failures (Full suite above, against the known baseline).
- [x] `qa/stories.md` has these stories with "Last run" filled (Slice 216).
- [x] This result is filled in, with the rendered hover text of S-216.1.1, .2 and .5 from a local run.

## Rulings

- Ruling: "the same check the send path uses" is `ChatbotProvider::name($page) === 'gateway'`, the check inside `ChatbotProvider::for()` — every send goes through it via `BotcakeOutbox::send` — cost if wrong: a page whose chats come from the gateway while its `chatbot.provider` is Botcake (for example, auto-switched by ForbeastWatch) gets `no_gateway` rows; its replies go through Botcake too, so this matches "not served through Forbeast".
- Ruling: the decision is made when the row is written (in `billOf`), with no re-labelling of older rows — S-216.2.4 says older rows are unchanged — cost if wrong: none; pending rows from Botcake pages written before the release are still sent once and answered `page_not_found`, as today.
- Ruling: the spec 210 tests used Botcake pages to stand for billed pages, so their pages now go on the gateway (`AiKeyPoolTestCase::onGateway`, a faked gateway send, `sent()` reading both paths). `AiLoadTest` 6.5's "no URL" step now checks that the AI ran instead of the reply text, because a gateway page with no URL cannot send — the 210 behaviour under test is unchanged, only its fixture follows the new rule — cost if wrong: none to production code.
- Ruling: the CEO value's setter is found on log lines with `new_item_value_ceo` set. That covers scope `cogs_ceo` and the `cogs`/`all` saves of `/owner/private`, which log the CEO value in the same row — `saveItemSetting` never writes scope `cogs_ceo` itself — cost if wrong: a CEO value with no such log line shows no "🔒" line, without error.
- Ruling: a setter is named only when the log line's value equals the value shown. Values spread by apply-through, copied by the cogs_ceo mirror, or changed without a log name nobody — S-216.1.8 says "names the one that set the value shown", and naming a different value's setter would be wrong — cost if wrong: fewer "Set by" lines on such values.
- Ruling: a cogs sync line counts as the setter ("system (cogs sync)"), as S-216.1.2 says, even when it only carried yesterday's price forward. The sync is not scheduled anywhere in the repo, so it may run by hand or from a server cron — cost if wrong: if it runs daily, most Item Values say "system (cogs sync)" instead of the person who set the price (proposed task 1).
- Ruling: the time is formatted on the server (Manila, `M j, Y g:i A`) — the browser's zone never changes it, and the hover is plain text — cost if wrong: none.
- Ruling: a removed user's fallback is the full email on the new Item Value lookup (S-216.1.9), while the RTS hover keeps today's `rtsSetByMap` fallback (the part before the @) — minimal touch to the existing RTS name — cost if wrong: two styles for a removed user (TODO.md).
- Ruling: the lookup is one query with `l.*`, not a column listing plus a select. On sqlite a column listing is two queries, and `l.*` reads the optional `new_item_value_ceo`/`source` columns when they exist; a missing table is caught and gives no names — cost if wrong: none (fewer queries than the plan).
- Ruling: the S-216.1.10 time half is measured by hand (above), not asserted in the suite, since a timing assertion would be flaky — cost if wrong: a later slowdown is not caught by CI.
- Ruling: the `/owner/private` render pin (`SharedPagesBasePinTest`) is re-pinned. It exists to catch leaked changes, and the diff against the base render is exactly this spec's `title` and owTip lines; earlier specs re-pinned the same way — cost if wrong: none; the pin keeps guarding from the new hash.
- Ruling: the CEO hover line is inside `@if(!empty($effectiveIsCEO))` as well as gated in the data, so the Marketing view's page source does not even carry the label — S-216.1.6 names the page source — cost if wrong: none.

## Story changes

None to the cases. The Test column names the tests; S-216.1.5 and S-216.1.6 each have a data test and a page-source test.

## Deferred minors

From the review (skeptic-reviewer, standard depth: approve, no blocker or major), all in `TODO.md` under "Set by on hover (spec 216)":

- A daily cogs sync names itself as the setter (see Rulings; proposed task 1).
- A removed user shows as the email's local part on RTS but as the full email on Item Value.
- No test for an apply-through or mirrored value. It shows no "Set by" through the same value check that S-216.1.7 tests.
- The time half of S-216.1.10 is measured by hand.

## Deploy notes

- No migration and no config. Code only: one controller, one Blade view and one service.
- `/owner/private` data is cached until the version bumps. The new fields appear after the next save, upload or `?refresh=1`. Until then, cached payloads simply lack them, and the hover shows what it shows today.
- **Merge danger.**
  - **Door:** `/owner/private/item-summary` (CEO, Marketing, Marketing-OIC) and the chatbot AI billing path (`ChatbotAiUsage::gatewayOf`).
  - **Blast radius:**
    - The new hover fields are display-only. No computation reads them.
    - The AI change stops usage and load checks for Botcake pages, which are Incepxion's AI pages today. Their usage was only ever answered `page_not_found`, so no billing is lost.
    - A gateway page is unchanged.
    - The CEO fields are gated like `item_value_ceo`, and the item-summary cache key already includes role and view_as.
  - **Revert:** revert the branch's commits. Nothing is stored by this change.

## Open questions

None.

## Proposed tasks

1. Decide whether the Item Value hover should credit the person who started the price's run (as the RTS "Set by" already does) instead of a cogs sync line that only carried the price forward. That matters if `cogs:sync-from-macro` runs daily on the server.
2. One shared "removed user" fallback (full email, or the part before the @) for the RTS and Item Value hovers.

216: done — set-by and time on the RTS and Item Value hovers (CEO value only in the CEO view), and AI usage and load checks only for pages served through a gateway
