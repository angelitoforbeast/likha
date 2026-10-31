# Result: spec 207 setup speed

**Status:** done
**Date:** 2026-10-31
**Branch / PR:** `feat/207-setup-speed` (from 5086ad48), not pushed, no PR
**Preview or run link:** n/a (not deployed; the page scripts run in node in the tests)

## Summary
Test and Save and test now send their Botcake reads together in one burst (`BotcakeClient::reads`, built on `Http::pool`). Each read keeps the client's own retry rule and timeout, and the whole burst takes one rate slot. The results are then read and stored in the same order as before, through `tokenFieldsFrom`, `blockStateFrom`, `publishedFrom` and `keywords`. Every staff setup click uses `BotcakeClient::forSetup`: Test, Save and test, Fix chat block, On and Off. Its rate key is `setup:<sha1 token>`, separate from the page's send lane (`sha1 token`). Writes stay in order (content, then publish) with today's retries.

The Check cache key is now built from the page's own inputs:
- its settings print;
- a print of everything its flow reaches: the flow, the flows of the sequences it subscribes to, those sequences and all their steps, and whether it is a step itself;
- its own content answers;
- one digest of the names a check treats as known.

Amendment 207-1: `/chatbot/data` now reads the settings, checks, keyword states and last chats of all pages at once, with no query per page.

So editing one flow only re-checks the pages whose flow reaches it. `/chatbot/data` still carries every cached check. The page re-checks only the rows with none, 2 at a time, rows on screen first (`checkQueue` / `nextCheck`).

## Case table
| Case | Test | Result |
|---|---|---|
| S-207.1 | `ChatbotSetupSpeedTest::test_S_207_1_test_finishes_in_one_round_trip_and_stores_the_same_statuses` | pass (red on base: "Test took 2.6 s / Failed asserting that 2.597… is less than 1.2") |
| S-207.2 | `ChatbotSetupSpeedTest::test_S_207_2_save_and_fix_write_in_order_read_the_default_reply_once_and_answer_as_before` | pass (red on base: "Save and test took 3.06 s … less than 1.7") |
| S-207.3 | `ChatbotSetupSpeedTest::test_S_207_3_a_busy_send_lane_does_not_slow_setup_and_setup_takes_none_of_its_slots` (Test, Save and test, Fix chat block, On, Off) | pass, 5 data sets (red on base: "test waited 4.5 s for the sends", the same for all 5) |
| S-207.4 | `ChatbotSetupSpeedTest::test_S_207_4_a_429_or_failed_read_is_handled_as_before_and_the_other_reads_are_stored` (7 data sets) | pass; characterisation: the same snapshots pass on the base code |
| S-207.5 | `ChatbotCheckCacheTest::test_S_207_5_one_data_request_carries_every_warm_check_and_only_stale_pages_are_checked_two_at_a_time` | pass (red on base: "every page has its cached check" after 30 min, and no `check-queue` block in the page) |
| S-207.6 | `ChatbotCheckCacheTest::test_S_207_6_editing_flow_a_rechecks_only_the_pages_on_flow_a` (4 data sets) and `…_a_flow_edit_that_adds_a_saved_field_rechecks_every_page_that_could_name_it` | pass (red on base: "B1 keeps its cached check / Failed asserting that null is not null", all 4 data sets) |
| S-207.7 | `ChatbotCheckCacheTest::test_S_207_7_check_results_equal_the_base_codes_for_the_same_data` and `ChatbotContentCheckTest::test_S_207_7_an_image_answer_saved_by_another_page_rechecks_a_page_waiting_for_it` | pass; the snapshot comes from the base code. The second test was red on the first version of the per-page key and is fixed in bdb147fa. |
| S-207.8 | `ChatbotCheckCacheTest::test_S_207_8_check_again_forces_one_row_and_the_next_load_shows_it` | pass (passes on base too: unchanged behaviour) |
| S-207.9 (amendment 207-1) | `ChatbotDataSpeedTest::test_S_207_9_chatbot_data_with_117_pages_makes_a_bounded_number_of_queries_and_the_same_json` | pass (red on base: "cold: no query runs once per page: select * from "cache" where "key" in (?) / Failed asserting that 473 is equal to 10 or is less than 10"); the JSON snapshot comes from the base code |

## Story changes
- S-207.3: the case names Test only; it now covers all five setup actions from decision 3 (Test, Save and test, Fix chat block, On, Off). It also checks that the sends' slot is left untouched. Reason: decision 3 names all five actions.
- S-207.4: "retried as today" made concrete. A read in Test or Save and test was one try before (`once()`), so it still is; a write (publish) is retried on 429 as before. Reason: the base code shows it.
- S-207.7: added a case for a shared image answer. When a page is waiting for an AI answer and another page saves it, the waiting page re-checks. Reason: the review found that the per-page key missed it.
- S-207.9: added by amendment 207-1. /chatbot/data with 117 pages: a bounded number of queries and the same JSON. The test runs it with a cold and with a warm cache, both in the database store as in production.

## Done-when checklist
- [x] P1 pass with tests, failing first where timing or call counts show it. The red runs are in the case table: `.1`, `.2`, `.3`, `.5` and `.6` were red on the base or on the first version. `.4`, `.7` and `.8` pin today's behaviour, from snapshots recorded on the base code.
- [x] tests/Feature has no new failures against the base: 3805 tests, 2 failures, 4 skipped on 88add73c. The two failures are the known ones (ExampleTest::test_the_application_returns_a_successful_response, ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order). Base 5086ad48: 3781 tests, the same 2 failures, 4 skipped.
- [x] Before/after timings from the tests: see the table below. The routes to compare in the nginx log after deploy are under Deploy notes.
- [x] Deploy notes: below.
- [x] The final line: at the end.

### Timings (tests: every Botcake call takes 500 ms; elapsed = wall time + client waits)
| Action | Before (5086ad48) | After |
|---|---|---|
| Test | 2.60 s | 0.56 s |
| Save and test | 3.06 s | 1.54 s |
| Fix chat block | 1.53 s | 1.53 s (one read, then two writes in order) |
| Test, send lane busy for 4 s | 7.05 s | 0.51 s |
| Save and test, send lane busy | 7.57 s | 1.54 s |
| Fix chat block, send lane busy | 6.03 s | 1.54 s |
| On, send lane busy | 6.02 s | 1.53 s |
| Off, send lane busy | 5.01 s | 0.51 s |
| /chatbot/pages load, 117 pages, warm | 1 data request, 0 check calls (only within 10 min, and none after any flow edit) | 1 data request, 0 check calls for 1 hour; a flow edit re-checks only the pages that use it |
| Same, 3 stale pages | 3 calls, 3 at a time | 3 calls, at most 2 at a time, rows on screen first |

Commands: `SHOW_207_TIMINGS=1 php vendor/bin/phpunit tests/Feature/Chatblast/ChatbotSetupSpeedTest.php`. For the "before" column, the same file was run with `app/` stashed back to the base.

### /chatbot/data (amendment 207-1)
Where the time went, measured locally with 117 pages, the cache in the database as in production, and 1,500 chats per page (175k rows). The profile used a scratch query listener, not committed; the bounded count is pinned by S-207.9.

| Load | Before (5086ad48) | After |
|---|---|---|
| Cold (settings, chat stats and page map not cached) | 861 queries, 334 ms | 68 queries, 186-263 ms |
| Warm | 381 queries, 111 ms | 42-44 queries, 62-92 ms |

The per-page parts before:
- 117 `MAX(received_at)` on botcake_chats (with the JSON standby filter);
- 117 page-settings cache reads plus 117 page-settings queries when cold;
- 117 check cache reads;
- 234 reads of the AI site settings;
- one `botcake.keywords` query per tested page.

After: one statement for every page's last chat. Each page has its own `ORDER BY received_at DESC LIMIT 1` subquery on the (page_id, received_at) index, so a page with many chats stops at its newest non-standby chat and does not scan them all. Beside it:
- one cache read for all settings, and one query for the missing ones;
- one cache read for all checks;
- one keywords query;
- one AI site read.

The live 1.7-1.9 s cannot be reproduced here: MySQL round trips over the network, and the size of the live chats table. Most of it should be the 117 MAX() scans and the ~470-880 round trips that are now gone. Check it with `rt=` on `GET /chatbot/data` after deploy (target under 0.5 s).

## How to run
```
composer install   # vendor already present in the worktree
php vendor/bin/phpunit tests/Feature/Chatblast/ChatbotSetupSpeedTest.php tests/Feature/Chatblast/ChatbotCheckCacheTest.php
php vendor/bin/phpunit tests/Feature/Chatblast/ChatbotContentCheckTest.php --filter S_207_7
php vendor/bin/phpunit tests/Feature        # the whole suite (node needed for the page-script tests)
```
`WRITE_207_SNAPSHOTS=1` rewrites `tests/Feature/Chatblast/snapshots/207/*.json`. Use it only on the base code, since the snapshots are its answers.

## Rulings
- Ruling: Test and Save and test read `/flows` (and Test reads `/default_reply`) in the same burst even when they turn out not to be needed. Why: the reads do not depend on each other (decision 1), and the stored results are unchanged. Cost if wrong: one or two extra Botcake reads per click.
- Ruling: when the token read fails, nothing else is stored, even though the other reads of the burst came back. Why: decision 1 says the stored results are exactly as before, and before, nothing else was read. Case .4's "other reads still stored" applies to the reads after a working token. Cost if wrong: a Test with a failing token shows no chat block or keyword line, as before.
- Ruling: one rate slot per burst on the setup key. Writes after the burst take their own slot (2/s), which they usually find already free because the previous call took longer. Why: decision 3. Cost if wrong: see Open questions (Botcake's real limit).
- Ruling: "+ Page", `botcake:chat-setup` and `chatbot:connections-check` keep the send lane. Why: decision 3 lists only Test, Save and test, Fix chat block and On/Off. Cost if wrong: + Page can still wait behind sends.
- Ruling: a check result is kept 1 hour instead of 10 minutes. It is kept 10 minutes while some image link or content check is not done, and it is dropped as soon as any new content answer is saved while it waits for one. Why: the key now changes with the page's own inputs, so the time limit only covers inputs outside the key (orders/ads for the expected item, ad videos, image link health, comment events). Decision 4 asks to re-check only stale or changed pages. Cost if wrong: a change in those outside inputs shows up to 1 hour late. Check again forces a fresh run.
- Ruling: the names a check treats as known are in every page's key as one digest: botfields, system variables, fields saved by any flow, and stored customer keys. Why: decision 6 (same results). A flow edit that adds a saved field can change any page's "(unknown)" reason. A plain text edit does not change the digest, so decision 5 still holds for it. Cost if wrong: a new saved field or customer key re-checks every page once.
- Ruling: content answers count per page by `page_name`. Shared image and video answers are handled by the "waiting for an answer" rule above. Why: text answers are tied to the page's own setting rows; image and video answers are not. Cost if wrong: none known.
- Ruling: the flow-reach prints and the saved-field names are computed once per change of the flow tables (cached under their MAX(updated_at)/COUNT, as the old global key was). Why: it reads every flow graph only once per change. Cost if wrong: two flow writes in the same second with the same row count look like one change, the same weakness the old key had.
- Ruling: /chatbot/data reads the settings of every listed page in one go (`PageSettings::preload`). That is one Cache::many, then one query for the pages not in the cache. What that query finds is kept in the request's memo only, never written to the cache. Why: a `set()` that commits while the list is being read must not be covered by an old map for 10 minutes; the bot reads the same cache. Cost if wrong: a cold load runs one query for its missing pages each time, until another path fills their cache.
- Ruling: the keyword state of every page comes from one read of each page's newest non-unknown `botcake.keywords` row (`keywordsOnAll`), not the full history. Why: one query instead of one per page, and the read does not grow with every Test. Cost if wrong: keyword status rows are never secret, so a secret one would be skipped.
- Ruling: the last chat of every page comes from one statement with one `ORDER BY received_at DESC LIMIT 1` subquery per page (in chunks of 100), not 117 `MAX()` queries. Why: one round trip. Each subquery stops at the newest non-standby chat on the (page_id, received_at) index, where MAX() with the JSON filter scanned every chat of the page. Cost if wrong: none known; it is valid SQL on MySQL 8, pgsql and SQLite.
- Ruling: the timing tests answer each fake call 500 ms after it was sent, with a promise. Laravel's fake waits on that promise once for its transfer stats, and that first wait returns at once. The client clock is wall time plus recorded waits. Why: Laravel's `Http::fake` otherwise forces pooled calls to run one after another. Cost if wrong: test-only.

## Deferred minors
- A burst of 4-5 reads on top of the page's own 2/s sends can reach about 7 requests in one second. Botcake's real limit is unknown; a test once saw no 429 up to 5/s. A 429 on a `once()` read in Test stamps `token_status` error or `chat_block` unknown until the next Test. Accepted because the decisions fix the burst and the separate key, and nothing hides a problem: the error is shown. Left as an open question.
- A page whose content check never finishes (AI down) is re-checked on the next load each time any content answer is saved anywhere. Accepted: it costs time but gives no wrong result.
- A change of expected item (page item settings, the 3-day orders window, ads) on a page with no pending content check shows up to 1 hour later instead of 10 minutes. Accepted under the 1-hour ruling; Check again forces it.
- A non-connection transfer error inside the pool (for example too many redirects) now counts as "no answer" (status 0) instead of throwing. Accepted: it is an edge case, and it is handled like a dropped connection.
- A reload while a check queue is still running starts a second queue (up to 4 checks at once). Accepted: the old code did the same.
- `BotcakeClient` catches `LockTimeoutException` without importing it, so that catch never matches (a pre-existing bug, not touched). Listed under Proposed tasks.

- Bulk settings read: MySQL's case-insensitive collation treats page names that differ only by accent ("Cafe" and "Café") as the same, but the bulk read matches them in PHP and drops those rows. Accepted: the page still gets its own rows. Such pairs are not known to exist.
- `keywordsOnAll` groups pages by `pageKey()`, which also collapses double spaces inside a name. A row stored as "Page  Uno" now counts toward "Page Uno", which `keywordsOn()` never matched. Accepted: the same mapping `prints()` already uses.

## Merge danger
A two-way door. Blast radius: the Connections buttons and On/Off on /chatbot/pages (live chat routing setup) and the Check column. If Test or Save misbehaves, staff see a wrong status until the next Test. If the Check key is wrong, a page could show an old result for up to an hour, or until Check again. Revert: revert the six feature, perf and fix commits; they touch only the files of this spec plus `PageSettings::preload`, `ChatbotAiSettings::row` and `ChatbotAiChain::forPage` (each has a new optional argument). The new cache keys and entries are new names and shapes, so old entries are ignored and expire. No migration and no data change.

## Deploy notes
- No migration, no new config or env, no queue change. The Blade view changed: run `php artisan view:clear` (or the usual deploy's view cache step) on Likha and Incepxion. No `npm run build` is needed (the script is inline).
- No cache clear is needed: the check key and entry format changed (old entries are ignored and expire within 10 minutes). After any later change to the Check logic itself, clear `chatbot:page-check:*`, since results now live up to 1 hour.
- Restart the PHP-FPM workers or reload opcache as usual, so the web requests pick up `BotcakeClient`. Queue workers do not use the changed paths, but restarting them is harmless.
- Routes to compare in the Incepxion nginx log (`rt=` and `urt=` at the end of each line), before and after:
  - `POST /chatbot/connections/botcake-test` (Test): expect about one Botcake round trip, ~0.3-0.5 s.
  - `POST /chatbot/connections/botcake` (Save and test): expect about 3 round trips.
  - `POST /chatbot/connections/chat-block` (Fix chat block).
  - `POST /chatbot/active` (On/Off).
  - `GET /chatbot/connections` (the modal's info, DB only).
  - `GET /chatbot/check` (the count per page load should drop to the stale rows only) and `POST /chatbot/check` (Check again).
  - `GET /chatbot/data` (one per load): was 1.7-1.9 s on 2026-10-31; the target is under 0.5 s (amendment 207-1).

## Tests
- New: `tests/Feature/Chatblast/ChatbotSetupSpeedTest.php` (14 tests), `tests/Feature/Chatblast/ChatbotCheckCacheTest.php` (8 tests), `tests/Feature/Chatblast/ChatbotDataSpeedTest.php` (1 test), one test in `ChatbotContentCheckTest.php`, and snapshots in `tests/Feature/Chatblast/snapshots/207/` recorded from the base code.
- The touched files' existing tests pass: ChatbotConnectionsTest, BotcakeClearTest, BotcakeChatAdTest, BotcakeMockTest, ChatbotPageCheckTest, ChatbotContentCheckTest, ChatbotFrozenCheckTest, ChatbotPagesTest, PageAutoSetupTest, BulkTokenTestTest, GatewayPerPageTest.
- Full tests/Feature: 3805 tests, 2 failures, 4 skipped on 88add73c. The two failures are the known ones (ExampleTest::test_the_application_returns_a_successful_response, ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order). Base 5086ad48: 3781 tests, the same 2 failures, 4 skipped.
- Skeptic review (decisions 3 and 5): 2 majors, both fixed in one loop (bdb147fa) and closed on re-check.
- Review of the amendment (34f431c1): no blocker or major. The race of the bulk settings read and the growth of the keywords read were fixed anyway. The other minors are under Deferred minors.

## Open questions
1. Botcake's real per-page rate limit is unknown. Test now sends 5 reads at once and Save and test sends 4, on top of the page's 2/s sends. Is it worth logging setup reads that get a 429 after deploy, to learn the limit? A 429 on a Test read shows as a token error or "chat block unknown" until the next Test.

2. The amendment target (/chatbot/data under 0.5 s on the live server) can only be confirmed from `rt=` after deploy. Locally: 861 to 68 queries cold, 381 to 42-44 warm.

## Process suggestions
- Laravel's `Http::fake` waits on every fake response while it is being sent, so a test of `Http::pool` concurrency sees the calls run one after another unless the fake's first wait returns at once. Evidence: the first run of S-207.1 showed 5 calls 0.51 s apart even with the pool in place. The test explains the workaround.

## Proposed tasks
- Import `Illuminate\Contracts\Cache\LockTimeoutException` in `BotcakeClient` (its two `catch` blocks never match today) — a lock timeout throws instead of sending without the lock — normal.
- Log setup-read 429s (page, read, time) for a week after deploy, to learn Botcake's limit — the burst size depends on it — low.

207: done — Test answers in one Botcake round trip (0.56 s vs 2.6 s on 500 ms fakes), setup clicks no longer wait on sends, the Check column re-checks only pages whose own inputs changed (2 at a time), and /chatbot/data runs 68 queries cold instead of 861
