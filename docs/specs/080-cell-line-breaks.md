Small task: spec 080, about 30 minutes.

**Branch.** In this worktree, run `git checkout -b fix/080-cell-line-breaks main`. Main is da59da93, which is live.

**Rules**
- Never push. Never touch remote hosts.
- No attribution lines.
- Save this brief as `docs/specs/080-cell-line-breaks.md`, and the result as `docs/specs/080-cell-line-breaks.result.md`.
- Do a headless browser check at 1366 and 390 px, with screenshots.

# Brief 080: botfield cells on /chatbot/pages keep line breaks and fold to 3 lines

## Why

On `/chatbot/pages` (`resources/views/chatbot/index.blade.php`), long botfield values (Main Flow, Fill-Up Form, Loop 3 Form, Prompts, Seq N) show as one run-on line with no line breaks. The owner, 2026-10-19: "ayusin mo naman breakline dito … lines lang kita, may see more lang, pag cnlick maggexpand, pero may limit yung height".

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Line breaks.** A text cell shows the value with its line breaks kept (`white-space: pre-line` or equivalent), and long words wrap. | The owner. |
| **Collapsed.** A cell shows at most **3 lines**. When the text is longer, a small "See more" link shows under it. | The owner. |
| **Expanded.** "See more" expands that cell in place up to a max height of about 240 px, scrolling inside when the text is longer. The link becomes "See less". Expanding one cell doesn't expand the others. Clicking the text itself still opens the editor, as now; the link must not open it. | The owner: expands, but with a height limit. |
| **Unchanged.** Image cells, the switch, FLOW and the sticky columns. There is no page-level horizontal scroll, and the row height grows only with expanded cells. | — |
| **The modal editor stays the place to edit.** Keep its textarea showing line breaks as typed. | — |

## Done when

- The browser check shows a multi-line Main Flow:
  - collapsed to 3 lines with "See more";
  - expanded with an inner scroll;
  - back to collapsed with "See less";
  - at phone width too.

  Screenshots go in `docs/specs/080-cell-line-breaks/`.
- `php artisan test tests/Feature/Chatblast` passes.
- Report the commits.
