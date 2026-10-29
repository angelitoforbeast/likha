# Result: spec 199 the Live page as a neural network room

> Replace every placeholder below. Write "n/a" if a section doesn't apply. Committed beside
> the spec: name no people and no decision ids.

**Status:** done
**Date:** 2026-10-30
**Branch / PR:** `feat/199-live-neural-network` (worktree `.claude/worktrees/live-199`), no PR: no push in this run, as the brief says
**Preview or run link:** `docs/specs/199-evidence/live.html` (opens on its own, plays simulated events and the money packet); screenshots `docs/specs/199-evidence/live-1920.png` and `live-390.png`

## Summary
The Live feed (`LiveFeed`) now gives every `order` event an `amount`: the COD of the order the encode produced for that customer, resolved at read time in one query per poll, or null. `counts` gained `order_value {today, recent}` (a cached per-day list of order events with their amounts; a known amount is never re-read, so an order counts once), `sequences` (today's top 3 by follow-ups sent, from one grouped read inside the existing settled/live tally), and the feed takes a `bot` filter. The page is rebuilt from the approved comp: a fixed 1920×1080 canvas scaled to the screen with the header, KPI strip, server-drawn neural network (seeded clusters, links, 5-minute tick clock, AI satellite, three bot hubs with a dormant state, Orders node), bottom strip with the order value, and the Live activity timeline; at phone width it becomes cards. The animation rules (routes, the money packet and its chip, merged bursts, hold-for-landing and never-count-down) are pure functions in `public/js/chatbot-live-rules.js`, tested in node; the inline driver only draws.

## Case table
| Case | Test | Result |
|---|---|---|
| S-199.1 | `ChatbotLiveNetworkTest::test_S_199_1_an_order_carries_the_cod_of_its_encoded_order_and_never_another_customers` | pass — `OK (7 tests, 88 assertions)` for the file |
| S-199.2 | `ChatbotLiveNetworkTest::test_S_199_2_an_order_encoded_later_gets_its_amount_on_a_later_read_and_counts_once` | pass (same run) |
| S-199.3 | `ChatbotLiveNetworkTest::test_S_199_3_the_order_value_is_the_sum_of_known_amounts_today_and_in_the_last_five_minutes_per_page` | pass (same run) |
| S-199.4 | `ChatbotLiveNetworkTest::test_S_199_4_the_top_three_sequences_are_todays_real_names_and_counts_by_count` | pass (same run) |
| S-199.5 | `ChatbotLiveNetworkTest::test_S_199_5_the_bot_filter_narrows_the_per_bot_counts_and_the_events` | pass (same run) |
| S-199.6 | `ChatbotLiveNetworkTest::test_S_199_6_the_page_renders_every_section_with_its_data_hooks_and_keeps_every_number_of_the_scoreboard` | pass (same run) |
| S-199.7 | `ChatbotLiveRulesTest` (4 tests: money packet / "+1 ORDER", burst merge none dropped, hold and never count down, reduced motion) | pass — `OK (4 tests, 37 assertions)` |
| S-199.8 | `ChatbotLiveNetworkTest::test_S_199_8_the_query_count_of_a_poll_does_not_grow_with_the_number_of_events` | pass (same run) |
| S-199.9 | `ChatbotLiveTest`, `ChatbotLiveScoreboardTest` | pass — `OK (6 tests, 77 assertions)`, `OK (9 tests, 99 assertions)` |
| S-199.10 | `tests/Feature` full run vs the baseline | SUITE_RESULT |

Red runs (one line each): S-199.1–.5 `Undefined array key "order_value"` / `"sequences"` / missing `amount` key before the feed change; S-199.1 second-order case `+ 5 => 1149` before the fallback fix, and the late-encode case `+ 8 => 640` before the 24 h run window; S-199.6 failed on the old view (no `name="bot"` select); S-199.7 `node:fs` (rules file not yet written); S-199.8 passed from the start (it guards growth, nothing to fail before the change).

## Story changes
- Added section S-199 to `qa/stories.md` with the spec's ten cases.
- S-199.1 widened: besides "another customer's order never lends its COD", also "another order of the same customer" and "a not-accepted encode" give null (found by the review; both are the same rule, never a borrowed peso).
- Changed assertions in spec-190/196 tests (only what the redesign moved):
  - `ChatbotLiveTest::test_S_190_3…` and `ChatbotLiveScoreboardTest::test_S_196_2…`: the event keys are `type, at, page, id, bot`, plus `amount` on order events only (was: exactly the five keys).
  - `ChatbotLiveScoreboardTest::test_S_196_1…`: labels `AI CORE` → `AI ANSWERS`, `ORDERS` → `Orders recorded`, `NEURAL MAP` → `NEURAL NETWORK`, `LATEST` → `LIVE ACTIVITY`; the paths are `forbeast, incepxionbot, botcake, inq, seq, ai, ord` once each (was `forbeast, incepxionbot, botcake, fu, ord` twice each: one map now, no separate phone map).

## Done-when checklist
- [x] All P1 cases pass with tests — the case table above; the four Live test files run green (outputs above).
- [x] The full suite adds no new failures vs 59b39b46 — SUITE_LINE
- [x] Rendered evidence page `docs/specs/199-evidence/live.html` that opens on its own and plays the money animation with simulated events — the real Blade view rendered with real-shaped fake data by `docs/specs/199-evidence/build.php`, the rules script inlined, `fetch` replaced by a simulator (orders with a COD, without one, then a burst of three; a late COD rolls the total without a second packet). Links point to `http://localhost/...` only.
- [x] Screenshots at 1920×1080 and 390 px — `live-1920.png` (a "+₱599" money packet mid-flight on Bot 1's link) and `live-390.png` (through `frame390.html`, because headless Chrome on Windows has a minimum window width wider than 390 px).
- [x] Measured query count and time per poll — see Tests.
- [x] The COD join ruling — see Rulings.
- [x] Deploy notes — see Deploy notes.
- [x] The cases table — above.
- [x] Ends with the status line.

## How to run
From the worktree (vendor installed, Node on PATH for the rules test):
```
"/c/Users/Forbeast/.config/herd/bin/php.bat" vendor/bin/phpunit tests/Feature/Chatblast/ChatbotLiveNetworkTest.php
"/c/Users/Forbeast/.config/herd/bin/php.bat" vendor/bin/phpunit tests/Feature/Chatblast/ChatbotLiveRulesTest.php
"/c/Users/Forbeast/.config/herd/bin/php.bat" vendor/bin/phpunit tests/Feature/Chatblast/ChatbotLiveTest.php tests/Feature/Chatblast/ChatbotLiveScoreboardTest.php
"/c/Users/Forbeast/.config/herd/bin/php.bat" docs/specs/199-evidence/build.php   # rebuilds live.html
```
Open `docs/specs/199-evidence/live.html?now=1` in a browser (the `now` flag starts the first simulated poll at once).

## Rulings
- Ruling: the order amount joins `chatbot_events` (order) → `order_encodes` → `macro_output.COD`. The order event's `detail.run` is the flow run that wrote the order; the same run writes the `order_encodes` row (Upload to macro, or the sheet step's live encode) with that `run_id`, same page name and PSID. The order's encode is that run's row; if the run has none, the first live encode of the same page + PSID made at or after the order and within 24 h whose run is not another order's (Encode order in a later run). The encoded order is that row when `status = done` with a macro row, else a later `done` encode on the same macro row. Amount = that macro row's `COD` (so a staff correction counts) when it is a plain positive number (commas and ₱ allowed), else null; "second order not accepted" encodes are skipped. — the run id is the only key both tables share that ties one order to one encode; page + PSID + time alone would let a customer's two orders swap CODs — cost if wrong: a wrong or missing peso on the TV, never a changed order.
- Ruling: page matching for the join uses the page name from the page map against `order_encodes.page` — that column is indexed with `created_at` (`order_encodes_page_created`), so the read needs no new index — a page renamed after its orders were encoded shows null amounts for those orders.
- Ruling: order value is a cached per-page, per-day list of order events; each counts refresh reads only the last 15 minutes of order events (new rows and late inserts) and re-resolves only the ones still null, in one query; a known amount is kept — so an order counts once, and a poll never re-reads the day — cost: a COD corrected by staff after the amount was known is not reflected until the next day.
- Ruling: amounts show as whole pesos on screen (`2050.5` → `₱2,051`); the feed keeps centavos — TV readability — cost: up to ₱0.50 display rounding per figure.
- Ruling: the bot filter narrows `counts.bots.in/out` (other bots 0, unknown 0) and the events (in SQL, by each source's bot); AI events have no bot and are left out under a specific bot; the KPI totals, order value and sequences stay page-level — the spec names only per-bot counts and events — cost: the KPIs do not change with the bot filter.
- Ruling: top-3 sequences are counted per tally range (settled + live) and joined to names by one primary-key read of the top ids; a deleted sequence shows "Sequence #id" — keeps the "never rescan the day" rule of the counts — cost: none known.
- Ruling: fonts are not self-hosted: Saira and IBM Plex Mono are not on this machine and the brief forbids downloads. The stacks use them when installed on the TV, else Bahnschrift (Windows), Roboto Condensed, Arial Narrow, system sans; monospace falls back to Cascadia Mono / Consolas — the spec's allowed fallback — cost: the TV may show Bahnschrift, as in the screenshots.
- Ruling: the "Exit TV mode" link stays beside a new "TV mode" button that toggles the browser's full screen (hidden where the Fullscreen API is missing) — both in the brief.
- Ruling: the burn-in drift moves the whole canvas up to 4 px, one step a minute over a 10-step loop, in JS (so reduced motion does not stop it) — the brief's figure.
- Ruling: the order count and order value hold a server increase for up to 8 s waiting for the landing packet, and never drop below the shown value within 60 s of the last rise; window numbers (last 5 min, active chats, signals) follow the server at once — the brief's sync rules; the rule is only meaningful for running totals.
- Ruling: the reply-time "ok" note now reads "Customer sent → reply sent" with a "Bot 1" chip by the title (the comp's text); the other four reply-time notes are unchanged.
- Ruling: the last order line sits under Orders recorded ("Last: page · time · bot") instead of the timeline's ORDER row, because the timeline only holds events seen since the page opened.
- Ruling: the timeline logs each poll's events when the poll arrives (newest on top, 8 rows), not when each packet lands — no row is lost when packets are thinned or skipped.
- Conflict noted: the spec says "use the impeccable skill … and run its polish pass". The skill's context loader downloads its engine on first run, and downloads are forbidden, so its polish playbook and craft floor were read and applied by hand. The polish pass found two browser-default surfaces (text selection, dropdown options) and themed them; the craft floor's "self-host the display face" yields to the spec's allowed fallback.

## Deferred minors
Recorded in `TODO.md` under "Live page order value (spec 199)":
- Orders still without an amount are re-resolved on every counts refresh all day — one indexed query per 5–10 s refresh, reset daily.
- The "not accepted" filter relies on the JSON key being absent — the encode code always writes a row id there.
- The fallback join can still lend a COD when a customer's earlier order is more than a day older than the resolve window and its encode lands after a later order — needs two orders of one customer within the 24 h order rule plus an encode lagging more than a day.

## Merge danger
Two-way door. Additive feed fields (`amount` on order events, `counts.order_value`, `counts.sequences`, the `bot` parameter); the counts cache keys moved to `chatbot-live:v3:*` and `chatbot-live:settled:v2:*` (the tally shape changed), so the first poll after deploy recounts the day once. No migration, no write to any order or COD. Blast radius if wrong: the Live page only (and anything reading its JSON: nothing else does). Revert: revert the branch's commits; the old cache keys simply expire.

## Deploy notes
- Migrations: none. No index needed: the amount read uses `order_encodes_page_created` (page IN, created_at ≥) and the primary key of `macro_output`; the run-owner read uses `chatbot_events (page_id, psid, created_at)`.
- Caches: none to clear; new keys `chatbot-live:v3:{page|all}`, `chatbot-live:settled:v2:{page|all}`, `chatbot-live:order-value:v1:{page|all}` (24 h).
- Assets: new `public/js/chatbot-live-rules.js` (plain script, no build step; `npm run build` not needed). No font files (system fallback, see Rulings).
- Workers: no restart needed.
- Both sites: the site name in the title, core and caption comes from the existing site setting (tested on Incepxion by `test_S_196_1_on_incepxion…`).

## Tests
- New: `ChatbotLiveNetworkTest` (S-199.1–.6, .8; sqlite in memory), `ChatbotLiveRulesTest` (S-199.7; runs `tests/js/call.cjs` on `public/js/chatbot-live-rules.js` in node, the repo's existing pattern for page JS; skipped when node is missing).
- Query count and time per poll (sqlite in memory, 40 orders + 40 messages, 80 events in the poll; measured with a throwaway test, not committed): counts cached = 10 queries, 1.7 ms; counts refresh after 11 s = 18 queries, 2.8 ms; first count of the day (cold) = 26 queries, 10 ms. The count does not grow with events (S-199.8). Real MySQL timings were not measured (no production access in this run).
- Full suite: SUITE_DETAIL

## Open questions
None blocking. For the owner to confirm when convenient: the bot filter leaves the KPI totals page-wide (see Rulings).

## Process suggestions
- The impeccable skill's launcher needs a one-time engine download; on a no-download brief its polish pass can only be done by hand. Install the engine once on this machine, or allow it in briefs that require the skill.
- Headless Chrome on Windows cannot shoot a 390 px window; `frame390.html` (an iframe at 390 px) is the reliable way, worth keeping as a shared helper.

## Proposed tasks
- Self-host Saira and IBM Plex Mono under `public/fonts` — the comp's type; needs a download the owner approves — normal.
- Measure the Live feed on production MySQL (queries and ms per poll on a busy day) — the timings here are sqlite — normal.

## Progress
- Run 1: data (amount, order value, sequences, bot filter), rules JS, page, evidence, review (one loop, plus one re-check), polish — STATUS_LINE

199: done — the Live page is a neural network room with the order's COD flowing into the totals; all P1 cases pass with tests
