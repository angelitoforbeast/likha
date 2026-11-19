# 307 result: "Facebook User" is not a customer

Base: origin/main f964a6eb. Branch `feat/307-facebook-user-not-a-key`.

## Plan

Base f964a6eb: full suite once before any code (Herd PHP, `-d memory_limit=4G`), to have the base count.

1. **One constant.** New `app/Services/PlaceholderName.php`: `NAMES` (the placeholder names) and `is(?string)`, true for an empty name and for any listed name in any case, with any spacing (spaces, tabs, no-break and zero-width characters are ignored, so "facebook  user" and "FacebookUser" count). No other placeholder exists in the code or tests; the data could not be checked from here (no server access), so the list is the names Meta shows for a hidden profile: Facebook User, Messenger User, Instagram User.
2. **FU-1 / FU-2, `PancakeChat::forOrder`.** PSID step unchanged. After it: a PSID was given → return null (no name fallback). No PSID and a placeholder name → return null. No PSID and a real name → page + name, then name only, as today. Astra's EARLIER CONVERSATION, its `get_chat_history` tool and MacroChecker's pass 2 all read through `forOrder` already (spec 131 amendment 1), so they follow without their own change; tests pin that.
3. **FU-3, the other customer keys.**
   - `OrderIdentity::find`: a placeholder name skips the name rule and falls to phone, then identical input, as an empty name. Its callers compute the key phone only when the name is empty today, so `ImportLikhaFromGoogleSheet::importRow`, `ImportMacroFromGoogleSheet` and `LiveOrder::values` (the encode mirror of the Likha import) use `PlaceholderName::is` for that test and for the "No name / no phone" flag.
   - `LiveOrder::find`: no "empty PSID + same fb_name" OR branch for a placeholder; no PSID and a placeholder name → no customer found (new row), as for an empty name.
   - `BotcakePsidMatcher`: `orderQuery` (exact and case-insensitive steps) finds no orders for a placeholder; a sheet record with a placeholder name becomes `conflict` instead of being attached; `copyReorders` skips placeholder customers.
   - `BotcakeCustomerMatcher::matchPage` (the Botcake API customer match, also by name): placeholder names are no name on both sides, so such an order goes to the phone rule like a nameless one.
   - Read, left as is, listed in the result: the "See more" `has_pancake` flag, `/pancake/retrieve2`, `/pancake/retrieve-orders`.
4. **FU-4.** End-to-end test with a fake model: the 18/11 order, Mercedita's "Facebook User" conversation on the page, no conversation for the order's PSID. Assert no request to the model carries "Mercedita" (prompt and the `get_chat_history` tool output) and the row keeps its own phone.
5. **FU-5.** `orders:placeholder-mixups --from= --to= [--page=]`, `app/Console/Commands/PlaceholderMixups.php`, SELECT only. Pass 1 per day: id, page, day, fb_name, FULL NAME, PHONE NUMBER of every row in range. A row with a placeholder fb_name is a suspect for a field when another row on the same page and day has the same FULL NAME (normalized, not a placeholder) or the same phone (normalized PH mobile). Pass 2 loads the text of the suspects and their candidates only: reported when the value is not in the suspect's own all_user_input or its CXD without ASTRA blocks (Astra's block holds the copied value). "Came from" = the candidate whose own text has the value, else the lowest id. Prints id, page, day, field, came-from id, and counts; never names, phones or addresses.
6. Tests per H/N case, the 131 lookup test that pinned the old PSID → page + name fallback updated to the new rule, targeted runs, full suite against the base count.

## What shipped

**The placeholder constant:** `App\Services\PlaceholderName::NAMES = ['Facebook User', 'Messenger User', 'Instagram User']` (`app/Services/PlaceholderName.php`). `PlaceholderName::is($name)` is true for an empty name and for any listed name in any case and with any spacing: every space, tab, no-break space and zero-width/format character is ignored, so "facebook  user", "FacebookUser" and "Facebook User" all count. "Facebook", "User", "Facebook Users" and "Facebook User Ana" do not. The code and tests held no other placeholder; the data could not be checked from here (no server access), so the other two are the names Meta shows for a hidden Messenger and Instagram profile.

**Every place changed (FU-1 to FU-3):**

| Place | Before | Now |
|---|---|---|
| `PancakeChat::forOrder` (`app/Services/PancakeChat.php`) | PSID → page + name → name only, for any name | PSID step unchanged. A PSID was given and found nothing → no hit. No PSID and a placeholder → no hit. No PSID and a real name → page + name → name only, as before. |
| Astra: EARLIER CONVERSATION (`AstraEncoder::pancakeHistory`), the `get_chat_history` tool (`AstraEncoder::runTool` → `MacroChecker::fetchPancakeChat`), `astra:replay-address-rules` | read through `forOrder` (spec 131 amendment 1) | no code change; they follow `forOrder`. Pinned by tests. |
| Checker 1: pass 2 (`MacroChecker::fetchPancakeChat`) and "See more" (`MacroOutputController::pancakeMore`) | read through `forOrder` | no code change; pinned by tests ("See more" of the 18/11 order says `found: false`). |
| `OrderIdentity::find` | name rule for any non-empty name | a placeholder skips the name rule: phone, then identical input (Macro), as an empty name. |
| `ImportLikhaFromGoogleSheet::importRow`, `ImportMacroFromGoogleSheet` (the callers of `find`) | key phone computed only for an empty name | computed for a placeholder too; the "No name / no phone" flag (Likha) and the no-key count (Macro) follow the same test. |
| `LiveOrder::find` (`app/Services/OrderEncode/LiveOrder.php`) | `botcake_psid = PSID OR (empty PSID AND fb_name = name, same day)` | a placeholder is no name: no OR branch; no PSID and a placeholder → no customer (new row). |
| `LiveOrder::values` (the encode's mirror of the Likha import) | phone / flag rule for an empty name | same rule for a placeholder, so the encode writes what the Likha import writes. |
| `BotcakePsidMatcher::orderQuery` (exact and case-insensitive steps) | orders by PAGE + fb_name | null for a placeholder. |
| `BotcakePsidMatcher::evaluate` (sheet records, first try and every retry) | attach by name, or conflict | a placeholder record becomes `conflict` at once, nothing attached. |
| `BotcakePsidMatcher::copyReorders` | copy the single known PSID of PAGE + fb_name | placeholder customers skipped. |
| `BotcakeCustomerMatcher::matchPage` (Botcake API customers, also by name; found while reading) | a single "Facebook User" API customer was attached to every "Facebook User" order by name | placeholder names are no name on both sides; such an order goes to the phone rule, as a nameless one. |

**Read and left as is** (they key by name but do not put another customer's data on an order):
- `MacroOutputController` list, `has_pancake` (`EXISTS full_name = fb_name`): only decides whether the "See more" link shows. For a "Facebook User" order the link can still show and then answers "no chat". Changing it means a normalized-placeholder test inside the list SQL; Checker 1 UI is out of scope.
- `PancakeRetrieve2Controller` (`/pancake/retrieve2`, "conversations not yet an order", page + name for conversations without PSID): a report; a "Facebook User" conversation without PSID can be hidden by any "Facebook User" order of its page. Its SQL is tuned for speed (spec 302/305 tests); left for a separate change.
- `Pancake\RetrieveOrdersController::check` (`/pancake/retrieve-orders`, pasted sender names): a report; a pasted "Facebook User" line still says EXISTING when any such order exists.

**FU-4:** no code beyond `forOrder`: with no conversation, the prompt says `EARLIER CONVERSATION: none found.` and the tool answers `No earlier conversation found.`, which is the existing no-chat path.

**FU-5:** `php artisan orders:placeholder-mixups --from=Y-m-d --to=Y-m-d [--page=NAME]` (`app/Console/Commands/OrdersPlaceholderMixups.php`). SELECT only, one day at a time: first the short columns (id, PAGE, fb_name, FULL NAME, PHONE NUMBER) of every row of the day; a row with a placeholder fb_name is a suspect for a field when another row of the same page and day has the same FULL NAME (lower case, spaces collapsed, not a placeholder) or the same PH mobile (normalized). Then all_user_input and CXD of the suspects and their candidates only (500 ids per query). Reported when the value is not in the suspect's all_user_input nor in its CXD without ASTRA blocks (Astra's own block holds the copied value, so it is no proof). "Likely from" is the first candidate whose own text has the value ("from has it: yes"), else the lowest candidate id ("no"). Output: a table `id | page | day | field | likely from | from has it`, then `Placeholder rows checked`, `Rows reported: N (FULL NAME: a, PHONE NUMBER: b)` and `Ids: …`. It never prints a name, phone or address. Bad or missing dates, or `--from` after `--to`, exit 1.

**Tests added:** `tests/Feature/PancakePsid/PlaceholderNameLookupTest.php`, `tests/Feature/PancakePsid/PlaceholderAstraTest.php`, `tests/Feature/Orders/PlaceholderOrderKeyTest.php`, `tests/Feature/Orders/PlaceholderMixupsCommandTest.php`, `tests/Feature/OrderEncode/PlaceholderLiveOrderTest.php`, `tests/Feature/Botcake/PlaceholderPsidMatchTest.php`. Changed: `tests/Feature/PancakePsid/PancakeChatLookupTest.php` (see Story changes).

## Evidence per done-when item

**1. A test for every H and N case passes; full suite vs base.**

Red first: before the code change the new tests ran with 13 failures (every FU-1 to FU-4 case below except the constant test; the FU-4 one failed because Mercedita's chat was in the prompt as EARLIER CONVERSATION). After the change:

| Case | Test (file :: method) | Result |
|---|---|---|
| Placeholder list: empty, any case and spacing, one constant | `PlaceholderNameLookupTest::test_the_placeholder_constant_covers_empty_and_any_case_or_spacing` | pass |
| FU-1 H: "Facebook User", PSID not imported, another "Facebook User" chat on the page → no hit; nothing reaches Astra (history), Checker 1 pass 2, "See more" | `PlaceholderNameLookupTest::test_FU_1_a_placeholder_order_whose_psid_chat_is_not_imported_gets_no_chat_and_nothing_of_the_other_customer` | pass |
| FU-1 H: same order once its PSID conversation exists → that chat, "Matched by PSID" | `PlaceholderNameLookupTest::test_FU_1_the_same_order_gets_its_own_chat_matched_by_psid_once_it_is_imported` | pass |
| FU-2 H: PSID present, missing, real name "Ana Cruz" with another Ana Cruz chat → no hit | `PlaceholderNameLookupTest::test_FU_2_an_order_with_a_psid_never_borrows_a_real_names_chat` | pass |
| FU-2 H: no PSID, real name → page + name ("Matched by page and name"), then name only | `PlaceholderNameLookupTest::test_FU_2_N_an_order_without_a_psid_keeps_the_page_and_name_then_name_only_fallbacks` | pass |
| FU-3 H: two "Facebook User" import rows, same page and timestamp, different phones → two orders | `PlaceholderOrderKeyTest::test_FU_3_two_facebook_user_rows_on_the_same_page_and_timestamp_with_different_phones_are_two_orders` | pass |
| FU-3: same "Facebook User", same phone → one order; no phone → never joins, flagged; `OrderIdentity::find` falls to phone / identical input | `PlaceholderOrderKeyTest::test_FU_3_the_same_facebook_user_with_the_same_phone_is_still_one_order`, `…_without_a_phone_never_joins_…_and_is_flagged`, `…_order_identity_find_falls_through_…` | pass |
| FU-3 N: real name keeps the import key | `PlaceholderOrderKeyTest::test_FU_3_N_a_real_name_keeps_the_old_key`, plus the unchanged `OrderImportKeyTest` | pass |
| FU-3 `LiveOrder::find`: PSID order does not take another "Facebook User"'s import row; no PSID + placeholder → own row | `PlaceholderLiveOrderTest::test_FU_3_an_order_with_a_psid_does_not_take_…`, `…_without_psid_and_a_placeholder_name_makes_its_own_row` | pass |
| FU-3 N `LiveOrder::find`: real name without PSID still linked by page + fb_name; same "Facebook User" by PSID still one row | `PlaceholderLiveOrderTest::test_FU_3_N_a_real_name_without_psid_…`, `…_N_the_same_facebook_user_by_psid_is_still_one_row`, plus the unchanged `OneRowPerDayTest` | pass |
| FU-3 `BotcakePsidMatcher` exact + case-insensitive sheet step, retry, reorder copy | `PlaceholderPsidMatchTest::test_FU_3_a_sheet_psid_with_a_placeholder_name_is_never_attached_by_name`, `…_a_waiting_placeholder_record_is_not_attached_on_retry`, `…_a_reorder_never_copies_…_and_a_real_name_still_does` | pass |
| FU-3 N `BotcakePsidMatcher`: real-name sheet PSID attached | `PlaceholderPsidMatchTest::test_FU_3_N_a_real_name_sheet_psid_is_still_attached`, plus the unchanged `BotcakePsidImportTest` | pass |
| FU-3 `BotcakeCustomerMatcher`: no attach by placeholder name, phone decides; real name as before | `PlaceholderPsidMatchTest::test_FU_3_the_botcake_customer_match_never_uses_a_placeholder_name_and_falls_to_the_phone`, `…_a_single_facebook_user_customer_is_not_attached_…`, plus the unchanged `BotcakeCustomerMatchTest` | pass |
| FU-4 H: 18/11 end to end, fake model: own phone 639913485181 kept, no "Mercedita" in any request (prompt and the `get_chat_history` answer) | `PlaceholderAstraTest::test_FU_4_the_18_11_case_end_to_end_keeps_the_rows_own_phone_and_never_shows_mercedita_to_the_model` | pass |
| FU-5 H: 18/11 fixture → 1997748, 1998721, 1999317, 1999592 listed (each with field and "from 1997706"), not 1997706; no names/phones/addresses printed; SELECT only, rows unchanged | `PlaceholderMixupsCommandTest::test_FU_5_the_18_11_fixture_lists_the_four_wrong_rows_and_not_merceditas_own_row` | pass |
| FU-5: without `--page` the ShadeShift row is listed too; other day outside the range; bad dates exit 1; nothing found says 0 | `PlaceholderMixupsCommandTest::test_FU_5_without_a_page_…`, `…_bad_or_missing_dates_are_refused`, `…_nothing_to_report_says_so` | pass |

Runs (Herd PHP 8.4.16):
- New tests: 25 tests, all pass.
- `tests/Feature/PancakePsid tests/Feature/Orders tests/Feature/OrderEncode tests/Feature/Botcake tests/Feature/NightRun tests/Feature/Chatblast tests/Feature/PageSettings` (`-d memory_limit=4G`): 3,611 tests, 0 failures, 0 errors, 2 skipped. The existing Astra, MacroChecker, NightRun and spec 131 tests pass unchanged (one 131 line changed, see Story changes).
- `php artisan list` shows `orders:placeholder-mixups`.
- Full suite, base f964a6eb (before any code, `-d memory_limit=4G`): **5,874 tests, 4 failures, 6 skipped, 0 errors**. The 4: `ExampleTest::test_the_application_returns_a_successful_response`, `Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`, `OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_…` with `/owner/private`, `PageSettings\FlowNamesTest::test_S_052_7_a_refresh_with_the_same_answers_writes_no_new_row`.
- Full suite, branch: **5,899 tests (base + the 25 new), 3 failures, 6 skipped, 0 errors**. The 3 are base failures (ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest `/owner/private`). `FlowNamesTest::test_S_052_7` failed on base and passed on the branch; this spec touches nothing it uses (page settings flow names), so it reads as timing-dependent. **No new failures.**

**2. Every changed place and the placeholder constant:** see What shipped (table "Every place changed", the constant, and "Read and left as is").

**3. Pushed, Merge danger:** branch `feat/307-facebook-user-not-a-key` pushed to origin (`git push -u origin feat/307-facebook-user-not-a-key`); Merge danger below covers both sites, the cherry-pick files and the revert.

## Story changes
- Spec 131's lookup test `test_service_prefers_psid_then_page_and_name_then_name_only` asked for the page + name chat on an order whose PSID had no conversation. FU-2 reverses that on purpose, so the test now asks the page + name step with no PSID, and asserts that the same call with the unknown PSID returns nothing.

## Rulings
- **A placeholder sheet PSID record becomes `conflict`** rather than staying `waiting` for 30 days: it can never be attached by name, `conflict` already means "this name does not decide the customer", the sheet shows it and the daily clean deletes it like any conflict, and the hourly retry stops reading it.
- **Placeholder rows get the nameless phone rule when they are written**, not only when matched: in the Likha import and the encode mirror the phone is now `OrderPhone::forOrder` (D, else the one number in E, else G) instead of the first `09…` in D, and a placeholder row with no phone gets the "No name / no phone" flag. The two go together in the code (one `$namePhone`), and the flag tells staff the row cannot be tied to a customer, which is true for "Facebook User" too.
- **An order with a PSID gets no name fallback even when the `psid` column is missing** (migration not run). Both sites have spec 131's migration, so this only matters on a fresh database.
- **Test fixtures use made-up customers.** The H cases name "Mercedita" and the phone 639913485181, and the 18/11 order ids; those are used as given. Mercedita's surname and phone, and every other customer, are invented, so no other real customer data is copied into the tests.
- **FU-5 counts an empty fb_name as a placeholder**, as the constant does (FU-1 lists empty as a placeholder). The "not in its own text" condition keeps the nameless rows that are fine out of the list.

## Merge danger

**Commits to cherry-pick (Likha main and Incepxion's line, in this order):** `5f9111c9` (the fix + its tests), `abe78150` (the finder + its test). The docs commits are optional. No migration, no config, no new setting, no route, no schedule.

**Files a cherry-pick touches:**
- New: `app/Services/PlaceholderName.php`, `app/Console/Commands/OrdersPlaceholderMixups.php`, the six test files listed above.
- Changed: `app/Services/PancakeChat.php`, `app/Services/OrderIdentity.php`, `app/Jobs/ImportLikhaFromGoogleSheet.php`, `app/Jobs/ImportMacroFromGoogleSheet.php`, `app/Services/OrderEncode/LiveOrder.php`, `app/Services/BotcakePsidMatcher.php`, `app/Services/BotcakeCustomerMatcher.php`, `tests/Feature/PancakePsid/PancakeChatLookupTest.php`.
- Each change is a few lines at one spot (a `use` line, a condition, an early return). If Incepxion's copy of a file differs nearby, the conflict is local; the rule to keep is "a placeholder is no name" and "PSID given and not found → no hit". The finder needs `AstraEncoder::BLOCK_MARK`, `BotcakeCustomerMatcher::normName/normPhone` and `OrderPhone::numbers`, all present at f964a6eb; check they exist on Incepxion's line. The tests need spec 131's `PancakePsid` test base and migration (`2026_11_05_100000_add_psid_to_pancake_conversations`).

**What changes on both sites after deploy (nothing runs by itself; it applies to the next import, encode, Astra row, Checker row and PSID run):**
1. An order with a PSID whose Pancake conversation is not imported yet gets no Pancake history (Astra, Checker 1 pass 2, "See more"), even for a real name. On Incepxion, where conversations arrive late (22:23 on 18/11), more rows will run with "EARLIER CONVERSATION: none found" until the import lands; a later Astra/Checker run of the row picks the chat up by PSID.
2. "Facebook User" orders never get a chat by name. Real-name orders without a PSID keep page + name, then name only.
3. Imports key "Facebook User" rows by TIMESTAMP + PAGE + phone (then identical input on Macro) instead of the name, exactly as nameless rows already are. The Macro import finds the order the Likha import made by this key: for a "Facebook User" it now needs the same phone (D, else the one number in the input) on both sheets. When the two sheets carry different phones, Macro inserts its own row instead of updating; before, it updated whichever "Facebook User" of that page and minute it found first, which could be another customer. Sheet rows are imported once each (Likha marks DONE, Macro its status column).
4. Waiting Botcake sheet PSIDs with a placeholder name turn `conflict` on the next PSID run (the sheet shows "conflict", the daily clean deletes them). Reorder copy and the Botcake API customer match stop attaching PSIDs by "Facebook User"; such orders get a PSID only by phone. Expect a drop in "by_name" counts on pages with many hidden profiles; those were the risky ones.
5. The Likha import and the encode write the phone of a placeholder row with the nameless rule (D, else the one number in E, else G) and flag one with no phone "No name / no phone".

**Not changed:** existing rows (the five 18/11 rows stay wrong until a data fix is approved from the finder's list); the `has_pancake` link, `/pancake/retrieve2`, `/pancake/retrieve-orders`.

**Revert:** `git revert abe78150 5f9111c9` on each line (or the cherry-picked equivalents). No data or schema to undo; the only data the fix writes differently is PSID records set to `conflict` and the key/phone of newly imported placeholder rows, which a revert does not need to touch. The finder is read-only.
