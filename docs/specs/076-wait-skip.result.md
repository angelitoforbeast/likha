# Result 076: "wait for reply" can skip after a set time

Branch `feat/076-wait-skip`, cut from `main` at `2b65a6cd`. Nothing was pushed or deployed, and no real API was called.

## Commits

| Commit | What |
|---|---|
| `e3f8cc5b` | docs: the brief (verbatim), the S-076 stories, the plan |
| `613ddef7` | feat: `skip_after` in the Send config and ports, the `chatbot_waits` table, park and resume in the engine, `ChatbotRunner::skip()`, the tick command and its schedule, the event texts, the editor form |
| `8b82183d` | fix (review loop 1): the tick queues one job per wait on the `chatbot` queue; a reply that can no longer run ends the timer instead of holding the tick; the event shows how long it really waited; a huge amount is refused; a crashed tick holds its lock 5 minutes |
| (this commit) | docs: result, stories, TODO, screenshots |

## The migration

`database/migrations/2026_10_25_100000_create_chatbot_waits_table.php`. It only adds, and is guarded with `hasTable`.

**`chatbot_waits`** columns:
- id;
- `page_id`, `psid`, `flow_id`;
- `node_id` (20);
- `due_at`;
- `dispatched_at` (nullable: when the tick queued it);
- `created_at` (when the run parked).

**Indexes:** UNIQUE(`page_id`, `psid`), INDEX(`due_at`).

Deploy: run it by hand together with the code. Until it runs, waits behave as before, with no timer and no error.

## The scheduler line

In `routes/console.php`:

```php
Schedule::command('chatbot:resume-skipped')
    ->everyMinute()
    ->withoutOverlapping(5);
```

The tick only queues jobs: one `ResumeChatbotWait` per due wait, on the `chatbot` queue. The sending happens there, under the same customer lock as `RunChatbotFlow`.

## How it works

- **Config:**
  - The Send step gets `skip_after: {amount, unit: minutes|hours}`, kept only when wait is on and the amount is 1 or more.
  - More than 23 hours (1380 minutes) is refused with "Skip after can be at most 23 hours." (422 in the editor).
  - When the setting is unset, the key is absent from the config, so old graphs stay identical and spec 075's strict draft-equals-published check is not upset.
  - Ports: `reply` + `skipped` with `skip_after`, `reply` alone without it. `FlowGraph::clean` refuses a `skipped` wire when `skip_after` is not set.
- **Park:** a live run that reaches such a step sets `__wait`, as before, and upserts the customer's row with `due_at = now + skip_after`. That is the customer's message time plus queue lag. Event: "Waiting for reply at step n2 (skips after 2 h)".
  - A wait without `skip_after` removes the customer's row.
  - The Test tab never writes a row.
- **Sync with `__wait`:** `CustomerFields::clear('__wait')` deletes the row. A reply (resume), the skipped resume and "Clear all fields" all go through it.
- **Tick:** due rows, by `due_at`, 500 per batch, at most 50 s. Each row gets `dispatched_at` and one job. A row is queued again only after 5 minutes (a job that was left or lost).
- **The job, `ChatbotRunner::skip()`:**
  1. Takes the customer lock `chatbot-run:page:psid` (held by someone else: try again later).
  2. If an unrun chat of the customer can still run (under 30 minutes old), leaves the row: the reply wins.
  3. Claims the row with one `DELETE … WHERE id = ? AND due_at <= now`, so only one job ever acts.
  4. Checks that `__wait` is still that flow and step (otherwise the wait already ended).
  5. Drops the wait with a "skipped" event, sending nothing, when:
     - an older unrun chat exists ("the customer replied and the reply was not run");
     - the page is Off or has no flow;
     - the bot is paused;
     - the **published** flow changed (another flow, the step removed, or no longer a wait);
     - `skip_after` was removed;
     - the customer's last message is 24 hours old or more ("the 24-hour window has closed").
  6. Otherwise, runs the published graph from the step's `skipped` port. No reply is saved. Events: "Flow … started (no reply in 2 h)" and "No reply in 2 h → skipped at step n2". Both show in the inbox's executed actions, like the other events.
- **When `__wait` stays after a drop:** when the step is still a wait (`skip_after` removed), for a paused bot, a page that is Off or has no flow, and an unrun reply. The customer's next message then still resumes through `reply`. It is cleared when the step or flow is gone, and after the 24-hour guard.
- **Draft/publish:** live runs and the tick read only `chatbot_flows.graph`, the published graph. A draft change does not touch a live wait (tested).

## Case table

`vendor/bin/phpunit tests/Feature/Chatblast/ChatbotWaitSkipTest.php`: 16 tests, 87 assertions, all pass.

| Case | Test | Result |
|---|---|---|
| S-076.1 | `test_S_076_1_a_reply_before_the_deadline_goes_through_reply_and_removes_the_deadline` | ✔ pass |
| S-076.2 | `test_S_076_2_with_no_reply_the_tick_goes_through_skipped_once` | ✔ pass |
| S-076.2 | `test_S_076_2_the_row_is_claimed_once_and_each_wait_is_one_job_on_the_chatbot_queue` | ✔ pass |
| S-076.3 | `test_S_076_3_a_changed_flow_or_page_drops_the_wait` (6 data sets: another flow, step removed, skip_after removed, page Off, no flow, bot paused) | ✔ ×6 pass |
| S-076.3 | `test_S_076_3_a_draft_change_does_not_touch_the_live_wait` (also: the event shows the time really waited after a republish) | ✔ pass |
| S-076.4 | `test_S_076_4_outside_24_hours_nothing_is_sent_and_the_event_is_logged` | ✔ pass |
| S-076.5 | `test_S_076_5_skip_after_and_the_skipped_port_are_validated` | ✔ pass |
| S-076.6 | `test_S_076_6_a_reply_waiting_in_the_queue_wins_over_the_tick` | ✔ pass |
| S-076.6 | `test_S_076_6_a_reply_that_can_no_longer_run_ends_the_timer_without_a_send` | ✔ pass |
| S-076.7 | `test_S_076_7_the_timer_follows_wait` | ✔ pass |
| S-076.8 | `test_S_076_8_the_editor_saves_and_loads_the_setting` + browser check | ✔ pass |

The brief's Done-when tests map to the cases like this:
- a reply before the deadline goes through `reply` and removes the deadline: S-076.1;
- with no reply, the tick goes through `skipped`, once only, even with two ticks running: S-076.2, both tests (two ticks, one job per wait, the claim);
- a changed flow or a removed step drops the wait: S-076.3;
- outside 24 hours nothing is sent and the event is logged: S-076.4;
- the ports and the 23-hour limit are validated: S-076.5;
- the editor saves and loads the setting: S-076.8.

Red runs:
- **All 14 first tests:** `FileNotFoundException: File does not exist at path …/2026_10_25_100000_create_chatbot_waits_table.php` (the migration about to be written).
- **S-076.8, after the backend and before the form:** `Failed asserting that '<!DOCTYPE html>…' contains "Skip if no reply after"`.
- **Two test mistakes, fixed in the tests:**
  - page Off is a system setting: `chatbot.active is written by the system`;
  - `+=` kept the existing `wait => false`.
- **Review loop 1:**
  - S-076.5: `TypeError: FlowGraph::skipMinutes(): Return value must be of type int, float returned` (the huge amount).
  - S-076.2 (new test): `The expected [App\Jobs\ResumeChatbotWait] job was not pushed.`
  - S-076.3 (draft test): the event said "No reply in 5 h" instead of "2 h".
  - S-076.6 (new test): `Failed asserting that 1 is identical to 0.` (an old unrun reply held the row).

**Suites:**
- `vendor/bin/phpunit tests/Feature/Chatblast`: 391 tests, 2548 assertions, all pass (PHPUnit deprecations only).
- Full suite: 1976 tests, 1 error and 1 failure, both the known ones: `ExampleTest::test_the_application_returns_a_successful_response` and `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. No new failures.
- I used `vendor/bin/phpunit` rather than `php artisan test`: this worktree has no environment file, and one must not be created.

## Review

`skeptic-reviewer` ran at standard depth with extra care on concurrency, on `e3f8cc5b..613ddef7`, then re-checked the fix (`613ddef7..8b82183d`). The re-check found it **clean**.

| Finding | Severity | Outcome |
|---|---|---|
| Rows held by an unrun chat that can never run (the job's 30-minute retry limit, a lost dispatch) stayed due forever; 500 of them would stop every skip on every page | major | fixed: only chats under 30 minutes old hold a wait; an older unrun reply ends the timer with an event and keeps `__wait` |
| Skips ran one at a time inside the scheduler process (synchronous sends, AI steps), which at volume delays them and the other scheduled commands | major | fixed: the tick queues one job per wait on the `chatbot` queue (`dispatched_at`, re-queued after 5 minutes) |
| "Once only, even with two ticks" was not really tested: the claim was never reached | major | fixed: a test that reaches the claim (`'gone'`, no send), plus one job per wait across two ticks |
| A huge `skip_after` amount gave a TypeError (500) instead of 422 | minor (untrusted) | fixed |
| Bare `withoutOverlapping()` holds a 24-hour lock after a crash | minor | fixed (5 minutes) |
| The skip label came from the current published graph, not the time really waited | minor | fixed (from the row) |
| `__wait` staying was not asserted in the drop cases; `array $graph = null` deprecation | minor | fixed |
| An AI step on the skipped branch sees the last message twice; an unreachable drop wording; the editor's max is a hint; queue churn while the worker is down | minor | accepted, TODO.md |

## Browser check

Headless Chrome over CDP against the scratch app, as in spec 075. Script outside the repo: `%TEMP%\likha075\check076.mjs`. Replies off, fake tokens.

| Check | Result |
|---|---|
| Send form | "Skip if no reply after [amount] [minutes / hours]" under the wait box, with the hint "Only with "Wait for the reply". Empty = wait forever. Up to 23 hours; then it exits on "skipped"." ✔ |
| Wait on + 2 hours, Done | the step shows the ports `reply` and `skipped (no reply in time)`; summary "⏳ waits for reply, skips after 2 h" ✔ |
| Wire `skipped` → End (real drag), autosave | "Draft saved 13:47" ✔ |
| Reload | `skip_after {amount: 2, unit: hours}` and the `skipped` wire come back; the form shows 2 / hours (input max 23) ✔ |
| 24 hours | "Not saved: Skip after can be at most 23 hours." ✔ |
| 30 minutes | input max 1380; saved; "skips after 30 min" ✔ |
| Phone 390 × 844, the form open | scrollWidth 390 = viewport 390; the amount field fits ✔ |
| Console | only the 422 network line from the 24-hour step, which is expected ✔ |

Screenshots in `docs/specs/076-wait-skip/`:
- `01-send-form-skip-after.png`
- `02-skipped-port-wired.png`
- `03-over-23-hours-refused.png`
- `04-phone-send-form.png`

## Story changes

- New section S-076 (S-076.1 to .8), drafted from the brief's decisions in the plan commit.
- S-076.2: one job per wait on the `chatbot` queue; a customer whose lock is held is tried again after 5 minutes (the review moved the work to the queue).
- S-076.6: a reply that can no longer run (over 30 minutes old) ends the timer with an event and keeps `__wait`, without holding up other customers (review finding).

## What the brief got wrong, or left open

- **Deadline:** "last customer message time + skip_after" became *park time* + `skip_after`. The run is started by the customer's message, so the difference is queue lag. After a skipped branch parks at another wait, there is no new message, and the 24-hour guard covers that case.
- **Columns:** the table has two columns beyond the brief's list. `dispatched_at` lets the tick hand work to the queue without queuing a row twice. `created_at` gives the event the time really waited.
- **24-hour guard:** it also ends `__wait`, so a customer who comes back days later starts from Start.
- **Paused bot:** the brief didn't say. A paused bot drops the timer and keeps `__wait`.
- **The tick only queues work:** the brief said the tick "resumes each one". At about 1M chats/day, the review showed that sending inside the scheduler would back up, so the tick queues one job per wait on the `chatbot` queue, under the same lock.
- **Deploy:** the scheduler and the `chatbot` queue worker must both run (see TODO.md).
- **Process:** the plan went into `docs/plans/076-wait-skip.md` and I went ahead without a separate "go", as in spec 075: this session can't wait for a reply, and the brief fixed the design.
