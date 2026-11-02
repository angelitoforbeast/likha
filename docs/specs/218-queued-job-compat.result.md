# Result: spec 218, a deploy never breaks the jobs already waiting in the queue

**Status:** done (built and tested locally; not pushed, not deployed)
**Date:** 2026-11-03
**Branch / PR:** `fix/218-queued-job-compat`, cut from f5d6d450 (live Likha main; Incepxion 36e36f9c has the same tree). Not pushed, no PR.

## Cause

Laravel's `SerializesModels` writes a job's properties into the queue payload and, on the worker, sets back only the
properties present in the payload (`__unserialize`); the constructor does not run. It also leaves out any property whose
value equals its class-level default. A typed property declared through constructor promotion (`public ?string $earlier
= null` in the constructor) has a constructor default but no class-level default, so a payload written before the
property existed leaves it uninitialized, and the first read throws. That is what happened to the 8,213
`RunChatbotSequenceStep` jobs queued by the previous class version.

## Fix

Every optional typed property of a queued job now has a class-level default equal to its old constructor default. The
constructor takes the same parameter (not promoted) and assigns it. An older payload therefore leaves the default, which
the code already reads as "no information" (for the step job: `$earlier = null` means no earlier claim, exactly as a fresh
dispatch). Property names, types, defaults and the payload format are unchanged.

### Properties changed (21, in 15 classes under `app/Jobs`)

| Class | Property | Now |
|---|---|---|
| `RunChatbotSequenceStep` (215) | `$claim`, `$earlier` | `public ?string … = null` |
| `SendOrderToForbeast` (211) | `$probe` | `public bool $probe = false` |
| `RunChatblastSetupCheck` | `$by` | `public ?int $by = null` |
| `RunChatbotContentCheck` | `$page` | `public ?string $page = null` |
| `SyncBotcakeCustomersJob` | `$full` | `public bool $full = false` |
| `HandleComment` | `$until` | `public ?int $until = null`; `retryUntil()` uses 6 hours from now when it is null (what a fresh dispatch sets) |
| `ImportBotcakePsidFromGoogleSheet` | `$cutoffDateTime`, `$runId`, `$cleanSheet` | defaults `null`, `null`, `false` (the constructor defaults) |
| `ImportMacroFromGoogleSheet` | `$userId` | `public ?int $userId = null` |
| `ProcessAdsManagerReportsUpload` | `$userId` | `public ?int $userId = null` |
| `ProcessJntCreateOrdersBatch` | `$userId` | `public ?int $userId = null` |
| `ProcessPancakeConversationsUpload` | `$userId` | `public ?int $userId = null` |
| `ProcessPaymentActivityUpload` | `$uploadedBy`, `$diskName`, `$uploadLogId` | `'system'`, `null`, `null` |
| `ExportJntStatusToGsheet` | `$status`, `$dateRangeRaw` | `'All'`, `''` |
| `SyncFromJntStatusByTrack` | `$dryRun` | `public bool $dryRun = true` |
| `SyncJntOrderQueryByDate` | `$page` | `public ?string $page = null` |

Audited: all 52 `ShouldQueue` classes under `app/Jobs`, including those changed by specs 210, 211, 214, 215 and 216.
`ResumeChatbotSequenceSend` and `GatewayAfterSend` (215) needed no change: their typed properties are required
constructor parameters (present in every payload since the class was first queued) or have class defaults (`$timeout`,
`$tries`, `$uniqueFor`).

## Tests

`tests/Feature/Chatblast/QueuedJobCompatTest.php`:

| Case | Test | On f5d6d450 | After the fix |
|---|---|---|---|
| S-218.1 | `test_S_218_1_a_step_job_queued_by_the_previous_class_version_runs_after_the_deploy`: unserializes the exact old payload `O:31:"App\Jobs\RunChatbotSequenceStep":4:{subscriptionId;claim;connection;queue}` and calls `handle()`; the step hands its send off and is sent once | **fails**: `Error: Typed property App\Jobs\RunChatbotSequenceStep::$earlier must not be accessed before initialization` (the production error) | passes |
| S-218.2 | `test_S_218_2_the_resume_and_gateway_watch_jobs_run_from_their_first_payload_shape` (`ResumeChatbotSequenceSend`, `GatewayAfterSend`) | passes | passes |
| S-218.3 | `test_S_218_3_no_queued_job_has_a_typed_property_an_older_payload_can_leave_uninitialized`: reflection over every `ShouldQueue` class in `app/Jobs`; a typed property must have a class default or be a required constructor parameter of the same name | **fails**, listing the 21 properties above | passes |

The guard is stricter than "set in the constructor": an optional constructor parameter is set in the constructor too, yet
an older payload skips it, which is exactly the incident. A required parameter is allowed because every payload of the
class carries it.

### Counts (sqlite)

| | Tests | Assertions | Failures | Skipped |
|---|---|---|---|---|
| f5d6d450 (live main) | 4,169 | 45,427 | 4 | 5 |
| This branch | 4,172 | 45,441 | 4 | 5 |

- **No new failure.** The 4 are the known ones on f5d6d450: BigBroadcastTest S-167.1, ExampleTest,
  ItemTableFitScriptTest, AstraAddressRulesTest S-26.18.
- **The 3 new tests pass.**
- **Green in the same run:** the 6 Sequence classes (Handoff 11, Safety 28, ReRun 12 with 1 MySQL-only skip,
  Visibility 3, Durability 14, Bench 10), the 14 listed suites, and ForbeastHoldTest 17.

## Deploy notes

- No migration. No supervisor or Horizon change. No config or env change.
- **Safe to deploy with a full queue** (that is the point): jobs queued by the current live code keep running after the
  deploy, and so will jobs queued by this code after any later deploy that adds an optional property (the guard test makes
  that a red test before it ships).
- Rollback note: a payload written by this code leaves out optional properties at their default (Laravel skips
  default-valued properties). Rolling back to code from before this fix, while such jobs are still queued, would hit the
  same error in the older classes (for example `SendOrderToForbeast` with `probe = false`). After this fix is live, roll
  back only to code that includes it, or drain the queue first.

## Out of scope

The steps that failed on 2026-11-03 are not re-run (their subscriptions have ended; an owner decision). No change to the
queue driver, Horizon or job behaviour beyond the defaults.
