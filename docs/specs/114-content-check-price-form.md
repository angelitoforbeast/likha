# 114: the content check compares the full price and checks the Fill-Up Form for its fields, not for an item

Live on 2026-10-22 the Check column showed two false alarms:
1. "Main Flow 2: price ₱199 + … ≠ orders ₱299 COD" while the Main Flow lists Item Price ₱199, Shipping ₱60, Insurance ₱40 = ₱299 (the check compared only the item price).
2. "Fill-Up Form: maybe ANOTHER ITEM … (0%)" while the Fill-Up Form names no item by design.

## Decisions
- Price: compare the order COD with the total the text promises: the stated total, or item price + all stated fees (shipping, insurance, other). Flag only when no reading matches the COD (±₱1). Sum in PHP; the AI answer returns `{item_price, fees:[...], total}`. Re-ask only texts whose cached answer lacks the new shape (cache version bump).
- Fill-Up Form: no item check. Plain, case-insensitive text match (no AI) for Full Name (Pangalan), Phone/Cellphone Number, Complete Address, Landmark. Missing ones warn "Fill-Up Form: walang Landmark". Quantity is optional.
- Everything else of the page content check stays.

## Done when
- Tests: ₱199 + ₱60 + ₱40 vs ₱299 COD passes; ₱199 alone vs ₱299 flags; stated total ₱299 passes; Fill-Up Form with all four fields passes with no AI call; missing Landmark warns; old cached answers without the new fields are re-asked once.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes.
