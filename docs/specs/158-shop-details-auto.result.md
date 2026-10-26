# Result 158: SHOP DETAILS is built automatically from the page's fields

## Plan
This is one medium-risk slice with no new route. Server and UI shipped together because the read-only grid is tested through `/chatbot/data`.
1. `App\Services\Chatbot\ShopDetails` holds the rule in one place: `build()`, `price()`, `missing()` and `for($page)`.
2. Every reader of the page's SHOP DETAILS goes through it, and the stored `shop_details` row is skipped.
3. /chatbot/pages:
   - The column is kind `auto`: read-only, it shows the built text and the note "Auto: ITEM NAME IN JNT DASHBOARD + PRICE + QUANTITY".
   - Cell save and restore are refused with a 422, `/chatbot/cells` skips the key, and paste-map doesn't offer it.
   - In the browser, the column is left out of the edit targets, the modal fields and the mapping dropdown.
4. The content check shows an X "SHOP DETAILS: missing <field>".
5. A new read-only command, `chatbot:shop-details-diff`.
6. Review by `skeptic-reviewer` at standard depth, followed by one fix loop.

## Progress
- **Baseline at 816c5baa:** `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` → 1660 tests, 0 failures, 1 skipped.
- **Red, first slice:**
  - `ShopDetailsAutoTest` S-158.1/.5 failed with `Class "App\Services\Chatbot\ShopDetails" not found` and the command not found.
  - S-158.2/.6 failed with `Undefined array key "SHOP DETAILS"`.
  - S-158.3 failed on the column's kind and the note.
  - `UploadMacroTest::test_S_158_2` failed because the order took the stale stored text.
- **Green:** after the feat commit, the target suite ran 1670 tests, 0 failures, 1 skipped.
- **Review** found 3 majors:
  1. The Inbox panel still showed the stored row.
  2. The page check's flow variables used the stored row, so a flow using `{{SHOP DETAILS}}` got a permanent "Missing: SHOP DETAILS".
  3. Putting SHOP DETAILS into `defaults()` moved it to slot 5, which shifted positional grid pastes.
- **Fix loop 1** (red first: the Inbox, page-check, price and multi-line cases failed on the missing behaviour):
  - The Inbox and `ChatbotPageCheck` use the built text.
  - The column keeps today's place: after the added and in-use keys, or last. A positional paste gives it a slot whose value is dropped, so sheet rows stay aligned.
  - A price with no leading number gives no PRICE line, so it counts as missing.
  - A multi-line ITEM or QUANTITY uses its first line only.
  - The re-check found no blocker or major; its 3 new minors are accepted in `TODO.md`.
- **Final:** the target suite ran 1676 tests, 0 failures, 1 skipped (the same skip as the baseline). No new failures.

### Design choice not in the spec
The SHOP DETAILS content check runs only when the page has at least one of ITEM NAME IN JNT DASHBOARD, PRICE, QUANTITY or an old typed SHOP DETAILS. A page with none of them isn't selling yet, and flagging it would put three X's on every bare page. A page with some of the fields but not all gets the X that decision 3 asks for.

## Readers changed
- `app/Services/Chatbot/ChatbotPage.php:68,74-75`: `botfields()` skips the stored row and sets `shop_details`, `SHOP DETAILS` and the column label to the built text. This covers the flow variables, the "Upload to macro" / "Encode order" input (`{{SHOP DETAILS}}`), comment replies, CommentCheck, Preview and every FlowRun.
- `app/Services/Chatbot/PageContentCheck.php:78,209-219`: the content check (Check column). It flags a missing part with an X; the old typed-text comparison is removed.
- `app/Services/Chatbot/ChatbotPageCheck.php:154`: the "Missing:" botfields check in `runFlow`.
- `app/Services/Chatbot/Inbox.php:345-348`: the Inbox customer panel's botfields.
- `app/Http/Controllers/ChatbotController.php`:
  - :134, `data()`: the grid cell and the grid's Copy (the export).
  - :216, `cells()`: skips the key.
  - :265, `pasteMap()`: the key is not a target.
  - :725, `valueError()`: refuses `cell` and `restore`.
- `app/Services/ChatbotFields.php:54,74-77`: kind `auto`, and the column is always present in its old place with the label SHOP DETAILS.
- `resources/views/chatbot/index.blade.php`:
  - `editCols()` and `SHOP_NOTE`.
  - The read-only grid cell.
  - The grid paste drops values that land on it.
  - The read-only modal field.
  - The modal's edit targets, start field and mapping dropdown use `editCols()`.
- `app/Console/Commands/ChatbotShopDetailsDiff.php` (new): `chatbot:shop-details-diff`.
- Not changed, because they read order rows (`macro_output`), not page settings:
  - `LiveOrder` (it writes what the flow gives it, now the built text).
  - `FlowEncode::orderFor`.
  - MacroChecker, Pancake and the Cancel Detector.
  - The Google Sheet imports, which are out of scope.

## Case table
| Case | Test | Result |
|---|---|---|
| S-158.1 | `ShopDetailsAutoTest::test_S_158_1_the_rule_builds_page_item_price_and_quantity`. Table of 9 rows: ₱198, 198, 1,299, PHP 1,299, P 1,299.50, ₱ 198, FREE, Promo 199, and a multi-line item. | pass |
| S-158.2 | `ShopDetailsAutoTest::test_S_158_2_flow_variables_use_the_computed_text_and_ignore_a_stale_stored_row` | pass |
| S-158.2 | `UploadMacroTest::test_S_158_2_the_uploaded_order_takes_item_and_cod_from_the_computed_shop_details_not_a_stale_stored_row`: ITEM_NAME "2 x GLOW TAPE", COD 198. | pass |
| S-158.2 | `ChatbotInboxTest::test_S_158_2_the_inbox_panel_shows_the_built_shop_details_not_the_typed_one` | pass |
| S-158.3 | `ShopDetailsAutoTest::test_S_158_3_the_grid_shows_it_read_only_and_every_write_is_refused` | pass |
| S-158.3 | `ShopDetailsAutoTest::test_S_158_3_the_paste_mapping_does_not_offer_shop_details` | pass |
| S-158.3 | `ShopDetailsAutoTest::test_S_158_3_the_shop_details_column_keeps_its_place_so_sheet_pastes_stay_aligned` | pass |
| S-158.4 | `ChatbotContentCheckTest::test_S_158_4_shop_details_is_built_from_the_fields_and_a_missing_part_is_an_x` | pass |
| S-158.4 | `ChatbotPageCheckTest::test_S_158_4_a_flow_using_shop_details_is_not_missing_it_without_a_typed_row` | pass |
| S-158.5 | `ShopDetailsAutoTest::test_S_158_5_the_diff_command_lists_only_pages_whose_old_text_differs` | pass |
| S-158.6 | `ShopDetailsAutoTest::test_S_158_6_an_incepxion_style_page_on_forbeast_uses_the_same_rule` | pass |
| S-158.7 (P2) | 390 px: manual, needs a browser | not run (no running app in this worktree; accepted in TODO) |

Final run: `Tests: 1676, Assertions: 9735, PHPUnit Deprecations: 76, Skipped: 1`. There were no failures.

## Story changes
- S-145.1, S-145.2 and S-145.3 compared the stored SHOP DETAILS with the fields. S-158.4 replaces them, because the stored text is no longer read, and their tests are removed.
- S-106.4: the "no X" assertion now covers price findings only. The page there has only a PRICE, so SHOP DETAILS is correctly an X.
- S-099.1 also expects the two SHOP DETAILS X's.
- S-099.4 now fills ITEM and QUANTITY.
- S-099.5 recomputes on a Prompt 2 change instead of a QUANTITY change, because a QUANTITY alone would now be an X.
- S-068.7: the new column is now just before SHOP DETAILS, which is always the last column when no page has a typed row.
- S-149.2 tests a nested botfield through Main Flow instead of SHOP DETAILS.
- S-158.1, .2, .3 and .4 gained the edge cases from the review: a price with no number, a multi-line item, the Inbox, the column's place, and a flow using `{{SHOP DETAILS}}`.

## Accepted findings
These are listed in `TODO.md` under "158", each with its reason:
- the diff command's literal comparison,
- the modal's read-only value not updating live,
- the PAGE line using the caller's page string,
- the AI paste mapping getting no guess for a SHOP DETAILS cell,
- the "missing PRICE" wording for a price like "FREE",
- a custom "+ Column" label for `shop_details`,
- 390 px not checked in a browser.

## Merge danger
- **Check column after deploy:** the Check column starts showing an X on pages with ITEM, PRICE or QUANTITY empty. Those pages' orders already got no item or COD.
- **Before or right after deploy:** run `php artisan chatbot:shop-details-diff`, which is read-only. On 2026-10-26 about 6 of 33 pages differed, among them AllStock Mart and Tech Flask Ph. Their orders change to the fields' values from the first run after deploy.
- **No migration**, no new table and no new setting rows.

## Amendment 158-1: the Chatblast directory run (qa finding)
- **Finding:** running `tests/Feature/Chatblast` as a whole failed `ChatbotWaitSkipTest::test_S_076_3` with data set "bot paused" ("Skipped: … bot paused (n2)" expected, '' got). It passed alone.
- **Reproduced:** two full-directory runs failed it the same way, together with `ForbeastStandbyHiddenTest::test_S_117_15` (see the second cause).

### Cause 1: id reuse in the "tags ready" cache (not state from 158)
- **The failing flow run:** a temporary trace showed that the WaitSkip run crashed with `table chatbot_replies has no column named flow_version_id`. No wait was created, so nothing could be skipped.
- **The cache:** `ChatbotTags::ready()` caches "the tags tables exist" per `spl_object_id(app())`. PHP reuses a freed object's id, so a new test's app can get an earlier test's id and inherit its "ready" answer. WaitSkip's database has no tags columns, yet `ready()` said yes.
- **Why it depended on unrelated files:** whether an id gets reused depends on memory allocation. Dropping `ShopDetailsAutoTest`, which runs after WaitSkip, made it pass; dropping `ForbeastStandbyHiddenTest` made both pass. A filtered run of only the files up to WaitSkip also passed.
- **The existing workaround:** `OrderEncodeTestCase::tearDown` already resets `ChatbotTags::$ready` for exactly this reason; the Chatblast base class never did. 158 added no shared state: no static or memo in `ShopDetails`, and `ChatbotPage::$ids` is unchanged.
- **Production is not affected:** a worker runs one app per process.
- **Fix:** the same one-line reset in `tests/Feature/Chatblast/ChatblastTestCase.php` `tearDown()`.
- **Code fix tried and dropped:** I first changed the cache key to a `WeakReference` (the `ChatbotReply::hasRaw()` pattern). That broke two OrderEncode tests, because their tearDown resets the property with the old shape. The tests-only fix is smaller and matches the existing practice. The code fix is suggested in `TODO.md`.

### Cause 2: a clock-dependent test (also not 158)
- `ForbeastStandbyHiddenTest::test_S_117_15` puts a chat at `now()->subHour()` and expects it in "today". Between 00:00 and 01:00 Manila, that chat falls on yesterday, so the test fails even alone. This is why base failed it right after midnight.
- **Fix:** the test pins its clock to 12:00 with `travelTo`, so it no longer depends on the time of day.

### qa finding 2 and final runs
- **qa finding 2:** `ChatbotContentCheckTest::test_S_158_4…` now opens with an explicit assertion that a page with none of the fields gets no SHOP DETAILS finding. A probe value confirmed the assertion is live.
- **Final:** `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode tests/Feature/MetaAds` was run twice, at 00:3x Manila, past midnight. Both runs gave `Tests: 1710, Assertions: 9930, Skipped: 1`, 0 failures.

## Commits
- `16efcd3f` docs: spec 158 and its result template (spec 158)
- `85c69d1a` feat: SHOP DETAILS is built from the page name, item, price and quantity; read-only on the pages grid, with a diff command (spec 158)
- `ee086fef` fix: the Inbox and the page check use the built SHOP DETAILS, the column keeps its place for pastes, and a price without a number is missing (spec 158)
- `403d6cdc` docs: result, case table, accepted minors and the order-encode note (spec 158)
- The amendment 158-1 fix commit: the test-isolation reset, the S-117.15 clock pin and the explicit S-158.4 assertion.

158: done — SHOP DETAILS is built from the page's fields everywhere it is read; the Chatblast run failure was a pre-existing test-isolation bug (object-id cache) plus a midnight-dependent test, both fixed in tests; 5 test folders run twice, 1710 tests, 0 failures.
