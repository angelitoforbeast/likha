# Result 013: the Daily Summary table on owner/private becomes truly compact

Status: **in progress** (branch `feat/013-compact-table`, cut from `617fb34`).

## Plan

Headless run: nobody could give the "go", so this plan was committed before any code and then built (see Rulings, 1). It is also the spec the developer and the reviewer worked from; done-when 1 allows no file under `docs/`.

Tier: **medium** (UI screen, no new way in for untrusted input). One task, side `frontend`: `frontend-developer`, then `skeptic-reviewer` at standard depth.

### Design

All compact rules live in `resources/views/owner/_fit_to_width.blade.php` (the partial 011 added: its `<style>` and its plain script). `private.blade.php` gets only what CSS cannot do alone.

1. **One switch, two states.** `#owFitSwitch` and the key `owFitMode` stay. State `fit` (default, anything that is not exactly `full`) is now "Compact": the script puts the class `ow-compact` on the `[data-ow-fit]` card, then measures and zooms only what is still too wide, as 011 does. Label `↔ Compact NN%` (100% when no zoom is needed). State `full` is untouched 011 behaviour: no class, no zoom, label `↔ 100%`, horizontal scroll.
2. **Cells get a column id only in compact.** The script sets `window.__owCompact` while the page is parsed and fires a window event `ow-fit-mode` on every change. The Alpine component keeps a flag `owCompact` (read from that global, updated by the event). The four `x-for="col in cols"` cells (main `th`, repeated `th` of an expanded section, body `td`, total `td`) get `:data-col="owCompact ? col.id : null"`. Alpine removes an attribute bound to `null`, so in the old layout the cells carry no new attribute and no rule can match them.
3. **CSS, all under `.ow-compact > table > …` with child combinators** (so the nested RTS table and the campaigns panel are not touched):
   - body cells: padding `2px 5px`, 12px; header cells: padding `4px`, 10px, no letter-spacing, `white-space:normal`, `min-width:0 !important` over the inline `minw`;
   - Page and Item keep a floor (`min-width` 120 and 130 px over the inline 110 and 160); Promo wraps with a floor of 80 px;
   - the RTS block's inner cells: padding `0 4px`, line-height 1.2;
   - text columns `action`, `claude_action`, `claude_reason`, `ceo_action`, `ceo_reason`: header floor 150 px (reasons 170 px), cell `white-space:normal`; the collapsed text `div` (the one whose bound style holds `ellipsis`) becomes a three-line clamp with `max-width:none !important`; the opened one gets `max-width:none !important`; the author line stays one line (`inline-block`, ellipsis) so the existing "more" button sits beside it. The originals stay in the file.
   - With `table-layout:auto` the number columns end at their content width and the text columns, whose max-content is a whole sentence, take what is left.
4. **Header wrap.** Labels such as `Prof.%(1M)` have no space. A small method `hdr(label)` returns the label unchanged in the old layout and, in compact, with a zero-width space before `(`; the two header `x-text` use it. Same text, one more break point.
5. **Actions view (R4).** Button `#owActionsBtn` in the partial, rendered only under `$effectiveIsCEO` (actual CEO in CEO view: the condition of the Claude and CEO columns). The script toggles the class `ow-actions` on the card and keeps `owActionsView` in localStorage. CSS hides every `[data-col]` cell except `cpp`, `proj_pct`, `proj_pct_1d`, `proj_pct_3d`, `proj_pct_7d`, `hold` and the five text columns; Page and Item are fixed cells without `data-col`, so they stay. `cols` is never changed, so sorting, drag and `saveCols()` see the full list and nothing is written to the column settings. In the old-layout state the button is disabled and the class is off.
6. **R3 (merge): skipped,** reasons under Rulings.
7. **Tests first** (`tests/Feature/OwnerPrivate/FitToWidthTest.php`, extended): CEO page 200 with the switch, the Actions button and the compact rules; Actions button absent for Marketing and for a CEO in Marketing view; `data-col` bound only through `owCompact`; no `x-html` or `innerHTML` in the partial.
8. Full suite once at the end, evidence below, no push, no PR.

## Summary

Pending.

## What changed

Pending.

## Evidence per done-when item

Pending.

## Rulings

Pending.

## Deploy steps for Mira

Pending.

## What a browser check must look at

Pending.

## Proposed tasks

Pending.

## Suggestions for Mira

Pending.
