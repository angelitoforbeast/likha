# Result 183: use echo to know who answered; fix the switch back; read Forbeast's honest lag

## What shipped

1. **Echoes are stored** (`app/Services/Chatbot/ChatEchoes.php`, `HandleGatewayEvent`, `GatewayEventController`).
   - **Source:** every echo reaches Likha through the Forbeast gateway webhook, because Meta sends a page's echoes to every app subscribed to the page. Botcake's JSON API block forwards customer input only and never sends echoes, so there is nothing to read from Botcake.
   - **What is stored:** `HandleGatewayEvent::fields()` now keeps `app_id` (digits only) and `timestamp` (integer ms). Each echo is stored on any page in `pageMap`, whatever its provider and whether the bot is On or Off. Nothing runs because of it, and a person's reply does **not** pause the bot.
   - **Idempotent by Meta mid:** a cache lock per mid (24 h), then a lookup in that customer's echo rows.
   - **Refused (counted `echo_no_id`):** an echo with no mid, a non-numeric page or psid, or a psid equal to the page id.
   - **Batches:** the webhook takes `{"kind":"batch","events":[…]}` or a bare JSON array. A batch may hold `send_status` and echo events (`is_echo` exactly `true`) of one page, signed with that page's secret. Anything else gets 422; over 64 KB without `X-Gateway-Batch: 1` gets 413.
   - **Old jobs:** jobs queued before the deploy (no `app_id` key) keep the old path and are only counted.
2. **Who answered, in the Inbox** (`Inbox::thread`, `chatbot/inbox.blade.php`).
   - **Matched echoes:** an echo of a Likha reply (same text hash or an image, within 120 s, and the same app as the reply's `via`) labels that reply, e.g. "Bot · via Forbeast · Bot (Forbeast)".
   - **Other echoes:** Botcake, Person, Chatbot Builder or Other app show as their own grey page message with that label. A Botcake echo with the same text as a Forbeast reply stays a separate bubble.
   - **Executed actions:** the executed-actions list leaves out echoes.
   - **Polling:** polling has an `echo` cursor.
   - **For other features:** `ChatEchoes::latest($pageId, $psid)` returns the latest sender, its label and the time.
3. **Bot Health** (`ChatEchoes::health`, `BotHealth::buildProblems`, `chatbot/health.blade.php`). Last 60 min, cached 60 s.
   - **Pages measured:** bot On, not a test page id, and at least one echo in the last 24 h. A page with no echoes is not hooked to Forbeast's echo and would look all-unanswered.
   - **Unanswered:** a customer message older than 5 min with no echo from any app in [received − 30 s, received + 5 min]. Ignored attachments (`_ignored`, or the ignore setting with an ignorable attachment) and standby copies don't count. The count is per customer.
   - **Banner:** a page with ≥ 3 unanswered customers is red in "Pages with problems" and in the banner, with the reason "N customers not answered in 5 min". If the page is already red for failed sends, the send reason stays.
   - **Double replies:** consecutive echoes to one customer from two different app ids within 60 s, each pair counted once. The latest 5 examples show with their Inbox links.
   - **New section:** "Who answered" lists, per page, the unanswered customers and the double replies with examples.
4. **Switch back** (`ForbeastWatch`).
   - `health` joins the conditions that switch back after 30 healthy minutes.
   - The gap between two switch backs of a page is `back_gap_minutes` (60), replacing `back_hours` (6).
   - Health is measured during the gap, and the gap applies only at the moment of switching back. A page that was healthy since a new switch at T0+10 goes back at T0+60, not at T0+90.
   - A person's provider change after the switch still blocks it.
5. **Honest lag** (`ForbeastWatch::inbound`).
   - `inbound_lag_seconds` is used as before.
   - A Botcake copy with no Forbeast twin counts as evidence only when the customer also has no echo from any app in [copy − 90 s, copy + inbound_seconds (300)]. The 90 s covers the 60–80 s Botcake copy lag.
   - A later echo (such as the echo of Likha's own standby answer) does not erase the evidence.
   - The settle window after a move is unchanged.
   - Answering from standby is unchanged (decision 7): it still uses all orphans.
   - The switch's `chat_ids` list the evidence chats.
6. **Untouched:** sending, flows, chatblast and Forbeast. No route, nav or migration change.

## Storage choice

The `chatbot_events` table, type `echo`. No migration and no new table.
- **Columns:** `psid` = the customer; `created_at` = Meta's time, or now when the time is missing or more than a day off; `detail = {mid, app_id, sender, hash (16 hex of sha1 of the lowercased, space-collapsed text), text (first 200 chars)}`.
- **Why this table:** it already has `(page_id, psid, created_at)`, `(type, created_at)` and `created_at` indexes and a 30-day prune. Every reader filters by type, except the Inbox executed-actions list, which now excludes `echo`.
- **Why not `botcake_chats`:** every reader of it treats a row as a customer message (counts, flows, Inbox, ForbeastWatch).

## App id map

| App id | Sender | Label |
|---|---|---|
| 1115162827653315 | forbeast | Bot (Forbeast) |
| 556376998159104 | botcake | Botcake |
| 573598697063667 | chatbot_builder | Chatbot Builder |
| 263902037430900 | person | Person (Page Inbox / Business Suite) |
| any other or none | other | Other app |

## Config keys (also in .env.example)

| Key | Env | Default |
|---|---|---|
| services.gateway.echo.unanswered_minutes | GATEWAY_ECHO_UNANSWERED_MINUTES | 5 |
| services.gateway.echo.unanswered_customers | GATEWAY_ECHO_UNANSWERED_CUSTOMERS | 3 |
| services.gateway.echo.double_seconds | GATEWAY_ECHO_DOUBLE_SECONDS | 60 |
| services.gateway.auto_switch.back_gap_minutes | GATEWAY_SWITCH_BACK_GAP_MINUTES | 60 (replaces back_hours / GATEWAY_SWITCH_BACK_HOURS, 6) |

## Case table

| Case | Test | Result |
|---|---|---|
| S-183.1 | `ChatEchoTest::test_S_183_1_a_botcake_echo_is_stored_once_with_its_sender_and_shows_on_the_inbox_chat` | pass |
| S-183.1b | `test_S_183_1b_a_batch_of_three_echoes_is_stored_once_each_in_either_body_shape` | pass |
| S-183.1c | `test_S_183_1c_text_is_cut_to_200_characters_an_unknown_app_is_other_and_an_echo_to_the_page_itself_is_not_stored` | pass |
| S-183.1d | `test_S_183_1d_a_refused_list_stores_nothing` (4 data sets: mixed pages, customer message, `is_echo` as text, 64 KB without header) | pass |
| S-183.1e | `test_S_183_1e_an_echo_labels_only_a_reply_sent_through_its_own_app` | pass |
| S-183.2 | `test_S_183_2_a_person_reply_is_labelled_person_and_the_bot_is_not_paused` | pass |
| S-183.3 | `test_S_183_3_customers_with_no_echo_in_5_minutes_are_unanswered_and_three_make_the_page_red` | pass |
| S-183.3b | `test_S_183_3b_a_bot_off_page_and_fewer_than_three_customers_make_no_banner` | pass |
| S-183.4 | `test_S_183_4_two_apps_replying_to_one_customer_within_60_s_is_a_double_reply` | pass |
| S-183.5 | `ForbeastSmartSwitchTest::test_S_183_5_a_page_moved_for_health_goes_back_after_30_healthy_minutes_unless_a_person_changed_it` | pass |
| S-183.5b | `test_S_183_5b_one_switch_back_per_60_minutes` (41 min refused, 61 min allowed) | pass |
| S-183.6 | `test_S_183_6_a_customer_answered_by_any_app_within_300_s_is_not_evidence_for_a_switch` (echo at 30 s: no switch; echo at 301 s: switch) | pass |
| S-183.7 | suites below | pass |

**Red runs, before the code:**
- **S-183.1, .1b, .1c and .2:** failed on the missing behaviour: no row ("actual size 0 matches expected size 1"), 422 for the batch, and null access on the missing Inbox item.
- **S-183.3, .3b and .4:** failed with `Undefined array key "echoes"` or the missing "Who answered".
- **S-183.5 and .5b:** failed with provider `botcake` where `gateway` was expected.
- **S-183.6:** failed with provider `botcake`, because the page switched.

**After the review:**
- S-183.1e and S-183.6 "301 s" failed first, then passed after the fix.
- The 1c addition (echo to the page itself) failed with a count of 2, then passed after the fix.
- S-183.1d passed from the start: it pins the refusals of the new list shape.

## Suites (done-when)

`php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings` (ForbeastWatch and BotHealth tests are inside):
- **Baseline at 858f3d89:** 1538 tests, 1 failure, 1 skipped. The failure was `FlowNamesTest::test_S_052_7_a_refresh_with_the_same_answers_writes_no_new_row`: "Failed asserting that 11 is identical to 10". Nothing here touches it, and it passed in the later run, so it is flaky.
- **After the code (16a5469d):** 1548 tests, OK, 1 skipped (PHPUnit deprecations only).
- **After the review fixes (d089bb24):** 1554 tests, OK, 1 skipped, 0 failures, 0 errors (PHPUnit deprecations only). No new failures against the baseline.

## Story changes

- Added S-183.1c (200 chars, unknown app, echo to the page itself). The spec names these fields; the page-id refusal came from review.
- Added S-183.1d (the refusals of the new list body) and S-183.1e (echo matched by app). Both came from review.
- S-183.3 is split into .3 (counted and banner) and .3b (bot-Off page and below threshold).
- S-183.5 is split into .5 (health condition, person wins) and .5b (60 min gap).
- S-183.6 has two data sets, so both bounds are tested: an echo within 300 s, and one after.

## Changes to existing tests

`ForbeastSmartSwitchTest::test_S_175_6c` now sets `back_gap_minutes = 360`, the old 6-hour default, so it keeps testing its own story. The new 60-minute default is S-183.5b.

## Review

`skeptic-reviewer`, adversarial on the webhook path and standard on the rest, on 858f3d89..16a5469d.

**Majors, all fixed in d089bb24:**
1. The echo evidence had no upper bound: a later echo, including that of Likha's own standby answer, removed the evidence. It is now bounded to [copy − 90 s, copy + 300 s].
2. The Inbox matched an echo to a Likha reply by text only. A Botcake echo with the same text could hide a double reply or mislabel the reply. Matching now also requires the same app as the reply's `via`.
3. The new bare-list body had no refusal tests. S-183.1d now covers it.

**Minor fixed:** an echo whose psid is the page id is not stored.

**Other minors:** accepted in `TODO.md` under 183, with reasons.

**Declined to judge, to confirm with Forbeast spec 027:**
- **The echo's `psid`.** It is taken as the customer, Meta's recipient. If Forbeast puts the page there, the echo is now refused rather than mis-stored.
- **The `timestamp` format.** Only integer ms is read; anything else falls back to the processing time.

## Conflicts and notes

- **Other work:** none of spec 182's files are touched: no routes, nav or new screen. `chatbot/health.blade.php` gains one section after "Pages with problems", so a merge with any 182 edit there is a small one.
- **Starts empty:** "Who answered" and the unanswered banner start empty after deploy. A page is measured only from its first stored echo, and Bot Health shows "No echoes yet" until then.
- **Double replies:** a person answering within 60 s after the bot also counts. The spec says "two different sender apps", and Page Inbox is an app.
- **This file and the spec do not conflict.**

## Deploy notes

- No migration, no new table.
- Run `php artisan config:clear` (or `config:cache`) for the new keys. The defaults work without `.env` changes. `GATEWAY_SWITCH_BACK_HOURS` is no longer read; set `GATEWAY_SWITCH_BACK_GAP_MINUTES` if a different gap is wanted.
- Restart the queue workers: `HandleGatewayEvent` has new fields and code. Jobs queued before the restart have no `app_id`, so their echoes are counted only and not stored.
- **Expected after deploy:**
  - Pages the watcher moved with condition `health` (the Oct 28 wave), on Botcake with a ready Forbeast token and no person change since, go back to Forbeast after 30 healthy minutes.
  - Pages switched back at 17:42 can switch back again 60 minutes after that.
  - Expect a wave of `switched_back` events in the first hour.
- The same commits apply to Incepxion: same codebase, no site-specific code. The app id map is Meta's, so it is the same for both sites.

183: done — echoes are stored per customer with the sending app (single or batched), the Inbox labels who answered, Bot Health counts unanswered customers (banner at 3) and double replies, health-moved pages switch back after 30 healthy minutes with one switch back per 60 min, and switch evidence needs no copy and no echo within 300 s.
