# Result 235: CBB is Chatbot Builder only, and the auto-switch notices Meta errors it doesn't know

## Status

Done, not pushed, not deployed, no migration. The branch is `fix/235-cbb-and-unknown-codes`, from `f175cb2f`; the work
ends at `f5e3cf0a`, and the docs commit (result, stories, TODO) comes after it. All 13 S-235 cases pass. The full suite
has 2 failures, both on the spec's list of known failures (ExampleTest and ItemTableFitScriptTest); see Checks.

The reviewer said "go" with three decisions:
- the outage guard: yes;
- the Botcake label stays in the Inbox;
- both story changes are accepted.

The skeptic-reviewer ran a spec review before the plan, then reviewed every task at the depth of its tier. Its one
major (tasks 4–5) was fixed in loop 1 and closed on re-check, and no blocker or major is open. No browser check was
run: the only screen change is three new kinds of text line in the existing banner, and this session has no browser
tools.

**Merge danger:** two-way door, high tier.
- **Blast radius:** live gateway pages can now switch to Botcake (or to the other gateway) on a new condition,
  `unknown_error`.
- **Banner:** it gains three line kinds, and "Main flow double" / "leftover" stop counting Botcake echoes.
- **No data is written beyond cache keys and the usual `chatbot.auto_switch` events.**
- **Revert:** revert the branch. Cache keys expire within a day, and a page switched by `unknown_error` returns by
  the healthy rule, or by hand.
- **Merge with `fix/234-stop-after-551`:** both branches touch `SendStatus.php` (234 adds one line just before the
  `afterResult` try block; mine is a separate hunk after it), `config/services.php` (234 edits the `chatbot` block,
  mine `gateway.auto_switch`) and `qa/stories.md` (each appends its own slice). The stories file conflicts at the
  end, and keeping both slices resolves it. The other two files should merge cleanly or with a trivial conflict.
- **`feat/233-psid-page-id`:** no shared files.

**Turning B2 and B3 off quickly, by config only:**
- `GATEWAY_SWITCH_UNKNOWN_RESPONSE_PCT=0` turns B2 off: no `unknown_error` switch, and pending trips don't switch
  either.
- `GATEWAY_SWITCH_UNKNOWN_UPDATE_PCT=0` turns B3 off: no orange UPDATE line.
- Then `php artisan config:clear` (or `config:cache`) and `php artisan queue:restart`.
- The B4 white line has no switch of its own. Counting goes on while both are off, and it costs only cache writes.
- `GATEWAY_SWITCH_BURST_PAGES=0` would turn off the outage guard, together with the existing hold. Don't use it as
  an off switch.

## Plan

A spec review (adversarial, before the plan) said "go with changes". Its six "needs revision" items are built into
the plan below and marked R1–R6. Its concern about a Meta-wide outage is under Open questions.

### Design

**Part A: CBB is Chatbot Builder only** (`app/Services/Chatbot/ChatEchoes.php`)
- `CBB = ['chatbot_builder']`. This alone fixes the false "Main flow double". On a Botcake-route page, that double
  was a Botcake echo counted as CBB plus the Likha main-flow reply in `chatbot_replies`. The query for older CBB
  echoes and `cbb_new` ("answered by CBB") read the constant, so they follow.
- Leftover: only pairs that include a Chatbot Builder echo and are not main-flow doubles. Today every two-app pair
  outside the main doubles counts, so a Botcake + Person pair shows as a "leftover CBB reply". `doubles` stays the
  total of all two-app pairs.
- Labels stay as they are: a Botcake echo is "Botcake" in the Inbox and on Health (S-235.1.3).
- The header comment is fixed: Botcake does forward its echo, and on a Botcake-route page that echo is the Likha bot's
  own reply.
- Dropped from the first draft: mapping Botcake to the Likha bot in the pair check on Botcake-route pages. Those pages
  have no Forbeast echoes, so the mapping would only matter at the moment of a switch (YAGNI).

**Part B: Meta error causes and unknown codes** (`ForbeastWatch.php`, `SendStatus.php`, `GatewayProvider.php`,
`GatewayAfterSend.php`, `config/services.php`, `.env.example`, `ProblemsPanel.php`)
- `ForbeastWatch::cause(?int $code, ?int $sub)` returns one of five causes:
  - `customer`: 551, a subcode in `CUSTOMER_SUBCODES`, or 10 without 1893063;
  - `restricted`: 10 with 1893063;
  - `connection`: a code in `CONNECTION_CODES`; 2022 keeps today's no-switch in `afterResult`;
  - `unknown`: any other code;
  - `null`: no code.
- `afterSend` and `afterResult` use `cause()` in place of their own list checks. Their behaviour changes in one
  respect: `restricted` returns early, with no switch and no `failed_sends` bump. `unknown` keeps today's
  `failed_sends` bump in `afterSend`.
- **Counting (B1).** One method, `ForbeastWatch::tally(page, gateway, type, failed, code, sub)`, counts only for
  gateway pages on their active gateway (the same guard as `afterResult`). It is called from two places:
  - `SendStatus::receive`, for every confirmed status (sent, partial, failed), after the one-time update succeeds.
    It reads the reply row that method already loads; adding `sequence_id` and `chat_id` to that select means no
    new query.
  - **R2:** `afterSend`, for a synchronous failure that carries a Meta code. Such a send never gets a
    `send_status`, so it cannot be counted twice. `GatewayProvider::send` passes the `$messagingType` it already
    has. `GatewayAfterSend` (the sequence pool) passes UPDATE.
- **RESPONSE or UPDATE (R3).** A send counts as RESPONSE when its reply row has `kind = manual` (an Inbox reply,
  sent as RESPONSE), or when `chat_id` is set and `sequence_id` is empty. Everything else counts as UPDATE.
  `chatbot_replies` doesn't store `messaging_type`; this rule mirrors how `BotcakeOutbox` picks the type. Chatblast
  doesn't send through the gateway, so it never reaches this count.
- **Window.** The counts sit in per-minute cache buckets, one set per page, gateway and type: `total`, `unknown`,
  and an array of unknown counts per code. Buckets live 11 minutes, and the window is the last 10 buckets.
  - **R5:** the check runs on every counted send, so the result doesn't depend on the order of sends. Each counted
    send reads one per-page cache marker, "unknown seen in the last 10 min", which an unknown failure sets. Only
    when the marker is there does it read the window with `Cache::many`.
  - Normal traffic costs one cache increment and one cache read per confirmed send, the same as the existing
    counters. With the database cache store in production, that is one or two small cache-table writes per send;
    the result will say so.
- **B2.** When RESPONSE total ≥ `unknown_min_sends` and unknown ÷ total ≥ `unknown_response_pct`, it calls
  `switch($page, 'unknown_error', '#9999: 4 of 12 replies failed in 10 min', [], [], $gw)`.
  - The code named is the most frequent unknown code in the window, written `#code` or `#code subcode N`.
  - `unknown_error` goes into `REASONS` ("Forbeast send failed at Meta with an unknown error"; `reason()` already
    swaps in the gateway's own name) and into `BACK_CONDITIONS`, so `switchBack` brings the page back.
  - Like every other condition, it switches to the other gateway first when that one is ready, otherwise to Botcake.
- **B3.** When UPDATE total ≥ `unknown_min_sends` and the unknown share is ≥ `unknown_update_pct`, there is no switch.
  A cache entry for each page and code (10-minute TTL) goes into an index written under a lock. ProblemsPanel shows an
  orange line: "New Meta error #X on <page>: N of M updates failed in 10 min". N and M are the latest window counts,
  refreshed on each counted send.
- **B4.** `Cache::add` of an `unknown-seen` key per code and date, with a TTL to the end of the day. The first add
  writes the code, page and time into a day index, under the same lock. ProblemsPanel shows one white line per code
  per day under "All pages": "New Meta error #X first seen today on <page> at HH:MM".
- **Config (B6, R1).** Three keys go in `services.gateway.auto_switch`:

  | Key | Env | Default |
  |---|---|---|
  | `unknown_min_sends` | `GATEWAY_SWITCH_UNKNOWN_MIN_SENDS` | 10 |
  | `unknown_response_pct` | `GATEWAY_SWITCH_UNKNOWN_RESPONSE_PCT` | 30 |
  | `unknown_update_pct` | `GATEWAY_SWITCH_UNKNOWN_UPDATE_PCT` | 70 |

  - They are read raw, not through `limit()`, which turns 0 into 1. A percentage of 0 or less turns that part off.
  - To turn B2 or B3 off quickly: set its env var to 0, run `php artisan config:clear` (or `config:cache`), then
    `queue:restart`.
  - A test covers "0 means off".
- **B5 / S-235.3.1 (R6).** BotHealth already counts 1893063 as "Page restricted" from the error text, so that check
  is a characterisation test, green before and after. The red test covers the new behaviour:
  - `cause(10, 1893063)` returns `restricted`, not `customer`;
  - a 1893063 failure is not counted as unknown, so no B4 line;
  - there is no `failed_sends` bump and no switch after `failed_sends` + 1 synchronous and confirmed failures.

### Tasks

| # | Task | Side | Tier | Cases | Files |
|---|---|---|---|---|---|
| 1 | Stories into `qa/stories.md`, slice 235, including the S-235.2.5 change; tests first throughout | docs | low | all | qa/stories.md |
| 2 | Part A: the CBB constant, the leftover rule, the header comment | backend | medium | S-235.1.1, .1.2, .1.3 | ChatEchoes.php; new `tests/Feature/Chatblast/CbbEchoTest.php`; any existing echo test that pins Botcake as CBB (listed in the result) |
| 3 | `cause()`, and B5 in `afterSend` / `afterResult` | backend | high | S-235.3.1, .3.2 | ForbeastWatch.php; new `tests/Feature/Chatblast/UnknownMetaCodeTest.php` |
| 4 | The window tally, the B2 switch, config, the sync and async call sites | backend | high | S-235.2.1, .2.2, .2.3, .2.5, plus "0 means off" | ForbeastWatch.php, SendStatus.php, GatewayProvider.php, GatewayAfterSend.php, config/services.php, .env.example, UnknownMetaCodeTest.php |
| 5 | The B3 orange and B4 white lines | backend | high (same counters) | S-235.2.4, .4.1 | ForbeastWatch.php (alert indexes), ProblemsPanel.php, UnknownMetaCodeTest.php |
| 6 | Full suite; result with case table, merge danger and the config off-switch; TODO for accepted minors | docs | low | n/a | result, TODO.md |

- Task 2 shares no files with tasks 3–5, so it is parallel-safe and runs in its own worktree. Tasks 3, 4 and 5 run in
  that order because they share ForbeastWatch.php.
- Models follow the kit's tiers. Task 2 gets a sonnet developer and a sonnet skeptic-reviewer at standard depth.
  Tasks 3–5 get an opus developer and an opus skeptic-reviewer at adversarial depth.
- No browser check is planned. The only screen change is two new kinds of text line in the existing banner, and this
  session has no browser tools.

### Files shared with the other open branches

- `fix/234-stop-after-551` also changes:
  - `SendStatus.php`: one line next to the `afterResult` call;
  - `config/services.php`: the `chatbot` block, not `gateway.auto_switch`;
  - `qa/stories.md`.

  My SendStatus change goes in a separate hunk after the existing `afterResult` try block. A small textual conflict
  is still possible, depending on which branch merges second.
- `feat/233-psid-page-id`: no shared files.

## Summary

The spec, plan and stories went in first (`9a05446e`, `542ba3cc`, `e370e742`), then one commit per task, then the
fix loop and the final wave.

| Commit | What |
|---|---|
| `65993f42` | **Task 2.** `ChatEchoes::CBB = ['chatbot_builder']`; leftover counts only pairs that include a Chatbot Builder echo and are not main-flow doubles; header comment fixed (Botcake forwards its echo; on a Botcake-route page it is the Likha bot's own reply). Labels unchanged. |
| `0b88ae4f` | **Task 3.** `ForbeastWatch::cause(code, sub)` returns restricted (10 + 1893063), customer, connection, unknown or null. `afterSend` and `afterResult` use it with unchanged behaviour, except that a restriction returns early and never switches or bumps `failed_sends`. |
| `220f6ddf` | **Task 4.** `tally()`: per page, gateway and RESPONSE/UPDATE, a 10-minute window of per-minute cache buckets. It counts from `SendStatus::receive` (every confirmed result, using the reply row it already loads) and from `afterSend` (a synchronous failure that carries a Meta code; the type comes from `GatewayProvider::send`, and `GatewayAfterSend` passes UPDATE). B2 trips settle once a minute in `chatbot:forbeast-watch` (`settleUnknown()`) after `hold_minutes`. `unknown_error` is in `REASONS` and `BACK_CONDITIONS`. Config: three keys in `services.gateway.auto_switch`, read raw (0 = off), plus `.env.example`. |
| `a18ed3f0` | **Task 5.** Three ProblemsPanel lines: orange per page "New Meta error #X on <page>: N of M updates failed in 10 min"; orange site line "Meta error #X on N pages: replies failing; no more pages switched (Botcake goes through the same Meta)"; white "New Meta error #X first seen today on <page> at HH:MM", once per code per day. |
| `7966d5b1` | **Fix loop 1.** Switched trips keep counting toward the burst; `tally()` in `afterSend` has its own try/catch; pending trips honour 0%; the burst is keyed by the code without its subcode; the site lock is taken at most once a minute per page. |
| `f5e3cf0a` | **Final minors wave.** The site line says "no more pages switched", since earlier pages may already have switched. |

**Files changed outside the plan's list:**
- `app/Console/Commands/ChatbotForbeastWatch.php` (6 lines), to call `settleUnknown()`, which the outage guard
  needs.
- Two existing tests:
  - `DoubleReplyUrgentTest` S-202.2a: psid 7203 now uses a Chatbot Builder echo, where it used Botcake as CBB. The
    assertions are unchanged and the test's intent is kept.
  - `SequenceHandoffTest` S-215: the `afterSend` mock expectation gained the new `'UPDATE'` argument.

**Other branches' files:** none of `feat/233-psid-page-id`'s were touched. Of `fix/234-stop-after-551`'s files,
`SendStatus.php`, `config/services.php` and `qa/stories.md` were touched (see Merge danger).

**Cache cost of a normal confirmed send** (`CACHE_STORE=database`):
- `Cache::add` plus `Cache::increment` on the minute total, and one read of the per-page marker. That is 4–5
  cache-table statements, plus the cached page map read, and no table query. It is the same shape as the existing
  `bump()` counters.
- An unknown failure adds the per-code bucket, the marker write, the first-of-day `Cache::add`, and one
  `Cache::many` of the window.

## Checks

- **Lint:** `php -l` is clean on every changed PHP file.
- **Full suite** (`vendor/bin/phpunit` on `f5e3cf0a`): `Tests: 4535, Assertions: 77595, Failures: 2, PHPUnit
  Deprecations: 95, Skipped: 6.` The 2 failures are the known pre-existing ones from the spec:
  - `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response`
  - `Tests\Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
    (`Failed asserting that actual size 57 matches expected size 45.`)

  BigBroadcastTest and AstraAddressRulesTest passed in this run, so there are no new failures.
- **Runner:** tests were run with `vendor/bin/phpunit`, not `artisan test`. In this worktree the Collision printer of
  `artisan test` prints a spurious `file_get_contents(...)` warning on every test, while plain phpunit passes the same
  files cleanly.
- **Related files**, all green after the last commit: ChatEchoTest, IncepxionEchoTest, DoubleReplyUrgentTest,
  ProblemsPanelTest, ForbeastAutoSwitchTest (37), ForbeastSmartSwitchTest (23), ForbeastHoldTest (17),
  GatewayPerPageTest (18), GatewayRoutePerPageTest, QueuedJobCompatTest (5), SequenceHandoffTest (11),
  SendStatusBatchTest (16), EarlySendStatusTest (5).

**Red runs** (one line per slice, first failure):
- S-235.1.1: `main_doubles expected 0 but was 1` (CbbEchoTest.php:84).
- S-235.1.3: `[cbb_new, leftovers, main_doubles]` expected `[0,0,0]` but was `[1,1,0]`.
- S-235.3.1 / .3.2: `Call to undefined method ForbeastWatch::cause()`.
- S-235.2.1: `Failed asserting that null is not null` (no switch event).
- S-235.2.4 / .2.6 / .4.1: `Failed asserting that two arrays are identical` (no line).
- S-235.2.5: `actual size 0 matches expected size 1` (no white line).
- Staggered variant in S-235.2.6: `ang ika-5 page ay hindi lumipat` (the 5th page switched on the old code).
- Varying-subcode variant: `5 is identical to 0`.
- 0% with a pending trip: the page switched.
- `tally()` throwing: `null is identical to 'failed_sends'`.
- Final copy: `two arrays are identical`.

**No red run,** because these cases pin what must not happen or what was already true:
- S-235.1.2: a real Chatbot Builder double was already shown.
- S-235.2.2, .2.3, the first part of .2.7, and the S-235.3.1 behaviour asserts.
- The successes-first variant of S-235.2.1, since the check already ran on every counted send.

**Case table** (from `vendor/bin/phpunit tests/Feature/Chatblast/CbbEchoTest.php
tests/Feature/Chatblast/UnknownMetaCodeTest.php --testdox`: `OK (13 tests, 72 assertions)`):

| Case | Test | Result |
|---|---|---|
| S-235.1.1 | `CbbEchoTest::test_S_235_1_1_botcake_echoes_of_likha_sends_are_not_a_main_flow_double` | pass |
| S-235.1.2 | `CbbEchoTest::test_S_235_1_2_a_chatbot_builder_echo_with_likha_sends_is_a_main_flow_double` | pass |
| S-235.1.3 | `CbbEchoTest::test_S_235_1_3_a_botcake_echo_on_a_gateway_page_is_labelled_botcake_and_never_cbb` | pass |
| S-235.2.1 | `UnknownMetaCodeTest::test_S_235_2_1_four_of_twelve_replies_failing_with_an_unknown_code_switch_the_page` (through SendStatus::receive and the chatbot:forbeast-watch command; failures-first and successes-first) | pass |
| S-235.2.2 | `UnknownMetaCodeTest::test_S_235_2_2_two_of_twelve_replies_failing_do_not_switch` | pass |
| S-235.2.3 | `UnknownMetaCodeTest::test_S_235_2_3_customer_code_failures_make_no_switch_and_no_line` | pass |
| S-235.2.4 | `UnknownMetaCodeTest::test_S_235_2_4_most_updates_failing_with_an_unknown_code_show_an_orange_line` | pass |
| S-235.2.5 | `UnknownMetaCodeTest::test_S_235_2_5_fewer_than_ten_sends_make_no_switch_and_no_orange_line` (through GatewayProvider::send, synchronous) | pass |
| S-235.2.6 | `UnknownMetaCodeTest::test_S_235_2_6_a_meta_wide_unknown_code_switches_no_page_and_shows_one_site_line` (same minute, varying subcodes, and staggered) | pass |
| S-235.2.7 | `UnknownMetaCodeTest::test_S_235_2_7_zero_percent_turns_the_switch_and_the_line_off` (including a pending trip) | pass |
| S-235.3.1 | `UnknownMetaCodeTest::test_S_235_3_1_code_10_subcode_1893063_is_a_restriction_that_never_switches` | pass |
| S-235.3.2 | `UnknownMetaCodeTest::test_S_235_3_2_code_10_subcode_2018278_stays_a_customer_cause` | pass |
| S-235.4.1 | `UnknownMetaCodeTest::test_S_235_4_1_the_first_unknown_code_of_the_day_shows_one_white_line` | pass |

## Rulings

1. **Outage guard (the reviewer's yes).** When `burst_pages` (5) or more distinct pages trip B2 on the same unknown
   code (without its subcode) within the 10-minute window, no further page switches and one orange site line shows.
   - A page can't know whether more pages will follow, so a B2 trip waits `hold_minutes` (2) and settles in the
     once-a-minute `chatbot:forbeast-watch`. A B2 switch therefore comes 2–3 minutes after the threshold, not at
     once.
   - Pages that switched more than `hold_minutes` before the 5th trip stay switched and return by the healthy rule.
     Pages that switched still count toward the burst.
   - `burst_pages=0` turns the guard off, the same meaning it has for the existing hold.
2. **The Botcake label stays in the Inbox** on Botcake-route pages (the reviewer's yes).
3. **RESPONSE or UPDATE** comes from the reply row. `kind = manual`, or a chat with no sequence, counts as RESPONSE;
   everything else counts as UPDATE. `messaging_type` isn't stored, and this rule mirrors how `BotcakeOutbox` picks
   it. Chatblast doesn't send through the gateway, so it is never counted.
4. **Where counting happens:**
   - every confirmed `send_status`;
   - every synchronous gateway failure that carries a Meta code, because that send never gets a `send_status`.

   A synchronous "sent" is counted only by its later `send_status`. A failure without a code (HTTP 0, 5xx) counts in
   the total only, and stays with the existing `no_answer` / `failed_sends` rules.
5. **The B2 reason** reads "Forbeast send failed at Meta with an unknown error: #9999: 4 of 12 replies failed in 10
   min". The existing `reason()` joins the condition and the error with ": " and swaps in the gateway's own name. N
   counts every unknown code in the window; the label is the most frequent one.
6. **The B3 orange line** is one per page (its most frequent code). It stays up to 10 minutes after the page was last
   over the threshold.
7. **No conflict between this spec and CLAUDE.local.md.** The kit's `backend-developer` agent isn't installed in this
   session, so general-purpose agents did the development under the kit's TDD rules (sonnet for medium, opus for
   high). The skeptic-reviewer reviewed each task at its tier's depth.

## Story changes

- **S-235.2.5 (accepted by the reviewer).** The Then was "nothing". It is now "no switch and no orange line (the white
  line of S-235.4.1 still shows on the first sighting of the day)". Reason: B4 shows a new code even below the
  thresholds.
- **S-235.2.1 and S-235.2.4 (accepted).** Both now say "(in any order)". Reason: the check runs on every counted send,
  so the outcome doesn't depend on whether failures come first.
- **S-235.2.6 (added).** A Meta-wide unknown code switches no page and shows one site line. Reason: the reviewer's
  outage ruling.
- **S-235.2.7 (added).** Both percentages at 0 turn the switch and the line off. Reason: the done-when item "turn off
  B2 and B3 quickly by config", and the spec review found that the existing `limit()` turns 0 into 1.
- **S-235.3.1 (sharpened).** The Then now names what it asserts: cause restricted (not customer), not counted as
  unknown, no `failed_sends` switch, counted for "Page restricted". Reason: "not skipped as a customer code" had
  nothing observable, and "Page restricted" was already true, so that part is a characterisation test.

## Open questions

None open.
- The two questions in the plan (the outage guard and the Botcake label) were answered by the reviewer.
- What is still undecided is under Proposed tasks.
- The accepted minors are in `TODO.md`, under "CBB and unknown Meta codes (spec 235)".

## Proposed tasks

1. **Treat 1893063 like #2022 in `PageRestriction`**, so the restriction holds follow-ups. Today
   `PageRestriction::isRestriction` matches only `#2022`, so a page restricted with #10 keeps sending sequences into
   the same refusal. This was out of scope here: B5 says only that the existing line covers it.
2. **Make `ProblemAcks::kind` independent of numbers, codes and plurals, for all lines at once.** Today acknowledging
   "#9999" also hides "#8888", as it does for every line that differs only by digits.
3. **Reset a trip's hold when a page crosses the threshold again on the other gateway** (TODO entry). This needs a
   two-gateway test fixture.
4. **Watch the cache-table load during the first real unknown-code incident.** If it runs hot, move the cache store to
   Redis, or count only failures per minute.

