# Brief 060: Chatblast pages keep the header fixed and scroll only the data

**Branch.** This worktree is a new branch `fix/060-chatblast-sticky-header`, created from `main` at b8ac9c02 (live).

**Rules.**
- Never push. Touch no remote hosts.
- No attribution lines.
- All UI text is English.
- No horizontal scroll at 1366 or 1280 px.

**Deliverables.**
- Save this brief as `docs/specs/060-chatblast-sticky-header.md` in your first commit.
- Save the result as `docs/specs/060-chatblast-sticky-header.result.md`.
- Aim at about 45 minutes. Do a quick self-review before the result.

## Why

The owner said: "tsaka yung mga sticky ayusin mo, ampanget talaga". Amendment 6 of spec 056 read this as "remove sticky" and made the pages scroll as plain documents. The owner corrected it: "ha? need nga may sticky, yung data lang mag-scroll". He wants the sticky parts kept, but looking good.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| On the five Chatblast pages (To send `/chatblast`, History `/chatblast/history`, Messages `/chatblast/campaigns`, Sequences `/chatblast/sequence`, Page settings `/chatblast/page-settings`), these stay visible while scrolling: the **app top bar**, the **page header with its filters/chips/action buttons**, and the **table's column header row**. Only the table rows (the data) scroll. The simplest robust way: the page is a full-height column, the top parts are fixed-height, and the table area is the only scroll container (`overflow-y: auto`) with `position: sticky; top: 0` on its `thead`. | The owner |
| **Clean styling of the fixed parts.** White background, one thin bottom border (gray-200), no coloured band, no heavy shadow; at most a very light shadow under the header only once the rows have scrolled. Column headers in small, medium-weight gray text. | "ampanget" was the styling |
| **Pages without a table:** on Messages and Sequences (cards), keep the top bar and the page header with its filters fixed, and let the content scroll under them. | Same behaviour everywhere |
| **Keep everything else from amendment 6:** row details in a modal, no Log out, the button, chip and table styles. The modal stays a modal. There is still no horizontal page scroll; if a table is wider than the screen, horizontal scrolling stays inside the table area only (this should not happen at 1366 px). | Keep what he liked |
| **Browser check** at 1366 and 1280 px on all five pages: scroll the data and confirm the header and controls stay; the modal still opens. Save screenshots to `handoff/060-chatblast-sticky-header/screens/` in the main checkout. Run the Chatblast and PageSettings tests. | Evidence |

**Note for the merge.** Another branch, feat/057, also changes `resources/views/page_settings/index.blade.php`. Keep your changes to page_settings limited to the layout and scroll-container wrapper and the thead styling, so the merge stays easy.
