# Result: spec 200 the Live page as a Jarvis HUD, with notes and latest customers per bot

**Status:** done
**Date:** 2026-10-30
**Branch / PR:** `feat/200-live-jarvis` (worktree `.claude/worktrees/jarvis-200`, cut from 8506b25c), no PR: no push in this run, as the brief says
**Preview or run link:** `docs/specs/200-evidence/live.html` (opens on its own; plays simulated polls with the loot, the notes and the 3D toggle); screenshots `docs/specs/200-evidence/live-1920.png` (2D, mid-loot), `live-1920-3d.png` (3D, mid-loot), `live-390.png` (phone)

## Summary
The Live feed's `counts` gained `notes`: per bot (`forbeast`, `incepxionbot`, `botcake`) `linked, chatting, ai, avg, orders, value, last {type, page, at}, customers [{name, tag, at}]`, all for the Manila day with the page filter, narrowed by the bot filter, and empty for a bot that is not linked. Every note is built inside the existing shared counts cache (5 s fresh / 10 s stale): `chatting` comes from the active-chats read (now grouped by bot), `ai` from the AI read inside the settled/live tally (joined to the call's chat by primary key for its bot), `orders`/`value` from the cached order-value list (which now keeps each order's bot; same COD join as 199), and `last`/`customers` from a new cached per-day customers state that reads only the last ~17 minutes of four indexed sources per refresh and keeps the newest 30 customers per bot, plus grouped reads for the final <= 12 customers (names, the bot's last reply and its AI mark). The page is rebuilt from comp D: header (site LIVE, live dot, page and bot filters, Manila clock, 3D/2D, TV mode, exit), KPI row, the gold reactor with the order value, orders, "+N in the last 5 min" and Last, rotating segmented rings, scan band and the 60-tick 5-minute dial, satellites (Inquiries received today, AI answers, Follow-ups sent with the top 3, the site bot's signals), three bot hubs with IN/OUT and in/out pipes, a NOTES panel per bot on an angled leader line, and LIVE ACTIVITY. Motion: CSS for the always-on parts (ring rotation, scan band, a streak on every live link, burn-in drift, boot draw-in and notes unfold), one `requestAnimationFrame` loop only while coins fly or numbers roll; the loot (six coins arcing from the bot hub into the core, the total rolling per landing, "+₱N", the gold shock ring; a "+1" plate when there is no COD) is planned by pure functions in `public/js/chatbot-live-rules.js`. 3D is CSS perspective with per-layer depth and a slow orbit, remembered per browser; 2D is the default. At phone width the HUD becomes cards.

## Case table
| Case | Test | Result |
|---|---|---|
| S-200.1 | `ChatbotLiveJarvisTest::test_S_200_1_each_bots_notes_equal_the_hand_counted_values_with_the_page_and_bot_filter_and_a_dormant_bot_is_zero` | pass — `OK (5 tests, 129 assertions)` for the file |
| S-200.2 | `ChatbotLiveJarvisTest::test_S_200_2_the_latest_customers_are_the_four_newest_per_bot_with_their_tag_and_time_and_never_another_days` | pass (same run) |
| S-200.3 | `ChatbotLiveJarvisTest::test_S_200_3_a_stored_name_is_shown_else_customer_and_the_page_short_name_and_nothing_private_leaves_the_server` | pass (same run) |
| S-200.4 | `ChatbotLiveJarvisTest::test_S_200_4_the_query_count_of_a_poll_does_not_grow_with_events_or_customers` (cold and warm refresh; 60 extra customers with chats, AI, replies and orders) | pass (same run) |
| S-200.5 | `ChatbotLiveRulesTest::test_S_200_5_…` (3 tests: coins, parts, "+1" plate, reduced motion, landing times; landings roll the total and never count down, merged bursts keep every order; 3D view saved, restored, default 2D, broken storage) | pass — `OK (7 tests, 94 assertions)` for the file |
| S-200.6 | `ChatbotLiveJarvisTest::test_S_200_6_the_page_renders_every_section_of_the_hud_with_its_hooks_and_every_number_of_spec_199` | pass (S-200.1 run) |
| S-200.7 | `ChatbotLiveTest`, `ChatbotLiveScoreboardTest`, `ChatbotLiveNetworkTest`, `ChatbotLiveRulesTest` | pass — `--filter ChatbotLive`: `Tests: 34, Assertions: 485`, no failures |
| S-200.8 | `tests/Feature` full run vs 8506b25c | pass — see Tests |

Red runs (one line each): S-200.1–.3 `ErrorException: Undefined array key "notes"` before the feed change; S-200.3 page half `…contains "Maria &lt;b&gt;Santos&lt;/b&gt;"` failed until the page rendered the notes; S-200.5 `{"error":"no function: loot"}` / `"no function: readView"` before the rules; S-200.6 first run failed on two hand counts written wrong in the test (`replies` 6 and `in-forbeast` 7, recounted from the seed to 4 and 6), not on the code; S-200.4 passed from the start (it guards growth; nothing to fail before the change).

## Story changes
- Added section S-200 to `qa/stories.md` with the spec's eight cases.
- Changed assertions in spec-190/196/199 tests (only what the redesign moved):
  - `ChatbotLiveTest::test_S_190_3…`: "no customer name" now holds for the events (`json_encode($out['events'])` has no `Juan`); the whole feed body still has no message text and no PSID (`Magkano`, `sagot`, `Hello`, `7004`). Names are allowed in the notes by this spec.
  - `ChatbotLiveNetworkTest::test_S_199_6…`: labels `NEURAL NETWORK`, `CUSTOMER INQUIRIES`, `FOLLOW-UP SEQUENCES`, `Follow-ups sent`, `Orders recorded` → `INQUIRIES RECEIVED TODAY`, `FOLLOW-UPS SENT`, ` orders · ` (the orders line in the core); paths `inq, seq, ai, ord, forbeast, incepxionbot, botcake` → `seq, ai, forbeast-in, forbeast-out, incepxionbot-in, incepxionbot-out, botcake-in, botcake-out` (one each); the top sequence `data-live-seq="0">Pa-remind · 1<` → `data-live-seq="0"><span>Pa-remind</span><b>1</b>` (name and count in their own cells, as in the comp).
  - `ChatbotLiveScoreboardTest::test_S_196_1…`: labels `Orders recorded`, `NEURAL NETWORK` → ` orders · ` (`NEURAL NETWORK` dropped); paths as above.

## Done-when checklist
- [x] All P1 cases pass with tests — case table; outputs above.
- [x] No new suite failures — baseline before code `Tests: 3674, Assertions: 70841, Failures: 2` (ExampleTest, ItemTableFitScriptTest); final in Tests.
- [x] Rendered evidence page `docs/specs/200-evidence/live.html` with real-shaped fake data that opens on its own and plays the loot, the notes and the 3D toggle — the real Blade view rendered by `docs/specs/200-evidence/build.php` with fake counts and notes (made-up names), the rules script inlined, `fetch` replaced by a simulator (an order with a COD, one without, a burst of three; the notes move customers to the top and mark ORDERED). `?now[=ms]` polls early, `?d3` opens in 3D, `?noamt` makes the first order a "+1", `?shot` swaps animation frames for timers (headless screenshots only).
- [x] Screenshots — `live-1920.png` (2D at 1920×1080: coins landing, the total rolling from ₱62,456 toward ₱63,055, Orders 263, Bot 1's newest customer ORDERED), `live-1920-3d.png` (3D, the same moment), `live-390.png` (390 px through `frame390.html`: header, KPIs 2×2, the gold core card, each bot card with its notes below, AI, follow-ups, inquiries, signals, activity).
- [x] Query count and time per poll — see Tests.
- [x] Name-source ruling — Rulings.
- [x] Deploy notes — no migration (Deploy notes).
- [x] Cases table and final line.

## How to run
- Tests: `php vendor/bin/phpunit tests/Feature/Chatblast --filter ChatbotLive` (node is needed for `ChatbotLiveRulesTest`).
- Evidence: `php docs/specs/200-evidence/build.php`, then open `docs/specs/200-evidence/live.html?now` in a browser; press 3D / 2D in the header.
- Screenshots (headless Chrome): `chrome --headless=new --window-size=1920,1080 --virtual-time-budget=3380 --screenshot=live-1920.png "file:///…/live.html?now=2000&shot"` (add `&d3` for 3D; `frame390.html` with `--window-size=390,2900` for the phone).

## Rulings
- **Name source.** 1) the newest non-empty `botcake_chats.name` of that page + PSID today: both entry points write it with every message (the Botcake JSON API sends the customer's Facebook full name; the gateway writes the name from `botcake_psids`, else the customer's previous named chat), so it is the name the system itself resolved, refreshed every message; 2) else `botcake_psids.fb_name` for that PSID on the same page (matched by page key, as the gateway does), for customers whose only events today are bot-side (reply, follow-up, order); 3) else "Customer · <page name cut to 18 characters>", never the PSID. Not used: the encoded order's receiver name (`macro_output`): it is the shipping receiver, sits beside the address and phone, and can differ from the person chatting. Tested in S-200.2 (the `botcake_psids` fallback; the same PSID's row on another page is ignored) and S-200.3 (the newest named chat wins over an older one and over a later empty one; no name gives "Customer · Likha Home Essent…").
- **Customer of a bot, and the time.** A customer belongs to a bot today when any of these went through it: a message in (the gateway that delivered it, `chatVia`), a delivered reply or follow-up out (`via`), an order (`detail.via`), an AI answer (the bot of the call's chat). The time shown is the latest of those.
- **Tags.** ORDERED: an order event today on that bot (from the order-value list); AI: the bot's last delivered reply to them today carries the `ai_text` mark (the flow engine stores it on every message made from an AI answer, in our record only, never sent to Meta); else CHATTING.
- **Last activity** types: message → "Inquiry", reply → "Reply", follow-up → "Follow-up", order → "Order", AI → "AI answer".
- **Dormant and the bot filter.** A gateway bot that is not set up returns `linked: false` and zeros; with the bot filter, the other bots' notes are zeros and empty lists (their `linked` stays), the same rule 199 uses for per-bot in/out.
- **Notes wait for the loot.** When a poll carries an order that plays coins, that bot's notes update at the first coin landing (ORDERED and the value arrive with the money), at most 10 s later; reduced motion, phone width or a hidden tab update them at once.
- **Status colours.** Two colours only: the reconnecting / paused / signed-out status uses gold instead of 199's red; dormant is dashed and dim, never red.
- **Fonts.** Comp D's system faces (Bahnschrift, Cascadia Mono / Consolas), nothing loaded; downloads are out of this job (same as 199).
- **Impeccable.** The skill's context launcher was not run: it downloads its engine on first use, and downloads are not allowed here. The build followed comp D directly plus the skill's craft floor and polish reference (themed selection and focus rings, tabular numerals, contrast, states: no follow-ups yet, no customers yet, empty activity, dormant, reconnecting, signed out).
- **Removed** `resources/views/chatbot/partials/live-map.blade.php` (199's network partial, no longer included anywhere).

## Deferred minors
- The always-on CSS parts (ring rotation, scan band, streaks) run all day on the TV; they are compositor animations on a handful of elements. Not measured on the TV box itself.
- The headless screenshots needed `?shot` (timers instead of animation frames) because headless Chrome with a virtual time budget barely runs `requestAnimationFrame`; the page itself is unchanged.
- In 3D the Botcake notes plate (nearest the camera) overlaps the Bot 2 notes during part of the orbit, as in the comp's 3D capture.

## Merge danger
- `LiveFeed` cache keys moved (`chatbot-live:v4`, `settled:v3`, `order-value:v2`, new `customers:v1`), so after deploy the first refresh per page filter recounts the day once; the old keys expire on their own (24 h).
- The view was rewritten whole; another branch editing `resources/views/chatbot/live.blade.php` or the removed `partials/live-map.blade.php` will conflict.

## Deploy notes
- Migrations: none. Every new read uses an existing index: `botcake_chats (received_at)`, `(page_id, psid, received_at)` and its primary key (the AI join); `chatbot_replies (sent_at)`, `(page_id, psid, sent_at)`; `chatbot_events (type, created_at)`; `chatbot_ai_requests (created_at)`; `botcake_psids (psid)`.
- Assets: `public/js/chatbot-live-rules.js` changed (plain script, no build step). No `npm run build`, no fonts.
- Both sites: the title and the core read the site name (`INCEPXION LIVE` / `INCEPXION BOT` on Incepxion), covered by `test_S_196_1_on_incepxion…`.
- The first counts refresh of each day (or after a cache flush) reads the whole day once for the customers state (one grouped read per source); on a busy day that is one read of ~12k chats grouped to the day's distinct customers. Later refreshes read ~17 minutes.

## Tests
- Baseline (8506b25c code, before changes): `Tests: 3674, Assertions: 70841, Failures: 2, PHPUnit Deprecations: 92, Skipped: 4` (ExampleTest, ItemTableFitScriptTest).
- Final: `Tests: 3682, Assertions: 71025, Failures: 2, PHPUnit Deprecations: 92, Skipped: 4` (the same two known failures: ExampleTest, ItemTableFitScriptTest); 8 tests more than the baseline (the S-200 tests). One guard landed after that run (a customer row without page or PSID is skipped in `LiveFeed`), so the Live files ran again on the final tree (next line).
- Live files: `php vendor/bin/phpunit tests/Feature/Chatblast --filter ChatbotLive` → `Tests: 34, Assertions: 485`, no failures (deprecations only).
- Query count and time per poll (sqlite in memory; the S-200 day plus 400 more chats and replies and 20 more orders; one feed call with cursors; measured with a throwaway test, not committed):
  - cached counts (within 5 s): 13 queries, 1.9 ms in queries, 4.4 ms total;
  - counts refresh (after 10 s): 34 queries, 4.5 ms in queries, 9.8 ms total (8 of them new: four window reads for the customers state and four for the final <= 12 customers);
  - first count of the day (cold): 40 queries, 7.1 ms in queries, 59.2 ms total.
  The count does not grow with events or customers (S-200.4). Production MySQL timings were not measured (no production access in this run).

## Open questions
- None blocking. For the owner when convenient: the latest-customer names come from the chat record (the Facebook name); if he prefers the receiver name of the encoded order for ORDERED customers, that is a small change (see Rulings for why it was not used).

## Process suggestions
- Keep `frame390.html` and the `?shot` timer switch as the shared way to screenshot animated evidence pages in headless Chrome.

## Proposed tasks
- Measure the Live feed on production MySQL on a busy day (queries and ms per refresh, and the cold read) — normal.
- Self-host a condensed face (e.g. Saira Condensed) if any office TV is not a Windows box — needs a download the owner approves — low.

## Progress
- Run 1, stage data: per-bot notes and latest customers with names and tags in `LiveFeed` (`counts.notes`); the spec-190 privacy assertion moved to the events.
- Run 1, stage rules: loot split, coin landing times, saved 3D view in `chatbot-live-rules.js`.
- Run 1, stage page: the HUD view, motion, 3D and the 390 px cards; moved selectors in the 196/199 tests; S-200.6.
- Run 1, stage evidence and polish: evidence page, screenshots, measured query counts, full suite. Status: done.

200: done — Live page rebuilt as the comp-D Jarvis HUD with per-bot notes, named latest customers, loot and a remembered 3D toggle; all P1 cases pass, no new suite failures
