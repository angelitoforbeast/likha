# Plan: spec 205, the Live page as Jarvis E

Built on spec 200 (LiveFeed counts, notes, events, loot rules, the 3D toggle saved per browser). The start prompt says to build
without waiting for a go; this plan is committed first and the work follows it.

## Threat model

- Untrusted: customer names in `botcake_chats.name` / `botcake_psids.fb_name` (from Facebook through Botcake or the gateway),
  page names, the query string of `/chatbot/live` and `/chatbot/live/feed` (page_id, bot, cursor). All already validated by
  LiveFeed::page / bot / cursor; names are only ever written with `textContent` / canvas `fillText`, and Blade-escaped.
- Trusted: the repo, config, the role check (unchanged).
- New exposure: a short customer name (first name + initial) and an opaque per-customer key in the feed JSON. No PSID,
  phone, address or message text. The page stays behind the same role check.

## Tasks

| # | Task | Side | Tier | Cases | Files |
|---|---|---|---|---|---|
| 1 | Radar per bot: extend the one grouped "active chats" read of the last 5 minutes to return, per bot, each chatting customer with last-message age, state (chatting / AI / ordered) and a "new today" flag (correlated `EXISTS` on existing indexes, same query); short names (first name + initial) for new customers and in the 200 notes list; an opaque key on order events so the coins start at that customer's dot. | backend | medium | S-205.2, .5, .6 | `app/Services/Chatbot/LiveFeed.php`, `tests/Feature/Chatblast/ChatbotLiveJarvisETest.php`, changed 200 assertions |
| 2 | Rules: vault disc count (one disc per ₱5,000, max height), the "+N" label for merged orders without amount, E's coin timing, radar dot tracking (angle per key, radius by age, blips only for new customers seen after boot, gold kept while in the window), ticker icon per event type. Pure functions in `chatbot-live-rules.js`, run in node. | frontend | medium | S-205.3, .2 (front-end side) | `public/js/chatbot-live-rules.js`, `tests/Feature/Chatblast/ChatbotLiveRulesTest.php` |
| 3 | Page: the Blade view as comp E (slim header with the funnel, clock, 3D, TV; globe with signals; vault; Bot 1 / Botcake radars, Bot 2 dormant, AI hex, follow-up arcs, replies / avg reply / chats gauges; icon ticker); numbers as DOM text in each panel (server-rendered first values, same `data-live-count` hooks); one hidden card per panel with every moved number; site name from settings. | frontend | medium | S-205.1, .4, .5 (page), .8 | `resources/views/chatbot/live.blade.php`, Live tests |
| 4 | Motion and 3D: canvas drawing at 30 fps (no blur, sprites), packets per event from a pool, loot from the bot's dot through the globe to the vault, cylinder 3D wall with the camera orbit, burn-in drift, reduced motion, 390 px column. | frontend | medium | S-205.3 (page), .7 | same view |
| 5 | Evidence and polish: `docs/specs/205-evidence/build.php` + `live.html` (fake real-shaped data, blips with name tags, loot, 3D), screenshots 1920x1080 2D / 3D and 390 px, overlap hit-test at the orbit extremes, word count on screen, query count / time per poll, full tests/Feature vs the baseline, result. | both | low | S-205.7, .9 | `docs/specs/205-evidence/*`, result |

Tasks share the view and the rules file, so they run in order in this one worktree (not parallel-safe).

## Data shape (new)

`counts.radar[bot]` = list (newest first, at most 150 per bot) of `{k, ago, s, new, name}`:
- `k`: 12 hex chars, HMAC of page|psid with the app key (stable per customer, not reversible to a PSID);
- `ago`: seconds since the customer's last message at `as_of` (0..300);
- `s`: `order` (an order by this bot for this customer today), else `ai` (an AI answer for this customer in the last 5 min),
  else `chat`;
- `new`: no message from this customer earlier today (before the 5-minute window);
- `name`: first name + initial, only when `new`, else null.
Dormant (not linked) bots and bots outside the bot filter get `[]`. Order events carry the same `k`.

## Decisions left open by the spec (taken here, listed in the result)

- "Ordered" = an order event by that bot for that customer today (the 200 ORDERED rule), so a gold dot stays gold for the
  whole time it is on the radar.
- "AI answering" = an answered AI request for the customer in the last 5 minutes.
- "New customer" = first message of the day inside the 5-minute window.
- Names in the 200 notes list are shortened too (case .5: first name + initial only in the JSON).
- Fonts: no self-hosted face exists in the repo and nothing may be downloaded, so a system stack:
  Bahnschrift Condensed → Roboto Condensed → Arial Narrow → Liberation Sans Narrow → sans-serif.
