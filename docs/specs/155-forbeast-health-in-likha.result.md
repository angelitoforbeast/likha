# Result 155: Forbeast send health in Bot Health, with a send-lag alarm

## Plan
One medium-risk backend + UI slice (no new route; the Forbeast answer is shown as escaped text with its numbers coerced):
1. `ForbeastSendHealth` service: fetch `GET {gateway.url}/v1/health/sends` (site key, Bearer, connect 3 s / timeout 5 s), keep samples, run the alarm rule, build the panel.
2. `chatbot:forbeast-watch` calls the fetch last, in its own try/catch, so a slow or failing endpoint never delays or breaks the per-page checks.
3. Alarm events go through `ForbeastWatch::event()` (the old private `record()` now calls it) into `chatbot.auto_switch`; the owner feed gains `pages`; the Changes view labels the new results.
4. Bot Health: red banner, "Forbeast sends" panel (6 tiles with lights, inline-SVG 24 h line, per-page table worst first), first-reply notes. /chatbot/pages: the same banner.
5. Review by `skeptic-reviewer` (standard depth), then one minor-fix wave.

## Progress
- Baseline at 66ec2a4e: `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` → 1651 tests, 0 failures, 1 skipped.
- Red: all 9 S-155 tests failed because `ForbeastSendHealth` did not exist (S_155_2 then failed on `Undefined array key "send_lag_seconds"`, with no data stored).
- Green: 9/9. After the feat commit, the target suite ran 1660 tests with 0 failures and 1 skipped.
- Review: no blocker or major findings; 9 minors. Fixed with a test first: the per-page-only trigger and its error text, failed fetches neither count nor reset, non-default thresholds, the alarm cleared when no page is on Forbeast, the note following the chosen page, and the graph's divide-by-zero guard. The rest are accepted in `TODO.md`.
- Final: 1660 tests, 0 failures, 1 skipped (the same skip as the baseline). No new failures.

## Files
- `app/Services/Chatbot/ForbeastSendHealth.php` (new): fetch, samples, alarm rule, panel, active alarm.
- `app/Console/Commands/ChatbotForbeastWatch.php`: calls the fetch after the page loop.
- `app/Services/Chatbot/ForbeastWatch.php`: new public `event()` (with `$extra` fields); adds the `send_lag` reason label.
- `app/Http/Controllers/ChatbotProviderEventsController.php`: the feed carries `pages` (null for other events).
- `app/Http/Controllers/ChatbotController.php`: the banner on /chatbot/pages; Changes labels "Alarm (no switch)" and "Recovered".
- `app/Http/Controllers/ChatbotHealthController.php`: passes the panel and the alarm to the view.
- `resources/views/chatbot/health.blade.php`: banner, panel include, and first-reply notes (the cards and the Forbeast row of Compare bots).
- `resources/views/chatbot/index.blade.php`: banner include.
- `resources/views/chatbot/partials/forbeast-health.blade.php`, `send-lag-alarm.blade.php` (new).
- `tests/Feature/Chatblast/ForbeastSendHealthTest.php` (new), `qa/stories.md` (S-155), `TODO.md`.

### Storage choice
Cache only, no table and no new rows for the samples:
- `forbeast-health:latest` holds the last answer, including the per-page numbers.
- `forbeast-health:samples` holds `[timestamp, overall lag]` per minute. Entries older than 24 h are dropped on each write, so it stays at or under 1440 small pairs.
- `forbeast-health:alarm` holds the streak counters and the active alarm.
- `forbeast-health:missing` is the 404 flag.
- TTL is 25 h.

Why the cache: these are per-minute readings whose only use is the panel and the streak. Writing them to `app_settings` or `page_settings` would add about 1440 history rows a day to tables people read as settings history. What must last is the alarm events, and those are rows in `page_settings` (`chatbot.auto_switch`), where the owner's routine already reads.

Trade-off, accepted in TODO: a cache clear in the middle of an alarm loses the open alarm. There is then no `recovered` row, and a second alarm can follow sooner than 30 minutes.

### The alarm rule
- **One sample:** one successful answer is one sample. A 404, a 500, a timeout or bad JSON is not a sample: it neither adds to the streak nor resets it.
- **Raising an alarm:**
  - When: the overall `send_lag_seconds` is over 120 for 3 samples in a row, or one Forbeast page's lag is over 120 for 3 of its samples in a row.
  - What is written: one `alarm` row. Its result is `alarm`, from `gateway`, to null, condition `send_lag`, error `Forbeast send lag <n>s, <q> queued`, plus `pages`.
  - Page-only alarm: when only pages trigger, `<n>`/`<q>` are the worst lagging page's numbers, and `pages` is the lagging pages.
  - Overall alarm: `pages` is every page on Forbeast.
  - Where the row goes: it is written on the first affected page.
- **Repeats:** at most one alarm per 30 minutes while the alarm lasts.
- **Recovery:** one `recovered` row once the worst lag (overall and every Forbeast page) is under 30 s for 3 samples in a row.
- **Never a switch:** the page stays on Forbeast.
- **No Forbeast pages:** when no page is on Forbeast, the alarm state is cleared and no event is written.
- **Banner:** a red banner on Bot Health and on /chatbot/pages while the alarm is active.

### Panel lights
These come from the endpoint's `thresholds`; the defaults 30/120/5/15 apply only when the answer lacks them.
- **Send lag and forward lag:** amber when over the amber threshold, red when over the red one.
- **Failed %:** uses the failed thresholds.
- **Workers:** red unless the count is above 0 and the status is `running`.
- **Queued and sent/min:** no light, because the contract has no threshold for them.
- **Status:** grey "not available yet" on a 404. "Not available" when there is no reading, or when the last one is more than 5 minutes old (stale); the last numbers are then shown dimmed.

## Story changes
- S-155.5 also covers that failed fetches neither count nor reset the streak, and the per-page-only trigger with its pages and error. The reason: the review found that the "or a Forbeast page" branch and the failure gaps were untested.
- S-155.8 also covers that an active alarm is cleared when every page leaves Forbeast. The reason: otherwise a banner would stay for up to 25 h.
- S-155.10 (P2, 390 px) was checked by markup only (`grid-cols-2`, `overflow-x-auto`); it was not run in a browser.

## Evidence per case
| Case | Test | Result |
|---|---|---|
| S-155.1 | `test_S_155_1_fetch_keeps_24h_of_samples_404_is_not_available_and_a_failure_goes_stale_without_stopping_the_watch` | pass |
| S-155.2 | `test_S_155_2_panel_numbers_and_lights_follow_the_endpoint_thresholds` | pass |
| S-155.3 | `test_S_155_3_per_page_table_matches_by_facebook_page_id_worst_first` | pass |
| S-155.4 | `test_S_155_4_three_lagging_samples_raise_one_alarm_per_30_minutes_then_one_recovered` | pass |
| S-155.5 | `test_S_155_5_a_spike_of_one_or_two_samples_raises_no_alarm` | pass |
| S-155.6 | `test_S_155_6_send_lag_never_switches_a_page_to_botcake` | pass |
| S-155.7 | `test_S_155_7_red_banner_on_bot_health_and_chatbot_pages_while_the_alarm_is_active` | pass |
| S-155.8 | `test_S_155_8_bot_health_renders_without_the_panel_when_no_page_is_on_forbeast` | pass |
| S-155.9 | `test_S_155_9_roles_are_unchanged` | pass |
| S-155.10 | markup check only | not run in a browser |

`ForbeastSendHealthTest`: OK (9 tests). Target suite: `Tests: 1660, Assertions: 9696, PHPUnit Deprecations: 76, Skipped: 1`, no failures (the baseline was 1651 with the same skip). Every test uses `Http::fake` with `Http::preventStrayRequests()`, and no real Forbeast call is made.

## Commits
- `39401e67` docs: spec 155 and its result template (spec 155)
- `76b10a87` feat: Forbeast send health in Bot Health with a send lag alarm and no switch (spec 155)
- `0791d3c3` fix: a page-only send lag alarm names that page's lag, the alarm clears when no page is on Forbeast, the note follows the chosen page (spec 155)
- docs: this result and TODO entries (spec 155)

## Open points
- **Cache store in production:** it must be shared and persistent, such as database or redis, not `array`. Check `CACHE_STORE` on the server before deploy.
- **Deploy:** there are no migrations.
- **Before Forbeast ships its spec 016:** the panel shows "not available yet" and no alarm fires.
- **Accepted minors:** five entries in `TODO.md` under "Spec 155": the cache trade-off; the Changes author "auto-switch" on alarm rows; the banner staying while the endpoint is down; no exact-minute-30 boundary test; and 390 px checked by markup only.
- **Not pushed:** the branch `feat/155-forbeast-health-in-likha` is not pushed and has no PR, as the brief says.

155: done — Bot Health shows Forbeast's real send lag, queue, sent/min, failed %, forward lag and workers with lights, a 24 h line and a per-page table; 3 samples over 120 s write an `alarm` event (one per 30 min, then `recovered`) and show a red banner, never a switch.
