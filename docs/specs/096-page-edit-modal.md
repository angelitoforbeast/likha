# Brief 096: an Edit button on each page row opens that page's fields, with paste, and Save

## Why

Spec 092 shipped a global "Paste values" modal on `/chatbot/pages`. It starts with a page picker of all 60 pages, then a paste box. The owner, 2026-10-20, seeing it live: "panget to, per page yung button, para lagyan yung value".

What he wants:
- a button per page;
- a modal for that page;
- all its fields listed vertically and scrollable (his earlier words: "per field kita, scrollable vertically");
- paste from Google Sheets to fill them;
- a Save button.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **An "Edit" button in each row's Page cell** (pencil icon plus "Edit"; on phones the icon with aria-label "Edit <page>"). It opens the **page modal** for that page. | The owner. |
| **Page modal layout.** The title is the page name, with the page ID muted. At the top sits a paste box: "Paste a row copied from Google Sheets (with or without the header line)". Below it, **every botfield of the page in grid column order, one per line, vertically scrollable**: the field label, then an auto-growing textarea with the current value, line breaks kept. Image fields (PICTURE, Seq Img N) show a small preview next to the link input. The footer stays sticky with "Save N changes" and Cancel. ACTIVE and FLOW are not in this modal. | The owner. |
| **Paste fills the fields.** Pasting into the paste box parses the TSV (reuse 092's parser in `public/js/chatbot-paste.js`). A header line maps **by name**; otherwise the values map **by order**, starting at a "Starting at field" dropdown (default the first botfield), and only the first data row is used. Filled fields turn amber, with "Was: …" under them. A blank pasted cell keeps the current value unless "Paste blanks as empty" is ticked. A non-link in an image field is flagged and not saved. | Paste one row, fill everything. |
| **Edits by hand** in any field also count as changes (amber). | Same modal for typing. |
| **Save** sends all changed fields of this page in one request through 092's bulk-save endpoint (all or nothing, history kept, limits, image-link check). After saving, the row in the grid updates and an "Undo" link appears in a toast for that save (092's undo). Cancel, or closing with unsaved changes, asks first. | Reuse what works. |
| **Remove the global "Paste values" button and its page-picker modal**, and the row checkboxes it added. Keep the grid's select, copy and Ctrl/Cmd+V paste from 092 as they are. | The owner disliked the global modal. |
| **Phone (390 px):** the modal is full screen; the fields scroll; Save and Cancel stay visible. | Usable on phone. |

## Done when

- **Tests:**
  - the modal data endpoint returns, for one page and in column order, the fields and current values (only for a page the role can see; reuse the existing endpoints if they already give this);
  - Save through bulk-save for one page changes only that page;
  - the global modal's page picker is gone;
  - existing 092 tests still pass, or are updated where they covered the removed global modal.
- **Browser check:**
  - the Edit button on a row;
  - the modal with fields listed vertically;
  - paste of a TSV row with a header filling fields, amber;
  - Save, then the grid updated;
  - Undo;
  - phone layout.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes.
- Report the commits.
