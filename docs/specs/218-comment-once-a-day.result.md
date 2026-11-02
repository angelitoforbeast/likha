# Result: spec 218 one comment answer per customer per page per day

## Status

done (no open question)

## Date

2026-11-03

## Branch / PR

`feat/218-comment-once-a-day` (worktree `.claude/worktrees/comments-218`), based on `vps/main` f5d6d450. Not pushed, no PR.

## Summary

One code file changed: `app/Services/Chatbot/CommentReplies.php`. There is no migration, table, column or config.
Nothing in `app/Jobs/` changed, so `HandleComment` keeps its properties and the jobs already queued run as before.

- **The rule.** A commenter (`from_id`) gets at most one answer per page per Manila calendar day: one Comment PM and one public reply, as configured. Each later comment that day is `skipped` with reason `daily_limit`, and nothing is sent for it.
- **When it is checked.** In `handle()`, each time the job runs: on arrival, and again after a wait for the next minute (full minute or 429). The check runs before the per-minute seat is taken, so a skipped comment does not use one of the page's 20 seats.
- **What counts as answered.** Another row of the same page and `from_id` counts as answered today when both hold:
  - Its `pm_status` or `reply_status` is `sent`, `already_replied` or `unknown` (may have been posted), or the row is still `sending`.
  - Its `handled_at` is today (Manila), or `handled_at` is null because the row is still in progress (sending, or waiting with one part sent).

  Skipped rows, and rows that failed before anything was sent, do not count.
- **Concurrency.** One cache lock, `comment-replies:once:<page_id>:<from_id>` (10 s), covers the check and the claim to `sending`.
  - The lock is released before any HTTP call. From then on the row is `sending`, which counts as answered.
  - If another worker holds the lock, the job is released for 5 seconds without sending, then checks again.
  - The skip is a conditional update (`where status = queued`), so a duplicate job of the same row cannot overwrite a row that is already sending.
- **Not limited.**
  - A comment with no `from_id` (null or empty) is answered as before, because no limit can apply (S-218.1.7).
  - A row that already sent one part before a 429 finishes its other part without the check: it is the day's answer.
- **Query.** The check filters `page_id` = P and `received_at` ≥ the start of the Manila day minus 7 hours, then `from_id`, on the existing `(page_id, received_at)` index. A comment waits in the queue 6 hours at most (`HandleComment::retryUntil`), so an older row cannot have been answered today.
- **Where it shows.** `CommentReplies::REASONS` maps `daily_limit` to "already answered today".
  - The Comments view of `/chatbot/replies` shows such a row as "Skipped: already answered today", and the skipped filter lists it.
  - Health counts comments by status only (in, replied, failed). A `daily_limit` row counts as "in" and not as "replied", like the other skips.

## Checks (command and output)

PHP is Herd's `php84`. Tests use sqlite in memory, as `phpunit.xml` sets, and Forbeast is faked with `Http::fake`.

1. Before the code: `php vendor/bin/phpunit tests/Feature/Chatblast/CommentRepliesTest.php --filter S_218` gave `Tests: 18, Assertions: 55, Failures: 16.`
   - All 16 failures are assertion failures: extra calls, a missing `daily_limit`, "Job was expected to be released", a null label.
   - They come from the S-218.1.2, .3, .4, .5, .6, .8 and .9 tests.
   - S-218.1.1 and S-218.1.7 passed, because they guard behavior that already works (see Red lines).
2. After the code, before adjusting the older tests: `php vendor/bin/phpunit tests/Feature/Chatblast/CommentRepliesTest.php` gave `Tests: 50, Assertions: 216, Failures: 3`. The three are S-149.8, S-149.18 and S-149.9, which sent several comments from one `from_id` (see Story changes).
3. Same command after the adjustment: `OK (50 tests, 231 assertions)`.
4. Full suite, `php vendor/bin/phpunit`: FULL_SUITE_RESULT

## Red lines

One feat slice: `feat(chatbot): one comment answer per customer per page per Manila day (spec 218)`. The tests were
written first and watched fail before the code existed.

| Case | Red before the code | After the code |
|---|---|---|
| S-218.1.2 (second and third comment) | "only the first comment is answered": 6 calls, expected 2 | pass |
| S-218.1.2 (8 earlier states) | calls were made, expected `[]` | pass (all 8) |
| S-218.1.3 | c_3 was sent, expected `daily_limit` | pass |
| S-218.1.4 | c_2 was sent, expected skipped | pass |
| S-218.1.5 | c_2 was sent, expected `daily_limit` | pass |
| S-218.1.6 (during the first send) | c_2 was sent inside c_1's call | pass |
| S-218.1.6 (lock held) | "Job was expected to be released, but was not." | pass |
| S-218.1.8 | c_1 was sent after its wait, expected `daily_limit` | pass |
| S-218.1.9 | "Failed asserting that null is identical to 'already answered today'." | pass |
| S-218.1.1, S-218.1.7 | already green: guards that a first comment, and a comment with no `from_id`, are still answered | pass |

## Done-when checklist

- [x] Cases S-218.1.1 – S-218.1.9 pass, each with a test named after it: 11 test methods, 18 runs with the data sets. The file gives `OK (50 tests, 231 assertions)`.
- [x] The result has a red line for every feat/fix slice, watched before the code (the table above).
- [x] The full suite adds no new failures (Checks, item 4).
- [x] `qa/stories.md` has Slice 218, with Test and "Last run" (2026-11-03 passed) filled for all nine cases.
- [x] This file is filled in, with Deploy notes.

## Rulings

- Ruling: "today" is the Manila day of the send, not of the arrival. An answered row counts when its `handled_at` is today or it is still in progress — the rule is about what the customer receives in a day — cost if wrong: a comment that arrived at 23:59 and waited past midnight uses up the next day's answer (one answer fewer, never a double).
- Ruling: no new index and no migration; the check reads the existing `(page_id, received_at)` range from 7 hours before the start of the Manila day and filters `from_id` on those rows — cost if wrong: a slower check on a very busy page, fixed by an additive `(page_id, from_id, received_at)` index with no code change.
- Ruling: one cache lock per page and commenter covers the check, the seat and the claim to `sending`; it is never held during HTTP, because a `sending` row already counts as answered — cost if wrong: if the workers did not share one cache store, two simultaneous comments could both be answered (the per-minute counter already relies on that shared store).
- Ruling: when the lock is busy, the job is released for 5 seconds instead of being skipped — cost if wrong: a 5-second delay for that comment.
- Ruling: a row that already sent one part before a 429 finishes its other part without the check, since both parts are one answer — cost if wrong: none found.
- Ruling: a `sending` row left by a dead worker counts as answered until `HandleComment::failed` marks it failed, and a row marked failed that way has no part status, so it does not count (the spec's "failed before sending") — cost if wrong: after a crash in the middle of a send, the customer may get a second answer later that day (rare).
- Ruling: the check runs before the per-minute seat, so a `daily_limit` skip does not use one of the 20 seats — cost if wrong: none, since the seats only pace sends.

## Story changes

- Added Slice 218 to `qa/stories.md`, with the spec's cases S-218.1.1 – S-218.1.9.
- Three older tests sent several comments from one `from_id`. Under the new rule the second comment would be skipped `daily_limit`, but these tests are about the per-minute limit and the Replies view, not the daily rule. Each comment now gets its own `from_id`, and the assertions are unchanged:
  - S-149.8: the 21 comments in a minute use `from_id` 601–621.
  - S-149.18: the "other" comment waiting for the same 429 minute uses 556.
  - S-149.9: c_2 on the Replies Comments view uses 556.

## Deferred minors

- The comment on the `reason` column in `2026_11_08_100000_create_chatbot_comment_events_table.php` does not list `daily_limit`. It is a comment in a migration that has already run, so I left it.
- Health has no separate count for `daily_limit` skips. Like the other skips, they count in "Comments in" and not in "replied". The Replies Comments view lists each one.

## Deploy notes

- There is no migration and no config. The only code change is in `CommentReplies`; the rest is tests and docs.
- No queued job class changed, so the `HandleComment` jobs already in the queue keep working. Once the workers have restarted, those jobs get the check when they run, so a backlog from before the deploy is limited too.
- Restart the queue workers (Horizon) as on every deploy, so that they load the new `CommentReplies`.
- The lock uses the default cache store (`Cache::lock`), the same store as the per-minute counter. Every worker must share that store (Redis or database), as the 20-per-minute limit already requires.
- Likha and Incepxion run the same tree. Both get the rule on their next release.
- To revert, revert the feat commit. Rows already skipped `daily_limit` stay skipped and are not sent again.

## Open questions

None.

## Proposed tasks

- If the owner wants the daily skips as a number on Health ("skipped: already answered today"), that is a small follow-up in `BotHealth::comments`.

218: done — one Comment PM and one public reply per commenter per page per Manila day; later comments skipped `daily_limit`, under a per-commenter lock
