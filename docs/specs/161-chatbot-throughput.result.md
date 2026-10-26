# Result 161: the chatbot lanes scale for 50–100 chats per second

## What shipped
- `config/horizon.php`, supervisor `chatbot`: `minProcesses` 4 (per queue, so 8 always up), `maxProcesses` default 40 (`HORIZON_CHATBOT_MAX` still overrides), `balanceMaxShift` 5, `balanceCooldown` 1, `memory` 256 per worker (unchanged, now pinned by a test). Horizon's auto scaler (v5.50) grows each pool by up to 5 per scale, about every 1–2 s, so a burst on `chatbot-high` takes it from 4 to 36 (and the supervisor to 40) in about 7–14 s. Chatblast and the others are unchanged.
- `config/services.php`: `services.chatbot.ai_concurrency` default 100 (`CHATBOT_AI_CONCURRENCY` overrides); `ChatbotAiPool::max()` fallback matches. The 429 backoff is unchanged: it halves the limit, pauses new sends until Retry-After, and grows back by one per answer.
- Bottleneck fix: on every chat, `ChatbotPage::botfields` called `ChatbotFields::columns()`, which runs `SELECT DISTINCT key FROM page_settings WHERE provider='chatbot' AND active=1`. That scans every page's chatbot settings and grows with pages and setting history. It also ran a `hasTable` check and an `app_settings` read. A run now uses `ChatbotFields::labels()`, the same key => label map cached 60 s (`chatbot-fields:labels`). "+ Column" (`add()`) clears it after the commit. Every other staff write path checks keys against `columns()`, so `add()` is the only way a new key appears. The admin screens still call `columns()` uncached.
- `.env.example`: `HORIZON_CHATBOT_MAX=40`, `CHATBOT_AI_CONCURRENCY=100`.
- No customer-visible change. The per-customer lock (`chatbot-run:<page>:<psid>`) and the order per customer are untouched.

## Case table
| Case | Test | Result |
|---|---|---|
| S-161.1 | `ChatbotThroughputTest::test_S_161_1_the_chatbot_supervisor_starts_at_4_per_queue_and_grows_fast_to_40` (also `ChatbotPriorityTest::test_S_108_2_…`, now asserting 40) | pass |
| S-161.2 | `ChatbotThroughputTest::test_S_161_2_the_ai_pool_sends_up_to_100_at_a_time_unless_env_says_otherwise`; `ChatbotAsyncAiTest::test_S_161_2_at_the_default_of_100_a_429_still_halves_the_pool` | pass |
| S-161.3 | `ChatbotThroughputTest::test_S_161_3_a_run_reads_the_botfield_labels_without_scanning_every_pages_settings` | pass |
| S-161.4 | the full done-when list (below) | pass, no new failures |
| S-161.5 (P2) | local benchmark (below) | done |

Red run before the change:
- `.1`: "Failed asserting that two arrays are identical" (2, 16, 2, 3 instead of 4, 40, 5, 1).
- `.2` config: "Failed asserting that 50 is identical to 100."
- `.3`: "Failed asserting that 2 is identical to 0." (two DISTINCT scans).

The 429-at-100 test passed from the start: it confirms existing behaviour, as the spec asks.

## Suite
`php vendor/phpunit/phpunit/phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode tests/Feature/MetaAds`
- Baseline c42a2cf9: Tests 1739, Assertions 10162, Failures 0, Skipped 1.
- This branch (after `ffd3d3a3`): Tests 1743, Assertions 10174, Failures 0, Skipped 1. After the review-minor commit, `ChatbotThroughputTest` was re-run: 3 tests, OK.

## Local benchmark (S-161.5)
This was a throwaway PHPUnit spike, not committed. One process stood in for one worker, on sqlite in memory. It pushed 1,000 synthetic gateway `message` events from 500 customers, each with 20 old chats, through `HandleGatewayEvent` → `ChatbotInbound` → `RunChatbotFlow` (sync) → an AI node (faked OpenAI, waiting path) → a Forbeast send (faked). All 1,000 replies were recorded `sent`, and no customer was locked out. "Other pages" means 300 extra pages × 50 chatbot keys × (1 active + 2 old versions) = 45,000 `page_settings` rows.

| | other pages | chats/s per worker | p50 | p95 | queries/chat |
|---|---|---|---|---|---|
| before | 0 | 199.5 | 4.2 ms | 9.9 ms | 27.5 |
| before | 300 | 149.3 | 5.9 ms | 11.4 ms | 27.5 |
| after | 0 | 216.8 | 4.2 ms | 8.2 ms | 24.5 |
| after | 300 | 218.1 | 4.0 ms | 8.6 ms | 24.5 |

Before the fix, at 300 pages, the DISTINCT query alone took 1.93 s of DB time per 1,000 chats, more than every other query put together. After the fix, the cost per chat no longer depends on how many pages exist. On MySQL, absolute numbers will differ; the scan grows with the table there too (index `(provider, key, active)` makes it a range read over all chatbot rows, active or not).

Checked and left alone (no shared-row writes or global locks on the per-chat path):
- **Counters:** the gateway counters are `Cache::increment`, an atomic INCR in Redis.
- **Locks:** the only lock is per customer. The AI provider slots apply only to a capped provider (Local), never to OpenAI.
- **Gateway route:** `/api/gateway/events` has no throttle.
- **Per-chat writes:** an insert into `botcake_chats`, `chatbot_events` and `chatbot_replies`, plus an upsert into `chatbot_customer_values`, all per customer row.
- **Page settings and flows:** page settings are cached per page (Cache plus an in-process memo), and the flow is one `find` by id.
- **Smaller costs (in TODO.md):** `Schema::hasTable('chatbot_flows')` 2×/chat, `ChatbotAiQueue::recorded()` (`hasColumn`) 1× per AI call, `botcake_chats` by id 3–4×/chat. These took about 0.05 ms each on sqlite. On MySQL `hasColumn` reads information_schema, so it is worth memoising if MySQL shows load there.

## Review
`skeptic-reviewer` (standard depth) found no blockers and no majors. It checked the ramp against Horizon's `AutoScaler`: 4 → 36 per pool in 7 steps, about 7–14 s.
- **Fixed:** the docblock no longer cites the spec id, the config-arithmetic "≤30 s" assertion is dropped, the horizon comment says ~7–14 s, and the cache comment now states the race window.
- **Accepted in `TODO.md`:** the 60 s label race right after `add()`, a restored key's derived label being missing for up to 60 s, and the second halving test.
- **Not judged, out of scope:** whether 40 workers × 256 MB and 100 concurrent OpenAI calls (the rate limit is shared with Astra) suit the 4 vCPU server and MySQL `max_connections`.

## Story changes
None. `qa/stories.md` is not touched; the brief's cases are used as given.

## Deploy notes
1. **Check the production env first.** The old `.env.example` set `HORIZON_CHATBOT_MAX=16` and `CHATBOT_AI_CONCURRENCY=50` explicitly. If production's env has those lines, they override the new defaults and nothing changes. Remove both lines, or set them to 40 and 100.
2. Check MySQL `max_connections`. Up to 40 chatbot workers, plus chatblast's 8, plus the pools, the web and the database workers, each hold one connection. Check RAM too: the cap is 256 MB per worker, and a typical worker uses well under that.
3. There is no migration. `php artisan config:cache` if production caches config.
4. `php artisan horizon:terminate`; supervisor restarts Horizon with the new supervisor settings.
5. Restart `chatbot:ai-pool` (supervisor program `likha-chatbot-ai`) so it reads the concurrency of 100.
6. Watch for OpenAI 429s in the pool log. The backoff halves the concurrency on its own; lower `CHATBOT_AI_CONCURRENCY` if 429s keep coming. Astra shares the rate limit.

161: done — chatbot Horizon min 4/max 40 with fast ramp, AI pool 100, and the per-chat page_settings DISTINCT scan replaced by a 60 s label cache (149 → 218 chats/s per worker at 300 pages locally).
