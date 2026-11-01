# Plan: spec 214 hold on a burst of no-answer

Risk tier: medium (live message routing). Side: backend only. All tasks touch `ForbeastWatch.php` and one test
file, so none is parallel-safe; they run in order in this worktree. Threat model: nothing new reaches the code
from outside. The inputs are the gateway's HTTP outcome (status code only) and config (trusted). No new route,
table or migration.

## Design

State lives in the cache next to the existing counters, plus the existing `chatbot.auto_switch` event rows.

- **Burst list** `forbeast-watch:burst:<gateway>`: page hash → [page name, unix time of its last no-answer].
  Updated on every no-answer send (HTTP 0 or 5xx, the existing `$noAnswer`), entries older than
  `burst_seconds` dropped. Read and written under a short cache lock per gateway.
- **Hold** `forbeast-watch:hold:<gateway>`: `since`, `until` (since + `hold_minutes`), `page` (the page that holds
  the `held` row), `seen` (every page with a no-answer since the burst), `reached` (page hash → [name, last
  error] of the pages that reached the no-answer threshold), `ended` (set when the window passed and the
  pages were switched).
- **afterSend, no-answer path only.** When a page's no-answer count reaches the threshold:
  1. hold active and window open → the page joins `reached`, no switch;
  2. hold whose window passed, or ended → switch this page as today;
  3. no hold, burst pages ≥ `burst_pages` (> 0) → start a hold, one `held` event (gateway, page count, pages,
     window), no switch;
  4. otherwise → switch as today (S-214.1).
  The failed-sends rule (4xx with an answer, and the `fails` counter) is untouched.
- **Recovery.** A sent message, or a successful health probe `GET /v1/pages/{id}`, from a page in `seen` while the
  hold is open: the hold and the burst list are cleared, `no_answer` and `fails` of every `seen` page reset, one
  `recovered` event (gateway, page count, seconds held, how it was seen). A success after the hold ended clears
  the ended state quietly (the pages already switched).
- **Hold end.** `chatbot:forbeast-watch` (every minute, after the health checks so a recovered probe wins)
  calls `settleHolds()`: a hold whose window passed is marked ended and every page in `reached` is switched
  with `switch(..., 'no_answer', ...)`, the same event rows and switch-back rule as today. The ended state lasts
  at most an hour, so pages reaching the threshold meanwhile switch at once instead of a second hold.
- **Cache lost during a hold (S-214.7).** When a page reaches the threshold and no hold is cached, the latest
  `held` row of that gateway inside the window (with no `recovered` row after it) restores the hold with its
  original `since`, so the window is never extended or skipped. If the counters were lost too, pages need three
  new no-answers, and the burst list fills again before that.
- **Config** `services.gateway.auto_switch.hold_minutes` (2), `burst_pages` (5), `burst_seconds` (60), env
  `GATEWAY_SWITCH_HOLD_MINUTES`, `GATEWAY_SWITCH_BURST_PAGES`, `GATEWAY_SWITCH_BURST_SECONDS`.
  `burst_pages` 0 = no hold (read without the `max(1, …)` of `limit()`).
- **Feed.** `held` and `recovered` rows are already in `/api/chatbot/provider-events` (every `auto_switch`
  row). The feed adds `gateway`, `page_count` and `held_seconds` (null when absent) so the alarm can report them.
  `recovered` reuses the result string of the send-lag alarm; its condition `no_answer` tells them apart, and
  every reader that skips `recovered` today (switch-back, notes) should skip this one too.

## Tasks

| # | Task | Cases | Tier | Side |
|---|---|---|---|---|
| 1 | Config, burst list, hold start in `afterSend`; per gateway; failed-sends unchanged | S-214.1, .2, .5, .6, .8 | medium | backend |
| 2 | Recovery from a sent message or a health probe | S-214.3 | medium | backend |
| 3 | Hold end in the command; restore after a cache loss | S-214.4, .7 | medium | backend |
| 4 | Feed fields for held and recovered | S-214.9 | low | backend |
| 5 | Stories in `qa/stories.md`, result, full suite, one skeptic review of the whole diff | all | — | docs |

Tests: `tests/Feature/Chatblast/ForbeastHoldTest.php`, one test per case named after it, Forbeast faked with
`Http::fake`, time moved with `travel()`. The existing `ForbeastAutoSwitchTest`, `ForbeastSmartSwitchTest`,
`GatewayPerPageTest` and `ProblemsPanelTest` must stay green.
