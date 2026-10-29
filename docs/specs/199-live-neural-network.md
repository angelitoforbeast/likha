# Brief 199: the Live page as a neural network room, with the order money flowing in

## Why
/chatbot/live (spec 196) is the scoreboard the office keeps on a TV. The owner wants it to look and feel like a living neural network (a calm "Jarvis" holographic room), close to a reference he chose, and "all out" on the design: inputs streaming into the Likha bot core, the core routing out to each bot, AI answers as their own step, and money visibly flowing in when an order comes: the totals count up and a peso amount travels into them. A design brief and an approved comp were made for this and are the design contract.

## Design contract (committed in your first commit)
- `docs/design/199-live/brief.md`: the design brief. Sections 3 (layout), 4 (data mapping), 5 (which event triggers which animation, and the sync rules), 6–11 (colour, type, motion, TV readability, phone, states, buildability) are binding.
- `docs/design/199-live/comp.html` (+ `comp-1920.png`, `comp-390.png`): the approved comp. Build comp-led: the live page must look like the comp at 1920x1080 and follow its phone layout at 390 px. Use the impeccable skill for the build and run its polish pass before filling the result.
- Where the brief and this spec differ, this spec wins.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | **Look and layout as the comp**: header (LIKHA LIVE, live dot, page filter, bot filter, Manila clock, TV mode), KPI strip (Replies today, Avg reply time, Orders today, Active chats, each with "+N in the last 5 min" where the brief says), the NEURAL NETWORK panel (Customer inquiries and Follow-up sequences flowing into the LIKHA BOT core with its 5-minute tick ring and "signals · last 5 min"; AI ANSWERS as its own pink satellite node with its own link and traffic; out to Bot 1 · Forbeast, Bot 2 · Incepxionbot and Botcake · Fallback, each its own hub with name, status, replies sent and messages in; a not-linked bot is DORMANT, dashed, never red), bottom strip (Follow-ups sent, Orders recorded, Order value today), and the LIVE ACTIVITY timeline on the right. Fixed 1920x1080 canvas scaled to the screen; phone layout as the comp. | The owner's reference and his yes to the comp. |
| 2 | **Order amount = the COD of the encoded order.** Each `order` event the feed returns carries `amount` (pesos, number, or null): the COD of the order the encode produced for that customer (same page + PSID) from that chat (the encode run that follows the order, within 24 h). Find the most reliable join in the code (run id, chat/customer id, macro row written by Upload to macro / Encode order), write it under Rulings with why, and test it. `counts.order_value = {today, recent}` is the sum of the known amounts of today's / the last 5 minutes' order events. The amount is resolved when the feed is read, so an order whose encode finishes later gets its amount on a later poll (the total then rolls up; no second money packet). No COD yet or no encoded order: `amount` null, the money packet shows "+1 ORDER", nothing is added to the peso total. Never invent or estimate a peso. | The owner: "Gamitin ang COD ng na-encode na order bilang halaga sa Live page." |
| 3 | **Money animation** exactly as brief section 5 (gold packet bot hub → core → Orders node, "+₱N" chip, Orders today / Orders recorded / Order value today count up, gold glow, floating "+₱N"; bursts merged, never dropped; never count down inside a minute; reduced-motion fallback). Counts everywhere roll up to new values instead of jumping. | The owner: totals grow when an order comes, and money has its own animation. |
| 4 | **Follow-up sequences**: replace "Seq 1/2/3" with today's top 3 sequences by follow-ups sent, real names with counts (one grouped query, cached like the other counts). Fewer than 3: show what exists. | Every label must be real data. |
| 5 | **Bot filter**: a `bot` parameter (all, forbeast, incepxionbot, botcake) that narrows the per-bot in/out counts and the events by the bot that delivered them, like the page filter does by page. | The comp's "All bots" control must work. |
| 6 | **Everything spec 196 shows stays** (no number dropped): unknown inbound/outbound notes, reply-time states (ok / none / not linked / not on Forbeast / error), signed-out and error states, the burst thinning and the "never drop an order" rule, the Exit TV mode link. | Narrow redesign, not a data cut. |
| 7 | **Performance on an all-day TV**: no `filter: blur`, no `backdrop-filter`; at most about 30 animated packets alive; ambient motion in CSS/SVG; the 5-second poll and the counts cache stay as they are; the new amount and sequence reads add no per-event query (one query per poll each, indexed). A slow subtle drift of the whole canvas every few minutes to avoid burn-in on the TV. | The page runs all day. |
| 8 | **Fonts and libraries**: self-host Saira and IBM Plex Mono (open licence) under `public/`, or fall back to a system font if self-hosting is not possible without a download; no new JS framework, no CDN dependency. | No outside dependency for a TV page. |
| 9 | Same codebase runs on two sites (Likha and Incepxion); build it once and keep anything site-specific behind existing settings. | Releases go to both. |
| 10 | Privacy as today: page names and bot names only on screen; no customer names, phone numbers, addresses or message text. | Shown on an office TV. |

## Cases (S-199)
P1:
- .1 an order event whose encoded order has a COD returns that `amount`; one without an encoded order, or without a COD, returns null; another customer's order on the same page never lends its COD.
- .2 an order encoded after the event appears with null first and with its amount on a later read; `counts.order_value.today` rises then and never counts an order twice.
- .3 `counts.order_value.today` / `.recent` equal the sum of known amounts of today's / the last 5 minutes' order events (Manila day), page filter applied.
- .4 the top-3 sequences returns the real names and counts of today's follow-ups, ordered by count; fewer than 3 handled.
- .5 the `bot` filter narrows the per-bot counts and the events; `all` equals today's behaviour; an unknown value is treated as all.
- .6 the page renders every section of decision 1 with the right data hooks, and every number spec 196 shows is still on the page (decision 6).
- .7 the front-end rules: an order event with an amount plays the money packet with "+₱N" and rolls Orders today, Orders recorded and Order value today; with null it shows "+1 ORDER" and leaves the peso total alone; a burst of orders is merged, none dropped; numbers never count down inside a minute; reduced motion shows the new values without packets. Test the JS logic in whatever way the repo's existing Live page tests do (or a small node test of the pure functions), not by eye.
- .8 the feed's query count per poll does not grow with the number of events.
- .9 existing spec-196 tests pass (update only selectors the redesign moved, and list every changed assertion under Story changes).
- .10 the full suite adds no new failures vs 59b39b46 (known baseline: 2 failures, ExampleTest and ItemTableFitScriptTest).

## Threat model and risk
Trusted: signed-in staff who may open the Live page (same permission as today). Untrusted: the query parameters (`page_id`, `bot`, cursors) and page names (shown as text, escaped). Risk tier: medium (a page on a shared screen, a new money figure, two new reads on busy tables).

## Done when
All P1 cases pass with tests; the full suite adds no new failures vs 59b39b46. The result has: a rendered evidence page of the live view with real-shaped fake data at `docs/specs/199-evidence/live.html` that opens on its own (assets inlined or relative) and plays the money animation with simulated events, plus screenshots at 1920x1080 and 390 px if you can make them locally; the measured query count and time per poll; the COD join ruling; deploy notes (migrations: an index only if needed, say so; caches; font files); the cases table; and it ends with "199: <done|blocked> — <one line>".

## Out of scope
Changing how orders are counted or encoded, editing any order or COD, new pages, Incepxion-specific adaptations beyond settings, deploying.

## Budget
Large job. Work in stages (data first: amount, order value, sequences, bot filter, with tests; then the page; then polish), commit after every passing stage, end each run with a status line. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
