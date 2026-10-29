# Result 190: Chatbot → Live, an animated map of what the bot is doing right now

Status: done. Branch `feat/190-chatbot-live`, based on 6bef798e (live). Sending, flows and the other screens are unchanged. No migration, no new index, no new dependency.

## What shipped
**A new tab: `/chatbot/live`.**
- It appears as "Live" in the Chatbot nav, after Speed.
- Access is the same as Speed and Bot Health (the `/chatblast` nav access). Any other role gets 403, on both the page and its feed.

**Top of the page.**
- Five counters for today (Manila), each with its "+N in 5 min":
  - Messages received
  - Bot replies confirmed by Meta (with a small "N sent, not confirmed yet" under it)
  - Follow-ups sent
  - AI answers
  - Orders saved
- A Live dot. It shows "Paused (still counting)", "Paused while the tab is hidden", "Reconnecting…", or "Signed out: reload the page" on a 401/403/419.
- "last update HH:MM:SS".
- An All pages / one page filter, like Speed's.
- Pause and Preview buttons.

**The map.** It is drawn with SVG and plain JS, with no library and no build step. The page is dark; the app's top bar is unchanged.
- **Layout:**
  - In the centre is the "Likha Bot" core.
  - **Inquiries** is on the left, **Follow-up** at top right and **Orders** at bottom right.
  - An **AI** node is linked to the core.
  - Each cluster shows today's count.
- **What each event does:**

  | Event | Animation |
  |---|---|
  | message | A pulse travels Inquiries → core, then the core glows and reads "processing" |
  | AI answer | A spark on the AI node, and a pulse AI → core |
  | reply confirmed by Meta | The core's outer ring flashes |
  | follow-up | A pulse core → Follow-up |
  | order saved | A pulse core → Orders |

- **Idle:** a slow heartbeat, a slowly turning ring and a faint flow along the lines.
- **Spacing:** the events of one poll are spread over about 4 s, in time order. At most about 60 pulses are drawn at once; past that, the target only lights up.
- **Too many events:** past the cap, a "+N more events" badge shows.
- **Pause** stops the animation. Polling, counters and the event list keep going.
- **Preview** plays about 6 s of demo events, one of each type. They are labelled "Preview: demo events, not counted" and make no server call.
- **Reduced motion** (`prefers-reduced-motion: reduce`):
  - No travelling pulses, sparks, heartbeat, spin or flow.
  - The clusters and the ring only light up.
  - The counters still update.
- **Below 620 px:** the map switches to a stacked layout (viewBox 400 × 690) so the labels stay readable at 390 px:
  - Inquiries on top;
  - the core with AI beside it;
  - Follow-up and Orders below.
- **Latest events list:** the newest 12 events, newest first. Each shows time, type and page name.

**Data: one JSON endpoint, `GET /chatbot/live/feed`** (`throttle:120,1`). The page polls it every 5 s and pauses while the tab is hidden.

- **Request:** `page_id` and the cursor of the last poll (`cursor[chat|seq|order|ok|ai]`).
- **Response:** `counts`, `events`, `more`, `cursor` and `now`.
- **Each event** is `{type, at, page, id}` only. There are no customer names, message text or reply text.

**Event sources:**

| Event | Table and rule | Cursor |
|---|---|---|
| `message` | `botcake_chats`, not standby copies | id |
| `follow_up` | `chatbot_replies` with kind `send`, `sequence_id` set, status sent or partial | id |
| `order` | `chatbot_events` with type `order`: the bot's "Counts as an order", the same source as Results and Bot Health | id |
| `confirmed` | `chatbot_replies`, a non-sequence send with `confirmed_status` sent/partial and `confirmed_at` | (`confirmed_at`, id) |
| `ai` | `chatbot_ai_requests` with `answered_at` set, no error, and not the Encode order model | (`answered_at`, id) |

**Rules:**
- **Why two cursor kinds:** a confirmation or an AI answer happens after its row is written, so those two sources use (time, id) instead of id.
- **Lag for those two:** they are released 5 s after their time (`LAG_SECONDS`). A Meta confirmation that arrives a few seconds late, or out of order, is still caught. A time in the future waits until it is due.
- **First poll:** a missing or broken cursor (not digits, an impossible date, a future value) means "from now". The first poll has no backlog.
- **Old id cursors** are raised to at most 20,000 ids below the newest.
- **Time window:** every query is bounded to the last 15 minutes of its indexed time column.
- **Cap:**
  - Each source returns at most 200 rows. When there are more, the poll counts all of them and keeps the newest 200.
  - Across sources, the newest 200 by time are returned, and the rest are counted in `more`.

**Counter definitions:**
- **Messages:** `botcake_chats` by `received_at`, without standby copies.
- **Confirmed:** a non-sequence send with status sent/partial and a Meta confirmation, by `sent_at`.
  - Botcake sends are confirmed when sent.
  - Forbeast sends are confirmed by its send_status.
- **Sent, not confirmed yet:** the same sends without a confirmation yet.
- **Follow-ups:** sequence sends with status sent/partial.
- **AI answers:** answered with no error, not the Encode order, by `created_at`.
- **Orders:** `chatbot_events` order rows.

**Counter cache.**
- The last 5 minutes are not cut off at midnight.
- The counters are cached per page filter and are never older than 10 s, using `Cache::flexible([5, 10])`:
  - fresh for 5 s;
  - from 5 to 10 s, the old value is served and the new one is counted after the response;
  - after 10 s, they are counted in the request.
- The refresh lock expires after 15 s.

## Endpoint queries
On a poll with counters cached (the usual case), the endpoint runs:
- the page map: cached, as on every Chatbot page;
- 3 × `SELECT MAX(id)` on a primary key (botcake_chats, chatbot_replies, chatbot_events);
- 5 event queries, each `... WHERE <time> >= now-15min AND <filters> AND (id > ? | (time, id) > ?) ORDER BY ... LIMIT 201`:
  - `botcake_chats`: PK range, with the received_at index as the alternative;
  - `chatbot_replies` seq: PK range, with the sent_at index as the alternative;
  - `chatbot_events`: the (type, created_at) index;
  - `chatbot_replies` ok: the (sequence_id, sent_at) index on sqlite, or sent_at;
  - `chatbot_ai_requests`: the created_at index.
- Only when one source has more than 200 rows: a `COUNT` and a `DESC LIMIT 200` on the same 15-minute range.

When the counters are counted (at most once per 10 s per page filter), it adds 4 aggregate queries over today:
- `botcake_chats` on the received_at index;
- `chatbot_replies` on the sent_at index (one pass with `SUM(CASE ...)`);
- `chatbot_ai_requests` on the created_at index;
- `chatbot_events` on the (type, created_at) index, which covers this query.

The query text with each sqlite query plan is in `live-190-perf.json`.

## Measured time
**Fixture:** production-sized, sqlite in memory. The fixture and timer were a throwaway test, not committed.
- 3 days of data: 102,083 chats, 175,000 replies, 29,166 AI requests and 35,000 events.
- About 35k chats, 60k replies, 10k AI calls and 1.5k orders a day, over 30 pages.
- "Now" is 22:00, so today is nearly full.

| Case | Median | p95 / max |
|---|---|---|
| Poll, counters cached | 0.8 ms | 1.3 ms |
| Poll, counters 5–10 s old (served, refreshed after the response) | 1.6 ms | 3.3 ms max |
| Poll, counters counted in the request, all pages | 35.7 ms | 41.7 ms |
| Same, one page | 5.7 ms | 6.7 ms |
| Very old cursor (200 events + 784 counted) | 10.5 ms | 12.2 ms |
| Full HTTP request (auth, JSON), cached | 2.1 ms | 3.4 ms |
| Full HTTP request, counters counted | 36.9 ms | 39.2 ms |

All cases are under 100 ms. MySQL was not measured: there are no local MySQL credentials. TODO.md records this and what to do if a read-only `EXPLAIN ANALYZE` on the server shows the day count over 100 ms.

## Case table (S-190)
| Case | Test | Result |
|---|---|---|
| S-190.1 (P1) The page and the feed open for a role that sees Speed, and are 403 otherwise. The Live link is in the nav. | `test_S_190_1_the_page_and_feed_open_for_a_role_that_sees_speed_and_403_otherwise` | pass |
| S-190.2 (P1) Today's counters equal the fixture: messages 3 (2 in 5 min), confirmed 3 (2), sent not confirmed 1 (1), follow-ups 2 (1), AI 3 (2), orders 2 (1). They are never older than 10 s. | `test_S_190_2_the_counters_equal_the_days_rows_and_the_last_five_minutes` | pass |
| S-190.3 (P1) Given the last cursor, only newer events come back, in time order and typed (message, ai, confirmed, follow_up, a late confirmation, order). Each has only type, time, page and id: no name, message or reply text. A confirmation within the 5 s lag comes on the next poll. Broken, impossible or future cursors start from now. | `test_S_190_3_given_the_cursor_only_newer_events_come_in_time_order_typed_and_without_names_or_text` | pass |
| S-190.4 (P1) Over the cap: 230 messages give the newest 200 plus "+30". 150 messages and 100 follow-ups give the newest 200 plus "+50". The next poll continues after them. | `test_S_190_4_more_than_the_cap_returns_the_newest_events_and_a_count_of_the_rest` | pass |
| S-190.5 (P1) The page filter limits counters and events. A broken filter means all pages. | `test_S_190_5_the_page_filter_limits_counters_and_events` | pass |
| S-190.6 (P1) Existing Chatblast suites pass. | `tests/Feature/Chatblast`: baseline at 6bef798e was 1363 tests, 0 failures, 1 skipped. Final: 1369 tests, 0 failures, 1 skipped (the same one). | pass |
| S-190.7 (P2) At 390 px the page has no horizontal scroll. | Headless Chrome at 390 px (mobile emulation): `scrollWidth` 390, no element sticks out. The map is the stacked layout at 340 × 587. Desktop 1280: `scrollWidth` 1280, map 1214 × 631. | pass |
| S-190.8 (P2) With reduced motion there are no travelling pulses. | `test_S_190_8_reduced_motion_turns_off_travelling_pulses` (the CSS and the script hook). Headless Chrome with `prefers-reduced-motion: reduce` emulated: 0 pulse elements during Preview (34 without it), heartbeat and flow animation `none`, and the counters still updated (28,943 → 28,945). | pass |

**Red runs:**
- **First run:**
  - S-190.1, .3 and .8 failed with 404 (no route yet).
  - S-190.2 failed because a Botcake `test` send counted as confirmed. Fix: confirmed now also requires status sent/partial.
  - S-190.5 failed on its own wrong expectation (5 messages by then, not 3).
- **Lag assertion of S-190.3:** it fails with `LAG_SECONDS = 0` (checked by mutation).
- **Impossible-date cursor (review fix):** S-190.3 failed before the strict date check.

## Story changes
- S-190 added to `qa/stories.md`.
- S-190.3 also covers the 5 s lag for late Meta confirmations, a design choice not in the brief. Without it, an out-of-order confirmation would never animate.
- The nav assertions of `ChatbotTabsTest`, `ChatbotInboxTest` and `ChatbotSequencePagesTest` now include "Live" after "Speed". This is the intended nav change.

## Review
`skeptic-reviewer` checked the polling endpoint's performance, access and XSS, and found no majors.
- **Access:** both routes are in the Speed group, with the same role check.
- **XSS:**
  - page names go through `{{ }}` or `textContent` only;
  - the boot JSON uses `JSON_HEX_*` and is read with `JSON.parse`;
  - there is no `innerHTML`.

**Minors fixed:**
- impossible cursor dates are rejected (they could make MySQL error);
- an old id cursor is bounded to 20,000 ids below the newest;
- the counter refresh lock now expires (15 s).

**Minors accepted in TODO.md:**
- the MySQL timing is unmeasured;
- the confirmed and follow-up event queries read the 15-minute reply window during a sequence burst.

## Evidence
The files are in `C:\Users\Forbeast\AppData\Local\Temp\claude\C--mira-mira-office\1332ecd3-ded0-44d9-8ce1-615fd84c5036\scratchpad\evidence\`.

**How it was made:**
- The page was rendered from the production-sized fixture as `live-190.html`.
- 12 real responses of the endpoint were recorded while new rows arrived, in `live-190-feed-polls.json`. None contains the fixture's customer name or message, which the recording test asserted.
- Headless Chrome (CDP) then opened the page with `fetch` answered from those responses (`live-shot.mjs` in the scratchpad).

**Screenshots and logs:**
- `live-190-desktop-idle.png`, `-live.png` (real events from the first poll animating) and `-preview.png` (Preview running).
- `live-190-390-idle.png`, `-live.png` and `-preview.png`: 390 px.
- `live-190-390-reduced-*.png`: 390 px with reduced motion.
- `live-190-desktop.txt`, `live-190-390.txt` and `live-190-390-reduced.txt`. Each records, per state:
  - width and overflow;
  - map size;
  - status, counters and the event list;
  - pulses counted during Preview;
  - Pause: 0 pulses and 2 polls in 11 s, counters still updating;
  - hidden tab: 0 polls in 11 s, then one poll right after it becomes visible.
- `live-190-perf.json`: timings, queries and plans.
- `live-190-suite-baseline.txt` and `live-190-suite-final.txt`.

## Deploy notes
- No migration and no config change. Deploy the code, then run:
  - `php artisan route:cache` if routes are cached;
  - `php artisan view:clear`.
- The page polls every 5 s per open tab. Each poll reads at most 15 minutes of rows from four tables. The day counters are counted at most once per 10 s per page filter, shared by all viewers, using the Redis cache (locks supported).

**Applies to Incepxion.** It is the same codebase with the same tables (botcake_chats, chatbot_replies, chatbot_ai_requests, chatbot_events), so the Live tab works there after deploy with no extra step. Page names come from that site's own page settings.

190: done — Chatbot → Live is built: today's counters, an animated SVG map fed by a capped cursor endpoint (≈2 ms polled, ≈37 ms when counting, on a production-sized sqlite fixture), 390 px and reduced motion verified.
