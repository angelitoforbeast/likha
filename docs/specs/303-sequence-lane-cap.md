# 303 — A sequence-only lane cap, set from the database

## Why
After spec 301 the busy site sends ~45–55 sequence steps a second, but four big pages still queue thousands of steps (40 minutes old at worst) because each Botcake page lane holds at most `BotcakeSendPool::laneTickets()` = lane_seconds 3 ÷ interval 0.1 s = **30** tickets, and Botcake answers in ~5 s, so a page sends ~6 steps/s while its Botcake rate (`rate_per_page` 10/s, shared through BotcakeClient::slot with the bot's replies) allows 10/s. Measured on production: no 429s from Botcake in 30 minutes at that volume, and bot reply times on the big pages equal the other pages'. The lane cap comes from `services.botcake.lane_seconds` (env), which the bot's reply pool and chatblast also use, so raising it would change them too.

## Stories and cases
**LC-1 Sequence lane cap from app_settings (P1).** `SequenceSendPool::cap(true)` (Botcake lanes) uses the `app_settings` row `chatbot.sequence_lane_cap` when it holds an integer 1..100, read through the same cached resolver style as 301's `pools()` (one shared cache, ≤60 s); otherwise today's `BotcakeSendPool::laneTickets()`. `fullPages()` and `room()` use the same value. The gateway lane cap and the reply/chatblast pools are unchanged. The per-token rate (BotcakeClient::slot) still bounds every page, so a higher cap can never exceed `rate_per_page`.
- H: row 50 → a Botcake page lane holds up to 50 sequence tickets; requests still spaced by the rate interval (test with the fake clock, as 301's tests do).
- H: no row / bad value (0, 101, "x") → today's 30.
- E: the reply pool's lanes stay at laneTickets() (test).

## Constraints
- No migration, no new table. Keep every comment in the language and style of the file around it.
- Tests next to 301's pool tests. The sequence/send-pool filter passes; the full suite adds no new failures compared with base fd835bca (list them, or say which filter you ran if the full suite is not repeated, with reason).

## Allowed commands
vendor/bin/phpunit (with filters) via Herd PHP; git add, git commit, git push -u origin feat/303-sequence-lane-cap.

## Done when
1. Tests for LC-1 pass; 301's pool tests still pass.
2. The result says how to set and roll back the row (tinker one-liner) and the commits to cherry-pick.
3. The branch is pushed.

## Budget
Small job. At most 2 attempts at the same failing step, then stop and report.
