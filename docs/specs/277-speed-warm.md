# 277 /chatbot/speed: warm that works, Sequences that finish, a page that never waits

## Why (measured on the live site today)
`/chatbot/speed` (`App\Services\Chatbot\ResponseSpeed`, `App\Console\Commands\ChatbotSpeedWarm`, `ChatbotSpeedController`, schedule in `routes/console.php` `chatbot:speed-warm` every two minutes withoutOverlapping) opens in 14 s on average (max 51 s); `/chatbot/speed/sequences` takes 18–21 s and then shows "too large". Every warm run takes about 340 s and logs `too large <yesterday> sequences, <today> sequences`.

Causes found:
1. **The warm stamps never match.** The cache store is Redis. Laravel's Redis store saves numbers raw and returns them as numeric strings (`'1794733156'`, type string, read on the live server). `warmDefault()` and `warm()` check `is_int($at)`, so the stamp is always "missing": yesterday's page and both days' Sequences are rebuilt on every run instead of every 20 / 30 minutes.
2. **Sequences runs out of memory in the warm.** The warm sets `memory_limit` to 512M when unlimited; the Sequences budget is 3/4 of it (384M). Measured peak: 386M (yesterday) and 417M (today) → OverflowException → `too_large`, so its whole read is wasted. The server has ~10 GB RAM available.
3. **Sequences is slow:** a full day took 160–200 s, almost all of it SQL (242–270 queries, 150–183 s), mostly `stepHistory()` (reads `chatbot_events` type sequence with `whereIn page_id` (~40 pages) × `whereIn psid` (chunks of 500) × `created_at < until`, all history of those customers). Indexes on `chatbot_events`: (page_id, psid, created_at), (created_at), (type, created_at). The page part (build()) is fine: 8–12 s.
4. **The web builds when the cache runs out.** Today's default entry lives 120 s + 180 s grace, but a warm run takes longer than that, so viewers hit a missing entry and wait for the build; the Sequences endpoint then spends its 20 s web budget and returns too_large.

## What to build
1. Stamps: accept numeric strings (and ints) everywhere a warm stamp is read. Test with a stamp stored as a numeric string.
2. Memory: the warm run uses `memory_limit` 1024M (when the CLI limit is lower or unlimited); budget stays 3/4 of the limit. Keep the web budget as is.
3. Sequences faster, same numbers: cut the `stepHistory()` cost (and anything else you find in `sequences()`) without changing any output. Ideas, your call: read only what `originalDue()` needs (it only uses the last event before each step's run, so the history before the customer's first run of the day needs only its last event: bound or collapse that read); avoid scanning other event types by reading on an index that covers it; reduce memory of `$before`/`$sends`. **No migrations or new indexes in this spec**; if you conclude only an index would help, stop that part and report. Prove equality: a test that builds Sequences with the old algorithm (copy it into the test as a reference) and the new one over seeded random data (several seeds, include customers with history before the day, multiple sequences, retries, skipped/failed, late steps after midnight) and asserts identical output, like `tests/Feature/Chatblast/ChatbotTagsBackfillTest.php` does for another job. Also report query count and peak memory for a sizeable seeded day, before vs after.
4. Final days are built once: a past day is final once its reply window has passed (day end + `MAX_WAIT_SECONDS`, and for Sequences day end + `STEP_LATE_SECONDS`). A final day's default entries (page and Sequences) are kept for 7 days and the warm does not rebuild them while a complete copy exists. Not-final yesterday keeps today's design (page every `YESTERDAY_WARM_SECONDS`, Sequences every `SEQUENCES_WARM_SECONDS`).
5. The page never waits on the default view (no page, no bot filter): warmed entries live long enough to outlast a slow run (e.g. 15 min for today's page, the Sequences warm period plus grace for Sequences), and each entry carries `built_at`; the page and the Sequences section show "as of HH:MM" (Manila time like the rest of the page). The warm still rebuilds today's page entry every run, so it is at most a few minutes old. If a default Sequences entry is missing, the `/speed/sequences` endpoint returns a quick "building" state (shown as "Binubuo pa ang Sequences; lalabas sa loob ng ilang minuto") instead of computing for 20 s, and the warm builds it on its next run. Filtered views (a page or a bot) keep computing on request as today.
6. The warm log line stays one line per run; add each part's seconds so slow parts show.

## Done when
- Tests (next to the existing ResponseSpeed / speed warm tests; find them with `grep -rl "ResponseSpeed\|speed-warm\|SpeedWarm" tests`) cover: numeric-string stamp respected (no rebuild within the period); warm memory limit raised; final day not rebuilt by the warm while its copy exists; not-final yesterday rebuilt on schedule; default page served from cache without running the build (query log or a spy); `built_at` shown; default Sequences missing → endpoint answers "building" without building; filtered Sequences still computed; old-vs-new Sequences equality over random seeds.
- `vendor/bin/phpunit --filter "ResponseSpeed|Speed"` passes; the full suite adds no new failures versus the base (base had 3 known failures: ExampleTest root response, ItemTableFitScriptTest min width, SharedPagesBasePinTest S_42_4); report before/after counts.

## Out of scope
Chatbot Results, the replied backfill, other pages, migrations/indexes, schedules other than the speed warm, visual redesign.

## Allowed
Local commands in your worktree only: composer, phpunit, artisan, git add/commit on `fix/277-speed-warm`. No ssh, push, gh, deploy, or `.env`. Budget: large job; at most 2 attempts at a failing step, then stop and report what you tried.

## Report back
Commit hashes, test results (filter and full suite before/after), the Sequences query count and peak memory before/after on your seeded day, and anything unsure.
