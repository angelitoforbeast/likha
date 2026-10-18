# Result 054: /page-settings fits the screen, with no horizontal scroll

Branch `feat/054-page-settings-fit` from `main` at 98e1ea66. Not pushed. Plan: `docs/plans/054-page-settings-fit.md`. Amendment 054-1 (English text, a summary bar, a row state colour) is part of the brief and is covered below.

## Commits

| Commit | What |
|---|---|
| `a5d0074a` | docs: the spec (word for word), the plan and the stories |
| `5ca1f9ef` | feat: every grid column carries its cell group (`botcake`, `flows`, `other`) |
| `88c65b9e` | feat: the grid stacks Botcake, Flows and Sync into cells, with an expandable detail row |
| `862734e1` | fix: follow-ups from the first review (Botcake line order, history links, the `:focus` rule, reuse) |
| `743dc59c`, `3f461979` | docs: the first result, `TODO.md`, story runs |
| `686447e0` | feat (amendment 1): the page text in English, including server-made strings; old stored Tagalog lines shown in English |
| `a66ffe94` | feat (amendment 1): the summary bar with filter chips, and the row state colour |
| `5009d76e` | fix (amendment 1 review): old Tagalog run errors shown in English; the chips keep focus and follow Test all |
| (the docs commit after it) | docs: this result, `TODO.md` and the stories |

## Payload

The only payload change is one new field. Every column in `columns` gains `group`:

- `botcake`: page_id, token, token_status, field.message and test_psid;
- `flows`: the 4 flow IDs and the shipping template;
- `other`: every non-KNOWN key.

The view places cells by `group`. A column added with "+ Column" in the browser is `other`. There are no migrations, no new routes and no Botcake calls. The amendment changed the wording of strings the server already sent (see below), not their shape.

## Layout per cell

The table is `table-fixed w-full`, with a `colgroup` of Page 12 %, Botcake 22 %, Flows 29 %, Orders / Customers 7 %, Sync 20 % and actions 10 %. The Sync column grew for "Last sync: …" in the amendment. No value can widen the table. Long values wrap (`break-all` / `break-words`) or are clipped (`truncate`) with the full text in `title`.

- **Page** (sticky): a ▸ / ▾ toggle ("Show details" / "Hide details") and the page name. The name wraps. A 4 px left border shows the row state (see the amendment).
- **Botcake:** five labelled lines (a small grey label, then the value): ID, Token (masked as before, `••••` plus the last 4), Status (read-only, system, grey background), Message field and Test PSID.
- **Flows:** Shipping, Delivering, In transit and Broadcast. Each line has its ID and the 052 name line (the name, red "Not in Botcake" / "Deleted in Botcake", or grey "Not checked yet"), clipped to one line with the full name on hover. The last line is Template: its name and one clipped body line, with the full body on hover; "Pick a template" when empty.
- **Every value above is an editable `.cell`,** as each `td` was before: click (or Enter / F2) to edit; it saves on Enter, on blur or after a pause, and Escape cancels. The Saving / Saved / ! marks, the ⋯ history button on hover, secret handling and the late-response guard (`saveSeq`) are unchanged. So is the poll rule that sets aside the server value of the cell being edited.
- **Orders / Customers:** "40 / 0". The header's title gives the order window ("Orders (30 days) / Customers").
- **Sync:** line 1 is "Last sync: Oct 17 → Oct 18 · 01:04" (or "Last sync: none yet"), line 2 is "next: from Oct 17" (or the next label when there is no date). Both lines turn red when the last run failed. The full 053 line plus the full next label are on hover.
- **Actions:** the small buttons "Test" (title "Test token") and "Sync" (title "Sync now"), and the links "Cust." (title "Customers") and "Orders" (title "Orders without a PSID"). The forms and URLs are the same as before.

Unchanged: the poll, the name filter, the sort (most orders first), Test all tokens with its marks on the Status line, Sync all (pause, resume, stop, run panel, plan), + Page, + Column, and the grey rows for pages without orders.

## The expanded row

▸ opens a `tr.detail` under the page row. One cell spans the table, with:

- **Flows:** each ID with its full name, wrapped and in its 052 colour.
- **Shipping template:** the name and the full body (`whitespace-pre-wrap`).
- **Sync:** the dates, kind, time, stop reason or status; the counts (+new · updated · matched · ad id); the error; the full "Next sync" label; orders and customers. All of it is red when the last run failed.
- **Other keys:** every non-KNOWN key as a labelled, editable `.cell`, with the same edit behaviour.
- **History:** a link for every editable key of the page. Each link opens the existing history panel.

The open pages are held in a `Set` outside `render()`, so a poll, a structure re-render (+ Page, + Column, a new key from another user), the name filter or a chip keeps them open. When an open row's cells or info change, only its read-only part (`[data-detail-info]`) is rebuilt. An other key being edited inside it is never replaced.

## Amendment 054-1: what changed

### All /page-settings text is English

- **The page and its script:** title, headers, cell labels, buttons ("Test all tokens", "Sync all"), the help text, notices, errors, confirms, empty states ("No pages yet. Use + Page.", "No pages match the filter."), tooltips, the sync cell, the plan line ("Sync all plan: …"), the run panel ("Last 10 Sync all runs", Started / By / Skipped / New / Matched / Duration; running / paused / stopped / done), the history panel, + Page and + Column messages.
- **052 flow and template lines** (`BotcakeFlowNames::line()`): "Not in Botcake", "Deleted in Botcake", "Not an APPROVED template of the page", "Check failed: <reason>", "(sequence message)". New reasons are English ("Botcake: no answer", "Botcake: not a list", "Botcake: refused (success:false)", "out of time", "no botcake.page_id or token", "(no body)", "(no name)"). The stored markers (`!wala`, `!bura`, `!hindi approved`, `!hindi nasuri: `) are data and stay as they are.
- **053 sync labels** (`BotcakeSyncAll::next()` / `SUMMARY`, `BotcakeCustomerSync::kindLabel()` / `shortDate()`): "by date, from Oct 16", "resume from batch 15", "window (45 days, full)", "skipped (fresh, 2 h ago)", "no ID/token"; "by date, resumed from batch N"; English month names ("Oct 17").
- **Errors the page shows:** `PageSettings::validate` messages, `PageSettingController` JSON errors and flash messages, the two `last_error` texts the sync writes, the Sync all orphan error, and `BotcakeClient`'s exception text ("Botcake: token refused (success:false)", "Botcake: HTTP 500 after 3 retries", …). `BotcakeClient` is shared, so its text is English on the other pages too; the chatblast tests stay green.
- **Old stored text:** Tagalog written before the change is shown in English on read, without rewriting rows. Flow and template lines go through `BotcakeFlowNames::OLD_TEXT`, run errors through `PageSettingsGrid::OLD_ERRORS`, plus a pattern for "… pagkatapos ng N retry".
- **Code comments stay in Tagalog,** as the amendment says.

### The summary bar

`#summary-bar` sits above the grid as one line of chips: **Pages** N, **With orders** N, **Token invalid** N, **Setup incomplete** N, **Flow problems** N and **Last sync failed** N, then the legend.

- **Setup incomplete:** a page with orders that lacks any of `botcake.page_id`, `botcake.token`, `botcake.flow.shipping`, `botcake.field.message` or `botcake.template.shipping`.
- **Flow problems:** a flow name line in red (not in Botcake or deleted).
- **Token invalid:** the status starts with `invalid`.
- **Last sync failed:** `last_sync.failed`.
- **Counts:** all of them come from the grid already in the browser, with no new query. They update on every repaint (poll, save, Test all).
- **Filtering:** a chip shows only those pages, together with the name filter. A second click, or Pages, clears it. `aria-pressed` marks the active chip, and Pages when there is no filter. The chips are built once and updated in place, so keyboard focus stays on a chip through a click and a poll. Test all re-renders when a chip is on, so the list follows the new token statuses.

### The row state colour

A 4 px left border on the Page cell:

- **Red** when the token is invalid or the last sync failed, on any row.
- **Amber** when a page with orders is incomplete.
- **Green** when a page with orders is complete and its token is ok.
- **None** otherwise.

Rows without orders keep their grey text. The legend (Ready, Setup incomplete, Token invalid or last sync failed) sits in the summary bar.

## Browser check (headless Chrome over CDP, scratch app)

The setup:

- The scratch app ran on `127.0.0.1:8054` against a scratch sqlite file, with the same tables the tests create.
- The data was made up: 30 pages, including one with a very long name, long flow names, a long template body, a failed sync and two other keys; a ready page, a page with an invalid token, and a page with a flow not in Botcake. Tokens were fake.
- Pages that got edited had no token, so no Botcake call was made.
- The browser logged in with a session minted for the scratch CEO user. The login form needs tables outside this slice.
- The script, seed and server live outside the repo.

Measured on the final tree (`scrollWidth <= clientWidth`, for the document and for the `#grid` box):

| Width | Document | Grid box |
|---|---|---|
| 1366 × 768 | 1366 <= 1366 ✔ | 1318 <= 1318 ✔ |
| 1280 × 720 | 1280 <= 1280 ✔ | 1232 <= 1232 ✔ |
| 1280 × 720, detail row open | 1280 <= 1280 ✔ | 1232 <= 1232 ✔ |

The other checks in the same run:

- **Chips:** "Pages 30 | With orders 6 | Token invalid 1 | Setup incomplete 2 | Flow problems 1 | Last sync failed 1", all as seeded.
- **Each chip** showed exactly its pages:
  - Token invalid: the invalid page;
  - Setup incomplete: the two pages with orders that lack keys;
  - Flow problems: the flow page;
  - Last sync failed: the failed page;
  - With orders: the 6 pages with orders.
- **Clearing:** a second click cleared the filter (30 rows), as did Pages. With "Setup incomplete" on, the name filter "beta" left only that page.
- **Focus:** focus stayed on the chip after a click and a poll. Pages had `aria-pressed=true` with no filter.
- **Borders:**
  - red: the failed page and the invalid page;
  - amber: the two incomplete pages;
  - green: the ready page, and the flow page (complete and token ok);
  - none: a page without orders.
- **Visible text:** no Tagalog words left (a scan of the page text).
- **Botcake cell:** Test PSID was edited in place, marked Saved, and the server returned `1122334455`.
- **Flows cell:** the Broadcast flow was edited in place, marked Saved, and the server returned `5502`. The name line said "Check failed: no botcake.page_id or token", as expected without a token.
- **Escape:** typing then pressing Escape on Message field saved nothing.
- **Open state:** the detail row stayed open after two polls (11 s), after the + Page re-render, and under the name filter.
- **Other key in the detail row:** sms.sender was edited, the input survived a poll, it was marked Saved, the server returned `NEWSMS`, and the detail row stayed open.
- **History link:** it opened the panel with the old value. ▸ closed the detail row.
- **Console:** no JS exceptions.

Screenshots and raw logs are in the main checkout's `handoff/054-page-settings-fit/screens/` (git-ignored).

- **`screens/amendment-1/` (final tree):** `grid-1366.png`, `grid-1280.png`, `summary-1366.png`, `detail-1280.png`, `detail-edited-1366.png`, `history-1366.png` and `evidence.txt`.
- **`screens/` itself:** the first run, before the amendment.

## Case table

| Case | Test | Result |
|---|---|---|
| S-054.1 | `test_S_054_1_every_column_carries_its_cell_group_and_other_keys_go_to_the_detail_row` | pass (red first: `Failed asserting that two arrays are identical`, no `group`) |
| S-054.2 | `test_S_054_2_the_grid_has_five_cells_per_row_in_a_fixed_table_and_keeps_every_action` | pass (red first: `table-fixed` not in the page) |
| S-054.3 | `test_S_054_3_the_sync_cell_shows_the_dates_and_time_then_the_next_stop_date` | pass (red first: the compact strings were missing; later the English strings) |
| S-054.4 | `test_S_054_4_open_detail_rows_live_outside_the_render_and_hold_the_other_keys_and_history` | pass (red first: no `open` set) |
| S-054.5 | Browser check above | pass |
| S-054.6 | `PageSettingsGridTest::test_S_054_6_lines_stored_in_tagalog_before_the_change_are_shown_in_english` (7 keys) and `SyncPlanTest::test_S_054_6_run_errors_stored_in_tagalog_before_the_change_are_shown_in_english` (6 errors) | pass (red first: "Check failed" missing; then `'Pinatay ang sync…'` returned as is) |
| S-054.7 | `test_S_054_7_the_page_text_is_english` and the browser scan | pass (red first: "Search pages" not in the page) |
| S-054.8 | `test_S_054_8_the_summary_bar_counts_from_the_loaded_grid_and_each_chip_filters_with_the_name_filter` and the browser check | pass (red first: no `id="summary-bar"`) |
| S-054.9 | `test_S_054_9_the_page_cell_border_shows_the_row_state_and_the_legend_explains_it` and the browser check | pass (red first: the border rule missing) |

These tests now assert the English wording:

- `FlowNamesTest`: the flow, template and reason lines;
- `SyncPlanTest`: the next labels, the summary, the months and the JS sync line;
- `BotcakeWindowReadTest`: the kind label;
- `PageSettingsGridTest`: the validation message and the lock banner;
- `PageGridOrdersTest`: "Orders (30 days)".

**Full suite on the final tree:** 1608 tests, 18350 assertions, 1 error and 1 failure, 3 skipped. Both are the known ones: `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (error) and `ExampleTest::test_the_application_returns_a_successful_response` (failure). `tests/Feature/PageSettings`: 115 tests, all green.

An earlier full run overlapped my edits and showed S_054_4 red. That was a race; the test is green on the committed tree.

## Reviews (skeptic-reviewer, standard depth)

**First review (layout):** no blocker and no major. Fixed minors:

- **Line order:** the Botcake cell shows ID, Token, Status, Message field, Test PSID (the spec's order).
- **Story table:** the S-054.3 test location.
- **History links:** they skip the read-only Token status.
- **The `:focus` rule:** the unasked `.cell:focus .hist` rule was dropped.
- **Reuse:** the detail row reuses `other` and `fmt`.
- **"+ Column" notice:** it says the key is under ▸.

**Second review (amendment 1):** one major, fixed in one loop.

- **Major, fixed:** run errors stored in Tagalog before the change (`last_error`) were still shown in the Sync tooltip and the detail row. A failed run on a page with an invalid token stays its last run, so this would not clear by itself. The grid now translates them on read (`OLD_ERRORS` plus the retry pattern), test-first (S-054.6 in `SyncPlanTest`, red then green).
- **Fixed minors:**
  - The chip filter now follows Test all (a re-render when a chip is on).
  - The chips keep keyboard focus (built once, updated in place).
  - Pages is `aria-pressed` when there is no filter.
  - The three untested `OLD_TEXT` keys got table rows in S-054.6.
  - S-054.6 to S-054.9 were added to the stories.
- **Accepted,** with reasons in `TODO.md` (054):
  - the red border on rows without orders (to confirm, below);
  - old stored text translated on display only;
  - strings outside /page-settings left as they are;
  - the chip filter applies on render;
  - duplicated `SUMMARY` and validation texts between PHP and JS;
  - the script-string tests.

Also accepted from the first review: the view tests pin strings of the page's script, a + Column key shows only in the detail row, the leftover shipping-flow repaint, and the ⋯ button stays mouse-only.

## Story changes

- **New:** slice 054, cases S-054.1 to S-054.5, written here. The spec brought no stories, and the story-writer agent was not used, to keep to the time box.
- **New for amendment 1:** S-054.6 to S-054.9 (old stored text in English, English page, summary bar, row state colour).
- **Fixed in review:** the S-054.3 test location.

## Notes and what the brief got wrong

- **To confirm, red border on rows without orders:** a page without orders gets the red border when its token is invalid or its last sync failed. The amendment says "Rows without orders stay grey". I read that as their grey text staying, with red still marking a real problem. If those rows should have no border at all, it is a one-word change in `borderOf()`.
- **Bulk actions:** the brief lists "bulk actions" among the things to keep. The grid has none besides Test all and Sync all (and its controls), and all of them are kept.
- **Shipping-flow template line:** the 052 "Template: …" line under the shipping flow is gone, because the template is now the next line in the same Flows cell.
- **Outside the page:** the run `last_message` and the Sync all run message stay Tagalog (the grid does not show them), as do the other pages (`ChatblastFlowInfo`, customers, PSID coverage). `BotcakeClient`'s exception text became English everywhere, because it is shared.
- **Workflow:**
  - No separate "go" on the plan was awaited (non-interactive session).
  - The view tasks of each part went into one commit, because they change one file.
  - The work was done by the main session rather than by developer agents, to keep to the time box.
- **Worktree setup:** the worktree had no `vendor/`, so `composer install` was run in it. The lock file is unchanged.
- **Login on sqlite:** the app's full migration set does not run on sqlite (a MySQL-only `DELETE … JOIN`), and the login needs `everyday_tasks`. That is why the browser check used the tests' schema and a minted session.
- **Merge danger:** low.
  - There is no migration.
  - Old stored Tagalog text still displays, in English.
  - Run logs and command output now carry the English `kindLabel` and month names.
  - Anything outside the repo that parses `BotcakeClient`'s message text (none found inside it) would see English.
