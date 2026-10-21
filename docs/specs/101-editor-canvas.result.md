# Result 101: moving around the flow editor is easy, and dragging never selects text

Branch `fix/101-editor-canvas`, cut from `feat/097-variable-groups` as the brief asked. Nothing was pushed, and no server was touched. Only the flow editor (`resources/views/chatbot/editor.blade.php`) and its tests and docs changed. PageSettings, Botcake and `/chatbot/pages` were not touched.

## Commits

| Commit | What |
|---|---|
| `e252297e` | docs: the brief, verbatim |
| `ce8a2b63` | feat: whole-card drag without text selection, Snap, pinch, wire highlight, layered Arrange, minimap; tests S-101.1 and S-101.2 |
| `c3653c05` | fix: after a pinch the remaining finger pans; the wire "×" is pointer-only (review minors) |
| (this commit) | docs: result, stories S-101, TODO, screenshots |

## What changed

| Decision | How |
|---|---|
| No text selection while dragging | During a step drag or a pan, `#cv` gets the class `moving`, which sets `user-select: none` and the grabbing cursor. A `selectstart` guard runs while a pointer is held on the canvas, and any selection is cleared on each move. The step forms are modals outside `#cv`, so their text stays selectable (browser check 3). |
| Whole card is the handle | A press anywhere on a card, except a port dot, starts a step drag. It becomes a drag only after 4 px of movement; less than that is a click and opens the form, as before. Cursors: grab on the card, grabbing while dragging. Ports still start a wire. |
| Pan and zoom | Dragging empty space pans (mouse or one finger). The wheel zooms around the pointer; Ctrl+wheel (trackpad pinch) has a finer step. The range is 0.2×–2× (it was 0.3×–2×). A two-finger pinch zooms and pans around the point between the fingers; when one finger lifts, the other one pans. "−", "+" and Fit are unchanged in use. |
| Snap | A "Snap" checkbox in the toolbar, on by default and saved in `localStorage` (`chatbot.snap`). A dropped step is rounded to a 16 px grid. |
| Wires | Bezier curves from the port dot to the step's input, drawn behind the steps (as before). A wire that goes back to the left loops wider so it stays readable. Each wire has an invisible 12 px hit path, so it is easier to click. Hovering a step highlights its incoming and outgoing wires in blue and dims the rest to 15 %. Clicking a wire selects it and shows a "×" at its middle plus the existing "Remove wire" button; the Delete key works as before. |
| Arrange | A layered layout, left to right. It does a breadth-first walk from Start, with each step's wires taken in port order (row 1, row 2 … no match). Then come the steps no wire points to, then whatever is left (loops cut off from Start). Columns are 320 px apart (cards are 220 px wide). In each column a step sits level with the step it came from when there is room; otherwise it goes under the column's last card, with a 32 px gap. Everything is on the 16 px grid. It is one click and one Undo step, as before. |
| Minimap | A canvas at the bottom right of the editor: 180×120 px, or 120×80 px under 640 px wide. It shows every step (Start in green) and the current view as a blue frame. A click or drag on it centres the view there. The "Map" button hides and shows it (`aria-pressed`), saved in `localStorage` (`chatbot.minimap`). |
| Saved format | Unchanged: only `x` and `y` of nodes change. Undo, Redo, Save draft, Publish, Discard and History are untouched. |
| Speed | Steps are placed with `transform: translate()` instead of `left`/`top`. Card heights and port offsets are measured once per render, not during a drag. A drag move only updates that card's transform and the paths of its own wires. The minimap redraws at most once per animation frame. Measured: 0.054 ms per drag move with 40 steps and 40 wires, including a forced layout read after each move (check 24). |
| Fit | Its margin is now 30 screen pixels on each side. Before, it was 60 world units, so below about 0.5× zoom the right-hand column spilled out of the canvas; Arrange calls Fit on a wide layout. |
| Help text | The description under the title now says: drag a step to move it; drag empty canvas to pan; scroll or pinch to zoom. |

## Tests

Case table:

| Case | Test | Result |
|---|---|---|
| S-101.1 saving keeps node positions (snapped, negative, far right) and changes nothing else | `ChatbotEditorCanvasTest::test_S_101_1_saving_keeps_the_node_positions` | ✔ pass |
| S-101.2 the page has Snap (checked), Map with its minimap, and the no-select rule on `#cv.moving` | `ChatbotEditorCanvasTest::test_S_101_2_the_editor_has_snap_and_a_minimap` | ✔ pass |
| S-101.3 drag 200 px: no selection, moves, cursors; form text still selectable | browser 3, 6, 7, 8b | ✔ pass |
| S-101.4 click, under-4-px wobble, tap or Enter opens the form | browser 1, 2, 19c, 27 | ✔ pass |
| S-101.5 pan, wheel and Ctrl+wheel zoom around the pointer, limits, − + Fit, pinch, pinch then lift a finger | browser 12–16, 28, 29, 29b, 29c | ✔ pass |
| S-101.6 Snap on and off, remembered | browser 8, 10, 11 | ✔ pass |
| S-101.7 wire from a port, wires behind, hover highlight, select with ×, Undo | browser 17–19, 19b | ✔ pass |
| S-101.8 Arrange: no overlaps, left to right, on the grid; Undo and Redo | browser 0, 4, 5 | ✔ pass |
| S-101.9 minimap draws, click moves the view, toggle remembered | browser 20–22 | ✔ pass |
| S-101.10 390 px usable | browser 25–28 | ✔ pass |
| S-101.11 autosave keeps positions; a cancelled drag is one Undo step; drag speed | browser 19d, 23, 24 | ✔ pass |

**Red runs:**
- S-101.2 failed first with `Failed asserting that '<!DOCTYPE html>…' contains "id="snap" checked"`.
- S-101.1 passed at once. It is a characterisation test: the server already kept positions (`FlowGraph::clean`), and this work must not change that.
- For the canvas, the same drag was run against the editor before this change (the old file restored for one run, then put back). Dragging step n2 from its text selected `"Pili po kayo: price, cod, location ⏳ waits for reply → orde…"`, and the step did not move.

**Suites:** `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: **763 tests, all pass** (761 before plus the 2 new ones). There is 1 skip and there are 47 PHPUnit deprecation notices, both as before. `ChatbotEditorTest` and `ChatbotDraftPublishTest` pass unchanged.

## Browser check

**Setup:** the same approach as 092–097. A script outside the repo drove the installed Chrome headless over the DevTools protocol, with real mouse, wheel, keyboard and touch input (Node 24 built-ins, no package installed). The app ran with `php artisan serve` on a temporary sqlite database outside the repo, with settings passed as environment variables; no env file was created.

**Fixture:** "Main Flow (from CBB)", 40 steps piled in one 500×320 px corner (231 overlapping pairs). It has a Send that waits, a Condition with 3 rows plus "no match", four branches of 8 steps with AI failed-ports to a shared End, a loop back to the Send, a chain that Start never reaches, and loose Ends. Before each run, the draft was dropped so the run started from the pile.

**Result: 42/42 checks passed** at 1366×768 and 390×844 (touch emulation on), with no console errors.

| # | Check | Output |
|---|---|---|
| 0 | Fixture: 40 steps, piled up | 231 overlapping pairs |
| 1, 2 | A click, and a 2 px wobble, open the form | "Condition · n3", "Set field · n25" |
| 3 | Text in the step form can be selected | selection non-empty |
| 4, 4b | Arrange: no rectangles intersect, Start leftmost, 11 columns, all on the 16 px grid | overlaps `[]` |
| 5, 5b | Undo restores every position; Redo brings the layout back | exact |
| 6 | Drag 200 px from the step's text: selection empty mid-drag and after; `moving` and `dragging` set | `""` |
| 7 | The step moved 200 px on screen (544 world units at 0.368×) and the form stayed shut | dx 544, expected 543.7 |
| 8, 8b | Snap on: drop at [912, 48]; cursor grab, then grabbing | ✔ |
| 9 | Undo after the move puts it back | [368, 48] |
| 10, 11 | Snap off: lands at [920, 149], expected [919.9, 148.6]; off survives a reload | ✔ |
| 12 | Pan: the view moved exactly 150, 80; steps unchanged; no selection | ✔ |
| 13, 14 | The wheel and Ctrl+wheel zoom around the pointer | drift 0.28 world px |
| 15 | Limits | 2 and 0.2 |
| 16 | "+", "−", and Fit showing every step inside the canvas | ✔ |
| 17, 17b, 17c | Hovering n3 lights exactly its 5 wires (opacity 1), dims the rest (0.15), wires behind the steps, cleared on leave | ✔ |
| 18, 19 | Clicking a wire selects it, with the × and Remove wire; the × removes it (40 → 39); Undo brings it back (40) | ✔ |
| 19b | Dragging from n38's port to n39 makes the wire | ✔ |
| 19c | Enter on a focused step opens its form | ✔ |
| 19d | A `pointercancel` mid-move keeps the new place, ends the drag and is one Undo step | ✔ |
| 20 | The minimap is 180×120 at the bottom right, drawn | 11 352 painted pixels |
| 21 | Clicking its right edge shows the last column (Start leaves the view); clicking Start's green mark brings Start back | ✔ |
| 22 | Map hides it (`aria-pressed=false`), and it stays hidden after a reload | ✔ |
| 23 | After a drag, the autosaved graph has exactly the positions on screen | "Draft saved" |
| 24 | Drag cost | 0.054 ms per move |
| 25 | 390: page scroll width 390, canvas 358×572, every canvas tool on screen, minimap 120×80 | ✔ |
| 26, 27 | 390: a one-finger drag moves a step without selection; a tap opens the form, which fits | ✔ |
| 28 | 390: one finger on empty space pans exactly 50, 30 | ✔ |
| 29, 29b | 390: a pinch from 80 to 164 px zooms ×2.05 with 0 drift, and moves no step | ✔ |
| 29c | 390: after a pinch, lifting one finger lets the other pan 40, 20 at the same zoom | ✔ |
| 30 | Console errors | none |

**Screenshots** are in `docs/specs/101-editor-canvas/`:
- `1366-before-arrange.png`: the pile.
- `1366-after-arrange.png`: the arranged 40-step flow.
- `1366-hover.png`
- `1366-wire-selected.png`
- `1366-minimap-right.png`
- `390-canvas.png`
- `390-form.png`
- `390-pinched.png`
- `390-fit.png`

At 390 px the minimap sits at the bottom of the canvas, below the first screen (the canvas is 572 px tall), so it is not in `390-canvas.png`; check 25 measured it.

## Review

One `skeptic-reviewer` pass at standard depth (medium risk: a UI screen, no server change). **No blocker or major.** It confirmed:
- wire creation, Enter, Delete, forms and Undo/Redo still hold;
- the measurements can't go stale, since every content change goes through `render()`;
- Arrange can't overlap or crash on cycles, edges to missing nodes or unreachable steps.

Minors:

| Minor | Outcome |
|---|---|
| A pinch kept running after one finger lifted, and each later move threw a TypeError | **Fixed** (`c3653c05`): the remaining finger becomes a pan. Browser check 29c added. |
| The wire "×" had `role="button"` but no keyboard focus | **Fixed**: role and label removed; the keyboard path is the Delete key and the "Remove wire" button. |
| The selected wire is an index into `graph.edges` and can point at another wire after edges shift | **Accepted** in `TODO.md`: it predates this spec and Undo recovers it. |
| The browser cases have no committed script | **Accepted** in `TODO.md`: no browser runner in the repo, and adding one is a new dependency. This result lists every check with its output. |
| Missing cases: wire from a port, Enter, pointercancel, pinch-lift | **Added** as browser checks 19b, 19c, 19d and 29c. |
| Arrange with no Start | Not added: it returns early, unchanged from before. |

## Story changes

- New section S-101 in `qa/stories.md`, eleven cases. The brief brought no stories and said to start at once, so they were written from its decisions and its "Done when" list, not run past the reviewer first.
- After the review, S-101.4 (Enter), S-101.5 (lift a finger after a pinch), S-101.7 (a wire from a port) and S-101.11 (a cancelled drag) were widened to cover the reviewer's missing cases.

## Notes and conflicts

- **Beyond the literal brief:**
  - The minimum zoom went from 0.3× to 0.2×, so a 40-step arranged flow fits on one screen.
  - Fit's margin was fixed (see above).
  - The help text under the title was updated (docs travel with the change).
  - A "×" was added on the selected wire as the delete affordance; the editor already allowed deleting wires.
- **Real devices** (iOS Safari pinch, trackpads) were not tried; only emulated input was. This is noted in `TODO.md`.
- **No conflict** between CLAUDE.local.md and the brief.
- **Deploy:** no migration, no new setting, no route. No asset build either: the editor's CSS and JS are inline in the Blade view.
