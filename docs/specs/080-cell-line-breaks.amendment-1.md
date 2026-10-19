Amendment 080-1. I stopped your run on purpose so this change could be added. Keep your work so far.

Save this text verbatim as `docs/specs/080-cell-line-breaks.amendment-1.md`, commit it, and treat it as part of the spec. The owner changed the interaction on 2026-10-19: "walang button na see more, iccclick yung cell para magexpand yung buong row. tapos may edit button lang per cell".

| Change | Why |
|---|---|
| **No "See more" or "See less" links.** | The owner. |
| **Clicking a cell expands the whole row:** every text cell in that row shows its full text, each up to the max height (about 240 px), scrolling inside when longer. Clicking a cell of an expanded row collapses the row back to 3 lines per cell. Only one row is expanded at a time; expanding another row collapses the previous one. Show a subtle cue that a row can expand, such as a fade at the bottom of clamped text. | The owner. |
| **Editing.** Each editable cell gets a small **edit button** (pencil icon, aria-label "Edit <column>"). It is visible on hover and focus on desktop, and always visible on touch or phone width. It is the only way to open the modal editor. Clicking the text no longer opens the editor. Image cells keep their preview, and their edit button opens the URL editor. | The owner. |
| **Keyboard.** A cell is focusable. Enter or Space toggles the row; the edit button is its own tab stop. | Accessibility. |

Everything else in spec 080 stays as written: line breaks kept, 3 lines collapsed, the max height, no page-level horizontal scroll, the browser check and the screenshots. The result lists this change.
