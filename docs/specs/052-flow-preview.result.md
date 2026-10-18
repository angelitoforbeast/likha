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
| fbecfaa5 | fix: review loop 1 (a slow read never names a changed ID, a cleared ID clears its name, blank template names, data null past the last page, repaint of the template line) |
| 64644e3b | fix: only `data: null` past the last flows page ends the list (final minors wave) |
| (this commit) | docs: stories, TODO, result |

## Keys added (rows in `page_settings`)

| Key | Who writes | In the grid |
|---|---|---|
| `botcake.template.shipping` | the owner (editable); the system when it is empty (shown as by "system") | its own column "Shipping template", after the four flow columns |
| `botcake.flow.<x>.name` (one per flow key, e.g. `botcake.flow.shipping.name`) | system only (`set(..., system: true)`) | second line under the flow ID cell; not a column |
| `botcake.template.shipping.text` | system only | the body line under the template cell and under the shipping flow; not a column |

`PageSettings::SYSTEM_KEYS` now lists `botcake.token_status` and `botcake.template.shipping.text`, and `PageSettings::isSystemKey()` adds the pattern `botcake.flow.<x>.name` (`DETAIL_PATTERN`). Stored values: the name or body, or a marker: `!wala` (not in the list), `!bura` (is_removed), `!hindi approved` (template name not APPROVED on the page), `!hindi nasuri: <reason>` (our own short reason, never a response body). An equal value is "unchanged" (no new row).

## When the refresh happens (never in Sync)

- **After a cell save** (`/page-settings/cell`), for that page only:
  - a `botcake.flow.<x>` save: one flows read (`GET /pages/{id}/flows?page=N`, every page until an empty page, a page with no new IDs, `data: null`, 10 pages, or the time runs out). Every flow key of the page gets its name. On failure, only the saved key gets "hindi nasuri: <reason>", so the old ID's name never stays. The save itself always succeeds.
  - a `botcake.template.shipping` save or clear: one templates call; when empty, the system picks (rule 1, then rule 2, else nothing), then the body is stored.
  - clearing a flow ID clears its name row (no call).
- **Test token (row button) and Test lahat (`/page-settings/test-token/one`)**, only when the token is ok: the flows read, then one templates call (skipped when the page has neither a shipping flow nor a template). A failure here keeps the old lines and comes back as `refresh_error`. Test lahat returns the written cells in `cells`; the row button's flash message says whether it refreshed.
- **Time:** no retry (`once()`), at most 8 s per call, through the page's shared rate key. Test lahat stops all Botcake calls 30 s after the request starts (the token check has 20 s of that); a call with under 2 s left is skipped as "kulang sa oras". After a save: 20 s.

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

No button: the lines are part of the cells, and they update from the save JSON, the Test lahat JSON and the 5-second poll (the name and text keys never become columns there either). All text is set with `textContent`.

## Case table

| Case | Test | Result |
|---|---|---|
| S-052.1 | `test_S_052_1_saving_a_flow_id_fills_its_name_and_a_botcake_failure_never_blocks_the_save` (7 data sets), `test_S_052_1_saving_one_flow_names_every_flow_of_the_page_and_a_failure_marks_only_the_saved_one`, `test_S_052_1_a_slow_read_does_not_name_a_flow_id_that_changed_meanwhile` | pass |
| S-052.2 | `test_S_052_2_every_page_of_the_flow_list_is_read_until_the_end_with_a_cap` | pass |
| S-052.3 | `test_S_052_3_test_lahat_refreshes_names_and_template_only_when_the_token_is_ok_and_returns_the_cells`, `test_S_052_3_test_lahat_skips_the_refresh_when_its_time_budget_is_spent`, `test_S_052_3_the_row_test_token_button_refreshes_only_when_the_token_is_ok` | pass |
| S-052.4 | `test_S_052_4_an_empty_template_key_is_filled_by_the_flow_name_then_the_single_custom_field_template` (6 data sets) | pass |
| S-052.5 | `test_S_052_5_a_template_typed_by_the_owner_is_kept_and_an_unknown_or_pending_name_shows_red` | pass |
| S-052.6 | `test_S_052_6_the_name_and_text_keys_are_refused_from_the_page_and_the_template_key_is_editable` | pass |
| S-052.7 | `test_S_052_7_name_and_text_rows_sit_in_their_cells_and_are_not_columns`, `test_S_052_7_a_refresh_with_the_same_answers_writes_no_new_row` | pass |
| S-052.8 | `test_S_052_8_the_token_is_never_returned_logged_or_stored_and_only_names_and_text_are_kept` | pass |
| S-052.9 | `test_S_052_9_other_roles_still_get_404_and_no_botcake_call` | pass |
| S-052.10 | owner check by hand (no JS test harness in the project) | not run |

Red runs: task 1, both S-052.6/7 tests failed on the missing behaviour ("Failed asserting that 200 is identical to 422", "array does not contain 'botcake.flow.shipping.name'"). Tasks 2 and 3, every new test failed before the service existed (e.g. "Trying to access array offset on null" on the missing `cells`, "Failed asserting that null is identical to 'post_order'"). Fix loop: the stale-ID test, the time-budget block, the blank-name data set and the clear check each failed first ("'CHATBLAST - SHIPPING STATUS (post_order)' is null", "2 is identical to 3", "InvalidArgumentException: Kailangan ang value.").

`tests/Feature/PageSettings`: 102 tests, 865 assertions, all pass. One existing test changed: `PageSettingsGridTest::KNOWN` now includes the new Shipping template column.

Full suite (`phpunit`, PHP 8.4, sqlite in memory): 1547 tests; the only failures are the two known ones, `ExampleTest` and `ImportStartTest` (fresh worktree). Last line: `Tests: 1547, Assertions: 17970, Errors: 1, Failures: 1, PHPUnit Deprecations: 13, Skipped: 3.` (the error is `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, the failure `ExampleTest::test_the_application_returns_a_successful_response`). One earlier full run stopped at about 52% with "Maximum execution time of 55 seconds exceeded": the existing chatblast controllers call `set_time_limit(55)` inside their tests, and on Windows that limit is wall-clock time for the rest of the PHPUnit process. Not caused by this work; a rerun passed. Suggestion for the owner: guard those calls in tests.

## Review

`skeptic-reviewer`, standard depth, on the whole diff; one fix loop.

- **major (fixed):** a slow refresh could write the old ID's name after a newer save changed the flow ID (two autosaves, or Test lahat during a save). The ID is now re-read right before each write; test `test_S_052_1_a_slow_read_does_not_name_a_flow_id_that_changed_meanwhile`.
- **minors fixed:** blank or space-padded template names from Botcake (they made `set()` throw a 500 after the save); `data: null` past the last page is the end, not an error; a cleared flow ID clears its name (it could feed the template pick); the shipping flow's template line repaints when the template changes on save or poll; time-budget pagination test added.
- **minors accepted** (reasons in `TODO.md`, section 052): names that begin with a marker; the key patterns in both PHP and JS; `store()` without a refresh; the 0.5 s throttle overrun of the 30 s cap; no lock on the re-check; the flows read takes at least two calls; no JS test.
- Re-check of the fix: every item fixed, no new blocker or major. One new minor (page 2+ with no `data` at all was read as the end of the list, so unfound IDs could be marked "Wala sa Botcake") was fixed in 64644e3b with a test; that test was not watched red first.

## Story changes

- S-052.1: "one flows call" became "one flows read (every page of the list)", plus the cases for the other flow keys, a changed ID during the read, and clearing a flow ID. Reason: the spec asks for every page, which needs at least two calls (see below); the review found the stale-ID case.
- S-052.2: added `data: null` past the last page and the time budget. S-052.3: added "kulang sa oras". S-052.4: added the blank name. S-052.5: added "the system picks again after a clear". S-052.8: the token's last 4 is shown in the grid on purpose (existing behaviour), so the check is the full token in responses and the last 4 in logs and stored values.

## What the brief got wrong or left open

- "one flows call" and "Read every page of the list" conflict: with an unknown page size, the read has to fetch until an empty page or a page with no new IDs, so a one-page list takes two calls. Both fit the time budget.
- The row **Test token** button is a form post with a redirect, not JSON; the reload shows the new lines and the flash message says whether the refresh worked. Only Test lahat returns `cells`.
- The brief doesn't say what to do when the page has no shipping flow and no template: the templates call is skipped then (nothing to show, and it saves a call per page in Test lahat).
- Pagination beyond page 1 was not checked live; the code accepts an empty list, a repeated list or `data: null` as the end. If Botcake answers differently, the saved key would show "hindi nasuri".
- Not in the brief but done: clearing a flow ID also clears its stored name.
- Process: the kit wants a "go" on the plan before code; this one-shot brief gave none to wait for, so the committed plan is the record. The worktree had no `vendor`; it was installed with `composer install` from the unchanged lock (a link to the main checkout's `vendor` would have run the main checkout's code).

## Not done / for the owner

- S-052.10 by hand on the running page (no browser check was run in this session).
- Deploy notes: no migration; the new rows appear on the first save or Test lahat per page.
