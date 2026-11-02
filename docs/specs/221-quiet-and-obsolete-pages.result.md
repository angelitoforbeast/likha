# Result: spec 221, urgent only for pages that sell

**Status:** done (built and tested locally; not pushed, not deployed)
**Date:** 2026-11-03
**Branch / PR:** `feat/221-quiet-and-obsolete-pages`, cut from vps/main 86db0060 (live Likha; Incepxion runs the same
tree). Not pushed, no PR.
**Scope:** S-221.1 only. Amendment 221-1 dropped S-221.2 (Mark obsolete) and S-221.3 (comeback) before any of it was
built: there is no obsolete mark, no page_settings row for it, no endpoint and no banner link.

## Summary

The setup warning in the problems banner (`App\Services\Chatbot\ProblemsPanel::setup()`) is orange (urgent) only when
the page is sending (chatblast) **and** has at least one order in the last `ProblemsPanel::ORDER_DAYS` = 14 days. A
sending page with no order in that window gets a white line, `Quiet (no order in 14 days), setup incomplete: <reason>`,
and does not count in "(N urgent)". A page that is not sending stays `Not sending, setup incomplete: <reason>` (white),
as before.

- "Order in the last 14 days": a `macro_output` row whose `ts_date` is today or one of the 13 days before (Manila), the
  way `PageExpectedItem` counts its days.
- One grouped query per banner build (`SELECT PAGE … WHERE ts_date >= ? AND PAGE IS NOT NULL GROUP BY PAGE`, on the
  existing `ts_date, PAGE` index), matched to the setup pages in PHP with `PageSettings::pageKey` (trim, inner spaces,
  case). It is part of the existing 60-second `chatbot:problems-panel` cache, so a quiet page that gets an order is
  orange again within 60 s.
- If the order query fails or `macro_output` is missing, the page counts as selling: the line stays orange as before.

Files: `app/Services/Chatbot/ProblemsPanel.php` (code); `tests/Feature/Chatblast/QuietSetupTest.php` (new);
`tests/Feature/Chatblast/ProblemsPanelTest.php`, `tests/Feature/Chatblast/SetupProblemsBannerTest.php`,
`tests/Feature/PageSettings/PageGridOrdersTest.php`, `tests/Feature/PageSettings/PsidCoverageTest.php` (see Rulings);
`qa/stories.md`; `docs/plans/221-quiet-and-obsolete-pages.md`; this spec and result. No migration, table, column, route,
config, view or queued job change; nothing in `resources/views/chatbot/`.

Commits: `cdf08404` spec and result file, `f7876f29` plan, `2c293590` tests, `4213c44c` code, `3f6bf955` stories,
`babd880e` query-count tests, then this result.

## Checks (command and output)

PHP: `C:\Users\Forbeast\.config\herd\bin\php84\php.exe` (8.4.16), `phpunit.xml` (sqlite in memory).

Slice and the banner tests that read the setup line:

```
php vendor/bin/phpunit tests/Feature/Chatblast/QuietSetupTest.php tests/Feature/Chatblast/ProblemsPanelTest.php
  tests/Feature/Chatblast/SetupProblemsBannerTest.php tests/Feature/Chatblast/DoubleReplyUrgentTest.php
  tests/Feature/Chatblast/FacebookNameTest.php tests/Feature/Chatblast/SpeedPrewarmTest.php
  tests/Feature/PageSettings/PageSettingsCacheTest.php
Tests: 60, Assertions: 462, PHPUnit Deprecations: 2.   (no failures)

php vendor/bin/phpunit tests/Feature/Chatblast/QuietSetupTest.php
OK (5 tests, 24 assertions)

php vendor/bin/phpunit tests/Feature/PageSettings/PageGridOrdersTest.php tests/Feature/PageSettings/PsidCoverageTest.php
OK (12 tests, 87 assertions)
```

Full suite, first run (after the code commit):

```
php vendor/bin/phpunit
Tests: 4199, Assertions: 45754, Failures: 6, PHPUnit Deprecations: 94, Skipped: 5.
1) BigBroadcastTest::test_S_167_1_…            (baseline)
2) ExampleTest::test_the_application_…         (baseline)
3) ItemTableFitScriptTest::test_every_…        (baseline)
4) AstraAddressRulesTest::test_S_26_18_…       (baseline)
5) PageGridOrdersTest::test_the_order_counts_are_one_query_cached_…   new: "mula sa cache", 2 is identical to 0
6) PsidCoverageTest::test_the_summary_counts_each_page_in_one_grouped_query   new: 3 is identical to 1
```

5 and 6 count every query that names `macro_output` while rendering a page with the banner; the banner's own order
query (and its `hasTable`) now ran inside their window. Fixed in the tests (`babd880e`, see Rulings). Full suite, second
run (final code):

```
php vendor/bin/phpunit
Tests: 4199, Assertions: 75770, Failures: 2, PHPUnit Deprecations: 94, Skipped: 5.
1) Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response              (baseline)
2) Tests\Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width… (baseline)
```

No new failures. BigBroadcastTest S_167_1 and AstraAddressRulesTest S_26_18 passed in this run. `QueuedJobCompatTest`
and `QueuedJobPayloadTest` passed in both runs.

## Red lines

Watched before the code: `QuietSetupTest` (final version) run against the base `ProblemsPanel.php` (86db0060), the
code file swapped back afterwards:

```
1) QuietSetupTest::test_S_221_1_2_a_sending_page_with_no_order_in_14_days_is_white_and_not_urgent
-'⚠ 1 page with problems (0 urgent)'
+'⚠ 1 page with problems (1 urgent)'
2) QuietSetupTest::test_S_221_1_3_a_quiet_page_that_gets_an_order_is_orange_again_within_the_cache_time
-'white'
+'orange'
3) QuietSetupTest::test_S_221_1_5_eighty_pages_take_one_grouped_order_query_per_banner_build_within_the_cache
Failed asserting that actual size 0 matches expected size 1.
Tests: 5, Assertions: 12, Failures: 3.
```

S-221.1.1 and S-221.1.4 describe today's orange and pass on the base by design. Each was shown to guard the new code
with a mutation of the branch code (reverted afterwards):

- Window one day short (`subDays(ORDER_DAYS - 2)`): `test_S_221_1_1…` fails, `-'⚠ 1 page with problems (1 urgent)'`
  `+'⚠ 1 page with problems (0 urgent)'`.
- Page match by `mb_strtolower` only (no `pageKey`): `test_S_221_1_4…` fails, `-'⚠ 2 pages with problems (2 urgent)'`
  `+'⚠ 2 pages with problems (1 urgent)'`.

## Done-when checklist

- [x] Cases S-221.1.1 – S-221.1.5 pass, each with a test named after it (`QuietSetupTest`, OK 5 tests). S-221.2 and
  S-221.3 were removed by Amendment 221-1.
- [x] The result has a red line for the feat slice, watched before the code (above).
- [x] The full suite adds no new failures (final run: 2 failures, both in the known baseline).
- [x] `qa/stories.md` has the S-221.1 stories with "Last run" filled (2026-11-03 passed).
- [x] This result is filled in, with Deploy notes.

## Rulings

- Ruling: "the last 14 days" is today plus the 13 Manila days before, by `ts_date` — the same counting as
  `PageExpectedItem` (its "3 days" is today and 2 before), and the order date is the spec's measure — cost if wrong: a
  page whose last order was exactly 14 days ago shows white one day earlier than expected; change `ORDER_DAYS - 1` to
  `ORDER_DAYS`.
- Ruling: when the order query fails or `macro_output` is missing, the setup line stays orange as before — a noisy
  banner is safer than hiding a page that sells — cost if wrong: during a database error the old noise comes back for
  that build.
- Ruling: `ProblemsPanelTest` and `SetupProblemsBannerTest` insert one order today for their pages in `setUp` — those
  tests check the banner's lines, links and order with a page that should be urgent; without an order their sending
  pages are now quiet by design — cost if wrong: none for production; the no-order case is covered by `QuietSetupTest`.
- Ruling: `PageGridOrdersTest` and `PsidCoverageTest` build the banner once before they count `macro_output` queries —
  they measure the grid's and the summary's own queries, and the banner's order query is cached for 60 s — cost if
  wrong: none for production; the banner's query count is covered by S-221.1.5.
- Ruling: the quiet rule applies only to the setup line; failed sends, unanswered customers, doubles, watcher moves,
  slow, no chats and same page ID keep their colors — the spec's quiet rule names the setup warning only — cost if
  wrong: other urgent lines on old pages stay urgent.
- Ruling: the "sells" set is cached inside the existing `chatbot:problems-panel` entry; after the deploy, an entry
  written by the old code (no `selling`) reads as unknown (orange, as before) until it expires — cost if wrong: at most
  60 s of the old colors.

## Story changes

- S-221.2 (A page can be marked obsolete) and S-221.3 (An obsolete page that is alive comes back): removed by
  Amendment 221-1 (the owner, 2026-11-03: automatic only for now). Not in `qa/stories.md`; nothing of them was built.
- S-221.1: unchanged; the Test column names the tests and the days they use.

## Deferred minors

- The quiet line links only to Page settings, like the other setup lines; it does not show the date of the last order.
- The `/chatbot/pages` list and the other banner sources do not use the quiet rule (out of scope).

## Deploy notes

- Code only: `app/Services/Chatbot/ProblemsPanel.php`. No migration, no config, no route, no queue change; no queued
  job class touched (`QueuedJobCompatTest` green).
- No cache clear needed: the banner cache lives 60 s and an old entry reads as before (orange) until it expires.
- One more query per banner build (every 60 s at most): `macro_output` by `ts_date` range, grouped by `PAGE`, on the
  existing index `macro_output_ts_date_page_idx` (`ts_date, PAGE`).
- Same tree for Likha and Incepxion.
- After the deploy, the banner should show far fewer urgent pages: sending pages with a setup X and no order in 14 days
  move to white "Quiet …" lines.

**Merge danger:** door: the problems banner on every Chatbot and Chatblast screen (read only, inside `safely()`, so an
error cannot break a screen). Blast radius: the color and wording of the setup line and the "(N urgent)" count; no
sending, bot, chatblast, order or Forbeast behavior changes. Revert: `git revert 4213c44c` (the code commit); the
test commits then expect the new colors, so revert `2c293590` and `babd880e` with it.

## Open questions

None.

## Proposed tasks

- If a manual "obsolete" mark is wanted later, S-221.2 and S-221.3 in the spec are the starting point.

221: done — the setup warning is urgent only for a sending page with an order in the last 14 days; others read "Quiet (no order in 14 days)".
