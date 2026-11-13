# 273: The bot gateway switch ignores one stuck connection and our own server's network blips

Weight: bounded. Risk tier: high (decides when live pages leave the gateway for Botcake). Size: medium. Budget: 2 attempts per failing step; stop and report if blocked.

## Why (live data, incepxion.pro, 2026-11-13)
`App\Services\Chatbot\ForbeastWatch::afterSend()` switches a page to Botcake after 3 gateway sends in a row get no answer (HTTP 0 or 5xx; `config/services.php` no_answer = 3). The burst hold only applies when 5+ pages fail at once. Two false switches of the same page today:
- 11:31–11:32: the VPS itself stalled ~1 min (CPU steal 17.9%, DNS "Resolving timed out"); every outbound call failed, Botcake included; the page was switched although the gateway was fine.
- 13:32:27: three sends of ONE page timed out in the same second (curl 28, ~11.8 s each, one stuck connection to the gateway host); Botcake sends were normal (0–2 no-HTTP failures/min), other gateway sends worked the minute before; the page switched at 13:32:30 and came back at 14:03 after 30 healthy minutes.
Each false switch costs ~30 minutes on Botcake. The owner wants the switch to happen only when the gateway is really failing, and to check Botcake first: if our own server cannot reach Botcake either, the problem is ours, not the gateway's.

## Stories
S1 (P1) **Failures in the same burst count once.** No-answer failures of one page that land within `burst_window_seconds` (default 15) of the first failure of that burst count as one strike. A page switches on no_answer only after `no_answer` strikes (default 3) that span at least `no_answer_min_seconds` (default 180) — i.e. failures that keep happening for minutes, not one stuck connection. A success resets the strikes as today.
S2 (P1) **Connection-phase failures are retried once.** When a gateway send fails before the request was sent (DNS resolve failure, connection refused, connect timeout — curl errno 6, 7, or 28 with "Connection timed out"/"Resolving timed out"), the send is retried once after ~2 s; only if the retry also fails does it count. A read timeout after the request was sent ("Operation timed out after N ms with 0 bytes received") is NOT retried (the message may have arrived) and counts as a failure as today. Never retry on a 4xx.
S3 (P1) **Check Botcake before switching (server-side problem → no switch).** Right before any switch away from the gateway (no_answer, failed_sends or health), the watcher checks whether our own server can reach the outside: (a) Botcake sends in the last 2 minutes with no HTTP answer (HTTP 0) ≥ `server_side_min` (default 5) or ≥ 20% of Botcake sends in that window, OR (b) a quick probe GET to `https://botcake.io/` (connect 5 s, total 8 s) fails. If either says our server's network is down, do NOT switch; record a "held: server-side network problem" event (same event store as today) and keep the page on the gateway; re-evaluate on the next failure/check.
S4 (P1) **One live look at the gateway before switching.** Right before a no_answer or failed_sends switch, one health GET to the same gateway health URL `health()` uses; if it answers OK now, don't switch, reset the strikes, record "held: gateway answered on re-check".
S5 (P1) The existing behaviours stay: burst hold across 5+ pages, health-check switch (3 failed checks) — but it also passes S3; switch back after 30 healthy minutes; pages without a Botcake token still get cannot_switch; every switch/hold is recorded with its reason.
S6 (P2) **CPU steal watch.** A scheduled command every 5 minutes samples `/proc/stat` (two reads ~5 s apart) and stores the steal % (cache + one log line). If the average over the last hour is above `steal_alert_percent` (default 5), the Problems panel (`App\Services\Chatbot\ProblemsPanel`) shows a yellow line "Server CPU steal <x>% in the last hour (the hosting provider's machine is overloaded; replies and sends may slow down)". No alert when /proc/stat is unreadable (non-Linux, tests fake it).

## Constraints
- All new numbers in `config/services.php` next to the existing ForbeastWatch keys, overridable by env like the others; defaults as above.
- Smallest change in ForbeastWatch and the gateway send path that does S1–S5; keep its cache-counter style; English UI text.
- Tests (Http::fake / faked clock): three same-second timeouts → no switch; three strikes over 3+ minutes → switch; connect-phase failure retried once and succeeds → no strike; read timeout not retried; Botcake failing too → held, no switch; botcake.io probe failing → held; gateway answers on re-check → held and reset; health-check switch also passes the server-side check; switch back unchanged; burst hold unchanged; steal command stores the value and the panel shows/hides the line at the threshold.
- Commit: `fix(chatbot): the gateway switch ignores one stuck connection and our own server's network blips (273)`; no Co-Authored-By, Claude-Session or "Generated with" lines, no person names. Save this spec as `docs/specs/273-gateway-switch-steadier.md` and a `docs/specs/273-gateway-switch-steadier.result.md`.

## Allowed
composer install, php artisan test (new tests + the full suite once; known pre-existing failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest; in the local test `.env` comment out CHATBOT_QUEUE_CONNECTION and CHATBLAST_QUEUE_CONNECTION, they override phpunit's sync queue), vendor/bin/pint on new files, git add/commit, `git push -u origin feat/273-gateway-switch-steadier`. No real outbound calls in tests. Do NOT merge, push to main or deploy.

## Done when
- Every story's tests pass; full suite adds no new failures beyond the three known ones (paste the summary line).
- The result lists the new config keys with defaults and the files changed.
