# Result 182: bot response time by hour

A new **Speed** tab under Chatbot (`/chatbot/speed`). For any day it shows a table with one row per Manila hour, 00–23, and the bot's first reply time in each. The day's total row and a Forbeast row and a Botcake row sit under the table. A line chart shows the median and p90 per hour. Day controls: ← previous day, Today, Yesterday, next day → (hidden on today), and a date picker. Filters: Page and Bot. Access is the same as Bot Health (`NavAccess::allows(role, '/chatblast')`); other roles get 403.

## Definitions
- **Message.** A `botcake_chats` row received on that Manila day. The app timezone is Asia/Manila and the stored times are local, so `received_at` is the start time. Forbeast's gateway event carries no Meta timestamp (`HandleGatewayEvent::fields` keeps none), so `received_at` is used for both bots. For Forbeast that is when Likha's queued job stored the chat, so a backed-up queue is not visible here (see `TODO.md`).
  - **Left out:**
    - `payload._ignored`: attachments the bot ignores by design.
    - `payload._standby`: the Botcake copy of a chat on a Forbeast page. Forbeast answers it, and its own copy is counted.
    - Echoes and test runs: neither is ever stored as a chat. Test sends are stored with status `test` and are not replies.
- **Bot.** Forbeast when `payload.source = gateway`, else Botcake: the bot that carried the chat.
- **Not on bot.** A message on a page with no `chatbot.flow_id` at that moment. This is the same rule Bot Health uses (`BotHealth::flowSpans` / `hadFlow`, now public so both share it). These messages are counted apart, and not in messages, answered or the times.
  - Chats on pages with the bot **Off** are never stored (the guard refuses them). The day's refused count comes from the existing counters (`botcake-chat:refused:<date>:bot_off` and `gateway-events:<date>:bot_off`, kept 3 days). It is shown as a note when no filter is set.
- **Answer.** The first `chatbot_replies` row to the same customer (page_id + PSID) that meets all of these:
  - `kind = send`
  - `status` is `sent` or `partial`. Failed, skipped and test rows are not answers, and a Forbeast send that `send_status` reports failed becomes `failed`.
  - `sequence_id` is null. A sequence step is a follow-up, not a reply.
  - `sent_at` is at or after the message.
  - It is sent within 24 h of the message. Otherwise the message is unanswered.
- **Reply time.** The answer's `confirmed_at` (Meta's sent time from Forbeast's `send_status`; for Botcake it is the same as `sent_at`) when `confirmed_status` is `sent` or `partial`. Otherwise its `sent_at`, and the answer is counted as **unconfirmed** (shown in the note under the table). Delivered and seen are not used anywhere.
- **Numbers.**
  - Average (1 decimal), median and p90: nearest rank, the same functions as Bot Health.
  - ≤ 30 s / ≤ 1 min / ≤ 5 min: the count of answers within the limit, as a % of all on-bot messages (unanswered messages count as not within).
  - Hours after the current hour (today) are blank. Earlier hours with no messages show 0 and —.
  - The Forbeast and Botcake rows are whole-day totals. They follow the Page filter but not the Bot filter.

## Queries, indexes and time
- **Query 1:** `botcake_chats` with `received_at` in [day, day+1). It uses index `received_at`, or `(page_id, received_at)` with a page. It reads page_id, psid, received_at and payload with `cursor()`.
- **Query 2:** `chatbot_replies` with `sent_at` in [day, day+2), `kind = send`, `status IN (sent, partial)` and `sequence_id IS NULL`. It uses index `sent_at`, or `(page_id, sent_at)` with a page. It reads page_id, psid, sent_at, confirmed_at and confirmed_status.
  - PHP keeps only the replies of that day's on-bot customers.
  - The matching is a binary search per customer, over the replies sorted by `sent_at`, with the earliest real time from each reply onwards.
- **Cache:** per (day, page, bot), 120 s for today and 1800 s for past days. Both are query builder only (no raw SQL), so MySQL, pgsql and sqlite behave the same.
- **No migration, no new table, no rollup.** Live computing is fast enough (below).
- **Measured** with a throwaway test on sqlite in memory, PHP 8.4. The fixture: one day of 35,000 chats on 40 pages and 30,000 answered replies, plus 30,000 replies the next day.
  - All pages: **0.28 s**, about 36 MB peak above the baseline.
  - One page: **0.02 s**, 0.9 MB.
  - My first version read every reply in the window. It took 0.36 s and 87 MB; reading only the day's customers' replies brought that down.
  - The numbers on MySQL are not measured. Open `/chatbot/speed` once after deploy and note the time.

## Files
- New:
  - `app/Services/Chatbot/ResponseSpeed.php`
  - `app/Http/Controllers/ChatbotSpeedController.php`
  - `resources/views/chatbot/speed.blade.php`
  - `resources/views/chatbot/partials/speed-cells.blade.php`
  - `tests/Feature/Chatblast/ResponseSpeedTest.php`
- Changed:
  - `routes/web.php`: one GET route, `chatbot.speed`, throttle 60/min.
  - `app/Support/ChatblastNav.php`: the Speed tab, after Health.
  - `app/Services/Chatbot/BotHealth.php`: `flowSpans` and `hadFlow` are now `public`. No behaviour change.
  - Three nav tests list the new tab: `ChatbotInboxTest`, `ChatbotSequencePagesTest`, `ChatbotTabsTest`.
- No change to sending, flows, Bot Health thresholds or other screens (decision 4). `ForbeastWatch.php` and `HandleGatewayEvent.php` are untouched.

## Case table
| Case | Test (`tests/Feature/Chatblast/ResponseSpeedTest.php`) | Result |
|---|---|---|
| S-182.1 | `test_S_182_1_the_hour_row_uses_the_first_reply_actually_sent` | pass. 10:00 row: 3 / 2 / 1, avg 46, median 46, p90 72 (confirmed time), ≤ 30 s 1, ≤ 1 min 1, ≤ 5 min 2 |
| S-182.1 | `test_S_182_1_a_reply_without_send_status_uses_its_recorded_time_and_is_marked` | pass (70 s, unconfirmed 1) |
| S-182.1 | `test_S_182_1_a_message_just_before_midnight_counts_in_hour_23_with_its_reply_after_midnight` | pass (also the 24 h limit; added after review) |
| S-182.2 | `test_S_182_2_yesterday_and_the_day_arrows_move_by_one_day` | pass (also hostile date and page_id values) |
| S-182.3 | `test_S_182_3_provider_split_and_filters` | pass |
| S-182.4 | `test_S_182_4_hours_after_now_are_blank` | pass |
| S-182.5 | `test_S_182_5_chats_on_pages_off_the_bot_are_counted_apart` | pass |
| S-182.5 | `test_S_182_5_messages_refused_with_the_bot_off_are_shown_only_without_filters` | pass (added after review) |
| S-182.6 | `test_S_182_6_access_is_like_bot_health` | pass |
| S-182.7 | `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` | pass: OK, 1549 tests (1 skipped), deprecation notices only |
| S-182.8 | `test_S_182_8_the_table_scrolls_inside_its_box_on_a_phone` | pass (markup only: the table scrolls in an `overflow-x-auto` card, and the day nav and filters use `flex-wrap`). Not run in a browser |
| (cache) | `test_today_is_cached_two_minutes_and_past_days_longer` | pass (also one cache entry per filter) |

- **Red runs:** before the code existed, all 9 first tests failed for the right reason: `Target class [App\Services\Chatbot\ResponseSpeed] does not exist`, and 404 on `/chatbot/speed`. The tests added after review pinned behaviour that already existed, so they were green when added.
- **Baseline at 858f3d89** (with only the brief committed): the same three suites gave OK, 1538 tests (1 skipped, the same deprecation notices).
- **First full run after the feature:** 3 failures. They were the three nav tests that list every Chatbot tab exactly. They now include Speed, since the spec asks for the tab.
- **Final:** 1549 tests, no failures. No new failures against the baseline.

## Story changes
- Added S-182 to `qa/stories.md` from the brief's cases. S-182.1 and S-182.5 have one extra test each (midnight and the 24 h limit; the bot-Off refused count).
- S-182.7 notes the three nav tests that changed.

## Review
Skeptic reviewer, standard depth (medium: a read-only screen behind the existing access), on 858f3d89..2dbc55eb. No blockers and no majors.
- **Fixed in 4bb42287:**
  - The unconfirmed note named only Forbeast. It now also covers replies recorded before sent times were kept.
  - Missing tests: the Yesterday link itself, hostile `date` and `page_id` values, one cache entry per filter, midnight and the 24 h limit, and the bot-Off refused count.
- **Accepted in `TODO.md`:**
  - Forbeast's start time leaves out Likha's queue delay.
  - Unconfirmed Forbeast replies count at handover time here, while Bot Health leaves them out.
  - Unconfirmed is not split by bot.
  - There is no clamp on past dates.
  - The full payload is read per chat.
  - The `bot_off` counter key is typed a second time.
  - A few thin branches are untested.
  - The 390 px check is markup only.

No browser check: this session has no browser tools. 390 px was checked by reading the markup and asserting it in S-182.8.

## Conflicts
None between the spec and the project rules.

## Deploy notes
- No migration, no env, no new table, no queue change. Deploy the code, then run `php artisan route:cache` and `php artisan view:cache` if they are cached.
- Chart.js comes from the same CDN script Bot Health already loads. No `npm run build` is needed (no Vite asset changed).
- After deploy, open `/chatbot/speed` once for today with all pages and note the time; it is cached 2 minutes after that.
- **Incepxion:** the same commits apply unchanged. The code is shared (same tables and same Chatbot nav), and none of it is Likha-only.

182: done — new Chatbot › Speed tab shows the first reply time per hour (customer message → reply actually sent, Meta's time from Forbeast when confirmed) for today, yesterday or any day, with a provider split, page filter and a "not on bot" count; about 0.3 s for a 35k-chat day, no migration.

## Amendment 182-1

Verbatim:

> Amendment 182-1 (the owner, 2026-10-28: "yes add to, pero yung sa sequence, pwede din ba kung kelan tayo nagpush, kelan actual sent sa meta? para alam natin actual delay, included din yung max"). Same rules as the spec: Conventional Commits ending "(spec 182)", no assistant/owner names or attribution lines, never push/deploy/touch a server, 2 tries per step, tests with `php vendor\phpunit\phpunit\phpunit` from PowerShell. Save this amendment verbatim in the result under "Amendment 182-1" and list what you applied.
>
> | # | Change | Why |
> |---|---|---|
> | 1 | **AI vs no AI.** In the reply-time table, split each hour into "with AI" (the first reply came from a run that called the AI — chatbot_ai_requests or the run's AI step; find the link) and "no AI" (flow only): count, average, median, p90, max. Add an **AI time** column: how long the AI itself took (request created → answered, chatbot_ai_requests) — average, p90, max per hour. | Owner: AI replies are slower; see them apart. |
> | 2 | **Max** everywhere: add the maximum reply time per hour next to average/median/p90 (and in the totals row). | Owner asked for max. |
> | 3 | **Sequences section** (separate from replies, same day/hour navigation): per hour, sequence steps that were due — scheduled time (when the step was due), pushed time (when Likha queued/sent it to the provider), and actual sent time at Meta (send_status confirmed sent time for Forbeast pages; for Botcake pages the best available sent time, marked). Show per hour: due, sent, failed, skipped; delay **scheduled → pushed** and **pushed → actually sent at Meta**, each with average, p90 and **max**; and a list of the 20 slowest of the day (page, sequence, step, scheduled, pushed, sent, delay). Find the columns in ChatbotSequences / sequence subscriptions / chatbot_replies (sequence_id, sequence_step) — no new table unless truly needed (justify). | Owner: know the actual delay of sequences, including max. |
> | 4 | Tests: AI vs no-AI split on a fixture; max values; a sequence step scheduled 10:00, pushed 10:01, Meta-sent 10:03 → delays 60 s and 120 s in the 10:00 row and in the slowest list. Existing tests pass. | Proof. |
>
> Done when: these pass plus the spec's suites with no new failures vs 858f3d89; result updated; end with "182-1: <done|blocked> — <one line>".

### What I applied
1. **AI vs no AI** (a new table under the hour table, "With AI vs no AI, by hour", plus a Day row).
   - **The link:** a reply is "with AI" when its run's chat (`chatbot_replies.chat_id`) has any `chatbot_ai_requests` row with that `chat_id`, answered or not. The Encode-order wait (`FlowEncode::MODEL`) and `sync` records are not AI calls, the same rule as Bot Health. Every other answered reply is "no AI".
   - **Columns per group:** replies, average, median, p90, max.
   - **AI time:** `answered_at − created_at`, summed over the run's answered calls, as average, p90 and max per hour.
2. **Max:** a Max column after p90 in the hour table, in the Day row and in the Forbeast and Botcake rows.
3. **Sequences section** ("Sequences by hour due", same day and filter controls), plus "Slowest steps of the day" (20).
   - **Source:**
     - A step is the existing `chatbot_events` row of type `sequence` with op `ran`, `skipped` or `failed`.
     - **Pushed** is the `sent_at` of the step's first `chatbot_replies` row: `kind = send`, same page, PSID, `sequence_id` and `sequence_step`, within 600 s before the event.
     - **Sent at Meta** is that row's `confirmed_at` from `send_status` (Forbeast).
     - Botcake sends at once, so its sent time is the pushed time. It is marked "(Botcake: = pushed)" and left out of pushed → Meta.
   - **Scheduled:** the time the step was due, before any retry.
     - Nothing stored it before. The subscription keeps only the *next* due, and the retry paths (lock busy, AI pending, bot paused) move it forward.
     - So the screen rebuilds it: `ChatbotSequences::dueAt(the customer's previous event in that sequence, this step's delay)`. The previous event is the "subscribed" event or the earlier step's event. This is the same formula `subscribe()` and `advance()` used when they set the due.
     - `ChatbotSequences::runStep` now also writes `sequence_id` and `due` (the subscription's `next_send_at`) into the step's event detail. That is the fallback when there is no earlier event.
     - This is the only change to an existing sending-path file, and sending does not change; S-182.12 proves the new fields through the real engine. No new table: the events and replies already hold everything else.
   - **Per hour (by scheduled hour):** due, sent, failed, skipped, no message (the step ran but its flow sent nothing), then scheduled → pushed and pushed → Meta, each with steps, average, p90 and max. Hours after now are blank.
   - **Slowest:** page, sequence, step, scheduled, pushed (+ s), sent at Meta (+ s), and the total delay.
4. **Tests:**
   - `ResponseSpeedTest`: S-182.9 (AI split), S-182.10 (max), and S-182.11 in two tests (10:00 → 10:01 → 10:03 gives 60 s and 120 s in the row and at the top of the slowest list; the original due is kept despite retries).
   - `ChatbotSequenceEngineTest`: S-182.12 (the event records `due` and `sequence_id`).

| Case | Test | Result |
|---|---|---|
| S-182.9 | `test_S_182_9_each_hour_splits_replies_with_ai_from_flow_only_and_shows_the_ai_time` | pass (red first: `Undefined array key "with_ai"`) |
| S-182.10 | `test_S_182_10_max_is_shown_per_hour_and_for_the_day` | pass (red first: `Undefined array key "max"`) |
| S-182.11 | `test_S_182_11_sequence_steps_show_scheduled_to_pushed_and_pushed_to_meta_with_the_slowest` | pass (red first: `Undefined array key "sequences"`) |
| S-182.11 | `test_S_182_11_the_scheduled_time_is_the_original_due_not_the_last_retry` | pass (added after review; red first: 60 s from the last retry instead of 300 s) |
| S-182.12 | `ChatbotSequenceEngineTest::test_S_182_12_a_step_event_records_when_the_step_was_due_and_its_sequence` | pass (red first: no `due` in the detail) |
| S-182.7 | `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` | pass: OK, 1554 tests (1 skipped), deprecation notices only. Baseline at 858f3d89 was 1538; no new failures |

**Time** (the same throwaway sqlite fixture as before, plus 12,000 AI calls and 5,000 sequence steps in the day):
- All pages: 0.48 s, about 56 MB peak.
- One page: 0.02 s.

The time-rebuild fix adds one indexed query on `chatbot_events (page_id, psid, created_at)` for the day's sequence customers. It was not re-timed after that.

**Review:** skeptic reviewer, standard depth, on 345c48f9..721f8605. One major, no blockers.
- **Major, fixed in c7bf1e0b:** the scheduled time was the last retry time. A step that waited on the AI showed almost no delay. It now uses the original due, as described above.
- **Minor, fixed:** Due did not add up when a ran step sent nothing. There is now a "No message" count.
- **Minor, fixed:** the test now asserts that Botcake steps are left out of pushed → Meta.
- **Accepted in `TODO.md`:**
  - AI time includes calls made after the timed reply.
  - The scheduled time uses the step's delay as it is now.
  - A re-join within 10 minutes can reuse a send.
  - The Bot filter on sequences uses each page's bot now.
  - The seconds formatter is copied in three partials.
  - A few thin branches are untested.

**Story changes:** S-182.9 to S-182.12 were added to `qa/stories.md`. S-182.11 includes the original-due case found in review.

**Deploy notes:** no migration, no new table, no env, no build. The sequence workers load the new `ChatbotSequences` code after the usual `php artisan queue:restart`. Steps that ran before the deploy get their scheduled time from the event history where it exists, and otherwise show none. **Incepxion:** applies unchanged.

182-1: done — the Speed tab splits each hour into with-AI and flow-only replies with the AI's own time, shows max everywhere, and adds a sequences section (scheduled → pushed → sent at Meta, each with average, p90 and max, plus the 20 slowest); no new table.
