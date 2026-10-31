# Result: spec 208 Facebook name

**Status:** done
**Date:** 2026-10-31
**Branch / PR:** `feat/208-facebook-name` (from e00a2753), not pushed, no PR
**Preview or run link:** n/a (not deployed; the page helpers run in node from the rendered HTML)

## Summary
A new service `App\Services\Chatbot\FacebookName` reads each page's real Facebook name and keeps it in two page settings rows: `facebook.name` and `facebook.name_checked` ("`<time> · <forbeast|incepxionbot|meta-ads|unknown> · <page ID>`"). No new table and no migration. The sources are tried in order:
1. The gateway probe the page already has. Its name is stored by `ForbeastPageSetup::connect` / `retest`, with no extra call.
2. The stored Meta Ads user connections: one Graph `GET /{page-id}?fields=name` each, no retry, a 10 s timeout, stopping at the first that answers.
3. Otherwise "unknown", and the last known name is kept.

When names are fetched:
- Botcake Save and Test, gateway Save and Test, "+ Page" with an ID, and the nightly `chatbot:connections-check`.
- In the nightly run the lookups run after all the tests and retests, so a duplicate row reuses a name read in the same run.
- Never on a page load.

What staff see:
- `FacebookName::overview()` (two queries, no HTTP) feeds the `facebook` block of `/chatbot/data` rows and of the Connections info, and the problems banner.
- The page shows amber warnings in the Check column and in the Connections popup ("Facebook name: …", "Same page ID as …" with which row matches).
- The banner has one orange line for each shared page ID.

## Case table
| Case | Test | Result |
|---|---|---|
| S-208.1 | `test_S_208_1_the_gateway_test_stores_the_facebook_name_and_the_duplicate_row_shows_it` | pass: `✔ S 208 1 the gateway test stores the facebook name and the duplicate row shows it` |
| S-208.1 | `test_S_208_1_the_gateway_save_stores_the_name_and_a_failed_gateway_test_tries_the_others_once` | pass: `✔ S 208 1 the gateway save stores the name and a failed gateway test tries the others once` |
| S-208.2 | `test_S_208_2_without_a_gateway_token_a_meta_ads_connection_that_can_read_the_page_gives_the_name` | pass: `✔ S 208 2 without a gateway token a meta ads connection that can read the page gives the name` |
| S-208.2 | `test_S_208_2_a_rate_limited_connection_is_tried_once_without_waiting` | pass: `✔ S 208 2 a rate limited connection is tried once without waiting` |
| S-208.3 | `test_S_208_3_when_no_source_can_read_it_the_name_is_unknown_and_the_last_known_name_is_kept` | pass: `✔ S 208 3 when no source can read it the name is unknown and the last known name is kept` |
| S-208.3 | `test_S_208_3_a_name_read_for_another_page_id_is_not_kept` | pass: `✔ S 208 3 a name read for another page id is not kept` |
| S-208.4 | `test_S_208_4_names_equal_but_for_case_and_spaces_give_no_warning` | pass: `✔ S 208 4 names equal but for case and spaces give no warning` |
| S-208.5 | `test_S_208_5_two_rows_with_one_page_id_both_warn_the_banner_counts_the_pair_once_and_the_match_is_marked` | pass: `✔ S 208 5 two rows with one page id both warn the banner counts the pair once and the match is marked` |
| S-208.5 | `test_S_208_5_a_name_from_outside_is_shown_as_text` | pass: `✔ S 208 5 a name from outside is shown as text` |
| S-208.6 | `test_S_208_6_loading_the_pages_and_the_data_calls_no_gateway_and_no_graph` | pass: `✔ S 208 6 loading the pages and the data calls no gateway and no graph` |
| S-208.7 | `test_S_208_7_the_nightly_run_looks_up_each_listed_page_once_records_failures_and_keeps_going` | pass: `✔ S 208 7 the nightly run looks up each listed page once records failures and keeps going` |
| S-208.8 | `test_S_208_8_plus_page_with_a_used_id_names_the_owner_and_the_facebook_name` | pass: `✔ S 208 8 plus page with a used id names the owner and the facebook name` |

File: `OK (12 tests, 139 assertions)`. Red run first (before any code, with an empty service skeleton): `Tests: 9, Assertions: 21, Errors: 3, Failures: 5`. Examples: S-208.1 `Failed asserting that null is identical to 'Alaga Natural Collective'.`, S-208.5 `Undefined array key "facebook"`. S-208.6 passed on red (nothing called the outside yet); it guards against a regression.

## Story changes
- Added the S-208 section to `qa/stories.md` (cases .1 to .8 from the spec).
- .1, .2 and .5 each got a second test from the review:
  - .1: gateway Save, and a failed gateway Test that tries the others once.
  - .2: a rate-limited connection is tried once with no wait.
  - .5: a name from outside is shown as text.
- .3 got a second test: a name read for another page ID is not kept. This is the edge the page ID in `name_checked` exists for.
- .6 also covers the Connections popup GET, which is a page load too.

## Done-when checklist
- [x] P1 pass with tests (failing first): the case table above; red output recorded above.
- [x] tests/Feature has no new failures vs the base. Base at e00a2753: `Tests: 3805, Assertions: 72120, Failures: 2, Skipped: 4`. After: `Tests: 3817, Assertions: 72259, Failures: 2, Skipped: 4`. The same two known failures both times: `ExampleTest::test_the_application_returns_a_successful_response` and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`.
  - The 207 snapshot files (`tests/Feature/Chatblast/snapshots/207/*.json`) were regenerated with `WRITE_207_SNAPSHOTS=1`.
  - Their diff is only the new `facebook` block, in each data row and in the Connections info: 127 blocks, all with the keys `page, page_id, name, source, checked_at, differs, same_id, match`.
  - S-207.9's query bounds pass with the two new queries.
- [x] The rendered warnings for .1 and .5. They come from the real `/chatbot/data` JSON and the `facebookLines` helper taken from the rendered `/chatbot/pages` HTML and run in node. The banner HTML is rendered by the layout. See "Evidence" below.
- [x] Deploy notes: below (no migration; the nightly command is `chatbot:connections-check`).
- [x] The final line.

### Evidence (S-208.1 and S-208.5, rendered)
```
### 4RO Alaga Natural Collective
facebookLines() = {"warnings":["Facebook name: Alaga Natural Collective","Same page ID as Alaga Natural Collective: Alaga Natural Collective matches the Facebook name"],"line":"Facebook name: Alaga Natural Collective","link":null}
### Alaga Natural Collective
facebookLines() = {"warnings":["Same page ID as 4RO Alaga Natural Collective: this row matches the Facebook name"],"line":"Facebook name: Alaga Natural Collective","link":null}
### Refined Market
facebookLines() = {"warnings":["Same page ID as Refined Optics: this row matches the Facebook name"],"line":"Facebook name: Refined Market","link":null}
### Refined Optics
facebookLines() = {"warnings":["Facebook name: Refined Market","Same page ID as Refined Market: Refined Market matches the Facebook name"],"line":"Facebook name: Refined Market","link":null}
### Banner (rendered HTML, one line per shared ID)
<li data-level="orange">🟠 Same page ID 1085660977960440 as Alaga Natural Collective; Alaga Natural Collective matches the Facebook name  <a href="/chatbot/pages" …>Pages</a></li>   (under 4RO Alaga Natural Collective)
<li data-level="orange">🟠 Same page ID 490249500844090 as Refined Optics; Refined Market matches the Facebook name  <a href="/chatbot/pages" …>Pages</a></li>   (under Refined Market)
```
The frozen Check cell counts the warnings (`checkSummary` from the rendered page, in node):

| Check result | Facebook warnings | Summary |
|---|---|---|
| ok | 2 | `{"icon":"warn","count":2}` |
| failed | 2 | `{"icon":"x","count":3}` |
| not checked yet | 2 | `{"icon":"warn","count":2}` |
| ok | none | `null` |

Not seen in a browser: the Check cell and popup rendering (`el` / `textContent`) were not run in a real DOM.

## How to run
```
composer install   # vendor already present in this worktree
php vendor/bin/phpunit tests/Feature/Chatblast/FacebookNameTest.php
php vendor/bin/phpunit tests/Feature          # full run; Herd PHP 8.4, sqlite in memory
```
Node must be on PATH for the helper tests (they skip without it).

## Rulings
- Ruling: `facebook.name_checked` holds the page ID as a third part, `<time> · <source> · <page ID>`. Why: a stored name must stay tied to the ID it was read for. A lookup for a new ID drops the old name, and the display ignores a name whose ID is not the row's current `botcake.page_id`. Cost if wrong: one extra part in a text value; easy to drop.
- Ruling: "one Graph call per page per run" is read as one try per stored Meta Ads connection, with no rate-limit retry and a stop at the first that answers. A Graph "no answer" (timeout or connection) stops the loop for that page. Why: decision 1 says to try the stored connections in turn. Cost if wrong: M calls per unreadable page per night, M = the number of connections.
- Ruling: in the nightly run all lookups run after the tests and retests. A row whose page ID was already read in the run (by a gateway or Graph) takes that name without a new call. Why: duplicate rows otherwise cost a Graph pass each. Cost if wrong: none; the order of the existing tests is unchanged.
- Ruling: "active row" for the same-ID warning means an active `botcake.page_id` settings row, on or off and listed or not. These are the rows the chat routing (`BotcakeChat::pageMap`) reads. Cost if wrong: a warning on a row staff consider retired; Clear removes it.
- Ruling: the banner line for a shared ID sits under the first row by name and names the others. It is cached with the banner's other sources (60 s). Why: the pair counts once. Caching keeps the per-screen query count inside the page-settings grid budget (`PageSettingsGridTest` allows ≤10). Cost if wrong: the line can lag a Clear by up to 60 s.
- Ruling: the 207 golden JSON snapshots were regenerated for the new `facebook` key. Why: they pin the endpoints' JSON; the only difference is the new block, as checked above. Cost if wrong: none for behaviour; the 207 equality now includes the 208 block.
- Ruling: a gateway Save that fails, or that is refused, records no attempt. Nothing is saved then, as before. The next Botcake Test or nightly run records the attempt. Cost if wrong: one missing "unknown" stamp until the next test.
- Ruling: "Facebook name: unknown" with the link sits in the Connections popup, under the title. The Check column shows "unknown" only inside the same-ID warning. Why: an unknown name on every unreadable row would flood the Check column, while decision 4 warns only on a difference. Cost if wrong: a one-line UI change.
- Ruling: `facebook.name` and `facebook.name_checked` are system keys (`PageSettings::SYSTEM_KEYS`), not editable in the page settings grid. They are not in the Changes list. Cost if wrong: add a label to `SETUP_LABELS`.
- Ruling: `Graph::get` has two optional trailing parameters (`$tries`, `$timeout`). Existing callers keep 4 tries and 120 s.

## Deferred minors
From the skeptic review (no blocker or major left):
- The lookup runs inside the Test / Save request. Worst case: up to 20 s per gateway probe (the existing probe timeouts) plus 10 s for one Graph try. A Graph no-answer now stops the Graph loop. Accepted: it is the same budget the gateway Test already has; moving it after the response is a bigger change.
- A failed gateway Save, or a `connect()` with no name, records no attempt (see the rulings).

Fixed in the fix loop:
- Duplicate rows each doing a Graph pass at night.
- `remember()` not guarded inside connect/retest: now `fromProbe`, with try/report.
- The probe-to-name rule written twice: now one helper.
- The missing tests: gateway Save, failed gateway Test, rate limit, a name from outside as text.
- The reviewer's one major, the 10 failures in the 207 snapshot tests: fixed by the regenerated snapshots.

## Merge danger
Two-way door. Warnings only: no routing, rename, merge or clear. New rows are written only under provider `facebook`. Blast radius if wrong:
- A wrong or missing warning.
- Extra Graph calls at night, bounded by connections × pages.

How to revert: revert the feature commit. The `facebook.*` rows can stay; nothing reads them once the code is reverted.

## Deploy notes
- No migration and no new config. The nightly command is unchanged: `chatbot:connections-check` (scheduled in `routes/console.php`). It now also logs `facebook_names` and `facebook_unknown` counts.
- Likha and Incepxion (same code): restart the queue workers as usual after the deploy; no cache clear needed.
- Names fill in on the next Botcake or gateway Test, or the next nightly run. Until then rows show "Facebook name: unknown" in Connections. The same-ID warnings show right away, since they need no lookup.

## Tests
- `tests/Feature/Chatblast/FacebookNameTest.php`: 12 tests, every case of S-208, with gateway and Graph fakes only (`Http::preventStrayRequests` from the base). It includes node runs of the page helpers.
- Neighbouring folders after the change:
  - Chatblast: 1586 tests; the 10 snapshot failures are fixed, and the folder passes inside the full run.
  - MetaAds: `OK (91 tests)`.
  - PageSettings: `Tests: 149`, no failure.
  - Botcake: `Tests: 94`, no failure.
- Full `tests/Feature`: `Tests: 3817, Assertions: 72259, Failures: 2, Skipped: 4`, the same two known failures as the base.

## Open questions
- Whether the stored Meta Ads user tokens (Login for Business, ads permissions) can read page names at all. The live check covered only the app token. If they cannot, the meta-ads source always answers #100. That costs one call per connection per unreadable page each night, and only the gateway source gives names. Worth one live check after deploy: a Botcake Test on a page without a gateway token, then its `facebook.name_checked` row.

## Process suggestions
- When a change adds a field to a JSON response, check `tests/**/snapshots` golden files first. Evidence: the 207 snapshots failed 10 tests on the first suite run.

## Proposed tasks
- Show `facebook.*` attempts in Changes (a "Facebook name" label), so staff can see when the name was last read — low.

208: done — Facebook name stored from the gateway probe or a Meta Ads token; warnings for a different name and a shared page ID in Check, Connections and the banner; no new test failures
