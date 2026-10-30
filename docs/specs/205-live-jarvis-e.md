# Spec 205: the Live page as Jarvis E — visual first, almost no text, COD in its own vault

## Why
Spec 200 built comp D (a Jarvis HUD with per-bot notes). The owner, comparing it with a still from the Iron Man film (a glowing globe, radial spokes, translucent panels full of graphics and almost no readable text): "ang daming sulat, ayaw ko madaming sulat", and "yung COD is hiwalay na lugar sana". Comp E was made for this and he asked for it to be built ("pagawa mo na din yung E").

## Design contract
- `docs/design/205-live-jarvis-e/comp-e.html` (+ `comp-e.png` 2D mid-loot, `comp-e-3d.png` curved wall) is the approved comp; `notes.md` section E (what each visual encodes, the table of where every comp-D number went, the motion spec, the 3D recipe, the assumptions marked ASSUMED) is binding; other sections are background.
- Build comp-led on top of spec 200's data and feed (LiveFeed per-bot notes, latest customers, loot rules, 3D toggle remembered per browser). Where the comp and this spec differ, this spec wins.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Layout and look as comp E: slim header (logo, filters behind one funnel icon, clock, 3D, TV); centre globe = the Likha bot core with its one number (signals in the last 5 min); a separate gold VAULT panel (bottom centre) = Order value today (COD) big, orders count small, coin stack; translucent panels on spokes: Bot 1 and Botcake as radars (each chatting customer a dot; new customer = bright blip with a name tag that fades after ~3.5 s; ordered = gold dot; AI answering = thin ring) with only big IN/OUT numbers; Bot 2 dormant = dim wireframe with a power icon; AI = hex grid + one number; Follow-ups = three arcs (top-3 sequences) + one number; Replies, Avg reply and Chats as gauges. A one-row icon ticker replaces the LIVE ACTIVITY text column. | The approved comp. |
| 2 | Loot as comp E: coins pop from the bot where the order happened, fly through the globe and land on the vault; the total rolls up as each lands; "+₱N" small; null amount = one small "+1". Bursts merged, none dropped; never count down within a minute; reduced motion without coins. | Owner. |
| 3 | Names: Facebook name of new customers, first name + initial on the blip tag, shown briefly (no list on screen). No phone, address or message text. | Owner said yes to names; comp E shows them briefly. |
| 4 | Numbers moved off screen (recents "+N in 5 min", last order, inquiries total, per-bot AI/orders/value, sequence names with exact counts, bot full names) appear in a hover/tap card per panel; nothing from spec 199/200's feed is dropped from the data. | Keep the data, cut the text. |
| 5 | Replace the sim shortcuts in the comp with real data: gold dots stay for the 5-minute window; the vault's coin stack scale — use one disc per ₱5,000 and a max stack height (no target); no "same hour yesterday" tick. | Mira's decision; no invented targets. |
| 6 | Performance as 199/200 (no blur/backdrop-filter, ≤ ~30 animated elements in flight, the 5-second poll and counts cache unchanged, new per-customer radar data from one grouped query per poll, measured); the radar needs per-bot chatting customers with status (chatting / AI / ordered) and last-message time — extend LiveFeed once, indexed. Burn-in drift kept. | All-day TV. |
| 7 | Fonts: self-host a condensed face only if it can be done without a download; otherwise system fonts with a defined fallback stack (the TV may not be Windows). | No downloads allowed. |
| 8 | Same code for Likha and Incepxion (site name from settings). | Both sites. |

## Cases (S-205)
P1: .1 every panel of decision 1 renders with its data hooks; .2 radar data per bot (dots = chatting customers in the last 5 min, new/ordered/AI states) equals seeded data, page and bot filters applied, dormant bot empty; .3 loot rules (front-end, tested like 199/200); .4 hover/tap cards carry every moved number (assert the numbers); .5 names: first name + initial only, escaped, nothing private in the JSON or page; .6 query count per poll does not grow with customers; .7 3D: no panel overlaps at the orbit extremes (hit-test as in 200); .8 existing Live tests pass (list changed assertions); .9 full tests/Feature adds no new failures vs 43a4e713.

## Done when
P1 pass; evidence page docs/specs/205-evidence/live.html with real-shaped fake data (loot, blips with name tags, 3D toggle); screenshots 1920x1080 2D and 3D and 390 px; word count on screen reported (target: close to comp E's ~44 tokens); query count/time per poll; deploy notes (no migration expected); result with the final line.

## Out of scope
Changing what is counted (orders, COD, replies), other pages, deploying.

## Budget
Large job. Stages: data (radar per bot) with tests; page; motion/3D; polish. Commit after each passing stage. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
