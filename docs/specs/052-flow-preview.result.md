# Result 052: flow preview on /page-settings

Branch `feat/052-flow-preview` from `develop` at 835d89ea. Not pushed. No migration, no new table or column, and nothing in the chatblast pages, services or migrations was touched (`ChatblastFlowInfo` is unchanged).

## Commits

| Commit | What |
|---|---|
| 71abe906 | docs: the spec, verbatim |
| 6739b1f2 | docs: plan and stories |
| 195af472 | feat: name and text keys are system keys drawn inside their cells; the Shipping template column |
| 01b81e82 | feat: refresh from Botcake after a save and after an ok Test token (row and Test lahat) |
| ebe561bc | feat: the grid's second lines, updated on save, Test lahat and poll |
| fbecfaa5 | fix: review loop 1 (a slow read never names a changed ID, a cleared ID clears its name, blank template names, repaint of the template line) |
| 64644e3b | fix: final minors wave of review 1 (paging end check, since removed by 7d10025a) |
| f1e53157 | docs: result, stories and TODO after review 1 |
| 7d10025a | fix: review 2 and the live fact (one flows call with no paging, auto-pick after a shipping flow save, stale template save guard, shared rate key for the token check, late save responses ignored, Test lahat within 25 s) |
| (this commit) | docs: result, stories and TODO after review 2 |

## Keys added (rows in `page_settings`)

| Key | Who writes | In the grid |
|---|---|---|
| `botcake.template.shipping` | the owner (editable); the system when it is empty (shown as by "system") | its own column "Shipping template", after the four flow columns |
| `botcake.flow.<x>.name` (one per flow key, e.g. `botcake.flow.shipping.name`) | system only (`set(..., system: true)`) | second line under the flow ID cell; not a column |
| `botcake.template.shipping.text` | system only | the body line under the template cell and under the shipping flow; not a column |

`PageSettings::SYSTEM_KEYS` now lists `botcake.token_status` and `botcake.template.shipping.text`, and `PageSettings::isSystemKey()` adds the pattern `botcake.flow.<x>.name` (`DETAIL_PATTERN`). Stored values: the name or body, or a marker: `!wala` (not in the list), `!bura` (is_removed), `!hindi approved` (template name not APPROVED on the page), `!hindi nasuri: <reason>` (our own short reason, never a response body). An equal value is "unchanged" (no new row).

## When the refresh happens (never in Sync)

- **After a cell save** (`/page-settings/cell`), for that page only:
  - a `botcake.flow.<x>` save: **one** flows call (`GET /pages/{id}/flows`, no `page` parameter: the API ignores it and `data.flows` is the full flat list). Every flow key of the page gets its name; an ID missing from the list is "Wala sa Botcake". A failed or odd answer marks nothing missing: only the saved key gets "hindi nasuri: <reason>", so the old ID's name never stays. The save itself always succeeds.
  - a `botcake.flow.shipping` save while `botcake.template.shipping` is empty: also one templates call, which runs the auto-pick and stores the body, in the same 20 s budget.
  - a `botcake.template.shipping` save or clear: one templates call; when empty, the system picks (rule 1, then rule 2, else nothing), then the body is stored. If another save changed the template name while Botcake was answering, nothing is written (the newer save writes).
  - clearing a flow ID clears its name row (no call).
- **Test token (row button) and Test lahat (`/page-settings/test-token/one`)**, only when the token is ok: one flows call, then one templates call (skipped when the page has neither a shipping flow nor a template). A failure here keeps the old lines and comes back as `refresh_error`. Test lahat returns the written cells in `cells`; the row button's flash message says whether it refreshed.
- **Rate and time:** the token check, the flows call and the templates call all go through the page's shared rate key (`shared(sha1(token))`), so they stay 2 per second. No retry on the refresh calls (`once()`), at most 8 s each. Test lahat stays within 25 s of the request start, counting the token check (up to 20 s) and the 0.5 s rate waits; a call with under 2 s left is skipped as "kulang sa oras". After a save: 20 s.

## What the grid shows

| State | Under the flow ID cell | Under the shipping flow (extra line) | Under Shipping template |
|---|---|---|---|
| name known | the flow name (grey-black) | `Template: <name> — <body with {{1}} as (mensahe ng sequence)>`, full body on hover | the body, full on hover |
| ID not in the page's flows | red "Wala sa Botcake" | | |
| flow removed | red "Bura na sa Botcake" | | |
| never checked | grey "hindi pa nasusuri" | grey "Template: … hindi pa nasusuri" | grey "hindi pa nasusuri" |
| Botcake failed after a save | grey "hindi nasuri: <reason>" | grey "Template: <name> — hindi nasuri: <reason>" | grey "hindi nasuri: <reason>" |
| template empty, nothing to pick | | grey "Template: piliin ang template" | grey "piliin ang template" |
| template not an APPROVED template of the page | | red "Template: <name> — Hindi APPROVED na template ng page" | red "Hindi APPROVED na template ng page" |
| empty flow ID cell | nothing | nothing | |

No button: the lines are part of the cells, and they update from the save JSON, the Test lahat JSON and the 5-second poll (the name and text keys never become columns there either). All text is set with `textContent`. Each cell keeps a save sequence number: the response of an older save that arrives after a newer one is ignored.

## Case table

| Case | Test | Result |
|---|---|---|
| S-052.1 | `test_S_052_1_saving_a_flow_id_fills_its_name_and_a_botcake_failure_never_blocks_the_save` (7 data sets), `test_S_052_1_saving_one_flow_names_every_flow_of_the_page_and_a_failure_marks_only_the_saved_one`, `test_S_052_1_a_slow_read_does_not_name_a_flow_id_that_changed_meanwhile` | pass |
| S-052.2 | `test_S_052_2_the_flow_list_is_read_with_one_call_and_an_odd_answer_marks_nothing_missing` | pass |
| S-052.3 | `test_S_052_3_test_lahat_refreshes_names_and_template_only_when_the_token_is_ok_and_returns_the_cells`, `test_S_052_3_test_lahat_skips_the_refresh_when_its_time_budget_is_spent`, `test_S_052_3_the_row_test_token_button_refreshes_only_when_the_token_is_ok` | pass |
| S-052.4 | `test_S_052_4_an_empty_template_key_is_filled_by_the_flow_name_then_the_single_custom_field_template` (6 data sets), `test_S_052_4_saving_the_shipping_flow_with_an_empty_template_picks_the_template_at_once` | pass |
| S-052.5 | `test_S_052_5_a_template_typed_by_the_owner_is_kept_and_an_unknown_or_pending_name_shows_red`, `test_S_052_5_a_failed_template_save_does_not_overwrite_a_newer_template` | pass |
| S-052.6 | `test_S_052_6_the_name_and_text_keys_are_refused_from_the_page_and_the_template_key_is_editable` | pass |
| S-052.7 | `test_S_052_7_name_and_text_rows_sit_in_their_cells_and_are_not_columns`, `test_S_052_7_a_refresh_with_the_same_answers_writes_no_new_row` | pass |
| S-052.8 | `test_S_052_8_the_token_is_never_returned_logged_or_stored_and_only_names_and_text_are_kept` | pass |
| S-052.9 | `test_S_052_9_other_roles_still_get_404_and_no_botcake_call` | pass |
| S-052.10 | owner check by hand (no JS test harness in the project) | not run |

Red runs: task 1, both S-052.6/7 tests failed on the missing behaviour ("Failed asserting that 200 is identical to 422", "array does not contain 'botcake.flow.shipping.name'"). Tasks 2 and 3, every new test failed before the service existed (e.g. "Trying to access array offset on null" on the missing `cells`, "Failed asserting that null is identical to 'post_order'"). Review 1: the stale-ID test, the blank-name data set and the clear check each failed first. Review 2: run against the code of f1e53157, the changed and new tests failed (S-052.1 "two arrays are identical" on one flows call, S-052.2 "2 is identical to 1", S-052.3 "null is identical to 'kulang sa oras'", the auto-pick after a flow save, and the stale template save "two strings are identical"); with only the old controller, the rate-key assertion failed (token check and flows call both at 1000.0).

`tests/Feature/PageSettings`: 104 tests, 869 assertions, all pass. One existing test changed: `PageSettingsGridTest::KNOWN` now includes the new Shipping template column.

Full suite (`phpunit`, PHP 8.4, sqlite in memory), last line: `Tests: 1549, Assertions: 17974, Errors: 1, Failures: 1, PHPUnit Deprecations: 13, Skipped: 3.` The error is `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` and the failure `ExampleTest::test_the_application_returns_a_successful_response`, the two known ones. Twice a full run stopped partway with "Maximum execution time of 55 seconds exceeded": the existing chatblast controllers call `set_time_limit(55)` inside their tests, and on Windows that limit is wall-clock time for the rest of the PHPUnit process. Not caused by this work; the rerun passed both times. Suggestion for the owner: guard those calls in tests.

## Review

**Review 1:** `skeptic-reviewer`, standard depth, on the whole diff; one fix loop.

- **major (fixed):** a slow refresh could write the old ID's name after a newer save changed the flow ID (two autosaves, or Test lahat during a save). The ID is now re-read right before each write; test `test_S_052_1_a_slow_read_does_not_name_a_flow_id_that_changed_meanwhile`.
- **minors fixed:** blank or space-padded template names from Botcake (they made `set()` throw a 500 after the save); a cleared flow ID clears its name (it could feed the template pick); the shipping flow's template line repaints when the template changes on save or poll. The paging fixes from that round were replaced in review 2.
- **minors accepted** (reasons in `TODO.md`, section 052): names that begin with a marker; the key patterns in both PHP and JS; `store()` without a refresh; no lock on the re-checks; no JS test.

**Review 2** (independent review of 835d89ea..f1e53157, plus a live read-only check of the flows API), all fixed in 7d10025a:

1. Live fact: `GET /flows` ignores `page` and returns the full list. Now one call with no `page` parameter, no paging, `MAX_FLOW_PAGES` and the loop removed. A failed or odd answer still marks nothing missing. A flow save makes one call.
2. Auto-pick after a shipping flow save while the template is empty: one templates call in the same budget. Test `test_S_052_4_saving_the_shipping_flow_with_an_empty_template_picks_the_template_at_once`.
3. A failed template save no longer writes "hindi nasuri" when a newer save changed the template name meanwhile (the name is read before the call and compared after it, as `refreshFlows` does for IDs). Test `test_S_052_5_a_failed_template_save_does_not_overwrite_a_newer_template`.
4. The token check (row button and Test lahat) uses the page's shared rate key, like the flows and templates calls. Asserted in the S-052.3 test by request times 1000.0, 1000.5 and 1001.0.
5. The grid ignores a save response older than the cell's latest save (per-cell sequence number). No JS test (no harness); checked by `node --check` and to be seen in S-052.10.
6. `TEST_BUDGET` is 25 s from the request start, and each call's time now subtracts the 0.5 s rate wait, so Test lahat never passes about 25 s. The docblock no longer says "~20 segundo". The time-budget test now uses a 24 s token check.

Accepted and documented in `TODO.md`: the auto-picked value in `botcake.template.shipping` is read by `ChatblastFlowInfo::pickTemplate` as the owner's choice (same rules, and that file belongs to the other branch); deactivating a `.name` or `.text` row only resets its line. Also noted there: the row Test token button still retries its token check, so on a slow Botcake its refresh is skipped. Side effect of item 4: the row button's token check now waits at most 10 s on a Retry-After header (the `shared()` default) instead of 60 s.

## Story changes

- S-052.1: one flows call (no page parameter); plus the cases for the other flow keys, a changed ID during the read, and clearing a flow ID.
- S-052.2: rewritten from paging to "one call, no page parameter; an odd answer marks nothing missing" (live fact).
- S-052.3: added the shared rate key and the 25 s limit with "kulang sa oras".
- S-052.4: added the blank name and the pick right after a shipping flow save.
- S-052.5: added "the system picks again after a clear" and the stale template save.
- S-052.8: the token's last 4 is shown in the grid on purpose (existing behaviour), so the check is the full token in responses and the last 4 in logs and stored values.

## What the brief got wrong or left open

- "Read every page of the list" (the API may be paginated) was wrong: the live check shows `GET /flows` ignores `page` and returns the full list, so one call is right and matches "one flows call".
- The row **Test token** button is a form post with a redirect, not JSON; the reload shows the new lines and the flash message says whether the refresh worked. Only Test lahat returns `cells`.
- The brief doesn't say what to do when the page has no shipping flow and no template: the templates call is skipped then (nothing to show, and it saves a call per page in Test lahat).
- The brief's refresh after a flow save named only the flows call; the auto-pick after a shipping flow save (review 2) adds one templates call when the template is empty.
- Not in the brief but done: clearing a flow ID also clears its stored name.
- Process: the kit wants a "go" on the plan before code; this one-shot brief gave none to wait for, so the committed plan is the record. The worktree had no `vendor`; it was installed with `composer install` from the unchanged lock (a link to the main checkout's `vendor` would have run the main checkout's code).

## Not done / for the owner

- S-052.10 by hand on the running page (no browser check was run in this session), including a fast second edit of a flow ID while the first save is still waiting.
- Deploy notes: no migration; the new rows appear on the first save or Test lahat per page.
