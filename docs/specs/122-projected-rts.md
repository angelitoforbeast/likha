# Brief 122: Projected RTS next to the Set RTS on /owner/private, and a daily check feed

The profit on /owner/private uses the SET RTS % per page+item (`page_item_settings.rts_pct`, edits logged in `page_item_settings_log`). Staff set it by feel; in September 25 of 47 big items were off by more than 5 points (mean gap 9.3). The owner wants the set value checked daily against what the orders really do, including orders still in transit.

A backtest (orders 2026-08-01..09-25, ~97.5k resolved) proved this projection:
- Orders: `macro_output` (PAGE, ITEM_NAME, ts_date, waybill) joined to `from_jnts` on `waybill_number` (unmatched orders are left out and counted).
- States from `from_jnts.status`: Delivered, Returned (= RTS), For Return, In Transit / Delivering. Age = days since `submission_time`. Day-N state history is in `from_jnts.status_logs` (JSON `{from,to,batch_at}`, Manila time).
- For Return ends RTS ~100% → count it as RTS. In Transit/Delivering ends RTS with a probability p(age): pooled ≈ 64% at day 3, 76% day 5, 86% day 7, 95% day 10. Item-specific p is better early.
- Projected RTS% = (Returned + For Return + Σ in-transit × p(age, item)) ÷ (orders with a J&T state). Mean abs error vs final: 2.7 pts at day 3, 1.1 at day 5, 0.5 at day 7. The raw "RTS so far" is 30–40 pts too low early.

## Decisions
| Decision | Why |
|---|---|
| **Learned rates** `chatbot`-free service `RtsProjection`: nightly command `rts:learn` computes p(age) for age 0..14 days from orders resolved in the last 8 weeks (an order's state at age N from status_logs; did it end Returned?). Pooled rates, plus per page+item rates where that item has ≥30 in-transit samples at that age; else pooled. Store the rate table where similar app-wide JSON lives (`app_settings`, check precedent; no new table). Schedule it nightly (quiet hour). | Learns as data grows. |
| **Per page+item numbers** for a date range (default: orders shipped in the last 21 days): orders, matched, delivered, returned, for return, in transit, **Projected RTS%**, **Actual RTS%** (resolved orders only, and only orders ≥14 days since ship), **Set RTS%** (current `page_item_settings`), gap = set − projected, last set by/at (from the log). | The check. |
| **/owner/private**: next to the existing Set RTS show **Projected RTS** (with "n orders, x% still in transit" in the cell title/details) and highlight red when \|set − projected\| > 5 points and matched orders ≥ 30. Reuse the page's existing table and styles; no other change to its numbers. | The owner sees it where he sets it. |
| **Daily feed for Mira**: `GET /api/owner/rts-check?from=&to=` with its own read-only key `RTS_CHECK_KEY` (env file, `hash_equals`, 401 otherwise, throttled), returning the per page+item rows above where matched ≥ 30, flagged rows first. No customer data. | Mira messages the owner daily. |
| No migration unless unavoidable (then nullable only, and say so). | Lean. |

## Done when
- Tests (sqlite, synthetic orders + from_jnts with status_logs): `rts:learn` produces p(age) from resolved history (For Return counted as RTS; item rate used only with ≥30 samples); projection math on a known fixture (e.g. 24 returned + 25 delivered + 51 in transit at p=0.61 → ≈55%); actual RTS ignores unresolved and too-young orders; set RTS and last-set-by come from settings/log; the feed needs the key and flags |gap|>5 with n≥30; /owner/private renders the Projected RTS cell and the red flag.
- `vendor/bin/phpunit` for the folders you touch plus `tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` pass.
- Result: files, schedule, config keys, feed contract, commits, and a sample of the feed output from the fixture.
