# Result 173: Meta Ads sync — choose the ad accounts, page name, delivery and result type

Branch `feat/173-meta-ads-accounts-and-fields`, based on debbaedb. Not pushed, not deployed.

## What shipped
1. **Page name without new Meta permissions** (`Insights`, `Sync`)
   - The ad's page ID is read in this order: `creative.object_story_spec.page_id`, then `creative.actor_id`, then the part before "_" of `creative.effective_object_story_id`. The last two fields are now requested in `AD_FIELDS`.
   - The name comes from the active `botcake.page_id` rows in page_settings, through the existing `BotcakeChat::pageMap()`. That is the same map the Botcake chats use: trimmed, secret-aware and cached.
   - Other providers: none. I searched `app/`, and `botcake.page_id` is the only page_settings key that holds a Facebook page ID (Forbeast reads the same key).
   - The Page `?ids=…&fields=name` call is removed.
   - When no Likha page matches, the row takes the page_name of an earlier upload row for the same ad, as before. When there is none, the row is skipped. Skipped rows are listed on the run log's `error_message` as `No page name, skipped: ad <id> (page <id>), …`. The run status does not change because of them.
2. **Delivery in the export's wording** (`Insights::AD_SET_DELIVERY`, `NOT_DELIVERING`)
   - The evidence is in a comment next to the mapping.
   - ad_set_delivery: ACTIVE → `active`, PAUSED → `inactive`, any other status → `not_delivering`.
   - campaign_delivery: `active` only when both the campaign and the ad set are ACTIVE, otherwise `not_delivering`.
   - **Not produced:** the export's rare campaign `inactive` (90 rows in 30 days).
3. **Result type in the export's wording** (`Insights::RESULT_TYPES`, evidence in a comment)
   - MESSAGING_PURCHASE_CONVERSION, OFFSITE_CONVERSIONS and ONSITE_CONVERSIONS → "Meta purchases". I read ONSITE_CONVERSIONS as one of the "other purchase goals".
   - VALUE → "Meta purchase conversion value".
   - CONVERSATIONS → "Messaging conversations started".
   - All other goals keep their earlier wording.
4. **Choosing the accounts** (`Sync::ACCOUNTS` = app_settings `meta_ads.accounts`, a JSON list of IDs; no new table)
   - On /ads_manager/report, the Meta section has an "Ad accounts to sync" list. Each account appears once with:
     - its name and ID, and the Facebook names whose connections read it;
     - its upload and API rows and spend from the latest finished Compare;
     - "not in uploads" when the upload spend is 0;
     - a checkbox.
   - Saving is CEO only: `POST /ads_manager/meta/accounts` answers 403 to anyone else, who sees the checkboxes disabled.
   - Only IDs that appear in a connection are accepted.
   - Each change is logged as an `ads_manager_api_event`, with the before and after lists in `protected_details`. Saving with nothing ticked clears the selection.
   - **Sync** (the button, `meta-ads:sync` and the schedule) reads only the selected accounts. With nothing selected:
     - the button and the command say "Choose the ad accounts first";
     - the scheduled run is quiet, writes no log and makes no Graph calls;
     - a direct `Sync::run` ends `failed` with that message.
   - **Compare** reads the selected accounts, or all accounts while nothing is selected.
   - A new account is unticked until someone picks it.
   - `php artisan meta-ads:accounts --list` prints `ID  name  yes|no  (Facebook names)` and a count. `--set=ID,ID` uses the same validation and log. No token is printed.
   - To support the log, `Connections::event()` gained an optional `$details` argument.

## Case table
| Case | Test | Result |
|---|---|---|
| S-173.1 | `MetaAdsAccountsTest::test_S_173_1_the_meta_section_lists_each_account_once…`, `…a_ceo_saves_two_of_five…`, `…saving_with_nothing_ticked_clears…`, `…an_id_that_is_not_in_any_connection_is_refused`, `…a_non_ceo_sees_the_list_read_only…` (2 roles) | pass |
| S-173.2 | `test_S_173_2_sync_with_no_selection…`, `…sync_reads_only_the_selected_accounts`, `…compare_reads_the_selected_accounts_or_all…` (2 sets) | pass |
| S-173.3 | `test_S_173_3_the_accounts_command_refuses_an_unknown_id…` | pass |
| S-173.4 | `MetaAdsFieldsTest::test_S_173_4_page_name_comes_from_page_settings…`, `…an_unknown_page_takes_the_earlier_upload_name…`, `…the_page_id_comes_from_the_story_spec_then_actor_id…` (4 sets) | pass |
| S-173.5 | `test_S_173_5_delivery_uses_the_export_wording` (the 4 evidence pairs, plus another status) | pass |
| S-173.6 | `test_S_173_6_result_type_uses_the_export_wording` (5 sets) | pass |
| S-173.7 | `tests/Feature/MetaAds` (the 154 tests and `AdsManagerUploadCharacterizationTest`) | pass, `OK (68 tests, 351 assertions)` |
| S-173.8 | Checked by reading the markup only. The list stacks per account, with `min-w-0`, `break-all`, `shrink-0` and 20 px checkboxes. | not run in a browser |

Red runs:
- Stage A: 9 failures before the code, for example `Failed asserting that '' contains "ad a4 (page 666)"` and the delivery and result-type pairs.
- Stage B: `Call to undefined method Sync::setAccounts()`, and `The command "meta-ads:accounts" does not exist`.
- The review fixes (the quiet scheduled run and the page-ID order cases) were added together with their code, not red first.

## Suites (done-when)
`phpunit tests/Feature/MetaAds tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings`:
- At debbaedb (baseline): `Tests: 1541, Assertions: 46859, Skipped: 1`, no failures.
- After the change: `Tests: 1568, Assertions: 46944, Skipped: 1`, no failures.
- No new failures.

## Story changes
- I added S-173 to `qa/stories.md`. Three things go beyond the brief's cases:
  - S-173.1 also covers saving with nothing ticked (it clears the selection).
  - S-173.2 also covers the scheduled run staying quiet.
  - S-173.4 also covers the order of the page ID sources.
- Reason: the reviewer found these gaps.

## Changes to existing tests (needed by the decisions)
- `MetaAdsTestCase`:
  - adds the page_settings migrations and a `pageSetting()` helper, plus a default row `Page P1` = `p1` (the page ID used by `adDetail()`);
  - the fake Graph no longer answers `?ids=` Page-name calls.
- `MetaAdsSyncTest`:
  - each write-mode test now saves a selection (Sync does nothing without one);
  - S-154.4 now expects `campaign_delivery` `not_delivering` for a PAUSED campaign, per Decision 3.

## Review
- I ran `skeptic-reviewer` at standard depth. It found 1 major and some minors.
- **Major, fixed:** the page-name lookup re-implemented `BotcakeChat::pageMap()`, and the copy differed from it: the first row won, values were not trimmed and secret rows were ignored. Insights now uses `pageMap()`.
- **Minors, fixed:** the scheduled sync was noisy without a selection; tests were missing for the page-ID source order, a story ID without "_", and saving an empty selection.
- **Minors, accepted in `TODO.md` with reasons:**
  - Compare reads nothing when every saved ID has left the connections;
  - no test sends `accounts` as a string or an item over 50 characters;
  - 390 px was not checked in a browser.

## Conflicts
None between the brief and CLAUDE.local.md.

## Deploy notes
- No migration. Run `php artisan view:clear` (the Blade view changed) and `php artisan route:clear` (or `route:cache`) for the new POST route.
- **Until accounts are chosen, Sync does nothing.** Write mode is off today, so nothing changes in practice. Compare still reads all accounts.
- To set the 5 accounts that carry the upload spend after deploy:
  1. Run `php artisan meta-ads:accounts --list`. The latest Compare on /ads_manager/report shows the "not in uploads" accounts.
  2. Run `php artisan meta-ads:accounts --set=ID1,ID2,ID3,ID4,ID5`. It prints `Selected 5 of N ad accounts`; an unknown ID is refused and nothing changes.
  3. Or the CEO ticks the 5 accounts on /ads_manager/report and presses "Save accounts".
- Before turning write mode on, run a Compare and then one Sync on a past day, and check that run log's "No page name, skipped" list. Any page listed there needs its `botcake.page_id` on /page-settings.
- **Incepxion:** the same commits apply unchanged. Same codebase, no migration, no site-specific config.

173: done — Sync and Compare read the accounts the CEO picks, and API rows get the upload's page name (from Likha page settings), delivery and result type; the suites have no new failures.
