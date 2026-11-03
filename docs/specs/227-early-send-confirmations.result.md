# Result 227: a send confirmation that arrives before its reply row is kept and matched later

**Branch:** `fix/227-early-send-confirmations` from `b8fc9d63`. **Status:** done, not pushed, not deployed. No migration.

| Commit | Story |
|---|---|
| `539dde8b` | the spec |
| `2c9800f8` | S1 findings (plan), S2 keep and retry, the stories |
| `3d976df3` | S3 heal command |

## S1 the cause

The race, as the spec suspected (details and the live checks in `docs/plans/227-early-send-confirmations.md`):
both send paths write the `chatbot_replies` row, with its `client_ref`, only after they have handled the
gateway's 202 (`SequenceSendPool::respond` → `finishSequence` → `BotcakeOutbox::record`; flow replies:
`GatewayProvider::send` then `record`). The gateway answers 202 after dispatching its drain, so its worker
can send and deliver `send_status` first. The sequence pool handles up to 100 answers one after another in
one process, which makes the window seconds wide in a burst (hence 640 of 670 being sequence sends).
`SendStatus::match` then finds no row, `receive` returns `send_status_unknown`, and nothing kept the event.

Not checked here (no server access): the per-day counter `gateway-events:<date>:send_status_unknown` and the
row timestamps for the example ref; the plan gives the two queries.

## What shipped

- **S2** `SendStatus::receive` returns `SendStatus::NOT_YET` when the event has a `client_ref` and no reply
  matches. `HandleGatewayEvent` re-dispatches the same event after 5, 15, 40 and 60 s
  (`SEND_STATUS_RETRIES`), with the try number in the event array (`send_try`) and without `queued_at`, and
  counts `send_status_retry` per retry. After the last try it counts `send_status_unknown` as before. A repeat
  and an event without a `client_ref` behave as before. No new job property: events queued by the live release
  run as try 0.
- **S3** `php artisan chatbot:heal-send-confirmations [--hours=24] [--apply] [--sleep-ms=500]`. The gateway has
  no read by `client_ref` (`GET /v1/sends/{id}` needs the gateway's own id, which Likha does not keep). The
  command uses the duplicate answer of `POST /v1/pages/{id}/messages`: the same `client_ref` gives 200
  `duplicate: true` with the send's state, and sends nothing. Candidates: gateway replies of the window with
  status `sent`, HTTP 2xx, a `client_ref` and no confirmation. Dry run prints the count only and calls nothing.
  `--apply` posts each candidate (same PSID, messages and `client_ref`, the page's current address and key),
  writes a final `sent` / `failed` / `partial` through `SendStatus::receive` (same write and repeat guard as a
  live event), leaves `queued` / `sending`, prints counts, and stops at once (exit 1) on an answer that is not a
  duplicate or on a 429.
- **S4** not done: the gateway's final state costs one call per reply, so it is not cheaply known. The alarm is
  left as is; S2 removes the cause. After S3 runs, the old unconfirmed rows no longer hold the alarm.

## Risk of S3 `--apply` (owner's call)

If the gateway does not know a `client_ref` that Likha recorded as accepted (a lost gateway row, or the page's
gateway token now belonging to another system, since duplicates are per system), the probe is a real, late
send of the original message to that customer. The command stops at the first such answer, so at most one.

## Case table

| Case | Test | Result |
|---|---|---|
| S-227.2.1 | `test_S_227_2_1_a_send_status_before_its_reply_row_is_applied_when_the_row_appears` | pass |
| S-227.2.2 | `test_S_227_2_2_after_the_last_try_it_is_counted_unknown_and_not_retried_again` | pass |
| S-227.2.3 | `test_S_227_2_3_a_repeat_changes_nothing_and_is_not_retried` | pass |
| S-227.2.4 | `test_S_227_2_4_an_event_without_a_client_ref_and_no_match_is_unknown_at_once` | pass |
| S-227.2.5 | `test_S_227_2_5_the_retry_carries_no_queue_time_and_an_event_from_the_live_release_is_try_zero` | pass |
| S-227.3.1 | `test_S_227_3_1_the_dry_run_counts_the_candidates_and_asks_and_writes_nothing` | pass |
| S-227.3.2 | `test_S_227_3_2_apply_fills_the_final_state_from_the_duplicate_answer_and_leaves_a_send_still_going` | pass |
| S-227.3.3 | `test_S_227_3_3_an_answer_that_is_not_a_duplicate_stops_the_run` | pass |

Red runs: S-227.2.1/2.2/2.5 failed first ("Failed asserting that 1 is identical to 0": counted unknown on the
first miss; no retry pushed); S-227.2.3/2.4 passed first (they pin today's behaviour). S-227.3.* failed first
with `CommandNotFoundException`.

## Full suite (`php artisan test`, Herd PHP 8.4)

| | Failed | Passed |
|---|---|---|
| `b8fc9d63` | 2 | 4371 |
| this branch | 2 | 4379 |

The same 2 known failures both times: `ExampleTest > the application returns a successful response` and
`ItemTableFitScriptTest > every catalog column id has a minimum width…`. The warnings are the worktree having no
environment file (phpdotenv), on every test.

## Story changes

None to the spec's stories; the cases above are new in `qa/stories.md` under "Spec 227".

## Rulings

- Retry delays 5, 15, 40, 60 s (two minutes in all), counted as `send_status_retry`.
- S3 probes only rows the gateway answered with 2xx, uses messaging type UPDATE (ignored for a duplicate),
  is paced at 500 ms per call because the probes share the per-system API limit with live sends, and does
  no network call without `--apply`.
- S4 left out (state not cheap to know).
