Amendment 004-1

| Topic | Change | Who decided, why |
|---|---|---|
| Velocity for Scaling | BENTA/ARAW for a Scaling item (normal set) = max(units of the last 7 days ÷ 7, the 003 14-day velocity). Replaces "last 7 days ÷ 7" alone. The lugi set keeps the 14-day velocity. | Mira's decision: a Scaling item with 0 sales in its last 7 days (possible when the rise was in days 8–14) would otherwise drop to a HOLD-only order, which contradicts "rising items get more stock". |
| Done when | Add a test at /item/stock: Scaling, 7-day velocity 0, 14-day velocity 10, HOLD 50, stock 0, incoming 0, lead 7 → normal set uses 10/day, palugit 14, order qty 50 + 10×21 = **260**. The existing 365 case (7-day 15 > 14-day 10) stays 365. | Mira's decision, to pin the amendment. |
