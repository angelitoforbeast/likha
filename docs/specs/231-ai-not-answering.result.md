# Result 231: "AI not answering" in the problems banner

## Status

Plan written, waiting for the reviewer's "go". No code yet. Branch `feat/231-ai-not-answering` from `b26149a5`.

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

Developers: `backend-developer` (sonnet) per task; `skeptic-reviewer` (sonnet, standard). The browser check
(S-231.5.4 at 390 × 844) is optional; the test checks the markup (`break-words`, no fixed width), like S-184.9.

## Summary

(filled in when done)

## Checks (command and output)

Baseline: full suite on the clean tree at `b26149a5`, running while this plan was written; its failures will be
listed here as pre-existing.

## Rulings

Proposed with the plan (the reviewer may change them with the "go"):

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

## Story changes

None yet. The spec's cases go into `qa/stories.md` as given.

## Open questions

1. The site-wide line needs a page to sit under for Acknowledge/Done. The plan uses a fixed "All pages" entry and
   lets the ack controller accept that one name. Alternative: the site line cannot be acknowledged (no buttons).
   Which one?
2. Should the Inbox links be up to 3 affected chats ("Chat 1"…"Chat 3", like the double-reply examples) or one link
   to the page's Inbox? The plan uses up to 3 chats.

## Proposed tasks

- A step deep link in the flow editor (`/chatbot/flows/{id}?node=n5` selects and centres the step), so the banner's
  "Flow" link lands on the AI step. UI task, not in this spec.
