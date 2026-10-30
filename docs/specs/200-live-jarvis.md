# Spec 200: the Live page as a Jarvis HUD, with notes and latest customers per bot

## Why
Spec 199 shipped /chatbot/live as a "neural network" TV scoreboard. The owner's verdict: it looks childish ("parang bata gumawa"). He asked for a real Jarvis look, a map that always moves, a 3D button, money that drops like game loot into the total, IN and OUT per bot instead of a separate "Customer inquiries" node, and Jarvis-style notes beside every bot that include the customers that bot is talking to. Comp D was made for this and he approved it ("gawin mo na ang jarvis").

## Design contract
- `docs/design/200-live-jarvis/comp-d.html` (+ `comp-d.png` 2D mid-loot, `comp-d-3d.png` 3D) is the approved comp: the live page must look like it at 1920x1080 and play the same motion. `notes.md` section D (pitch, the 8 named motion moments, idle motion, burn-in drift, the 3D recipe, the 390 px collapse) is binding; sections A–C are background only.
- Build comp-led with the impeccable skill and run its polish pass before filling the result. Where the comp and this spec differ, this spec wins.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Layout and look as comp D: header (LIKHA LIVE, live dot, page filter, bot filter, Manila clock, 3D/2D toggle, TV mode), KPI row (Replies today, Avg reply time · Bot 1, Orders today, Active chats), the gold reactor core showing Order value today (COD) with orders count, "+N in the last 5 min" and "Last: <page> · time · bot", rotating segmented rings, scan band, 5-minute tick dial; satellites: Inquiries received today (small stat), AI answers, Follow-ups sent with the top-3 sequences, Likha bot signals in the last 5 min; bot hubs Bot 1 · Forbeast, Bot 2 · Incepxionbot (not linked = DORMANT, dashed and dim, never red), Botcake · Fallback, each with IN and OUT and two-way packets on two lines to the core; a NOTES panel per bot joined by an angled leader line; LIVE ACTIVITY timeline on the right. Two colours only: ice blue and gold. | The approved comp. |
| 2 | Per-bot NOTES, all real data for today (Manila day), page filter applied: chatting now (distinct customers, page + PSID, who messaged that bot in the last 5 min), AI answered (that bot), avg reply (Bot 1 only, from the existing Forbeast reply time; others show "—"), orders today and order value (COD) from that bot (same COD rule and join as spec 199), last activity (type · page name · time), and LATEST CUSTOMERS: the 4 most recent distinct customers of that bot today, newest first, each with name, a tag (ORDERED if that customer has an order event today on that bot, else AI if the bot's last reply to them was an AI answer, else CHATTING) and the time of their last event. A dormant bot shows dim notes with "none while not linked". | The owner wants Jarvis notes per bot with its customers. |
| 3 | Customer names ARE shown in the latest-customers list (the owner said yes). Find the most reliable stored customer name for a page + PSID (e.g. what Botcake/Forbeast/chat tables or the encoded order hold), write the source under Rulings with why, and test it. No name stored: show "Customer" plus the page's short name, never the PSID. Never show phone numbers, addresses or message text anywhere. Names are escaped text. | Owner's word; the rest of the privacy rule stays. |
| 4 | Money moment = loot drop as in comp D: an order with an amount bursts coins from the bot hub, they arc into the reactor and the total rolls up as each lands, a small "+₱N" label, a gold shock ring; an order with null amount drops one small "+1" plate and leaves the peso total alone. Bursts merged, none dropped; numbers never count down inside a minute; reduced motion shows the new values without coins. Reuse/extend `public/js/chatbot-live-rules.js` (pure functions, tested like 199). | Owner: "icon na pera, yung parang naglo-loot". |
| 5 | The network always moves: streaks on every live link, breathing status dots (pulse once per successful poll rather than endlessly, per the comp's detector note), the 5-minute ring ticks, the scan band sweeps. Dormant links stay still. | Owner: "make sure gumagalaw yung neural map". |
| 6 | 3D toggle: CSS 3D as in comp D (perspective, layered depths, slow orbit), header/KPIs/activity stay flat; the choice is remembered per browser (localStorage, wrapped in try/catch); 2D is the default. | Owner asked for a 3D button. |
| 7 | Everything spec 199 shows and its feed contract stay (no number dropped; the separate "Customer inquiries" node becomes the small "Inquiries received today" stat); spec-199 tests keep passing (update only selectors the redesign moved and list every changed assertion under Story changes). | Narrow redesign plus the new per-bot data. |
| 8 | Performance as 199: no filter: blur, no backdrop-filter, ≤ ~30 animated elements in flight, 5-second poll and counts cache unchanged; the new per-bot numbers and latest customers are read once per poll with grouped, indexed queries (no per-event or per-customer query); measure query count and time per poll and put them in the result. Burn-in drift kept. | All-day office TV. |
| 9 | Same codebase for Likha and Incepxion (site name from existing settings, e.g. INCEPXION LIVE / INCEPXION BOT). | Releases go to both. |

## Cases (S-200)
P1:
- .1 per-bot notes numbers (chatting now, AI answered, orders today, order value, last activity) equal hand-counted values on seeded events for each bot; page filter and bot filter applied; a dormant bot returns zeros.
- .2 latest customers: 4 most recent distinct customers per bot, newest first, correct tag (ORDERED > AI > CHATTING), time of last event; a customer active on two bots appears under each; another day's customers never appear.
- .3 names: the stored name is shown when present; when absent, "Customer · <page short name>"; no PSID, phone, address or message text in the feed JSON or the page (assert on the response body).
- .4 the feed's query count per poll does not grow with the number of events or customers.
- .5 front-end rules: loot burst with amount (coins, +₱N, roll-up), "+1" plate with null, merged bursts none dropped, no count-down inside a minute, reduced motion without coins; 3D toggle state saved and restored, default 2D.
- .6 the page renders every section of decision 1 with its data hooks, and every number spec 199 shows is still there.
- .7 existing spec-199/196 Live tests pass (changed assertions listed).
- .8 the full tests/Feature suite adds no new failures vs 8506b25c.

## Threat model and risk
Trusted: signed-in staff who may open the Live page (same permission as today). Untrusted: query parameters (page_id, bot, cursors), page names and customer names (shown as escaped text). Risk tier: medium (customer names on a shared screen, new reads on busy tables).

## Done when
All P1 cases pass with tests; no new suite failures; the result has a rendered evidence page `docs/specs/200-evidence/live.html` with real-shaped fake data that opens on its own and plays the loot, the notes and the 3D toggle; screenshots at 1920x1080 (2D and 3D) and 390 px; the measured query count and time per poll; the name-source ruling; deploy notes (migrations: an index only if needed, say so); the cases table; and the final line.

## Out of scope
Changing how orders, COD or replies are counted; editing any data; new pages; deploying.

## Budget
Large job. Stages: data (per-bot notes, latest customers, names) with tests; then the page; then motion/3D; then polish. Commit after every passing stage. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
