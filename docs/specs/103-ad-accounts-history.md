# Brief 103: the Ad Accounts page shows who added or changed each ad account, with a change log

## Why

`/ads_manager/ad_account` (`AdAccountController`, `resources/views/ads_manager/ad_account/index.blade.php`, model `AdAccount`, table `ad_accounts`: id, ad_account_id, name, timestamps) maps each Facebook ad account ID to the person who owns it. Marketing staff add accounts there (reached through the "Ad Account ID" button on `/ads_manager/payment/upload`). The table records no user, so nobody can tell who added, renamed or deleted an account. The owner, 2026-10-21: "sino naglagay ng mga value niyan dati?" … "yes dapat".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **History rows in the same table, the `page_settings` precedent** (`PageSettings::set`: the old row is kept with `active = false` and `replaced_at`, the new row carries `created_by`). Add nullable columns to `ad_accounts`: `created_by` (user id), `active` (default true), `replaced_at`, `replaced_by` (user id), and `action` (`added` / `changed` / `deleted`). An edit keeps the old row inactive and writes a new active row; a delete only marks the row inactive with `action=deleted` and `replaced_by` (no hard delete). No new table. | The owner prefers no new table when existing rows can hold it. |
| **Readers see only active rows.** Find every reader of `AdAccount` / `ad_accounts` (`git grep`), e.g. payment mapping, reports, CPP; they must keep returning exactly what they return today (one row per active ad_account_id). Use a global scope or explicit `where('active', true)`; your plan says which, and a test proves a reader ignores inactive rows. If a unique index on `ad_account_id` exists, replace it with one that allows history (e.g. unique on ad_account_id + active-only via a generated column, or drop the unique and enforce in code); explain. | No change to existing numbers. |
| **Who.** The logged-in user's id on every add, change and delete. Existing 13 rows keep `created_by` NULL and show "unknown" (no backfill guessing). | Honest history. |
| **On the page:** two new columns in the table, "Added by" (name) and "Last change" (Manila date-time); and a "Change log" section (or a toggle) below it listing every add, change and delete, newest first: when (Manila), who, action, ad account ID, old name → new name. Paginate 50. | The owner's ask. |
| **Access unchanged.** Who can open and edit the page stays as it is now (logged in, allowed IP). Do not add role gates or menu links in this spec. | Separate decision. |
| **Migration** adds nullable columns and indexes only; existing rows get `active = true`. Its release needs the owner's yes, so say so in the result. | Rule. |

## Done when

- Tests: add sets created_by; rename keeps the old row inactive with replaced_by and creates a new active row; ID change works the same way; delete marks inactive with action=deleted (row still in DB); the list and every reader return only active rows (one per ad_account_id); the change log shows old → new name, who and when, newest first; existing rows show "unknown".
- Browser check: the table with "Added by" and "Last change", and the change log, at 1366 and 390 px; no page-level horizontal scroll.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, plus the new tests; the full suite adds no new failures.
- The result lists the readers you checked (file:line), the migration, and the commits.
