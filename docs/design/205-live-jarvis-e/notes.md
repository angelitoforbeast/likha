# Likha Live v2: three directions

Files (this folder): `comp-a.html` / `comp-a.png`, `comp-b.html` / `comp-b.png` (+ `comp-b-3d.png`), `comp-c.html` / `comp-c.png`.
Every HTML is one self-contained file (system fonts, no CDN, no `filter: blur`, no `backdrop-filter`) and animates on its own with simulated traffic on an 8 s loop (one order per loop, six coins). The PNGs are frozen with `?at=2100` at the moment the loot is mid-air (coins 1-3 landed, 4-6 still flying, total halfway rolled). Other URL switches: `?noamt` = the "+1 ORDER" case (no COD yet), `?d3=1` (comp B only) opens in 3D, `?at=<ms>` freezes any moment of the loop.
Order of A, B, C is random; it is not a ranking.

## Critique of the current page (why it reads "pangit")

1. Flat hierarchy: the four KPIs are the same size and the money (P62,863), the number the owner cares about, is 40 px in the bottom-left corner, smaller than "Replies".
2. Five accent colours (cyan, violet, green, pink, gold) shout at the same volume, so nothing is the accent. It reads as a rainbow, not a system.
3. The network is clip-art: tangled hairline "node clusters" around icon-in-circle discs. They carry no data and make the labels collide (BOT 1 text sits on top of its own cluster).
4. Hairline links at about 1 px and 40 % opacity: the "neural map" looks like a still picture from across the room even though it moves.
5. Small and dim text: labels 14-18 px in blue-grey, and the Live Activity rows fade from 100 % to about 10 %, so rows 3-8 are unreadable. The column looks empty.
6. Structure fights the data: "Customer inquiries" is a big separate node, but traffic really comes in and goes out per bot. Orders (261) and replies (17,893 / 17,896) appear twice with no reason.
7. Costume typography: wide letter-spaced caps ("N E U R A L  N E T W O R K", "L I V E  A C T I V I T Y") and glow everywhere instead of light and layering. Generic sci-fi.
8. No moment: an order is +1 on a small gold number. Nothing celebrates money.

## What changed in all three (owner's later messages)

- **Per-bot in/out.** There is no big "Customer inquiries" node any more. Each bot hub carries its own IN (messages in) and OUT (replies sent), with two lines to the Likha core: one for in (packets travel to the core), one for out (packets travel back to the hub). Bot 1 and Botcake have both; Bot 2 is dashed and says DORMANT, "not linked, 0 in, 0 out", never red, no packets. The page-wide "inquiries received today 12,293" stays as a small total. AI answers is its own small node on the core. Follow-up sequences feed the core from the other side with the top-3 names and counts (names are sample data).
- **Restraint.** One or two accents per comp, no icons in circles, no node clusters. Only the logo mark and the coins are drawn graphics.
- **Moving map.** The map moves all the time (see each idle list). Everything is deterministic from one clock so a frozen frame equals the live frame.
- **Loot.** The order moment is a coin drop, not a text chip (spec per comp below). Shared timing: order at t=600 ms; 6 coins launch 110 ms apart; each coin: 380 ms pop-out (ease-out cubic, scatter fan, overshoot scale 0.3 to 1.15, flips like a spinning coin), then 900 ms arc into the total (ease-in, power 1.8, quadratic curve), shrinks to 0.74 and fades in the last 10 %. The total is split in six parts (100, 100, 100, 100, 100, 99 = 599): each landing rolls the total up by its part over 300 ms ease-out-expo, nudges the total up 1.2 % and flashes the frame; after the last coin the total settles with a 520 ms 3.5 % overshoot. The amount stays readable as the rolling total plus a small "+P599" label that appears on the first landing, rises 26 px and fades at the end. Orders today and Orders recorded tick +1 on the first landing, the tape gets its ORDER row (with +P599 in the chip) at the same moment.
- **"+1 ORDER" (no COD yet).** One small coin-shaped plate "+1" (no peso sign), no burst fan (it just lifts 50 px), same arc, lands on Orders today / Orders recorded; the order-value total does not move and its panel is not flashed; label "+1 ORDER". Try `?noamt`.
- **3D.** Comp B has a real "3D" button (works, 2D toggles back). A and C: described below.
- **Reduced motion:** no packets, coins, sweeps or tilts; the frame shows the settled state and numbers swap; the 5-minute ring still updates (it is data).
- **Burn-in drift** (all): the whole stage shifts 0 to 3 px in one step every 60 s over a 10 min loop (CSS `steps(10)`, 600 s), invisible to people.
- **Privacy:** only "Sample Page NN" and bot names. No customer names, phones or messages.

## Fonts

All three use Windows' built-in Bahnschrift (condensed for numerals, semi-condensed for labels), Cascadia Mono / Consolas for tape times. Nothing is loaded. Risk: on a TV box that is not Windows the fallback is Arial Narrow / Segoe UI and the numerals get wider; if the TV is not a Windows PC, self-host Saira Condensed (already the page's face, SIL OFL) for numerals. Not downloaded here.

## 390 px collapse (one line each)

- A: grid becomes one column in this order: header (filters wrap, clock under the title), amber Order value block, Orders today, the three KPIs, the three bot rows (IN/OUT stay side by side), core, tape; the pipes are dropped and the loot flies from the Bot 1 row to the amber block.
- B: the orbit is dropped; the disc becomes the first card (value, orders, last), then the KPIs 2x2, the three bot cards with IN/OUT, core, AI, sequences, tape; coins fly from the Bot 1 card to the value, tilt/3D hidden.
- C: KPIs 2x2, the network panel turns into stacked lanes (bot hub, in/out line, Likha core row), the amber bottom strip becomes the second card; coins fly from the Bot 1 hub to the strip.

## Detector (impeccable, one run)

`pulsing-dot` on the LIVE / bot status dots in all three (the owner asked for breathing nodes). In the build, tie the pulse to the successful poll (one pulse per answer, not an endless loop) so it is honest. B also flags the sequence rows as "flush"; they sit inside padded panels and read fine in the render. No blur, no backdrop-filter, no gradient text, no skip link.

---

# Comp A: "Tape" (broadcast scoreboard)

**Pitch**
1. Concept: a sports/stock-exchange scoreboard. Warm black, one accent (amber = money), white numerals. The money is the biggest thing on the screen (a solid amber block with 236 px black numerals), the white Likha core plate is the second solid block, and the network is a compact strip (330 px) across the top.
2. Better than today: hierarchy is unmistakable from across the room (money, then orders, then everything else); every label is at least 22 px, every number is tabular; no hairline sci-fi; per-bot IN/OUT sits in plain rows you can read like a score line.
3. Motion: pipes draw in on load, off-white streaks run in/out of Bot 1 and Botcake all day, and an order drops amber coins out of Bot 1 down into the amber block, which sweeps with light as it counts up.

**Motion spec (named moments)**
1. *Pipe draw-in* (page load, once): the 6 pipes draw from hub to core, 1.4 s, expo ease-out.
2. *Traffic* (idle, forever): comet streaks of 7 dots, 2.5-4.5 px, on the in and out pipe of each live bot (Bot 1 about every 1.5 s each way, Botcake about every 4-5 s), on the sequence pipe leftwards into the core, and a short one from AI to the core. Dormant pipes stay still and dashed.
3. *Order pop* (trigger: an order event): a 4 px amber ring expands 20 to 110 px at Bot 1 over 520 ms while six coins (flat amber discs with a black outline so they read on the amber block) fan out above it, 380 ms each, 110 ms apart.
4. *Loot arc* (900 ms per coin, ease-in): coins arc down-left into the amber block, spin (flip every 105 ms), and vanish as they touch the numeral.
5. *Tick up* (each landing): the total rolls by about P100 over 300 ms, ease-out-expo, the numeral gets a 1.2 % nudge and the block's brightness lifts 12 % for 260 ms.
6. *Gold sweep + settle* (after the 1st coin, 900 ms): one soft white band passes left to right across the amber block; after the last coin the numeral overshoots 3.5 % and settles over 520 ms.
7. *Amount label* (first landing, 1.8 s): a black plate "+P599" fades in at the block's top right, lifts 26 px, fades out. Orders today jumps +1, the tape slides a new ORDER row in from the right (800 ms, expo), orders row highlighted solid amber.
8. *Tape* (idle): a row every 1.6 s slides in 24 px from the right, older rows fade to 70 %.

**Idle:** streaks, status dots breathing every 2.6 s, tape, clock, counters creeping up (replies every 1.7 s, inquiries 1.1 s, AI 2.6 s, follow-ups 3.4 s). **Burn-in:** 3 px drift per minute.
**3D (not built here):** a "3D" button in the header tilts only the strip: the three bot rows, the core and the sequences become plates on a perspective floor (`perspective: 2200px`, plates at `translateZ` 0 / 40 / 80 so the core stands highest, camera `rotateX(24deg)` with a 10 s sway of 4 degrees), the amber block stays flat in front like a lower-third. Same technique as comp B, CSS 3D only, no library.

---

# Comp B: "Reactor" (orbital, money at the core)

**Pitch**
1. Concept: the whole business is a reactor with the money in its core: the gold disc in the middle IS the order value; around it a 60-tick five-minute clock (the ring), and the bots, AI and follow-ups orbit on floating plates. Each bot plate has its own IN and OUT, wired to the ring with two parallel lines.
2. Better than today: one focal point (the gold number, 148 px) instead of four equal KPIs; depth from light and layering (a raised plate, gradient disc, ring), not glow; two accents only (ice blue for system, gold for money); the right column is solid and readable to the last row.
3. Motion: ring ticks sweep and light with traffic, packets run both ways on every bot, and an order bursts a stack of metallic coins out of Bot 1 that arc into the disc and ring it with a gold shock; a working 3D button tilts the whole reactor and lets it sway.

**Motion spec (named moments)**
1. *Boot* (once): pipes draw in 1.4 s expo (build: skip the boot when the page reloads inside 60 s).
2. *Ring clock* (idle): the head tick (white) advances every 5 s around the 60 ticks; behind it ticks stay lit by traffic and fade over 5 min; one tick turns gold when an order lands.
3. *Bot traffic* (idle): ice-blue streaks go in on each bot's top pipe and out on its bottom pipe; AI and follow-ups pipes each have a stream into the ring. Dormant Bot 2 stays dashed and still.
4. *Order burst* (trigger): a 4 px gold ring ripples out of Bot 1 (24 to 114 px, 520 ms); six metallic gold coins (radial gradient, rim and inner ring) pop out, 380 ms, spin like flipped coins.
5. *Coin arc* (900 ms each, 110 ms apart, ease-in): coins arc over the ring into the disc and shrink into the value.
6. *Total ticks* (per landing): the gold number rolls by about P100 over 300 ms (expo), nudges 1.2 %, the disc's rim brightens and a soft gold radial rises behind the number; the "+P599" label lifts above the disc and fades.
7. *Gold shock* (after the last coin, 1 s): one gold ring expands from the disc over the clock ring (250 to 340 px, expo) while the number overshoots 3.5 % and settles in 520 ms.
8. *Tape row* (same time): the ORDER row slides in with a gold wash and "ORDER +P599".

**Idle:** ring clock, streaks, breathing of the rim (sin, 5 s), status dots, counters creeping, tape every 1.6 s. **Burn-in:** 3 px drift per minute.
**3D (working, press "3D", it becomes "2D"):** CSS 3D only, no library. `#cam` has `perspective: 2200px` centred on the reactor; `#world` is `preserve-3d`; the ring plane stays at depth 0, the plates float 70 px above it, the coin layer 110 px, the amount label 120 px. The camera eases (6 % per frame, about 0.8 s) to `rotateX(30deg)` and sways `rotateY` +-9 degrees (about 80 s per cycle), with a 3 degree tilt breathing (about 56 s per cycle); toggling back eases to flat. Cost: no extra repaints beyond the 2D version (one transform on one element per frame), safe for an all-day TV. The spoke ends sit under the floating plates so the offset does not show. `comp-b-3d.png` is a capture.

---

# Comp C: "Mission control" (refined HUD, lanes)

**Pitch**
1. Concept: the owner's original Jarvis idea made precise. A mint-on-graphite control room with a segmented dial (48 thick arcs = the 5-minute clock) as the Likha core, three bot lanes on the left with IN and OUT and parallel arrowed lines into the core, AI above, follow-ups on the right, and a warm amber "vault" strip at the bottom where the money lands.
2. Better than today: same layout skeleton the owner already knows, but fixed: lines are 3 px with direction arrows, nodes are plates not clip-art, text is 22 px and up with full contrast, the activity column stays readable to its last row, the corner brackets and layered gradients give finish without glow; two accents (mint = system, amber = money).
3. Motion: the dial's arcs light with traffic, streaks run along every lane, and an order sends amber coins from the Bot 1 lane on a long swooping arc past the dial into the vault total, which warms its rim as each coin lands.

**Motion spec (named moments)**
1. *Lane draw-in* (once): pipes draw 1.4 s expo.
2. *Dial clock* (idle): the white head arc advances every 5 s over 48 arcs; arcs stay lit by traffic and fade; one arc turns amber on an order.
3. *Lane traffic* (idle): streaks run right on each in pipe and left on each out pipe (Bot 1 every 1.5 / 1.9 s, Botcake every 4-5 s); the follow-ups pipe runs into the dial from the right; AI has a short pulse into the core. Bot 2 is dashed and quiet.
4. *Order pop* (trigger): amber ring ripples out of the Bot 1 hub (520 ms); six minted coins (octagonal, double-edged, amber gradient) fan out and flip.
5. *Swoop* (900 ms each, ease-in): coins curve down the left side of the dial (control point set so they never cross the core numbers) and into the bottom strip.
6. *Vault warms* (each landing): the total ticks up by about P100 (300 ms, expo), the panel rim shifts from dull bronze to bright amber and fades back; 1.2 % nudge.
7. *Light sweep + settle* (after first landing, 900 ms): a soft amber band passes across the strip; after the last coin the number overshoots 3.5 % and settles in 520 ms; "+P599" label lifts from the strip's top right and fades.
8. *Tape row* (same time): ORDER row slides in with an amber wash; the newest row always carries a faint mint wash.

**Idle:** dial clock, lane streaks, status dots breathing, counters creeping, tape every 1.6 s. **Burn-in:** 3 px drift per minute.
**3D (not built here):** "3D" button in the header turns the network panel into a tilted deck: the dial lifts to depth 90 px, bot hubs at 40 px on the left, follow-ups at 40 px on the right, lanes stay on the floor; `perspective: 2200px`, `rotateX(28deg)`, sway 8 degrees. Same CSS 3D recipe as B (about 15 lines: wrapper, `preserve-3d`, `translateZ` on plates, eased camera).

---

## What I could not check / assumptions

- Real device: renders were made with Chrome headless on Windows (Bahnschrift present). Not opened on the TV, not tested over 24 h; burn-in drift and the loop are written for it, not measured.
- Motion judged from the code and frozen frames; I did not record a video. Coins in the PNGs overlap other things for a moment on purpose (they are in flight).
- New data the comps assume: order amount per order (the page has none today: with no amount the "+1 ORDER" case plays and the value total is hidden), order value total, top-3 sequence names with counts, "Bot filter" and per-bot IN/OUT (`counts.bots.in/out` already exist). The six-way split of the amount is a presentation trick, the server sends the one amount.
- Sample values: the three sequence names and "Sample Page NN" are placeholders; the sequence counts sum to 22,699.
- PRODUCT.md was not written (this folder is not a project; the brief and screenshot carried the facts). No image-generation round: the comps are built HTML, rendered.

---

# Comp D: "Jarvis" (owner's pick of direction; B's reactor, per-bot notes, deep 3D)

Files: `comp-d.html`, `comp-d.png` (2D, mid-loot, `?at=2100`), `comp-d-3d.png` (`?at=2100&d3=1`). `?noamt` shows the "+1 ORDER" case. Same engine, same 8 s loop as A to C.

**Pitch**
1. Concept: B's orbital reactor dressed as a real holographic HUD: thin 1.5-3 px rings, three counter-rotating segmented arcs behind the gold disc, a scan band sweeping the dial, corner brackets on every panel, small mono labels, a faint scanline texture over the stage. Colour stays at two: ice blue for the system, gold for money.
2. Every bot hub has a NOTES panel on the left, joined to the hub by an angled leader line (45 degree elbow, square end caps). Bot 1 and Botcake notes show: Chatting (customers active in the last 5 min), AI answered, Avg reply, Orders today, Order value from this bot, Last activity (page name + time) and Latest customers (4 names, each with a status tag CHATTING / AI / ORDERED and a time; the newest slides in at the top). Bot 2's notes are dashed and dim ("none while not linked").
3. Money loot lands in the gold core exactly as in B (six coins out of Bot 1, arc, total ticks per coin, shock ring); the numerals that matter stay big: 120 px order value, 78 px KPIs, 42 px bot IN / OUT, 32 px note values.

**Per-bot numbers on screen** (all placeholders that must be real in the build): Bot 1 chatting 68 + Botcake 3 = Active chats 71; AI answered 9,418 + 3 = 9,421; orders 260 + 2 = 262 (the Bot 1 figures tick up with every loot landing); value from Bot 1 = total minus P599 from Botcake; Avg reply is Bot 1 only (Botcake shows a dash, as the page measures Forbeast only). The Bot 1 and Botcake counts of orders, value, AI and chatting per bot are new data (the page has per-bot in and out only today).

**Customer names (owner said yes):** each live bot's notes show 4 recent customers by name with a status and a time. They are fake sample names here ("Maria Santos"-style, full names). No phone numbers, no addresses, no message text anywhere. Build note: names are personal data on a wall TV; keep the page behind login (it is today) and cap at 4 per bot, newest first, never older than the day.

**Motion spec (named moments)**
1. *Notes boot* (once, load; staggered Bot 1 at 0.5 s, Bot 2 at 0.9 s, Botcake at 1.3 s): the leader line draws from the hub (expo ease-out, 0.9 s), the panel unfolds top to bottom (clip-path, 700 ms, expo), then the values type in character by character over 900 ms.
2. *Pipe draw-in* (once): the in and out lines of each hub draw from hub to ring in 1.4 s expo.
3. *Ring idle* (forever): outer arc set rotates at 4.5 deg/s, the dotted ring at -2.2 deg/s, the inner segmented arc at -9 deg/s, the 5-minute ticks light with traffic as in B, and a scan band (cyan gradient with a bright leading edge) sweeps top to bottom of the dial every 7 s, clipped to the dial.
4. *Traffic* (forever): ice streaks go in and out on each live bot, AI and follow-ups lines; Bot 2's lines are dashed and still.
5. *Notes tick* (event): when a note value changes, the figure flashes white and drops 3 px back into place in 500 ms (ease-out). New customer: the list slides the new name in from the right at the top, 800 ms, expo, the oldest drops off the bottom.
6. *Order loot* (trigger: order): gold ring ripples out of the Bot 1 hub, six coins (thin gold rim, dashed inner ring, flip as they fly) pop out 380 ms, arc 900 ms into the core 110 ms apart; each landing adds about P100 (300 ms, expo), nudges the core numerals 1.2 % and warms the rim and a gold glow behind them; after the last coin the gold shock ring expands over the dial and the total overshoots 3.5 % and settles (520 ms). A small "+P599" lifts over the core and fades.
7. *Order in the notes* (same moment): Bot 1's Orders today, Order value and the customer list update: a new top row "ORDERED" in gold with the customer name; the tape gets its gold ORDER row.
8. *"+1 ORDER"* (no COD): one "+1" token flies, orders and the ORDERED row update, no pesos, the value is not touched.

**Idle / burn-in:** status dots breathe (2.4-2.6 s; build: pulse once per poll), counters creep, tape row every 1.6 s, whole stage drifts 3 px a minute.

**3D (built, press "3D", becomes "2D"):** CSS 3D, no library, no download. `perspective: 1500px` centred on the core. The world is pulled back 230 px, tilts `rotateX(32deg)` and orbits `rotateY` +-11 degrees over about 70 s (plus a 3 degree tilt breath). Each layer has its own depth (`translateZ`): far rings -110, dial ring -40, gold disc +35, core plate +30, inquiries total +60, AI +55, follow-ups +95, Bot 2 +10, Bot 1 +70, Botcake +120 (with its notes, leader and pipes in the same group so they stay joined), coins +140, amount label +150. The KPIs, header and Live Activity tape stay flat as a HUD overlay. The camera eases in and out (5 % per frame). One transform on one element per frame plus the 2D draw cost; light enough for an all-day TV. Known: the pipes ride with their hub, so in 3D the gap between a floating hub and the ring grows slightly; it reads as height.

**390 px collapse:** HUD overlay first (header, KPIs 2x2), then the gold core card, then one card per bot with IN/OUT and its notes unfolded below it (no leader lines), then AI, follow-ups, tape; no rings, no 3D, coins fly from the Bot 1 card to the core card.

**Detector:** same pulsing-dot warning as the others; nothing else new. No blur, no backdrop-filter, no CDN.

---

# Comp E: "Wall" (visual-first, almost no text; the Iron Man hologram wall)

Files: `comp-e.html` (self-contained, loops forever), `comp-e.png` (2D, mid-loot, 1920x1080, `?at=2100`), `comp-e-3d.png` (`?at=2100&d3=1`). Helpers in `src/`: `patch-e.*` (edit script), `wc.mjs` (word counter), `run-e.html` (error probe). Canvas 2D + CSS 3D only: no library, no CDN, no download, no blur / backdrop-filter / shadowBlur (glows are pre-rendered radial-gradient sprites drawn additively).

**Owner's two steers, both applied.** (1) "Ang daming sulat": the page is graphics; the numbers are the text. (2) "Yung COD hiwalay na lugar": the money is NOT in the globe. The globe is the **Likha bot core** (its one number is the bot's signals in the last 5 min). The money has its own **gold vault panel** bottom-centre, directly under the globe: a coin stack that grows, the P total big, orders count small. Loot coins fly from the gold blip in the Bot 1 radar, **through the globe**, and land on the vault stack.

**Pitch**
1. One look = a hologram wall: a luminous white-blue globe with amber and cyan rings turning in 3D, 8 translucent cyan trapezoid panels fanned around it on radial spokes, a gold vault below. Each panel is a graphic, not a table.
2. Customers are dots. Each bot's radar shows every customer chatting in the last 5 minutes as a glowing dot; a new customer is a bright blip with a floating name tag that fades in 3.5 s; an order turns a dot gold. There is no customer list anywhere.
3. Palette widened to the still: ice/cyan for the system, amber/gold for money only, green for customers. Type: Bahnschrift Condensed numbers, Bahnschrift SemiCondensed 20 px uppercase labels, one or two words each.

**Layout (1920x1080 stage, globe centre 960,585):** header 76 px; top row AI hex (left of centre) and Follow-ups arcs (right of centre); left column Bot 1 radar (large) over Bot 2 dormant; right column Botcake radar (large) over Chats ring; bottom row Replies gauge, GOLD VAULT (widest), Avg reply gauge; icon ticker 28 px at the bottom. Panels are rounded trapezoids, wide edge outward, short edge toward the globe; a bright dash crawls round each frame.

**What every visual encodes (and where each comp D number went)**

| Comp D item | Comp E |
|---|---|
| Order value today P63,055 (COD) | Vault: big P total (68 px), gold. Stack of coin discs, one disc = P6,000 (placeholder scale, max 14). |
| Orders today 262 | Vault, small "262 ORDERS". |
| "+3 in the last 5 min", Last order (page, time, bot), "+222 in the last 5 min" | Hover/tap: vault and replies tips. Not on screen. |
| Likha bot signals 801 | Globe's one number (128 px), "LIKHA BOT" under it. |
| Inquiries received today 12,293 | Hover/tap on the globe. It is also exactly Bot 1 in + Botcake in, so it stays derivable. |
| Bot 1 IN 11,582 / OUT 17,893; Botcake IN 711 / OUT 3 | The two big numbers on each bot panel (62 px) with a triangle pointing toward (IN) or away from (OUT) the globe; the same two flows run as packets on the spoke (white = in, cyan = out). |
| Bot name tags (Forbeast, Fallback, Inceptionbot), Connected / Not linked | LED beside the bot name (breathing green = connected); full names and status in the tip. Bot 2 = dim wireframe radar + power icon (dormant). |
| Chatting 68 / 3 (per bot notes) | The count of green dots on that bot's radar (68 dots in the sim). Exact number in the tip. |
| Latest customers (4 names, status, time) | Names: only as a floating tag on a new blip, 3.5 s then gone. Status: gold dot = ordered, thin cyan ring on a dot = answered by AI, plain green = chatting. Time: the dot's distance from the centre (centre = just now, rim = 5 min ago). The list itself is dropped. |
| Per-bot AI answered 9,418 / 3, Orders 260 / 2, Order value 62,456 / 599, Last activity, Avg reply on Bot 1 | Hover/tap on the bot panel. Not on screen. |
| AI answers 9,421 | AI panel: 30 hexagons, one lights per answer and fades over a minute (newest flashes white) + the number (50 px). |
| Follow-ups 22,699 and three sequences (14,210 / 5,871 / 2,618) | Follow-ups panel: three concentric arcs sized by count (full / 41 % / 18 %), a bright dot runs along each, + the total (50 px). Names and exact counts in the tip. |
| Replies today 17,896 | Replies gauge: 240 degree arc, 78 % filled, white tick = same hour yesterday (NEW data, see below) + number. |
| Avg reply 5.4 s | Speedometer: needle on 0 to 15 s, cyan segment under 7.5 s, pale 7.5 to 12 s, amber above 12 s + number. Definition ("customer sent to reply sent", Bot 1 only) in the tip. |
| Active chats 71 | Ring of 100 dots, 71 lit green, a bright dot circling + the number. "Last 5 min" and the per-bot split in the tip. |
| LIVE ACTIVITY column (time, type, bot, page name per event) | Replaced by a one-row icon ticker (28 px): down arrow = incoming, up arrow = reply sent, hexagon = AI answer, arc = follow-up, gold coin = order; slides left at 26 px/s. **Dropped:** the page name, bot and time of each event. |
| TODAY, MANILA, UPDATED, "5-MIN CLOCK", "LIVE" word | Clock only (11:52:22). The pulsing dot stands for LIVE. Timezone/updated text dropped; can go in the clock's tip in the build. |

**Dropped outright:** per-event page names and times, per-customer time and the 4-name lists, the Today/Manila/Updated line. Everything else is on screen or one hover/tap/focus away (panels are keyboard-focusable and show the same data card on focus; the TV never hovers, so TV mode shows only the graphics).

**Word count of the 1920x1080 frame (`src/wc.mjs`: DOM text plus every canvas fillText with alpha > 0.05, same `?at=2100` moment)**
- Comp D: **295 tokens** (85 numbers, 210 words). The count may include a few small duplicate strings; by eye D is still about 270.
- Comp E: **44 tokens** (19 numbers, 25 words; 3 of the 44 are the P glyph on coins in flight). Words visible: LIKHA LIVE, 3D, TV, BOT 1, BOTCAKE, BOT 2, IN, OUT (x2), AI, FOLLOW-UPS, REPLIES, AVG REPLY, CHATS, ORDERS, LIKHA BOT, two name tags (Grace P., Maria S.), s.
- About 6.7x fewer. The tip card (hidden by default) adds text only on hover.

**Motion spec (always moving, deterministic loop, 8 s order cycle)**
1. *Radar sweep* (forever): 4.2 s per turn, cyan trailing wedge + bright leading line. A dot glows to full as the sweep passes it and decays (1.5 s time constant), so the wall reads alive even when nothing changes.
2. *Blips*: Bot 1 gets a new customer every 4.4 s (sim): bright white-green dot, an expanding ping ring (2.6 s) and a name tag that fades out by 3.5 s. Dots drift outward over their 5 minutes and leave at the rim. Botcake: one new customer per 100 s. Bot 2: no sweep, one dashed hairline turning once per 9 s.
3. *Spokes*: each spoke carries packets. Bot 1: IN every 0.9 s, OUT every 0.7 s; Botcake every 9 s / 7 s; AI every 2.6 s; follow-ups every 3.4 s; replies 1.2 s; avg reply 2.8 s; chats 3 s (green). Bot 2 spoke is dashed and still; the vault spoke is gold and carries no packets (the coins are its traffic). Counters tick with the same periods.
4. *Globe*: five rings (amber, cyan, thin amber, dashed ice, dotted ice) turn in real 3D (tilt precessing 4 to 8 degrees, in-plane spin 3 to 7 degrees per second, dashes running along them), back halves drawn dim behind the globe and front halves bright in front; two satellites orbit; meridians rotate on the globe; 16 green glints drift across it; the core breathes (0.7 s cycle).
5. *Hex grid*: a cell lights per answer (every 2.6 s), the previous 22 stay lit fading; *arcs*: a bright dot runs each arc; *gauges*: end-of-arc glow breathes; *chats ring*: lit dots shimmer, one bright dot circles every 6 s.
6. *Frame dash*: a bright 90 px dash crawls round every panel's border.
7. *Order loot* (every 8 s, starts 0.4 s into the cycle): a gold ring ripples out of the gold dot in the Bot 1 radar; from 0.7 s six coins leave it 150 ms apart, flip as they fly, follow one curve **through the centre of the globe** (the globe flashes white as each passes) and drop 1.1 s later onto the top of the vault stack; each landing adds about P100 to the total (100,100,100,100,100,99 = 599), the top disc flashes and a gold glow blooms; after the last landing a gold shock ellipse expands across the stack, the number overshoots 3.5 % and settles in 0.5 s, "+599" lifts over the vault and fades, the orders count ticks. A new coin icon enters the ticker. (The PNG is frozen at 2.1 s: three coins landed, three in flight.)
8. *Idle / burn-in*: the status dot breathes (2.4 s); counters creep. Build note: drift the whole stage 3 px a minute as in D (not in this comp).

**3D recipe (button "3D", pressed state; eases in at 6 % per frame, about 1 s)**
- Same DOM, same canvases. A `perspective: 1500px` camera with its origin on the globe; one `#world` element (`preserve-3d`) carries everything. Panels are laid on a **cylinder** around a vertical axis through the globe: flat position (x, y) from the globe becomes angle = x / 548 (radians), cylinder radius 795, height scaled 1.45, so each panel keeps its aspect; each panel gets `translate3d(R sin a, y*1.45, -R cos a) rotateY(-a) scale(1.45)` (concave: the wall wraps toward the viewer at the sides). 2D to 3D is a linear blend of those numbers by one factor K (0 to 1), so the switch is a smooth fold.
- The camera is pulled back 240 px (`translateZ(-240px)`), tilts 4 degrees (breathing 2 degrees) and the wall orbits +-7 degrees about the globe's vertical axis in about 60 s. The globe stays at the axis (z = 0), the wall at z = -795 to -295, so the globe is always in front of the wall.
- **No overlaps at any moment:** every panel is a non-overlapping patch of one cylinder surface (the 2D layout has 26 px or more between panels and the map is monotonic in x), and the camera is outside the wall's open arc looking at its concave side, so each view ray crosses the wall once. Checked in `comp-e-3d.png` (orbit near -5 degrees); the header and ticker stay flat above the 3D layer.
- Spokes are recomputed each frame as a segment from the globe's outer ring to each panel's inner edge with `matrix3d`, two crossed ribbons (one edge-on in 2D) so they are visible from any angle; packets ride both. Coins are billboards (counter-rotated against the orbit) on the same curve through the globe.
- Cost: 9 canvases redrawn at 30 fps + about 30 CSS transforms per frame. No filter, no blur.

**Known and assumed**
- Rendered with Chrome headless at 1920x1080; a real-time headless smoke test ran with no script errors and the sweep, ticker and packets advanced. Not watched for minutes, not run on the TV, no 24 h test, and the 2D to 3D tween was judged from its two end states, not seen mid-way.
- New data the build needs beyond D: per-customer last-message time for the last 5 minutes and a status (chatting / AI / ordered) per customer, per bot (radar dots); yesterday's replies at the same hour (the white tick on the replies gauge; drop the tick if not wanted); a coin-disc scale for the vault (P6,000 per disc is a placeholder: pick a daily target so the stack means something); per-bot chatting / AI / orders / value (same as D).
- Sim shortcuts that must not ship: orders every 8 s (real is about 3 per 5 min), gold dots fade after 45 s (build: gold stays the full 5 min), the AI hex lights one cell per 2.6 s.
- Customer names on a wall TV are personal data (D's rule holds): first name plus initial only, 3.5 s, behind login, and only for a new customer.
- Bahnschrift is a Windows system font (as in D); the build must ship a self-hosted condensed face. The impeccable detector gave one error and one advisory: the pulsing LIVE dot (build: pulse once per poll) and the repeating-gradient stripes, which are the Bot 2 dashed spoke, kept on purpose (dashed = not linked).
- Header: filters collapse into one funnel icon button (opens the two selects); TV mode hides the buttons and cursor and goes fullscreen (Esc leaves); no skip-to-content link.
- Contrast was not measured with a tool. By eye: panel numbers are near-white on near-black; the globe number is deep navy on pale blue (weakest at the globe's edge, still dark on mid-blue); small labels are #8fb4c8 on the dark panel fill.
- Minor: at the orbit's extreme the Chats panel's lower corner touches the icon ticker row (a flat HUD layer, not a panel overlap); the vault's "+599" lift is dim in the PNG because it is just fading in.
