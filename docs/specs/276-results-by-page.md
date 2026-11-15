# 276 Chatbot Results: By page

## Why
`/chatbot/results` (`App\Services\Chatbot\ChatbotResults`, view `resources/views/chatbot/results.blade.php`, controller `ChatbotResultsController`, also `results.json`) already shows Funnel, By bot, By message, By sequence step, By ad and Compare, for one page at a time (page filter) or all pages added together. The owner wants all pages side by side: per page, the response rate and the order rate, and how many orders came from the main flow versus after a sequence step.

## What to build
A new section **By page**, placed right after By bot, in the HTML page and in `results.json` (key `by_page`), built in `build()` like the others and cached with the report.

One row per page that had bot sends or orders in the range (respect the date range and the `via`, `flow_id`, `version_id` filters exactly as Funnel does; when `page_id` is set, just that page's row). Columns, using the same definitions as `metrics()` so the numbers add up with Funnel:
| Column | Meaning |
|---|---|
| Page | page name from `BotcakeChat::pageMap()` (fallback the id); link that sets `page_id` to this page keeping the other filters, so By sequence step then shows that page's steps |
| Sent | bot sends (`kind` send, `status` sent) in range |
| Customers | distinct psid sent to |
| Replied % | replied ÷ sent (as Funnel "Replied") |
| Orders | order events in range for the page (as Funnel) |
| Order rate | orders ÷ customers (as Funnel "Orders") |
| Main flow | orders whose `detail` has no `sequence_id`/`sequence_step`, count and % of the page's orders |
| After sequence | the rest, count and % of the page's orders |
| Delivered % | delivered ÷ orders (as Funnel) |

Sort by Orders, highest first; a totals row at the bottom that equals Funnel's numbers for the same filters. Sortable columns are nice but not required.

Performance (the live tables are large: millions of `chatbot_replies` rows a week): the sends part must be ONE grouped query by `page_id` over the same `sends()` builder (COUNT, COUNT DISTINCT psid, SUM of replied_at not null), never a query per page. Orders come from the `$orders` collection already loaded in `build()` (group in PHP). Use `$delivery` already computed. No new tables, migrations or indexes; plain SELECTs only.

## Done when
- Feature tests (next to the existing Chatbot Results tests; find them with `grep -rl ChatbotResults tests`) cover: two pages with different sends, replies and orders give the right per-page numbers; main flow vs after-sequence split; the totals row equals Funnel; `page_id` filter gives one row; the `via` filter applies; `results.json` has `by_page`; the page renders the section with the page link.
- `vendor/bin/phpunit --filter ChatbotResults` passes; the full suite adds no new failures versus the base (base had 3 known failures: ExampleTest root response, ItemTableFitScriptTest min width, SharedPagesBasePinTest S_42_4); report before/after counts.
- Query count: the By page section adds at most 2 queries to the report (assert it in a test with `DB::enableQueryLog` or report the count).

## Out of scope
Any other section, the backfill, schedules, styling changes beyond matching the existing tables.

## Allowed
Local commands in your worktree only: composer, phpunit, artisan, git add/commit on `feat/276-results-by-page`. No ssh, push, gh, deploy, or `.env`. Budget: medium; at most 2 attempts at a failing step, then stop and report.

## Report back
Commit hashes, test results (filter and full suite before/after), the added query count, and anything unsure.
