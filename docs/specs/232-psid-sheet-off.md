# Spec 232: stop the hourly Botcake PSID sheet import

**Project:** Likha (the same code runs incepxion.pro). **Shape:** change · **Weight:** bounded · **Risk tier:** low

## Why
routes/console.php schedules `botcake:psid-import` every hour at :00 (except 03:00) and `botcake:psid-import --clean-sheet` at 03:00. Each run reads the whole Google Sheet "PHONE NUMBER BOTCAKE" (PSID!A:K) for 5 to 6 minutes, and the server is busiest in exactly those minutes.

It no longer brings anything in:
- On Likha, no `botcake_psids` row has ever come from the sheet (`setting_id` is null on every row).
- On incepxion.pro, the last sheet row is from 2026-10-31.
- Every order on 2026-11-05 that has a PSID (1,534 on Likha, 4,213 on incepxion.pro) has that PSID in `botcake_chats` too.

So the two schedule entries are removed.

## Decisions already made
| # | Decision |
|---|---|
| 1 | Remove both `Schedule::command('botcake:psid-import' …)` entries and their comment from routes/console.php. |
| 2 | Keep the `botcake:psid-import` command, the job, the manual button (BotcakePsidGsheetController), the `botcake_psids` table and the API customer sync (`botcake:sync-customers`, BotcakeCustomerSync, BotcakeSyncAll) unchanged. |
| 3 | Leave a one-line comment where the entries were, saying that the sheet import is manual only now and why (PSIDs come from chats and the Botcake API). |

## Stories
Add to qa/stories.md under `## Slice 232 – Hourly PSID sheet import off`:
- S-232.1.1 happy: `php artisan schedule:list` has no `botcake:psid-import` line; `botcake:sync-customers` and `botcake:prune-chats` are still listed.
- S-232.1.2 happy: the manual import button still queues the job (the existing test, or a new one).
- S-232.1.3 edge: running `php artisan botcake:psid-import` by hand still works.

## Done when
- [ ] S-232.1.1 to .3 pass, each with a test named after it. Any existing test that expects the schedule entries is updated, and the result says which.
- [ ] The full suite adds no new failures compared with 088351ff (known pre-existing: ExampleTest, ItemTableFitScriptTest, and the time-limited BigBroadcastTest and AstraAddressRulesTest S-26.18).
- [ ] The result is filled in.

## Out of scope
Removing the command, the button, the table or the sheet. Deploying.
