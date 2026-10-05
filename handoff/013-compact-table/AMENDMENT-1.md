Amendment 013-1

Measured in compact state, zoom 1, by Mira on 2026-10-05 (numbers are px):
- Container 1660. Table natural width 1929. So the zoom still has to be 0.86 (before 013 it was 0.76).
- Row height: 93 to 107, average 104. Before 013 it was 115. So almost no gain in rows per screen.
- What sets the row height, as the summed height of each cell's children over the 42 rows: `rts_set` 89 to 102 (average 100); `claude_action` 61; `action` 32 to 61; page cell 23 to 59; `jnt_rdt` 47; `item_val` 32; everything else 16 to 18. The `rts_set` cell became tall because its column was narrowed to 71 px and its small note lines now wrap.
- Column widths now: promo 148, price 51, np_per_order_1m 55, adspent 88, orders_1d 44, proj_prof_1d 71, item_val 69, item_val_ceo 69, cpp 51, rts_set 71, jnt_rdt 109, tcpr 48, breakeven_cpp 61, proj_profit 78, the four proj_pct columns 48 each, hold 37, action 135, claude_action 135, ceo_action 135; the page, item and expander columns together 282.
- Cell padding 2px 4px, body font 12px, header font 10px, header height 32.

| Change | Who | Why |
|---|---|---|
| In compact state the `rts_set` cell is at most 34 px high: the percentage on the first line; the "from <date>" note and the "estimated / updated" note together on one second line, no wrapping, cut with an ellipsis, full text in the title. Its column may be up to 95 px wide for that. | Mira's decision | It alone makes every row about 100 px high; R1a says a row is no taller than the three-line block plus a small padding |
| After that, no cell other than the text columns and the page cell may be taller than the `jnt_rdt` block (47). Target row height: 70 or less when a text cell shows three lines and its author line, about 50 when it does not. | Mira's decision | R1a |
| Table natural width at a 1707 px viewport with this column set: 1730 or less (zoom 0.96 or better), with the three text columns staying at 135 or wider. Suggested targets, adjust as the content needs: promo 100 (wraps to two lines), adspent 72, proj_prof_1d 62, item_val 58, item_val_ceo 58, jnt_rdt 95, proj_profit 68, breakeven_cpp 52, the four proj_pct 44 each, tcpr 44, price 46, np_per_order_1m 48, cpp 46, orders_1d 40, hold 34, page plus item plus expander 230 (page name and item name wrap to two lines; the small "mixed primary" and "computed since" notes stay on one line each, cut with an ellipsis, full text in the title). | Mira's decision | The owner's words: too much space, text not readable; a zoom of 0.86 still shrinks the text |
| Set explicit widths per `data-col` in compact state (width or max-width with `table-layout` left as it is, or a `colgroup`, your call) so the number columns cannot take a share of spare width. Spare width goes to the text columns only. | Mira's decision | The verifier's minor finding: auto layout shares spare width in proportion to content |
| Everything else in handoff 013 stays: old layout untouched in the other state of the switch, Actions view, no data change, CEO-only button, no settings write. | Mira's decision | Unchanged scope |

Done when (added):
8. RESULT.md, section "Amendment 013-1": the new per-column width table and the arithmetic to a total of 1730 or less; the CSS rule for `rts_set` with file and line; the expected row heights.
9. The tests still pass: `artisan test --filter=FitToWidth` and the full suite adds no new failures against 514 passed, 3 skipped, 1 failed (show both summary lines). Add a test that the compact CSS holds the `rts_set` rule and explicit widths.
10. `git diff --stat 5f9de2e..HEAD` shows only the two owner views, the test, and the handoff folder. The old-layout state is still untouched (say how you checked).
11. No commit message carries an attribution line. No push, no PR.
You still have no browser; say so. End with the branch head.
