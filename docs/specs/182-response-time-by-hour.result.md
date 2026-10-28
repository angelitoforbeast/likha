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
