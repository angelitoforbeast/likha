# Result 175: the Forbeast watcher moves a page only when Forbeast is really at fault, and moves it back by itself

## What shipped

- **Evidence before an "inbound" switch** (`ForbeastWatch::inbound`). A Botcake copy with no Forbeast twin counts once it is `inbound_seconds` (300) old, and a switch needs copies from `inbound_customers` (3) customers. Then, in this order:
  1. **Likha is behind** (lag ≥ `lag_seconds`, 60): the page is held, with the event `held: Likha ingest lag N s` (once per hour per page). Nothing is answered, because the Forbeast copies are still in Likha's queue and will answer themselves.
  2. **Forbeast says it is fast** (latest health `inbound_lag_seconds` < 60): the page is held, with the event `held: Forbeast healthy (lag N s), N customers missing — likely not delivered by Meta` (once per hour per page), and the missing customers are answered from standby.
  3. **Otherwise** (field absent or ≥ 60): the page switches as before. The error starts with `Forbeast slow: N s · ` when the lag is known, and the event carries `customers`, `likha_lag` and `forbeast_lag`.
- **Missing messages are answered** with the existing standby path (`answerLate` → `runChats`, under the switch lock). Its skip rules are unchanged: not when the customer was answered since, and not when a Forbeast run for the twin is still going. This happens when the page is held because Forbeast is healthy, and also below the threshold when Forbeast's lag is unknown (older Forbeast). It does not happen when Forbeast reports lag ≥ 60 s (the copy is still coming) or when Likha is behind. A chat answered from standby gets the payload marker `_from_standby` and still counts as evidence, so customers answered one by one still add up to a switch. It is never answered twice: only real standby rows run.
- **Automatic switch back** (`ForbeastWatch::switchBack`, called by `chatbot:forbeast-watch` for every page whose latest auto_switch row is a returnable switch).
  - Eligible pages:
    - the page is on Botcake and the page's Forbeast token is ready;
    - the latest auto_switch event (skipping send-lag alarm/recovered rows) is `switched` to Botcake with the condition `inbound`, `failed_sends` or `no_answer`;
    - no provider row is newer than that event, so a person always wins;
    - there was no `switched_back` in the last `back_hours` (6).
  - The health check runs every minute for these pages. Each check that comes back connected, with can_send true and inbound_lag_seconds below 60 or absent, extends a streak. A failed or slow check, or a gap of more than 180 s between checks, restarts it. After `back_minutes` (30) the provider is set to `gateway` and an event is recorded: `switched_back`, from botcake, to gateway, condition `healthy`, error `back on Forbeast after N min healthy`, `healthy_minutes`, `forbeast_lag`.
  - The streak only starts after the switch.
- **Visible reasons.**
  - `/chatbot/pages` note: gets a `label` field. It reads "Auto-switched" while on Botcake, and "Held on Forbeast" or "Back on Forbeast" on Forbeast pages for 24 h. A manual provider change hides it, as before.
  - Changes log: shows "Held on Forbeast · …" and "Switched back to Forbeast · …".
  - `/api/chatbot/provider-events`: gets a `reason` field. The other fields are unchanged.
  - BotHealth still counts only `switched`.
- **Untouched:** connection-code switches (190, 200, 2022, 613, 4, 32), health switches, sending, flows, chatblast and Forbeast.

## Config keys (services.gateway.auto_switch, also in .env.example)

| Key | Env | Default |
|---|---|---|
| inbound_customers | GATEWAY_SWITCH_INBOUND_CUSTOMERS | 3 |
| inbound_seconds | GATEWAY_SWITCH_INBOUND_SECONDS | 300 |
| lag_seconds | GATEWAY_SWITCH_LAG_SECONDS | 60 |
| back_minutes | GATEWAY_SWITCH_BACK_MINUTES | 30 |
| back_hours | GATEWAY_SWITCH_BACK_HOURS | 6 |

## The exact "Likha is behind" check

`HandleGatewayEvent::fields()` now stamps `queued_at` (unix time at dispatch, in the webhook controller). `handle()` writes `now − queued_at` to the cache key `forbeast-watch:ingest` (TTL 300 s) for every gateway event. `likhaLag()` is the larger of:

- (a) that last recorded wait;
- (b) the wait of the oldest job now waiting on the `chatbot-high` queue of `queue.chatbot_connection`. On the database driver this is `now − min(available_at)` of unreserved, already-available jobs. On redis it is Horizon's `WaitTimeCalculator::calculateFor('<connection>:chatbot-high')`.

Likha is behind when the result is ≥ 60 s. This replaces the spec's alternative "newest stored gateway row < 60 s old": the queue wait measures Likha's lag directly, while a row's age also depends on customer traffic.

## Case table

| Case | Test | Result |
|---|---|---|
| S-175.1 | `test_S_175_1_three_customers_missing_for_300_s_switch_when_forbeast_says_nothing_about_lag` | pass |
| S-175.2 | `test_S_175_2_fewer_customers_or_a_shorter_wait_never_switch_and_customers_past_the_wait_are_answered` (2 data sets) | pass |
| S-175.3 | `test_S_175_3_when_likha_is_behind_the_page_is_held_with_one_event_and_nothing_is_answered` (processed late; waiting in the database queue) | pass |
| S-175.4 | `test_S_175_4_when_forbeast_says_it_is_fast_the_page_is_held_and_the_missing_customers_are_answered` | pass |
| S-175.5 | `test_S_175_5_a_slow_forbeast_switches_with_its_lag_as_the_reason` | pass |
| S-175.6 | `test_S_175_6a…` (30 min back, 29 not, note and changes), `6b` (person wins), `6c` (6 h), `6d` (slow check restarts), `6e` (alarm row doesn't block) | pass |
| S-175.7 | `test_S_175_7_a_connection_code_still_switches_at_once_and_never_moves_back` | pass |
| S-175.8 | full run below | pass |

The red runs before the code: all S-175 tests except 6b and 7 failed on the missing behaviour. 6b and 7 pin behaviour that was already true, that nothing switches back. After review: S-175.2 (2 customers, 300 s) failed with "Failed asserting that false is true" (the customers were not answered), and 6e failed with `'gateway'` expected, `'botcake'` actual.

## Suites (done-when)

`php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings`:
- baseline at 88f49262: **1500 tests, OK, 1 skipped**;
- after: **1513 tests, OK, 1 skipped**, 0 failures, 0 errors (PHPUnit deprecations only, in old test files).

The ForbeastWatch tests (`ForbeastAutoSwitchTest`, 35 tests) and the BotHealth tests (`ChatbotHealthByBotTest`) are inside that run.

## Story changes

- S-175.2 also asserts that customers past 300 s are answered (decision 2, "no customer is left without a reply"; this was found in review).
- S-175.6 gained "a slow check restarts the 30 minutes" and "a send-lag alarm row after the switch does not block it".
- "A page set to Botcake by a person after the switch" is tested as a person moving it to Forbeast and back to Botcake. Re-choosing the provider a page already has writes no row (`PageSettings::set` returns "unchanged"), so it leaves no trace to honour.

## Changes to existing tests

- `ForbeastAutoSwitchTest::setUp` pins `inbound_customers = 2` and `inbound_seconds = 120`. Its tests are about pairing and the settle window at the old thresholds; the new defaults are tested in `ForbeastSmartSwitchTest`.
- S-117.8 now looks for the literal `'Auto-switched'` instead of `'Auto-switched '`, because the note's summary is now `(a.label || 'Auto-switched') + ' ' + a.at`.

## Review

`skeptic-reviewer`, standard depth, on d23ca173.
- **Major (fixed in db8be7bf):** customers below the threshold were never answered when the Forbeast lag was unknown.
- **Minor (fixed):** a send-lag alarm row could hide a returnable switch.
- **Minors accepted in TODO.md:**
  - "no missing copies / no send failure" can't be measured while a page is on Botcake: Forbeast drops that page's messages and nothing sends through it;
  - the Horizon path is untested (production uses the database queue);
  - one cache write per gateway event, and a retried job counts its retry delay as lag;
  - switch-back health calls run one after another in the command;
  - the missing tests for the feed `reason` field, the "Held on Forbeast" note, and failed_sends/no_answer going back.

## Conflicts and notes

- `resources/views/chatbot/index.blade.php` (the /chatbot/pages view, where spec 174 is also working) has a one-line change in `autoSwitchNote` plus its comment. Decision 4 needs it. It is a small, easy merge conflict if spec 174 touches the same lines.
- Remaining risk of a double reply: a customer answered from standby when Forbeast's lag is unknown, whose Forbeast copy then arrives more than 300 s late with a different mid, would get a second reply. Forbeast's new `inbound_lag_seconds` closes this: with a known lag ≥ 60 s nothing is answered early.
- `health` condition switches (Forbeast says not connected, or the health check fails 3 times) are not switched back. Decision 3 names inbound and send-related switches only.

## Deploy notes

- No migration, no new table.
- `php artisan config:clear` (or `config:cache`) for the new keys; the defaults work without .env changes.
- Restart the queue workers / `horizon:terminate`: `HandleGatewayEvent` has a new field and code. Jobs queued before the deploy have no `queued_at` and are simply not measured.
- After deploy, the pages the watcher moved on Oct 26–27 (inbound) become eligible: each goes back to Forbeast after 30 healthy minutes if its Forbeast token is ready and nobody changed its provider since. Expect a wave of `switched_back` events in the first hour.
- The same commits apply to Incepxion (same codebase; no site-specific code).

175: done — the inbound switch now needs 3 customers, 300 s, a Likha that isn't behind and a Forbeast that isn't reporting itself fast; held customers are answered; watcher-moved pages return after 30 healthy minutes.
