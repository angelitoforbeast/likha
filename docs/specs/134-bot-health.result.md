# Result: spec 134, bot health dashboard

Status: done, not pushed. Branch `feat/134-bot-health` (cut from `e717c969`). Not opened in a browser (layout and charts unchecked visually).

## Files
- `app/Services/Chatbot/BotHealth.php` (metrics, lights, thresholds, queue depth, 5-minute cache)
- `app/Http/Controllers/ChatbotHealthController.php`
- `resources/views/chatbot/health.blade.php` (Chart.js `@4` CDN, same as the finance page)
- `app/Support/ChatblastNav.php` ("Health" tab); tests `ChatbotInboxTest`, `ChatbotSequencePagesTest`, `ChatbotTabsTest` list it
- `database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php`
- `tests/Feature/Chatblast/ChatbotHealthTest.php` (17 tests, S-134.1 to S-134.17)

## Routes
- `GET /chatbot/health` (`chatbot.health`): same access as `/chatbot/pages`
- `POST /chatbot/health/thresholds` (`chatbot.health.thresholds`): CEO only
- `GET /api/chatbot/health?from&to&page`: `X-CHATBOT-EVENTS-KEY`, numbers only (no slow or failure lists)

## Thresholds JSON (`app_settings` key `bot_health_thresholds`)
`{"reply_p90":{"amber":15,"red":60},"reply_max":{"amber":120,"red":null},"failed_pct":{"amber":3,"red":10},"ai_failed_pct":{"amber":2,"red":10},"ai_p90":{"amber":10,"red":null},"answered_pct":{"amber":98,"red":90},"sheet_failed":{"amber":0,"red":null},"queue":{"amber":200,"red":null}}`
Higher is worse except `answered_pct` (lower is worse). Saving validates numbers, unknown keys, and amber before red; missing keys use defaults. `failed_pct` is judged on the rate without "customer not reachable".

## Migration
Index `chatbot_replies(chat_id, kind, status, sent_at)`, additive. `chat_id` had no index; the first-reply join needs it. No rollup table.

## Timings (sqlite in memory, 45,760 chats, 88,854 replies, 13 queries per load; MySQL not measured)
| Case | Cold | Cached |
|---|---|---|
| 7 days | 67 ms | 0.5 ms |
| 31 days | 309 ms | 0.3 ms |
| 7 days, one page | 11 ms | 0.2 ms |
Without the index the one-page case took 3.6 s, 7 days 113 ms.

## Decisions on open points
- Failed = every send not `sent` (includes skipped). Rate without unreachable = (failed - unreachable) / all sends.
- Percentiles: nearest rank; median: middle value (mean of two middle values for an even count).
- Reason order: unreachable, window (#10), HTTP 5xx, timeout, other.
- The Inbox has no per-customer deep link, so lists link to `/chatbot/replies/{id}`.
- AI rows are purged after 2 days, so AI graphs are short.
- Today and yesterday tiles are always the real dates, whatever range is chosen. Lights and queue are computed per load, outside the cache.

## Tests
New file: 17 pass. `tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 974 tests, 1 failure, `ForbeastStandbyHiddenTest::test_S_117_15…`, which fails identically on `e717c969`.

## Review
`skeptic-reviewer` standard depth: no blockers or majors. Fixed: AI failed and retried tiles added. Accepted in `TODO.md`: unvalidated `page_id` cache keys, reason order edge.

## Commits
See `git log e717c969..HEAD`.
