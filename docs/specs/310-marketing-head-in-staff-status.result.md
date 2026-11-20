# 310 · Result

## Plan
1. Base: `composer install`, full suite on 6c3ec11e, note the failures (below under Evidence).
2. Tests first, in `tests/Feature/Checklist/AiFollowupStaffStatusTest.php` (extends `AiFollowupTestCase`, which has the real
   follow-up columns, the chat tables and the `followup()` / `reviewed()` helpers; it sits next to `AiFollowupStaffTest`, the
   existing staff feed tests for follow-ups). Cases: each field (drafted, approved, auto_approved, rejected, waiting, sent,
   failed, last_at, last), a day with none (zeros and nulls), `?date=` picks another day, soft-deleted rows not counted,
   bad/missing key still 401/503, the old keys and values unchanged (response with the block removed equals the base shape).
3. Code: one static method `AiFollowups::dayActivity(Carbon $day): array` and one line in `StaffFeedController::status`
   adding `'ai_followups' => AiFollowups::dayActivity($day)` as the last top-level key.
   - Query 1: `checklist_tasks` where origin = 'ai', `deleted_at` null, `follows_date` in [day, next day) (or created that
     day when `follows_date` is null), left join `chat_messages` for the time it was sent. Columns listed explicitly, no text.
   - Query 2 (only when there is a newest one): the first name of its assignee (`checklist_task_users` → profile name, else user name).
   - Guards: no `origin` column or no table → zero block (the staff-activity tests build a smaller `checklist_tasks`).
4. Two existing tests must learn the new key: `StaffFeedApiTest` asserts the exact top-level key list, and
   `AiFollowupCountsTest` compares the whole feed before/after follow-ups exist (it must ignore the new block, which counts them by design).
5. Full suite, compare with base, fill this result, push.

## What shipped
- `GET /api/staff-status` has one new top-level key, `ai_followups`, placed last. The existing keys and their values did not change.
- `AiFollowups::dayActivity(Carbon $day)` (new, in its own "Staff feed" section): two read-only queries.
  1. Follow-ups of the day (`checklist_tasks`, origin `ai`, `deleted_at` null) with a left join to `chat_messages` for the time each was sent. The columns are listed explicitly and no text is selected.
  2. Only when there is a newest follow-up: its assignee's name (profile name, else user name) → first word.
- `StaffFeedController::status`: one import and one line, `'ai_followups' => AiFollowups::dayActivity($day)`.
- No migration, no new route. Drafting, approval and sending are unchanged.

Files: `app/Services/AiFollowups.php`, `app/Http/Controllers/StaffFeedController.php`,
`tests/Feature/Checklist/AiFollowupStaffStatusTest.php` (new), `tests/Feature/StaffActivity/StaffFeedApiTest.php` (1 line),
`tests/Feature/Checklist/AiFollowupCountsTest.php` (2 lines).

### Sample block (from `test_each_field_counts_that_days_followups`, date 2026-11-18)
A draft, one CEO-approved and sent, one auto-approved whose chat post failed, one rejected (newest, 12:00, Ben), one
expired, and one from another day (not counted):
```json
"ai_followups": {
  "drafted": 5, "approved": 2, "auto_approved": 1, "rejected": 1, "waiting": 1, "sent": 1, "failed": 1,
  "last_at": "2026-11-18T12:00:00+08:00",
  "last": { "at": "2026-11-18T12:00:00+08:00", "person": "Ben", "state": "rejected" }
}
```
A day with none gives `{"drafted":0,"approved":0,"auto_approved":0,"rejected":0,"waiting":0,"sent":0,"failed":0,"last_at":null,"last":null}`.

## Evidence per done-when item
1. **MH-1 tests pass.** `AiFollowupStaffStatusTest` has 10 tests and 39 assertions, all OK. They were red before the code (commit 07e83711, 8 failures and 1 error). The tests are:
   - each field: `test_each_field_counts_that_days_followups`
   - last and its state: `test_last_is_the_followup_with_the_newest_activity_and_its_state` and `test_approved_and_failed_states_of_the_last_one`
   - a day with none: `test_a_day_with_no_followups_gives_zeros_and_nulls`
   - the date parameter: `test_the_date_parameter_picks_that_day` and `test_without_follows_date_the_created_day_in_manila_counts`
   - soft-deleted rows: `test_soft_deleted_followups_do_not_count`
   - regular tasks are not follow-ups: `test_regular_checklist_tasks_are_not_followups`
   - auth unchanged: `test_the_key_check_is_unchanged` (401 with no key, 401 with a wrong key, 503 when the key is unset)
   - old keys and values unchanged: `test_the_old_keys_and_values_are_the_same_with_followups_present` (whole response minus `ai_followups` and `generated_at` is identical before and after follow-ups exist, and the key order is fixed)

   The test also checks that the response never contains the message text or the chat body.

   **Full suite vs base**

   | Run | Tests | Errors | Failures | Skipped |
   |---|---|---|---|---|
   | Base 6c3ec11e | 5975 | 5 | 25 | 15 |
   | Tip | 5985 | 5 | 23 | 15 |

   - +10 tests are the new file.
   - Tip failures, all known: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest (S_42_4 `/owner/private`), and the Chatblast speed tests (ResponseSpeed 10, SpeedMemory 4, SpeedPrecomputed 1, SpeedPrewarm 10). Run alone on the tip, each of those 4 speed files passes: ResponseSpeed 33/33, SpeedMemory 4/4, SpeedPrecomputed 9/9, SpeedPrewarm 20/20. BigBroadcast did not fail this time.
   - **Caveat on the base run:** it ran in this worktree while I committed the code (787646b0), so 2 of its failures came from my change, not the base. These were `AiFollowupCountsTest::test_every_count_and_list_is_the_same_with_followups_present` and `StaffFeedApiTest::test_status_has_exactly_the_documented_keys_and_no_private_data`. Both are fixed in 30a94351 and pass on the tip (43/43 and 1/1). Without them the base has 28 problems, the same set as the tip: no new failures.
2. This file holds the sample block, what changed, and the merge danger.
3. Branch pushed: `feat/310-marketing-head-in-staff-status`. No merge, no deploy.

## Story changes
- Two existing tests had to learn the new key. The spec's "nothing else changes" still holds for the old keys.
  - `StaffFeedApiTest` asserts the exact top-level key list; `ai_followups` was appended.
  - `AiFollowupCountsTest` snapshots the whole feed before and after follow-ups exist, to prove follow-ups never change the staff counts. It now ignores `ai_followups`, which counts follow-ups on purpose. Every other key is still compared.
- One extra case: an ordinary checklist task created that day is not counted.

## Rulings
- **Which day.** `follows_date` in [day, next day), else `created_at` that day in Manila, as the spec says. The code writes `follows_date` on every follow-up (`AiFollowups` sets it to the Manila date at creation), so the fallback only covers hand-made rows. The `<` next-day bound copies `checklistApproved` so it is safe if a date is stored with a time (sqlite).
- **Expired drafts** (`review_status = expired`, drafts never reviewed by day's end):
  - They count in `drafted` only, not `waiting`, because they are no longer drafts.
  - If one is the newest, `last.state` is `expired`. That value is not in the spec's list; I kept it because calling it "draft" would be untrue. **Reviewer may decide** to map it to `draft` or leave it out of `last`.
  - In practice an expired row only exists on past days. Expiry also writes no time, so it never moves `last_at`.
- **`sent`** = `chat_message_id` set, whatever the review status (only approved ones are ever posted).
- **`failed`** = approved, `chat_failed_at` set, no `chat_message_id`, as the spec says.
- **State order for `last.state`:** sent > failed > the review status (draft / approved / rejected / expired).
- **`last_at` and `last`:**
  - The activity time of a follow-up is the latest of its created, reviewed and sent times. Sent time is `chat_messages.created_at`, since no sent column exists; there is no migration.
  - `last` is the follow-up with the latest activity time; ties go to the higher id. So `last.at` always equals `last_at`.
  - A chat failure time is not counted as activity: it is a failed attempt, and the spec lists created, reviewed and sent.
- **Times** are read as Asia/Manila (`app.timezone`), like the controller's `iso()`.
- **`person`** is the first word of the profile name, else the user name, the same rule as `AiFollowups::firstName`. It is fetched with one joined query rather than `firstName()`, which loads the model and its relation (two queries). It is `null` if there is no assignee.
- **Guard:** with no `origin` or `chat_message_id` column on `checklist_tasks`, the block is all zeros. The staff-activity tests build a smaller table; production has both columns (specs 293 and 299 are live).
- **Where the tests live:** `tests/Feature/Checklist/`, next to `AiFollowupStaffTest` (the existing follow-up staff feed tests). Its base case has the real follow-up and chat columns.

## Merge danger
- **What ships:**
  - A new read-only key in `/api/staff-status`, behind the same `X-STAFF-FEED-KEY`. It exposes counts, ISO times and one first name.
  - Two more queries per call, on the `(origin, follows_date)` index. Rows are at most about 3 per marketing person per day.
  - No writes, migrations, routes or config.
- **Who notices:** the office (Mira plugin) reads the feed. A client that checks the exact key list would see one extra key; the plugin only reads keys it knows, which the architect should confirm.
- **Conflicts:** `AiFollowups.php` gains a section before "Pangalan", and `StaffFeedController::status` gains one line in the response. Any branch touching those spots merges by hand.
- **Revert:** `git revert 787646b0 30a94351 07e83711`. These are the code, the two adjusted tests and the new tests; nothing else depends on them.
