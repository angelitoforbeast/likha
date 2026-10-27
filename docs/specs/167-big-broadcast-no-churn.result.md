# Result 167: a big broadcast goes out in one run without churn or lost sends

Status: done. Branch `fix/167-big-broadcast-no-churn`, cut from `e7b09402` (spec 165 on top of live `87cb0e1e`).

## What changed
- **No churn while waiting (decision 1).** A chatblast job still checks the message under the customer lock, but it now hands the ticket to the send pool and releases the lock at once. The pool takes the customer lock when it starts the message (`ChatblastSender::poolStart`, 900 s, enough for the three steps plus the retries). At that point it also checks the customer gap and whether another sequence already messaged the waybill today while this one waited. If the customer is busy or in the gap, the ticket goes back in line with `next_at` (`BotcakeSendQueue::later`): nothing fails and no job is queued again. Per-customer order is still the claim's rule: only a customer's oldest open ticket can be claimed.
- **Safety deadline in hours.** `CHATBLAST_SECONDS` went from 240 s to 3 h; it now only catches rows that are really stuck (e.g. no pool). `recover` releases the customer lock when it puts a row back in line. A message that ends before the lock was taken sets no gap.
- **Safe retry (decision 2).** In the pool, a chatblast field write or read-back that Botcake answers with HTTP 5xx or no answer is tried again after 15 s, then 60 s. That makes 3 tries, and then it ends as today. These retries are counted apart from the 429 retries, so a step that first got 429s still gets its 15 s and 60 s tries. send_flow keeps its rule: only a 429 is retried; a 5xx or no answer is "Not sure it was sent". Bot replies are unchanged.
- **Unchanged (decision 3):** bot replies first (spec 165), the per-page rate setting, sequences, statuses and message text.
- **Counters (decision 4).** Each pool run counts chatblast messages taken (`queued`), `sent`, `retried` by step and `failed` by status and reason, without error details. The counts are cached under `botcake-send-pool:counts` on each heartbeat and logged (`botcake send pool: chatblast counts`) when the pool stops.

## Case table
| Case | Test | Result |
|---|---|---|
| S-167.1 | `BigBroadcastTest::test_S_167_1_5000_messages_on_50_pages_all_go_out_without_one_failed_for_waiting` (28 s; the simulated line lasts over 240 s) | pass (red on e7b09402: "Failed asserting that 4750 is identical to 5000": each customer's second message was bounced at queue time by the lock held for the waiting ticket) |
| S-167.1 | `test_S_167_1_a_waybill_another_sequence_messaged_while_it_waited_in_line_is_skipped_when_the_pool_starts_it` | pass (red with the pool-start check removed: got `sent` instead of `skipped`, "already sent today by another sequence") |
| S-167.2 | `test_S_167_2_a_step_before_send_flow_that_botcake_fails_is_tried_again_after_15_s_then_60_s` (6 rows) | pass (red on e7b09402: "the wait before try 2: 2.0 matches expected 15"; three 500s were followed by a 4th try that sent. Row "two write 429s, a 500, then ok" red on 34e1da57: `failed` "The custom field was not written (HTTP 500)" instead of `sent`, because the 429s used up the 5xx retries) |
| S-167.3 | `test_S_167_3_an_error_after_send_flow_is_never_tried_again` | pass (the same behaviour as before; red on e7b09402 only because `counts()` was missing) |
| S-167.4 | `BotRepliesBeforeBlastsTest` (S-165.1, .2, .3, .5) | pass |
| S-167.5 | `test_S_167_5_a_dead_pools_stuck_messages_are_taken_back_and_sent_once_by_the_next_pool` | pass (red on e7b09402: a third ticket was not in line; the customer's second message bounced at queue time) |
| S-167.6 | `tests/Feature/Chatblast tests/Feature/Botcake tests/Unit` | pass: 1318 tests, 44800 assertions, 1 skipped, 0 failures, 50 deprecations. Baseline e7b09402: 1308 tests, 0 failures, 49 deprecations; the extra deprecation is the new `@dataProvider` docblock, the same style as the existing tests |

## Story changes
- S-089.6 (`BotcakeAsyncSendTest`): "the customer lock is held while the pool sends" is now checked after the pool's first tick, not right after the job, because the lock is now taken when the pool starts the message (decision 1).
- S-089.11: the sweep test now travels `CHATBLAST_SECONDS + 1` instead of 241 s. The chatbot send's 10-minute deadline is then long past, so the same sweep fails it, and the comment says so.
- S-167.1 also covers the other-sequence check at pool start (added after review).
- S-167.4 maps to the spec 165 tests, as the case says; the story row now lists S-165.1, .2, .3 and .5, as this result does.
- S-167.2: a sixth table row (two 429s, a 500, then ok on a field write: sent once), added after QA found the 429s used up the 5xx retries.
- Decision 2 says "e.g. 15 s, 60 s, 180 s; 3 tries", and case .2 says "3 consecutive 500s → failed". To meet the case, a step gets 3 tries in total: the first, then one after 15 s, then one after 60 s. There is no 180 s wait.

## Not changed (by choice, in `TODO.md`)
- A 429 keeps today's rule: up to 3 retries at 2/4/8 s, or Retry-After capped at 10 s, with the page lane paused. 429 was already retried, and a 180 s pause would stall the whole page.
- The waiting path (no live pool) keeps 2/4/8 s: the job's 300 s timeout cannot hold 75 s of waits plus the steps.
- Review minors accepted with reasons: a lock leak if `poolStart` throws, sequence on/off and status re-checked only at queue time, the stale 10-minute window in `blockedToday`, order during the hourly pool restart, the `queued` counter's meaning, and 4 untested paths.

## Review
`skeptic-reviewer`, standard depth. It found 1 major: there was no test for the other-sequence check at pool start, which is now the only guard against two sequences messaging one waybill on the same day. Fixed: test added, and it goes red without the check. No blockers. The minors are accepted in `TODO.md`. The reviewer checked exactly-once (send_flow is never retried, `called_at` is unchanged), lock release on every path, and per-customer order, and found them sound.

## Deploy notes
- No migration and no config change. `BOTCAKE_RATE_PER_PAGE` stays the setting.
- Deploy when no chatblast run is in progress, if possible. A ticket queued by the old code still holds the old job's lock: the new pool sends it back in line until its old 240 s deadline, and the sweep then queues its job once. Nothing is sent twice.
- Restart `botcake:send-pool` (supervisor) and Horizon / the chatblast queue workers so both load the new code.
- The same commits apply to Incepxion: same codebase, and the touched files are the same as on Likha at `e7b09402`.

167: done — a big run waits in line without a lock or a deadline, so nothing is failed or requeued for waiting; field write/read failures before send_flow retry after 15 s and 60 s, counted apart from 429 retries; per-run counts are logged.
