# Result: spec 217 a fast pages screen, and ad groups edited like a page

## Status

done (one open question below; it does not block the merge)

## Date

2026-11-03

## Branch / PR

`feat/217-pages-speed-ad-group-edit` (worktree `.claude/worktrees/pages-217`), based on `vps/main` f5d6d450. Not pushed, no PR.

## Summary

**A. Speed (S-217.1).**

- **Cause, measured first.** The grid built every row in full: about 400 elements a row, so 33,600 elements for 80 pages. It added rows 6 per frame, and every frame laid out, styled and painted the whole table again. On a CPU slowed 4 times, the screen was usable only after 14.6 s, through about 15 tasks of 1–1.6 s each. Showing an Ad-based page's ad groups, or its Edit window, then cost another 1.4–2.1 s.
- **Fix: stand-in rows** (`resources/views/chatbot/index.blade.php`, block `lazy-rows`).
  - A row far from the view is a light stand-in: the page name and the row's height.
  - An IntersectionObserver on the table box builds the full row when it comes within 800 px of the view.
  - On the first screen, the rows in view are built in one frame. After that, rows in view are built in up to 60 ms a frame, and rows near the view in 12 ms a frame.
  - A full row that moves more than 3,200 px away goes back to a stand-in of the same height. The open row, the row being edited, a row with focus and a page with its Ad groups shown never fold.
  - The table now holds about 4,800 elements after load instead of 33,600. It stays at a few screens of rows whatever the page count.
  - Filters, Copy, Paste, Save, Undo and Check work on the data, not the DOM, so they are unchanged.
  - `aria-busy` stays on the table until the rows in view are built.
- **Fix: Edit window.**
  - On close it frees its fields and its ad groups (about 3,800 elements used to stay).
  - Its textareas are sized in one pass, so one layout instead of one per field.
  - Ad group changes made while the Edit is open repaint the table once, on close.
- **Ad group sub-rows (S-217.1.2).** Group rows and ad lists are built only when shown and removed when hidden. This was already true before this spec, and the run records it.

**B. Ad group Edit (S-217.2).**

- **The page's Edit window, for a group.** `openPe(r, g)` reuses `#pe`, titled "Page · Group".
  - **Fields.** It shows the page Edit's own fields, minus `AdGroups::EXCLUDED` (identity and switch keys). SHOP DETAILS shows as "Built from the group's item".
  - **Values.** Each field holds the group's own value. Empty shows "uses default: <page value>", muted.
  - **Paste.** The paste box, "Starting at field", "Paste blanks as empty" and the AI mapping are the page's own code: the same `/chatbot/paste-map` call with the page name, the same `PasteFieldAi`, preview and Apply. There is no second AI path and no prompt change.
- **Where to open it.** Each group row in the table, and each group in Manage in Edit, has an **Edit** button. Opened from Manage in Edit, the group Edit goes back to the page Edit when it closes.
- **Save.** It sends the changed values in one request to the existing group value endpoint: `POST /chatbot/ad-groups/value` with `values: {key: value}`.
  - Same access check, page check, throttle bucket and history as before.
  - `AdGroups::setValues()` runs one lock, one validation and one transaction: all or nothing.
  - The group's name and ads are read inside the lock.
  - A blank goes back to the page value.
- **History.** The History link opens the group's history, with Restore.

**C. Running first (S-217.3).** In `AdGroups::overview()`:

- Groups come not hidden first, then those with an On ID, then by customers in 30 days, then by name.
- IDs inside a group: not quiet, then On, then New, then customers, then last time.
- "Ads not in a group yet" keeps its New, On, customers order (see Story changes).
- This is screen order only. The live matching of a customer to a group is unchanged.

**D. Test from an ad (S-217.4).**

- **The Ad dropdown.** On an Ad-based page, /chatbot/test has an **Ad** dropdown (`adOptions`): each group's IDs (one optgroup per group, in the server's order), then the ads not in a group. On comes first in each list, and each entry shows name · status · id.
- **The server.** `ChatbotTabsController::testAd()` resolves the group the way the live run does: the ad, then its ad set, then its campaign, through `AdGroups::groupForAd`, with no Meta call.
- **The log line.** "Ad <id> → group <name>", or "Ad <id> → Default".
- **With the Ad group picker.** When both are set, the Ad wins and the line says so.
- **Page-based pages.** No dropdown, and the server ignores `ad`.
- **Skip timer.** The skip timer uses the same group.

**E. Manage groups (S-217.5).**

- "Manage in Edit" is a normal-size primary button beside the "N ads not in a group yet" count.
- The Default row reads "Default (page values)".

**Files changed**

- `resources/views/chatbot/index.blade.php`, `public/js/chatbot-adgroups.js`, `resources/views/chatbot/test.blade.php`
- `app/Http/Controllers/ChatbotAdGroupController.php`, `app/Services/Chatbot/AdGroups.php`, `app/Http/Controllers/ChatbotTabsController.php`
- Tests:
  - `tests/Feature/Chatblast/AdGroupEditTest.php` (new)
  - `tests/Feature/Chatblast/TestFromAdTest.php` (new)
  - re-pinned order assertions in `AdGroupPagesTest.php` and `AdBasedPagesTest.php` (see Story changes)
- Timing set and run: `tests/perf/set217.php` (new), `tests/perf/pages217.mjs` (new).
  - `tests/perf/screens.mjs` now exports `GRID_CHECK` and `topFunctions`.
  - `tests/perf/tailwind.mjs` takes an optional `extra` CSS argument.
- `qa/stories.md`, `TODO.md`, `docs/plans/217-pages-speed-and-ad-group-edit.md`

## Case table

| Case | Test | Result |
|---|---|---|
| S-217.1.1 | `pages217.mjs --cpu=2` "S-217.1.1 server < 1 s, usable < 2 s, no task over 200 ms after" | pass at 2× (desktop: server 771 ms, usable at 1,431 ms, longest after load 118 ms, scrolling 167 ms) and at 1×; fails at 4× (see Checks) |
| S-217.1.2 | `pages217.mjs` "S-217.1.2 groups and ad lists built only when shown, freed when hidden" | pass (1×, 2×, 4×; desktop and phone) |
| S-217.1.3 | `pages217.mjs` "S-217.1.3 …" (14 checks); S-172/174/181/192/212 suites (`AdGroupPagesTest`, `AdGroupRunTest`, `AdGroupsTest`, `AdBasedPagesTest`, `AdPreviewTest`, `AdGroupAllValuesTest`, `CommentRepliesTest`) | pass (14/14 at 1×, 2× and 4×; suites green) |
| S-217.1.4 | `pages217.mjs --phone --cpu=2` "S-217.1.4 loads and scrolls without freezing at 390 px" | pass at 1× and 2×; fails at 4× |
| S-217.2.1 | `pages217.mjs` "S-217.2.1 …" (5 checks) | pass |
| S-217.2.2 | `AdGroupEditTest::test_S_217_2_2_and_2_5_a_group_edit_save_writes_its_values_in_one_request_with_history_and_restore`, `test_S_217_2_2_a_bad_value_in_the_group_edit_saves_nothing`; `pages217.mjs` "S-217.2.2 …" (7 checks, AI answer faked) | pass |
| S-217.2.3 | `AdGroupEditTest::test_S_217_2_3_identity_and_switch_keys_are_not_group_fields_and_are_refused`; `pages217.mjs` "S-217.2.3 …" | pass |
| S-217.2.4 | `AdGroupEditTest::test_S_217_2_4_a_user_who_cannot_edit_page_values_gets_no_group_edit_and_403_on_its_save` | pass |
| S-217.2.5 | `AdGroupEditTest::test_S_217_2_2_and_2_5_…`; `pages217.mjs` "S-217.2.5 …" (2 checks) | pass |
| S-217.3.1 | `AdGroupEditTest::test_S_217_3_1_groups_with_an_on_ad_come_first_then_by_customers_then_name_and_ads_on_then_new_then_customers`; `pages217.mjs` "S-217.3.1 …" | pass |
| S-217.3.2 | `AdGroupEditTest::test_S_217_3_2_ads_not_in_a_group_yet_list_on_then_new_then_customers` | pass |
| S-217.3.3 | `AdGroupEditTest::test_S_217_3_3_a_group_just_made_without_an_on_ad_stays_visible_after_the_running_groups` | pass |
| S-217.4.1 | `TestFromAdTest::test_S_217_4_1_the_ad_dropdown_lists_grouped_ads_by_group_then_the_rest_on_first_with_name_status_and_id`; `pages217.mjs` "S-217.4.1 …" | pass |
| S-217.4.2 | `TestFromAdTest::test_S_217_4_2_an_ad_in_a_group_by_ad_ad_set_or_campaign_uses_the_group_and_an_ad_in_no_group_uses_default`; `pages217.mjs` "S-217.4.2 …" | pass |
| S-217.4.3 | `TestFromAdTest::test_S_217_4_3_a_page_based_page_ignores_the_ad_and_tests_as_today`; `pages217.mjs` "S-217.4.3 …" | pass |
| S-217.4.4 | `TestFromAdTest::test_S_217_4_4_the_ad_group_picker_still_works_and_the_ad_wins_when_both_are_set` | pass |
| S-217.5.1 | `pages217.mjs` "S-217.5.1 …" (2 checks) | pass |
| S-217.5.2 | `pages217.mjs` "S-217.5.2 …" | pass |

## Checks (command and output)

PHP is Herd's (`C:\Users\Forbeast\.config\herd\bin\php84\php.exe`). Tests run on sqlite in memory, per `phpunit.xml`.

- `php vendor/bin/phpunit tests/Feature/Chatblast/AdGroupEditTest.php tests/Feature/Chatblast/TestFromAdTest.php` → `Tests: 11, Assertions: 103` (deprecations only, no failure).
- `php vendor/bin/phpunit --filter "AdGroup|AdBased|AdOpen|AdPreview|TestFromAd" tests/Feature/Chatblast` → `Tests: 139, Assertions: 1640`, no failure.
- `php vendor/bin/phpunit --filter "ChatbotNoHang|ChatbotPages|ChatbotPaste|AdGroupPages|AdGroupAllValues|AdBasedPages|AdGroups|ChatbotFrozenCheck|ChatbotFbLink|FacebookName|ChatbotPageEdit|ChatbotPageHistory|ChatbotConnectedFilter|ChatbotDataSpeed|ChatbotSetupSpeed|ChatbotVariableGroups|ChatbotRepliesVia|AdPreview|AdOpen|ChatbotTabs|ChatbotTestTab|ChatbotCheckCache|ChatbotAllChanges" tests/Feature/Chatblast` → `Tests: 229, Assertions: 1847`, no failure.
- `php -l` on the three changed PHP files → `No syntax errors detected`.
- `node --check public/js/chatbot-adgroups.js` → ok. The page scripts are checked with `node --check` by `ChatbotConnectedFilterTest`, which passes.

### How the speed is measured (S-217.1.1, 1.2, 1.4)

- **The set.** `php tests/perf/set217.php migrate && php tests/perf/set217.php seed` builds it.
  - 80 pages, 30 values each.
  - Pages 007, 014 and 021 are Ad-based, each with 6 groups of 4–5 IDs and 250 ads. That leaves about 223 ads not in a group per page.
  - Each ad has 0–6 customers in 30 days, and 5 days of Ads Manager rows. 4 in 10 are On.
  - It lives in a throwaway sqlite file in `storage/perf217/` (ignored by git). There is no env file: the script sets the environment itself, with local test values and no outside keys.
- **The run.** `node tests/perf/pages217.mjs --phone --cpu=N` starts the local server (`php -S`) and headless Chrome. Nothing outside 127.0.0.1 resolves.
  - **Stylesheet.** The layout's Tailwind CDN script is answered with a stylesheet built locally from the views. It includes the layout's own `text/tailwindcss` rules, so the table scrolls in its box as it does live.
  - **Warm-up.** Each run first loads `/chatbot/pages` and `/chatbot/data` once, since the live server is warm.
  - **CPU.** `--cpu=N` slows the page's CPU N times.
- **What is recorded.**
  - Server time (request to first byte) and size of `/chatbot/pages` (HTML) and `/chatbot/data` (JSON).
  - When the page is usable: rows shown and the table not `aria-busy`.
  - DOM elements.
  - The longest main-thread task over each stretch:
    - from start until 2 s after usable;
    - after usable;
    - while scrolling the whole table and back at 400 px a frame;
    - showing each Ad-based page's groups;
    - Manage in Edit.
- **Before and after.** "Before" is f5d6d450 (`app`, `resources` and `public` checked out from it) with the same run. "After" is HEAD.

### S-217.1.1 before / after (desktop 1600 px)

| | before 1× | after 1× | before 2× | after 2× | before 4× | after 4× |
|---|---|---|---|---|---|---|
| Server `/chatbot/pages` | 253 ms / 169 KB | 278 ms / 179 KB | 271 ms / 169 KB | 73 ms / 179 KB | 285 ms / 169 KB | 345 ms / 179 KB |
| Server `/chatbot/data` | 694 ms / 614 KB | 945 ms / 614 KB | 897 ms / 614 KB | 771 ms / 614 KB | 642 ms / 614 KB | 763 ms / 614 KB |
| Usable at | 2,422 ms | 1,535 ms | 7,144 ms | 1,431 ms | 14,606 ms | 2,629 ms |
| DOM elements after load | 33,592 | 5,183 | 33,592 | 4,771 | 33,592 | 4,771 |
| Longest task (load) | 169 ms | 126 ms | 669 ms | 269 ms | 1,619 ms | 659 ms |
| Longest task after usable | < 50 ms | 80 ms | 146 ms | 118 ms | < 50 ms | 235 ms |
| Scrolling the table, longest task | < 50 ms | 90 ms | 231 ms | 167 ms | 404 ms | 350 ms |
| Show ad groups of an Ad-based page | 142 ms | 111 ms | 410 ms | 283 ms | 1,366 ms | 1,218 ms |
| Manage in Edit | 191 ms | 91 ms | 641 ms | 388 ms | 2,132 ms | 952 ms |
| S-217.1.1 | FAIL | pass | FAIL | pass | FAIL | FAIL |

- **Server time.** It varies from run to run (`php -S` serves one request at a time, on sqlite). It is under 1 s in every run, before and after. This spec changes no query of `/chatbot/data`.
- **Page size.** The page is 10 KB bigger, for the group Edit and the stand-in code.
- **At 4× (stress).**
  - Load is usable 5.6 times sooner.
  - Showing ad groups, Manage in Edit and fast scrolling still have tasks over 200 ms. One full row costs about 20 ms of script at 1×, plus its layout.

### S-217.1.4 before / after (phone 390 px, mobile emulation)

| | before 1× | after 1× | after 2× | before 4× | after 4× |
|---|---|---|---|---|---|
| Usable at | 2,070 ms | 1,008 ms | 1,879 ms | 13,647 ms | 2,262 ms |
| DOM elements after load | 32,872 | 1,861 | 1,861 | 32,872 | 1,861 |
| Longest task (load) | 152 ms | 74 ms | 264 ms | 1,970 ms | 525 ms |
| Longest after usable / scrolling | < 50 / < 50 ms | < 50 / 51 ms | 97 / 159 ms | 199 / 264 ms | 222 / 464 ms |
| S-217.1.4 | FAIL (usable > 2 s) | pass | pass | FAIL | FAIL |

The phone "before" numbers come from the first run of this harness, before the warm-up was added. A 2× phone "before" was not run.

### S-217.1.2 and S-217.1.3

**S-217.1.2** at 1×:

- Before showing: 0 sub-rows and 0 group or ad nodes.
- With 3 Ad-based pages shown: 42 sub-rows and 99 group or ad nodes.
- After hiding them: 0 and 0, and the DOM drops from 14,828 to 5,665 elements.
- It holds both before and after this spec. The base kept about 3,800 extra elements after Manage in Edit (37,399 against 33,592); that is freed now.

**S-217.1.3**: 14 checks in real Chrome, all passing at 1×, 2× and 4×:

- inline ✎ edit saves;
- a 2 × 2 paste shows "4 cells changed on 2 pages", saves, and Undo puts it back;
- the Edit window saves a field;
- History lists versions, and Restore brings back the older value;
- the Check column shows a result;
- the search filter shows "10 of 80", and clearing it shows 80;
- the Replies switch goes to Ad-based and back;
- a far row is built when scrolled to;
- the grid matches `/chatbot/data`: 80 rows shown, 0 values differ, 0 duplicates, 0 inert.

### S-217.2 to S-217.5 in the browser

- `node tests/perf/pages217.mjs --groups` (1×): 22 of 22 checks pass, on Perf Page 007 and /chatbot/test.
- The paste-map request recorded by the run: `{"page_name":"Perf Page 007","cells":["G1 main …","<price>",""]}`. That is the page's request; the run fakes the AI's answer.

### Full suite

`php vendor/bin/phpunit` → `Tests: 4180, Assertions: 75538, Failures: 4, PHPUnit Deprecations: 94, Skipped: 5`. All four failures are in the known baseline:

- `ExampleTest::test_the_application_returns_a_successful_response`
- `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
- `AstraAddressRulesTest::test_S_26_18_…`
- `AstraAddressRulesTest::test_S_26_23_…` (timing-sensitive under the full run)

`BigBroadcastTest S_167_1` passed in this run. No new failure.

## Red lines

Each red was watched before its slice's code, except the review-minors fix.

- **fix (lazy rows), S-217.1.1:** f5d6d450 with the run: `S-217.1.1 …: FAIL (server 694 ms, usable at 2422 ms …)` at 1×; usable at 7,144 ms at 2× and 14,606 ms at 4×.
- **fix (lazy rows), S-217.1.4:** `phone 390: S-217.1.4 …: FAIL (server 657 ms, usable at 2070 ms …)`.
- **feat (group values at once, running first):**
  - `test_S_217_2_2_and_2_5_…`: `Failed asserting that 422 is identical to 200.`
  - `test_S_217_2_3_…`: `Unable to find JSON fragment [{"error":"token is the page's own setting …"}] within [{"error":"Pick a group, a field and a text value."}]`.
  - `test_S_217_3_1_…`: `Failed asserting that two arrays are identical.` The groups came in id order.
- **feat (test from an ad):**
  - `test_S_217_4_2_…` and `test_S_217_4_4_…`: `Failed asserting that two strings are identical.`
  - `test_S_217_4_1_…`: `Failed asserting that 0 is identical to 1.` (no `ad-options` in the page).
- **feat (group Edit, Manage in Edit, Default):** the run's group checks on the code before the commit, for example:
  - `S-217.2.1 each group row in the list has an Edit button: FAIL`
  - `S-217.5.1 Manage in Edit is a normal-size primary button next to the count: FAIL`
  - `S-217.5.2 the Default row is "Default (page values)" …: FAIL`
  - `S-217.2.2 a pasted row goes to /chatbot/paste-map …: FAIL`
  - `S-217.2.5 Apply with "Paste blanks as empty" off …: FAIL`
- **fix (short frames while scrolling):** before it: `desktop 1600 cpu/2: S-217.1.1 …: FAIL (… while scrolling 239 ms …)`.
- **fix (review minors):** no red was watched. The two new assertions (a `values` list, and more than 300 values) were written with the fix.
- **Already true before this spec:** S-217.1.2 (sub-rows were lazy already), S-217.2.4 (the same access check), S-217.3.2 (see Story changes), S-217.3.3 and S-217.4.3. Their tests pin today's behaviour.

## Done-when checklist

- [x] Cases S-217.1.1 – S-217.5.2 pass, each with a test or a recorded measurement named after it. S-217.1.1 has before/after numbers above, on a "normal PC" of 2× (see Rulings). At 4×, S-217.1.1 and S-217.1.4 still fail; this is recorded, not hidden.
- [x] The result has a red line for every feat/fix slice, watched before the code. The one exception is the review-minors fix (said above).
- [x] The full suite adds no new failures (see Full suite).
- [x] `qa/stories.md` has the Slice 217 stories with "Last run" filled.
- [x] This result is filled in, with Deploy notes.

## Rulings

- **Ruling:** "a normal PC" is this machine's Chrome with the CPU slowed 2 times; 1× and 4× are recorded too.
  - Why: the spec names no machine. This dev PC is fast, and 4× is the phone setting of Chrome's tools.
  - Cost if wrong: on a PC slower than 2×, showing an Ad-based page's ad groups and Manage in Edit still take 250–400 ms, and fast scrolling can pass 200 ms a frame.
- **Ruling:** rows far from the view are light stand-ins, built when near and folded back when far.
  - Why: the measured cause was whole-table work on every frame, growing with the number of full rows. CSS alone was tried with `--css` and gave no real gain: fixed table layout, `content-visibility`, no sticky cells, no line clamp. Those trials are in `storage/perf217/`, not committed.
  - Cost if wrong: the browser's find-in-page sees only rows near the view (the Search filter still finds pages). After a very fast scroll, rows fill in a moment later.
- **Ruling:** the timing run is the test for the browser-only cases: S-217.1.x, 2.1, the client half of 2.2, 2.3 and 2.5, 3.1, 4.1–4.3 and 5.x. The case IDs are in its check names.
  - Why: the Blade page's script is too large for the small Node DOM, and the run is the real page in real Chrome with the real server.
  - Cost if wrong: these checks are not in `phpunit`. They are run by hand: `node tests/perf/pages217.mjs --groups`, about 1 min.
- **Ruling:** the run answers `/chatbot/paste-map` with a fixed mapping (`page.fakeMap`).
  - Why: no real AI calls in tests. The request itself (page name and cells) is checked against the page's.
  - Cost if wrong: the AI's own choice of fields is not exercised here. That code is unchanged and has its own tests (`ChatbotPasteMapTest`).
- **Ruling:** a group Edit Save is one request to the existing `/chatbot/ad-groups/value`, with a `values` map, not one request per field.
  - Why: that endpoint's throttle (30 a minute) would refuse a pasted row of 40 fields. One request is also all or nothing, like the page's Save, and the same `change()` gives the lock, validation, history and Restore.
  - Cost if wrong: none seen. The single-value form is unchanged.
- **Ruling:** the group Edit shows the page Edit's fields only. FLOW and the group's AI stay on the group row's own controls.
  - Why: the spec says "the same fields the page Edit shows", and the page Edit has neither.
  - Cost if wrong: FLOW and AI are set through the existing controls, which are unchanged.
- **Ruling:** in the group Edit, History opens the group's history: every value, with Restore.
  - Why: that is the group's existing history (`/chatbot/ad-groups/history`).
  - Cost if wrong: the list is not narrowed to the one field.
- **Ruling:** inside a group, IDs sort by not quiet, On, New, customers, last. The quiet ones stay last, since 212 folds them.
  - Why: S-217.3.1.
  - Cost if wrong: five older assertions pinned the stored order (see Story changes).
- **Ruling:** the Ad dropdown lists each group's IDs and the first 50 ads not in a group. An ad set or campaign ID is one entry; 50 is the server's existing limit.
  - Why: the dropdown uses the same `/chatbot/ad-groups` data as Manage in Edit, with no new query.
  - Cost if wrong: on the live page, about 173 of the 223 ads not in a group cannot be picked (see Proposed tasks).
- **Ruling:** when both the Ad and the Ad group are set, the Ad wins, even when the ad is in no group (Default). The line then adds "(the Ad is used, not the Ad group picked)".
  - Why: S-217.4.4.
  - Cost if wrong: none.
- **Ruling:** in the run, the Tailwind CDN script is answered with a local stylesheet, as in spec 209.
  - Why: no downloads.
  - Cost if wrong: the live CDN's own work on added nodes is not measured. That work grows with the elements added, which this spec cuts from 33,600 to about 5,000 at load.

## Story changes

- **S-217.3.2.** "Ads not in a group yet" keeps its existing order: not quiet, New, On, customers. That already puts On and New first and then customers, which is what the case asks, and S-174.6 pins it. Inside a group the order is On then New (S-217.3.1). The test asserts the kept order.
- **Re-pinned assertions.** The stored order became the running-first order of S-217.3.1. Each changed assertion is marked "S-217.3.1".
  - `AdBasedPagesTest` S-174.6: now `['602', '601', '999']`.
  - `AdBasedPagesTest` S-174.7: the IDs are read sorted by ID.
  - `AdGroupPagesTest` S-212.4.1: read sorted by ID.
  - `AdGroupPagesTest` S-212.7.2: the quiet ID is last.
  - `AdGroupPagesTest` S-212.4.5 / 4.6: the tags read `Campaign, Ad set`.
- **S-217.1.1.** "A normal PC" is made measurable as Chrome with the CPU slowed 2× (see Rulings).

## Deferred minors

Accepted with reasons in `TODO.md`, under "Pages speed and ad group edit (spec 217), accepted review minors":

- a pasted value equal to the page value becomes the group's own value;
- the Ad dropdown's 50-ad limit;
- `adStatus` repeats `statusText`;
- "Add to group" defaults to the top running group;
- no test of the skip continuation with an ad;
- find-in-page sees only rows near the view.

Fixed from the review:

- `fold()` checks `entry.nodes` before using it.
- The Test page clears the old page's Ad and Ad group when the page changes.
- "(null days)" no longer shows.
- The values cap is 300, with its own error.
- The group History link is labelled "History of <group> (every value)".
- `rowStates()` is built once per batch.
- New tests: a `values` list is refused, more than 300 values are refused, and a group Save leaves the page values and another group untouched.

## Deploy notes

- No migration, no new table, no queued job class touched.
- No new route. The `/chatbot/ad-groups/value` route takes a new body form.
- After deploy: `php artisan view:clear`, and `php artisan route:clear` if routes are cached.
- `public/js/chatbot-adgroups.js` changed. Its URL carries a hash of the file (`?v=`), so browsers fetch the new one.
- Likha and Incepxion run the same tree.

## Merge danger

- **Door:** `/chatbot/pages` (the grid every staff member uses), the group value endpoint, and /chatbot/test.
- **Blast radius:** a bug in the stand-in rows could leave rows unbuilt, or the table `aria-busy`, on the pages screen. The data and the writes go through the same endpoints. The live bot's replies and its matching of customers to groups are unchanged; only the screen order changed.
- **Revert:** revert the branch's commits. There is no migration and no data change. Group values saved through the new form are ordinary group rows, with history.

## Open questions

- **Group Edit: should a pasted value equal to the page value stay empty ("uses default")?**
  - Today: the group Edit saves what it shows, as the page Edit does. Pasting a full Sheets row makes every field the group's own value, even where it equals the page value.
  - Effect: a later change of the page value (for example the price) then no longer reaches that group's customers.
  - Leaving equal values empty would be a new rule, so I did not add it. It is the owner's call.

## Proposed tasks

- **List every ad of an Ad-based page in the Test page's Ad dropdown.** Today it stops at the 50-ad limit of "Ads not in a group yet". Add a search like the one in Manage in Edit.
- **Cut the cost of one full grid row** (about 20 ms of script at 1×): event delegation instead of 3 listeners per cell, and fewer elements per cell. This would bring showing ad groups and Manage in Edit under 200 ms at 4×.
- **Measure the live Tailwind CDN's own work on /chatbot/pages** in a staff browser (DevTools Performance). The run cannot load it.

217: done — /chatbot/pages builds only the rows near the view (usable at 1.4 s instead of 7.1 s at 2×, 4.8k elements instead of 33.6k); every ad group has the page's Edit; running ads come first; /chatbot/test can run as a customer from an ad
