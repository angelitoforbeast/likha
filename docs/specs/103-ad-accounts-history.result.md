# Result 103: the Ad Accounts page shows who added or changed each ad account, with a change log

Branch `feat/103-ad-accounts-history`, cut from `main` (0b663bb0). Not pushed.

## What changed

- **History rows in `ad_accounts`** (migration `2026_10_31_100000_add_history_to_ad_accounts_table.php`).
  - New columns: `created_by` (user id, nullable), `active` (boolean, default true), `replaced_at` (nullable), `replaced_by` (user id, nullable) and `action` (`added` / `changed` / `deleted`, nullable).
  - New index: (`ad_account_id`, `active`).
  - Existing rows get `active = true` from the column default. Their `created_by` and `action` stay NULL, and the page shows "unknown". No backfill.
  - `active` is NOT NULL with default true, not nullable. A NULL would be neither active nor inactive to the readers.
- **The unique index on `ad_account_id` is dropped.** The plain index from the first migration stays.
  - Why: history keeps several rows with the same ID, so the unique had to go.
  - I chose to drop it and enforce in code, not to add a generated-column unique. A generated column needs different SQL for MySQL, pgsql and the sqlite tests, for a page that a few staff edit by hand.
  - How it is enforced: every write runs in a transaction and takes the active row with `lockForUpdate`.
    - The same ID and name again: nothing is written.
    - The add form with an ID that is already active: the old row becomes inactive and a new row is written (`changed`). This is what `updateOrCreate` did before, but now the old row stays.
    - The edit form changing an ID to another active ID: it is now refused with a form error ("May ad account na may ganyang Ad Account ID."). Before, the unique made this a 500 error.
  - The limit: two first-time adds of the same new ID at the same instant could both pass, because there is no row to lock yet. Accepted below.
  - The `down()` drops the columns but does not bring the unique back, because the unique would fail once history exists.
- **Writes** (`AdAccountController`).
  - Add: a new row with `created_by` = the logged-in user, `action = added`.
  - Rename, ID change (edit form), or a new name for an existing ID (add form): the old row gets `active = false`, `replaced_at` and `replaced_by`. A new active row gets the new values, `created_by` and `action = changed`.
  - Delete: the row becomes inactive, with `action = deleted`, `replaced_at` and `replaced_by`. Nothing is deleted from the table.
- **Readers see active rows only.** I used explicit `where('active', true)`, not a global scope.
  - Why: four of the five readers use `DB::table`, which a model scope never reaches. The page itself also needs the inactive rows for the log.
  - The readers outside this page check `Schema::hasColumn('ad_accounts', 'active')`. So code deployed before the migration still runs and returns what it returns today.
- **The page.**
  - The table has two new columns. "Added by" is the name of the user who wrote the current value (the active row's `created_by`), or "unknown". "Last change" is the active row's `updated_at` in Manila time, e.g. "Oct 21, 2026 9:34 AM".
  - A "Change log" section below the table, newest first, 50 per page (`?log_page=`), shows when (Manila), who, action, the ad account ID (old → new when it changed) and the name (old → new when it changed; struck through when deleted).
  - Old rows appear in the log as "added" by "unknown" at their `created_at`.
  - How the log is built: each row is one added/changed entry (`created_at`, `created_by`). Each deleted row is one more entry (`replaced_at`, `replaced_by`). For a changed entry, the old row is the inactive row that was replaced at the same instant by the same user; `write()` stamps both rows with one `now()`.
- **Access is unchanged.** I added no role gate and no menu link.

## Readers checked (`git grep -n "ad_accounts\|AdAccount"`)

| Reader | What it does | Change |
|---|---|---|
| `app/Http/Controllers/AdAccountController.php:19,23` | The page list and the edit form | `where('active', true)` |
| `app/Http/Controllers/AdsManagerCampaignsController.php:529` | Campaign history: account name per campaign (`pluck('name','ad_account_id')`) | active only (hasColumn guard) |
| `app/Http/Controllers/AdsManagerCampaignsController.php:950` | Campaigns per page: account name | active only (hasColumn guard) |
| `app/Http/Controllers/AdsManagerCampaignsController.php:1740` | Campaigns data (ads/adsets level): account name | active only (hasColumn guard) |
| `app/Http/Controllers/PaymentActivityController.php:102-109` | Payment records: rows and total (`leftJoin ad_accounts`) | active filter in the join condition |
| `app/Http/Controllers/PaymentActivityController.php:151` | Payment records: the ad account dropdown | same join |
| `tests/Feature/OwnerPrivate/AdsLevelCampaignsDataTest.php:49` | A test's hand-made `ad_accounts` table without `active` | none; the hasColumn guard keeps it green |

No other reader: no job, command, report, CPP or view reads `ad_accounts`. The three campaign lookups used `pluck`, so an inactive row could have overwritten the active name. The two payment joins would have duplicated every payment row (and doubled the total) for an account with history. S-103.9 proves the payment reader.

## Migration (needs the owner's yes before release)

`database/migrations/2026_10_31_100000_add_history_to_ad_accounts_table.php`: drops the unique on `ad_account_id`, adds 5 columns and 1 index. It deletes no data. Existing rows become `active = true` from the default. Run it by hand with the deploy (`php artisan migrate --force`) after the owner's yes.

If the code goes out before the migration:
- The other pages keep working (hasColumn guard).
- The Ad Accounts page itself errors until the migration runs.

So run the migration with the deploy.

## Case table

`vendor/bin/phpunit tests/Feature/AdAccount`: **OK (13 tests, 81 assertions)**, on the final code.

| Case | Test | Result |
|---|---|---|
| S-103.1 | `test_S_103_1_add_sets_created_by_and_action_added` | pass |
| S-103.2 | `test_S_103_2_change_keeps_the_old_row_inactive_and_writes_a_new_active_row` (rename, id change, add existing id) | pass ×3 |
| S-103.3 | `test_S_103_3_saving_the_same_values_writes_no_history_row` | pass |
| S-103.4 | `test_S_103_4_id_change_onto_an_active_id_is_refused_and_changes_nothing` | pass |
| S-103.5 | `test_S_103_5_delete_marks_the_row_inactive_and_keeps_it` | pass |
| S-103.6 | `test_S_103_6_the_list_shows_active_rows_only_with_added_by_and_unknown_for_old_rows` | pass |
| S-103.7 | `test_S_103_7_change_log_lists_adds_changes_and_deletes_newest_first`, `test_S_103_7_two_renames_by_one_user_in_the_same_second_keep_their_own_old_names` | pass, pass |
| S-103.8 | `test_S_103_8_change_log_pages_by_50` | pass |
| S-103.9 | `test_S_103_9_payment_records_join_only_the_active_ad_account_row`, `CampaignAccountNameTest::test_S_103_9_campaign_data_names_the_account_from_the_active_row_only` | pass, pass |
| S-103.10 | browser (below) | pass |

Red runs, before the code:
- S-103.1: `Failed asserting that 0 is identical to 1` (no created_by).
- S-103.2: `actual size 1 matches expected size 2` (updated in place).
- S-103.5: `actual size 0 matches expected size 1` (hard delete).
- S-103.6–8: 500, then `Undefined array key "log"`.
- S-103.9: `3003 => 'Binura'` (the inactive row joined).
- S-103.9, campaign data: written after the fix, so I proved it by disabling the filter at `AdsManagerCampaignsController.php:1742`. It then failed with `'Pangalang Luma'` instead of `'Pangalang Bago'`; the line is restored.
- S-103.7, same-second renames: `Expected '1001', 'Uno Luma' … Actual '2002', 'Dos Luma'` (paired with the wrong old row).

## Test runs

- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: **836 tests, no failures** (1 skipped, as on main), rerun on the final code.
- `vendor/bin/phpunit tests/Feature/OwnerPrivate tests/Feature/PaymentActivity tests/Feature/CardReconcile` (the other readers' tests): **OK (215 tests)** before the fix wave; `tests/Feature/AdAccount tests/Feature/OwnerPrivate tests/Feature/PaymentActivity` after it: **OK (217 tests)**.
- Full suite on the final branch (after the fix wave): 2204 tests, **1 error and 1 failure, both already on `main`** (baseline run on 0b663bb0: 2191 tests, the same two):
  - `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response`
  - `Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`

  No new failures.
- `php -l` is clean on every changed PHP file.

## Browser check

There was no built-in browser here, so I used headless Chrome over DevTools (Node 24, no packages), with the scripts outside the repo:
- A throwaway PHPUnit test, outside the repo, seeded data through the real routes, then saved the rendered page:
  - 2 old rows;
  - adds by two users;
  - an ID and name change;
  - an add followed by a delete.
- I opened the saved page at 1366 and 390 px and measured the page width and every element wider than the viewport outside a scroll box. No env file was created.

**Result: pass. No page-level horizontal scroll at either width and no console errors.**

| Check | 1366 | 390 | Evidence |
|---|---|---|---|
| The table shows Ad Account ID, Name, Added by, Last change, Actions; old rows show "unknown" | pass | pass (the table scrolls inside its box) | `103-ad-accounts-history/1366-ad-accounts.png`, `390-ad-accounts.png` |
| The change log shows deleted, added, changed (old → new ID and name) and the old rows, newest first | pass | pass (scrolls inside its box) | same |
| Page width = viewport width (1366/1366, 390/390); 0 overflowing elements | pass | pass | measured |

The check found two things, both fixed before the final screenshots:
- `whitespace-nowrap` on Actions clipped Delete inside the half-width table at 1366. I reverted it; the buttons wrap as before.
- The log's Name column was squeezed into very tall rows at 390. It now has a minimum width (`min-w-[14rem]`) and scrolls inside its box.

The broken logo in the screenshots comes from opening the page from a file. It is not part of this change.

## Story changes

- I added S-103.3 (saving the same values writes nothing) and S-103.4 (an ID change onto another active ID is refused). The brief does not name them, but without them the history would fill with no-op rows, or two active rows would share one ID.
- S-103.2 includes the add form with an existing ID. Before, it silently renamed the account (`updateOrCreate`), so it is a change too.

## Review

One `skeptic-reviewer` pass, standard depth (medium tier), on `0b663bb0..e31264ed`. **No blocker and no major.** It confirmed:
- all five readers filter on active rows (it ran its own `git grep`);
- the routes are still behind `web, auth, allowed_ip` (`route:list -v`);
- the migration is correct on MySQL and pgsql;
- the union and paginate query works on both, and the timestamp match is exact.

I fixed these minors in one final wave (`d32e9475`), each test-first:
- The change log could pair a rename with another account's old row when one user changed two accounts in the same second. It now prefers the old row with the same ID. Covered by S-103.7's second test.
- The "Done when" asks for every reader, but only the payment reader had a test. A campaign-data test now covers the name lookup (S-103.9's second test).
- The flash messages had changed without being asked for. They are back to the original: "Ad account updated." from the edit form, "Ad account saved." otherwise.
- The single-column `active` index wasn't needed and is removed from the migration, which is unreleased.

There was no second loop.

## Accepted findings

All are minors, in `TODO.md` under "103: ad account history", each with its reason:
- One active row per ID is enforced in code only. A double submit of a brand-new ID under READ COMMITTED or on pgsql could leave two active rows. Production's isolation level is worth a check.
- A stale edit form can update another account (the old code did the same).
- An ID change that differs only in case is refused on MySQL.
- pgsql sorts NULL timestamps first in the log.
- The time-and-user pairing can still be wrong for an ID change plus another change by one user in the same second.
- No tests for re-adding a deleted ID, an ID change onto a deleted ID, or deleting twice.
- `write()`'s return value is unused.

## Commits

| Commit | What |
|---|---|
| `e199f5a9` | docs: brief for ad account history (spec 103) |
| `5600283f` | feat: ad account history with who and a change log (spec 103) |
| `e31264ed` | fix: change log name column keeps a readable width on phones (spec 103) |
| `d32e9475` | fix: change log pairs a rename with its own old row, campaign name test, original flash messages (spec 103) |
| (this commit) | docs: result, screenshots, TODO |

## Merge danger

- **The migration drops a unique index.** Release it only with the owner's yes, and run it with the deploy. The Ad Accounts page needs the new columns. The other readers are guarded.
- **Behaviour change:** changing an ad account's ID onto another existing ID is now a form error instead of a 500 error. The add form with an existing ID still updates the name, as before, but now keeps the old row.
- **Interpretation:** "Added by" names who wrote the current value (after a rename, the renamer). The first adder is in the change log.
