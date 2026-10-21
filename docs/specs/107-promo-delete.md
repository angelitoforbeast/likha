# Brief 107: deleting a promo (or an RTS) on /owner/private always works and leaves a clean row

## Why

`OwnerPrivateController::deleteItemSetting` (field rts / promo / cogs) nulls the field on the `page_item_settings` row (page_name, item_name, effective_date, rts_pct NULL-able, promo NOT NULL default 'NONE', mode_cod_int, comment) or deletes the row when nothing is left. Deleting **promo** on a row that still has an RTS sets `promo = NULL` and fails on the NOT NULL column (found in spec 104; its S-104.1 promo case had to use a row without RTS). The owner, 2026-10-21: "ang panget kasi ng existing, ayusin mo na".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| "No promo" is the value `'NONE'` everywhere (the column default). Deleting promo writes `'NONE'`, never NULL. Saving an empty promo also writes `'NONE'`. Reading treats NULL (if any legacy row has it) the same as `'NONE'`. | One meaning for "no promo". |
| A row with no RTS (NULL) and promo `'NONE'` and no comment is deleted, as today's code intends; otherwise it stays with the remaining values. Same rule after deleting RTS. | Clean rows, nothing lost. |
| The spec-104 edit-log rows still get written (old value → NULL shown as "deleted" for RTS; old promo → `'NONE'` shown as "deleted" for promo). Check the edit-logs page still shows promo deletes struck through. | Keep the audit. |
| UI: the delete control on /owner/private shows a clear message on success and a readable error on any failure (no raw SQL error to the user). | It looked broken. |
| No schema change. | Lean. |

## Done when

- Tests: delete promo on a row with RTS → row kept, promo 'NONE', log row written; delete promo on a row without RTS → row removed (or kept per the rule), log written; delete RTS on a row with promo → row kept, rts NULL; delete both → row removed; empty-promo save → 'NONE'; legacy NULL promo reads as none; the S-104 tests still pass (update the S-104.1 promo case to the row-with-RTS case it was meant to be).
- `vendor/bin/phpunit tests/Feature/OwnerPrivate tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes; the full suite adds no new failures.
- The result lists every place that writes or reads `promo` (file:line) and the commits.
