# Result 233: page id on botcake_psids

## Status

Plan written and spec-reviewed, waiting for "go". No code yet. Branch `feat/233-psid-page-id` from `f175cb2f`.

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

(pending)

## Checks

(pending)

## Rulings

- Spec review (skeptic-reviewer, spec-review mode) before this plan: verdict "go with changes". Folded in: column guard (design 6), worker restart and both installs in the deploy order (design 5), bulk UPDATEs split instead of OR (design 7), nested OR scope, concrete `PageSettingsGrid::info` rule (design 8), capped and cleaned name report, the extra test rows. Kept against its minor: `--max-chunks` (reason in design 4).
- Constraint vs. design: the spec's deploy order is "migrate, deploy code, backfill", but here the migration file arrives with the code pull; the column guard makes either order safe.

- S-233.4.1 has no new test: its Then is that the existing tests pass unchanged, so the evidence is those test files run unchanged before the PR.
- `BotcakePsidMatcher`'s "already recorded?" lookups stay on the sheet's own page text (see Plan): they identify a sheet row, not a page.

## Story changes

- S-233.1.1 "a gateway chat event stores a row with page_id": `HandleGatewayEvent` never writes `botcake_psids`; the chat path that does is `/api/botcake/chat` (`BotcakeChatGuard`, source `chat_verify`). Proposed wording: ".1 happy: a chat on /api/botcake/chat whose PSID Botcake confirms stores a chat_verify row with page_id." The gateway's name lookup is covered as a reader in S-233.3.
- S-233.1.3 "a page with no id in settings": the API sync never runs for such a page (it needs `botcake.page_id`), and the chat path always has an id. The case is tested on the sheet path (`BotcakePsidMatcher`), the one writer that can meet a page without an id. Proposed wording: ".3 edge: a sheet row for a page with no botcake.page_id → page_id null, the row is still stored."
- S-233.3.2 "two different pages with the same name": page settings allow only one active `botcake.page_id` per page name, so this happens when a name is reused after a rename (page X renamed away from "Shop", page Y named "Shop"). The test builds it that way: rows of X and Y both carry page "Shop" with their own ids.

## Open questions

- Pages renamed **before** this deploy: their old rows keep the old name, which the active map no longer knows, so the backfill leaves them null and lists them. The inactive history in `page_settings` (old `botcake.page_id` rows keep their old page name) could fill them. Not in the plan, since decision 2 says "the page settings map"; the backfill's report shows whether it is worth a follow-up.

## Proposed tasks

(pending)
