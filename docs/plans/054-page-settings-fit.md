# Plan 054: /page-settings fits the screen

Threat model: setting values, page names, flow names and template bodies are stored text that came from the owner,
the orders import or Botcake (untrusted data, rendered only through `textContent`, never HTML). The grid's endpoints,
auth and validation are unchanged. No new route, form or parser: a layout change on an existing CEO screen. Risk:
**medium** (UI screen, plus one additive payload field).

## Design

- **Payload:** every column gains `group`: `botcake` (page_id, token, token_status, field.message, test_psid),
  `flows` (the 4 flow IDs and the shipping template) or `other` (every non-KNOWN key). The view places cells by
  `group`, so the grouping is pinned server-side and tested. A column added with "+ Column" in the browser is `other`.
  Nothing else in the payload changes.
- **Table:** `table-fixed w-full` with a `colgroup` (Page, Botcake, Flows, Orders / Customers, Sync, actions), so
  no value can widen it. Long values wrap (`break-all`) or are clipped (`truncate`) with the full text in `title`
  and in the expanded row. Page names wrap. The Page column stays sticky.
- **Editable value = `.cell` element** (was `td.cell`). The stacked cells hold one labelled line per key: a small grey
  label, then a `div.cell` with `data-pk` / `data-key`. `paint`, `repaint`, `startEdit`, the history button and the
  keyboard handler work on `.cell` as before, so click to edit, Enter / blur / pause save, Escape, Saving/Saved/!
  marks, secret handling, the `saveSeq` late-response guard and the poll's "keep the edited cell" rule are untouched.
- **Botcake cell:** ID, Token (masked as today), Status (read-only, system), Message field, Test PSID.
- **Flows cell:** Shipping, Delivering, In transit, Broadcast (ID + the 052 name line, clipped), then Template (name +
  one clipped body line, full body on hover). The "Template: …" line under the shipping flow goes: the template is
  now the next line of the same cell.
- **Orders / Customers cell:** "orders / customers", one line.
- **Sync cell:** line 1 the dates and the time of the last run ("Okt 17 → Okt 18 · 01:04", "wala pa"); line 2
  "susunod: mula Okt 17" (or the next label when there is no date). Red when the last run failed. Full text on hover.
- **Actions cell:** small buttons "Test", "Sync" (titles "Test token", "Sync now"), links "Cust." / "Orders" with
  titles; same forms and URLs as today.
- **Expanded row:** a ▸ / ▾ button in the Page cell. Opens a `tr.detail` under the row, one cell spanning the table:
  the flows with their full names, the template with its full body (wrapped), the sync details (dates, kind, time,
  status or stop reason, new / updated / matched / ad id counts, error, the full next label), the other keys as
  labelled editable `.cell` lines, and a History button for every key of the page. The open pages are a `Set` kept
  outside `render()`, so a poll, a structure re-render or a filter keeps them open. The read-only part of an open
  detail row is refreshed when its cells or info change; the editable other-key cells repaint like any `.cell`.
- **Unchanged:** endpoints, poll, filter, sort, Test lahat, Sync lahat and its panel, + Page, + Column, the grey rows.

## Tasks

1. **Column groups in the payload** (backend, medium). Case S-054.1. Test in `PageSettingsGridTest`.
2. **Stacked cells, sync, actions, fixed table** (frontend, medium). Cases S-054.2, S-054.3. View test in
   `PageSettingsGridTest`; `SyncPlanTest`'s pinned JS strings follow the new compact sync cell.
3. **Expanded row** (frontend, medium). Case S-054.4 (view test for the toggle and the open set) and S-054.5
   (browser).
4. **Browser check** (headless Chrome over CDP, a scratch sqlite database with made-up pages, no Botcake token on
   the edited pages so no Botcake call): no horizontal scroll at 1366 and 1280 px, edit in both stacked cells, the
   detail row open through a poll, an other key edited in the detail row. Case S-054.5.
5. **Review** (skeptic-reviewer, standard depth), fixes, full suite, result.
