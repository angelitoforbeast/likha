# 307 result: "Facebook User" is not a customer

Base: origin/main f964a6eb. Branch `feat/307-facebook-user-not-a-key`.

## Plan

Base f964a6eb: full suite once before any code (Herd PHP, `-d memory_limit=4G`), to have the base count.

1. **One constant.** New `app/Services/PlaceholderName.php`: `NAMES` (the placeholder names) and `is(?string)`, true for an empty name and for any listed name in any case, with any spacing (spaces, tabs, no-break and zero-width characters are ignored, so "facebook  user" and "FacebookUser" count). No other placeholder exists in the code or tests; the data could not be checked from here (no server access), so the list is the names Meta shows for a hidden profile: Facebook User, Messenger User, Instagram User.
2. **FU-1 / FU-2, `PancakeChat::forOrder`.** PSID step unchanged. After it: a PSID was given → return null (no name fallback). No PSID and a placeholder name → return null. No PSID and a real name → page + name, then name only, as today. Astra's EARLIER CONVERSATION, its `get_chat_history` tool and MacroChecker's pass 2 all read through `forOrder` already (spec 131 amendment 1), so they follow without their own change; tests pin that.
3. **FU-3, the other customer keys.**
   - `OrderIdentity::find`: a placeholder name skips the name rule and falls to phone, then identical input, as an empty name. Its callers compute the key phone only when the name is empty today, so `ImportLikhaFromGoogleSheet::importRow`, `ImportMacroFromGoogleSheet` and `LiveOrder::values` (the encode mirror of the Likha import) use `PlaceholderName::is` for that test and for the "No name / no phone" flag.
   - `LiveOrder::find`: no "empty PSID + same fb_name" OR branch for a placeholder; no PSID and a placeholder name → no customer found (new row), as for an empty name.
   - `BotcakePsidMatcher`: `orderQuery` (exact and case-insensitive steps) finds no orders for a placeholder; a sheet record with a placeholder name becomes `conflict` instead of being attached; `copyReorders` skips placeholder customers.
   - `BotcakeCustomerMatcher::matchPage` (the Botcake API customer match, also by name): placeholder names are no name on both sides, so such an order goes to the phone rule like a nameless one.
   - Read, left as is, listed in the result: the "See more" `has_pancake` flag, `/pancake/retrieve2`, `/pancake/retrieve-orders`.
4. **FU-4.** End-to-end test with a fake model: the 18/11 order, Mercedita's "Facebook User" conversation on the page, no conversation for the order's PSID. Assert no request to the model carries "Mercedita" (prompt and the `get_chat_history` tool output) and the row keeps its own phone.
5. **FU-5.** `orders:placeholder-mixups --from= --to= [--page=]`, `app/Console/Commands/PlaceholderMixups.php`, SELECT only. Pass 1 per day: id, page, day, fb_name, FULL NAME, PHONE NUMBER of every row in range. A row with a placeholder fb_name is a suspect for a field when another row on the same page and day has the same FULL NAME (normalized, not a placeholder) or the same phone (normalized PH mobile). Pass 2 loads the text of the suspects and their candidates only: reported when the value is not in the suspect's own all_user_input or its CXD without ASTRA blocks (Astra's block holds the copied value). "Came from" = the candidate whose own text has the value, else the lowest id. Prints id, page, day, field, came-from id, and counts; never names, phones or addresses.
6. Tests per H/N case, the 131 lookup test that pinned the old PSID → page + name fallback updated to the new rule, targeted runs, full suite against the base count.

## What shipped

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
