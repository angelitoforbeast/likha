# 273: The bot gateway switch ignores one stuck connection and our own server's network blips — result

## Summary
- **S1** `ForbeastWatch::afterSend`: a no-answer failure (HTTP 0 or 5xx) starts a new strike only when no strike of the
  same page started in the last `burst_window_seconds` (atomic `Cache::add` on a per-page key, so workers failing in the
  same second agree). Failures inside the window count once, in the no-answer count and in the failed-send count. The
  first strike's time is kept; `no_answer` strikes switch only when they span `no_answer_min_seconds`. A sent message
  resets everything as before. Window 0 / min 0 = the old counting.
- **S2** `GatewayProvider::send` and the sequence pool (`SequenceSendPool::respond`, gateway jobs): a failure before the
  request left (`GatewayProvider::connectPhase`: curl 6, 7, or 28 with "Connection timed out" / "Resolving timed out" /
  "Failed to connect to …") is tried once more after `connect_retry_ms`; only the second failure counts. A read timeout
  ("Operation timed out after N ms with 0 bytes received") and any HTTP answer (4xx included) are never retried. The retry
  is logged as `gateway send: no answer` with `retry: true`.
- **S3** Every Botcake send result (`BotcakeOutbox::outcome`: the chatbot `BotcakeProvider`, the botcake send pool's
  chatbot sends, the sequence pool's Botcake sends) is counted per minute in the cache, all and HTTP 0.
  `ForbeastWatch::switch()` now asks `heldBeforeSwitch()` first for the conditions no_answer, failed_sends and health
  (also the switches at the end of a burst hold): Botcake HTTP-0 sends in the last `server_side_minutes` ≥
  `server_side_min` or ≥ `server_side_pct` % of Botcake sends, else one GET to `server_probe_url` (connect 5 s, total 8 s;
  any HTTP answer = network fine; the verdict is cached 30 s). Network down: no switch, a `held` event with
  `held: server_side` and the reason ("Held: server-side network problem (Botcake: 5 of 5 sends got no answer in 2 min);
  stayed on Forbeast (…)"), recorded at most once per page per 10 min (it repeats on every failing send); the next
  failure or check evaluates again.
- **S4** Before a no_answer switch: one GET of the page on the gateway (the same request as `health()`, now shared as
  `pageCheck()`). Answers, connected and able to send: no switch, the strikes start over, a `held` event with
  `held: recheck` ("Held: Forbeast answered on re-check; …"). Limited by `recheck_max_holds` (default 1) until a send gets
  through — see Open points. Pages without their own gateway token cannot be re-checked and switch as before.
- **S5** Burst hold, health switch, switch back after 30 healthy minutes and cannot_switch are unchanged; the new held
  events carry `held`, so the burst-hold fallback (`heldRow`) never mistakes them for a burst hold, and the switch-back
  search already skips held events.
- **S6** `server:cpu-steal` (every 5 min, `withoutOverlapping`, background) reads `/proc/stat` twice 5 s apart
  (`CpuSteal`), stores `[time, steal %]` in the cache (last hour kept) and logs `server cpu steal`. The Problems panel
  shows one yellow "All pages" line "Server CPU steal x.x% in the last hour (the hosting provider's machine is overloaded;
  replies and sends may slow down)" when the hour's average is above `steal_alert_percent`. New level `yellow` (🟡)
  between orange and white; it does not count as urgent (the banner stays grey for a yellow-only list) and gets its own tab.
  Unreadable `/proc/stat`: nothing stored, no line.

## Config keys
All in `config/services.php` → `gateway.auto_switch`, overridable by env:

| Key | Env | Default |
|---|---|---|
| burst_window_seconds | GATEWAY_SWITCH_BURST_WINDOW_SECONDS | 15 |
| no_answer_min_seconds | GATEWAY_SWITCH_NO_ANSWER_MIN_SECONDS | 180 |
| connect_retry_ms | GATEWAY_SWITCH_CONNECT_RETRY_MS | 2000 |
| server_side_min | GATEWAY_SWITCH_SERVER_SIDE_MIN | 5 |
| server_side_pct | GATEWAY_SWITCH_SERVER_SIDE_PCT | 20 |
| server_side_minutes | GATEWAY_SWITCH_SERVER_SIDE_MINUTES | 2 |
| server_probe_url | GATEWAY_SWITCH_SERVER_PROBE_URL | https://botcake.io/ |
| server_probe_connect_seconds | GATEWAY_SWITCH_SERVER_PROBE_CONNECT_SECONDS | 5 |
| server_probe_timeout_seconds | GATEWAY_SWITCH_SERVER_PROBE_TIMEOUT_SECONDS | 8 |
| recheck_max_holds | GATEWAY_SWITCH_RECHECK_MAX_HOLDS | 1 |
| steal_alert_percent | GATEWAY_SWITCH_STEAL_ALERT_PERCENT | 5 |

`no_answer` (3) is unchanged.

## Files changed
- `app/Services/Chatbot/ForbeastWatch.php` — strikes (S1), `botcakeSend`, `serverSide`, `heldBeforeSwitch` (S3/S4), `pageCheck` shared with `health()`, `heldRow` skips spec-273 holds.
- `app/Services/Chatbot/GatewayProvider.php` — connect-phase retry, `connectPhase()`.
- `app/Services/Chatbot/SequenceSendPool.php` — the same retry for gateway sequence sends.
- `app/Services/Chatbot/BotcakeOutbox.php` — counts every Botcake send result.
- `app/Services/Chatbot/CpuSteal.php` (new), `app/Console/Commands/ServerCpuSteal.php` (new), `routes/console.php` (schedule).
- `app/Services/Chatbot/ProblemsPanel.php` — yellow level and the steal line.
- `config/services.php` — the keys above.
- Tests: `tests/Feature/Chatblast/GatewaySwitchSteadierTest.php` (new); `ChatblastTestCase.php`, `ForbeastHoldTest.php`, `GatewayRouteSequencePoolTest.php`, `ProblemTabsTest.php` (see Story changes).

## Tests and evidence
- New and changed tests (`GatewaySwitchSteadierTest` 21 cases incl. S1–S6, `GatewayRouteSequencePoolTest` with the pool retry): `Tests:    22 passed (124 assertions)`.
- The related switch tests (AutoSwitch, Hold, SmartSwitch, GatewayPerPage, GatewayRoutePerPage, ProblemsPanel, UnknownMetaCode, ChatbotProvider, SequenceHandoff, the two above): `Tests:    183 passed (1728 assertions)`.

- Full suite once: `Tests:    7 failed, 6 skipped, 5163 passed (81410 assertions)` (Duration 1252 s). The 7: the three known (ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest) and four `ProblemTabsTest` cases that listed the tabs without the new yellow one; those expectations were updated and `ProblemTabsTest` + `ProblemsPanelTest` rerun: `Tests:    26 passed (207 assertions)`.

## Story changes
- S4 is bounded: the live look holds a page at most `recheck_max_holds` (1) times until a send gets through, then the
  next run of strikes switches. Literal S4 (hold every time the page GET answers) would keep a page forever on a gateway
  whose page GET answers while its sends stall, which breaks the existing "a stalled gateway moves the page" behaviour
  (S5, spec 194 tests). 0 turns the live look off.
- S4 applies to no_answer only, not to failed_sends: failed_sends also counts sends the gateway answered with an error
  (HTTP 4xx); a page GET that answers proves nothing about those, so with S4 on failed_sends that rule could never switch.
  failed_sends still passes S3.
- S1 also applies to the failed-send count: no-answer failures in the same burst add one, otherwise five sends stuck on
  one connection would switch through failed_sends instead.
- Connect phase also accepts curl 28 "Failed to connect to … (Timeout was reached)" (curl's other wording for a connect
  timeout; nothing was sent).
- Older switch tests (`ChatblastTestCase`) run with `burst_window_seconds` 0, `no_answer_min_seconds` 0,
  `recheck_max_holds` 0 and `connect_retry_ms` 0: they send their failures in the same second and test other rules. The
  base also fakes the probe URL with a 200 so no test reaches botcake.io. `ForbeastHoldTest` fakes its no-answer as a read
  timeout (not retried) instead of a DNS failure, so one send is still one request. `GatewayRouteSequencePoolTest` S-223
  2d now rejects the retry too and checks there is exactly one. `ProblemTabsTest` (spec 245) expects the yellow tab
  (empty, disabled) between orange and white.

## Open points
1. **S4 as written conflicts with S5** (above): built bounded by `recheck_max_holds` = 1. Owner decision: keep 1, raise
   it, or 0 (off; S1 alone already covers the 13:32 single stuck connection).
2. **S4 not applied to failed_sends** (above). Decide if failed_sends should get the live look only when its last
   failure was a no-answer.
3. **S3 percentage on low Botcake volume**: 1 HTTP-0 out of 3 Botcake sends in 2 min (33%) already holds the switch.
   It re-evaluates on the next failure, so it can only delay, but a quiet night with one Botcake blip delays a real
   gateway switch by up to 2 min. `server_side_pct` can be raised or set to 0 (count rule only).
4. Chatblast sends (`BotcakeSendPool` blast steps) are not in the Botcake count: they do not go through
   `BotcakeOutbox::outcome`. The chatbot, sequence and probe signals cover S3; adding blast steps is a small follow-up.
5. The probe and the live look run inside the send (and the switch lock) only right before a switch: up to ~8 s + 5 s
   on that one send. The probe verdict is cached 30 s so a run of failures does not wait each time.
6. Not deployed; no live check of `/proc/stat` on the server (tests fake the reader).
