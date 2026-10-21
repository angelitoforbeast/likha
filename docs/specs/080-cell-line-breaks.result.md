# Result 080: botfield cells on /chatbot/pages keep line breaks and fold to 3 lines

Branch `fix/080-cell-line-breaks`, cut from `main` at `da59da93`. Nothing was pushed. This result covers the spec and **amendment 1** (`docs/specs/080-cell-line-breaks.amendment-1.md`).

## Commits

| Commit | What |
|---|---|
| `026fa388` | docs: the brief (verbatim) |
| `98a245c0` | docs: amendment 1 (verbatim) |
| `c36b323a` | fix: line breaks, 3-line fold with a fade, a click opens the whole row, a pencil per cell |
| `5884569c` | fix: a text cell's pencil opens the modal textarea, so line breaks survive an edit; screenshots |
| (this commit) | docs: result, stories |

## What changed (`resources/views/chatbot/index.blade.php`)

- **Line breaks:** a text cell shows the whole value (before, only its first line) with `white-space: pre-line`, and `overflow-wrap: anywhere` wraps long words. The width stays 14rem.
- **Collapsed:** at most 3 lines (`-webkit-line-clamp: 3`). When the text is longer, the last line fades out (a mask). This is the cue, as the amendment asked, in place of "See more".
- **Amendment 1, the row opens:**
  - Clicking a cell, or focusing it and pressing Enter or Space, opens the **whole row**. Every text cell in it shows its full text, up to 15rem (240 px), with its own scroll.
  - Clicking a cell of the open row closes it back to 3 lines.
  - Only one row is open at a time, and the open row stays open across re-renders (after a save, for example).
  - There are no "See more" or "See less" links.
- **Amendment 1, the pencil:**
  - Each editable cell has a ✎ button with aria-label and title "Edit <column>", and it is its own tab stop.
  - On desktop it shows on hover and focus. It is always visible on touch screens (`hover: none`) and under 640 px.
  - It is the only way to open the editor: clicking the text no longer opens it, and the pencil doesn't open the row.
- **Which editor the pencil opens:**
  - Text cells (Main Flow, PRICE, Prompts, Seq N…) open the modal textarea, which keeps the line breaks as typed.
  - Image cells open the inline URL editor, and keep their preview.
- **Unchanged:** the switch, FLOW, the sticky columns, the image preview, the save logic and the modal itself.

## Found while checking

Main Flow, Main Flow 2, Fill-Up Form and Loop 3 Form are "text" columns. Before this change, clicking them opened the inline `<input>` editor, and an `<input>` drops line breaks: editing a multi-line Main Flow there would have saved it as one line. The amendment says the pencil opens "the modal editor", and the URL editor for images. So every non-image cell now opens the modal textarea. A short value like PRICE also gets the modal now, which is one extra click to close it.

## Tests

- `test_S_080_text_cells_keep_line_breaks_fold_to_three_lines_and_open_the_row` (in `ChatbotPagesTest`) pins the markup:
  - the CSS for line breaks, the 3-line fold, the fade and the open row (15rem);
  - the pencil's aria-label, the Enter / Space toggle, and the pencil opening the modal for text and the URL editor for images;
  - no "See more", and no first-line-only display.

  The behaviour itself is checked in the browser, below.
- **Red runs:**
  - Before the view change: the first assertion failed (the CSS wasn't there).
  - The test for the modal-for-text fix failed until the pencil was changed.
- `vendor/bin/phpunit tests/Feature/Chatblast`: 409 tests, 2728 assertions, all pass (PHPUnit deprecations only). I used `vendor/bin/phpunit` rather than `php artisan test`: this worktree has no environment file, and one must not be created.

## Browser check

Headless Chrome over CDP, against a scratch app (sqlite under `%TEMP%`, replies off, fake tokens). The script is outside the repo: `%TEMP%\likha075\check080.mjs`. Seed data:
- Alpha Store: a 14-line Main Flow, a 5-line Fill-Up Form, a 20-line Prompt 1;
- Beta Shop: a 2-line Main Flow, and a 90-character word in PRICE.

| Check | 1366 × 768 | 390 × 844 |
|---|---|---|
| Collapsed Main Flow | 3 lines (60 px), fade on, 14 line breaks in the text ✔ | same ✔ |
| No "See more" anywhere | ✔ | ✔ |
| No page-level horizontal scroll | 1366 = 1366 ✔ | 390 = 390 ✔ |
| Pencil "Edit Main Flow" | hidden until hover or focus (opacity 0) ✔ | always shown (opacity 1) ✔ |
| Click the text → the row opens | Main Flow 240 px with an inner scroll (scrolled to 120 px); Fill-Up Form in the same row shown in full ✔ | same ✔ |
| Click Beta Shop's cell | only Beta Shop open ✔; a second click closes it ✔ | same ✔ |
| Open Alpha, click again | back to 3 lines ✔ | same ✔ |
| Keyboard | Enter opens, Space closes, Tab goes to "Edit Main Flow" ✔ | same ✔ |
| Pencil on Main Flow | modal with all 14 lines; the row did not open; after closing, the cell still has 14 lines ✔ | same ✔ |
| Pencil on an image cell (Seq Img 1) | inline URL editor ✔ | same ✔ |
| Pencil on Prompt 1 | modal with all 20 lines ✔ | same ✔ |
| Long word in PRICE | wraps inside the cell ✔ | same ✔ |
| Console errors | none ✔ | none ✔ |

Screenshots in `docs/specs/080-cell-line-breaks/`:
- `desktop-1-collapsed.png`, `desktop-2-row-expanded-inner-scroll.png`, `desktop-3-collapsed-again.png`, `desktop-4-pencil-opens-the-editor.png`;
- `phone-1-collapsed.png`, `phone-2-row-expanded-inner-scroll.png`, `phone-3-collapsed-again.png`.

On the phone, the sticky Page column covers the left part of a cell once the table is scrolled sideways. That is the sticky behaviour the spec keeps unchanged.

## Story changes

New section S-080 (S-080.1 to .4), written for the amended interaction.

## Changes from the spec (amendment 1)

- **No "See more" or "See less":** clicking a cell opens the whole row instead (one row at a time), with a fade as the cue.
- **A pencil per cell is the only way to edit:** clicking the text no longer opens the editor.
- **Text cells now edit in the modal textarea** (this was the inline input before), so line breaks survive. See "Found while checking".
