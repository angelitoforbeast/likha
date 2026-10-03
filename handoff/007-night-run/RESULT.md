# Result 007: Night run: automatic imports at 1am and 2am, Astra at 3am

Status: **done, waiting for Mira's review of branch `feat/007-night-run`**, cut from `develop` at `91c470a`.
- Spec: `docs/specs/007-night-run.md` (spec review in its §16, Mira's answers in §15a).
- Plan: `docs/plans/007-night-run.md` (T1–T7).
- Mira's "go": **given 2026-10-04**, after she read the spec, the 18 questions and the plan at `a906389`: "Go on the plan, T1 to T7 in order, one handoff." Her answers are amendment 007-1 (`AMENDMENT-1.md`).
- There's no PR, so this file stands in for the PR body. Nothing was pushed, merged, migrated or deployed. Everything ships switched off.

## Summary

The night chain is built and off by default. With the three switches on, the scheduler starts the macro and the
Likha import at two times (01:00 and 02:00), then Astra at 03:00 on every order of yesterday whose STATUS is
blank, one queued job per row on queue `astra`. The top of the AI Checker logs page shows the last 14 nights,
with a red banner when something went wrong.

Three things need your eyes before deploy:

1. **Not seen in a browser.** The page and the settings block are checked by markup tests and `npm run build`
   only; running the app isn't in the allowed commands. The checklist is under "The page".
2. **Only sqlite.** The lock, the row claim and the double-click guard are single conditional statements, but
   their behaviour under real concurrency on MySQL isn't tested here.
3. **Two commands outside the allowlist** were run by developer subagents: a throwaway PHP script that applied
   five text replacements to two files (T4), and test runs piped through `head`/`grep`. I read the T4 diff
   afterwards: only the job and the service changed, and both are in commit `7fbadfb`. Later briefs forbade it.

## Done-when evidence

| Item | Evidence |
|---|---|
| Spec, spec review and plan committed; Mira's "go" recorded | `a906389` (spec with review §16, plan), `c9cc38e` (amendment 007-1 and the go, recorded at the top of this file). |
| `artisan test` passes for the whole suite | On the final tree (`9680ec5` plus this file): `Tests: 1 failed, 3 skipped, 350 passed (3722 assertions)`. The one failure is the old baseline, red on `develop` since before handoff 001: `Tests\Feature\ExampleTest > the application returns a successful response` ("Expected response status code [200] but received 302"). The 3 skipped are the Boardroom live tests. No new failure. `php -l` on all 44 changed PHP files: no errors. `npm run build`: `✓ built in 2.21s`. |
| Every Testing-decisions case has a passing test | Table below. |
| `schedule:list` shows the night entries with the switches on and none with them off | Below the test table. |
| Characterization test passed before and after the refactor | Written against the old code and committed alone as `bde236d` (`5 passed (53 assertions)`). `git diff bde236d..HEAD -- tests/Feature/NightRun/RunRowCharacterizationTest.php` prints nothing. On the final tree: `RunRowCharacterizationTest` 5 passed (53 assertions). |
| Reviewer ran per tier | `skeptic-reviewer`, opus, adversarial depth, on T1, T2, T3, T4, T5 and each fix; T6 at standard depth. Each report has Spec / Correctness / Declined to judge. The two named checks are under "Review findings". |
| "Deploy notes for Mira" | Below, and in `docs/night-run.md`. |
| Branch, commits, clean tree | `feat/007-night-run`, 37 commits on top of `91c470a`, all Conventional Commits, no attribution lines; `git status --short` prints nothing. |

**Testing-decisions cases** (all in `tests/Feature/NightRun/`):

| Case | File › test |
|---|---|
| Selection: yesterday by Manila date across the UTC boundary | `NightAstraSelectionTest` › the tick takes yesterday by the manila date not the servers |
| STATUS NULL, '' and spaces; other dates and statused rows out; oldest first | `NightAstraSelectionTest` › it takes every blank status row of the orders date oldest first |
| Second start while queued or running is skipped | `ImportStartTest` › second macro start is refused while a run is queued or running; `NightImportCommandTest` › macro run is closed only after 15 minutes without progress (the "Skipped: run #N" case) |
| API still 409 with the same JSON | `ImportStartTest` › api start answers with todays json |
| Button message unchanged | `ImportStartTest` › button start redirects with todays message |
| Two starts at the same moment create one run | `ImportStartTest` › macro start while the lock is held creates no run; two macro starts in a row create one run |
| Archived settings excluded on all three paths | `ImportStartTest` › archived macro settings are excluded on button and api; `NightImportCommandTest` › archived macro settings are excluded on the scheduled path |
| Likha import guard | `ImportStartTest` › likha start and single sheet start are refused while a run is running; likha start while the lock is held creates no run |
| Stale run closed then a new one started (amendment 17) | `NightImportCommandTest` › macro run is closed only after 15 minutes without progress; likha run started over two hours ago is closed and a new run starts; `ImportStartTest` › likha run older than two hours is closed at the next start |
| A closed run's job does not import | `ImportStartTest` › macro job leaves a run that is already failed untouched; likha job leaves a run that is already failed untouched; macro job final write applies only while the run is still active |
| Switches off means nothing starts | `NightImportCommandTest` › switch off or bad arguments start nothing and record nothing; `NightAstraStartTest` › with the switch off nothing starts and a waiting step is still closed after its window |
| Invalid time falls back to the default | `NightSettingsTest` › invalid time falls back to the default |
| Astra waits for a running import (macro or Likha, amendment 12), runs after it | `NightAstraStartTest` › it waits while an import runs and starts after it |
| One failed sheet doesn't stop Astra (amendment 16) | `NightAstraStartTest` › a macro import counts only when it started since midnight and ended with a sheet done |
| "Did not run" after 60 minutes | `NightAstraStartTest` › it records did not run 60 minutes after the astra time |
| One run per date | `NightAstraStartTest` › one run per date |
| A row whose STATUS was set meanwhile is skipped and not written | `NightAstraRowJobTest` › a row that must not run is skipped without an engine call; a status set during the call leaves the order as the person left it; `AstraEncoderAdditionsTest` › only when status blank never writes over a status a person set during the call |
| Retry once then failed | `NightAstraRowJobTest` › a transient failure is retried once after 65 seconds and never a third time |
| Breaker at 10 in a row | `NightAstraRowJobTest` › ten failures in a row stop the run and a success resets the count; transient first attempts stop the run only when the streak is 120 seconds old |
| Immediate stop on invalid key or exhausted credit | `NightAstraRowJobTest` › a rejected key or exhausted credit stops the run at once; no api key right before the call fails the row and stops the run |
| Rows left running 10 minutes are failed by the tick | `NightAstraTickTest` › a row left running for over 10 minutes is failed and counted by the breaker |
| No re-queue loop | `NightAstraTickTest` › the tick never requeues a row and dispatches a pending row once; `NightAstraRowJobTest` › a second delivery of the same job makes no second engine call |
| Safety maximum | `NightAstraSelectionTest` › over the safety maximum only the oldest rows get a record |
| Stop time | `NightAstraRowJobTest` › a row is not run when the run stopped or the stop time passed; `NightAstraTickTest` › at the stop time queued rows become not run and the run finishes when the last row ends |
| "Retry failed" takes only failed and not-run rows still blank | `NightRunRoutesTest` › retry failed takes only failed and not run rows that are still blank |
| Every new route is CEO only | `NightRunRoutesTest` › each route is for the ceo only |
| Host scope from the app URL | `NightAstraRowJobTest` › the blacklist scope comes from the app url |
| The log row has source `night` | `NightAstraRowJobTest` › a success writes the result and one night log row |
| Price (amendment 1, replaces the migration test) | `AstraEncoderAdditionsTest` › gpt 6 luna cost is computed from the config price |
| No key fragment in the log (amendment 14) | `AstraEncoderAdditionsTest` › a 401 leaves no part of the key in the log and is remembered as the last error |
| Browser `runRow` same response and log row | `RunRowCharacterizationTest` › five tests (Astra, classic, 404, missing address list, exception) |

**`schedule:list`.** The allowlist has no command that edits settings, so the "on" listing comes from the sqlite
test database: `artisan test --filter=test_schedule_list_has_the_night_imports_only_when_their_switch_is_on`
re-loads `routes/console.php` and prints the real `schedule:list` output (that test sets the import times to
01:15 and 02:45 to prove the times are read from the settings):

```
--- schedule:list, switches on ---
  0  6 * * *  php artisan holds:snapshot ............... Next Due: 38 minutes from now
  15 1 * * *  php artisan night:import macro 1 ......... Next Due: 19 hours from now
  15 1 * * *  php artisan night:import likha 1 ......... Next Due: 19 hours from now
  45 2 * * *  php artisan night:import macro 2 ......... Next Due: 21 hours from now
  45 2 * * *  php artisan night:import likha 2 ......... Next Due: 21 hours from now
  *  * * * *  php artisan night:astra-tick ............. Next Due: 8 seconds from now

--- schedule:list, switches off ---
  0 6 * * *  php artisan holds:snapshot ................ Next Due: 38 minutes from now
```

Plain `php.bat artisan schedule:list` on the local database (nothing saved, so everything off):

```
  0 6 * * *  php artisan holds:snapshot ................ Next Due: 38 minutes from now
```

`artisan route:list --name=night_run` shows the four new routes: `night_run.settings`, `night_run.run_now`,
`night_run.retry_failed`, `night_run.rows`.

## How a night goes

All times Asia/Manila, defaults shown. `night_date` is the date of the morning; Astra's orders are the day before.

| Time | What happens | Rule |
|---|---|---|
| 01:00 | `night:import macro 1`, `night:import likha 1` | Each reads its switch again; off → nothing, no record. One record per night and kind. |
| | Macro: a run with no progress for 15 minutes is closed first | "Stale: closed by the night run", `cancel_requested` set; a run that is progressing is never closed, whatever its age. |
| | Likha: a run started more than 2 hours ago is closed first | Same message. |
| | Then the start | Started → "Done / Failed / Running" read live from the run. An active run → "Skipped: run #N was still running". |
| 02:00 | The same two commands, slot 2 | Same rules. |
| 03:00 | `night:astra-tick` (every minute) looks at tonight | Starts when no import of either kind is active **and** a macro run that started since 00:00 has ended with at least one sheet done. |
| 03:00–04:00 | Not met → "Waiting: …", checked every minute | After 60 minutes → "Did not run: an import was still running (macro)" / "(Likha)" / "no finished macro import since midnight". |
| Start | One transaction: the step goes to running, the blank rows of yesterday are recorded as queued (oldest first, at most the safety maximum), then one job per row goes to queue `astra` | One run per date. No API key → "Stopped: no API key set". No rows → Finished at once. |
| Per row | Claim, re-read the order, run Astra, record | See "Astra night run". |
| Every minute | Rows running over 10 minutes → Failed "Worker stopped"; queued rows not yet dispatched are dispatched once; the run is finished when no row is queued or running | The tick never puts a row back in the queue. |
| 07:00 | Rows not started → "Not run: out of time" | The run then finishes. |
| Late start | Scheduler down at 03:00, back before 07:00 | It starts then if the condition is met. |

## Import guard

- **One start function per kind:** `MacroImportStarter` and `LikhaImportStarter`. The check for an active run and
  the creation of the new one happen inside a cache lock, so two starts at the same moment make one run.
- **Button** (`/macro/gsheet/import`): same redirect and the same messages as before.
- **API** (`POST /api/automation/macro-import`): URL, header and both JSON answers unchanged, 409 included; the
  Google Sheets script needs no change. It now skips archived sheets, as the button always did.
- **Likha** (`start` and the single-sheet start): had no guard; a second start now gets HTTP 409
  `{ok:false, message:"May running import pa (Run #N). Hintayin muna matapos.", run_id}` and the page shows that
  message.
- **Scheduler:** `night:import {macro|likha} {1|2}`, described above.
- **Stale rules built (amendment 17):** macro = no progress for 15 minutes, where progress is the later of the
  run's and its items' `updated_at`. **Likha = 2 hours from the start, not the 30-minute no-progress rule**:
  on a fresh sheet the job reads the whole sheet and processes every row before its first `saveProgress`, so a
  healthy run can go a long time without touching its run or run-sheet rows. Likha closes such a run on every
  path (button too), because it has no Force-stop.
- **What I found about the jobs (asked in the handoff):** neither job writes into another run. The danger was a
  closed run's job importing next to the new run. Macro job: it now does nothing if its run is already closed,
  takes the run to `running` with a conditional write, and its final write applies only while the run is still
  active. Likha job: it returns at the start, and stops before the next sheet, when its run is no longer
  `running`. A sheet being read at the moment of closing still finishes; that limit is the Force-stop button's too.

## Astra night run

- **Selection:** `macro_output` rows with `ts_date` = the orders' date and STATUS NULL, empty or spaces,
  whatever the six fields hold and whether or not an AI tried them; ordered by `TIMESTAMP` then id.
- **Shared code:** what `runRow` did now lives in `AiCheckerRowRunner`; the browser and the night job both call
  it. The night job passes engine `astra`, source `night`, no user (`user_name` "Night run"), `batch_id`
  `night-<orders date>`, and the whole `APP_URL` as the host, so the existing rule in
  `MacroChecker::loadValidationRefs` picks `likha` or `incepxion`.
- **The job** (`RunNightAstraRow`, queue `astra`, timeout 540 s):
  1. Claims the row with one conditional update (queued → running). A job delivered twice makes no second call.
  2. Run stopped → "Not run: run stopped". Past the stop time → "Not run: out of time".
  3. Re-reads the order. STATUS no longer blank → Skipped "Status set by a person", nothing written. No chat
     text → Skipped "No chat text", no call, not a failure.
  4. Runs Astra with a 120 s call timeout. Astra's write to the order is one UPDATE that only matches while
     STATUS is still blank, so a status set during the call is never overwritten.
  5. Records PROCEED or the code, the log row's id, the cost and the duration.
- **Failures:** a timeout, a plain 429 or a 5xx is retried once, 65 s later; a second failure marks the row
  Failed and the order stays blank. A 401 or 403, or a 429 with `insufficient_quota`,
  `billing_hard_limit_reached` or `billing_not_active`, stops the run at once. Reasons are fixed strings; no
  OpenAI text or exception message is stored or shown.
- **Breaker:** ten failures in a row stop the run ("Stopped: 10 rows failed in a row (last: …)"). A first
  attempt that goes to its retry counts too, but such counts stop the run only once the streak is 120 seconds
  old. By the reviewer's arithmetic an outage of fast 5xx answers stops the run at about 2 minutes, and one of
  timeouts at about 20 minutes with two workers.
- **Guards:** safety maximum (default 1,500: the oldest are taken, the rest are shown as "not run: over the
  safety maximum"), stop time (default 07:00), one run per date.
- **Bound on spend without a CEO click:** at most 2 engine runs per row, at most the safety maximum of rows, one
  run per date.
- **Manual (CEO):** "Run now" for a date up to yesterday, and "Retry failed" for a night. Both only ever queue
  rows that are failed or not run and still blank (Run now also adds blank rows not yet in the run); rows Astra
  finished are never run again.
- **Not changed:** Astra's prompts, tools, gates and PROCEED rules; the J&T order code. `AstraEncoder` got three
  opt-in switches the browser never sets, and its two warning lines no longer log OpenAI's response body or an
  exception message (amendment 14).

## The page

`/encoder/checker_1/ai-checker/logs`, new section "Night run" above "Batches":

- Red banner lines for the latest night: a switched-on step that failed, stopped, did not run, was "Done with N
  failed sheets", or left no record ("… has no record: is the scheduler running?").
- One line per night (14): date and "orders of …" · Imports badge with "N of M done" · Astra badge and
  "185 rows · 141 PROCEED · 38 for a person · 6 failed · … skipped · … not run".
- › opens the night: each import with its time, state, processed / inserted / updated and message; failed
  sheets by name; Astra's counts by code, duration, reason, "by schedule" or "by hand".
- CEO only, and left out of the data for everyone else: estimated cost, failed sheets' messages, Show rows
  (each row links to its entry on the AI answers page), Retry failed, Run now.
- `/encoder/checker_1/settings`: a CEO-only "Night run" block with the three switches, four times and the
  safety maximum.

**Browser checklist for Mira** (not done here):
1. CEO, logs page: banner, one line per night; at phone width the line stacks with no horizontal scroll.
2. › expands by click and by keyboard.
3. Show rows lists the rows; "log" opens the answers page for that order.
4. Retry failed and Run now show their confirm, then the one-line message.
5. A Marketing user sees the lines but no cost, buttons, rows or sheet messages.
6. Settings as CEO: save with a bad time → the error shows in the Night run block and an unticked switch stays
   unticked.

## Amendments applied

**Amendment 007-1** (`AMENDMENT-1.md`, saved in `c9cc38e`):

| Item | Built |
|---|---|
| 1 | gpt-6-luna price as one config line, no migration; test "gpt 6 luna cost is computed from the config price". |
| 2, 18 | `lastError()`, `httpTimeout()` (120 s at night), `onlyWhenStatusBlank()`; browser path proven unchanged by the characterization test. |
| 3–7, 9–11, 13, 15 | As the defaults. |
| 8 | "No chat text" rows are skipped, counted, and the night's line adds up. |
| 12 | Astra waits while a macro **or** Likha import is active; reasons end "(macro)" / "(Likha)". |
| 14 | `AstraEncoder::post` logs status and OpenAI's error type and code only; tested with a key-like string in a 401 body. |
| 16 | The condition is "a macro run started since midnight ended with at least one sheet done"; such a run with failed sheets reads "Done with N failed sheets", lists them, and raises the banner. |
| 17 | Macro: no progress for 15 minutes. Likha: 2 hours from the start, with the reason above. |

## Rulings

- Ruling: the price is a config line, not a table — `ai_checker_prices` is a config array read by both engines — a wrong price only skews the estimate.
- Ruling: Likha stale rule is 2 hours from the start — the fresh-sheet branch doesn't touch its rows while it works — a hung Likha run blocks Likha imports and Astra for up to 2 hours.
- Ruling: failed sheets' messages are shown to the CEO only, names to everyone — the messages are raw exception text and the section is visible to Marketing — Marketing must ask the CEO for the reason.
- Ruling: the breaker counts a transient first attempt, but such counts stop the run only after a 120 s streak — counting only final failures let an all-night outage run to 07:00, counting first attempts alone let a 30 s blip stop the night — a real outage runs about 2 minutes (fast errors) to 20 minutes (timeouts) before it stops.
- Ruling: a row killed or swept as "Worker stopped" keeps that state even if its job finishes later — one rule for the row's last write — the night's count can say Failed for an order that is in fact PROCEED; its log entry shows the truth.
- Ruling: `failure_streak_started_at` was added to the steps migration in place — the migration has never been applied anywhere — if some database did run the earlier file, that column is missing there and a new migration is needed.
- Ruling: the night settings save has its own route, error bag and saved note — the existing settings route has no role gate and needs the idle-threshold fields — none.
- Ruling: Run now checks "already running" before the import condition — `start()` would otherwise take over a waiting step — none.
- Ruling: Retry failed with nothing to retry leaves the step as it is — re-opening would wipe a "Stopped" reason for no rows — none.
- Ruling: `night_date` is handled as a `Y-m-d` string with no Eloquent cast — the `date` cast stores a datetime on sqlite and breaks the unique lookup — none on MySQL (DATE column).
- Ruling: worked on the branch in place, no worktree, no push, no PR — handoff constraints and Mira's amendment — none.

## Review findings and TODO.md

| Review | Verdict | Blocker / major | Outcome |
|---|---|---|---|
| Spec | needs revision | 3 majors (timeout never retried; `dispatched_at` on retry; dead Likha run blocks imports) | Fixed in the spec before the plan (§16). |
| T1 | 1 major | A Cancel click on the last sheet left a healthy macro run `running` for good | Fixed `8178090`; re-check: closed. |
| T2 | pass | none | |
| T3 | go | none | Browser path matched line by line; characterization test byte-identical. |
| T4 | 1 major (money guard) | The breaker never tripped during an all-night outage | Fixed `f64b713`; re-check: closed. |
| T5 | 1 major (availability) | That fix let a short blip stop the night | Fixed `5b9ef75` (loop 2); re-check: closed, and the T4 major stays closed. |
| T6 | pass | none | Minors fixed in `e96d4bb`, `a1769ae`. |

**The two checks the handoff names** (T4 review):
- **Re-queue loop: holds.** Three statements can queue or dispatch a row: the start insert, `dispatchPending`
  (only rows never dispatched), and the single transient retry. The reviewer could not build a third engine run
  or an endless dispatch from redelivery, two workers, a killed worker, the sweep racing a live job, a second
  tick or start, or a crash mid-start. Re-checked after T5 for Run now and Retry failed.
- **Never overwrite a person's status: holds.** The only write to `macro_output` reachable from the job is
  Astra's conditional update on blank STATUS; the job re-reads STATUS after every claim, so before the retry too.

**Minors:** the ones on untrusted or money paths were fixed in the final wave (`e96d4bb`, `a1769ae`): the
unticked switch coming back ticked after a refused save, the night line not adding up, long text causing
horizontal scroll, stale row list, doubled errors, the macro job's read-then-write start. The rest are in
`TODO.md` under "Handoff 007", each with its reason (25 entries).

**Declined to judge by the reviewer** (environment facts): the production cache store; `pcntl` on the server;
`DB_QUEUE_RETRY_AFTER` and `APP_URL`; OpenAI's real error codes for the fatal class and whether timed-out calls
are billed; MySQL behaviour of the conditional statements.

## Deploy notes for Mira

Full text in `docs/night-run.md`.

1. **Migrations** (`php artisan migrate --force`): `2026_10_04_100000_create_night_run_steps_table`,
   `2026_10_04_100100_create_night_astra_rows_table`. Two new tables, nothing altered.
2. **`php artisan config:cache`** so the gpt-6-luna price is read.
3. **Supervisor program for queue `astra`** (path, user and log path are placeholders for the server's own):

   ```ini
   [program:likha-astra]
   process_name=%(program_name)s_%(process_num)02d
   command=php /var/www/likha/artisan queue:work database --queue=astra --sleep=3 --tries=3 --timeout=540 --max-time=3600
   directory=/var/www/likha
   numprocs=2
   autostart=true
   autorestart=true
   stopasgroup=true
   killasgroup=true
   stopwaitsecs=600
   user=www-data
   redirect_stderr=true
   stdout_logfile=/var/www/likha/storage/logs/astra-worker.log
   ```

4. **Check on the server:** the cache store is `database`, `file` or `redis` (not `array`); the workers' PHP has
   `pcntl`; `DB_QUEUE_RETRY_AFTER` is above 540; `APP_URL` names the site.
5. **Settings to turn on** (`/encoder/checker_1/settings`, CEO, block "Night run"): night macro import, night
   Likha import, night Astra; times 01:00, 02:00, 03:00; stop time 07:00; safety maximum 1,500.
6. **First night:** the next morning the logs page should show one line: imports Done with counts, Astra
   Finished, and rows found = PROCEED + for a person + failed + skipped + not run. "Waiting for the worker" =
   the `astra` program isn't running. "Did not run" and "Stopped" name their reason. The Google Sheets script
   can keep calling the API: a call during a night import gets the usual 409.
7. incepxion: same code, everything off, nothing changes there until its switches are turned on.

## Proposed tasks

1. Delete the dead `MacroImportController.php` (a second, unguarded macro start with no route).
2. Stop logging response bodies and query messages in the classic engine (`MACRO_CHECKER_*_HTTP`) and in
   `AI_CHECKER_LOG_FAIL`, as Astra now does.
3. One shared blank-STATUS rule and one shared CEO check (four and three copies today).
4. A real-MySQL test job for the lock, the claim and the re-open, since sqlite can't show the races.
5. Remember when each night switch was turned on, so the banner doesn't say "no record" on the first day.
6. A Force-stop button for the Likha import, like the macro one.

**Short summary:** T1–T7 are built on `feat/007-night-run`; suite 350 passed with only the old `ExampleTest`
failure; three review majors found and closed within two loops; the page hasn't been seen in a browser and
nothing has run on MySQL. Everything is off until the CEO turns the switches on.
