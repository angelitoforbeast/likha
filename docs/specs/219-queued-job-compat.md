# Spec 219: a deploy never breaks the jobs already waiting in the queue

**Project:** Likha and Incepxion (one codebase). Laravel, Redis Horizon, PHPUnit.
**Shape:** bug · **Weight:** small · **Risk tier:** medium (background jobs on a trusted path)

## Why

Spec 215 went live on Incepxion at 2026-11-03 01:01 Manila. About 8,760 `RunChatbotSequenceStep` jobs were already
waiting in Redis, serialized by the previous version of the class with four properties: `subscriptionId`, `claim`,
`connection` and `queue`. The new version of the class has one more typed property, `$earlier`, declared through
constructor promotion with no class-level default. Unserializing an old payload does not run the constructor, so
`$earlier` stayed uninitialized, and `handle()` read it: 8,213 jobs failed in 44 s with "Typed property
App\Jobs\RunChatbotSequenceStep::$earlier must not be accessed before initialization".

Nothing was sent twice; 777 subscriptions later ended "outside 24h" because their step did not run in time. The owner
wants this kind of failure gone for every future deploy: a job queued by the previous version of a class must run after a
deploy exactly as a fresh dispatch of the new version would.

## Stories

| Case | Given / When / Then |
|---|---|
| S-219.1 (P1) | Given a `RunChatbotSequenceStep` payload serialized by the previous class version (`subscriptionId`, `claim`, `connection`, `queue` only), When a worker unserializes it after the deploy and runs `handle()`, Then the step runs normally, as a fresh dispatch with no earlier-claim information would |
| S-219.2 (P1) | The same for `ResumeChatbotSequenceSend` and `GatewayAfterSend`: a payload that carries only the properties the class had when it was first queued runs normally |
| S-219.3 (P1) | A guard test fails when any queued job class under `app/Jobs` has a typed property that an older payload can leave uninitialized: a typed property with no class-level default, other than a required (no default) constructor parameter |

## Done when

- A test reproduces the incident with the exact old payload shape and fails on f5d6d450, then passes after the fix.
- Every typed, optional property of a queued job has a class-level default, and the code treats the default as "no
  information" so the job behaves exactly as a fresh dispatch.
- The guard test of S-219.3 covers every `ShouldQueue` class under `app/Jobs`.
- The 6 Sequence classes, the 14 listed suites, ForbeastHoldTest and the full suite pass, with no new failures compared
  with f5d6d450 (4 known failures: BigBroadcast, ExampleTest, ItemTableFit, Astra S-26.18).
- `docs/specs/219-queued-job-compat.result.md` is filled in, with deploy notes: no migration, no supervisor change, safe
  to deploy with a full queue.

## Out of scope

- Re-running the steps that failed on 2026-11-03 (their subscriptions have ended; that is an owner decision).
- Changing the queue driver, Horizon settings, job payload format or any job's behaviour beyond the defaults.
- Jobs outside `app/Jobs`.
