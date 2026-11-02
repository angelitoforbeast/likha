# Spec 215: sequence sends don't hold a worker while waiting on the send API

**Project:** Likha (likhaaitech.com) and Incepxion (incepxion.pro), one codebase. Laravel, MySQL, Redis Horizon, PHPUnit.
**Shape:** change · **Weight:** architectural · **Risk tier:** high (live customer messaging on every page)

## Why
On 2026-11-01, Incepxion's sequence follow-ups ran late from 13:00. Average lateness was 254 s at 14:00, 897 s at 19:00 and 2,387 s at 22:00, with a max of about 76 min. Throughput sat flat at about 14,700 sends an hour.
- Each `RunChatbotSequenceStep` sends synchronously inside the Horizon worker, about 2.9 s a job waiting on the API.
- `BotcakeSendQueue::async()` only hands off runs with `startedBy === null`, and sequence runs set startedBy.
- 12 workers ÷ 2.9 s ≈ 4.1 jobs a second. Memory allows only about 18 workers (16 GB, no swap), so more workers is not the fix.
- Likha (Forbeast gateway pages) is on time at half the volume, but its gateway send is also an inline POST.

The owner wants the code faster, with **one behaviour for every provider**: a sequence step hands its send off and never holds a worker while waiting. There is no special rule per provider; the slow path is removed.

## Decisions already made (don't reopen unless something is actually broken)
| Decision | Who and why |
|---|---|
| One behaviour for all providers (Botcake-direct and the Forbeast gateway): sequence sends don't wait inside the worker. | Owner, 2026-11-02: no rule like "if Botcake do this, otherwise not". |
| Never send a step twice. When unsure whether a call went out (e.g. the pool was killed after the call), record it failed and advance, as today. | Owner, 2026-11-02: a duplicate promo annoys the customer and can get the page restricted. |
| Failure semantics stay as today's inline sequence path: the same reply rows, error text and raw body; #551 and other 4xx not retried; the step advances as today (S-070.5d, S-095.2, S-168.4). No new retries that could double a message. | Reviewer, follows from the line above. |
| The pointer advances only when the send result is known, never at hand-off. While a step's send is in flight, the tick must not claim or dispatch that subscription again, however long the queue. | Reviewer: correctness first. |
| Every send-time check stays at send time: bot paused, page off, sequence paused, 24 h window, exit on reply. A customer reply before the call goes out stops it; after the call it is logged as sent. | Reviewer. |
| If the hand-off path is unavailable (pool not alive, feature flag off), the step sends inline as today. Nothing is lost or stuck. | Reviewer. |
| Customer first replies (chatbot-high) must not slow down. Sequence result or resume work must not queue ahead of first replies on chatbot-high. | Reviewer. |
| A dead job's subscription (no job queued, no send in flight, stale dispatched_at) is picked up again within about 2 minutes, not 10. Fix the `runStep` `gone` return that leaves the row stuck. | Reviewer: removes the 10-minute amplifier. |
| The per-page rate (`services.botcake.rate_per_page`, shared with inbox and manual sends) and the customer-ordering rule still hold. | Reviewer. |
| A switch to turn the hand-off off for sequences without a deploy (config flag, default on), so the old inline path can come back instantly. | Reviewer: rollback. |

## Design: your call, written in docs/plans/215-sequence-sends-no-wait.md before building
Known code facts (verify them):
- `ChatbotSequences::tick()` and `runStep`, with BATCH 500, SECONDS 50 and REDISPATCH_MINUTES 10.
- `RunChatbotSequenceStep` has timeout 280 and ShouldBeUniqueUntilProcessing.
- `FlowEngine::send` (about line 277) and `afterSend` (about line 371) hold the rule that a step does not park a wait.
- `BotcakeSendQueue::async()` and `BotcakeSendPool` (send_concurrency 100, `recover()`, per-customer `claim()`).
- `ChatbotRunner::resume` (about line 250) rebuilds a FlowRun WITHOUT startedBy, sequenceId or sequenceStep. A naive flip of async() would lose the sequence id and step on reply rows and tags (S-100.2), the UPDATE messaging type, the no-wait rule, the `advance()` call and the `ran` event.
- `GatewayProvider::send` is a synchronous POST with no retry and dedupes by client_ref. It calls the ForbeastWatch hooks, which must still be called.

Options include:
- (a) reuse the send pool, carrying the sequence context in the ticket, with resumes on a queue that isn't chatbot-high
- (b) a non-blocking path inside the job that sends many steps concurrently

Pick one, justify it against the decisions, and list the risks and the files you will touch.

## Stories
Use the qa draft below, case ids S-215.x, S-215b.x … S-215f.x. You may correct a case when the code shows it is wrong, and list it under "Story changes" in the result.

- **S-215 Hand-off.** Sends don't hold a worker.
  - (1) 4 workers, 300 due steps on 20 pages, fake API at 2.9 s: peak in flight at least 40, all sent within 60 virtual seconds.
  - (2) handle() ends in under 0.5 s.
  - (3) Success advances as advance() does today.
  - (4) #551/4xx: logged failed, same text, no retry, advances.
  - (5) 5xx/HTTP 0 behaves as today's inline path, exactly one row per attempt.
  - (6) A multi-send step keeps its order; a failed send doesn't stop the next send; sequence id and step on every row.
  - (7) "Wait for reply" in a step doesn't park.
  - (8) AI or Sheet nodes don't run twice.
  - (9) Non-ASCII, 2,000-character and image messages are byte-identical.
  - (10) Flag off or pool down: inline as before.
  - (11) Inbox, Replies and /chatbot/speed show the same fields (via, sequence id and step, tags, UPDATE type, due).
- **S-215b Safety under load.**
  - (1) Steps 1, 2, 3 go in order, once each.
  - (2) A step in flight gets no second job or ticket.
  - (3) A 12-minute backlog is not re-claimed.
  - (4) A sequence ticket and a reply ticket for the same customer go one at a time, and a customer reply isn't held behind more than one sequence send.
  - (5) Exit on reply while in flight: no later step, no double send.
  - (6) Pause, page off or 24 h window closed: checked at send time.
  - (7) A page move while in flight: no double send.
  - (8) Lock taken: retry in 1 minute as today.
- **S-215c Restarts.**
  - (1) A clean pool exit drains in-flight sends; each advances once.
  - (2) Killed after the call: no resend, recorded failed, not stuck.
  - (3) Killed before the call: back to pending, sent once.
  - (4) A worker restart between hand-off and result still advances once; a duplicate resume does nothing.
  - (5) Pool down: inline fallback.
  - (6) A terminate and a pool restart together: rows plus failures equal steps due.
- **S-215d Stale claim.**
  - (1) A dead job is re-picked within about 2 minutes and sent once.
  - (2) A queued job's row is not re-stamped.
  - (3) A stale job after a newer claim never leaves a stuck row.
  - (4) 2,000 due at once: no row hidden, tick within 50 s.
- **S-215e Providers and other traffic.**
  - (1) A Botcake page step goes via Botcake.
  - (2) A Forbeast gateway page step goes via the gateway with UPDATE and client_ref, and the ForbeastWatch hooks are still called.
  - (3) A gateway that fails or isn't set up: logged failed, no worker held.
  - (4) A provider switch mid-flight: exactly one provider, once.
  - (5) The per-page rate holds; a 429 backs off that page only.
  - (6) Sequence and inbox sends share the rate.
  - (7) 2,000 steps in flight don't slow a first reply on chatbot-high (same fixture versus the old code).
  - (8) Chatblast unchanged and not starved either way.
  - (9) A full pool means pending, no failure.
- **S-215f Visibility.**
  - (1) /chatbot/speed Sequences shows average, p90 and max lateness per hour (S-182.11 and S-185.5 still pass).
  - (2) A read-only artisan command shows per hour: steps sent, due but unsent, oldest overdue, average lateness, and pool pending.
  - (3) Lateness is measured due → sent at Meta, not due → hand-off.
  - (4) An empty hour shows blank, no error.

## Threat model
- Untrusted: message content and API responses (Meta, Botcake, gateway).
- Trusted: page settings written by staff, config.
- The risk is operational: duplicate or lost customer messages, and slowed first replies. Hence the high tier and the tests above.

## Done when
- The plan is in docs/plans and accepted by the reviewer before the build.
- Every S-215 P1 case has a test that passes.
- A throughput bench test with the fake handler and clock: 600 due steps on 40 pages with 4 simulated workers at 2.9 s latency finishes within 60 virtual seconds, with peak in flight at least 40 and no page over its rate. The same fixture through the old inline path is slower than the bound, as the control.
- The existing suites pass: ChatbotSequenceEngineTest, ChatbotPriorityTest, BotcakeAsyncSendTest, FailedSendKeepsConversationTest, ChatbotSequencePagesTest, ChatbotAfterOrderSequenceTest, GatewaySitePagesTest, GatewayTypingTest, ForbeastAutoSwitchTest, ChatbotTagsTest, ChatbotViaTest, RealSendTimeTest, BotcakeFailedReasonsTest, QueueRedisHorizonTest.
- The full `php artisan test` adds no new failures compared with the base commit. Run it on the base first and record the counts.
- The flag turns the hand-off off, tested.
- `docs/specs/215-sequence-sends-no-wait.result.md` is filled in: status, what changed per file, the test counts on base and on head, story changes, the new config keys and their defaults, the owner checks (after release: Incepxion average lateness under 60 s at 19:00 and 22:00 and max under 5 min, for 2 days), and open risks.

## Out of scope
- ForbeastWatch.php
- chatblast logic
- the Horizon worker counts or env values
- Forbeast's own app
- any UI beyond the read-only visibility in S-215f

## Budget
Large job. 2 attempts per failing step, then stop and report what you tried.
