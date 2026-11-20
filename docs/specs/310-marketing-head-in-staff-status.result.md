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
_(filled at the end)_
