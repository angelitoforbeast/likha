# 303 result: a sequence-only lane cap, set from the database

Base: live fd835bca. Branch `feat/303-sequence-lane-cap`.

## Plan

- `SequenceSendPool::laneCap()`: the `app_settings` row `chatbot.sequence_lane_cap` read with `AppSetting::get()` (as 301's `pools()`), kept in one shared cache key `sequence-send-pool:lane-cap:<site>` for `POOLS_CACHE_SECONDS` (60 s). Only the row is cached; with no valid row the value is `BotcakeSendPool::laneTickets()` computed from config every time, exactly as today.
- `cap(true)` (and therefore `room()` and `fullPages()`, which both go through `cap()`) uses that value. It is read once at the start of each claim round in `tick()`, not once per candidate, so the pool adds one cache read per claim round (every 0.2 s), not one per ticket. That matters because 301 found the per-ticket round trips are the pool's limit.
- `cap(false)` (gateway), `BotcakeSendPool::laneTickets()` / `perPage()` (reply pool and chatblast) and `BotcakeClient::slot` (the per-token rate) are unchanged.
- Tests next to 301's, in `tests/Feature/Chatblast/SequencePoolThroughputTest.php`, using its fixture (`load()`, `shardPool()`, the fake clock `BotcakeClock`).

## What shipped

Commits on `feat/303-sequence-lane-cap`:

- `59b1dbfb` docs: spec 303 sequence lane cap and result skeleton
- `1ccdabce` feat(sequences): Botcake lane cap of the sequence pool from the app_settings row chatbot.sequence_lane_cap (303)
- (this result commit)

Code: `app/Services/Chatbot/SequenceSendPool.php` only. New constants `LANE_CAP_SETTING = 'chatbot.sequence_lane_cap'`, `LANE_CAP_CACHE`, `MAX_LANE_CAP = 100`; new `laneCap()`, `resolveLaneCap()` and `forgetLaneCap()`; `tick()` reads `laneCap()` once per claim round; `cap(true)` returns it. A value is used only when it is an integer 1..100 after trimming spaces. Anything else (no row, empty, 0, 101, "x", "2.5", -5, "50 tickets") means today's `laneTickets()` (30 at lane_seconds 3 and rate 10). If the row cannot be read, the error is reported and today's value is used. No migration, no new table, no config or env change.

## Evidence per done-when item

### 1. LC-1 tests pass; 301's pool tests still pass

New tests in `SequencePoolThroughputTest` (rate_per_page 10, lane_seconds 3, so laneTickets = 30; fake clock; fake Botcake):

- `test_LC_1_H_with_the_row_at_50_a_botcake_page_lane_holds_up_to_50_sequence_tickets_spaced_by_the_rate`: one page, 80 due tickets, row 50, Botcake never answers, 10 s in 20 ms steps. The pool's most-held tickets = 50, 50 calls made, 50 `sending` and 30 `pending` tickets; each call at least 0.1 s after the previous one. `cap(false)` (gateway) stays 30.
- `test_LC_1_H_with_a_5_s_botcake_the_row_lifts_a_page_from_6_to_its_rate_and_never_above_it`: one page, 400 tickets, Botcake answering 5 s after each call, 25 s run, calls counted in seconds 10–20. Calls per second: **no row 6.0, row 50 9.6, row 100 10.0**. Every run keeps each call at least 1 / rate_per_page = 0.1 s after the previous one, and row 100 never goes above 10/s. (Row 50 reaches 9.6 rather than 10 because a ticket is held for the 5 s answer plus up to one 0.2 s claim round: 50 / 5.2 ≈ 9.6.)
- `test_LC_1_H_no_row_or_a_bad_value_keeps_todays_30`: no row gives 30. Rows '', 0, 101, x, 2.5, -5 and "50 tickets" each give 30. Row 1 gives 1, and " 100 " gives 100. With no row, the value still follows config (rate 2 gives 6 = `laneTickets()`).
- `test_LC_1_a_changed_row_reaches_the_pool_within_a_minute`: a running pool keeps the cached 30 after the row changes to 50, then uses 50 at the first claim after 61 s.
- `test_LC_1_E_the_reply_pool_lanes_stay_at_lane_tickets`: with row 50, `laneTickets()` and the reply pool's `perPage()` are still 30. When 40 chatblast messages are queued on one page, the reply pool holds 30 of them in `sending`, not 50.

Without the change (with `cap(true)` put back to `BotcakeSendPool::laneTickets()` on purpose, then restored), the two H tests and the "within a minute" test fail. The bad-value and reply-pool tests pass either way, as they should.

Runs (Herd PHP 8.4.16):

| run | tests | result |
|---|---|---|
| `--filter LC_1 SequencePoolThroughputTest.php` | 5 | OK |
| filter `Sequence\|SendPool\|SendQueue\|ReplyPool\|Pool\|Sweep\|CustomerReach\|GatewayRoute\|LaneTickets\|BotRepliesBeforeBlasts\|BotcakeAsyncSend` (includes all of 301's pool tests) | 354 | OK (1 skipped, as at 301) |
| full suite, branch at `1ccdabce` (`-d memory_limit=4G`) | 5,853 | 3 failures, 6 skipped, 0 errors |
| the 3 failing tests on base fd835bca code (`SequenceSendPool.php` and the test file checked out from fd835bca, then restored) | 4 | the same 3 failures |

The 3 failures are base failures, the same three that 301 and 302 listed: `ExampleTest::test_the_application_returns_a_successful_response`, `Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`, and `OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_…` with `/owner/private`. **No new failures.** The full suite was not run a second time on base. Instead, these three were run against the base code, as shown above, because none of them touches the files this spec changes.

### 2. How to set and roll back the row; commits to cherry-pick

See Merge danger.

### 3. Pushed

`origin/feat/303-sequence-lane-cap` (see the report for the head commit).

## Story changes

None.

## Rulings

1. **Only the row is cached, not the resolved cap.** With no row the value is `laneTickets()` read from config on every call, so a site without the row behaves exactly as today, including after a `lane_seconds` or `rate_per_page` change.
2. **Read once per claim round.** `cap()` is called for every candidate in `room()` and for every lane in `fullPages()`. A cache read on each call would add a Redis round trip per candidate, and 301 found per-ticket round trips are the pool's limit. Reading it once per claim round (every 0.2 s) keeps the change at most 60 s old plus one round.
3. **Range 1..100.** This is the spec's range. A value below today's 30 is allowed (for example, to slow a page down) and is a valid setting, not an error.
4. **Same row for all shares.** With 301's N processes, each process uses the same row. A page belongs to one process, so the cap per page is the row value.

## Merge danger

**What ships:** with no row, nothing changes; the sequence pool's Botcake lane cap stays `laneTickets()`. With the row set, only the sequence pool's Botcake lanes hold more (or fewer) tickets. The gateway cap, the reply pool, chatblast and the per-token rate are unchanged. What can change on a page at row 50: up to 50 sequence calls waiting on Botcake at once instead of 30, still at most `rate_per_page` requests a second (shared with the bot's replies through `BotcakeClient::slot`). It needs no migration, no `.env` change, no `config:cache` and no supervisor change. A running pool picks up the row within about a minute without a restart.

**Cherry-pick** (Incepxion's line, after 301's commits, which add `pools()` and the `AppSetting` import this builds on): `1ccdabce` (the code and tests). The two docs commits are optional. It touches only `SequenceSendPool.php` and `SequencePoolThroughputTest.php`.

**Set** (on each site, in the app's database):

```
php artisan tinker --execute="App\Models\AppSetting::set('chatbot.sequence_lane_cap', 50);"
```

or in SQL:

```
INSERT INTO app_settings (`key`, value, created_at, updated_at) VALUES ('chatbot.sequence_lane_cap', '50', NOW(), NOW())
  ON DUPLICATE KEY UPDATE value = '50', updated_at = NOW();
```

The value takes effect within 60 s, at the next claim round of each running process.

**Roll back** (back to today's `laneTickets()`, within 60 s):

```
php artisan tinker --execute="App\Models\AppSetting::where('key', 'chatbot.sequence_lane_cap')->delete();"
```

(or `DELETE FROM app_settings WHERE `key` = 'chatbot.sequence_lane_cap';`). Setting it to 30 works too. Reverting the code also brings back today's behaviour; the row is then ignored.

**Check after setting:** `botcake_send_requests` in `sending` per big page rises to about 50 in busy hours. `chatbot:sequence-lateness` falls on the four big pages. Botcake 429s (the page pauses) should stay at zero: if they appear, lower the row or delete it.
