# Result 193: the Speed screen shows the real reply time on Meta's clock

Status: done. Branch `feat/193-meta-reply-time`, based on 79189d57 (live on Likha and Incepxion). The existing sections, numbers, queries, cache and access are unchanged.

## What changed on `/chatbot/speed`
A new first section, **"Reply time at Meta (customer sent → our reply sent, Meta's clock)"**, sits above "… by hour". It follows the day and Page filters. Its content depends on the state:

- **Forbeast answered.** The section shows, in this order:
  1. The scope line: "Only pages on Forbeast are in this section. N of the day's messages went through Botcake and are not in it." N is the existing Botcake row of the Speed table (`by_bot.Botcake.messages`, which follows the Page filter).
  2. The summary line: `Day: n messages · n bot answered · n by a person · n unanswered · avg · median · p90 · max · Meta → Forbeast avg`.
  3. The chart. It has Average (solid), Median (dashed) and p90, with the previous day as the thin dotted reference. It uses the same renderer, tooltip and adaptive axis as the other charts. Null hours are gaps.
  4. The table, in a closed "Show table" `<details>` like the other sections (its state is remembered the same way). Its columns are Hour, Messages, Bot answered, By a person, Unanswered, Average, Median, p90, Max and Meta → Forbeast avg, followed by a Day row. Hours that are not in Forbeast's answer (today's future hours) are blank.
- **Bot filter Botcake.** The section says "Botcake gives no Meta times. Choose Forbeast or all bots to see this section." Nothing is called.
- **Not linked.** The section says "Not linked to Forbeast. This site has no Forbeast link, so there are no Meta times here." Nothing is called.
- **Forbeast slow, down or erroring.** The scope line shows, followed by "Forbeast did not answer (HH:MM:SS). <reason> The rest of this page is not affected; it tries again within a minute." The reason is one of:
  - "No answer within 5 s."
  - Forbeast's own error (escaped and cut to 200 characters), with its HTTP status.
  - "This page is not on Forbeast for this site." for a 404 `unknown_page`.
  - "Its answer could not be read."

  The rest of the page renders as before. It never returns a 500.

**Chart note:** only the average is visible at first. Median and p90 are in the legend and start hidden, the same as every other Speed chart (amendment 188-1, which said "every chart"). One click on the legend shows them.

## How it works
- **`ForbeastSite::replySpeed($date, $pageId, $timeout)`** (new) calls `GET {GATEWAY_URL}/v1/site/reply-speed?date=…[&page_id=…]` with `Bearer FORBEAST_SITE_KEY`, through the existing `call()`.
  - `call()` takes an optional timeout. The default is still 15 s, so the existing callers are unchanged.
  - The connect timeout is `min(5, timeout)`.
- **`App\Services\Chatbot\MetaReplySpeed`** (new) does the rest:
  - **Linked** is `ForbeastSite::linked()`, the one definition the site already uses: GATEWAY_URL, FORBEAST_SITE_KEY and FORBEAST_SITE_SECRET.
  - **Timeouts:** the day gets 5 s. The previous day gets only what is left of those 5 s, at least 1 s. It is not called at all when the day failed.
  - **Worst case on a cold load is about 6 s.** That happens when Forbeast answers the day slowly and then times out on the previous day.
  - **Cache:** the key is `meta-reply-speed:v1:md5([date, page_id])`. It is versioned like `response-speed:v2`, and the bot filter is not part of it because Forbeast and all bots read the same data.
    - Today is cached for 2 minutes and past days for 30 minutes.
    - A failure is cached for 1 minute, so a down Forbeast costs at most one wait per minute per page filter, not one per load.
  - **The answer is untrusted.** `hours` must be a list of objects whose `hour` is an int from 0 to 23, and `total` must be an object; otherwise the answer is "could not be read". Within a row:
    - a count that is not a non-negative int becomes 0;
    - a time that is not a finite non-negative number becomes "—".
  - All the work is inside `try`/`catch (Throwable)`; anything unexpected is reported and shown as "did not answer".
- **`ChatbotSpeedController`** merges the section (`meta`) and, when there is data, its chart (`charts.meta`) into the existing view data.

## Case table (S-193)
| Case | Test (`tests/Feature/Chatblast/MetaReplySpeedTest.php`) | Result |
|---|---|---|
| S-193.1 (P1) | `test_S_193_1_the_table_and_chart_show_the_forbeast_hours_and_day_total` (first section; 10:00 row 4 / 3 / 1 / 0, 3.4 s, 3 s, 5.1 s, 6 s, 2.6 s; empty hour 0 messages with no times; 13:00 onward blank; Day row and summary line; chart series and gaps; the script has the `meta` lines). `test_S_193_1_only_forbeast_pages_are_in_it_and_the_botcake_chats_are_counted` ("2 of the day's messages went through Botcake") | pass |
| S-193.2 (P1) | `test_S_193_2_the_page_filter_passes_page_id`: the query is `date` plus `page_id=1001` with the site key; without a page, only `date` | pass |
| S-193.3 (P1) | `test_S_193_3_the_previous_day_is_drawn_as_reference` (2026-10-20 fetched; prev avg, median and p90 at 10:00 are 5, 5, 7). `test_S_193_3_when_only_the_previous_day_fails_the_day_is_still_drawn` (added after review) | pass |
| S-193.4 (P1) | `test_S_193_4_not_linked_shows_so_and_calls_nothing` (no URL, then no key: "Not linked to Forbeast", no HTTP). `test_S_193_4_the_botcake_filter_says_botcake_gives_no_meta_times` | pass |
| S-193.5 (P1) | `test_S_193_5_forbeast_down_slow_or_refusing_still_renders_the_page` (timeout, 500, 404, not JSON, wrong shape: 200, "Forbeast did not answer (12:30:00)", the other four charts present, one call each). Added after review: `…_hostile_values_are_dropped_and_forbeast_text_is_escaped`, `…_a_page_forbeast_does_not_know_says_so`, `…_the_calls_have_a_5_s_limit_together` (the day is called with 5; after a 1.2 s day, the previous day is called with 1–3) | pass |
| S-193.6 (P1) | `test_S_193_6_a_second_load_makes_no_http_call_until_the_cache_ends` (2 calls, then 0; after 121 s only today again; after 30 more minutes both). `test_S_193_6_a_failed_answer_is_kept_a_minute_so_a_down_forbeast_does_not_slow_every_load` | pass |
| S-193.7 (P1) | `tests/Feature/Chatblast/ResponseSpeedTest.php` unchanged: 31 tests, OK. `tests/Feature/Chatblast`: see Tests | pass |
| S-193.8 (P1) | `test_S_193_8_the_table_scrolls_inside_its_box_and_the_chart_follows_the_screen_width`, plus headless Chrome at 390 px: `scrollWidth` 390, no element sticks out, meta canvas 332 × 256, also with the table open | pass |

**Red runs:**
- **First slice:** all 10 new tests failed on their assertions before the code: the section was missing, and there were no HTTP calls. `test_S_193_1…` errored on the missing `meta` view data.
- **Review slice:** these failed on their assertions before the fix:
  - the 404 message;
  - the hostile hour shapes: they exposed that re-faking HTTP in a test kept the first stub, so the tests now route every answer through one stub;
  - the 5 s shared limit: the previous day was called with 5.

## Tests
- **Baseline at 0654f631** (the spec only, same code as 79189d57), `tests/Feature/Chatblast`: OK, 1400 tests, 1 skipped, 52 PHPUnit deprecations (`baseline-193.txt`).
- **After the feature (0d07b53e):** OK, 1410 tests (`chatblast-193-stage1.txt`).
- **Final (2cbdce7b):** OK, 1414 tests, 50,191 assertions, 1 skipped, the same 52 deprecations (`chatblast-193-final.txt`). No new failures.
- `PageOwnersScreenTest` and `GatewaySitePagesTest` (the other `ForbeastSite` callers): 78 tests, OK.

## Review
Skeptic reviewer, standard depth, on 0654f631..0d07b53e: no blockers and no majors. Its minors were handled as follows:
- **Fixed:**
  - The worst-case wait was about 10 s (two full timeouts). It is now about 6 s, through the shared 5 s limit.
  - Missing tests for hostile field values and escaping, for "the day answers but the previous day fails", and for the timeout actually being passed. All three are now tested.
  - A Botcake or other-site page in the Page filter read "did not answer". It now reads "This page is not on Forbeast for this site."
  - The older Speed tests could call a real Forbeast in a checkout whose `.env` is linked. `phpunit.xml` now sets `FORBEAST_SITE_KEY` empty, and the tests link through `config()`.
- **Accepted in `TODO.md`:** "Linked" also needs FORBEAST_SITE_SECRET (the site's one definition), although this read does not use it.

## Story changes
- Added S-193 (.1–.8) to `qa/stories.md` from the brief.
- After review, S-193.3 gained "only the previous day fails". S-193.5 gained the 404 message, hostile values and escaping, and the shared 5 s limit.

## Evidence
A throwaway test (not committed) rendered the screen on the spec 185 fixture day (2026-10-21 18:30, reference 2026-10-20), with Forbeast faked from the spec 029 contract: about 680 messages, a slow 14:00, and 2 unanswered at 18:00. The pages were opened in headless Chrome through the DevTools protocol.

The files are in `C:\Users\Forbeast\AppData\Local\Temp\claude\C--mira-mira-office\1332ecd3-ded0-44d9-8ce1-615fd84c5036\scratchpad\evidence193\`:
- `speed-193.html`, `speed-193-desktop.png` (1280 px, full page), `speed-193-desktop-1.png` (the new chart).
  - The summary line reads "Day: 680 messages · 640 bot answered · 38 by a person · 2 unanswered · avg 10.8 s · median 8.8 s · p90 15.2 s · max 51.8 s · Meta → Forbeast avg 2.6 s".
  - The 14:00 spike (31.7 s) is clamped at the 30 s axis top with its label.
- `speed-193-390.png`, `speed-193-390-1.png` (390 px, mobile emulation), and `speed-193-390-open.png` / `speed-193-desktop-open.png` with the new table open.
  - At both widths `scrollWidth` equals the viewport (390 / 1280) and no element sticks out.
  - The canvases are 332 × 256 at 390 px and 1206 × 224 at 1280 px.
- `speed-193-notlinked.html` and `speed-193-390-notlinked.png`: "Not linked to Forbeast", which is what Incepxion shows today.
- `speed-193-down.html` and `speed-193-desktop-down.png`: "Forbeast did not answer (18:30:00). No answer within 5 s.", with every other section rendered as before.
- `speed-193-botcake.html`: the Bot filter set to Botcake, showing "Botcake gives no Meta times".

## Deploy notes
- **Files:**
  - Changed: `app/Services/Chatbot/ForbeastSite.php`, `app/Http/Controllers/ChatbotSpeedController.php`, `resources/views/chatbot/speed.blade.php`.
  - New: `app/Services/Chatbot/MetaReplySpeed.php`, `resources/views/chatbot/partials/speed-meta-cells.blade.php`.
  - Tests and policy: `phpunit.xml` (tests only), `tests/…`, `qa/stories.md`, `TODO.md`.
- **Nothing else to run:** no migration, no new config or env, no asset build.
- **After deploy:**
  1. Run `php artisan view:clear` if compiled views are cached.
  2. Open `/chatbot/speed`.
- **Likha:** it uses the existing GATEWAY_URL, FORBEAST_SITE_KEY and FORBEAST_SITE_SECRET.
  - Until Forbeast's spec 029 endpoint is live, the section shows "Forbeast did not answer (…)" (a 404 or a not-found message). That costs at most one call a minute per page filter, and the rest of the page is unaffected.
  - Once the endpoint is live, the numbers appear within 2 minutes.
- **Incepxion:** the same code. It has no Forbeast link today, so the section shows "Not linked to Forbeast" and makes no call.
  - When Incepxion is linked (all three values set), the section turns on by itself.
  - With only the URL and the key set, it still says Not linked (`TODO.md`).
- **Not checked against the real Forbeast**, by design: the work is built against the contract with faked HTTP.

193: done — /chatbot/speed opens with Forbeast's reply time on Meta's clock (hour table, Day row, chart with the previous day); it is capped at about 6 s and cached, shows "Not linked" or "did not answer" instead of breaking, and the existing sections are unchanged.
