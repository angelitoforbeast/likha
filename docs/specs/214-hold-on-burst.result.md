# Result: spec 214 a short outage between the sites does not switch every page to Botcake

## Status

done (amendment 214-1 included; no open question)

## Date

2026-11-01

## Branch / PR

`feat/214-hold-on-burst` (worktree `.claude/worktrees/hold-213`), based on `vps/main` d05d1b63. Not pushed, no PR.

## Summary

A burst of no-answer on many pages of one gateway now holds instead of switching each page to Botcake.

- **Burst.** Every no-answer send (HTTP 0 or 5xx, the existing no-answer path) records its page in a
  per-gateway burst list (cache, last `burst_seconds`).
- **Hold.** When a page reaches its 3rd no-answer while `burst_pages` or more pages are in the list, the
  gateway goes into a hold for `hold_minutes`.
  - One `held` event row is written: gateway, page count, page names and end time.
  - No page switches, and sends keep going through the gateway.
- **Recovery.** A sent message, or a health probe that answers connected, from a page that was in the burst
  ends the hold.
  - One `recovered` row is written: gateway, page count, seconds held, and how it was seen.
  - The no-answer and failed counters of those pages reset.
- **Window passed without an answer.** Every page that reached the threshold switches.
  - It gets the same `switched` row (`condition no_answer`, plus `held_seconds`) and the same switch-back
    rule as today.
  - The hold is ended by whichever comes first: `chatbot:forbeast-watch` (`settleHolds`, every minute,
    after the health checks) or the first no-answer after the window.
  - An ended hold is kept for an hour. Pages that reach the threshold during that hour switch at once, with
    no second hold for the same outage.
- **Health probes (amendment 214-1).** A health probe of the page's own gateway that gets no answer (or 5xx)
  joins the same burst and hold as a no-answer send.
  - During a hold, failed probes are counted but switch nothing.
  - 5 or more pages failing probes within `burst_seconds` start a hold (the `held` row has `trigger: health`).
  - At the hold's end, every page over either threshold (3 no-answers or `health_fails` failed probes) switches
    in that same step, with condition `no_answer` or `health` (the first it reached) and today's rows and
    switch-back rule.
  - A recovery resets both counters.
  - One page failing probes alone switches as today; a 4xx probe and a "not connected" answer are unchanged.
- **Per gateway.** forbeast and incepxionbot each have their own burst and hold.
- **Cache lost during a hold.** The latest `held` row of the gateway (inside the window, with no `recovered`
  after it) restores the hold with its original start. The window is never skipped or stretched.
- **Probe-only recovery.** If the hold ended on a health probe alone, no new hold starts on that gateway until
  a page of that burst sends successfully (at most `back_minutes`).
  - This stops a gateway that answers GET but not POST from holding the failing pages forever.
  - Those pages then switch as before.
- **Config.** `services.gateway.auto_switch.hold_minutes` (2), `burst_pages` (5) and `burst_seconds` (60),
  from env `GATEWAY_SWITCH_HOLD_MINUTES`, `GATEWAY_SWITCH_BURST_PAGES` and `GATEWAY_SWITCH_BURST_SECONDS`.
  - `.env.example` is updated.
  - `burst_pages=0` turns the hold off.
- **Feed.** `/api/chatbot/provider-events` already returned every `auto_switch` row. It now also returns
  `gateway`, `page_count` and `held_seconds` (null when absent).

Files changed:
- `app/Services/Chatbot/ForbeastWatch.php`
- `app/Console/Commands/ChatbotForbeastWatch.php`
- `app/Http/Controllers/ChatbotProviderEventsController.php`
- `config/services.php`
- `.env.example`
- `tests/Feature/Chatblast/ForbeastHoldTest.php`
- `qa/stories.md`

No file of specs 210/211 is touched.

## Case table

| Case | Test (`tests/Feature/Chatblast/ForbeastHoldTest.php`) | Result |
|---|---|---|
| S-214.1 | `test_S_214_1_one_page_alone_switches_after_three_no_answers_as_today` | pass |
| S-214.2 | `test_S_214_2_a_burst_on_five_pages_holds_instead_of_switching_with_one_held_event` | pass |
| S-214.3 | `test_S_214_3_an_answer_from_an_affected_page_ends_the_hold_and_resets_the_counters` (data sets: a sent message, a health probe); `…_a_probe_that_answers_while_sends_still_fail_gives_one_hold_then_pages_switch_as_today`; `…_a_probe_saying_the_page_is_not_connected_does_not_end_the_hold`; `…_an_answer_from_a_page_outside_the_burst_does_not_end_the_hold` | pass |
| S-214.4 | `test_S_214_4_when_the_hold_ends_without_an_answer_every_page_that_reached_the_threshold_switches` (through `chatbot:forbeast-watch`); `test_S_214_4_the_first_no_answer_after_the_window_ends_the_hold_before_the_command_does` | pass |
| S-214.5 | `test_S_214_5_refused_sends_with_an_answer_keep_the_failed_sends_rule_and_never_make_a_burst` | pass |
| S-214.6 | `test_S_214_6_holds_are_per_gateway` | pass |
| S-214.7 | `test_S_214_7_a_cleared_cache_during_a_hold_resumes_it_and_never_skips_the_window` | pass |
| S-214.8 | `test_S_214_8_the_window_and_burst_are_config_values_and_zero_pages_turns_the_hold_off` | pass |
| S-214.9 | `test_S_214_9_the_feed_shows_the_held_and_recovered_events` | pass |
| S-214.10 | `test_S_214_10_health_failures_during_a_hold_do_not_switch_and_the_hold_end_switches_both_kinds_together`; `test_S_214_10_a_recovery_resets_the_health_counters_too` | pass |
| S-214.11 | `test_S_214_11_a_burst_of_failed_probes_starts_a_hold_and_one_page_alone_switches_as_today` | pass |

## Checks (command and output)

PHP is Herd's `php.bat` (PHP 8.4.16). Tests use phpunit.xml (sqlite in memory, array cache).

- `php -l` on the four changed PHP files: `No syntax errors detected` (each).
- `vendor/bin/phpunit tests/Feature/Chatblast/ForbeastHoldTest.php` on 26bf752e (amendment 1): `OK (17 tests, 144 assertions)`.
- The existing ForbeastWatch tests, run again on 26bf752e:

  | Test file | Output |
  |---|---|
  | `ForbeastAutoSwitchTest` | `OK (35 tests, 219 assertions)` |
  | `ForbeastSmartSwitchTest` | `Tests: 23, Assertions: 574, PHPUnit Deprecations: 4` (no failures) |
  | `GatewayPerPageTest` | `OK (18 tests, 190 assertions)` |
  | `ProblemsPanelTest` | `Tests: 11, Assertions: 52, PHPUnit Deprecations: 1` |
  | `ForbeastSendHealthTest` | `OK (9 tests, 177 assertions)` |
  | `ChatbotProviderTest` | `Tests: 19, Assertions: 115, PHPUnit Deprecations: 2` |
  | `ForbeastPageTokenTest` | `OK (10 tests, 79 assertions)` |
  | `ForbeastStandbyHiddenTest` | `OK (4 tests, 12 assertions)` |

- **Review:** `skeptic-reviewer`, standard depth (medium tier), on the whole diff `d05d1b63..HEAD`.
  - First pass: needs fixes. The two majors and the minors are listed under Rulings 5–7, Deferred minors and
    Open questions.
  - Loop 1 (9af6ab81): needs fixes. A healthy page's send cleared the probe marker.
  - Loop 2 (009845c0): **pass**, no blocker or major.
  - Amendment 1 (26bf752e): **pass**, no blocker or major. One minor accepted (Deferred minors: GET 5xx on 5+ pages while sends work).
- Full suite (`vendor/bin/phpunit`) on 009845c0, before amendment 1 (the amendment asked for the files above to be re-run, not the full suite): `Time: 15:55.790`, `Tests: 3989, Assertions: 43543, Failures: 4, PHPUnit Deprecations: 92, Skipped: 4`. No failure is new. Each one was re-run with the four changed source files put back to `d05d1b63`:

  | Failing test | On the base code | What it is |
  |---|---|---|
  | `BigBroadcastTest::test_S_167_1_5000_messages_on_50_pages…` (4370 of 5000) | fails on the base too | Load-dependent. It passed when run alone on HEAD. It does not use the gateway or `ForbeastWatch`. |
  | `ExampleTest::test_the_application_returns_a_successful_response` (302, not 200) | fails on the base too | Not related to this change. |
  | `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width…` (57, not 45) | fails on the base too | Not related to this change. |
  | `AstraAddressRulesTest::test_S_26_18…` (`27.79 is less than 25.0`) | fails on the base too under the same load (33.08 s, 34.72 s) | A wall-clock limit. HEAD alone gave 32.36 s and 32.34 s. It does not touch chatbot code. |

## Red lines

Each was watched failing before its code. Each failure is on the assertion, not on a typo or a missing class.

**Slice 1** (hold start, e4e1ce91), on the base code:
- `test_S_214_2…`: `Failed asserting that two strings are identical.` at ForbeastHoldTest.php:121. The page switched to `botcake`.
- `test_S_214_5…`: `Failed asserting that actual size 0 matches expected size 1.` at :222. No held row.
- `test_S_214_6…`: `two strings are identical` at :240.
- `…outside_the_burst…`: `actual size 0 matches expected size 1` at :171.
- S-214.1 passes on the base code by design. It pins today's behaviour, which the hold must keep.

**Slices 2 and 3** (recovery, hold end, cache loss, e7b557c6), on slice 1's code:
- `test_S_214_3…` (both data sets): `Failed asserting that actual size 0 matches expected size 1.` at :153. No recovered row.
- `test_S_214_4…`: `Failed asserting that two arrays are identical.` at :191. No page switched at the window end.
- `test_S_214_7…`: `two strings are identical` at :255.
- `test_S_214_8…`: `two strings are identical` at :297. The custom hold never ended.

**Slice 4** (feed, 1e5456f8), on slice 3's code:
- `test_S_214_9…`: `Failed asserting that two arrays are identical.` at :311. `gateway` and `page_count` came back `'missing'`, `held_seconds` null.

**Fix loop 1** (9af6ab81), on 1e5456f8:
- `…_a_probe_that_answers_while_sends_still_fail…`: `two strings are identical` at :186. Page 1 was held again instead of switching.
- `…_a_probe_saying_the_page_is_not_connected…`: `two arrays are identical` at :207. A recovered row was written.
- `test_S_214_4_the_first_no_answer_after_the_window…`: `two arrays are identical` at :260. Page 1 was left on the gateway.

**Fix loop 2** (009845c0), on 9af6ab81:
- `…_a_probe_that_answers_while_sends_still_fail…`, with a healthy page's send added: `Failed asserting that two strings are identical.` at :188.

**Amendment 1** (26bf752e), on 009845c0:
- `test_S_214_10_health_failures_during_a_hold…`: `Failed asserting that two strings are identical.` at :397. The pages switched by health during the hold.
- `test_S_214_10_a_recovery_resets_the_health_counters_too`: `two strings are identical` at :423. Page 3 switched on the old health count.
- `test_S_214_11…`: `two strings are identical` at :443. The probe burst switched the pages instead of holding.
- The single-page part of S-214.11 passes on the earlier code by design (today's behaviour).

## Done-when checklist

- [x] Cases S-214.1 – S-214.11 pass, each with a test named after it, and the existing ForbeastWatch tests still pass (see Checks).
- [x] The result has a red line for every feat/fix slice, watched before the code (see Red lines).
- [x] The full suite adds no new failures (see Checks, full suite).
- [x] `qa/stories.md` has these stories with "Last run" filled, and this result is filled in.

## Rulings

1. **"No answer" keeps its current meaning: HTTP 0 or 5xx** (`$noAnswer` in `afterSend`).
   - Both count toward a burst and both are held.
   - A 4xx with an answer stays on `failed_sends`. It never joins a burst, and it still switches at 5 during
     a hold (S-214.5).
2. **During a hold, a no-answer send does not trigger `failed_sends` either.**
   - It is the same path evidence.
   - Otherwise the 5th no-answer of a held page would switch it through the other rule.
3. **Recovery needs an answer from a page that was in the burst.** This is S-214.3's "affected page", and it
   applies to sends too.
   - A success from another page proves nothing about the broken path.
   - Counting it would let a partial outage hold forever.
4. **On recovery, the `no_answer` and `fails` counters of the burst's pages both reset.** The fails came from
   the same outage.
5. **A health probe ends a hold only when it answers `connected` and `can_send`.**
   - "Not connected" is a page problem, not proof the path is back.
   - The health rule handles it as today.
6. **After a probe-only recovery, no new hold starts until a page of that burst sends successfully** (found in
   review, two loops).
   - Without this, a gateway that answers GET but not POST would hold, recover, and hold again forever, and
     no page would ever switch.
   - The cost is at most one hold of delay. After that, the failing pages switch as before.
7. **The hold ends at whichever comes first: the watch command or the first no-answer after the window.**
   After it ends, pages that reach the threshold switch at once for an hour, with no second hold.
8. **The `held` row goes on the page whose 3rd no-answer started the hold, and the `recovered` row on the same
   page.**
   - This follows the send-lag alarm's precedent: one row, with the other pages in `pages`.
   - `recovered` reuses the existing result string. Its condition `no_answer` tells it apart from the
     send-lag `recovered`.
   - Every reader that skips the send-lag rows (switch-back, notes) skips these too. `reason()` gives the
     row's own text.
9. **If the cache is lost, the hold resumes from the `held` row with its original window.**
   - The pages that had reached the threshold are not known after the loss.
   - They rejoin on their next no-answer.

10. **Failed probes and no-answer sends share one burst list and one hold per gateway** (amendment 1).
    - A page counts once, whichever way it failed. The amendment's "5+ pages failing probes" is the case
      where only probes run, for example when there are no customer messages.
    - Only probes of the page's own gateway count, and only when there was no answer or 5xx, the same as
      a no-answer send. A 4xx probe and a "not connected" answer keep today's rules.
11. **A page that reached both thresholds switches with the condition it reached first.**
12. **The `held` and `recovered` rows keep condition `no_answer` for both kinds of hold.** The new `trigger`
    field (send or health) tells them apart, so the cache-loss restore and the feed stay unchanged.

## Story changes

- **S-214.10 and S-214.11** added by amendment 214-1, red first.

From review, these are extra tests under existing case IDs; no Given / When / Then was changed:
- **S-214.3**, added from review:
  - a probe that answers while sends still fail (including a healthy page's send in between);
  - a probe that says not connected;
  - an answer from a page outside the burst.
- **S-214.4**, added from review: the first no-answer after the window ends the hold.

## Deferred minors

Accepted from review, not fixed:
- **Lock timeout.** If the hold's lock cannot be had within 3 s, the burst update runs without it.
  - That can drop a burst entry or write a second `held` row.
  - It is rare and harmless, and sends must never wait longer.
- **`lastSwitch` reads only the last 20 `auto_switch` rows of a page.**
  - Repeated held/recovered pairs on the same page could push an older switch row out.
  - This is bounded now that a probe-only recovery allows one hold.
- **Inline end of a hold.** When the first no-answer after the window ends the hold, that one send job switches
  every reached page.
  - If the job dies partway, `ended` is already set.
  - The pages it did not reach then switch on their next no-answer, not through `settleHolds`.
- **Wrong gateway in the label.** "Held on Forbeast" also shows for an Incepxionbot hold, in three places: the
  ChatbotController history, the ProblemsPanel and the /chatbot/pages note. The reason text names the right
  gateway.

- **GET failing with 5xx on 5+ pages while sends work** (amendment 1 review). A probe burst starts a hold, and the
  next send from a burst page ends it and resets the counters, so the feed gets a held+recovered pair about every
  3 minutes. The pages correctly stay on the gateway, because sends work.

## Deploy notes

- **No migration and no new table.** The state is in cache keys plus the existing `chatbot.auto_switch` rows:
  - `forbeast-watch:burst:<gateway>`
  - `forbeast-watch:hold:<gateway>`
  - the lock `forbeast-watch:hold-lock:<gateway>`
- **New env values, all optional:** `GATEWAY_SWITCH_HOLD_MINUTES=2`, `GATEWAY_SWITCH_BURST_PAGES=5`,
  `GATEWAY_SWITCH_BURST_SECONDS=60`.
  - If config is cached, run `php artisan config:cache` after setting them.
  - `GATEWAY_SWITCH_BURST_PAGES=0` turns the hold off without a deploy.
- **Restart the queue workers after the deploy,** so the send path loads the new `ForbeastWatch`.
- **Load at the end of a hold.** When a hold ends by time, the job that ends it (the watch command or one send
  job) switches every reached page. That can be dozens of switches, each with its standby run. Before this
  change the same work was spread over many jobs, one switch each.

## Merge danger

- **Door:**
  - the no-answer branch of `ForbeastWatch::afterSend` (every gateway send that fails without an answer);
  - `health()` on a probe of the page's own gateway that gets no answer or 5xx;
  - `health()` on a probe that answers connected;
  - one extra step in `chatbot:forbeast-watch`.
- **Blast radius:** live routing of every chatbot page on both gateways.
  - If it is wrong, a real outage keeps pages on the gateway up to `hold_minutes` (default 2) longer before
    they switch, or for one more hold after a probe-only recovery. Since amendment 1 this also covers pages
    whose probes fail.
  - A single failing page is not affected (S-214.1).
- **Revert:** either of these.
  - Set `GATEWAY_SWITCH_BURST_PAGES=0` for the old behaviour, with no deploy.
  - Revert this branch's code commits.

  Nothing stored needs cleaning: the cache keys expire on their own.

## Open questions

None. The question about the health rule switching during a hold is closed by amendment 214-1 (Ruling 10,
cases S-214.10 and S-214.11).

## Proposed tasks

- Name the gateway in the "Held on …" label (history, Problems panel, page note).

214: done — a burst of no-answer sends or failed health probes on 5+ pages of one gateway now holds 2 min and re-checks before switching; pages over either threshold switch together if it lasts
