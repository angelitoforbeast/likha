# Result 233: page id on botcake_psids

## Status

Done, not pushed, not deployed. Branch `feat/233-psid-page-id` from `f175cb2f`, 11 commits. All 12 cases pass (S-233.4.1 through the existing tests); the full suite has only the 2 known pre-existing failures. One new migration (`2026_11_18_100000_add_page_id_to_botcake_psids.php`) and one new command (`botcake:psid-page-id-backfill`). High tier: tasks 1–3 reviewed by `skeptic-reviewer` at adversarial depth, task 4 at standard depth; 2 majors (task 1) fixed in one loop; minors fixed on untrusted paths or accepted in `TODO.md`.

### Merge danger

- **Kind:** a two-way door for the code, a mostly one-way door for the data. The column and indexes can be dropped (`down()` is there); the backfill only writes `page_id` on rows where it was null, and nothing else, so its effect can be undone by setting `page_id` back to null (it records nothing else).
- **Blast radius:** order matching (both matchers), the Botcake customer sync, the `/api/botcake/chat` PSID check, the gateway name, the Inbox, the live feed, chatbot tags, Chatblast candidates, ad groups, the customers page, PSID coverage and the page settings grid. For pages never renamed the rows they find are the same as before.
- **Deploy order, on Likha and on incepxion.pro:**
  1. Pull the code and run `php artisan migrate --force` right away. Run it when no long sync is running (`botcake:sync-customers`), because each `ADD INDEX` briefly takes a metadata lock that waits for open transactions on `botcake_psids`, and new writes queue behind it while it waits. Expect about a minute on Likha.
  2. `php artisan queue:restart`, so no worker keeps the old code or the memo "no page_id column" from before the migration.
  3. `php artisan botcake:psid-page-id-backfill --max-chunks=1` to see one chunk's counts and time, then `php artisan botcake:psid-page-id-backfill`. Read the names it lists: those pages' old rows keep matching by name.
- Code that runs before the migration (for example, workers between the pull and the migrate) falls back to the name, as today; nothing errors.
- **Revert:** revert the commits; the column can stay (nothing else reads it) or be dropped with `migrate:rollback --step=1`.

## Plan

### What the code does today (from reading it)

Writers of `botcake_psids` (grep of `app/`):

| Writer | Path | Knows the page id? |
|---|---|---|
| `BotcakeChatGuard::checkPsid` → `create` (source `chat_verify`), called by `BotcakeChatController` (`/api/botcake/chat`) | chat | yes, `$pageId` is a parameter |
| `BotcakeCustomerSync::store` → `create` / `save` (source `api`), and the `inactive` update in `syncPage` | Botcake API (`botcake:sync-customers`, Sync all) | yes, `syncPage` already reads `botcake.page_id` from settings |
| `BotcakePsidMatcher::recordImported` / `recordWaiting` → `create` (source `sheet`); used by `ImportBotcakePsidFromGoogleSheet` and `BackfillBotcakePsidWaiting` | sheet | no, from page settings by name |
| `BotcakeStressPage` → `insert` (source `stress_test`) | stress tool | yes, `$pageId` |

`HandleGatewayEvent` does **not** write `botcake_psids`: it stores `botcake_chats` and only reads `botcake_psids` for the customer's name. See Story changes.

Readers that pick rows by page, and how they compare the page today:

| Reader | Page compare today | Page id available |
|---|---|---|
| `HandleGatewayEvent::name` | by psid, then PHP `pageKey` | `$e['page_id']` |
| `BotcakeChatGuard::checkPsid` (is the PSID on the page) | by psid, then PHP `pageKey` | `$pageId` |
| `BotcakeCustomerSync::store` (existing rows) and inactive update | `page = ?` exact | settings |
| `BotcakeCustomerMatcher::matchPage` candidates and the `$adSql` raw subquery | `LOWER(TRIM(page)) = key` | settings |
| `BotcakePsidMatcher::tableQuery` (sheet rows; used by evaluate, recordImported, copyReorders) | `LOWER(page) = ?` | settings |
| `ChatblastController::pageCustomers` (candidates, test PSID) | `LOWER(TRIM(page)) = key` | settings |
| `AdGroups::latestAd` | by psid, PHP `pageKey` | `$pageId` |
| `AdGroups` page list (line ~1024) | `LOWER(TRIM(page)) = key` | settings (already read there) |
| `Inbox` list names and `Inbox::panel` | by psid, PHP `pageKey` | `$pageId` |
| `LiveFeed` names | by psid, keyed `pageKey|psid` | `$pageId` |
| `ChatbotTags::adId` | by psid, PHP `pageKey` | `$pageId` |
| `BotcakeCustomersController::index` (`/page-settings/customers`) | `page = $apiPage` exact | settings |
| `PsidCoverageController::ordersQuery` raw count subquery | `b.page = ?` exact | settings |
| `PageSettingsGrid::info` (customers per page) | `GROUP BY LOWER(TRIM(page))` | settings map |

Left as is, with the reason: `BotcakePsidMatcher::recordImported` / `recordWaiting` "already recorded?" lookups (`setting_id` + the sheet's own page text + name + psid: they dedupe a sheet row by its own text, which a rename doesn't change) and `cleanSheet` (by `setting_id` + psid, no page). `BotcakeStressPage` cleanup (deletes its own `stress_test` / `chat_verify` rows by name; out of scope, no deletes change). `macro_output.PAGE` compares stay by name (out of scope).

### Design

1. **Column and indexes** (migration only adds): `page_id` `string(64)` nullable, appended at the end (MySQL 8 adds it INSTANT), and three indexes:
   `(page_id, psid)` for the psid-and-page lookups and the ad group page list (it has no `source` filter);
   `(source, page_id, status, name_key, psid)` and `(source, page_id, status, phone_key, psid)`, the page-id twins of the two existing `(source, page, status, …)` indexes used by the matcher, Chatblast, the customers page and PSID coverage. `page_id` goes into `$fillable`.
2. **One rule, two helpers on `BotcakePsid`** (the rule is used by ~14 readers, so it lives in one place):
   - `scopeOfPage($q, ?string $pageId, Closure $byName)`: page id empty → `$byName($q)` (exactly today's query); otherwise `page_id = ? OR (page_id IS NULL AND <$byName>)`. Each reader passes its own current name condition as `$byName`, so for a page that was never renamed the result is the same as today, compare for compare (exact, `LOWER(TRIM())`, `LOWER()`).
   - `onPage(?string $pageId, string $pageKey): bool` for the readers that filter in PHP: row has a page id and the caller has one → ids equal; else `pageKey(row.page) === $pageKey` (today's check).
   - The name → id step uses `PageSettings::get($page, 'botcake', 'page_id')` (already cached, already used by the sync and ad groups). Raw SQL (`$adSql`, PSID coverage) gets `(bp.page_id = ? OR (bp.page_id IS NULL AND <today's compare>))` when the id is known, today's SQL otherwise; the syntax is the same on mysql, pgsql and sqlite.
3. **Writers** set `page_id` on insert, and on update only where the row has none yet (`page` is never changed):
   chat path from `$pageId`; API sync from the settings value `syncPage` already reads (passed into `store`); sheet path from `PageSettings::get(page, 'botcake', 'page_id')` (null when the page has no id: the row is still stored); stress tool from `$pageId`.
   A renamed page's sync now finds its old rows by id, so it updates them instead of inserting a second row per PSID under the new name.
4. **Backfill command** `botcake:psid-page-id-backfill {--chunk=5000} {--sleep=200 : ms between chunks} {--max-chunks=0 : 0 = until done}` (new file `app/Console/Commands/BackfillBotcakePsidPageId.php`):
   - map: `pageKey(name) => id` from `BotcakeChat::pageMap()` (active `botcake.page_id`); a key with more than one id is not used (left null, reported as ambiguous).
   - loop: `SELECT id, page WHERE page_id IS NULL AND id > :last ORDER BY id LIMIT :chunk` (primary key range, no long scan); group the chunk by `pageKey(page)`; per known page one `UPDATE … SET page_id = ? WHERE id IN (…) AND page_id IS NULL` (idempotent, safe next to a live writer); `last` = the chunk's max id; `usleep` between chunks.
   - prints: filled, no matching page (rows), ambiguous (rows), left (null rows in the table at the end), and one line per page name without an id with its row count (at most 50 lines, the largest first, control characters stripped and names cut to 80 characters: sheet page text is untrusted). Page names only; never customer names or PSIDs. `Log::info` gets the counts only.
   - `--max-chunks` stays: it is how an operator runs a short trial on live data first, and how S-233.2.4 stops a run part-way.
   - Resumable and idempotent by construction (only `page_id IS NULL` rows; a stopped run leaves the rest null for the next one).
   - Pages already renamed before this deploy: their old name is no longer in the active map, so those rows stay null and are reported by name (see Open questions).
5. **Deploy order** (for Merge danger): migrate → deploy code → `queue:restart` → run the backfill, on Likha and on incepxion.pro. Code before the backfill is correct, just name-matched for old rows (null `page_id` falls back to the name). The restart matters: rows written by workers still on old code would stay null until a later backfill run.
6. **Column guard** (from the spec review): the migration arrives with the code pull and is run by hand afterwards, so for a short while new code can run before the column exists. A cached `BotcakePsid::hasPageId()` (`Schema::hasColumn`, as `BotcakeCustomerMatcher` already does for `botcake_ad_id`) makes the writers skip `page_id` and `scopeOfPage` / `onPage` / the raw SQL fall back to today's name compare until the column is there. One test.
7. **Bulk UPDATEs never carry the OR** (from the spec review): before the backfill every row is null, and `page_id = ? OR (page_id IS NULL AND page = ?)` inside an UPDATE could range-lock all null rows of the index under REPEATABLE READ. The mutating statements (the sync's inactive update; `BotcakePsidMatcher`'s conflict/expire updates through `tableQuery`) run as two statements: the id branch, then `page_id IS NULL AND <name>`. Reads keep the single OR. `scopeOfPage` always wraps the OR in a nested `where()` so callers' other conditions (`source`, `status`) stay ANDed.
8. **PageSettingsGrid::info**: counts grouped by `(page_id, LOWER(TRIM(page)))`; a group with a page id is added to the page whose id it is (pageMap id → name → pageKey), a null group to the page by its name key; each row is counted once.
9. **Chat path note**: for a page with no `botcake.page_id` in settings the chat path still gets a page id from the request (Botcake confirmed the PSID with that page's token), and stores it; that is the id Botcake uses, so it is kept.

### Tasks (all backend; no UI changes)

| # | Task | Tier | Cases | Files | Parallel |
|---|---|---|---|---|---|
| 0 | Stories into `qa/stories.md` (slice 233, with the story changes below) | low | – | `qa/stories.md` | – |
| 1 | Migration, model (`fillable`, `scopeOfPage`, `onPage`), backfill command | high | S-233.2.1–.5 | migration, `BotcakePsid`, new command, `tests/Feature/Botcake/BotcakePsidPageIdBackfillTest.php` | no (others use the helpers) |
| 2 | Order matching path: writers and readers in `BotcakeCustomerSync`, `BotcakeCustomerMatcher`, `BotcakePsidMatcher`; `BotcakeStressPage` insert | high | S-233.1.2, S-233.1.3, S-233.3.1 (both matchers), S-233.3.3 | those 4 + `tests/Feature/Botcake/BotcakePsidPageIdTest.php` | no (shares the case test file) |
| 3 | Chat path: `BotcakeChatGuard` (writer + check), `HandleGatewayEvent::name`, `Inbox`, `LiveFeed`, `ChatbotTags` | high | S-233.1.1, S-233.3.1 (Inbox), S-233.3.2 | those 5 + the case test file | no |
| 4 | Page lists: `ChatblastController::pageCustomers`, `AdGroups` (latestAd + page list), `BotcakeCustomersController`, `PsidCoverageController`, `PageSettingsGrid::info` | medium | S-233.3.1 (Chatblast, ad groups) | those 5 + the case test file | no |
| 5 | Run-time measurement, full suite, result | low | S-233.4.1 | result only | – |

- Tests: one test per case, named `test_S_233_x_y_…`. S-233.3.1 is one test with a data provider, one row per changed reader: both matchers, Chatblast candidates, ad group page list and `latestAd`, Inbox list and panel, `LiveFeed`, `ChatbotTags::adId`, `HandleGatewayEvent::name`, `BotcakeChatGuard::checkPsid`, the customers page, PSID coverage and `PageSettingsGrid::info`; each task adds its rows red first. Extra rows from the spec review, added as data-provider rows of the case they prove (no new cases):
  - S-233.1.2: an existing API row without `page_id` gets it; a row with one is not overwritten; after a rename the sync updates the old row and inserts no second row for the PSID.
  - S-233.3.2: after a rename the inactive update touches only the page's own id rows; a `chat_verify` row with the same id stays out of Chatblast and the matcher's `source = 'api'` candidates.
  - S-233.2.2: a name shared by two ids is left null and reported as ambiguous; S-233.2.3: rows that already have a `page_id` are untouched; S-233.2.1: the log context holds counts only.
  - S-233.1.3: with the column missing, writers and readers behave as today (the guard in design 6).
- S-233.4.1 is the existing matcher, Chatblast, ad group, Inbox and PsidCoverage tests run unchanged (no new test; see Rulings).
- Run time per 100,000 rows: a throwaway test (not committed) seeds 100,000 rows over 3 pages in sqlite and times one `--sleep=0` run; the result reports it and adds the sleep (20 chunks × 200 ms = 4 s per 100,000). sqlite in memory is faster than live MySQL; the result says so.
- Online DDL note for the result: MySQL 8 adds the nullable column INSTANT; each `ADD INDEX` is INPLACE with `LOCK=NONE` (reads and writes continue), taking a short exclusive metadata lock at start and end that waits behind any open long transaction on the table, and new writes queue behind that wait. Estimate for ~800,000 rows: seconds to a minute per index; the result gives the reasoning, not a live measurement (no server access).
- Reviews: tasks 1–3 `skeptic-reviewer` adversarial (opus), task 4 standard (sonnet); low tasks by lint and tests here.

## Summary

Every `botcake_psids` row can now carry its Facebook page id, and every reader that looks a customer up by page uses the id first and the page name only for rows without an id (or a page without one). A page renamed in page settings keeps finding its old rows; a name reused by another page finds only its own rows; rows without an id match by name as before. Nothing renames, merges or deletes rows; `page` is untouched.

**Backfill command:** `php artisan botcake:psid-page-id-backfill` (`--chunk=5000`, `--sleep=200` ms between chunks, `--max-chunks=0` = until done). It reads `page_id IS NULL AND id > last` by primary key in chunks, matches each row's page through `PageSettings::pageKey` against every `botcake.page_id` in page settings, active and history, and updates by id with `page_id IS NULL` repeated in the UPDATE (a live write in between is never overwritten). A name that ever had two or more ids stays null and is listed as "more than one page id"; an old (replaced) name's id fills only rows made on or before that name's last `replaced_at`. It prints `Filled`, `No matching page`, `More than one page id`, `Left without page id` and up to 50 names (largest first, control characters stripped, 80 characters at most) with their row counts; the log gets the counts only.

**Run time per 100,000 rows** (throwaway seeded test, not committed: 100,000 rows over 4 names, 3 with ids, in-memory sqlite, `--sleep=0`): **1.39 s**, peak memory 66 MB; a second run took 0.05 s. Output: `Filled: 75000`, `No matching page: 25000`, `Left without page id: 25000`, `Lost Page: 25000`. With the default sleep add 20 chunks × 200 ms = **4 s per 100,000**, so about 5.4 s per 100,000 here. Live MySQL is slower than in-memory sqlite (each 5,000-row UPDATE by primary key writes to disk and to three new indexes); a fair guess is 5 to 15 s per 100,000 on the live server, so about 1 to 2 minutes for Likha's ~800,000 rows. Run `--max-chunks=1` first to see the counts and the time of one chunk.

Per task (all backend; no screen changed):

| # | Task | Tier | Commit | Files |
|---|---|---|---|---|
| 0 | Stories, slice 233 | low | `0f658061` | `qa/stories.md` |
| 1 | Migration, backfill command | high | `dd9f9658`, fix `0d5ca6c2` | `database/migrations/2026_11_18_100000_add_page_id_to_botcake_psids.php`, `app/Console/Commands/BackfillBotcakePsidPageId.php`, `tests/Feature/Botcake/BotcakePsidPageIdBackfillTest.php`, the two test bases |
| 2 | Model helpers; order matching and the API sync | high | `e3d12a89` | `app/Models/BotcakePsid.php` (`page_id` fillable, `hasPageId`, `pageIdOf`, `pageIdAttr`, `withPageId`, `scopeOfPage`, `updateOfPage`, `onPage`), `BotcakeCustomerSync`, `BotcakeCustomerMatcher`, `BotcakePsidMatcher`, `tests/Feature/Botcake/BotcakePsidPageIdTest.php` |
| 3 | Chat path | high | `42bff3f6` | `BotcakeChatGuard`, `HandleGatewayEvent::name`, `Chatbot\Inbox`, `Chatbot\LiveFeed`, `Chatbot\ChatbotTags` |
| 4 | Page lists | medium | `167e3693` | `ChatblastController::pageCustomers`, `Chatbot\AdGroups` (`latestAd`, `seen`), `BotcakeCustomersController`, `PsidCoverageController`, `PageSettingsGrid::info` |
| – | Final minors wave | – | `c130b906` | `BotcakePsidMatcher` (a retried record uses its own page id), `LiveFeed` (`id:` / `name:` keys) |
| 5 | Minors in TODO.md, result | low | `cdf02468` and this commit | `TODO.md`, this file |

Notes on the build:
- The model helpers were planned in task 1 but landed in task 2's commit, next to their first tests.
- The migration was first named `2026_11_05_200000`; the repo already has migrations up to `2026_11_17_100000`, so it was renamed `2026_11_18_100000` to run last. It never ran anywhere but the in-memory test database.
- The test bases `BotcakeApiTestCase` and `BotcakePsidTestCase` now run the new migration, and `BotcakePsidTestCase` also runs the page settings migrations (production always has that table; the sheet matcher now reads `botcake.page_id`). No test body changed.
- `LiveFeed` selects every column of the few PSID rows it reads, so the poll asks the schema nothing; the existing poll query-count tests (S-199.8, S-200.4, S-205.6) compare two polls and failed while the one-time column check sat in the first poll.
- No subagent developers: this session had no `backend-developer` agent, so the main session wrote the code test-first and `skeptic-reviewer` reviewed each task.

## Checks

PHP is `"/c/Users/Forbeast/.config/herd/bin/php.bat"`, shortened to `php`. Tests run with `php vendor/bin/phpunit`.

**Red runs** (first failure per slice, before the code):

| Slice | Red |
|---|---|
| Task 1 | `BotcakePsidPageIdBackfillTest`: 5 errors, `CommandNotFoundException: The command "botcake:psid-page-id-backfill" does not exist.` |
| Task 1 fix | against the old command with the `whereNull` guard taken out: `S_233_2_2 … Failed asserting that two arrays are identical.` and `S_233_2_3_a_row_written_by_a_live_writer … Failed asserting that two arrays are identical.` |
| Task 2 | 8 failures, e.g. `S_233_1_2 … ('new') Failed asserting that two arrays are identical.`, `S_233_3_1 … ('customerMatcher') Failed asserting that null is identical to 'psid-a'.`, `S_233_3_2 … ('inactive') Failed asserting that two arrays are identical.` (one more error was a test-helper bug, fixed). S-233.1.3 "no id" and S-233.3.3 passed before the change: they pin today's behaviour. |
| Task 3 | 7 failures, e.g. `('gatewayName') Failed asserting that null is identical to 'Ana Cruz'.`, `S_233_1_1 … Failed asserting that two arrays are identical.`, `('chatReaders') Failed asserting that 'Xavier Lim' is null.` The LiveFeed row first errored on a missing test migration (`no such column: via`), so its own red was not seen cleanly; its same-name row was red. |
| Task 4 | 7 failures, e.g. `('settingsGrid') Failed asserting that 0 is identical to 1.`, `('latestAd') Failed asserting that null is identical to '120250000000000001'.`, `('coverage') Failed asserting that two strings are identical.` |
| Minors wave | `('psidRetry') Failed asserting that two strings are identical.`, `S_233_3_3 … Failed asserting that 'Dina G.' is null.` |

**Green, affected files** (last runs): `BotcakePsidPageIdBackfillTest` OK (6 tests, 23 assertions); `BotcakePsidPageIdTest` OK (27 tests, 70 assertions); `tests/Feature/Botcake` OK (129 tests, 492 assertions); `PsidCoverageTest`, all `tests/Feature/PageSettings`, `AdGroup*`, `Chatblast*`, `BotcakeChat*`, `ChatbotInbox*`, `ChatbotTagsTest`, `ChatbotLive*`, `AdOpen*`: OK.

**Lint:** `php -l` on every changed PHP file: no syntax errors.

**Full suite** (`php vendor/bin/phpunit`, on `cdf02468`):

```
Tests: 4555, Assertions: 77616, Failures: 2, PHPUnit Deprecations: 95, Skipped: 6.
1) Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response
2) Tests\Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order
Time: 13:06.009, Memory: 862.00 MB
```

Both failures are the known pre-existing ones named in the spec; no new failure. BigBroadcastTest and AstraAddressRulesTest S-26.18 passed this run.

Kit hook tests (`node --test 'test/hooks/*.test.mjs'`): 1075 tests, 1026 pass, 5 fail, all in `format-on-edit` on `symlink` with `EPERM` (this Windows account may not create symlinks). This branch changes nothing under `test/` or `.claude/`.

**Case table**

| Case | Test | Result |
|---|---|---|
| S-233.1.1 | `test_S_233_1_1_a_verified_chat_stores_its_psid_row_with_the_page_id` | pass |
| S-233.1.2 | `test_S_233_1_2_the_api_sync_stores_the_page_id_from_settings` (new rows; an older row without a page id gets it; after a rename the old row is updated, not doubled) | pass |
| S-233.1.3 | `test_S_233_1_3_a_page_without_an_id_still_stores_its_row` (a page without `botcake.page_id`; before the column is migrated) | pass |
| S-233.2.1 | `test_S_233_2_1_rows_of_three_named_pages_are_all_filled` (also: the log holds counts only) | pass |
| S-233.2.2 | `test_S_233_2_2_a_name_without_one_page_id_stays_null_and_is_reported` (no page; two ids; an old name's row made after the rename; cleaned, cut name) | pass |
| S-233.2.3 | `test_S_233_2_3_a_second_run_changes_nothing`, plus `test_S_233_2_3_a_row_written_by_a_live_writer_during_the_run_is_not_overwritten` (extra, from the review) | pass |
| S-233.2.4 | `test_S_233_2_4_a_run_stopped_part_way_finishes_on_the_next_run` | pass |
| S-233.2.5 | `test_S_233_2_5_names_differing_in_case_or_spacing_match` (also an old name from history) | pass |
| S-233.3.1 | `test_S_233_3_1_a_renamed_page_still_finds_its_old_rows`: 15 rows (BotcakeCustomerMatcher, BotcakePsidMatcher, its retry of an old waiting row, HandleGatewayEvent name, BotcakeChatGuard check, Inbox list name, Inbox panel, LiveFeed name, ChatbotTags ad, Chatblast candidates, ad groups seen, customers page, page settings grid, ad groups latestAd, PSID coverage) | pass |
| S-233.3.2 | `test_S_233_3_2_two_pages_with_the_same_name_find_only_their_own_rows`: 5 rows (customer matcher incl. the ad id fill and chat_verify not a candidate, sheet records, the sync's inactive update, chat readers, page lists and coverage) | pass |
| S-233.3.3 | `test_S_233_3_3_rows_without_a_page_id_still_match_by_name` (matchers; live feed; a page named "id:1001") | pass |
| S-233.4.1 | the existing matcher, Chatblast, ad group, Inbox and PsidCoverage test files, bodies unchanged (`BotcakeCustomerMatchTest`, `BotcakePsidImportTest`, `BotcakeCustomerSyncTest`, `AdGroup*Test`, `ChatbotInbox*Test`, `PsidCoverageTest`, …) | pass |

**MySQL 8 online DDL** (reasoning, no live measurement: no server access): the migration is four statements. `ADD COLUMN page_id varchar(64) NULL` at the end of the table is `ALGORITHM=INSTANT` on MySQL 8.0.12+ (a metadata change, under a second). Each of the three `ADD INDEX` is `ALGORITHM=INPLACE, LOCK=NONE`: reads and writes go on while the index builds; it scans the ~800,000 rows and sorts them, which on a table this size is typically 5 to 30 s per index, so about 15 s to 1.5 min in all (more on incepxion.pro, which is larger). Each statement takes an exclusive metadata lock for a moment at its start and end. That lock waits for any open transaction on `botcake_psids` (a long sync or a long report query), and while it waits, new reads and writes on the table queue behind it. See Merge danger.

**Browser check:** skipped. No screen or template changed (the customers page, Chatblast and the grid show the same columns with different rows), and this session has no browser tools.

## Rulings

- Spec review (skeptic-reviewer, spec-review mode) before this plan: verdict "go with changes". Folded in: column guard (design 6), worker restart and both installs in the deploy order (design 5), bulk UPDATEs split instead of OR (design 7), nested OR scope, concrete `PageSettingsGrid::info` rule (design 8), capped and cleaned name report, the extra test rows. Kept against its minor: `--max-chunks` (reason in design 4).
- Constraint vs. design: the spec's deploy order is "migrate, deploy code, backfill", but here the migration file arrives with the code pull; the column guard makes either order safe.
- S-233.4.1 has no new test: its Then is that the existing tests pass unchanged, so the evidence is those test files run unchanged (their bodies; the two bases now also migrate the new column and page settings).
- `BotcakePsidMatcher`'s "already recorded?" lookups stay on the sheet's own page text (see Plan): they identify a sheet row, not a page.
- The three reworded stories (S-233.1.1, S-233.1.3, S-233.3.2) were accepted as proposed.
- **Page settings history in the backfill (reviewer's decision):** the backfill also uses inactive `botcake.page_id` rows (old name → id). A name that maps to exactly one id is filled; a name that ever mapped to two or more different ids stays null and is listed. The task 1 review added a bound: an id known only from history fills only rows made on or before that name's last `replaced_at`, because a later row under that name can belong to another page that took the name.
- Reviews and loops: task 1 (adversarial, opus): 2 majors (history without a time bound; the live-writer guard untested), fixed in one loop (`0d5ca6c2`) and closed on re-check. Tasks 2 and 3 (adversarial, opus) and task 4 (standard, sonnet): no blockers or majors. Minors on untrusted paths fixed in one wave (`c130b906`: a retried sheet record uses its own page id; live-feed keys; the report cleaning was tested in the task 1 fix; the ad id fill across pages with one name got a test row); the rest accepted in `TODO.md` with reasons.
- `BotcakeStressPage --seed` is the one writer left without `page_id` (fake PSIDs of a mock page; see TODO.md).

## Story changes

- S-233.1.1 reworded: "a chat on /api/botcake/chat whose PSID Botcake confirms stores a chat_verify row with page_id." Reason: `HandleGatewayEvent` never writes `botcake_psids`; the chat path that does is `BotcakeChatGuard` (source `chat_verify`). The gateway's name lookup is tested as a reader in S-233.3.
- S-233.1.3 reworded: "a sheet row for a page with no botcake.page_id → page_id null, the row is still stored." Reason: the API sync never runs for such a page and the chat path always has an id; the sheet path is the one writer that meets a page without an id. A second row covers "before the column is migrated".
- S-233.3.2: built as a name reused after a rename (page settings allow one active `botcake.page_id` per name). Reason: that is the only way two pages share a name.
- S-233.2.2 widened: "a name with no page in settings (or a name that ever had two ids)". Reason: the reviewer's history decision.
- S-233.3.1 Then widened to "and the other page readers" (gateway name, chat check, live feed, tags, customers page, PSID coverage, page settings grid). Reason: every reader changed needs a red row.
- All the above are in `qa/stories.md`, slice 233.

## Open questions

- None open. The one from the plan (pages renamed before this deploy) was decided: the backfill uses page settings history (see Rulings).

## Proposed tasks

- EXPLAIN the backfill's chunk SELECT and the matcher's candidate query on a copy of the live table before the first run (TODO.md).
- After the backfill on both installs, look at the names it lists; if many rows stay null, a follow-up can decide how to treat them (for example the owner telling which page an old name was).
- `orderBy('id')` on the sync's existing-row lookup, with a test (TODO.md).
- Other tables keyed by page name (`macro_output.PAGE`, `botcake_psid_import_runs.page_name`) have the same rename problem; out of scope here.
