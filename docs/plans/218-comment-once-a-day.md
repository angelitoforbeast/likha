# Plan: spec 218 one comment answer per customer per page per day

Risk tier: medium (messages to customers). Threat model as in the spec: untrusted comment events from the
gateway (`from_id`, ids), trusted database and config. The medium risk is a double answer under concurrency,
so the check and the claim to `sending` run under one lock per page and commenter.

No migration, no new table, no new column. `App\Jobs\HandleComment` and every other queued job class keep
their properties as they are (nothing in `app/Jobs/` changes).

## Design (all in `App\Services\Chatbot\CommentReplies::handle`)

- **Where.** In `handle()`, after the page and value checks and before the per-minute slot, so the limit is
  checked every time the job runs (also after a wait for the next minute, S-218.1.8) and a skipped comment
  does not take one of the page's 20 seats.
- **Who.** Only when the row has a `from_id` (S-218.1.7: no `from_id`, no limit, answered as today), and only
  when the row has not started sending yet (`pm_status` and `reply_status` both null). A row that already
  sent one part before a 429 finishes its other part: it is the one answer of the day.
- **Lock.** `Cache::lock('comment-replies:once:<page>:<from_id>', 10)`. Held over the check, the slot and the
  claim to `sending`; released in `finally`. If another job of the same person and page holds it, the job is
  released for 5 seconds (no send, no error) and checks again. Once a row is `sending`, it counts as answered,
  so the lock only has to cover the check-then-claim window, never the HTTP calls.
- **Answered today** (another row of the same `page_id` and `from_id`, not this row):
  - `status = 'sending'`, or `pm_status` or `reply_status` in (`sent`, `already_replied`, `unknown`);
  - and answered today, Manila day of now (the send time, not the arrival): `handled_at` today, or
    `handled_at` null (still in progress: sending, or waiting with one part sent).
  - `received_at` from 7 hours before today's start: a comment waits in the queue 6 hours at most
    (`HandleComment::retryUntil`), so older rows cannot be answered today. This keeps the query on the
    existing `(page_id, received_at)` index; `from_id` is filtered on the rows of that range.
  - Skipped rows and rows that failed before anything was sent have no sent/unknown part, so they do not
    count (S-218.1.5).
- **Skip.** `skip($event, 'daily_limit')`: status `skipped`, reason `daily_limit` (11 chars, the column is 20).
- **Label.** `REASONS['daily_limit'] = 'already answered today'`. The Replies Comments view
  (`ChatbotTabsController::comments`) shows it as "Skipped: already answered today" (S-218.1.9). Health counts
  comments by status only, so it needs nothing.
- The class docblock says the new rule.

## Tests (`tests/Feature/Chatblast/CommentRepliesTest.php`, Forbeast faked with `Http::fake`)

One test per case, named `test_S_218_1_N_...`, each watched red before the code:

1. First comment today: PM and reply sent.
2. Answered earlier today (data provider: PM sent, reply sent only, `unknown`/504, `already_replied`), then
   a second comment on the same post and on another post: no calls, `skipped` / `daily_limit`.
3. Answered yesterday 23:50 Manila, comment at 00:10: answered.
4. Answered on page P today, comment on page Q: answered.
5. Earlier rows today skipped (bot off, not set, reply, page author) or failed before sending: answered.
6. Two comments at the same moment: the second job runs while the first is in its HTTP call (inside the
   fake) and is skipped `daily_limit`; and with the lock held by another worker, the job waits (released,
   no call) and then is skipped once the first is sent.
7. No `from_id`: two comments, both answered.
8. A comment waiting for the next minute (429 / full minute): another comment of the same person is answered
   meanwhile; when the waiting one runs, it is skipped `daily_limit`.
9. The Replies Comments view shows "Skipped: already answered today".

Existing tests that send many comments from one `from_id` to test the per-minute limit or the Replies view
(S-149.8, S-149.9, S-149.18) get one `from_id` per comment, since their subject is not the daily rule.

## Steps

1. Spec and result file (done), this plan.
2. Stories in `qa/stories.md` (Slice 218), failing tests, watch red; save the red output.
3. Code in `CommentReplies`; adjust the three older tests; tests green; commit.
4. Full suite against the baseline; fill the result (checks, red lines, rulings, deploy notes); commit.
