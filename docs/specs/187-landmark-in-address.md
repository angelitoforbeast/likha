# Brief 187: the customer's landmark always reaches the order address (encode order and Astra)

## Why
Staff report many orders with no landmark in the address. Data (Likha, ts_date 2026-10-28): about a third of customers give none ("Landmark: N/A", "[Not provided]", "Wala pong nabanggit"), which is fine. But when the customer gives one, the encode order (App\Services\OrderEncode\Encoder, STEP 3 "extractAddressLine1WithLandmark": one AI prompt "Extract Address Line 1 including landmark…") keeps it in ADDRESS for only about 575 of 794 orders by exact match; some are reworded, some are dropped (e.g. "Landmark: Malapit sa Brgy. Hall" → ADDRESS "Pk. 17, Zone 7,"). Astra already has a rule (App\Services\AstraAddressRules, the address composer near line 640: house_number, purok_sitio, address deduplicated, then `near <landmark>` appended when the landmark is not already inside, capped at 250 chars).

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **One shared rule** (a small class, e.g. `App\Services\AddressLandmark`) taken from Astra's existing logic: clean the landmark (strip leading "near / malapit sa / sa may / tapat ng / …" as Astra does), treat placeholders as no landmark (N/A, NA, none, wala, not provided, "[…]" / "(…)" bracketed notes, "-", and their Tagalog variants like "wala pong nabanggit / na-mention / nakalagay", "hindi pa naibigay"), and if the cleaned landmark is not already contained in the address (Astra's normalized contains check, both directions as Astra does), append `near <landmark>`; cap to the length the order/J&T path allows (Astra uses 250; check the J&T payload builder's address limit and use the smaller). Astra calls the shared rule (same behaviour as today, its tests unchanged). | One rule, both paths. |
| 2 | **Encode order applies it** after the AI address line (STEP 3), using the landmark from the customer's form (`Landmark:` line in CXD; Parse may need a `landmark` field). The AI prompt is not changed. Logged as a step (e.g. `landmark_added` / `landmark_kept` / `no_landmark`) so it shows in the encode's steps. Applies to new encodes only; no backfill of old orders. | Rule-based, not left to the model. |
| 3 | Staff-edited addresses (edited_address / the staff-edit protection in LiveOrder) are not overwritten — the existing protection decides as today. | Don't undo people. |
| 4 | No change to province/city/barangay, phone, J&T upload or checker, Validate rules. | Safe. |

## Cases (S-187)
P1: .1 encode with "Landmark: Malapit sa Brgy. Hall" and an AI address "Pk. 17, Zone 7," → ADDRESS "Pk. 17, Zone 7, near Brgy. Hall"; .2 landmark already in the address (any case/spacing) → not repeated; .3 placeholders (N/A, [Not provided], (Wala pong na-mention), Wala pong nabanggit, -) → nothing added; .4 a long address + landmark is capped without cutting mid-word if possible and never exceeds the limit; .5 Astra's existing address tests pass unchanged and Astra uses the shared class; .6 a staff-edited address is not changed by a re-encode; .7 existing OrderEncode, NightRun (Astra/Validate), Jnt and Orders suites pass.

## Done when
P1 pass; `tests/Feature/OrderEncode tests/Feature/NightRun tests/Feature/Jnt tests/Feature/Orders` add no new failures vs e919bec5 (record the baseline). Result with the placeholder list, the length limit chosen and why, deploy notes, and that it applies to Incepxion. End with "187: <done|blocked> — <one line>".
