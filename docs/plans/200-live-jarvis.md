# Plan 200: the Live page as a Jarvis HUD, with notes and latest customers per bot

Spec: `docs/specs/200-live-jarvis.md`. Design contract: `docs/design/200-live-jarvis/comp-d.html` + `notes.md` section D.
Risk tier: medium for every task (the spec's tier). No new route, no new permission, no new query parameter: the same
`page_id`, `bot` and cursors behind the existing validation. Customer names are new output on an existing, signed-in page.

## Data design (decided here, the spec leaves it open)

`counts` gains one key, `notes`, keyed by bot (`forbeast`, `incepxionbot`, `botcake`):

```
notes.<bot> = {linked, chatting, ai, avg, orders, value, last: {type, page, at} | null,
               customers: [{name, tag: ORDERED|AI|CHATTING, at: "HH:MM"}]  (max 4, newest first)}
```

Every number is built inside the existing cached `buildCounts` (5 s fresh / 10 s stale, shared by every viewer of the
same page filter), so a poll never adds reads of its own; the bot filter is applied after the cache (`narrow`), like
199's per-bot in/out: the other bots' notes become zeros and empty lists.

| Note | Source (same rule as 199) | Read |
|---|---|---|
| chatting | distinct page + PSID with a non-standby chat in the last 5 min, per `chatVia` bot | the existing `active` query, grouped by bot too (sum = `active`, unchanged) |
| ai | answered AI calls today (no error, not the encode model), bot = `chatVia` of the call's chat (`chat_id`); no chat = unknown | the existing AI query in `tally` (settled + live parts), left-joined to `botcake_chats` by primary key and grouped by bot |
| avg | Bot 1 only: `reply_time.seconds` (Forbeast); others `null` ("—") | none |
| orders, value | today's order events of the bot (`detail.via`), value = the known COD amounts (199 join) | the existing cached order-value list, which now also keeps each order's bot |
| last | the newest event of the bot today: message in, reply / follow-up out, AI answer, order | the customers state below |
| customers | the 4 newest distinct page + PSID of the bot today by their last event | the customers state below + 3 small grouped reads for the final <= 12 |

### Customers state (one per page filter and Manila day, cached like the order-value list)
`{day, to, bots: {bot: {"page|psid": at}}, last: {bot: {type, page_id, at}}}`. Each refresh reads the window
`[max(day start, to - 16 min), now)` of four sources, each one grouped query on its indexed time column:
- `botcake_chats` (`received_at`, not standby) grouped by `chatVia`, page_id, psid -> MAX(received_at);
- `chatbot_replies` (`sent_at`, kind send, delivered status, via in the bots) grouped by via, page_id, psid, is-sequence -> MAX(sent_at);
- `chatbot_events` orders (`created_at`) grouped by `detail.via`, page_id, psid -> MAX(created_at);
- `chatbot_ai_requests` answered (`created_at` index, answered in the window) joined to its chat for the bot -> MAX(answered_at).
Merge keeps the max time per customer and per bot the newest 30 customers (the top 4 by last event is exact: a dropped
customer had 30 newer ones, and any new event of theirs re-enters with its new time). The first read of the day (or
after the cache is lost) reads the whole day once, as the order-value list and the settled tally already do.

Then, for the final <= 12 customers (never per customer):
- tag ORDERED: an order of that customer on that bot in today's order-value list (no query);
- tag AI: the bot's last delivered reply to them today carries the AI's own answer (`content` has the `ai_text` mark that
  the flow engine stores on a message made from an AI answer, never sent to Meta): one grouped read of MAX(id) per
  (via, page_id, psid) + one read of those ids' flag;
- name: see the name ruling; one read of today's named chats of these customers, plus at most one `botcake_psids` read for
  the ones without.

### Name source (ruling, tested)
1. The newest non-empty `botcake_chats.name` of that page + PSID today. Both entry points write it with every message:
   the Botcake JSON API sends the customer's Facebook full name, and the gateway writes the name from `botcake_psids`
   (Botcake's customer list) or the customer's previous named chat. It is the name the system itself resolved for the
   message, refreshed every message.
2. Else `botcake_psids.fb_name` for that PSID on the same page (page key match, as the gateway does): a customer with only
   bot-side events today (reply, follow-up, order) still gets the Botcake name.
3. Else "Customer · <page short name>" (page name cut to 18 characters).
Not used: the encoded order (`macro_output` receiver name): it is the shipping receiver typed for delivery, sits next to
the address and phone, and can differ from the person chatting.

## Front end
- `resources/views/chatbot/live.blade.php` rebuilt from comp D on the same 1920x1080 scaled stage: header, KPI row,
  reactor (rotating rings, 60-tick 5-minute dial, scan band, gold disc with Order value today, orders, +N in the last
  5 min, Last), satellites (Inquiries received today, AI answers, Follow-ups sent + top 3, Likha bot signals), three bot
  hubs with IN / OUT and in / out pipes, a NOTES panel per bot on an angled leader line, LIVE ACTIVITY on the right; the
  spec-199 extras stay (unconfirmed-free numbers, unknown in/out lines, reply-time note). Two colours: ice and gold.
- Motion: one `requestAnimationFrame` loop only while something moves (coins, roll-ups); idle motion is CSS (ring
  rotation, scan band, pipe streaks as one dashed stroke per live link, burn-in drift) so the in-flight count stays under
  ~30 (6 streaks, 3 rings, scan, <= 12 coins, label, shock). Status dots pulse once per successful poll.
- `public/js/chatbot-live-rules.js` (pure, node-tested) gains: `loot(group, reduced)` (6 coins and parts summing to the
  amount with a "+₱N" label, or one "+1" plate and no pesos; reduced = no coins), `lootTimes` (coin landing times),
  `view3d.read / save` (localStorage injected, try/catch, default 2D), `customers` diff (which name is new at the top).
- 3D: CSS perspective on the world, per-group `translateZ` from comp D, camera eased by the same loop only while toggling,
  then a CSS orbit animation; header / KPIs / activity flat. 390 px: cards (header, KPIs 2x2, core card, one card per bot
  with its notes, AI, follow-ups, tape), no rings, no 3D.

## Tasks
| # | Task | Side | Cases | Tier |
|---|---|---|---|---|
| 1 | Per-bot notes numbers in `LiveFeed` (chatting, ai, avg, orders, value, last), bot filter, dormant zeros | backend | S-200.1, S-200.4 | medium |
| 2 | Latest customers (state, tags) and names | backend | S-200.2, S-200.3, S-200.4 | medium |
| 3 | Rules: loot, landing parts, 3D state, customer diff | frontend | S-200.5 | medium |
| 4 | The page: comp D layout with every hook, 199 numbers kept, notes rendered | frontend | S-200.6, S-200.3 (page) | medium |
| 5 | Motion and 3D, reduced motion, 390 px | frontend | S-200.5 (driver), S-200.6 | medium |
| 6 | Existing tests' moved selectors, full suite, evidence page, screenshots, polish | both | S-200.7, S-200.8 | medium |

Tasks 1-2 share `LiveFeed.php` and run in order; 3-5 share the view and run in order. No migration expected (every read
uses an existing index: `received_at`, `sent_at`, `created_at`, `chatbot_events (type, created_at)`, primary keys,
`botcake_chats (page_id, psid, received_at)`, `chatbot_replies (page_id, psid, sent_at)`, `botcake_psids (psid)`).
