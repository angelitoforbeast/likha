# Spec 203: Mira RTS counts only the orders at the item's latest price

## Why
Mira RTS (spec 197; App\Services\AnalystRtsDaily, App\Services\RtsProjection, command rts:daily-estimate at 06:00) estimates the final RTS% per page + item from all orders since the first day of the previous month, mixing every price the item was sold at (e.g. Alyssa Aquino · Vintage Curtain: 2,086 orders since Sep 1, ₱199 and other promos together). The owner: "yes, dapat, kung ano yung latest, yun lang yung i-compute" — compute it only for the latest price.

## Stories (S-203)
P1
- .1 For each page + item, find its LATEST price: the selling price of its newest order (decide the most reliable field — the order's price or COD per quantity, the promo/price text the owner page shows in its PRICE column, or the item setting; read the code and data model, write the ruling with why, and test it). Mira RTS (projected RTS, in-transit share, the estimate, the tooltip numbers, and the 7/14-day accuracy check) uses only that page+item's orders at the latest price within the existing date range.
- .2 When the latest price has too few matched orders (below the existing MIN_MATCHED_FLAG = 30, or the rule the code uses for "enough"), say so instead of guessing: the tooltip says "Latest price ₱X: only N orders, not enough yet" and the cell shows the existing no-estimate state. Never silently fall back to all prices.
- .3 The tooltip names the price, e.g. "Mira RTS 67.0% (₱199 orders only): Auto 06:00: N order na may J&T status …".
- .4 The learned p(age) return rates used for in-transit orders stay per item as today (they describe delivery behaviour, not price); write that under Rulings, or change it only if the code shows a reason.
- .5 Day rows and accuracy records written before this change stay as they are; new day rows record which price they used.
- .6 `rts:daily-estimate --as-of` replay works with the latest price as known on that day.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Only the latest price's orders count. | The owner. |
| 2 | No silent fallback to all prices when there are too few orders. | A wrong number is worse than "not enough yet". |
| 3 | No new table (keep the existing rts_day rows / app_settings pattern); an index only if a measured query needs it. | Lean schema. |

## Threat model and risk
Trusted: signed-in owner/staff with access to the owner pages. No new inputs. Risk tier: medium (a number the owner acts on for ads).

## Cases to test
Seeded orders of one page+item at two prices over time: the estimate uses only the newest price; too few at the newest price gives the "not enough yet" state; tooltip text; ordering by the newest order; another page with the same item stays separate; --as-of replay; existing spec-197 tests kept (list changed assertions under Story changes).

## Done when
P1 cases pass; the full tests/Feature suite adds no new failures vs 8506b25c; the result has the price-source ruling, a before/after example for a seeded item, deploy notes (no migration expected; the next 06:00 run picks it up; whether a manual run is needed) and the final line.

## Out of scope
Changing the Set RTS column, CPP or profit columns, or ads decisions; deploying.

## Budget
Small-medium job. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
