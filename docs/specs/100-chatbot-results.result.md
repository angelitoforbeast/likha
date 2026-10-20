# Result 100: chatbot results (tags, replied, orders, delivered, Results page)

Branch `feat/100-chatbot-results`, cut from `main` at 8381e31c; `main` (099, 101) was merged in at the end. Not pushed.

## Commits

| Commit | What |
|---|---|
| c193db20 | docs: spec, plan and stories S-100 |
| cb2a4587 | feat: tags on every send path, botfield and flow versions, `replied_at` job and hourly backfill, "Counts as an order" with the order event, the migration |
| 53b1d0ec | feat: `/chatbot/results`, `/chatbot/results.json`, `chatbot:results`, the Results tab |
| 8429b73c | fix: review fixes (version id after re-publishing an earlier graph, order hook guarded, manual sends out of By message, blank J&T status, 92-day sequence step, `ready()` re-check per minute) |
| d82695c8 | chore: merge `main` (099, 101); conflicts in `editor.blade.php` and `qa/stories.md` resolved |
| (this commit) | docs: result, stories run, TODO, screenshots |

## What shipped, per task

1. **Tagging (all four paths).** Each `chatbot_replies` row now carries `flow_version_id`, `sequence_id`, `sequence_step`, `ad_id` and `tags` (JSON: per message, the botfields with their `page_settings` id and the customer fields its template used, plus `flow_hash`). One row per message and botfield goes to the new `chatbot_reply_tags` table, in one insert per send. There is one tagging call per send: `FlowEngine` sets `$run->tags`, and `BotcakeOutbox::record()` gets one optional `$tags` argument. The pool ticket carries `tags` beside `messages`, and the Inbox send merges `ChatbotTags::manual()`. The botfield ids come from the read `ChatbotPage::botfields()` already does, so there is no new query per message. The flow version and the ad are cached (a cache read per send). The flow version is the newest `chatbot_flow_versions` id; Publish clears the cache key of the graph it publishes. A flow with no version row gets a null id and the hash.
2. **Replied.** A chat arriving at `/api/botcake/chat` queues `MarkChatbotReplied` (queue `chatbot`). Every still-unreplied bot or manual send to that customer on that page from the 24 h before the chat gets `replied_at` and `reply_seconds`. `chatbot:replied-backfill` runs hourly (at :07) over the last 48 h.
3. **Orders.** Send, Set field and Sheet have a "Counts as an order" option (stored only when ticked), shown in the node form with a help line. When a live run passes a ticked node (not on `failed`, and after the pool has sent it), one `chatbot_events` row of type `order` is written. It holds the run, flow version and hash, the last 5 send ids, the sequence step of the latest sequence send in the last 92 days, and the ad. There is one per customer per 24 h. The Test tab writes none. The 30-day prune keeps orders.
4. **Report** (`ChatbotResults`). It gives the funnel, By message, By sequence step, By ad and a compare of two flow versions or two botfield versions. Delivered is computed at report time: the closest `macro_output` row with the same page and PSID within −1…+3 days, and its waybill's furthest J&T row, ranked the Chatblast way. The outcomes are delivered, RTS, in transit, other or unmatched. Results are cached 5 minutes.
5. **Access and outputs.** `/chatbot/results` and `/chatbot/results.json` sit in the `/chatbot` group (auth, allowed_ip) behind `NavAccess` on `/chatblast`, are throttled, and appear as a Results tab. `chatbot:results --page= --from= --to= [--flow=] [--flow-version=] [--compare= --a= --b=] --json` prints the same JSON as the endpoint. (`--version` is taken by Artisan itself, hence `--flow-version`.) No customer text, names, PSIDs or waybills are in any output.
6. **Page.** Server-rendered, with filters (page, from, to, flow, flow version with date, who and note), six funnel tiles, three tables, two compare forms and a side-by-side compare with counts. A version's value is folded in a `<details>`.

**Funnel units:**
- Chats: customers who wrote in the range.
- Sent: bot send groups, and the customers they reached.
- Replied %: share of the sent groups.
- Orders %: share of the customers reached.
- Delivered % and RTS %: share of the orders.

The flow and version filters narrow the funnel, By message and compare. By sequence step and By ad use only the page and dates, because a sequence step runs its own flow.

## Case table

Final run, after the merge with `main`:
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: **OK, 812 tests, 5424 assertions** (1 skipped, as before).

| Case | Test | Result |
|---|---|---|
| S-100.1 | `ChatbotTagsTest::test_S_100_1…` (waiting send; send pool) | pass |
| S-100.2 | `ChatbotTagsTest::test_S_100_2…` (sequence step, Inbox manual) | pass |
| S-100.3 | `ChatbotTagsTest::test_S_100_3…` | pass |
| S-100.4 | `ChatbotTagsTest::test_S_100_4…`, `test_S_100_4_b…` (re-publish of an earlier graph) | pass |
| S-100.5 | `ChatbotTagsTest::test_S_100_5…` | pass |
| S-100.6 | `ChatbotTagsTest::test_S_100_6_a…` (Set node, dedupe, 92-day step, unticked, failed), `_6_b` (Test tab), `_6_c` (ticked Send through the pool, refused send), `_6_d` (prune keeps orders) | pass |
| S-100.7 | `ChatbotTagsTest::test_S_100_7…` + browser checks E1–E2 | pass |
| S-100.8 | `ChatbotResultsTest::test_S_100_8…` (10 data sets), `test_S_100_8_b…` (closest match, other page, unmatched) | pass |
| S-100.9 | `ChatbotResultsTest::test_S_100_9…` | pass |
| S-100.10 | `ChatbotResultsTest::test_S_100_10…` | pass |
| S-100.11 | `ChatbotResultsTest::test_S_100_11…` | pass |
| S-100.12 | `ChatbotResultsTest::test_S_100_12…` (JSON = command output, no customer data, bad filters fall back) | pass |
| S-100.13 | `ChatbotResultsTest::test_S_100_13…` | pass |
| S-100.14 | browser checks 1–6 at 1366 and 390, kbd, console | pass |

**Red runs** (one line per slice):
- Tagging, replied and orders: `test_S_100_1…`: `ErrorException: Trying to access array offset on null` (no tags yet). The same run failed S-100.2–S-100.7 on missing tags, versions, `replied_at`, orders and `counts_order`.
- Report: the tests were written before `ChatbotResults` existed but were first run after it was written, so there is no recorded red run for them. Their expected numbers were computed by hand from the seed in the test.
- Review fixes:
  - `test_S_100_4_b…`: "two arrays are identical" (the third Publish got the first one's id).
  - `test_S_100_6_a…`: the step from 100 days ago was attributed.
  - `test_S_100_8` "blank status": got `other`, expected `in_transit`.
  - `test_S_100_9…`: a manual send showed up in By message.
- `_6_c` and `_6_d` passed on their first run: they pin behaviour that already worked.

**Full suite** (`vendor/bin/phpunit`, after the merge): 2167 tests, **1 error and 1 failure, both already failing on `main` before this work** (baseline run at the start: 2112 tests, the same two):
- `Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (error)
- `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (302 instead of 200)

No new failures.

## Existing tests changed

- `ChatbotSendingTest::test_S_069_S4…`: `Queue::assertNothingPushed()` became `Queue::assertNotPushed(RunChatbotFlow::class)`, because every stored chat now also queues `MarkChatbotReplied`.
- `ChatbotTabsTest::test_S_069_T1…`, `ChatbotInboxTest::test_S_074_5…`, `ChatbotSequencePagesTest::test_S_070_1…`: the pinned Chatbot nav list gains "Results".

## Story changes

All stories S-100.1–14 were drafted by this session, because the brief brought none. They were not confirmed by the reviewer before code: the start prompt said to start at once.
- **S-100.5:** "the latest unreplied send gets it" became "every unreplied send group of the 24 h before the chat gets it". This follows the brief's own definition (the first customer message after `sent_at`, within 24 h): when the bot sent two nodes and the customer then answered, that chat is the first message after both.
- **S-100.4, S-100.6:** extended with the review's cases: a re-publish of an earlier graph; a ticked Send through the pool; the prune keeps orders; a sequence step only counts within 92 days.
- **S-100.12:** the outputs carry no list of orders, because waybills are customer data.

## Review

`skeptic-reviewer`, standard depth, on the spec 100 diff from the merge base. It found 3 majors and 9 minors.
- **Fixed, majors:**
  - The flow version stayed on the old id after an earlier graph was published again. Publish now clears that key; test `_4_b`.
  - Missing test for a ticked Send through the pool; added as `_6_c`.
  - Missing test for the prune keeping orders; added as `_6_d`.
- **Fixed, minors:**
  - `order()` is now inside try/report.
  - Manual sends are out of By message.
  - A blank J&T status ranks 0, as in Chatblast.
  - The order's sequence step is limited to 92 days.
  - `ready()` re-checks the schema once a minute until migrated, not on every send.
  - The plan's median wording now matches the code.
- **Accepted with reasons in `TODO.md`:**
  - The J&T ranking is a second copy of Chatblast's.
  - `RUN_VARS` repeats the run variable names of `FlowRun::vars()`.
  - `results.json` can run without a page.
  - Compare by botfield accepts ids from different botfields.
  - The rerun guard on the events index.
  - The AI-answer tag has no test of its own.
  - "Chats" in By ad means the customers the bot reached with that ad.

There was no second review loop: every major was fixed with a test and no blocker was left.

## Browser check

There was no built-in browser here, so a script outside the repo drove the installed Chrome headless over DevTools (Node 24, no packages):
- The app ran with `php artisan serve` on a temporary sqlite database outside the repo, with settings passed as environment variables. No env file was created.
- The seed was:
  - one page with 160 customers over 2026-10-14…20;
  - two Publishes of "Main Flow (from CBB)", by Owner;
  - two Main Flow versions;
  - a three-step follow-up sequence, three ads (two with names), orders, and J&T statuses.

**Result: 17/17 passed, no console errors.**

| # | Check | Width | Evidence |
|---|---|---|---|
| 1 | Filters, the 6 funnel tiles, By message, By sequence step and By ad render; the Results tab is current | 1366, 390 | `1366-results.png`, `390-results.png` |
| 2 | No page-level horizontal scroll; the wide tables scroll inside their box | 1366, 390 | same |
| 3 | A botfield version's value is folded and opens on click | 1366, 390 | `*-version-open.png` |
| 4 | The flow version list shows each Publish (version, date, who, note); compare shows A and B side by side with counts | 1366, 390 | `*-compare-flow.png` |
| 5 | Compare: no page-level horizontal scroll | 1366, 390 | same |
| 6 | Two Main Flow versions compared side by side, each value folded | 1366, 390 | `*-compare-botfield.png` |
| kbd | Tab reaches the filters, Show, a version summary and the compare selects | 1366 | — |
| E1–E2 | The Send form shows "Counts as an order" (ticked from the saved graph) with its help line; no sideways scroll | 1366, 390 | `*-editor-counts-order.png` |

The check found one fix, made before the final run: the table header style (`.cb-table thead th`) outranked `text-right` and `normal-case`. Numeric headers were left-aligned over right-aligned numbers, and the compare headers were in capitals. They now use `!text-right` and `!normal-case`.

## For the owner

- **Migration that needs your yes before release:** `2026_10_30_100000_add_results_to_chatbot_replies.php`. It does three things:
  - adds nullable columns to `chatbot_replies` (`flow_version_id`, `sequence_id`, `sequence_step`, `ad_id`, `tags`, `replied_at`, `reply_seconds`) and three indexes (`flow_id, sent_at`; `sequence_id, sent_at`; `page_id, psid, sent_at`);
  - adds one index on `chatbot_events` (`type, created_at`);
  - creates the new table `chatbot_reply_tags`.

  No existing row is changed. The code runs without it: until it is applied, no tags are written and nothing fails. Index builds on a large `chatbot_replies` take a while on MySQL.
- **What to tick in the live flow:** open "Main Flow (from CBB)", open **n45 ("Order Confirmed")** (or n43, the sheet step), tick **"Counts as an order"**, and Publish. This session did not touch the live flow. Tick only one of them: both would still count one order per customer per 24 h, but n45 is the clearer signal.
- **New scheduled command:** `chatbot:replied-backfill` hourly at :07. It runs from the existing scheduler; nothing to set up.
- **History:** data starts when this is deployed and migrated. Sends before that have no tags, and old orders don't exist as events.

## Merge danger

- **098 (`fix/098-load-fixes`):** a small textual conflict is expected in `BotcakeOutbox::record()` (new optional `$tags` argument and the create call), in `BotcakeSendQueue::finishChatbot()` (reads `tags` from the payload), and around the image check in `FlowEngine::send()`. If 098 adds a page-settings cache in `ChatbotPage::botfields()`, keep the line that fills `self::$ids` from the same rows, or the botfield versions go empty.
- **`main`:** merged in at d82695c8, so this branch merges into `main` cleanly. `editor.blade.php` had one conflict with 101: the checkbox block sits after the Sequence form and before 101's `drawVars()`.
- **Brief vs `CLAUDE.local.md`:** none.

## Housekeeping

The first browser run put a Chrome profile at `docs/specs/chrome-profile/` (a wrong relative path, fixed after that run). It is untracked and was not committed. The guard blocks recursive delete, so it is still on disk: **please delete that folder.**
