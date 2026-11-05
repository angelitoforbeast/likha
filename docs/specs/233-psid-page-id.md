# Spec 233: page id on botcake_psids

**Project:** Likha (the same code runs incepxion.pro). **Shape:** change · **Weight:** bounded (one table, its writers and its readers) · **Risk tier:** high (a backfill on a large live table; it feeds order matching, Chatblast and ad groups)

## Why
`botcake_psids` (about 790,000 rows on Likha and more on incepxion.pro, growing by about 21,000 a day) keys a customer's page by its **name** (`page`, varchar). A Facebook page id never changes, but a page's name can be changed in Likha's page settings. When it is, every older row stays under the old name, so order matching, Chatblast, ad groups and the Inbox quietly stop finding those customers.

Likha already knows the id of each page:
- `BotcakeChat::pageMap()` maps id → name (page settings `botcake.page_id`);
- `botcake_chats.page_id` exists.

## Decisions already made (settled; don't reopen them unless something is actually broken)
| # | Decision |
|---|---|
| 1 | Add a nullable `page_id` column (string, the Facebook page id) to `botcake_psids`, with an index fit for the readers' lookups (for example (page_id, psid), and (page_id, phone_key) or (page_id, name_key) where readers search those). |
| 2 | Backfill it from the page name through the page settings map. This is a separate artisan command (e.g. `botcake:psid-page-id-backfill`), not inside the migration. It runs in chunks by id (for example 5,000), is resumable and idempotent (only rows where page_id is null), sleeps briefly between chunks so the live server is not starved, and prints counts (filled, no matching page, left). Names are matched with PageSettings::pageKey normalization. A name with no page id stays null and is reported by name with its row count. |
| 3 | Every writer of `botcake_psids` (HandleGatewayEvent, BotcakeChatGuard, BotcakeCustomerSync / BotcakeSyncAll, BotcakePsidMatcher, BackfillBotcakePsidWaiting, and any other found by grep) sets `page_id` on insert and on update when it knows it. The gateway and chat paths know it directly; the Botcake API path takes it from the page's settings. |
| 4 | Every reader that looks up by page uses `page_id` first, and the name only when the row's page_id is null or the page has no id. Results must stay the same for pages that were never renamed. |
| 5 | A page renamed in Likha keeps matching its old rows through page_id; nothing renames the old `page` values. |
| 6 | No row is deleted or merged. `page` stays as is. |

## Stories
Add these to qa/stories.md under `## Slice 233 – Page id on botcake_psids`, with one failing test per case first, named with its case ID.

S-233.1 New rows carry the page id (P1)
- .1 happy: a gateway chat event stores a row with page_id.
- .2 happy: the Botcake API customer sync stores page_id from the page's settings.
- .3 edge: a page with no id in settings → page_id null, the row is still stored.

S-233.2 The backfill fills old rows safely (P1)
- .1 happy: rows for 3 named pages with ids → all filled.
- .2 negative: a name with no page in settings → left null and reported.
- .3 edge: a second run changes nothing.
- .4 edge: a run stopped part-way finishes on the next run.
- .5 edge: names that differ only in case or spacing match through pageKey.

S-233.3 A rename doesn't break matching (P1)
- .1 happy: page "A" with id X has rows; it is renamed "B" in page settings → order matching (BotcakePsidMatcher, BotcakeCustomerMatcher), Chatblast candidates, ad groups and the Inbox still find the old rows by id.
- .2 negative: two different pages with the same name and different ids → each finds only its own rows.
- .3 edge: rows with page_id null still match by name, as today.

S-233.4 Nothing changes for pages never renamed (P1)
- .1 happy: the existing matcher, Chatblast, ad group, Inbox and PsidCoverage tests pass unchanged.

## Constraints
- High tier: the backfill touches live data. Log counts only, never names of customers or PSIDs.
- The migration only adds the column and indexes. Note in the result how long adding an index to a table of 800,000 rows takes on MySQL 8 (online DDL), and if it locks writes, say so in Merge danger.
- Run the full suite last. Known pre-existing failures: ExampleTest and ItemTableFitScriptTest; BigBroadcastTest and AstraAddressRulesTest S-26.18 are time-limited and flaky.

## Budget
At most 2 tries at the same step. Size: medium.

## Done when
- [ ] Cases S-233.1 to S-233.4 pass, each with a test named after it.
- [ ] The full suite adds no new failures.
- [ ] The result has the backfill command, its run-time estimate per 100,000 rows from a seeded test, and Merge danger with the deploy order (migrate, deploy code, then run the backfill).
- [ ] qa/stories.md has slice 233.

## Out of scope
Deleting or merging rows, changing `macro_output.PAGE`, other tables keyed by page name, and deploying.
