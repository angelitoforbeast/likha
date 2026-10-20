# Brief 101: moving around the flow editor is easy, and dragging never selects text

## Why

On `/chatbot/flows/{id}` (`resources/views/chatbot/editor.blade.php`, the canvas `#cv`), dragging a node or the canvas selects the node text, so moving things is hard. Wires cross over nodes and are hard to follow, and node cards overlap in a big flow ("Main Flow (from CBB)", about 40 nodes). The owner, 2026-10-21: "medyo panget lang interface neto … ang hirap mag move, pag nagmmove nahhighlight".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **No text selection while dragging.** During a node drag or a canvas pan, set `user-select: none` on the canvas, and clear any selection that starts. Text inside node forms (the modals) stays selectable. | The owner's main complaint. |
| **The whole node card is the drag handle,** except its ports and buttons. Show a small threshold (about 4 px) before a drag starts, so a click still opens the node. Show a grab cursor and a dragging cursor. | Easy to grab. |
| **Canvas pan and zoom.** Drag on empty space pans. Mouse wheel and trackpad zoom around the pointer (Ctrl+wheel too), within limits. On touch, a two-finger pinch and pan. The existing "−", "+" and "Fit" keep working. | Easy to move around. |
| **Snap to a 16 px grid** when dropping a node, toggled with a "Snap" checkbox (default on, remembered per browser). | Tidy layout. |
| **Wires.** Draw them as smooth bezier curves from the port side, behind the nodes. Hovering a node highlights its incoming and outgoing wires and dims the others. Clicking a wire selects it, with a delete affordance if the editor already allows deleting links. | Easy to follow. |
| **"Arrange"** lays the graph out left to right by flow order (a layered layout from the start node, branches stacked) without overlaps. Keep it one click and undoable with the existing Undo. | Clean layout. |
| **A minimap** (small, bottom-right, toggle) shows the whole flow and the viewport. Clicking it moves there. | Big flows. |
| **No change to the saved graph format** except node positions. Undo, Redo, Save draft and Publish behave as now. Performance: smooth at 60+ nodes (no layout thrash on drag; use transforms). | Safety and speed. |

## Done when

- **Browser check (headless Chrome, scripted):**
  - dragging a node 200 px leaves `window.getSelection()` empty and moves the node;
  - a click without moving opens the node form;
  - pan and zoom;
  - snap on and off;
  - wire hover highlight;
  - Arrange with no overlaps (the node rectangles don't intersect);
  - the minimap;
  - Undo after a move and after Arrange;
  - 390 px usable.

  Include screenshots before and after Arrange on a 40-node flow fixture.
- Existing editor tests still pass, plus a test that saving keeps node positions.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes.
- Report the commits.
