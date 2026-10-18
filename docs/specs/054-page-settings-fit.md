New task: spec 054. In this worktree, a new branch `feat/054-page-settings-fit` starts from `main` at 98e1ea66 (live on Likha and Incepxion; it holds specs 050, 052 and 053).

**Rules**
- Never push and never touch remote hosts.
- Use fake HTTP in tests, and never call Botcake from tests.
- No attribution lines in commits. No names or real ids in tests.
- Write comments in the style of the surrounding code (Tagalog doc comments).

**Deliverables and pace**
- Save this brief as `docs/specs/054-page-settings-fit.md` in your first commit.
- Write the plan first, in `docs/plans/054-page-settings-fit.md`.
- At the end, save `docs/specs/054-page-settings-fit.result.md`.
- Aim at about 1.5 hours. Commit after each part, and run a review before the result.

# Brief 054: /page-settings fits the screen, with no horizontal scroll

## Why

The owner has a standing rule, confirmed 2026-10-02: "ayaw ko ng horizontal scroll a, isa sa ayaw ko yun". When a table gets too wide, fit it to the screen: group or stack values, hide less-used columns, or move detail into an expandable row.

The /page-settings grid (`resources/views/page_settings/index.blade.php`, columns from `app/Services/PageSettingsGrid.php` KNOWN plus any other keys) now scrolls sideways. It has these columns:
- Page (sticky);
- Botcake ID, Botcake token;
- 4 flow IDs (Shipping, Delivering, In transit, Broadcast), each with the flow name line from spec 052;
- Shipping template, with its body;
- Message field ID;
- Test PSID;
- Token status;
- any other keys;
- Orders, Customers;
- Huling sync and Susunod na sync, with spec 053's dates;
- actions.

The owner chose the layout: "pagsamahin ang Flows at Botcake sa tig-isang cell, detalye sa nabubuksang row, walang horizontal scroll."

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| **One "Botcake" cell per row** stacks: Botcake ID, token (masked exactly as today), Token status (system, read-only), Message field ID and Test PSID. Each is a labelled line (small grey label, then the value), and each value stays **editable in place** exactly as today: click to edit, save on blur or Enter, Saving/Saved/error marks, history link, secret handling, and the late-response guard from 052. | Owner's layout |
| **One "Flows" cell per row** stacks the 4 flow IDs (label, ID and the 052 name line: name / red "Wala sa Botcake" / "Bura na sa Botcake" / grey "hindi pa nasusuri") and the Shipping template (name plus a one-line clipped body, with the full body in the expanded row and on hover). Every ID and the template stay editable in place, with the same behaviour. | Owner's layout; everything shown and editable |
| **"Sync" cell:** Huling sync and Susunod na sync stacked in one cell, compact ("Okt 17 → Okt 18 · 01:04" and "susunod: mula Okt 17"; red when failed, as in 053). Orders and Customers can be one small "Orders / Customers" cell. | Fit |
| **Actions** (Test token, Sync, history and the like) fit in a narrow cell: small buttons or icons with titles. Nothing is removed, and every action keeps working. | Fit, nothing lost |
| **Expandable row:** a ▸ toggle on the Page cell opens a full-width detail row under it with everything that doesn't fit: the full template body, the full flow names, the sync details (kind, stop reason, new/updated counts, errors), **any other (non-KNOWN) keys** with their editable cells, and links to history. The open/closed state per page survives the 5-second poll and re-renders (it must not collapse while the owner is reading or editing). | Owner's layout |
| **No horizontal scroll** on the page at a 1366 px wide window (and at 1280 px), whatever the page names or values. Long values wrap or are clipped with the full text on hover or in the expanded row. The page column stays sticky. Check it in a real browser at both widths (headless Chrome is fine) and put screenshots or the measured `scrollWidth <= clientWidth` in the result. | The owner's rule |
| **Behaviour is unchanged:** the same data and endpoints, poll, filter, sort order (most orders first), Test lahat, Sync lahat (pause, resume, stop, run panel), bulk actions, + Page, and the grey rows for pages without orders. Server-side changes only where the grid payload needs them. No migrations. | A layout change only |

## Tests

- Feature tests for anything the payload changes.
- A test that the grid payload or view still includes every KNOWN key and other keys, and every action.
- A browser check, by hand or headless: no horizontal scroll at 1366 px and 1280 px, edit in place in both stacked cells, the expanded row stays open through a poll, and other keys can be edited in the expanded row.
- The whole suite must pass except the known `ExampleTest` and `ImportStartTest`. Note that the chatblast tests reset `set_time_limit`.

## Out of scope

- New data or new Botcake calls.
- Other pages.

## Final message

- the commits;
- the new layout per cell;
- the expanded row;
- the browser check evidence (widths, scrollWidth, screenshots' paths);
- the test lines and suite result;
- the review findings and fixes;
- anything the brief got wrong.
