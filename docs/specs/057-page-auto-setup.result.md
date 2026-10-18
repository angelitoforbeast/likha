# Result 057: a new page sets itself up in Page settings

Spec: `docs/specs/057-page-auto-setup.md`. Plan: `docs/plans/057-page-auto-setup.md`.

- **Branch:** `feat/057-page-auto-setup`, cut from `feat/056-chatblast-app` at `5e916162`. Not pushed.
- **No migration.** New data lives in `page_settings` system keys, or is derived from `botcake_messages`.
- **No Botcake calls in tests:** fake HTTP only.

## Commits

| Commit | What |
|---|---|
| `e0685e21` | docs: the spec, saved verbatim |
| `caef764d` | docs: plan and stories (S-057.1 to .10) |
| `e6f1f58c` | feat: saving the Botcake ID or token tests the token at once and detects the flow and field; Test and Sync answer JSON for fetch |
| `f4d134b1` | feat: the Ready rule (Send test or a real send since the last setup change), the gate in To send, auto and the sender, and the Send test endpoint |
| `567359b3` | feat: Page settings view: dropdown, guides, warnings, checklist, Test send line and button, "Needs test" chip, fetch for row actions |
| `1c33bab8` | fix (review): a send in flight while the setup changes never counts; the four-key setup on the page; a new Botcake ID clears old candidates |
| `4aaeafff` | fix (review): the sender's Ready check matches pages by normalised name, like the build |
| (this commit) | docs: result, stories, TODO |

## How auto-detect works

1. **When it runs.**
   - On a save, through `PageSettingController::saveCell`: when `botcake.page_id` or `botcake.token` changes (not on an unchanged save) and the page has both, `BotcakePageSetup::check($page)` runs inside the same request. That is the brief's "in the save response" option, with the 25 s budget the Test button already had.
   - On the row Test, on "Test all tokens", and on the old form route.
2. **The token test.**
   - One `custom_fields` call (`BotcakeClient::tokenAndFields`): shared per-page rate key, `once()` (no retry), 20 s timeout.
   - Its body also lists the custom fields, so the field needs no extra call.
   - The token status is recorded as before.
   - Any Botcake error comes back as `refresh_error`. The value itself is already saved.
3. **When the token is ok:**
   - **Field:** candidates are fields whose name, in lower case without spaces and hyphens, is `gptinput` or `message`. They are stored in `botcake.field.message.options` as JSON `[{id, name}]`. When `botcake.field.message` is empty and there is exactly one candidate, it is written (digits only, logged as the system).
   - **Flow:** `BotcakeFlowNames::afterTokenOk` reads the flows list (one call; it is now read even when the page has no flow ID yet). Candidates are non-removed flows whose name contains "CHATBLAST" (any case). They are stored in `botcake.flow.shipping.options`. When `botcake.flow.shipping` is empty, it takes the only "(post_order)" candidate, else the only candidate, else nothing.
   - **Flow names, then template:** the flow names are written, then spec 052's template auto-pick runs (one templates call). A newly picked "(post_order)" flow therefore picks its template.
4. **Never overwrite.**
   - Only empty keys are filled, and unchanged option lists write no new row.
   - A saved value that is not a candidate is kept, and the page warns: "This flow is not a CHATBLAST flow in Botcake" / "This field is not a 'GPT Input' field in Botcake".
   - A changed Botcake ID clears the old lists, so the dropdown never offers another Botcake page's flows.
5. **The page.**
   - **Dropdown:** with more than one candidate, or a saved value outside the list, a select "name · id" sits under the ID; choosing one saves like typing, and typing still works.
   - **Guides:** when the check found nothing, red guide lines show:
     - "No CHATBLAST flow in Botcake. In Botcake, copy 'CHATBLAST - SHIPPING STATUS (post_order)' from another page to this page, then press Test."
     - "No 'GPT Input' custom field in Botcake. Create it, then press Test."
     - "No approved post_order template in Botcake." (shown when a flow is set, Botcake was checked, and no template could be picked).
   - **Checklist:** "How to set up a new page" opens a 5-step modal (not sticky).
   - **Empty values** show "+ set", as in spec 056.

## The Ready rule, and what changes for existing pages

**Rule** (`App\Services\ChatblastReady`):

- **Ready:** a page with its four setup keys (`page_id`, `token`, `flow.shipping`, `field.message`) is Ready when its newest confirmed send is at or after the newest of those four rows (`created_at`).
- **A confirmed send** is either:
  - `botcake.test_ok`, a system key the sender writes when a test row ends `sent` (field written, read back, and send_flow all 2xx with `success: true`), but only when the four keys still equal the ones the test used; or
  - a real chatblast row with status `sent`, counted from its `queued_at`, the send's start. Nothing extra is stored for it.
- **Reset:** a change to any of the four keys makes every earlier confirmation older than the change. A send that started before the change never counts for the new setup.
- **Untested pages** get `no_config` "Page not ready: needs a test send" (through `Chatblast::missingByPage`).
  - **Option chosen: History, not greyed in To send.** These rows live under **No config** in History rather than greyed in To send, so To send lists only what will really be sent.
  - **When the page is tested,** the next fetch (the hourly refresh, auto's own fetch, or Refresh now) turns them ready again.
  - **Moving existing rows:** each fetch's recheck also moves ready rows of untested pages there.
  - **Final guard:** the sender refuses non-test rows of an untested page with the same reason.
- **Send test** (row button; POST `/chatblast/page-settings/send-test`, page-settings access, throttled 20 a minute):
  - It sends the page's latest message text to the Test PSID, through the normal sender. A page with no message yet gets the sample "Test message from Chatblast: if you see this, the page is set up."
  - Without a Test PSID: "Set a Test PSID first", which links to the picker on To send.
  - With an incomplete setup: 422.
- **Page settings:**
  - **Test send line** in the Botcake cell: "Tested <time>" or amber "Needs a test send".
  - **Send test** in the row's actions.
  - **"Needs test N" chip.** The amber border and legend read "Setup incomplete or needs a test send".
  - **Green Ready** needs a test.

**Existing live pages on Likha:** I have no live data, so this is the rule, not a count. A page becomes **"Needs test"** at deploy when all of these hold:

- it has the four setup keys;
- it has no `botcake.test_ok` (none exist before this deploy);
- it has **no `sent` chatblast row queued at or after the newest of its four setup rows**.

So:

- **Stay Ready:** pages that have sent at least one chatblast message since their ID, token, flow and field were last saved.
- **Turn "Needs test":** pages whose setup was saved or changed after their last chatblast send (for example a token re-saved today), and complete pages that never sent.
  - Their ready rows move to History → No config at the first fetch after deploy.
  - Nothing is sent for them until someone presses Send test (or sets a Test PSID first).
- **The 26 incomplete pages** stay "Setup incomplete" until filled. The auto-detect fills them when Botcake has a clear flow and field. The 19 with no CHATBLAST flow and the 4 with no "GPT Input" field get the guides.

To count the "Needs test" pages before deploying, a read-only check on live (MySQL) can compare, per page key, the newest `created_at` of the active `botcake` rows for `page_id`, `token`, `flow.shipping` and `field.message` in `page_settings` with `MAX(queued_at)` of `botcake_messages` where `status = 'sent'` and `is_test = 0`. Pages where the first is newer, or the second is missing, will need a test. It is worth doing, because these pages stop sending until tested.

## No jump to the top

- **Row actions:** Test, Sync and Send test are buttons that use `fetch` (Sync answers JSON when the request expects JSON).
- **Saves and dropdown choices** use the existing fetch save. Cust. and Orders stay links to other pages.
- **No `postForm`** (a full-page form post) is left in the view.
- **In the browser,** with the ▸ row open and the window scrolled down, Test kept `scrollY` 506. After Sync it read 538: the page grew by a status line while the window was at the very bottom, so the browser kept its place. Send test kept 538.
- **No page navigation** happened in either browser run.

## Case table

Red runs, before the code:

- **S-057.1 and S-057.2:** nothing was filled; the call list was empty.
- **S-057.3:** the warnings were missing (the view came in task 3).
- **S-057.4, .8 and .9:** the strings were missing.
- **S-057.5, .6 and .7:** `ChatblastReady` was not found, and the route answered 404.
- **Review loop 1:** the in-flight test send wrote `test_ok` ("'2026-10-18 10:01:02' is null"), and the options were not cleared.
- **Review loop 2:** `tested('PAGE  DOS')` was false.

| Case | Test | Result |
|---|---|---|
| S-057.1 | `PageAutoSetupTest::test_S_057_1_saving_the_id_or_token_tests_the_token_at_once_and_reads_the_flows_fields_and_template` | pass |
| S-057.2 | `PageAutoSetupTest::test_S_057_2_the_flow_is_filled_only_when_one_candidate_is_clear…` (5 data sets) and `…the_field_is_filled_only_when_exactly_one…` (4) | pass |
| S-057.3 | `PageAutoSetupTest::test_S_057_3_a_value_a_person_saved_is_never_overwritten…` | pass |
| S-057.4 | `PageAutoSetupTest::test_S_057_4_the_page_has_the_dropdown_the_guides_and_the_setup_checklist` and the browser check | pass |
| S-057.5 | `ChatblastReadyTest::test_S_057_5_an_untested_page_is_not_in_to_send_until_a_test_send_or_a_real_send` | pass |
| S-057.6 | `ChatblastReadyTest::test_S_057_6_changing_the_flow_field_token_or_page_id_needs_a_test_again…` and `…a_send_in_flight_while_the_setup_changes_never_counts…` | pass |
| S-057.7 | `ChatblastReadyTest::test_S_057_7_send_test_sends_the_pages_latest_text…`, `…a_test_that_does_not_end_sent_never_marks_the_page_tested` (3 data sets), `…send_test_needs_page_settings_access` | pass |
| S-057.8 | `PageAutoSetupTest::test_S_057_8_an_untested_page_shows_needs_a_test_send…` and the browser check | pass |
| S-057.9 | `PageAutoSetupTest::test_S_057_9_row_actions_use_fetch_and_sync_answers_json` | pass |
| S-057.10 | Browser check below | pass |

**Changed tests:**

- `ChatblastTestCase::page()` writes `test_ok` by default (`$tested = true`), so existing tests keep Ready pages.
- `BulkTokenTestTest`:
  - an ok test now reads the flows too: 2 calls, still no retry;
  - its no-retry check now ignores the 0.5 s rate gap.
- `PageSettingsGridTest`:
  - the known columns include `botcake.test_ok`;
  - the row actions are buttons;
  - the border and legend include "needs a test send";
  - the flat query bound goes from 9 to 10 (one more query per grid, for the last real send per page; still constant).

**Suite:**

- `tests/Feature/Chatblast` and `tests/Feature/PageSettings`: `Tests: 319, Assertions: 2332`, all passing.
- The full suite on `4aaeafff`: `Tests: 2 failed, 1521 warnings, 158 passed (18905 assertions)`, 336 s. The two failures are the known `ExampleTest` and `NightRun\ImportStartTest`.

## Browser check (headless Chrome, scratch app, fake Botcake)

- **Setup:** a scratch app on `127.0.0.1:8056` against a scratch sqlite file outside the repo, with a router outside the repo that installs `Http::fake` for Botcake. **No Botcake call was made.**
- **The fake answered:**
  - custom fields "GPT Input" 801, "GPT-INPUT" 802 and "Phone";
  - flows "CHATBLAST - SHIPPING STATUS" 501, "… (post_order)" 502 and "Welcome";
  - an approved post_order template with `{{1}}`;
  - the customer field write and read-back, and send_flow.
- **Evidence:** in the main checkout's `handoff/057-page-auto-setup/screens/` (`auto-filled.png`, `ready.png`, `checklist.png`, `evidence.txt`, `evidence-scroll.txt`).

**The steps:**

1. "+ Page" "New Page Demo": an empty row with "+ set" everywhere.
2. **ID 2002, then the token:**
   - the notice read "Token: ok · flow, field and template checked";
   - the flow was filled with **502**, the "(post_order)" one, with a dropdown listing both CHATBLAST flows;
   - the field stayed empty, with a dropdown "GPT Input · 801 | GPT-INPUT · 802" (two candidates, so not auto-filled).
3. **Picked 801 in the dropdown:** it saved. The Test send line showed amber "Needs a test send", the chip read "Needs test 1", and the actions showed "Set a Test PSID first".
4. **Typed a Test PSID:** the actions showed **Send test**.
5. **Send test:** "test message sent to the Test PSID", then the line read "Tested 2026-10-18 19:56" and the chip "Needs test 0".
6. **Row Test, Row Sync, and the checklist:** worked with no navigation. The checklist modal had 5 steps and is not sticky.
7. **Width:** no horizontal scroll at 1366 and 1280 (1366 ≤ 1366, 1280 ≤ 1280). No JS errors.

## Review

`skeptic-reviewer` at adversarial depth on `5e916162..567359b3`, then two fix loops.

**Loop 1** (`1c33bab8`) closed one major and three missing tests:

- **A send in flight while the setup changed counted as a test of the new setup.**
  - `test_ok` is now written only when the setup still equals what the test used.
  - Real sends count from `queued_at`.
- **Tests added:**
  - a failed, field_mismatch or unknown test writes no `test_ok`;
  - a real send older than the newest change does not count;
  - Send test needs Page settings access.
- **Minors fixed:**
  - the page's "complete" now uses the server's four keys, so Needs test and Send test match the gate;
  - a new Botcake ID clears the old candidates.

**Loop 2** (`4aaeafff`) closed one major that loop 1 introduced:

- **The exact page name broke for other spellings.** The sender's Ready check matched the exact page name, while the build uses the normalised key, so rows of a differently spelled PAGE would cycle between ready and refused. It now matches by key.
- The re-check closed it.

**Accepted, with reasons in `TODO.md`:**

- the per-send scan of sent rows;
- the inner double space in a stored page name;
- the microsecond race between the empty check and the write;
- the four keys written in three places;
- the Test send line naming the test time when a real send made the page tested;
- the poll not refreshing `last_send`;
- the literal-only view tests;
- no test for a flows failure on the save path;
- the Test PSID link needing /chatblast access;
- clearing candidates when Botcake is down;
- the save taking up to ~25 s.

## Story changes

- None to the brief's cases.
- S-057.6 and S-057.7 gained the in-flight, failed-test and access checks from the review.
- S-057.1 gained the clearing of candidates on a new Botcake ID.

## What the brief got wrong, or left open

1. **"Pages that already have successful real sends count as tested"** holds only for sends after their last setup change. The same brief resets "tested" when the flow, field, token or page id changes, so a page whose token was re-saved after its last send needs a test. I applied the reset to real sends too; without it, a re-saved token would never be checked.
2. **"A successful real chatblast send"** is counted from when it was queued, not when it finished, so a send in flight during a setup change does not count for the new setup.
3. **"Setup complete"** is two things today:
   - the grid's "Setup incomplete" includes the template (unchanged, as asked in spec 056);
   - sending needs only the four keys. "Needs test", Send test and the server gate use the four keys.
   - A page with the four keys but no template shows both "Setup incomplete" and "Needs test".
4. **"No approved post_order template":** the check cannot tell a "post_order" template apart. The guide shows when Botcake was checked, a flow is set, and spec 052's auto-pick found no approved template.
5. **Custom fields paging:** the token test reads `custom_fields?page=1`, which the brief describes as one list. If Botcake pages that list, a "GPT Input" field on a later page would not be detected; it can still be typed.
6. **"In the save response, or in a queued job":** I chose the save response. A queued job would leave the row waiting for the 5 s poll with nothing to show. The cost is a save that can take up to ~25 s.
7. **Send test text:** "a sample row's text" means a real customer's message goes to the staff's own Test PSID. That is as the brief says, and worth knowing.
8. **The Test PSID picker** lives on To send (/chatblast). A role with only Page settings access cannot open it, but can type the Test PSID in its cell.

## Independent review: the minors, fixed

The independent review of `5e916162..96395740` found no majors. Each minor below was fixed test-first; the new tests failed before the change.

| Minor | Fix | Test (red run) |
|---|---|---|
| 1. A Test PSID cleared between the check and the send crashed Send test (500) | The controller answers 422 "Set a Test PSID first" when the sender made no row | `test_S_057_7_a_test_psid_cleared_between_the_check_and_the_send_answers_422` (red: 500, "Attempt to read property status on null") |
| 2. The candidate lists were not in `SYSTEM_KEYS` | `botcake.flow.shipping.options` and `botcake.field.message.options` added. /cell already refused writing and clearing them through the detail-key pattern; the test now pins both | `test_S_057_3…` (red: not in `SYSTEM_KEYS`; the write and clear assertions passed before) |
| 3. "No approved post_order template" was guessed from the flow list | The templates check now records its result in `botcake.template.shipping.text`: `NO_TEMPLATE` when it ran and found none (red line "No approved post_order template in Botcake."), or "not checked: <reason>" when the call failed or ran out of time, shown as "Template check failed: <reason>". The red "template not approved" row state needs a template name, so "none found" does not count as a broken template | `test_S_057_4_no_approved_template_shows_only_when_the_template_check_ran_and_found_none` (2 data sets; red: no text cell) |
| 4. A save whose token test errored said only "Token: error" | The notice adds the reason (`refresh_error`, e.g. "Botcake: no answer") | `test_S_057_1_a_failed_save_check_names_the_reason_and_a_non_botcake_error_is_reported` (red: literal missing) |
| 5. A non-Botcake exception in the token test was swallowed | `report($e)` before the generic "Botcake: no answer" | the same test (red: not reported) |
| 6. The sender's Ready check scanned the sent rows on every send | `ChatblastReady::tested()` is cached 60 s per page; `PageSettings::set` (after its transaction) and `deactivate` clear it when a setup key or `test_ok` changes, so a setup change or a successful test counts at once | `test_S_057_6_the_ready_state_is_cached_per_page_and_cleared_when_the_setup_or_the_test_changes` (red: queries on the second call) |

**Added: "Mark as tested" (CEO only).**
- On a complete page that needs a test, the CEO sees "Mark as tested". After a confirm it posts to `/chatblast/page-settings/mark-tested`, which writes `botcake.test_ok` with the current time and the CEO's user id. Nothing is sent.
- Refusals:
  - other roles with Page settings access get 403, and the page has no URL or button for them;
  - roles without access get 404;
  - an incomplete page gets 422.
- Test: `test_S_057_11_the_ceo_can_mark_a_page_tested_and_no_one_else_can` (red: 404 on the missing route).

**Changed tests:**
- `FlowNamesTest` S-052.4: when nothing is picked, the template text is now the `NO_TEMPLATE` marker, not empty.
- `PageSettingsGridTest` S-054.8: the template-state literal now includes the template-name check.

**Story changes:**
- S-057.4: the template guide rule is reworded.
- S-057.6: the Ready change takes effect at once despite the cache.
- S-057.7: covers the Test PSID cleared mid-request.
- New S-057.11: Mark as tested.

**Suite:** `tests/Feature/Chatblast` and `tests/Feature/PageSettings`: `Tests: 325`, `2380 assertions`, all passing (the warnings are PHPUnit's doc-comment data provider notices, as before). The full suite was not re-run for this round.

**Accepted, in `TODO.md`:**
- the cache-clear gap if `set()` ever runs inside an outer transaction;
- the Test send line not saying a page was marked by hand;
- pages whose template check failed before this change show no template line until their next Test.

**Not re-checked in the browser:**
- the new template lines;
- the error notice;
- the Mark as tested button and its confirm.

The view tests find their strings only.
