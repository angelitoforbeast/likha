# Brief 144: the encoder no longer holds possible duplicates (Validate catches them)

## Why
Rule R5 (spec 135; `app/Services/OrderEncode/Runner.php` ~line 149 `duplicates()`, `Duplicates.php`) holds an order as "Possible duplicate: <ids>" when the same phone (or name + barangay + city) already has an order that day. On 2026-10-25 the owner's test order on Joy Villaluna (row 1058245) encoded the right address but stayed unproceeded because of R5, and Astra then ran on it for nothing. The owner: "wala ngang paki sa duplicate diba? gagamitin yung validation button, required magvalidate" — duplicates are the Validate button's job, which staff always run.

## Decisions
| Decision | Why |
|---|---|
| The encode never holds for R5: no "Possible duplicate" hold, so a duplicate order with a good address PROCEEDs like any other. Add an app setting `order_encode_duplicate_hold` (on/off, default **off**, next to `order_encode_rules`, CEO only, shown on /order-encode/final) so it can be switched back without code. When off, still record the found ids as a step note (e.g. `duplicates_seen`) in the encode's step log, never as a hold. Applies to every encode mode (live, flow blocks, legacy block, import/shadow, backtests) through the same Runner path. | Duplicates are caught by Validate. |
| Astra on the spot / RunEncodeAstra is not queued when the encode's only blocker was R5 — that case no longer exists with the setting off; with the setting on, skip Astra for an R5-only hold (Astra cannot fix a duplicate). | No wasted Astra calls. |
| `order_hold_reason` / FlowEncode hold mapping keep `duplicate` for when the setting is on. Story updates: amend S-135.15/.16, S-136.8/.30-style duplicate cases to the new default; list them under "Story changes". | Keep stories true. |

## Done when
- Tests: with the setting off (default) a same-phone second order with a valid address PROCEEDs and the step log notes the duplicate ids; with it on, R5 holds as before and Astra is not queued for an R5-only hold; the setting is CEO-only and shown.
- `php vendor\phpunit\phpunit\phpunit tests/Feature/OrderEncode tests/Feature/NightRun tests/Feature/Chatblast` adds no new failures.
- Result: files, setting key/default, story changes, commits. End with "144: <done|blocked> — <one line>".

## Out of scope
Changing Validate; deploying.
