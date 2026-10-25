# 149 Comment replies: result

## Plan

Storage: a small `chatbot_comment_events` table (one row per comment), not `chatbot_replies`. Reasons:
- `chatbot_replies.kind` is `varchar(10)`: `comment_reply` does not fit, and widening the column rebuilds a large MySQL table.
- `chatbot_replies.psid` is required, and a comment may have no `from_id`.
- One row per comment holds the dedupe (unique `comment_id`), the skip reason and both results in one place.

The Replies page gets a "Comments" view that reads this table; Health counts from it.

Event path:
1. `/api/gateway/events`: the same signature check as the other gateway events; an unknown page gets 401.
2. `HandleGatewayEvent` sees `type: comment`.
3. `CommentReplies::receive()` stores the row. `comment_id` is unique, so a second copy is a duplicate.
4. Job `HandleComment` (chatbot queue) → `CommentReplies::handle()`:
   - checks: Forbeast provider, bot On, top level, not page-authored, not one of ours, values set;
   - the per-page slot: 20 per minute; when the minute is full the job is released to the next minute;
   - then the private reply (Comment PM), then the public reply (one random Comment Reply).

Sends go through `GatewayComments` (the page's Forbeast token, else `GATEWAY_KEY`, like `GatewayProvider`). Random seam: `CommentReplies::pickUsing(callable)`.

| Task | Covers | Tier | Side |
|---|---|---|---|
| T1 Columns: `comment_reply_1..3` and `comment_pm` as default botfields (long text), with paste-mapping descriptions. | .1 | medium | backend |
| T2 Comment intake and replies: the migration, the `HandleGatewayEvent` comment branch, `CommentReplies`, `HandleComment`, `GatewayComments`. | .2 .3 .4 .5 .6 .7 .8 .10 | high (a new event type from the public, publishing to Facebook) | backend |
| T3 Logs: a "Comments" filter on Replies (server plus a small view change) and Health counts (comments in, replied, failed). | .9 | medium | backend + frontend (one small view change, done in the same slice) |
| T4 Check: Forbeast's `GET /v1/pages/{id}` does not report scopes today, so the permission check is skipped and noted. | .11 | low | — |

## Progress
- [x] T1 Columns: green, checked by the main session. Reviewed together with T2 and T3 in the one adversarial review.
- [x] T2 Comment intake and replies:
  - The adversarial review found 1 major: no test for the re-check when a waiting job runs.
  - It was fixed in 1 loop, and the re-check found nothing still open.
- [x] T3 Logs on Replies and Health: reviewed with T2.
- [x] T4 Check (.11): not built; see Open points.
- [x] Review: `skeptic-reviewer`, adversarial depth, on `5a4cbd03..HEAD`. The major and 5 minors are fixed; the other minors are in `TODO.md` with reasons.
- [ ] Browser check: not run. This worktree has no running app or database. The Replies "Show" select and the comment detail popup are covered only by the markup and JSON tests.
- Deviation: the kit wants a spec review before the plan for a high-risk spec. I skipped it because the brief said "write the plan, commit, continue". The code got the adversarial review instead.

## Files
- New:
  - `database/migrations/2026_11_08_100000_create_chatbot_comment_events_table.php`
  - `app/Models/ChatbotCommentEvent.php`
  - `app/Services/Chatbot/CommentReplies.php`
  - `app/Services/Chatbot/GatewayComments.php`
  - `app/Jobs/HandleComment.php`
  - `tests/Feature/Chatblast/CommentRepliesTest.php`
- Changed:
  - `app/Services/ChatbotFields.php`: the 4 new default columns, kind `long`.
  - `app/Services/Chatbot/PasteFieldAi.php`: the one-line descriptions.
  - `app/Jobs/HandleGatewayEvent.php`: the comment ID fields in `fields()`, and the `type: comment` branch.
  - `app/Http/Controllers/ChatbotTabsController.php`: `kind=comments` on `/chatbot/replies/data`.
  - `resources/views/chatbot/replies.blade.php`: the Show select and the comment detail popup.
  - `app/Services/Chatbot/BotHealth.php` and `resources/views/chatbot/health.blade.php`: the three comment counts.
  - `tests/Feature/Chatblast/ChatbotPagesTest.php`: S-081's column order now includes the 4 new columns.
  - `qa/stories.md` (S-149) and `TODO.md`.

## Columns
`comment_reply_1`, `comment_reply_2` and `comment_reply_3` (labels Comment Reply 1, 2, 3) and `comment_pm` (label Comment PM).
- They are default botfields in `page_settings` (provider `chatbot`), of kind `long`, so they open in the modal editor.
- They sit after After Order 4 and before the columns added with "+ Column".
- An empty value turns that part off.

## Event handling path
1. **Intake.** `POST /api/gateway/events` runs the same size, page and signature checks as the other gateway events: an unknown page gets 401, and so does a bad or missing signature.
2. **Routing.** `HandleGatewayEvent` (queue `chatbot-high`) sees `type: comment` and calls `CommentReplies::receive()`. The comment text and `from_name` are never kept.
3. **Store.** `receive()` inserts one row into `chatbot_comment_events`. `comment_id` is unique: the second copy is counted `duplicate`.
4. **Skips on arrival:**
   - a reply to a reply (`is_reply`);
   - written by the page (`is_page_author`, or `from_id` = the page);
   - one of our own replies (`reply_comment_id`);
   - a Botcake page, or the bot is Off.

   Every other comment dispatches `HandleComment` (queue `chatbot`).
5. **`HandleComment` → `CommentReplies::handle()`:**
   - It checks the page again (provider, Off) and the values (`not_set`).
   - It takes a slot: 20 comments per minute per page (a cache counter). When the minute is full, the job is released to the next minute, for up to 6 hours. `maxExceptions = 1`, so an error is never retried.
   - It claims the row with a compare-and-set (`queued` → `sending`), sends the private reply (Comment PM), then the public reply (one random variant), and records each result.
   - A job that fails for good runs `failed()`, which marks the row `failed` and sends nothing.
6. **Variables** are filled the way FlowRun does it: the botfields first fill each other one level deep, plus `page_name`, `page_id` and `current_date`. There is no name variable.
7. **Sending.** `GatewayComments` sends with the page's Forbeast token (else `GATEWAY_KEY`). An `already_replied` answer (`error`, or Meta #10900) is recorded and not retried.

## Random seam
`CommentReplies::pickUsing(callable|null)`: the callable gets the filled variants (variables already filled) and returns the one to send. `null` uses `random_int`.

## Logs
- **Where:** table `chatbot_comment_events`, one row per comment, with:
  - `status` and `reason`;
  - `pm_status`, `pm_text`, `pm_error`;
  - `reply_status`, `reply_text`, `reply_error`, `reply_comment_id`;
  - `received_at` and `handled_at`.
- **Replies:** Show: Comments (`/chatbot/replies/data?kind=comments`), with the same date, page, status and search filters. Every value is shown as text.
- **Health:** Comments in, Comments replied and Comments failed, in the tiles and the JSON.
- **No comment text** is stored or logged. The S-149.9 test listens to every log line.
- **Counters:** the `gateway-events` counters also count `comment`, `duplicate` and `no_id`.

## Story changes
- **S-081 (`ChatbotPagesTest`):** the expected column order after Picture now includes the 4 comment columns, before the "+ Column" columns. The new defaults require this.
- **S-149.3:** the brief says "all replies empty → nothing sent". I read that with Decision 1 ("Empty = that part is off"):
  - With no Comment Reply, the private message still goes.
  - Only when all four columns are empty is the comment skipped as "not set".

  Each of these has its own row in the test table.
- **S-149.11:** not built; see Open points.

## Evidence per case
| Case | Test | Result |
|---|---|---|
| S-149.1 | `test_S_149_1_the_four_comment_columns_are_editable_with_history_and_in_the_paste_mapping` | pass |
| S-149.2 | `test_S_149_2_a_comment_gets_the_private_message_first_then_one_random_public_reply`, `test_S_149_2_a_botfield_inside_a_comment_value_is_filled_too` | pass |
| S-149.3 | `test_S_149_3_an_empty_column_turns_its_part_off` (3 rows) | pass |
| S-149.4 | `test_S_149_4_and_5_…` (Botcake page, bot Off) | pass |
| S-149.5 | `test_S_149_4_and_5_…` (reply, page-authored, ours) | pass |
| S-149.6 | `test_S_149_6_the_same_comment_twice_is_handled_once` | pass |
| S-149.7 | `test_S_149_7_already_replied_is_recorded_and_not_retried`, `test_S_149_7_a_failed_send_is_recorded_failed_and_not_retried` (no answer, ok:false, from_id null) | pass |
| S-149.8 | `test_S_149_8_the_21st_comment_in_a_minute_waits_for_the_next_minute` (released for 45 s at 10:00:15, sent at 10:01), `test_S_149_8_a_waiting_comment_is_skipped_when_the_page_changed_before_its_turn` (2 rows) | pass |
| S-149.9 | `test_S_149_9_comments_show_on_replies_with_a_filter_and_are_counted_on_health_without_comment_text_in_the_log` | pass |
| S-149.10 | `test_S_149_10_an_unknown_page_or_a_bad_signature_is_refused` | pass |
| S-149.11 | — | not built |

Red runs:

| Slice | First failure |
|---|---|
| T1 | `S_149_1`: `comment_reply_1 Failed asserting that two arrays are identical` |
| T2 | `Class "App\Services\Chatbot\CommentReplies" not found`, and "File does not exist" for the migration |
| T3 | `S_149_9`: `Failed asserting that two arrays are identical` (no comment rows yet) |
| Fix loop | The nested-botfield test: `Failed asserting that two strings are identical` |

In the fix loop, the re-check and failure-path tests passed on the existing code. They pin behaviour that already worked but had no test, which is what the major asked for.

Suite `tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode`:
- Final: `OK, but there were issues! Tests: 1479, Assertions: 8612, PHPUnit Deprecations: 75, Skipped: 1`. No failures.
- Baseline at 5ff034d1: `Tests: 1459`, no failures. So no new failures.

## Commits
- `5a4cbd03` docs: spec 149 and plan for comment replies
- `ca0c0d5b` feat: Comment Reply 1-3 and Comment PM botfield columns with paste-mapping descriptions (spec 149)
- `4ab79866` feat: comment events from Forbeast get one private message and one random public reply, 20 per minute per page (spec 149)
- `5d41e1a8` feat: comments on the Replies page (Show: Comments) and comment counts on Health (spec 149)
- `2209c3bc` fix: comment values fill nested botfields, re-check and failure paths pinned by tests, a dead job shows failed (spec 149)
- plus the docs commit with this result, the S-149 stories and `TODO.md`

## Open points
- **S-149.11 is skipped.** Forbeast's `GET /v1/pages/{id}` (used by connect and Test) does not report the token's scopes, so Check cannot tell whether the comment permissions are there. Add the check once Forbeast reports scopes.
- **Migration:** `php artisan migrate --force` must run with the deploy. Until it does, comment events are only counted (`no_id`).
- **Forbeast contract assumptions to confirm** against its handoff 014:
  - top-level comments come with `is_reply: false`;
  - the reply answer carries the new `comment_id` (used to skip our own replies);
  - errors use `error: "already_replied"` and/or `meta_code: 10900`;
  - a 2xx with `ok:false` counts as failed.
- **The 20-per-minute limit counts comments, not sends.**
- **Comment send failures do not trigger the Forbeast auto-switch** (`ForbeastWatch`).
- **Accepted review minors:** in `TODO.md` under "149".

149: done — top-level comments on Forbeast pages get one Comment PM private message and one random Comment Reply, set per page in /chatbot/pages, at most 20 per minute, logged on Replies and Health; S-149.11 skipped because Forbeast does not report scopes.
