# Result 196: the Live page becomes a TV scoreboard, with a neural map, per bot

Status: done. Branch `feat/196-live-scoreboard`, based on 8705e881 (live). Not pushed, not deployed. No real Forbeast or gateway was called: all HTTP is faked in tests.

Amendment 196-1 (owner, 2026-10-29) is applied. It is kept in `handoff/196-live-scoreboard/AMENDMENT-1.md` in the main checkout, not committed. It replaces comp A's stage grid in the middle with a neural map in the style of the owner's reference image. The data work, the top row of four numbers, the Latest stream and TV mode stay.

## What shipped

**`/chatbot/live` is a TV scoreboard** (layout `layouts/chatbot-tv`). There is no app switcher, nav or problems banner; a small "Exit TV mode" link goes to Speed. Access, routes and the 5 s poll are unchanged.

- **Top row: four huge numbers.** They update every poll.
  - **Replies today:** confirmed by Meta, with "+N in last 5 min".
  - **Avg reply time:** Forbeast's average for today, on Meta's clock. It shows "—" with the reason when Forbeast is not linked, down, timing out, or the page is not on Forbeast.
  - **Orders today:** with "+N in last 5 min".
  - **Active chats:** distinct customers who messaged in the last 5 minutes.
- **The neural map:** SVG drawn on the server, animated by vanilla JS. Dark navy, thin glowing network lines and nodes.
  - **INQUIRIES** on the left, three clusters: **Bot 1 · Forbeast**, **Bot 2 · Incepxionbot** and **Botcake · fallback**, each with today's input count.
  - A bot whose gateway is not set up shows "not linked". On Incepxion that is Bot 1.
  - Under the heading: the day's total messages, and "+N messages from before the bot was recorded" when that is not 0.
  - **The core** in the centre is named after the site: "LIKHA / AI CORE", or "INCEPXION" on incepxion.pro. It shows the day's AI answers.
  - **Replies confirmed per bot** ("↩ 32,211 replies") sit on each bot's path near the core.
  - **FOLLOW-UP** is at top right (sent today). **ORDERS** is at bottom right: orders today and the last order (page, time, bot). There is no amount: bot orders store none.
- **Lights.**
  - A message is a light from its bot's cluster into the core.
  - A confirmed reply travels from the core out to its bot's cluster.
  - A follow-up and an order travel out to their own clusters.
  - An AI answer lights the core.
  - The target lights up on arrival.
- **Motion stays calm.**
  - At most 30 lights at once, from a fixed pool of reused SVG circles with one animation loop. When none is free, the target only lights up.
  - Above 60 events in a poll, one in N is drawn; orders are always drawn.
  - With `prefers-reduced-motion`: no travelling lights, spin, pulse or fade. Only the target's colour changes, and the numbers still update.
- **Latest:** 9 lines on the right, newest first, older ones fading. Each line has time, type, page and bot.
- **390 px:** the map is a vertical version, with the bots on top, the core in the middle, and Follow-up and Orders below. The four numbers sit 2 × 2 and the stream goes underneath. There is no sideways scroll.
- **All numbers are today's totals** (Manila). Nothing counts from when the page opened.

**Data.**
- **Inbound marker.** Every new inbound chat gets `payload._via`: `forbeast` or `incepxionbot` from the gateway job, `botcake` from the Botcake endpoint. A Botcake param cannot set it. There is no new table or column.
  - **Rows from before the marker** count by their existing payload: `source = gateway` means Forbeast, plus `gateway = incepxionbot` means Incepxionbot, otherwise Botcake.
  - A row with no payload counts as **unknown**.
  - This is the gateway that delivered the chat, which is more exact than the page's provider at that time.
- **Output per bot:** confirmed non-sequence replies by `chatbot_replies.via`. A missing or unknown via counts as unknown, shown small.
- **Events** now carry `bot`: the delivering bot for a message, the via for a reply or follow-up, the order's `detail.via`, and null for AI.
- **Avg reply time:** `MetaReplySpeed::todayAverage($page)` is today from the Speed screen's Forbeast cache.
  - The cache holds an answer for 2 minutes and a failure for 1 minute. The call has a 3 s limit.
  - The states are `ok`, `not_linked`, `not_on_forbeast` and `error`.
- **Speed page:** "by a person" is now "by another app or a person" in the summary line, the table header and the chart tooltip. The note under the table adds that Botcake replies count there.

## Load: how the counters are counted

All counters come from one cache entry per page filter, shared by every viewer.
- It is fresh for 5 s. From 5 to 10 s the old value is served while it is recounted after the response (`Cache::flexible`).
- The events part stays per viewer (from its cursor), as in spec 190: indexed, the last 15 minutes only, capped.

**No poll scans the day.** The day count has two parts:
- **The settled part:** midnight up to an edge 15 minutes or more in the past (10-minute steps, and at least the gateway standby window + 1 min). It is a running total in the cache. When the edge moves, only the new 10-minute bucket is counted and added.
- **The live part:** the edge to now, counted on each refresh, about 15–25 minutes of rows on the indexed time column.

**Rows that change after they are counted** (a late Meta confirmation, for example) are picked up by a full recount of the settled part every 30 minutes, at most once per filter. A new day starts from zero.

**Queries on a refresh, all on indexed time ranges:**
- `botcake_chats` by `received_at`, grouped by the bot expression;
- `chatbot_replies` by `sent_at`, one SUM per bot and no GROUP BY, so the `(via, …)` index is never chosen for grouping;
- `chatbot_ai_requests` by `created_at`;
- `chatbot_events` by `(type, created_at)`, which covers the query;
- active chats: `COUNT(DISTINCT psid)` over the last 5 minutes;
- last order: the `(type, created_at)` index read backwards, LIMIT 1.

**Measured.** The fixture is sqlite in memory, built by a throwaway test that is not committed. "Now" is 22:00, so the day is nearly full. It holds 2 days and 30 pages:
- 76,666 chats (40k a day, 10% standby copies);
- 272,166 replies (60k replies and 75k follow-ups a day);
- 57,500 AI calls;
- 13,800 events.

Today up to 22:00 that is 33,156 messages, 52,275 confirmed replies and 68,867 follow-ups.

| Case | Median | Max |
|---|---|---|
| Poll, counters from cache (every viewer within 5 s) | 0.0 ms | 0.1 ms |
| Refresh (live part only), all pages, 6 queries | 2.6 ms | 3.0 ms |
| Refresh when the edge moves (one new 10-min bucket), 11 queries | 3.8 ms | — |
| Events part of a poll (per viewer) | 0.8 ms | 1.6 ms |
| Full HTTP poll (auth, JSON), counters cached | 2.2 ms | 3.0 ms |
| Full-day count (first load of the day, after eviction, or every 30 min), all pages | 141 ms | 151 ms |
| Same, one page | 14.2 ms | 14.9 ms |

The sqlite plans use the received_at, sent_at, created_at and (type, created_at) indexes, with no table scan. The query text, bindings and plans are in `live-196-perf.json`.

MySQL was not measured, because workers have no server access. TODO.md says what to run read-only.

## Case table (S-196)

| Case | Test | Result |
|---|---|---|
| S-196.1 | `ChatbotLiveScoreboardTest::test_S_196_1_the_four_big_numbers_and_the_per_bot_input_and_output_are_todays_real_counts`, `test_S_196_1_on_incepxion_the_title_and_core_carry_the_site_name` | pass |
| S-196.2 | `test_S_196_2_replies_sent_through_incepxionbot_land_in_bot_2s_row_and_events_carry_the_bot` | pass |
| S-196.3 | `GatewayPerPageTest::test_S_196_3_an_inbound_chat_is_marked_with_the_gateway_that_delivered_it`, `BotcakeChatTest::test_S_196_3_a_botcake_chat_is_marked_botcake_and_a_param_cannot_change_the_mark`, and the pre-marker rows in S-196.1 (4 / 3 / 2 + 1 unknown) | pass |
| S-196.4 | `test_S_196_4_the_counters_come_from_a_5_s_shared_cache_and_a_poll_never_rescans_the_day`, `test_S_196_4_a_late_confirmation_counts_once_after_the_settled_part_is_recounted`, and the measurements above | pass |
| S-196.5 | `test_S_196_5_forbeast_down_or_not_linked_shows_a_dash_and_the_rest_works`, `test_S_196_5_a_page_not_on_forbeast_or_a_timeout_also_shows_a_dash_with_the_right_note` | pass |
| S-196.6 | `test_S_196_6_tv_mode_hides_the_navigation_and_banners_with_a_small_exit_link` | pass |
| S-196.7 | `test_S_196_7_the_layout_reflows_at_phone_width_without_sideways_scroll`, plus headless Chrome at 390 × 844: `scrollWidth` 390, no element past the edge, narrow map 376 × 780 | pass |
| S-196.8 | `MetaReplySpeedTest::test_S_196_8_the_person_column_says_another_app_or_a_person_with_the_botcake_hint` | pass |
| S-196.9 | `tests/Feature/Chatblast`: see Tests | pass |

**Red runs:**
- S-196.3 failed before the marker: both chats had `_via` null, and the Botcake one had no key.
- S-196.1, .2, .4 and .5 first failed on "Undefined array key" while the counter code was half written, then on their assertions.
- The 5 s cache check failed on its own closure: an arrow function had captured the query list by value.
- S-196.4's "never from midnight" check fails when the running total is disabled (`RECOUNT_SECONDS = -1`), checked by mutation.
- S-196.5's not-on-Forbeast case failed (state `error`) before the review fix.
- S-196.8 failed on the old label.

## Tests
- **Baseline at f4708e63** (the spec only, same code as 8705e881), `tests/Feature/Chatblast`: OK, 1431 tests, 50,378 assertions, 1 skipped, 52 PHPUnit deprecations (`baseline-196.txt`).
- **After the build and the amendment (58904e11):** OK, 1441 tests, 50,478 assertions, 1 skipped (`chatblast-196-stage.txt`).
- **Final:** FINAL_LINE

## Story changes
- Added S-196 (.1–.9) to `qa/stories.md`. S-196.1 also covers the site name on Incepxion (amendment). S-196.4 adds the late-confirmation case and S-196.5 the not-on-Forbeast and timeout cases (both from review).
- **S-190 tests changed on purpose:**
  - S-190.3: events now carry `bot` as a fifth key (still no name or text).
  - S-190.1: the Live link in the Chatbot nav is no longer on this page (TV mode). It is still checked on the other Chatbot pages by `ChatbotTabsTest`. The map hook is now `data-live-board`.
- **The `_via` marker changed three older payload assertions:**
  - `ChatbotProviderTest` S-111.4b expects `_via: forbeast`;
  - `BotcakeChatTest` S-059.13 counts 50 params plus the marker;
  - `MetaReplySpeedTest` S-193.1 uses the new label.
- **Spec 190's Pause and Preview buttons are gone.** Comp A and the amendment have none; a TV has no one to press them.

## Review
`skeptic-reviewer`, standard depth, on 8705e881..58904e11 (after the amendment).

**What it found sound:**
- No blockers.
- The running total: bucket edges, midnight, eviction and concurrent refreshes; it never double counts.
- Memory: the fixed light pool, the stopping animation loop, the 9-line stream and the one timer chain.
- No XSS, no access change, and Forbeast being down never causes a 500.

**One major: the query time was not yet stated, and MySQL might pick the `(via, …)` index for `GROUP BY via`.**
- **Fixed (77e3d4ad):**
  - the replies tally is now one SUM per bot with no GROUP BY, so the sent_at range is read;
  - the measurements and plans are in this result.
- **Accepted:** MySQL is not measured (TODO.md).

**Minors fixed:**
- the settled edge stays behind the gateway standby window (`GATEWAY_SWITCH_STANDBY_MINUTES`), because re-run and promoted chats keep their old `received_at`;
- `tallyAdd` tolerates a missing key;
- a page filter on a page not on Forbeast says "This page is not on Forbeast";
- a hung poll is cut after 15 s (`AbortSignal.timeout`);
- SVG path lengths are read lazily, so a hidden map cannot stop the script;
- unused parameter and `now()` cleanups;
- a comment ties the SQL bot rule to `ChatbotProvider` and `ResponseSpeed`.

**Minors accepted in TODO.md:**
- rows that change after settling wait for the 30-minute recount;
- a re-run chat counts twice (existing since 190);
- MySQL is unmeasured;
- the JS is checked in the browser, not by a test.

## Evidence
**Location:** `C:\Users\Forbeast\AppData\Local\Temp\claude\C--mira-mira-office\1332ecd3-ded0-44d9-8ce1-615fd84c5036\scratchpad\evidence196\`

**How it was made:**
- The fixture day above was rendered as `live-196.html` (Likha, Forbeast faked at 2.43 s) and `live-196-incepxion.html` (incepxion.pro, Forbeast not linked).
- `live-196-feed-polls.json` holds 12 real endpoint answers while new rows arrived on all three bots. The recording asserted that no customer name or text is in them.
- Headless Chrome opened the pages with the feed answered from those 12 answers (`live-shot-196.mjs` in the scratchpad).

**Screenshots and logs:**
- `live-196-1920-idle.png`, `live-196-1920-live.png`: 1920 × 1080, the first poll's lights travelling.
  - `scrollWidth` 1920, nothing past the edge.
  - Map 1410 × 727.
- `live-196-390-idle.png`, `live-196-390-live.png`: 390 × 844, full page.
  - `scrollWidth` 390, nothing past the edge.
  - Vertical map.
- `live-196-390-reduced-*.png`: reduced motion. 0 lights during the poll, spin animation `none`, and the targets still light up and the numbers still update.
- `live-196-incepxion-1920-idle.png`: "INCEPXION LIVE", the core named INCEPXION, Bot 1 "not linked", and Avg reply time "— Not linked to Forbeast".
- `live-196-1920-stress.txt`: the all-day check. The poll interval was cut to 0.4 s for 90 s (212 polls, about 1,400 events).
  - DOM nodes 643 → 643.
  - JS heap after GC 798 KB → 888 KB.
  - At most 30 lights at once; 1,393 events only lit their target.
- The `.txt` beside each screenshot has its counts, the stream, overflow and light numbers.
- `live-196-perf.json`: timings, queries, bindings and plans.
- `baseline-196.txt`, `chatblast-196-stage.txt`, `chatblast-196-final.txt` (in the scratchpad folder).

## Deploy notes
- **No migration, no new env, no asset build** (Blade with inline CSS/JS). After deploy:
  - run `php artisan view:clear`;
  - run `route:cache` if routes are cached (routes are unchanged).
- **Counters:** the cache keys are new (`chatbot-live:v2:*` and `chatbot-live:settled:v1:*`). Expect the first load to count the day once (about 0.15 s on the fixture); after that, refreshes are small.
- **Inbound marker:** it starts with the deploy. Today's earlier chats count by their old payload, so the per-bot input is right from the first load.
- **Files:**
  - Changed: `app/Services/Chatbot/LiveFeed.php`, `app/Services/Chatbot/MetaReplySpeed.php`, `app/Jobs/HandleGatewayEvent.php`, `app/Http/Controllers/BotcakeChatController.php`, `resources/views/chatbot/live.blade.php`, `resources/views/chatbot/speed.blade.php`.
  - New: `resources/views/layouts/chatbot-tv.blade.php`, `resources/views/chatbot/partials/live-map.blade.php`.
  - Tests and docs: `tests/…`, `qa/stories.md`, `TODO.md`.
- **Incepxion:** same code. The title and core say INCEPXION (from APP_URL).
  - Bot 1 shows "not linked" until a Forbeast gateway is set up.
  - Avg reply time shows "— Not linked to Forbeast" until a gateway's site key and secret are set.

## Notes
- **The plan gate:** CLAUDE.local.md asks for the reviewer's "go" on a plan before code. The start prompt was the reviewer's go, with the steps given, so no separate plan was sent. It is noted here as the conflict rule asks.

196: done — /chatbot/live is a TV scoreboard: four huge numbers, a neural map with per-bot input and output into a site-named AI core, a 9-line stream, from a 5 s shared cache over running totals (≈3 ms refresh, ≈2 ms poll on a 40k/60k/75k-a-day sqlite fixture; MySQL not measured), 1920 and 390 px and reduced motion verified, memory flat over 212 stress polls.
