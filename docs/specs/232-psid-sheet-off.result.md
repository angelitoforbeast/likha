# Result 232: stop the hourly Botcake PSID sheet import

## Status

Done, not pushed, not deployed. No migration. Branch `fix/232-psid-sheet-off` from `088351ff`.
All 3 cases pass; the full suite has no new failure against `088351ff` (see Checks).
Low tier, so no reviewer pass: lint and tests were run by the main session. Browser check skipped: the only
screen change is one help sentence, and this session has no browser tools.

Merge danger: a two-way door. Blast radius: the scheduler only; after deploy, `schedule:run` no longer queues
`botcake:psid-import` at :00 or at 03:00. Nothing is migrated or deleted. Revert: revert the commit; the next
`schedule:run` picks the entries up again.

## Plan

One low slice, no stop for a plan (as the start asked):

1. Tests first in `tests/Feature/Botcake/BotcakePsidScheduleTest.php`, one per case: S-232.1.1 red on today's
   schedule; S-232.1.2 and .3 pin today's manual paths (they pass before and after).
2. Remove both `Schedule::command('botcake:psid-import' …)` entries and their three-line comment from
   `routes/console.php`; leave one comment line saying the import is manual only and why.
3. Stories into `qa/stories.md`, slice 232; full suite once at the end.

## Summary

- `routes/console.php` (−12, +1): the hourly entry (`0 0-2,4-23 * * *`), the 03:00 `--clean-sheet` entry and
  their comment are gone. In their place: `// Botcake PSID sheet import: manual na lang (button o
  botcake:psid-import), dahil galing na sa chats at Botcake API ang PSID.` The command, the job, the button
  (`BotcakePsidGsheetController`), `botcake_psids` and the API sync (`botcake:sync-customers`) are untouched.
- `tests/Feature/Botcake/BotcakePsidScheduleTest.php`: the **existing test that expected the schedule entries**,
  `test_hourly_import_and_the_0300_clean_then_import_are_scheduled_in_manila_time`, is replaced by
  `test_S_232_1_1_…` (no `botcake:psid-import` event; `botcake:sync-customers` and `botcake:prune-chats` still
  scheduled). The existing `test_command_creates_a_run_and_queues_the_import` is renamed to
  `test_S_232_1_3_the_command_run_by_hand_creates_a_run_and_queues_the_import`, body unchanged (it already proves
  the case; a second test would duplicate it). New: `test_S_232_1_2_the_manual_import_button_still_queues_the_job`
  (no test covered the button before): signed-in user, `allowed_ip` skipped as in `BotcakeApiTestCase`, POST
  `botcake.psid.import.run`, asserts a `running` run and the job queued on `psid` with that run id.
- `resources/views/botcake/psid/import.blade.php` (one sentence): the help list on the import page said
  "Kusa itong tumatakbo kada oras; 03:00 binubura sa sheet ang mga row na naitala na." That stops being true with
  this change, so it now reads "Hindi na ito kusang tumatakbo; sa button na lang ito." (Docs travel with the
  change.) The button and the form are unchanged.
- `qa/stories.md`: slice 232 with the case table.

## Checks (command and output)

PHP is `"/c/Users/Forbeast/.config/herd/bin/php.bat"`, shortened to `php` here.

Red run (tests written, `routes/console.php` not yet changed), `php vendor/bin/phpunit tests/Feature/Botcake/BotcakePsidScheduleTest.php`:

```
F..                                                                 3 / 3 (100%)
1) Tests\Feature\Botcake\BotcakePsidScheduleTest::test_S_232_1_1_the_sheet_import_is_not_scheduled_and_the_api_sync_and_chat_prune_still_are
Failed asserting that true is false.
.../BotcakePsidScheduleTest.php:21
```

(Line 21 is the "no `botcake:psid-import`" assertion. The first try of S-232.1.2 failed on `sole()` because the
route sits behind `auth` and `allowed_ip`; the test now signs a user in and skips `allowed_ip`.)

Lint: `php -l routes/console.php` and `php -l tests/Feature/Botcake/BotcakePsidScheduleTest.php` →
`No syntax errors detected`.

Botcake tests after the change, `php artisan test tests/Feature/Botcake`:

```
  Tests:    96 warnings (399 assertions)
```

Every `!` warning is `file_get_contents(...\.env): Failed to open stream` (the worktree has no env file); plain
`vendor/bin/phpunit` shows none. Warnings are not failures.

`php artisan schedule:list` itself could not be run here: it needs the local database (`connection to server at
"127.0.0.1", port 5432 failed`). S-232.1.1 checks the same list: it calls `schedule:list` in the test app, which
loads `routes/console.php`, and reads the scheduled events.

### Case table

| Case | Test | Result |
|---|---|---|
| S-232.1.1 | `test_S_232_1_1_the_sheet_import_is_not_scheduled_and_the_api_sync_and_chat_prune_still_are` | pass |
| S-232.1.2 | `test_S_232_1_2_the_manual_import_button_still_queues_the_job` | pass |
| S-232.1.3 | `test_S_232_1_3_the_command_run_by_hand_creates_a_run_and_queues_the_import` | pass |

### Full suite, `php artisan test`

Branch at `c4b23ece` (the code commit; the blade sentence, stories and this result were edited while it ran and
no test reads them):

```
   FAILED  Tests\Feature\ExampleTest > the application returns a successful response
   FAILED  Tests\Feature\Item\ItemTableFitScriptTest > every catalog column id has a minimum width and a place in th…
  Tests:    2 failed, 4296 warnings, 206 passed (77461 assertions)
  Duration: 677.65s
```

Baseline: the suite was not rerun on `088351ff` itself. Its code is the same as `a09e974e` (the commits after it are
docs only), whose full run is recorded in `docs/specs/230-cpp-sort.result.md`: `3 failed, 4294 warnings, 206
passed`, 4503 tests, failing `ExampleTest`, `ItemTableFitScriptTest` and the time-limited `AstraAddressRulesTest`
S-26.18. Here: 4504 tests (+1, the new S-232.1.2; the old schedule test was replaced one for one, and the
command test only renamed), and only the two known pre-existing failures. The time-limited `BigBroadcastTest` and
`AstraAddressRulesTest` S-26.18 both passed in this run. No new failure.

## Story changes

None. The three cases are in `qa/stories.md` as the spec gives them, with Given / When / Then filled in
(S-232.1.3 runs the command with `--clean-sheet`, the variant the old 03:00 entry used).

## Open questions

None blocking.

- After deploy, the scheduler stops queueing the import; a run already queued or running finishes as before.
  Nothing on the servers needs a manual step (no migration, no cron change).
- The spec's done-when list has no boxes to tick in a committed file (the spec is never edited); all three items
  are met, with the evidence above and in `qa/stories.md`.

## Proposed tasks

- For the owner: the "PHONE NUMBER BOTCAKE" sheet setting, the button and `botcake:psid-import` stay (out of
  scope here). If nobody presses the button for a few weeks, they could go in a later spec.
- For the owner (unchanged from spec 230): `ExampleTest` and `ItemTableFitScriptTest` fail on the baseline
  already, and `BigBroadcastTest` S-167.1 and `AstraAddressRulesTest` S-26.18 assert wall-clock limits that a busy
  machine misses.
