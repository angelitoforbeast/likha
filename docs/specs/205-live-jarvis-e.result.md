# Result: spec 205 the Live page as Jarvis E

**Status:** done
**Date:** 2026-10-30
**Branch / PR:** `feat/205-live-jarvis-e` (from 43a4e713), not pushed, no PR
**Preview or run link:** `docs/specs/205-evidence/live.html` (opens on its own, plays simulated polls: blips with name tags, loot into the vault, 3D toggle); screenshots in `docs/specs/205-evidence/`

## Summary

/chatbot/live is now comp E, the hologram wall: a slim header (logo, site name, a live dot that pulses once per poll, the page and bot filters behind one funnel button, the Manila clock, 3D, TV, exit), the bot core globe in the middle with its one number (signals in the last 5 minutes), a gold vault under it (order value today COD big, orders small, a coin stack of one disc per ₱5,000, up to 14), and eight translucent panels on spokes: Bot 1 and Botcake as radars, Bot 2 as a dim wireframe with a power icon while not linked, the AI hex grid, the three follow-up arcs, and the Replies, Avg reply and Chats gauges. A one-row icon ticker replaces the LIVE ACTIVITY column. Every number taken off the screen is in a card per panel (hover, tap or keyboard focus).

Data: LiveFeed's one grouped "active chats" read of the last 5 minutes now also returns, per bot, every chatting customer as a radar dot (age, state chat / AI / ordered, new today, short name only when new, an opaque key), with the same number of queries. Customer names are first name + initial everywhere in the feed (also in the spec 200 notes list). Loot: coins pop from the bot's youngest gold dot, fly through the globe (it flashes as they pass) and land on the vault stack; the total rolls up per landing; no amount = one "+1" plate ("+N" when merged).

The page's rules script now loads as `chatbot-live-rules.js?v=<content hash>` (the repo's existing `md5_file` pattern), so a browser's cached copy is never used after a release. The 3D wall was measured to keep every panel on screen and free of overlaps at both orbit extremes and through the 2D-to-3D fold.

## Case table

| Case | Test | Result |
|---|---|---|
| S-205.1 | `ChatbotLiveJarvisTest::test_S_205_1_every_panel_of_comp_e_renders_with_its_canvas_spoke_card_and_data_hooks_and_the_scripts_carry_a_version`, `ChatbotLiveRulesTest::test_S_205_1_the_ticker_draws_one_icon_per_event_type` | pass |
| S-205.2 | `ChatbotLiveJarvisTest::test_S_205_2_each_bots_radar_is_its_customers_of_the_last_five_minutes_with_state_and_new_and_the_filters_apply`, `ChatbotLiveRulesTest::test_S_205_2_radar_dots_sit_by_age_at_a_stable_angle_blip_once_for_a_new_customer_and_gold_comes_from_the_youngest_order` | pass |
| S-205.3 | `ChatbotLiveRulesTest::test_S_205_3_the_vault_stacks_one_disc_per_five_thousand_up_to_a_max_and_the_loot_says_plus_amount_or_plus_orders` + the kept S-199.7 / S-200.5 rule tests (bursts merged, none dropped, no count-down inside a minute, reduced motion without coins) | pass |
| S-205.4 | `ChatbotLiveJarvisTest::test_S_205_4_each_panels_card_carries_every_number_moved_off_the_screen` (asserts the numbers of 11 cards) | pass |
| S-205.5 | `ChatbotLiveJarvisTest::test_S_205_5_names_are_first_name_and_initial_only_escaped_and_nothing_private_is_in_the_json_or_the_page` | pass |
| S-205.6 | `ChatbotLiveJarvisTest::test_S_205_6_the_query_count_of_a_poll_does_not_grow_with_radar_customers` (3 dots vs 93 dots: same count, cold and warm) | pass |
| S-205.7 | headless Chrome hit-test, `live.html?d3&hit` (every 6 px of the screen, `elementsFromPoint`, panels per point; and each panel's box in stage pixels) | pass: 0 overlapping points and 0 panels outside 76..1046 px at orbit −7° and +6.96°, at 8 more orbit phases, and at 10 moments of the fold |
| S-205.8 | the five Live test files | pass: 42 tests, 642 assertions |
| S-205.9 | full `tests/Feature` | pass: no new failure (the two known; one time-limit flake in an unrelated NightRun test passes on rerun, see Tests) |

Red runs (one line each): data `S_205_2/5/6` — `ErrorException: Undefined array key "radar"`; rules `S_205_1/2/3` — `{"error":"no function: discs"}` / `dotPos` / `icon`; page `S_205_1/4` and the rewritten `S_200_6` — failed against the spec 200 page (no `data-pn`, no cards).

## Story changes

- Added the S-205 section to `qa/stories.md` (cases .1 to .9 as in the spec, with the tests). No spec 199/200 story was changed; their changed test assertions are listed under Tests.

## Done-when checklist

- [x] P1 cases pass — case table above.
- [x] Evidence page `docs/specs/205-evidence/live.html` with real-shaped fake data — the real Blade view rendered by `docs/specs/205-evidence/build.php` with fake counts (68 Bot 1 dots, 3 Botcake dots, made-up short names), the rules script inlined, `fetch` replaced by a simulator: each poll brings one or two new customers (blips with a name tag), messages, replies, AI, follow-ups and an order (with a COD, without, or a burst of three). Switches: `?now`, `?shot`, `?d3`, `?orbit=<ms>`, `?hit`, `?wc`, `?card=<panel>`, `?noamt`.
- [x] Screenshots — `live-1920.png` (2D, mid-loot: coins landing on the stack, total rolling, two stacked name tags on Bot 1's radar), `live-1920-3d.png` (3D curved wall, mid-loot), `live-1920-3d-orbit-minus7.png` and `live-1920-3d-orbit-plus7.png` (both orbit extremes), `live-1920-card-forbeast.png` (Bot 1's card on keyboard focus), `live-390.png` (phone column through `frame390.html`).
- [x] Word count on screen — 44 tokens (16 numbers, 28 words) at the mid-loot moment, from `live.html?now=1500&shot&wc=3300` (visible DOM text plus canvas `fillText` with alpha > 0.05): `BOT 1 IN 11,587 OUT 17,896 BOTCAKE IN 711 OUT 3 BOT 2 AI 9,423 FOLLOW-UPS 22,699 REPLIES 17,899 ₱ 62,740 263 ORDERS AVG REPLY 5.4 s CHATS 73 401 LIKHA BOT ₱ ₱ ₱ LIKHA LIVE 16:27:43 3D TV Paolo G. Rey N.` — the same as comp E's 44 (three are the ₱ on coins in flight, two are a name tag).
- [x] Query count / time per poll — see Tests.
- [x] Deploy notes — below (no migration).
- [x] Result with the final line.

## How to run

- Tests: `php vendor/bin/phpunit tests/Feature/Chatblast/ChatbotLiveJarvisTest.php` (and `ChatbotLiveRulesTest`, `ChatbotLiveTest`, `ChatbotLiveNetworkTest`, `ChatbotLiveScoreboardTest`); the rules tests need `node`.
- Evidence: `php docs/specs/205-evidence/build.php`, then open `docs/specs/205-evidence/live.html` (add `?d3` for 3D).
- Screenshots (headless Chrome): `chrome --headless=new --hide-scrollbars --window-size=1920,1080 --virtual-time-budget=3300 --screenshot=live-1920.png "file:///…/live.html?now=1500&shot"` (add `&d3&orbit=1768` / `&d3&orbit=31600` for the −7° / +7° orbit ends); phone: `frame390.html` with `--window-size=390,2700`.
- Hit-test: `chrome --headless=new --window-size=1920,1080 --virtual-time-budget=6000 --dump-dom "file:///…/live.html?now=1500&shot&d3&orbit=1768&hit=5000"` and read `data-hit` on `<html>`.

## Rulings

- The start prompt says to build without waiting for a go; CLAUDE.local.md asks for a reviewer's go on the plan. The prompt was followed: the plan is committed (`docs/plans/205-live-jarvis-e.md`) and the build followed it.
- A coordinator note during the build (not an "Amendment 205-K") was followed: page-specific JS loads with a content version (test in S-205.1), and every 3D panel stays on screen at the orbit extremes (the hit-test in S-205.7 checks it).
- "Ordered" (gold dot) = an order by that bot for that customer today (spec 200's ORDERED rule), so a gold dot stays gold for its whole time on the radar (decision 5).
- "AI answering" (thin ring) = an answered AI request (not Encode order) for the customer in the last 5 minutes.
- "New customer" = no message from the customer earlier today than the 5-minute window. Only new customers carry a name; the name tag shows once, 3.5 s, for customers that appear after the page opened (the first load has no blips).
- Case .5 says first name + initial only in the JSON, so the spec 200 notes list (still in the feed, decision 4) now carries short names too (`Ana R.`). Markup in a name is stripped before shortening.
- Order events do not carry the customer: coins start at the bot's youngest gold dot (or the radar centre). An order without a bot starts at the globe.
- Replies gauge (no "same hour yesterday", decision 5): the arc is the last 5 minutes' confirmed replies against today's average 5 minutes (half = normal pace, full = double or more). The card says so.
- Avg reply speedometer 0–15 s with the comp's three bands; no needle when there is no value. Chats ring: 100 dots, one lit per active chat.
- Vault: one disc per ₱5,000, max 14 discs, no target (decision 5). An empty vault shows a dashed floor.
- AI hex: a cell lights per AI answer event seen by the page and fades over a minute (so it is dark right after the page opens).
- Fonts (decision 7): nothing self-hosted is in the repo and nothing may be downloaded, so a stack: Bahnschrift Condensed → Roboto Condensed → Arial Narrow → Liberation Sans Narrow → Nimbus Sans Narrow → sans-serif (labels: Bahnschrift SemiCondensed → Segoe UI → Roboto → Liberation Sans → Arial).
- Packets ride spokes per real event from a pool (2 per spoke) instead of the comp's fixed timers; with 12 coins at most 26 animated elements are in flight (decision 6).
- 3D changes against the comp recipe (to keep panels on screen and apart): panel height factor and scale 1.3 (comp 1.45), camera pulled back 290 px (comp 240), the world lifted 8 px, the orbit grows with K³ and the panel scale with K² during the fold.
- Phone (≤ 900 px): one column (vault, globe, Bot 1, Botcake, Bot 2, AI, follow-ups, replies, avg reply, chats, ticker), each panel zoomed to the width; no spokes, no 3D button, coins land instantly.
- The cache key of the counts moved to `chatbot-live:v5` (the shape changed), so a deploy never serves counts without `radar`.

## Deferred minors

- The 2D→3D tween was checked at 10 sampled moments, not frame by frame.
- Not run on the office TV and not watched for hours (canvas redraw at 30 fps; a TV box should be checked for heat and frame drops).
- No skeptic-reviewer run: that agent is not available to this session; the work was checked by the tests, the hit-test and the screenshots only.

## Merge danger

- `resources/views/chatbot/live.blade.php` is rewritten (spec 200's markup is gone). Any branch that edits the spec 200 view will conflict.
- `app/Services/Chatbot/LiveFeed.php`: `active()` changed signature and query; `buildCounts()` now reads the order-value list before the active chats. Other branches touching LiveFeed may conflict there.
- Five Live test files changed assertions (listed under Tests).

## Deploy notes

- No migration, no new config or env, no new route, no npm build (the rules file is a plain file in `public/js`).
- The new counts cache key (`v5`) fills itself within one poll; old `v4` entries expire on their own.
- A TV that is open keeps the old page until it reloads; the new page loads `chatbot-live-rules.js?v=<hash>`, so the browser fetches the new rules file.

## Tests

- Baseline on 43a4e713 (full `tests/Feature`): `Tests: 3682, Assertions: 71025, Failures: 2, Skipped: 4` — `ExampleTest::test_the_application_returns_a_successful_response`, `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`.
- After (full `tests/Feature`): `Tests: 3690, Assertions: 71182, Failures: 3, Skipped: 4` — the two known failures, plus `NightRunAstraAddressRulesTest::test_S_26_18_…` failing its own time limit (`25.65 s` against `< 25 s`) while other worktrees ran their suites on the same machine; rerun alone twice: `OK (1 test, 13 assertions)` in 23.3 s and 23.0 s. This branch does not touch NightRun (the only changed service is `app/Services/Chatbot/LiveFeed.php`).
- Live tests: `ChatbotLiveJarvisTest` OK (10 tests, 244 assertions), `ChatbotLiveTest` OK (6, 77), `ChatbotLiveNetworkTest` OK (7, 81), `ChatbotLiveScoreboardTest` OK (9, 93), `ChatbotLiveRulesTest` OK (10, 147).
- Changed assertions in existing tests (S-205.8):
  - `ChatbotLiveJarvisTest::test_S_200_2` and `test_S_200_3`: customer names are short (`Ana R.`, `Cara L.`, `Ben C.`, `Dan U.`, `Fe G.`, `Eve T.`, `New N.`, `Maria S.`); `S_200_3` now asserts the JSON has no surname instead of the escaped full name on the page (there is no customer list on the page; escaping is S-205.5).
  - `ChatbotLiveJarvisTest::test_S_200_6`: rewritten for E. Kept: every spec 199 number in its `data-live-count` hook with its value, the recent / note / unit / unknown / kpi / seq hooks, `data-bot-row` + `data-linked`, the Bot 1 notes numbers, the bans (red, blur, external fonts and scripts; `shadowBlur` added). Dropped (not in E): the reactor rings, scan band, 60-tick 5-minute clock, the "Last:" line in the core, the LIVE ACTIVITY log, the in/out `data-path` pipes, the DORMANT / "Latest customers" text and the customer rows. Added: `data-live-filter`, `data-live-ticker`.
  - `ChatbotLiveNetworkTest::test_S_199_6`: the uppercase HUD labels became the card labels (`Inquiries received today`, `Follow-ups sent today`, …); `data-path` → `data-spoke`; the 60-tick check removed; `data-live-log` → `data-live-ticker`.
  - `ChatbotLiveScoreboardTest::test_S_196_1`: label list updated the same way (`' orders · '` and `LIVE ACTIVITY` removed); `data-path` → `data-spoke`. `test_S_196_5`: `not linked` → the Bot 1 card's `data-n="state">Not linked<`.
- Query count and time per poll (sqlite in memory; the S-200 day plus 400 more chats with replies, 134 AI answers and 20 orders; one feed call with cursors; throwaway test, not committed):
  - cached counts (within 5 s): 15 queries, 1.9 ms in queries, 4.5 ms total;
  - counts refresh (after 10 s): 36 queries, 6.8 ms in queries, 14.7 ms total, 245 radar dots;
  - first count of the day (cold): 42 queries, 6.6 ms in queries, 31.0 ms total, 251 radar dots.
  The radar adds no query: it is the existing active-chats read with two indexed `EXISTS` and two aggregates. (The 2 more than spec 200's figures come from this seed passing the 200-event cap on two event sources, which costs a count and a re-read each.)

### Progress

- Run 1, stage data: radar per bot, short names, tests S-205.2/.5/.6 — done (d6f8d08c).
- Run 1, stage rules: discs, plus-N plate, landing timing, dot tracking, ticker icons, tests — done (69a0568c).
- Run 1, stage page: comp E view, cards, versioned script, Live tests updated — done (950270d1).
- Run 1, stage motion/3D and polish: 3D fit and fold without overlap, stacked name tags, phone column, evidence page, screenshots, hit-test, word count, query measure, full suite — done.

## Open questions

1. Replies gauge: is "last 5 minutes against today's average pace" the meaning wanted for the arc (no yesterday data, no target)? — settled by Amendment 205-1: yes, pace; the card now says so.
2. Fonts: on a non-Windows TV the numbers fall back to Roboto Condensed / Arial Narrow / sans-serif. If the TV is not Windows and the look matters, a condensed face (e.g. Saira Condensed, SIL OFL) would have to be added to the repo, which needs a download: yes or no? — settled by Amendment 205-1: the font stays as it is.
3. Coins start at the bot's youngest gold dot, not necessarily the customer who just ordered (order events carry no customer). Fine, or add the customer key to order events (changes two older event-shape assertions)? — settled by Amendment 205-1: the coin keeps starting at the youngest gold dot.

## Process suggestions

- Keep `?hit` (every-6-px `elementsFromPoint` plus panel boxes in stage pixels) in evidence pages of any 3D layout: it found a transient corner overlap in the fold that the orbit-extreme screenshots did not show.
- Version every page-specific `public/js` / `public/css` file with the `md5_file` query string (as here).

## Proposed tasks

- Run the Live page on the office TV for a day and check frame rate and heat (normal).
- Measure the feed on production MySQL on a busy day, especially the radar read's two `EXISTS` (normal).

## Amendment 205-1

**Date:** 2026-10-30/31 · **Commits:** `aae76003` (orders add up, pace wording), `342ccee8` (TV in 2D, 3D labels, AI hex on load, no ticker), `8146402c` (label fit, evidence, screenshots), `6daa51ee` (stories), plus this result.

### Changes

| # | Change | Where |
|---|---|---|
| 1 | The day's order list (the source of each bot's order count, the "ordered" gold dots and the peso total) now reads from 15 minutes before its own last read instead of 15 minutes before now, so orders placed while nobody had the page open are kept. Its cache key moved to `chatbot-live:order-value:v3` so a deploy rebuilds the full day once. The counts carry `order_split` = `other` (orders with no bot on this screen: no `via` on the event, or a bot that is not linked here) and `no_amount` (orders with no encoded COD number). With the bot filter, the other bots' orders are added to `other`, so the shown bot + other = total under every filter. The vault card shows "Other / no bot" and "Without amount" (data hooks `data-live-split`, updated every poll). No new query. | `app/Services/Chatbot/LiveFeed.php` (`orderValue`, `orderSplit`, `narrow`), `live.blade.php` (vault card, `setCounts`) |
| 2 | TV mode always shows 2D: what is shown is `LiveRules.viewFor(saved, tv)`; entering TV snaps to 2D, leaving it returns to the browser's saved choice, which TV never changes. In 3D every label grows with the fold (`--lk` = 1 + 0.3 K; labels scale up and away from their number; the core name scales as one; AVG REPLY grows inward). Measured at 1920 × 1080 in 3D: smallest label 18.8 px (vault ORDERS at the +7° orbit end), all others 19–32 px; before: 15 px. | `chatbot-live-rules.js` (`viewFor`), `live.blade.php` (`goal`, `setTv`, `LK`, `.lb` CSS) |
| 3 | The counts carry `ai_minute`: the AI answers of the last minute in six 10-second slices (newest first, ending 5 s before now, since newer answers arrive as events on the first poll). They are six more `SUM(CASE …)` columns of the AI read that already runs, so no new query. On load the page lights one hex cell per answer at its age (`LiveRules.aiCells`), then continues from live events as before. | `LiveFeed.php` (`tally`), `chatbot-live-rules.js` (`aiCells`), `live.blade.php` (`lightFrom`) |
| 4 | The bottom-right icon ticker is removed: the canvas element, its CSS (stage and phone), its drawing, its event queue and the `icon` rule. The other event effects (spoke packets, AI hex, coins) are unchanged. No card text described the ticker, so no card changed. | `live.blade.php`, `chatbot-live-rules.js` |
| 5 | The Replies card says: "Gauge shows: pace, not a goal", "Pace: last 5 min vs today's average 5 min", "Half / full: usual pace / double or more". | `live.blade.php` |

### Cause of the order gap (item 1)

It was a bug in the per-bot count, not orders without a bot. The orders total on the vault comes from a running tally that never skips a stretch of time. Each bot's order count (and the peso total) comes from a second, cached list of the day's orders, and every refresh only added the orders of the last 15 minutes. The refresh runs only while someone has the Live page open, so when the page was closed for more than 15 minutes (for example the TV off for a while), the orders placed in that time were never added to the list: they were in the 707 but in no bot, and their COD was missing from the peso total. Reproduced in `test_S_205_10_…`: before the fix the test saw Bot 1 = 1 and Botcake = 1 where the right answer was 3 and 2. Ruled out: a time-window difference between the two counts (both are today, page filter, same table and type) and Botcake not linked (Botcake is always on screen). Orders that genuinely have no bot can still happen (an order event without `via`, which today's order node always writes, or an order of a bot that is not linked on this server, e.g. Bot 2), and those are the "Other / no bot" line. Not checked on production data (no server access in this job): after deploy, "Other / no bot" should be near 0 on Likha; a large number there would point to something else.

### Tests

- New: `ChatbotLiveJarvisTest::test_S_205_10_per_bot_orders_plus_other_equal_the_total_after_a_gap_in_polling_and_orders_without_cod_are_counted` (fixture: orders by three bots, one without `via`, three without COD, a 3.5-hour gap without polls; asserts per bot + other = total = 7, without amount = 3, value ₱5,699.50, the bot filter, the page filter, Bot 2 unlinked → other, and the card lines); `test_S_205_11_tv_mode_renders_2d_through_the_rules_and_3d_labels_grow_with_the_fold`; `test_S_205_12_the_counts_carry_the_ai_answers_of_the_last_minute_in_ten_second_slices_for_the_hex_on_load`; `test_S_205_13_the_icon_ticker_is_gone_from_the_page_and_its_drawing`; `test_S_205_14_the_replies_gauge_card_says_it_is_pace_against_todays_average_and_not_a_goal`; `ChatbotLiveRulesTest::test_S_205_11_tv_mode_shows_2d_whatever_the_browser_saved_and_outside_tv_the_saved_choice_still_applies`, `test_S_205_12_on_load_the_ai_hex_lights_one_cell_per_answer_of_the_last_minute_by_age`, `test_S_205_13_the_rules_no_longer_carry_the_ticker_icon`.
- Removed: `ChatbotLiveRulesTest::test_S_205_1_the_ticker_draws_one_icon_per_event_type` (the ticker is gone).
- Changed assertions: `ChatbotLiveJarvisTest::test_S_205_1` and `test_S_200_6`, `ChatbotLiveNetworkTest::test_S_199_6` now assert that `data-live-ticker` is absent; `test_S_200_6` also checks the two `data-live-split` hooks.
- Red runs: `S_205_10` — `Undefined array key "order_split"`, then (with the split but before the fix) `'forbeast' => 1 … 'botcake' => 1` against 3 and 2; `S_205_1x` rules — `no function: icon` on the old ticker test after the rule was removed (test replaced).
- Live files (`php vendor/bin/phpunit tests/Feature/Chatblast/<File>.php`): `ChatbotLiveJarvisTest` OK (15 tests, 298 assertions), `ChatbotLiveRulesTest` OK (12, 163), `ChatbotLiveNetworkTest` OK (7, 81), `ChatbotLiveScoreboardTest` OK (9, 93), `ChatbotLiveTest` OK (6, 77).
- Full `tests/Feature`: `Tests: 3697, Assertions: 71252, Failures: 2, Skipped: 4` — only the two known failures (`ExampleTest::test_the_application_returns_a_successful_response`, `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`); no new failure, and the NightRun time-limit test passed this time.
- `S-205.6` (query count does not grow with radar customers) still passes; items 1 and 3 add no query.

### Commands and evidence

- `php docs/specs/205-evidence/build.php` → `wrote 109327 bytes`. New switches: `?save3d` (a saved 3D choice before the page opens), `?tv` (TV mode on load, without fullscreen), `?lbl[=ms]` (on-screen height of every panel label at its far edge, in `data-lbl`; what is shown, in `data-view`). The fake counts carry `ai_minute` and `order_split` (270 orders = Bot 1 260 + Botcake 2 + other 8; 31 without amount).
- TV with saved 3D: `chrome --headless=new --window-size=1920,1080 --virtual-time-budget=5000 --dump-dom "file:///…/live.html?now=1500&shot&save3d&tv&lbl=3000"` → `data-view={"saved":"3d","tv":true,"K":0}`; without `tv`: `{"saved":"3d","tv":false,"K":1}`.
- Labels in 3D: `…/live.html?now=1500&shot&d3&orbit=<ms>&lbl=5000` at 11 orbit phases (both extremes and 9 between) → smallest label 18.8 px (ORDERS, +7°), 19.5 px at −7°; in 2D the smallest is 19 px (unchanged).
- Hit-test (S-205.7): `…/live.html?now=1500&shot&d3&orbit=1768&hit=5000` and `orbit=31600` → `overlapping: 0, outside: []` at −7° and +7°; also 0 / [] at fold moments 400, 500, 700, 900, 1100, 1300, 1600, 2000, 2500 ms and orbit phases −4.52° and +2.4° (51,988 points each). At 300 ms the first frame has not been laid out yet (all panels at the centre); the base commit's evidence page shows the same 5,084 points at 300 ms, so this is the measuring moment, not a regression.
- No script errors (`data-err` absent) at 1920 × 1080 and 390 px, in 2D, 3D and TV.
- Screenshots (`chrome --headless=new --hide-scrollbars --window-size=1920,1080 --virtual-time-budget=<ms> --screenshot=<file> "file:///…/live.html?<switches>"`):
  - `docs/specs/205-evidence/live-1920-a1.png` — 2D, vault card open (`now=1500&shot&card=vault`, 3300 ms): Orders today 271, Other / no bot 8, Without amount 31.
  - `docs/specs/205-evidence/live-1920-tv-a1.png` — TV mode with a saved 3D choice (`save3d&tv&now=1500&shot`, 3300 ms): 2D, no filter / 3D / TV / exit controls.
  - `docs/specs/205-evidence/live-1920-3d-a1.png` — 3D at the +7° orbit end (`now=1500&shot&d3&orbit=31600`, 5000 ms): every label ≥ 18.8 px.
  - `docs/specs/205-evidence/live-1920-reload-a1.png` — right after load, before the first poll (`shot`, 800 ms): 13 AI hex cells lit from `ai_minute` [3, 2, 4, 1, 2, 1].

### Story changes

- S-205.1: the icon ticker removed from the panel list; its ticker test removed (see S-205.13).
- S-205.7: "between the header and the ticker" → "between the header and y = 1046 px (where the ticker was)"; the measurement is unchanged.
- Added S-205.10 to S-205.14 (items 1, 2, 3, 4, 5).

### Notes

- The vault's "Orders today" still holds while coins are landing (up to 8 s, as before); the two new lines update on each poll, so for a few seconds after an order the card's lines can be one ahead of the held total.
- The old screenshots (`live-1920.png`, `live-1920-3d*.png`, `live-390.png`, `live-1920-card-forbeast.png`) still show the ticker; they are the record of the first build and were not retaken.
- Deploy: still no migration. The order-value cache key is now `v3`; the first refresh after deploy reads the whole day once (one query on today's order events, plus the COD lookup for orders without an amount).

205: done — Live page rebuilt as comp E (radars, vault, gauges, cards), 44 tokens on screen, radar from the same one query, 3D on screen without overlaps, all P1 cases pass; Amendment 205-1: per-bot orders + Other / no bot = total (polling-gap bug fixed), TV always 2D with 3D labels ≥ 18 px, AI hex lit on load, icon ticker removed, Replies card says pace
