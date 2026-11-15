# 278 Chatbot Results: built in the background, fewer scans

## Why (measured on the live site today)
`/chatbot/results` (`App\Services\Chatbot\ChatbotResults`, `ChatbotResultsController` index and json, view `resources/views/chatbot/results.blade.php`, also the artisan command `chatbot:results`) answers 504 Gateway Time-out: `report()` builds the whole report inside the web request (`Cache::remember` 300 s), and on the larger site a 7-day default build takes over 2 minutes (nginx gives up at 60 s, PHP at 30 s). Per section, 7 days, all pages: orders 0.2 s; deliveries 2.3 s; chats 3.6 s; funnel 17.9 s (3 queries, slowest the COUNT DISTINCT psid grouped by page_id, 13.8 s); by_via 58.6 s (8 queries: the funnel repeated per bot); by_message over 20 s per query (the chatbot_reply_tags join); by_step 8.5 s; by_ad over 20 s (COUNT DISTINCT psid by ad_id); by_page (new) about one more funnel-sized query. The same 7-day sends are scanned again and again.

## What to build
1. **Never build in the web request.** Results are built by a new console command `chatbot:results-warm`, scheduled `everyMinute()->withoutOverlapping(30)->onOneServer()` in `routes/console.php`. Each stored copy carries `built_at`. The page and `results.json` read the stored copy for the requested filters (including the compare pick, i.e. whatever `output()` covers):
   - copy present → show it, with "as of HH:MM (Manila)" near the date filter, even if it is older than its refresh period;
   - no copy → add the filters to a "wanted" list in the cache and answer at once: the page shows "Binubuo pa ang report (mga 1–3 minuto). Kusa itong magre-reload." and reloads itself every 20 s until the copy exists; `results.json` answers 202 `{status: "building"}`.
2. **What the warm builds, per run:** the default view (`filters([])`, last 7 days, no filters) when its copy is older than 15 minutes or missing; then wanted filters, oldest first, at most 3 per run; a wanted entry older than 30 minutes is dropped. Copies: default kept 6 hours, others 1 hour (so a viewer always gets the last copy). One log line per run with what it built and seconds per build. A failing build is reported and does not stop the others.
3. **Fewer scans, same numbers:** funnel, by_via and by_page read the same sends; get sent, customers (distinct page+psid) and replied for all three from ONE grouped read (grouped by page_id and the via bucket, as `whereVia()` defines it), and the chats count likewise from one grouped read. by_message: keep its numbers but avoid running the tags join twice where you can. Prove equality: tests build the report with the old code paths (keep a reference copy in the test) and the new, on seeded data with several pages, both vias (including old rows without a via tag), flows, versions and orders, and assert identical output.
4. `chatbot:results` (console) keeps building directly as today; the cache key version moves to v3.

## Done when
- Tests (next to the existing Chatbot Results tests; `grep -rl ChatbotResults tests`) cover: page and json with no copy → building state, filters added to wanted, and no report queries run in the request (query log); with a copy → served with as-of, no build; warm builds default when stale, skips when fresh, builds wanted (max 3, oldest first), drops wanted older than 30 min, continues after one build fails; schedule registered; old-vs-new equality for funnel, by_via, by_page and chats; the query count of a build goes down (report before/after).
- `vendor/bin/phpunit --filter ChatbotResults` passes; the full suite adds no new failures versus the base (base had 3 known failures: ExampleTest root response, ItemTableFitScriptTest min width, SharedPagesBasePinTest S_42_4); report before/after counts.

## Out of scope
/chatbot/speed and its warm, the replied backfill, other pages, migrations, new tables or indexes, queue jobs (do not use the queue: it carries the live chatbot work), visual redesign.

## Allowed
Local commands in your worktree only: composer, phpunit, artisan, git add/commit on `fix/278-results-background`. No ssh, push, gh, deploy, or `.env`. Budget: large job; at most 2 attempts at a failing step, then stop and report what you tried.

## Report back
Commit hashes, test results (filter and full suite before/after), build query count before/after, and anything unsure.
