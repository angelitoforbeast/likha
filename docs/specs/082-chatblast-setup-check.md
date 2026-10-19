# Brief 082: chatblast flow and message field set by fixed names, with a check per page (`docs/specs/082-chatblast-setup-check.md`)

## Why

On `/chatblast/page-settings`, every page needs `botcake.flow.shipping` (the Botcake flow ID) and `botcake.field.message` (the custom field ID).

The owner, 2026-10-20: all pages use the same flow name, custom field name and template, so the IDs should be set automatically on every page, new ones too. A page that is wrong should be visible.

The fixed names:
- flow `UPDATE SHIPPING STATUS 2026`;
- custom field `message`;
- template `post_order`.

**Today:**
- `BotcakeFlowNames::detectFlow` and `BotcakePageSetup::detectField` only fill blank values.
- They use fuzzy rules: a flow name containing "CHATBLAST" and "(post_order)", a field named "gptinput" or "message".
- They run only on Test token or save, and never replace an ID.
- `ChatblastFlowInfo` warns when a flow name lacks "CHATBLAST".
- The template auto-pick reads the name in parentheses at the end of the flow name.

**Live data, 2026-10-20 (read from the stored options lists):**
- 60 pages have a token.
- 40 pages have exactly one flow named `UPDATE SHIPPING STATUS 2026` and one field named `message`. The other 20 have neither.
- All 16 pages that are Ready today are among the 40. They currently point at `CHATBLAST - SHIPPING STATUS (post_order)` and a "GPT INPUT" field, so they will switch.
- On two pages that were checked, `post_order` is an APPROVED template.

**Botcake API (checked live):**
- `GET /pages/{id}/flows` returns only `id`, `name`, `is_removed` and `parent_id`. It does not include the flow's content, so the template and field used inside a flow cannot be seen.
- `GET /pages/{id}/flows/{flow_id}/statistics` returns cumulative `{seen, sent, delivery, total_phone_number, is_clicked}`.
  - The current flow on one page: sent 156, delivery 152.
  - The new flow: all 0.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Setup box** at the top of `/chatblast/page-settings` with three values: Flow name (default `UPDATE SHIPPING STATUS 2026`), Custom field name (default `message`) and Template name (default `post_order`). It can be edited by whoever can edit page settings. A **Check all** button sits next to it. Saving the box runs Check all. | The owner sets the names once, for every page. |
| **Storage.** Use page_settings rows under a reserved page name `_all`, with keys `botcake.setup.flow_name`, `botcake.setup.field_name` and `botcake.setup.template_name`. `_all` never appears in any page list, count, dropdown, Ready, chatblast, sync, chatbot or inbox. Find every page-listing query and test the main ones. If the repo already has a global-settings precedent that fits better, use it instead and say why in the result. | Row-based settings, the owner's pattern. |
| **Matching.** The name is trimmed, compared case-insensitively, with inner whitespace collapsed. Otherwise it must be equal: no fuzzy match. Removed flows (`is_removed`) are ignored. | Fixed names. |
| **Per page, per value.** If exactly one matches, write its ID to `botcake.flow.shipping` or `botcake.field.message` as a system write, replacing any other value, including one a person set. PageSettings keeps the old row as history. If none matches: **X** "No flow named … in Botcake" (or field). If two or more match: **X** "2 flows named …". On either X, leave the stored value as it is, but the page is **not Ready**. | The owner: move every page to the fixed flow. A page on any other flow must not send. |
| **Template.** The template passes when an APPROVED template has that exact name (same matching) and its BODY contains `{{1}}`. Write the name to `botcake.template.shipping`. If not, **X** with the reason, and the page is not Ready. | The owner: check the template by name and content. |
| **No Send test needed.** If the check set the flow and field IDs and everything passed, the page is Ready without a Send test. A change made by a person still needs a test, as today. Implement this in `ChatblastReady` so its doc comment stays true. | The owner, 2026-10-20: the check proves the names, and the delivered check below catches a broken flow. |
| **Delivered check.** When the check sets a new flow ID on a page, record that flow's statistics (`sent`, `delivery`) as the baseline. Once the page has at least one non-test chatblast sent via that flow at least 60 minutes ago, read the statistics again (hourly at :45, only for pages not yet confirmed). If `delivery` is above the baseline, mark it **Delivered** and stop checking. If it is not: **X** "Sent N, none delivered (Botcake)", and the page is not Ready, so sends skip with that reason. Record which flow ID each send used (the steps JSON may already hold it; check before adding a column). | The owner: Botcake shows delivered or not. |
| **The Check column** on the page list uses the **normal check and X icons** used elsewhere in Likha, not text glyphs such as ✓ or ✗. X shows its reason as a short line under the icon. It also shows when the page was last checked. A check that couldn't reach Botcake shows a grey "Check failed: <reason>", changes nothing, and leaves Ready as it was. | The owner: "yung normal lang". |
| **When it runs.** On + Page or a Test token that succeeds (replacing today's detect calls). Nightly at 21:30 Asia/Manila for every page, after the In transit window. On Check all, as a queued job on the `default` queue, where the page shows progress and the finish time. | Covers new pages and later changes in Botcake. |
| **Command.** `botcake:setup-check {page?} {--all} {--dry-run}`. The dry run prints, per page, the current ID, the found ID, the result (check or X plus the reason) and whether Ready would change. It writes nothing. | The reviewer runs the dry run on live before the first real run. |
| **Remove the fuzzy rules.** Remove the CHATBLAST and `(post_order)` candidate auto-pick, the gptinput auto-pick, the CHATBLAST warning in `ChatblastFlowInfo`, and the parentheses template pick. The fixed names replace them. The dropdown option lists may stay for display. | One source of truth. |
| **Botcake calls.** Per page: `flows`, `custom_fields` and `message_templates` (plus `statistics` when needed). Use the existing per-token shared throttle, one try per call, and no token in any log, step or page. | Existing client rules. |

## Done when

- **Tests:**
  - matching (case, spaces, removed);
  - one, none and two matches for the flow and the field;
  - a template that is approved, not approved, or has no `{{1}}`;
  - a system switch keeps Ready without a test;
  - a person's change still needs a test;
  - a page on a non-fixed flow is not Ready;
  - delivered check: baseline, delivered, not delivered after 60 minutes;
  - `_all` excluded from the page list, Ready and chatblast;
  - the dry run writes nothing.
- `php artisan test tests/Feature/Chatblast` passes.
- A headless browser check of `/chatblast/page-settings` at 1366 and 390 px shows:
  - the setup box;
  - the Check column with a check and an X;
  - no page-level horizontal scroll.
  Screenshots go in `docs/specs/082-chatblast-setup-check/`.
- Report the commits, the files changed and any migration.

## Out of scope

- Creating flows, fields or templates in Botcake (there is no API for it).
- The chatbot (`/chatbot`).
- The Send test flow itself.
- The 20 pages that lack the flow: they show an X, and the staff will add the flow in Botcake.
