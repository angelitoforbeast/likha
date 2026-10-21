# Brief 104: every change to RTS, promo and COGS lands in the one edit log, deletes and /item/cogs included

## Why

`/owner/private` saves RTS %, promo and COGS per page and item through `OwnerPrivateController::saveItemSetting`, and each save writes a row to `page_item_settings_log` (user_email, action create/update, page_name, item_name, effective_date, old/new rts_pct, item_value, promo, item_value_ceo, comment …). The owner reads it at `/owner/private/edit-logs` (`editLogs`, CEO and Marketing - OIC). Three write paths skip the log, so a value can change or vanish with no trace:

1. `OwnerPrivateController::deleteItemSetting` (deletes rts / promo / cogs: `cogs` and `cogs_ceo` rows, or nulls/deletes `page_item_settings` rows) writes no log row.
2. `/item/cogs` (`ItemCogsController::update` and `::delete`) edits and deletes `cogs` cells with no log and no user.
3. `cogs:sync-from-macro` (`CogsSyncFromMacro`) writes `cogs` rows with no log.

The owner, 2026-10-21, after asking who set a COGS value: "ayusin mo na".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Use the existing `page_item_settings_log`.** No new table. Add a new `action` value `delete` for deletes, and for the other paths a `source` column (nullable string: `owner_private`, `item_cogs`, `cogs_sync`) if no equivalent exists; existing rows read as `owner_private`. If a column is needed, the migration only adds nullable columns. | One log, the owner's no-new-table rule. |
| **Deletes** (path 1): one log row per deleted field with old value → NULL, the user, page/item/effective_date, action `delete`. | The gap the owner hit. |
| **/item/cogs** (path 2): each edit or delete writes a log row with old → new `item_value` (and `item_value_ceo` if that page edits `cogs_ceo`), the user, item, date; `page_name` NULL or the value that page uses, explained in the result. | Same. |
| **cogs sync** (path 3): log only rows whose value actually changed (old != new), user NULL shown as "system (cogs sync)". No log row when nothing changed. | No noise. |
| **edit-logs page:** shows the new rows with the same filters (user, scope, date, item); the action column shows "delete" and the source ("/owner/private", "/item/cogs", "cogs sync"); a filter for source is a plus if cheap. Deleted values shown struck through. Access unchanged (CEO, Marketing - OIC). | Read in one place. |
| **No change** to how values are computed or saved; only logging is added, in the same transaction as the write where one exists. | Safety. |

## Done when

- Tests: delete of rts / promo / cogs from /owner/private each write a `delete` row with old → NULL and the user; /item/cogs update and delete write rows with old → new and the user; cogs sync writes a row only when the value changed, attributed to the system; edit-logs lists all of them with the right labels and filters; existing log rows still show as before.
- Browser check of edit-logs at 1366 and 390 px; no page-level horizontal scroll.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake tests/Feature/OwnerPrivate` passes, plus the new tests; the full suite adds no new failures.
- The result lists every write path to `cogs`, `cogs_ceo` and `page_item_settings` you found (`git grep`, file:line) and whether it now logs; any migration (its release needs the owner's yes); and the commits.
