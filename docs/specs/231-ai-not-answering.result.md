# Result 231: "AI not answering" in the problems banner

## Status

Done, not pushed, not deployed. No migration. Branch `feat/231-ai-not-answering` from `b26149a5`, built after the
reviewer's "go" (site line under "All pages" with its own Acknowledge; up to 3 chats as Inbox links). One review
(skeptic-reviewer, standard): no blocker, no major; 5 minors accepted in `TODO.md`.

Merge danger: low, a two-way door. Blast radius: the problems banner on every Chatbot/Chatblast screen (one more
cached source, wrapped in `safely()` like the others) and the ack route (one more accepted page name, "All pages").
Nothing changes how the AI step or any send behaves. Revert: revert the commit; acks saved under "All pages" stay as
inert page settings rows.

## Plan

### What the code already has (read before the plan)

- **The AI queue** (`chatbot_ai_requests`, `ChatbotAiQueue`): one row per AI call. Async rows go
  `pending → sending → answered | failed → resumed`; the waiting path (Test tab, sequences, no live pool) writes one
  `status = 'sync'` record row. A failure keeps its `error` through `resumed`. "Empty answer" is not a separate
  state: `ChatbotAi` / the chain turn it into `error = 'OpenAI gave an empty answer.'`, and the sweep writes
  `'OpenAI did not answer.'` (or the provider's own "did not answer") at the deadline. `answer` is never stored on
  sync rows, so "empty answer" can't be read from `answer IS NULL`; the check reads `error` only.
- The same table also holds old Encode order waits (`model = FlowEncode::MODEL`, status `encoding`), which are not
  AI; `BotHealth` already excludes them the same way.
- **Indexes already there:** `created_at`, `(status, next_at)`, `(page_name, created_at)`, `(page_id, psid)`. So
  decision 8 needs **no migration**: the window query is a range on `created_at`, the stuck query a lookup on
  `status IN ('pending','sending')` (a few rows at most, since the sweep fails them at the deadline).
- **The flow version that ran:** the AI row has no `flow_version_id` (only `flow_id`, `node_id`, `run_id`).
  `chatbot_replies.flow_version_id` exists only when that run already sent something, and is null on Test runs.
  What every row has is its `created_at`, and Publish writes the live graph and a `chatbot_flow_versions` row
  (`flow_id`, `graph`, `created_at`) in one transaction, so the version that ran is **the newest version of the
  flow created at or before the AI row's `created_at`** (see Rulings).
- **Banner machinery:** `ProblemsPanel::build()` collects lines from sources, each in `safely()`; the slow ones sit
  in the 60 s `Cache::remember`. `line(page, level, text, links, since, window)` → `ProblemAcks::kind()` (text
  without digits) → Acknowledge / Done. Inbox chat links are `route('chatbot.inbox', ['page_id', 'psid'], false)`
  like `ChatEchoes`; the flow link is `/chatbot/flows/{id}` (the editor has no deep link to a step).

### Design

One new source `ai()` in `ProblemsPanel`, inside the cached `own` array, wrapped in `safely()` (decision 7). It runs
at most these queries, none per page:

1. **Window counts** (one grouped query): `created_at >= now − ai_fail_minutes`, `model <> FlowEncode::MODEL`,
   `GROUP BY page_id, error` → `COUNT(*)`, `SUM(created_at >= now − ai_site_minutes)`, `MAX(created_at)`. Gives per
   page: AI requests asked, failures, failures per error (the most common one for the text), and the same for the
   10-minute site window. Failures = `error IS NOT NULL AND error <> ChatbotAiLoad::ERROR`. Load-skips are also left
   out of "asked" (that page didn't really ask AI).
2. **Stuck** (one grouped query): `status IN ('pending','sending') AND created_at <= now − ai_stuck_seconds`,
   `GROUP BY page_id` → `COUNT(*)`, `MIN(created_at)`.
3. **Failure rows for links and the port check** (one query, only when query 1 found failures): the failure rows of
   the window, `id DESC`, `LIMIT 500` → `page_id, psid, flow_id, node_id, created_at`. Up to 3 chats per page as
   Inbox links ("Chat 1"…), the most common flow/node as the "Flow" link.
4. **Port check** (only for pages with 1 to `ai_fail_count − 1` failures, and not when the site line is on):
   one query for the version ids/times of the involved flows (no graph column), one for the chosen versions'
   graphs (`whereIn id`), and one for `chatbot_flows` (id, graph, published_at) of flows with no version found.
   Graphs are decoded once per version; a port is wired when an edge `{from: node_id, port: 'failed'}` exists.

Test pages (`BotcakeMock::isTestId`) are skipped like in `slow()`/`quiet()`. Page name from `self::name()`.

Lines (texts as in the spec; the count in "N of M" uses query 1):

| Rule | Level | Text | Links | since / window |
|---|---|---|---|---|
| failures ≥ `ai_fail_count` in `ai_fail_minutes` | red | `AI not answering: 4 of 9 AI replies failed in 15 min (OpenAI gave an empty answer)` | Chat 1..3 (Inbox), Flow | window 15 |
| pending/sending older than `ai_stuck_seconds` | red | `AI stuck: 2 replies waiting over 2 min` | Inbox (page) | since = oldest stuck `created_at` |
| ≥ `ai_site_min_pages` pages failed in `ai_site_minutes` and > half of the pages that asked | red, one line | `AI is failing on 37 of 52 pages: OpenAI or the key may be down` | Health | window 10 |
| 1..(`ai_fail_count` − 1) failures with the failed port not wired | orange | `AI failed with no fallback: 1 customer got no reply in 15 min (OpenAI did not answer)` | Chat 1..2 (Inbox), Flow | window 15 |

- The site line replaces the per-page red failure lines and the orange lines; the stuck lines stay (decision 4
  names only 2 and 5).
- "1 customer" counts distinct `psid`s among the unwired failures; "customer/customers" singular/plural.
- The trailing "." of the error is dropped inside the brackets; the error is cut to 80 characters.
- **The site line's page:** the banner groups lines by page and Acknowledge needs a page. The line goes under a
  fixed name `ProblemsPanel::SITE = 'All pages'`, and `ChatbotProblemAckController` accepts that one name besides
  the chatbot list (its ack is stored as page settings of "All pages", like any page). This is the only change
  outside `ProblemsPanel` and config, and it is on an existing, validated route (no new input).
- Config `services.chatbot.problems` gets `ai_fail_count` (3), `ai_fail_minutes` (15), `ai_stuck_seconds` (120),
  `ai_site_minutes` (10), `ai_site_min_pages` (5) with env `CHATBOT_AI_FAIL_COUNT`, `CHATBOT_AI_FAIL_MINUTES`,
  `CHATBOT_AI_STUCK_SECONDS`, `CHATBOT_AI_SITE_MINUTES`, `CHATBOT_AI_SITE_MIN_PAGES`, also added to `.env.example`
  next to the existing `CHATBOT_SLOW_*` lines.

### Tasks

All tasks touch `ProblemsPanel.php`, so none is parallel-safe; they run in order in this worktree. Tests go in a new
`tests/Feature/Chatblast/AiProblemsTest.php` (same base class and banner-HTML helper as `ProblemsPanelTest`), one
test per case named `test_S_231_x_y_…`, written red first.

| # | Task | Side | Tier | Cases | Review |
|---|---|---|---|---|---|
| T1 | Stories into `qa/stories.md` (slice 231), config keys + `.env.example`, the window and stuck queries, the red per-page and stuck lines, the site-wide line | backend | medium | S-231.1.1–.5, S-231.2.1–.3, S-231.3.1–.3 | skeptic-reviewer, standard |
| T2 | The orange no-fallback line: failure rows, version lookup by time, port check, fallback to the live graph | backend | medium | S-231.4.1–.3 | skeptic-reviewer, standard (with T3) |
| T3 | Banner behaviour: Acknowledge 1 h / Done for an AI line, a database error inside `ai()` leaves the rest of the banner, the line markup wraps at phone width; the "All pages" ack in the controller | backend | medium | S-231.5.1–.4 | (with T2) |
| T4 | Measurement and result: seed 50,000 AI rows in a throwaway test, time a cold banner build, `EXPLAIN QUERY PLAN` of each query (sqlite), full suite vs. the baseline, case table, rulings | — | low | done-when items | main session runs lint and tests |

Developers: `backend-developer` (sonnet) per task; `skeptic-reviewer` (sonnet, standard). (As built: no
`backend-developer` agent was available in the session, see Summary.) The browser check
(S-231.5.4 at 390 × 844) is optional; the test checks the markup (`break-words`, no fixed width), like S-184.9.

## Summary

What shipped, per task (one `feat:` commit, `8cf12545`, test and code together):

- **T1 – the red lines and the site line.** `ProblemsPanel::ai()` is a new source in the cached part of the banner
  (60 s, `safely()`). One grouped query over the last `ai_fail_minutes` of `chatbot_ai_requests` (`GROUP BY page_id,
  error`, with a `SUM(CASE …)` for the `ai_site_minutes` window) gives each page's asked/failed counts and its most
  common error. A grouped query on `status IN (pending, sending)` gives the stuck lines. Lines:
  - red `AI not answering: 3 of 9 AI replies failed in 15 min (OpenAI gave an empty answer)` + Chat 1–3 + Flow;
  - red `AI stuck: 2 replies waiting over 2 min` + Inbox;
  - red, under "All pages": `AI is failing on 6 of 10 pages: OpenAI or the key may be down` + Health. It replaces the
    per-page failure and orange lines; the stuck lines stay.
  Config `services.chatbot.problems.ai_fail_count / ai_fail_minutes / ai_stuck_seconds / ai_site_minutes /
  ai_site_min_pages` (3 / 15 / 120 / 10 / 5), env `CHATBOT_AI_*`, also in `.env.example`.
- **T2 – the orange no-fallback line.** For pages with 1–2 failures, the failure rows (one query, newest first,
  at most 500) are checked against the flow version that ran (`aiWired()`: versions by time, then the live graph
  if published before the request, else "not wired"). Line: `AI failed with no fallback: 1 customer got no reply
  in 15 min (OpenAI gave an empty answer)` + Chat links + Flow. A failure whose failed port is wired shows nothing.
- **T3 – banner behaviour.** The lines use `line()` with `window` (failures, site) or `since` (stuck, the oldest
  waiting request), so Acknowledge and Done work as for the other lines. `ChatbotProblemAckController` accepts
  `ProblemsPanel::SITE` ("All pages") besides the chatbot pages, so the site line can be acknowledged too.
- **T4 – measurement, stories, result.** Slice 231 in `qa/stories.md` (18 cases, all passed), 50,000-row timing and
  query plans below, full suite, accepted minors in `TODO.md`.

How it was built: this session had no `backend-developer` agent type available, so the main session wrote the
tests and code itself (red first, see Checks), and the `skeptic-reviewer` (sonnet, standard) reviewed the diff.
T1–T3 touch the same file and went into one commit and one review instead of three. The browser check was skipped:
it needs a running app with seeded AI rows and a signed-in user. S-231.5.4 checks the markup instead.

Docs: no behaviour doc covers the banner beyond `qa/stories.md`, which has the slice.

## Checks (command and output)

Baseline, full suite on the clean tree at `b26149a5` (`php.bat artisan test`):

```
Tests:    3 failed, 4283 warnings, 206 passed (47406 assertions)
Duration: 1104.69s
FAILED  Tests\Feature\Chatblast\BigBroadcastTest > s 167 1 5000 messages on 50 pages all go out without one failed for waiting
FAILED  Tests\Feature\ExampleTest > the application returns a successful response
FAILED  Tests\Feature\Item\ItemTableFitScriptTest > every catalog column id has a minimum width and a place in the drop order
```

These 3 are pre-existing (red before any change of this spec).

**Red runs.** `php.bat artisan test tests/Feature/Chatblast/AiProblemsTest.php` before any production code: 13
failed because the lines were missing (e.g. S-231.1.1 `Failed asserting that two arrays are identical.` with an
empty list of AI lines; S-231.5.2 `Failed asserting that 422 is identical to 200.` because no line had a kind to
acknowledge). 5 passed at once: the negatives S-231.1.2, .1.3, .2.2, .4.2 and S-231.5.3, which check that nothing
shows (or nothing breaks), true of the old code too. With the code in place but the ack-controller change taken out,
the site-line Acknowledge failed: `Failed asserting that 422 is identical to 200.` (S-231.3.1 and .3.3).

**Banner tests after the change:**
`php.bat artisan test tests/Feature/Chatblast/AiProblemsTest.php tests/Feature/Chatblast/ProblemsPanelTest.php tests/Feature/Chatblast/PageProblemsTest.php`
→ `Tests: 43 warnings (217 assertions)`, no failures. (The "warnings" are tests that pass with a
`file_get_contents` warning, as in the baseline's 4,283.)

**Lint:** `php.bat -l` on `ProblemsPanel.php`, `ChatbotProblemAckController.php`, `config/services.php`,
`AiProblemsTest.php` → `No syntax errors detected` for each.

**Full suite after the change** (`php.bat artisan test`):

```
FAILED  Tests\Feature\ExampleTest > the application returns a successful response
FAILED  Tests\Feature\Item\ItemTableFitScriptTest > every catalog column id has a minimum width and a place in th…
Tests:    2 failed, 4302 warnings, 206 passed (77476 assertions)
Duration: 763.41s
```

No new failures: both are pre-existing. The third baseline failure (`BigBroadcastTest` S-167.1, 5,000 messages
timed) passed this time; it's timing-sensitive and untouched by this change. Totals: 4,492 tests on the baseline,
4,510 now, which is the 18 new ones.

**Measurement on 50,000 seeded AI rows** (throwaway test, sqlite in memory, deleted afterwards). 50,000 rows over the
last 24 hours on 60 pages (about 520 in the last 15 minutes), 1 in 2,500 failed, plus failures on 4 pages in the
window and 2 stuck requests so every branch runs. Lines produced: 2 stuck, 3 red "AI not answering: 3 of 12 …", and
1 orange "AI failed with no fallback: 1 customer …".

```
rows: 50010
ai() ms (5 runs): 21.8, 17.1, 17.3, 14.9, 15.5
cold panel() ms (5 runs, cache flushed each time): 41.1, 21.9, 18.3, 19.2, 20
```

So the whole banner builds in about 20 ms cold (41 ms on the first run), and the AI check takes about 15–22 ms of
that. Warm, the 60 s cache serves it.

**Query plans** (sqlite `EXPLAIN QUERY PLAN`):

```
window counts (no statistics yet): SCAN chatbot_ai_requests USING INDEX chatbot_ai_requests_page_id_psid_index | USE TEMP B-TREE FOR GROUP BY
window counts (after ANALYZE):     SEARCH chatbot_ai_requests USING INDEX chatbot_ai_requests_created_at_index (created_at>?) | USE TEMP B-TREE FOR GROUP BY
stuck:                             SEARCH chatbot_ai_requests USING INDEX chatbot_ai_requests_status_next_at_index (status=?) | USE TEMP B-TREE FOR GROUP BY
failure rows:                      SEARCH chatbot_ai_requests USING INDEX chatbot_ai_requests_created_at_index (created_at>?)
```

The window query uses the `created_at` index once the table has statistics, which a fresh sqlite table in a test
lacks and MySQL InnoDB always has (see Rulings and Open questions). The version and flow lookups are `whereIn` on
primary keys and `(flow_id, id)`, and run only when a page has 1–2 failures.

### Case table

| Case | Test | Result |
|---|---|---|
| S-231.1.1 | `test_S_231_1_1_three_failures_in_15_min_make_one_red_line_with_count_error_and_links` | pass |
| S-231.1.2 | `test_S_231_1_2_two_failures_make_no_red_line` | pass |
| S-231.1.3 | `test_S_231_1_3_three_failures_over_20_min_with_two_in_the_window_make_no_red_line` | pass |
| S-231.1.4 | `test_S_231_1_4_failures_on_two_pages_make_one_line_each` | pass |
| S-231.1.5 | `test_S_231_1_5_empty_and_did_not_answer_count_and_no_ai_load_does_not` | pass |
| S-231.2.1 | `test_S_231_2_1_a_request_waiting_over_2_min_is_red_ai_stuck` | pass |
| S-231.2.2 | `test_S_231_2_2_a_request_waiting_90_seconds_shows_nothing` | pass |
| S-231.2.3 | `test_S_231_2_3_once_answered_the_stuck_line_is_gone_on_the_next_refresh` | pass |
| S-231.3.1 | `test_S_231_3_site_wide_line_by_pages_failing` data set "S-231.3.1 failures on 6 of 10 pages: one site line" | pass |
| S-231.3.2 | `test_S_231_3_site_wide_line_by_pages_failing` data set "S-231.3.2 failures on 3 of 10 pages: per-page lines" | pass |
| S-231.3.3 | `test_S_231_3_site_wide_line_by_pages_failing` data set "S-231.3.3 failures on 5 of 9 pages: one site line" | pass |
| S-231.4.1 | `test_S_231_4_1_one_failure_with_the_failed_port_not_wired_is_orange` | pass |
| S-231.4.2 | `test_S_231_4_2_one_failure_with_the_failed_port_wired_shows_nothing` | pass |
| S-231.4.3 | `test_S_231_4_3_the_check_uses_the_flow_version_that_ran` | pass |
| S-231.5.1 | `test_S_231_5_1_acknowledge_for_an_hour_hides_the_ai_line_and_it_comes_back_after` | pass |
| S-231.5.2 | `test_S_231_5_2_done_hides_the_ai_line_until_a_new_failure` | pass |
| S-231.5.3 | `test_S_231_5_3_a_database_error_in_the_ai_check_leaves_the_rest_of_the_banner` | pass |
| S-231.5.4 | `test_S_231_5_4_the_ai_lines_wrap_in_the_banner_list` (markup; no browser at 390 × 844) | pass |

All 18 are listed as passed in the full-suite run above (`! s 231 …`, passed with the environment's warning).

### Done when

- [x] Cases S-231.1 to S-231.5 pass, each with a test named after it (case table).
- [x] The full suite adds no new failures compared with b26149a5 (2 failed, both pre-existing).
- [x] `qa/stories.md` has slice 231 (18 cases, all "2026-11-05 passed").
- [x] The result is filled in, with the query plans and the banner build time on 50,000 seeded AI rows (above).

## Rulings

Given with the "go": the site line sits under "All pages" and can be acknowledged; up to 3 affected chats as Inbox
links; no migration; the version found by `created_at`; stuck lines kept under the site line. Proposed with the
plan and accepted:

- Ruling: a failure is `error IS NOT NULL` and not `ChatbotAiLoad::ERROR`; the empty answer is detected by its
  error text, not by `answer` — `answer` is never stored on sync rows and is nulled after 2 days, while every
  empty answer already gets `error = 'OpenAI gave an empty answer.'` — cost if wrong: a provider path that stores
  `''` with no error would be missed (none found in the code).
- Ruling: sync rows count (sequences and pages with no live pool reach real customers); Test-tab runs also write
  sync rows and can't be told apart from the row — cost if wrong: staff testing a broken AI step on a live page can
  add a failure to that page's count for 15 minutes.
- Ruling: Encode order waits (`model = FlowEncode::MODEL`) are not AI and are left out, as in `BotHealth` — cost if
  wrong: none expected; they are old rows the sweep finishes.
- Ruling: the red failure line (decision 2) counts every failure, wired or not; decision 6 (wired = not shown) is
  applied to the orange 1–2 failure case — three failures in 15 minutes mean the AI itself is failing even when a
  fallback hides it from customers — cost if wrong: a red line on a page whose fallback covers every customer;
  Acknowledge clears it.
- Ruling: the flow version that ran = the newest `chatbot_flow_versions` row of the flow with `created_at` at or
  before the AI row's `created_at`; when there is none (flow published before versions existed, or versions
  pruned past the last 50), the live `chatbot_flows.graph` is used when its `published_at` is at or before the AI
  row; only when neither is found is the port treated as not wired — a time lookup works for every row, while
  `chatbot_replies.flow_version_id` exists only when the run sent something before the AI step — cost if wrong: a
  Publish within the same second as the AI call could pick the other version.
- Ruling: the stuck rule reads only `pending`/`sending` (decision 3: "not answered, not failed"); an answered row
  whose resume job is lost is the sweep's job (redispatch after 30 s) — cost if wrong: a dead queue worker with a
  live pool would not show here (the "not answered" echo line still catches it).
- Ruling: the site line lives under the name "All pages", and the ack controller accepts that one name — the
  banner and Acknowledge need a page — cost if wrong: an "All pages" heading in the banner list and page settings
  rows under that name.
- Ruling: the "Flow" link opens the flow editor (`/chatbot/flows/{id}`) without selecting the step — the editor
  has no step deep link, and adding one is UI work outside this spec — cost if wrong: one extra click to find the
  AI step.
- Ruling: no migration — `chatbot_ai_requests` already has an index on `created_at` (and on `status, next_at` for
  the stuck query) — cost if wrong: none; the measured plan goes in Checks.

Made while building:

- Ruling: the grouped window query stays as decision 8 says, without an index hint — with table statistics sqlite
  plans it on `chatbot_ai_requests_created_at_index`, and MySQL InnoDB always keeps statistics; the
  `(page_id, psid)` index can't serve `GROUP BY page_id, error`, so a full index scan loses to a range of a few
  hundred rows — cost if wrong: a full scan of the table once a minute (the banner cache); see Open questions for the
  one-line check on production.
- Ruling: "asked" (the M in "N of M", and the pages that asked AI for the site rule) leaves out "No AI load" skips —
  a skipped step never called the AI, so counting it would dilute the share — cost if wrong: none for the red rule
  (it counts failures, not a share).
- Ruling: the site window is capped at the page window (`min(ai_site_minutes, ai_fail_minutes)`) — one grouped query
  counts both — cost if wrong: a config with a site window longer than 15 minutes is read as 15.
- Ruling: the orange line names the most common error among the customers with no reply (newest on a tie), the red
  line the most common error of the page — the orange line is about those customers — cost if wrong: none.
- Ruling: the 50,000-row measurement ran as a throwaway test and was deleted, not committed — it takes seconds of
  seeding and proves timing, not behaviour — cost if wrong: the timing is not re-checked automatically.

## Story changes

- The slice header line in `qa/stories.md` names the threat model, the tier and the test file, like the other
  slices. S-231.3.1–.3 are one table test (`#[DataProvider]` with one data set per case ID) rather than three tests,
  following the test rules (variants in a table). No case's Given/When/Then was changed.

## Open questions

Resolved with the "go": the "All pages" ack (built) and up to 3 chats as links (built).

1. For whoever deploys: a read-only check on production that the window query uses the `created_at` index:
   `EXPLAIN SELECT page_id, error, COUNT(*) AS n, SUM(CASE WHEN created_at >= NOW() - INTERVAL 10 MINUTE THEN 1 ELSE 0 END) AS recent FROM chatbot_ai_requests WHERE created_at >= NOW() - INTERVAL 15 MINUTE AND model <> 'order-encode' GROUP BY page_id, error;`
   should show `key: chatbot_ai_requests_created_at_index`, `type: range`. I could only check sqlite.

## Proposed tasks

- A step deep link in the flow editor (`/chatbot/flows/{id}?node=n5` selects and centres the step), so the banner's
  "Flow" link lands on the AI step. UI task, not in this spec.
- A stable Acknowledge/Done key for all banner lines (no plural or error text in the hashed part), so a line does
  not come back as "new" when "1 customer" becomes "2 customers" (accepted minor, `TODO.md`).
- A per-page bound for the orange candidates' rows instead of the site-wide 500 (accepted minor, `TODO.md`).
