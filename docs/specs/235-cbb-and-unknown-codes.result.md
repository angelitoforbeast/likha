# Result 235: CBB is Chatbot Builder only, and the auto-switch notices Meta errors it doesn't know

## Status

Plan written, waiting for "go". No code yet. Branch `fix/235-cbb-and-unknown-codes` from `f175cb2f`.

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

Not started.

## Checks

Not run yet.

## Rulings

None yet.

## Story changes

Proposed with the plan, for the reviewer to confirm:
- **S-235.2.5 (R4).** "Fewer than 10 sends → nothing" contradicts B4. Proposed Then: no switch and no orange line;
  the white B4 line still shows if this is the code's first sighting of the day.
- **S-235.2.1 and S-235.2.4 (R5).** The outcome doesn't depend on the order of sends. Each test sends the failures
  first and the successes after.

## Open questions

1. **Meta-wide outage (from the spec review; for the owner through the reviewer).** The burst and hold guard only
   covers `no_answer` and `health`. If Meta returns an unknown code (#1 or #2, say) for every page at once, every
   gateway page with 10 or more RESPONSE sends switches to Botcake within minutes. Botcake goes through the same
   Meta, so nothing is gained, and `runStandby` runs on every page.

   My recommendation is a small extra step inside task 4: when `burst_pages` (5) or more pages trip `unknown_error`
   on the same code within the window, skip the switch and show a B3-style orange line instead. I build it only with
   a yes. Without one, B2 stays exactly as the spec says.
2. **Label on Botcake-route pages.** A Botcake-stamped echo on a Botcake-route page keeps the "Botcake" label in the
   Inbox. A1 calls it the Likha bot's own reply, but no case asks for a new label, so it stays unless you say
   otherwise.

## Proposed tasks

None yet.
