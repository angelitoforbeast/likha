# Result 156: real send time, pending replies and real first reply in Bot Health

## Plan
One medium-tier backend + UI slice (no new route: a new event type on the existing signed `/api/gateway/events`), then one fix loop from the review.
1. Schema: three nullable columns on `chatbot_replies` (`client_ref`, `confirmed_at`, `confirmed_status`) + two indexes. No new table: one row per send already exists, and the real time belongs to that row (decision 1).
2. Write paths store `client_ref` on every send (flow outbox, inbox manual reply). Botcake sends that come back `sent` get `confirmed_at = sent_at`, `confirmed_status = sent` when they are written (Botcake is synchronous).
3. `HandleGatewayEvent` passes `send_status` to the new `SendStatus` service (match, idempotent update, failed/partial mapping, counts).
4. The new `RealSendTime` service (available, pending, unconfirmed, accept → sent lags) and the Bot Health changes (real first reply per day, bot and page; Pending tile; hourly chart).
5. Alarm: `ForbeastSendHealth::pendingCheck()` runs every minute from `chatbot:forbeast-watch` and shares spec 155's alarm state and event row.

## Progress
- Baseline at 816c5baa (done-when command): 1694 tests, 0 failures, 1 skipped.
- Red: 9 of the 10 new tests failed because the behaviour was missing (for example `Undefined array key "real"`, `null is identical to 'likha-…'`). S-156.9 passed from the start because it pins today's accepted numbers.
- Green on the first implementation. The review found 2 majors (fixed in one loop) and the full suite found 1 regression (`ChatbotHealthByBotTest` S-149.16 compares the whole compare row; the `real_*` keys are now added only when the new columns exist).
- Fix-loop red: with the fixed files reverted, 3 of the extended tests failed (S-156.3, S-156.5b, S-156.6). They passed after the fix.
- Reviewer re-check: no blockers, no majors.

## Files
- `database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php` (new)
- `app/Services/Chatbot/SendStatus.php` (new): match by `client_ref`. Fallback: a row without `client_ref` (sent before this deploy) on the same page + PSID, the nearest within ±10 min of `accepted_at`. The update is guarded by `whereNull('confirmed_status')`, so it runs once. `failed`/`partial` → the reply's status and an error such as `Forbeast could not send: "…" (#551) subcode 1545041` or `Forbeast sent 2 of 4: "…" (#10)`, so REASON_SQL counts it like a Botcake failure.
- `app/Services/Chatbot/RealSendTime.php` (new): available, pending, unconfirmed, lags, summary.
- `app/Jobs/HandleGatewayEvent.php`: sanitised `send` fields; `send_status` is handled before everything else and counted (`send_status`, `send_status_repeat`, `send_status_unknown`, `send_status_bad`).
- `app/Models/ChatbotReply.php`: fillable/casts, `hasConfirm()`, `confirmColumns()`.
- `app/Services/Chatbot/BotcakeOutbox.php`, `app/Http/Controllers/ChatbotInboxController.php`: pass `client_ref` and the Botcake confirmation.
- `app/Services/Chatbot/BotHealth.php`: `real_at` in the inbound query; `real_median/p90/max` per day and per bot; the `real` section (per page, unconfirmed, lag); pending added fresh in `output()`; the hourly chart uses the real time when available.
- `app/Services/Chatbot/ForbeastSendHealth.php`, `app/Console/Commands/ChatbotForbeastWatch.php`: `pendingCheck()`. Recovery in `alarmCheck` now also needs the pending source to be low.
- `resources/views/chatbot/health.blade.php`: "(accepted)" labels; Real first reply tiles; the "Real send time" section (Pending, Unconfirmed, accept → sent median/p90/max, per-page table, the "not available yet" note); a real column in Compare bots.
- `tests/Feature/Chatblast/RealSendTimeTest.php` (new), `qa/stories.md` (S-156), `TODO.md`.

### Schema change
- Additive and nullable only, with no default and no `AFTER`, guarded and idempotent. It changes no rows. It was designed for chatbot_replies at millions of rows.
- On MySQL 8.0.12+ the column add is `ALGORITHM=INSTANT` (metadata only, no table rewrite). On 5.7 it would rebuild the table. The production version has not been checked: run `SELECT VERSION()` before `migrate --force`.
- The two indexes, `client_ref` and `(via, confirmed_status, sent_at)`, are built online (INPLACE, reads and writes allowed). Each reads the table once, so expect minutes, not seconds.
- No backfill: older Botcake rows without `confirmed_at` use their `sent_at` in the queries.

### Behaviour notes
- A `partial` reply counts as answered (the customer got something) and also as failed (every status other than `sent` counts as failed, as before).
- A `failed` that arrives after Forbeast's 202 takes the reply out of the accepted first reply numbers. Spec .2 asks for this ("counted in failures").
- A confirmation that only says `sent` changes no accepted number (S-156.9).
- A reply does not count as pending or unconfirmed in two cases:
  - it was sent before the first `send_status` ever seen (deploy day);
  - a newer confirmed reply exists for the same page + PSID. Forbeast sends in order per PSID, so a lost event cannot hold the alarm for 24 h.
- "Available" means at least one Forbeast confirmation in the last 24 h.

## Story changes
- S-156 was added to `qa/stories.md` from the spec's cases. Additions, each for a reason found in the review:
  - .2: an unknown status is counted and nothing is written.
  - .3: a reply with a newer confirmed reply to the same customer is not pending (a lost event).
  - .5: a second test. A health alarm with no `send_status` yet is cleared only by health samples (a source with no data neither raises nor clears).
  - .6: on the first `send_status`, older replies are not pending or unconfirmed (deploy day).
  - .9: `partial` also leaves the accepted numbers unchanged.

## Evidence per case
| Case | Test | Result |
|---|---|---|
| S-156.1 | `test_S_156_1_send_status_sets_the_real_send_time_once_and_the_real_first_reply_follows_it` | pass |
| S-156.2 | `test_S_156_2_failed_and_partial_show_like_a_botcake_failure_with_the_meta_code` | pass |
| S-156.3 | `test_S_156_3_pending_counts_accepted_unconfirmed_replies_with_the_oldest_age_per_page` | pass |
| S-156.4 | `test_S_156_4_real_first_reply_median_p90_max_per_bot_and_per_page_with_botcake_on_its_sent_at` | pass |
| S-156.5 | `test_S_156_5_alarm_from_likha_pending_after_3_minutes_and_recovery_under_30s_without_a_switch`, `test_S_156_5_a_health_alarm_is_only_cleared_by_health_samples_while_send_status_is_not_available` | pass |
| S-156.6 | `test_S_156_6_before_any_send_status_the_real_time_is_not_available_and_there_is_no_pending_alarm` | pass |
| S-156.7 | `test_S_156_7_an_unknown_client_ref_is_counted_and_ignored_and_a_reply_from_before_the_ref_is_matched_by_page_and_psid` | pass |
| S-156.8 | `test_S_156_8_unconfirmed_after_24_hours_is_unconfirmed_not_pending` | pass |
| S-156.9 | `test_S_156_9_the_accepted_numbers_stay_as_they_were`, plus the unchanged `ChatbotHealthTest` (17), `ChatbotHealthByBotTest` (2), `ForbeastSendHealthTest` (9) | pass |
| S-156.10 (P2) | `test_S_156_10_the_real_send_section_wraps_at_390px` (markup: `grid-cols-2`, `overflow-x-auto`) | pass (markup); not run in a browser, no browser check in this session |

`RealSendTimeTest`: OK (11 tests, 105 assertions).

Done-when suite (`tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode tests/Feature/MetaAds`) on the final tree: **1705 tests, 0 failures, 0 errors, 1 skipped**, the same skip as the baseline. No new failures against 816c5baa (1694 tests).

One earlier full run ended with 1 error and 1 failure (`ChatbotTagsTest:288`: seconds 900/600 came back as 0). It ran at the same time as the reviewer's test runs in the same worktree. `ChatbotTagsTest` alone passed (12 tests), and the next full run was clean. The other red test's name was cut off in that run's output; the clean rerun covers it.

## Commits
- fbc3844a docs: spec 156 and its result template (spec 156)
- 245a925c feat: real send time from Forbeast send_status, pending and real first reply in Bot Health, pending send lag alarm (spec 156)
- fb771129 fix: no pending or unconfirmed before the first send_status, a lost send_status does not hold the alarm, pending without data neither raises nor clears it (spec 156)
- 4a19778f docs: S-156 stories and cases (spec 156)
- the final commit: docs: result, case table and accepted minors (spec 156)

## Open points
- Check the production MySQL version before the migration (INSTANT needs 8.0.12+). The index builds take minutes on millions of rows.
- Deploy Forbeast 017 on a drained queue. Otherwise replies sent between the backlog and the deploy show as pending for up to 24 h (in TODO.md).
- The accepted minors with reasons are in TODO.md under "156".
- Not pushed and not deployed. No real Forbeast or Botcake calls were made (fakes only).

156: done — Bot Health shows the real send time from Forbeast's send_status, the pending and unconfirmed replies, the real first reply per bot and page, and the accept → sent lag; Likha's own pending data also raises the send lag alarm; no new failures in the done-when suite.
