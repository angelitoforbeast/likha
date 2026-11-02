# Result: spec 219, a deploy never breaks the jobs already waiting in the queue

**Status:** done after rework (built and tested locally; not pushed, not deployed)
**Date:** 2026-11-03
**Branch / PR:** `fix/218-queued-job-compat`, cut from f5d6d450 (live Likha main; Incepxion 36e36f9c has the same tree). Not pushed, no PR.
**Number:** first written as spec 218; renumbered to 219 because another spec 218 (comment answer once a day, 81d106ac)
was released first. The earlier commit subjects on this branch, and the branch name, keep "218".

## Cause

Laravel's `SerializesModels` writes a job's properties into the queue payload and, on the worker, sets back only the
properties present in the payload (`__unserialize`); the constructor does not run. A typed property declared through
constructor promotion with a constructor default (`public ?string $earlier = null` in the constructor) has no class-level
default, so a payload written before the property existed leaves it uninitialized, and the first read throws. That is what
happened to the 8,213 `RunChatbotSequenceStep` jobs queued by the previous class version.

## Fix (reworked)

The first version gave the properties class-level defaults. Review found that `SerializesModels::__serialize`
(Laravel 12.19.3) leaves out every property equal to its class default, so the new payloads lacked `earlier`, `probe`,
`full` and the rest, and the previous code (still running during the deploy window, or after a rollback) could not read
them. That version is reverted (`5e2533ea`).

Now:
- The job classes keep their promoted properties exactly as on f5d6d450, so every new payload is byte for byte what
  f5d6d450 writes.
- A shared trait, `App\Jobs\Concerns\RestoresMissingDefaults`, wraps `SerializesModels`: its `__unserialize` calls
  `SerializesModels::__unserialize`, then fills every typed property that is still uninitialized from the default of the
  constructor parameter with the same name. An older payload into the new code therefore gets the constructor default,
  exactly as a fresh dispatch; `__serialize` is the untouched `SerializesModels` one.
- The trait replaces `SerializesModels` in the 14 queued jobs that have an optional typed property:
  `RunChatbotSequenceStep` (`$claim`, `$earlier`), `SendOrderToForbeast` (`$probe`), `RunChatblastSetupCheck` (`$by`),
  `RunChatbotContentCheck` (`$page`), `SyncBotcakeCustomersJob` (`$full`), `ImportBotcakePsidFromGoogleSheet`
  (`$cutoffDateTime`, `$runId`, `$cleanSheet`), `ImportMacroFromGoogleSheet`, `ProcessAdsManagerReportsUpload`,
  `ProcessJntCreateOrdersBatch`, `ProcessPancakeConversationsUpload` (`$userId` each), `ProcessPaymentActivityUpload`
  (`$uploadedBy`, `$diskName`, `$uploadLogId`), `ExportJntStatusToGsheet` (`$status`, `$dateRangeRaw`),
  `SyncFromJntStatusByTrack` (`$dryRun`), `SyncJntOrderQueryByDate` (`$page`).
- `HandleComment` is unchanged: it does not use `SerializesModels`, and its only typed property, `$until`, is always set
  in the constructor, so every payload carries it.

## Payload format unchanged: the evidence

`tests/Feature/Chatblast/fixtures/219/payloads.json` was recorded on f5d6d450's job classes: `app/Jobs` was checked out
from f5d6d450 (`git diff f5d6d450 -- app/Jobs` empty) and `QueuedJobPayloadTest` ran with `RECORD_219_PAYLOADS=1`. It
holds `serialize()` of the 15 classes, each built twice (required arguments only, and every argument with non-default
values; 29 payloads), with the clock frozen. That fixture and the reverted classes are one commit (`5e2533ea`); the trait
came after (`cbd1f23c`). `test_S_219_4_every_payload_is_byte_for_byte_what_f5d6d450_writes` compares the branch's
payloads with the recorded bytes: identical for all 29.

## Both directions

- **Old payload into new code:** `test_S_219_1…` unserializes the exact payload that failed in production
  (`O:31:"App\Jobs\RunChatbotSequenceStep":4:{subscriptionId;claim;connection;queue}`) and runs `handle()`: the step hands
  its send off and the message is sent once; `$earlier` reads null. `test_S_219_2…` does the same for
  `ResumeChatbotSequenceSend` and `GatewayAfterSend`.
- **New payload into old code:** the new code writes the f5d6d450 bytes (S-219.4), which f5d6d450 reads by definition;
  `test_S_219_4_a_payload_written_now_is_read_…` also reads every payload back and checks every typed property is set.
- **Rollback is safe:** the payload format is f5d6d450's, so rolling back to f5d6d450 with jobs from this code in the queue
  works. Rolling back to code before 215 is the old situation (payloads with `earlier` are read by a class without it:
  PHP ignores an unknown property, no error).

## Tests

| Case | Test | Before the fix | After |
|---|---|---|---|
| S-219.1 | `QueuedJobCompatTest::test_S_219_1…` (the exact production payload) | fails: `Error: Typed property App\Jobs\RunChatbotSequenceStep::$earlier must not be accessed before initialization` | passes |
| S-219.2 | `test_S_219_2…` (resume and gateway watch jobs) | passes | passes |
| S-219.3 | `test_S_219_3_every_queued_job_with_an_optional_typed_property_restores_missing_defaults` (recursive over `app/Jobs`, subfolders such as `Boardroom` included; the rule: every queued job with an optional typed property uses the trait), `test_S_219_3_the_guard_reads_subfolders` | fails, listing 20 properties in 14 classes | passes |
| S-219.4 | `QueuedJobPayloadTest` (2 tests, 29 payloads) | passes (it is the recorded state) | passes |

The guard's docblock records the known gap: a new **required** constructor parameter on a job that is already queued is not
covered (there is no default to restore); such a change needs a default or a drained queue before the deploy.

### Counts (sqlite)

| | Tests | Assertions | Failures | Skipped |
|---|---|---|---|---|
| f5d6d450 (live main) | 4,169 | 45,427 | 4 | 5 |
| This branch | 4,175 | 45,655 | 4 | 5 |

- **No new failure.** The 4 are the known ones: BigBroadcastTest S-167.1, ExampleTest, ItemTableFitScriptTest,
  AstraAddressRulesTest S-26.18.
- **The 6 new tests pass:** QueuedJobCompatTest 4, QueuedJobPayloadTest 2.
- **Green in the same run:**
  - the 6 Sequence classes (ReRun with 1 MySQL-only skip);
  - the 14 listed suites;
  - ForbeastHoldTest 17;
  - the classes behind the 15 jobs: BotcakePsidBackfill, BotcakePsidImport, BotcakePsidSchedule, BotcakeSyncSchedule,
    BotcakeWindowRead, ChatblastSetupCheck, ChatbotContentCheck, CommentReplies, AdsDataRefusal,
    AdsManagerUploadCharacterization, ImportStart, NightImportCommand, ForbeastOrders, BotcakePanel, SyncAll,
    PaymentDateOrder.

## Deploy notes

- No migration. No supervisor or Horizon change. No config or env change.
- **Safe to deploy with a full queue**, in both directions: jobs queued by f5d6d450 (or earlier) run on this code, and jobs
  queued by this code run on f5d6d450 during the deploy window (workers not yet restarted) or after a rollback.

## Out of scope

The steps that failed on 2026-11-03 are not re-run (their subscriptions have ended; an owner decision). No change to the
queue driver, Horizon, the payload format or any job's behaviour.
