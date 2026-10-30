# Design brief: Likha Live, "neural network room" (Jarvis direction)

Surface: `https://likhaaitech.com/chatbot/live` (Blade `resources/views/chatbot/live.blade.php` + `chatbot/partials/live-map.blade.php`, layout `layouts/chatbot-tv`).
Mode: Operate, as an ambient wall display (read from 3-5 m on a TV, glanced at on a phone). The brief wins: the owner asked for "parang Jarvis", then sent a reference image ("medyo malapit dito... all out ka") and asked for a neural-network look, a money animation on orders, separate bot nodes and AI as its own node.
Files in this folder: `comp.html` (animated comp, simulated events), `comp-1920.png`, `comp-390.png` (renders of the comp), `frame390.html` (helper that shows the comp in a 390 px frame).
All numbers and page names in the comp are placeholders. `PRODUCT.md` was not written (the task allowed only this folder).

Reference followed (`images/2.webp`): header with live dot, filters, Manila clock, TV mode; a KPI strip of four; a large NEURAL NETWORK panel with inputs (Customer inquiries, Follow-up sequences with Seq 1/2/3) streaming into a central LIKHA BOT core that routes to Bot 1 (connected), Bot 2 (dormant, dashed), Bot 3 (connected); a bottom strip (Follow-ups sent, Orders recorded); a right LIVE ACTIVITY timeline ending "Listening for new events". The comp keeps that skeleton and goes further on the network itself (dense node clusters, ambient particle streams, a five-minute tick clock around the core, packets with tails, a money packet).

---

## 1. What the page shows today (inventory, signed in as CEO, no login form hit)

Polls `/chatbot/live/feed` every 5 s (paused when the tab is hidden). Statuses: LIVE, RECONNECTING, PAUSED (TAB HIDDEN), SIGNED OUT: RELOAD.

| # | Element today | Detail |
|---|---|---|
| 1 | Header | "LIKHA LIVE" (site name from APP_URL), status dot + text, Page filter (All pages + page list, submits on change), "Today (Manila) · last update HH:MM:SS", "Exit TV mode" link |
| 2 | KPI Replies today | number + "+N in last 5 min" |
| 3 | KPI Avg reply time | seconds + "s", or "—"; note by state (ok / none / not_linked / not_on_forbeast / error) |
| 4 | KPI Orders today | number + "+N in last 5 min" |
| 5 | KPI Active chats | number + "customers who messaged in last 5 min" |
| 6 | Neural map | INQUIRIES + "N messages today"; three clusters (Bot 1 Forbeast, Bot 2 Incepxionbot, Botcake fallback) with inbound count or "not linked"; "↩ N replies" per bot; unknown inbound/outbound notes; core with "AI answers N"; FOLLOW-UP sent today; ORDERS today + "Last: page · time · bot"; legend |
| 7 | LATEST | 9 lines (time, type, page · bot), older fade, empty state |
| 8 | Light packets | message in to core; confirmed reply out to bot; follow-up and order out; AI answer flashes core; 30-light pool, thinning above 60 events/poll |
| 9 | Phone | one column, separate vertical map |

Weak today (seen live): the map is a letterboxed SVG with dead bands left and right; the four KPIs are equal and sit above, so the core is not the heart; map labels are 11-20 SVG units and shrink; Orders today appears twice; satellite nodes carry no meaning; the three bots look like the same clip-art cluster.

---

## 2. Direction

**"The neural network room."** The page is one network. Inputs on the left (Customer inquiries, Follow-up sequences) stream into the LIKHA BOT core in the middle; the core routes to the designated bot on the right (Bot 1 · Forbeast, Bot 2 · Incepxionbot, Botcake · Fallback). Dense node clusters and a faint node lattice fill the field; glowing particle streams run along every link all day; real events are brighter, bigger packets with tails. Cyan = inquiries, violet = sequences, emerald = outbound, pink = AI, gold = orders (the reference palette plus pink for AI and gold for money).

What makes it ours (not a generic cyan dashboard):
- **The tick ring is a five-minute clock** around the core: 60 ticks, one per 5 s poll; the bright head is "now"; ticks light where events arrived and fade. A glance tells whether the last five minutes were busy. The core number is "signals · last 5 min" (see section 4, derived, no new query).
- **Each bot is its own node**, not clip-art: a hub with a halo ring and icon, status text, replies sent big, messages in small. Linked = solid emerald ring and orbiting halo; Fallback = same ring labelled Fallback; Not linked = dormant (dashed ring, plug icon, no halo spin, dashed wire, "DORMANT / no traffic while not linked", never red).
- **AI answers is a satellite of the core, not part of it.** Decision: a pink node on its own short link above the core, with its own count and its own pulse stream (an `ai` event sends a pink packet node to core). Why: the AI call is a separate step in the flow (message, AI asked, core routes, bot sends), counted in a different table (`chatbot_ai_requests`) with its own event type, so it earns its own signal; putting it inside the core (as the reference does) hides that step and makes the core number ambiguous; a ring around the core would be a second ring competing with the tick clock. The core is left to say what it does: LIKHA BOT, "Process · prepare · route", signals last 5 min.
- **Money has its own packet** (section 5).

"Would anyone else have made this?" The cyan glow is the genre; the five-minute tick clock, dormant-not-broken bot state, AI as a separate step with its own traffic, and a gold packet that carries the peso amount into the totals are what this page owns.

---

## 3. Layout (design canvas 1920 x 1080, scaled to the screen)

One fixed 1920x1080 canvas scaled with `transform: scale(min(vw/1920, vh/1080))` and centred (screens wider than 16:9 show the same void on the sides). Reason: HTML (KPIs, timeline) and the network SVG share one coordinate space, and the page fills the TV (fixes the letterboxed map). 48 px safe margin on TVs that overscan.

```
y 0-72      header: logo, LIKHA LIVE, Live | page filter, bot filter, Manila clock, TV mode
y 72-232    KPI strip x4: Replies today | Avg reply time | Orders today | Active chats
y 232-1080  NEURAL NETWORK panel (x 0-1500)                     | LIVE ACTIVITY (x 1500-1920)
            CUSTOMER INQUIRIES (cyan cluster, count) ->            |  8 rows: chip + bot, "desc · page", time
            FOLLOW-UP SEQUENCES (violet cluster, Seq 1/2/3) ->     |  rail line with coloured dots
            AI ANSWERS satellite (pink) ->  LIKHA BOT core  -> Bot 1 / Bot 2 (dormant) / Botcake
            bottom strip: Follow-ups sent | Orders recorded (gold node) | Order value today (peso)
```
Core centre (690, 580), radius 132, tick ring 150-190. Inputs x 230. Bot hubs x 1150, text at x 1204. AI node (690, 345) with "AI ANSWERS 3,087" beside it. Orders node (560, 975) at the end of the gold link from the core.

---

## 4. Every panel mapped to real data (LiveFeed.php `counts` + `events`)

Check of the reference against what the page already has. Several things the coordinator listed as new already exist: **active chats, "+N in last 5 min", and live activity events all exist today.**

| Reference / comp panel | Source today | Status |
|---|---|---|
| Replies today + "▲ +N in last 5 min" | `counts.confirmed.today`, `.recent` | exists |
| Avg reply time, "Customer sent → reply sent", chip "Bot 1" | `counts.reply_time` (state ok / none / not_linked / not_on_forbeast / error; Forbeast only, so the chip is static "Bot 1") | exists |
| Orders today + "+N in last 5 min" | `counts.orders.today`, `.recent` | exists |
| Active chats + note | `counts.active` | exists |
| Customer inquiries "received today" | `counts.messages.today` | exists |
| Bot replies sent (big) | `counts.bots.out.{forbeast,incepxionbot,botcake}` | exists |
| Bot "N in" (small) | `counts.bots.in.*` | exists |
| Connected / Not linked / Fallback | `counts.linked.{forbeast,incepxionbot}`; Botcake is always Fallback | exists |
| AI answers | `counts.ai.today`, `.recent` | exists |
| Follow-ups sent | `counts.follow_ups.today` | exists |
| Orders recorded (strip) | `counts.orders.today` (same number as the KPI; shown twice as in the reference) | exists |
| Last order | `counts.last_order` {page, at, bot}; shown in the Live Activity ORDER row instead of the old footer | exists |
| Unknown inbound / outbound notes | `counts.bots.in.unknown`, `.out.unknown`; small line under the bot column when > 0 (not drawn in the comp) | exists |
| Live Activity rows | `events[]` {type, at, page, id, bot}: message = INCOMING, confirmed = SENT, follow_up = SEQUENCE, ai = AI, order = ORDER; description text is fixed per type | exists |
| "Listening for new events" | empty state of the timeline | exists (text only) |
| Core "signals · last 5 min" | sum of existing `.recent` values: messages + confirmed + follow_ups + ai + orders | derived, no new query |
| ▲ arrows | shown when `.recent` > 0 | derived |
| TV mode button | Fullscreen API toggle (the page already is a TV layout; "Exit TV mode" link stays) | front-end only |
| **Order amount per order (peso)** | not on the order event today: `chatbot_events` order `detail` holds run, flow_version, flow_hash, sends, sequence_id, sequence_step, ad_id, via, and LiveFeed returns only id, at, page, bot | **needs new data** |
| **Order value today (peso total)** | no field | **needs new data** |
| **Seq 1 / Seq 2 / Seq 3 (per-sequence counts or names)** | only the total `follow_ups`; no per-sequence breakdown | **needs new data** |
| **"All bots" filter** | LiveFeed filters by page only (`page_id`) | **needs new data** (a `bot` parameter) |

Needs-new-data detail for the build spec:
1. **Order amount.** Add `amount` (pesos, number or null) to the `order` event in `LiveFeed::events()` and `order_value: {today, recent}` to `counts`. Where it may come from: the encoded order's COD value (`OrderEncode/LiveOrder.php` builds a `COD` field from "PRICE: ₱..." in the shop details, lines about 214 and 278; and `FlowEncode.php` reads `COD`), which today is not linked to the `chatbot_events` order row. The spec must decide the join (run id / chat id) and what happens when no amount exists (null).
2. **Sequence breakdown.** Top 3 sequences today by confirmed sends: `chatbot_replies` grouped by `sequence_id`, with the sequence name. Until then the comp's Seq 1/2/3 labels have no numbers and are decoration; the build should show the real top-3 names (or omit the labels).
3. **Bot filter.** `bot` query param narrowing `in`/`out` by gateway/via. Until then the select is hidden (the page filter stays).

---

## 5. Which data event triggers each animation

Poll payload every 5 s (`GET chatbot.live.feed`, params `page_id` and `cursor[chat|seq|order|ok|ai]`): `{counts, events[], more, cursor, now}`. `counts` is cached 5-10 s and shared by viewers; `events[]` is per viewer from its cursor, up to 200, ordered by time, each `{type, at, page, id, bot}`. The first poll has no events (cursor starts at now); events appear from the second poll. An order event is a new `chatbot_events` row with `type = 'order'` (written when a flow passes a "Counts as an order" node, once per customer per 24 h), cursor key `order` by id, `bot` = `detail.via`.

| Trigger | What plays |
|---|---|
| `events[]` item `type:'message'`, `bot` | cyan packet customer inquiries to core; core flare + shockwave ring; Inquiries hub pulses; "received today" and the bot's "in" count roll up by the next `counts`; timeline row INCOMING |
| `type:'ai'` | pink packet AI node to core; AI hub pulses; AI number rolls; row AI |
| `type:'confirmed'`, `bot` | emerald packet core to that bot's hub; hub flashes white; "replies sent" and Replies today roll; row SENT |
| `type:'follow_up'`, `bot` | violet packet follow-up sequences to core then to the bot; sequences and bot hubs pulse; Follow-ups sent rolls; row SEQUENCE |
| `type:'order'`, `bot`, (new) `amount` | **money packet**: gold packet, bot hub back along its link into the core, then core down the gold link into the Orders node; if `amount` is present the packet carries a "+₱1,149" chip (30 px, outlined for legibility); core flares in the middle of the trip. On arrival (about 3.2 s): Orders today (KPI), Orders recorded (strip) and Order value (strip) count up (1.2 s, 1.2 s, 1.4 s, ease-out-expo), the Orders node and the Orders KPI glow gold for 1.4 s, and "+₱1,149" floats up off the KPI and fades (1.8 s). Row ORDER. |
| `counts.*` changes with no event (late confirmation, other viewer's day) | number counts from the shown value to the new one; no packet |
| `counts.orders.today` increases but no order event yet (counts and events have different freshness: counts up to 10 s old, events up to 5 s lag) | do not start the roll early; wait for the event, roll when the packet lands. If the count arrives first, hold the displayed value until the event or 8 s, then roll |
| An order event arrives before `counts` caught up | advance the displayed Orders and Order value locally by 1 and `amount` when the packet lands; when `counts` arrives, roll to the server value if it is higher, hold if equal, never count down inside a minute |
| `amount` is null or absent (the situation today) | money packet becomes a gold "+1 ORDER" chip, Orders counts up, the Order value readout is hidden (`counts.order_value` absent); no peso is ever invented |
| Burst: several orders in one poll | one money packet per 1.5 s, extra orders merged into one chip ("+₱4,200 · 3 orders"); orders are never dropped (existing rule) |

Other limits (all-day TV): up to 30 packets alive, thinning above 60 events per poll as today; ambient streams are SMIL/CSS and cost nothing per frame; no `filter: blur`, no `backdrop-filter`; SVG glow filter only on packet heads.

---

## 6. Colour, type, panel style

```
--void   #040a14   ground (plus a soft blue bloom at 40%/62%)
--line   rgba(120,190,255,.16)  hairlines;  --line2 rgba(120,190,255,.30)
--ink    #f2f8ff   numbers and names;   --mut #8ea6bf   labels (about 7:1 on the ground)
--inq    #35c8ff  inquiries        --seq #a566ff  sequences     --out #2af0c0  outbound / bots / live
--ai     #ff5fa8  AI answers       --ord #ffbf2f  orders and money  (+ chip text #ffd77a)
```
Hue is a signal: no coloured panel fills, colour only on nodes, links, numbers and chips. Error/signed-out use #ff6262 in the status text.

Type: **Saira Condensed** 700 for numbers (88-100 px KPIs, 64-76 px network counts), **Saira** for labels (tracking .04-.3em for caps titles), **IBM Plex Mono** for clock times in the timeline. SIL OFL; self-host woff2 in `public/fonts` with `font-display: swap`, fallback Bahnschrift/Arial Narrow (the comp loads Google Fonts only so it opens as one file). `tabular-nums` everywhere.

Panel style, following the reference: no heavy boxes. Flat navy ground, hairline dividers between KPIs, a hairline left edge on the activity column, icons drawn as single-stroke SVG at one weight, hubs as ringed circles. The network is the only "glass".

---

## 7. Motion

| What | How | Speed |
|---|---|---|
| Boot | rings draw in, numbers count up from 0, clusters fade in | 1.4 s, once; skipped when the page reloads within 60 s |
| Ambient streams | 4 small dots per link, along each wire (SMIL `animateMotion`, offset by 0.8 s) | 3-4 s per trip |
| Node twinkle | opacity on about a third of cluster nodes | 4.5 s alternate |
| Core | two dashed rings counter-rotate; disc breathes | 80 s, 50 s per turn; 5 s |
| Tick clock | head moves each 5 s; lit ticks fade over 5 min | repaint 1 s |
| Event packets | head + 3-circle tail along the real link | 1.9 s per hop; follow-up 3 s; money 3.2 s |
| Core hit | ring turns white + 16 px glow; shockwave ring scale 1 to 1.4, opacity .8 to 0 | 600 ms; 1 s; at most one shockwave per 400 ms |
| Number roll | count from the shown value to the new one; brief opacity flick | 0.9 s (orders 1.2 s, peso 1.4 s), ease-out-expo |
| Timeline | new row slides in 14 px from the right | .8 s |
| Burn-in guard | whole stage shifts up to 4 px, one step a minute over a 10-minute loop | continuous |

**Reduced motion** (same media query as today): no boot, no ambient streams, no twinkle, no ring spin or breathe, no packets, no shockwave, no number roll (the number swaps), no timeline slide. Hubs and the core change edge colour for 600 ms on events; the order shows a static gold "+₱1,149" beside the Orders KPI for 4 s and the Orders node glows; the tick clock still updates (data, not motion). The comp honours this.

---

## 8. Readability on a TV

Designed for 1080p at about 4 m: KPI numbers 88 px, network counts 64-76 px, bot "replies sent" 64 px, labels 22-26 px, timeline text 21-26 px; the only text under 21 px is the clock times (18 px mono) and the chip labels (16 px, decorative redundancy of the colour and the title). Contrast of `--mut` on the navy is about 7:1. Colour never carries meaning alone: every colour sits next to a word. The dormant bot is dimmer but still readable, never red. Safe margins and the scaled canvas keep it identical on 4K.

## 9. Phone (<= 900 px)

No canvas, no wires. Single column: header (wraps; filters, clock, TV mode), KPI 2x2, then network cards in flow order: LIKHA BOT core card (signals last 5 min), Customer inquiries + AI answers (2 columns), Bot 1, Bot 2 (dormant, dashed border), Botcake, Follow-ups sent + Order value (2 columns), then LIVE ACTIVITY (8 rows). Same `data-live-*` hooks and number roll; events flash a card border colour instead of packets (saves battery). 390 px checked in the comp: no horizontal scroll (render `comp-390.png`).

## 10. States

| State | Look |
|---|---|
| Bot linked / fallback / not linked | solid ring + status "Connected" or "Fallback" / dormant dashed ring, plug icon, "DORMANT, no traffic while not linked", dashed wire, no replies number |
| Avg reply time "—" | dash, unit hidden, reason shown under it (five existing states) |
| RECONNECTING / PAUSED / SIGNED OUT | status text and dot by the title turn red / amber / red; numbers freeze, not blanked |
| No orders yet | Orders 0, Last order row absent, Order value 0 (or hidden if not available) |
| Empty timeline | "Listening for new events" only |
| Page filter on | same layout, filtered numbers |

---

## 11. Buildability (existing Blade page, no framework)

- **Stack:** Blade + plain CSS + vanilla JS + inline SVG. **No library**; the only external assets are two open-licence fonts, self-hosted. The ambient dots use SMIL `animateMotion` (supported everywhere current Chrome, Edge, Safari, Firefox run); packets keep the existing `getPointAtLength` loop.
- **Server-drawn parts:** clusters and the lattice are generated with a seeded random (the comp does it in JS; do it in Blade/PHP so the SVG is stable and has content without JS, as the current partial does).
- **Keep every hook:** `data-live-count` (replies, reply_time, orders, active, messages, ai, follow_ups, in-*, out-*), `data-live-recent`, `data-live-note`, `data-live-unit`, `data-live-unknown`, `data-live-last-order`, `data-bot-row` / `data-reply-row` with `data-linked`, `data-live-log`, `data-live-status`, `data-live-updated`, `data-live-page`, `data-path`, `data-hit`, `data-lights`. New: `data-path` keys `inq`, `seq`, `ai`, `ord` besides the three bots; `data-live-count="peso"` and `"signals"` once the data exists.
- **JS to add:** stage scale; the number roll in `put()`; the tick clock painter; multi-segment routes (message, follow-up, money); the money packet with chip; optimistic Orders/peso advance and reconcile rule (section 5); shockwave; burn-in shift; fullscreen toggle.
- **Server work (separate spec):** the four items under "needs new data".
- **Tests:** existing tests that look for the hooks keep passing; add a screenshot check at 1920x1080 and 390x844.
- **Performance:** SVG about 450 elements (clusters, lattice, links); one rAF loop only while packets move; ambient dots are compositor-driven; no growth over a day.

## 12. Privacy

Latest/Live Activity shows page names (on the live site some look like people's names) plus the bot, exactly as today. No customer names, phones or message text; the comp uses "Sample Page NN". Screenshots of the live page were looked at but not saved to this folder for that reason.

## 13. Comp notes (`comp.html`)

- Open it in a browser. 1920x1080 canvas scaled to the window; under 900 px wide it becomes the phone layout.
- Animated with simulated events: a message about every 2.4 s (reply 0.7 s later, AI 45% of the time), a follow-up about every 7 s, and **a new order with a gold "+₱" money packet every 5.2 s**; each landing counts the Orders KPI, Orders recorded and Order value up and glows gold.
- Bot 2 shows Not linked / DORMANT on purpose (the real state on Likha today).
- Not in the comp: boot sequence, unknown-count lines, burn-in shift, real selects, the 30-light pool, Fullscreen, the real top-3 sequence names. "All bots", the peso values and the Seq labels depend on the new data in section 4.

## 14. Open questions for the owner

1. **TV type:** OLED or normal LCD, 1080p or 4K? (OLED makes the burn-in shift and a slightly dimmer ground mandatory.)
2. **Order amount:** where should the peso amount come from, and is a null amount acceptable (the animation then says "+1 ORDER" with no peso total)? The page has no amount for orders today.
3. **Sequences and bot filter:** show the top-3 real sequence names with counts in place of "Seq 1/2/3", and add the "All bots" filter? Both need new data work.

## Beat the incumbent (the current page)

1. Letterboxed map, flat hierarchy, core not the heart: the network fills the page, the core is centred, inputs and bots are separate nodes, AI is its own step.
2. Too small from across the room and duplicated numbers: numbers 64-100 px, labels at least 22 px, Orders merged into one node plus the KPI and the strip.
3. Decoration without meaning (satellites, tiny legend) and no money: the tick clock carries timing, colour is a signal, and orders carry a visible money packet that rolls the totals up.
