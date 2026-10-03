# Spec 007: Night run (imports at 01:00 and 02:00, Astra at 03:00 on yesterday's blank orders)

Handoff: `handoff/007-night-run/HANDOFF.md`. Base `develop` at `91c470a`, branch `feat/007-night-run`.
Risk tier: **high** (spends money on OpenAI and sets order statuses with nobody watching).
Sides: backend (most of it) and frontend (settings block, "Night run" section).

## 1. Threat model

- **Untrusted:** everything in `macro_output` (names, addresses, chat text), Google Sheets contents, OpenAI
  responses and error bodies, and anything a non-CEO user sends to the new routes (dates, step ids, form fields).
- **Trusted:** the repo, `app_settings` written by the CEO, environment and config, Mira's and Busing's inputs.
- **Secrets:** the OpenAI key (settings page, encrypted, or environment) and `AUTOMATION_KEY`. Nothing new logs
  or shows them. Reasons shown on the page are built from **fixed strings plus an HTTP status or an OpenAI error
  code**; no OpenAI response body and no exception message reaches the page or the night tables (an OpenAI
  "invalid key" message contains part of the key). For imports the page shows only the run-level message, never
  the per-sheet messages (those are raw exception text). One existing leak is left as it is and raised in
  question 14: `AstraEncoder::post` already writes 500 characters of an error body to the Laravel log
  (`AstraEncoder.php:534`).
- **Money invariant (the reviewer's main target):** the engine is called for a row only after an atomic claim
  `queued → running` succeeds. A row goes back to `queued` only (a) once, for a transient failure, when its
  `attempts` is 1, or (b) by a CEO click (Retry failed, Run now). Nothing scheduled ever moves a row back to
  `queued`. So without a click: at most 2 engine calls per row per run, at most `max rows` rows per run, one run
  per date.
- **Data invariant:** no gate, prompt, tool or PROCEED rule changes. A night job writes nothing to a row whose
  STATUS is not blank, checked before the call and again at the write (§6.4).
- No new power for the AI: the job passes the same inputs to the same `processRow`; AI text is never used as a
  command, a query fragment or a reason shown raw.

## 2. What exists today (read 2026-10-04)

| Thing | Where | Finding |
|---|---|---|
| Macro import, button | `MacroGsheetController::import` `:58-115` | read-then-create guard, `is_archived = false`, message "May running import pa (Run #N). Hintayin muna matapos." |
| Macro import, API | `AutomationController::macroImport` | same non-atomic guard, **all** settings (archived too), 409 JSON `{ok:false, message:"May running import pa (Run #N).", run_id}`, success JSON `{ok, message:'Import started', run_id, status_url}`, run message "Triggered via n8n" |
| Macro job | `app/Jobs/ImportMacroFromGoogleSheet.php` | `tries 1`, `timeout 3600`. At start it sets `status = running` **unconditionally**. Between sheets it reads `cancel_requested` and stops. At the end it writes `done`/`failed` unconditionally. It only ever writes its own run id. |
| Likha import | `LikhaOrderImportController::start`, `startOne` | no guard at all; run is created as `running`; both return `{ok:true, run_id}`; the page's script shows "Failed to start import" when `ok` is false |
| Likha job | `app/Jobs/ImportLikhaFromGoogleSheet.php` | `tries 1`, `timeout 3600`. No cancel check anywhere. Ends with `status = done` unconditionally. Only writes its own run id. It also creates and updates `macro_output` rows. |
| Astra, browser | `MacroCheckerController::runRow` `:179-260`, `writeLog` `:367`, `logDetail` `:266` | one row per request; log row has `user_id`, `user_name`, `source` (`single`/`batch`) |
| Astra engine | `AstraEncoder::processRow`, `post` `:527` | **never throws on an OpenAI error**: `post` logs a warning, retries once after 1.2 s on 429/5xx/exception, then returns null, and `processRow` returns `status = failed`, message "Astra: walang sagot mula sa AI". The HTTP status is lost. Uses the `Http` facade. |
| Host scope rule | `MacroChecker::loadValidationRefs()` `:1132-1141` and `PhoneWhitelist::phonesForHost` | scope = `incepxion` when the host string contains "incepxion", else `likha`. The host string comes in through `processRow(..., $host)`. |
| Log table | `ai_checker_logs` | `user_id` unsignedBigInteger **nullable**, `user_name` nullable, `source` `string(10)` (so `night` fits), `batch_id` `string(40)` |
| Prices | `config/services.php:61` `services.openai.ai_checker_prices` | **a config array, not a table.** There is no `ai_checker_prices` table. `gpt-6-luna` is missing, so its token cost is counted as 0. |
| Scheduler | `routes/console.php` | one entry, time read from `app_settings` inside try/catch with `Schema::hasTable` |
| Queue | `config/queue.php` | database connection, `retry_after` 1900 s by default |
| Settings page | `Checker1SettingsController` | `POST /encoder/checker_1/settings` has **no role gate** and requires the idle-threshold fields; CEO parts are checked inside |
| Tests | `tests/Feature/Item/ItemTestCase.php` | sqlite in memory, tables created by hand per test case, no macro/Astra tests |

**Stale-run finding (asked for in the handoff).** Neither job writes into another run: both use only their own
run id. The real danger is different: a job that is still alive, or still waiting in the queue, **imports at the
same time as the new run**.
- Macro: a job picked up after its run was closed sets the run back to `running` and imports. A job that is
  mid-way stops only if `cancel_requested` is set, and only between sheets; its last write flips the closed run
  to `done`.
- Likha: a job never notices anything; it runs to the end and writes `done`.

So closing a stale run needs three small changes in the jobs (§4.3). A sheet that is being read at the moment of
closing still finishes; the job stops before the next sheet. That is the same limit the Force-stop button has.

## 3. Settings (`app_settings`, CEO only)

| Key | Default | Rule |
|---|---|---|
| `night_macro_import_enabled` | off | `'1'` = on, anything else off |
| `night_likha_import_enabled` | off | same |
| `night_astra_enabled` | off | same |
| `night_import_time_1` | `01:00` | `^([01]\d|2[0-3]):[0-5]\d$`, else the default |
| `night_import_time_2` | `02:00` | same |
| `night_astra_time` | `03:00` | same |
| `night_astra_stop_time` | `07:00` | same |
| `night_astra_max_rows` | `1500` | integer 1 to 20000, else the default |

The Astra time must be earlier than the stop time: the save refuses otherwise, and the reader falls back to
both defaults when it reads such a pair.

One reader, `App\Support\NightRunSettings`, returns validated values with defaults. `routes/console.php`, the
commands and the controllers all use it. When the database isn't reachable it returns everything off (the
`hold_snapshot_time` try/catch pattern), so nothing is scheduled.

Saved by a **new** route `POST /encoder/checker_1/settings/night` (CEO only, 403 otherwise), from a CEO-only
block on the settings page. The existing settings form and route aren't touched.

## 4. Imports

### 4.1 Start services

`App\Services\Imports\MacroImportStarter::start(?int $userId, ?string $message): array` and
`App\Services\Imports\LikhaImportStarter::start(?LikhaOrderSetting $only = null): array`. Each returns
`['started' => bool, 'run' => model|null]`.

- The check and the create happen inside `Cache::lock('import-start:macro' | 'import-start:likha', 30)`,
  taken with `block(5)`. Inside the lock: an active run (macro `queued`/`running`, Likha `running`) → return
  `started = false` with that run; else create the run and its items, dispatch the job, return `started = true`.
  If the lock isn't obtained in 5 s, return `started = false` with the active run if one can be read, else null.
  With no run to name: the API answers 409 `{ok:false, message:"May running import pa.", run_id:null}`, the
  button says "May running import pa. Hintayin muna matapos.", the scheduler records "Skipped: another start
  was in progress". The wait is a property of the starter so tests don't sleep.
- **Likha only, on every path:** before the check, a `running` Likha run older than 120 minutes is closed
  (`failed`, "Stale: closed at the next start"; the scheduler's wording stays "Stale: closed by the night
  run"). The Likha job has no `failed()` hook and no Force-stop button, so without this one dead run would
  refuse every Likha import for good once the guard exists (question 15).
- Macro takes `MacroGsheetSetting::where('is_archived', false)` on every path; `total_settings` counts the same.
- Likha takes `is_archived = false` (as today); with `$only` it is the single-sheet start.
- Callers keep their own words:
  - button: unchanged redirect and messages;
  - API: unchanged URL, header and JSON on both answers, run message stays "Triggered via n8n";
  - Likha `start` and `startOne`: on a refusal `{ok:false, message:"May running import pa (Run #N). Hintayin
    muna matapos.", run_id}` with HTTP 409. The page's script already shows a message when `ok` is false; it is
    changed to show `data.message` when present (one line, both callers).
- Production cache store must support locks across processes (database, file, redis all do; `array` doesn't).
  This goes in the deploy notes; the code can't read the environment.

### 4.2 The night import command

`php artisan night:import {kind : macro|likha} {slot : 1|2}`:

1. Reads the switch for that kind again; off → exits, records nothing.
2. Stale rule: an active run of that kind whose `started_at` (or `created_at` when null) is older than 60 minutes
   (macro) or 120 minutes (Likha) is closed: `status = failed`, `finished_at = now`, message "Stale: closed by
   the night run"; its unfinished items/sheets are set to `failed`; for macro `cancel_requested = true`.
3. Calls the start service. Started → step `started` with `ref_id` = run id. Refused → step `skipped`, reason
   "Skipped: run #N was still running". An exception → step `failed`, reason "Failed to start" plus the exception
   class name only.
4. One step per `(night_date, kind)`; kinds are `macro_import_1`, `likha_import_1`, `macro_import_2`,
   `likha_import_2`. A second call for the same night and kind does nothing.

The page reads the linked run live for the result (Running, Done, Failed, counts, message), so no later write
to the step is needed.

For macro, only the scheduled start closes stale runs; the button and the API never do (the Force-stop button
exists). Likha closes them on every path (§4.1).

### 4.3 Job changes (the smallest that make the stale rule safe)

- `ImportMacroFromGoogleSheet::handle`: (a) at the start, return without doing anything when the run's status is
  not `queued` or `running`; (b) before the final status write, when `cancel_requested` is set, leave the run as
  it is; (c) in the existing cancel branch, when the run is already `failed`, stop without rewriting its status
  and message (so "Stale: closed by the night run" isn't replaced by "Cancelled by user.").
- `ImportLikhaFromGoogleSheet::handle`: (a) at the start, return when the run's status is not `running`;
  (b) before each sheet, re-read the status and stop when it is not `running`; (c) write the final `done` only
  when the status is still `running`.

Nothing else in the jobs changes; the Google fetch isn't touched or tested.

## 5. Night run records

`night_run_steps` (guarded by `Schema::hasTable`, new migration):

| Column | Notes |
|---|---|
| `night_date` date | the Manila calendar date of the morning the night happens. Astra's orders are `night_date − 1 day`. |
| `kind` string(20) | `macro_import_1`, `likha_import_1`, `macro_import_2`, `likha_import_2`, `astra` |
| `state` string(20) | imports: `started`, `skipped`, `failed`. Astra: `waiting`, `running`, `finished`, `stopped`, `did_not_run` |
| `reason` string(500) null | fixed strings only |
| `ref_id` unsignedBigInteger null | the import run id |
| `trigger` string(10) | `schedule` or `manual` |
| `started_at`, `finished_at`, `stop_at` | `stop_at` = when rows stop being started |
| `rows_found`, `rows_over_max` | Astra: selected count, and how many were left out by the safety maximum |
| `consecutive_failures` | the breaker's counter |
| timestamps | |

Unique `(night_date, kind)`.

`night_astra_rows`:

| Column | Notes |
|---|---|
| `step_id` | index `(step_id, state)` |
| `macro_output_id` | unique `(step_id, macro_output_id)`: a row is in a run once |
| `state` string(12) | `queued`, `running`, `done`, `failed`, `skipped`, `not_run` |
| `attempts` | incremented by the claim |
| `code` string(64) null | `final_code` from the engine |
| `proceed` boolean | the engine set PROCEED |
| `reason` string(500) null | fixed strings |
| `log_id` unsignedBigInteger null | the `ai_checker_logs` row |
| `cost_usd` decimal(10,4) null, `duration_ms` | copied from the engine's summary |
| `dispatched_at`, `started_at`, `finished_at`, timestamps | |

No raw SQL is planned; if any is needed it is parameterised and branched for mysql and pgsql.

## 6. Astra night run

### 6.1 Selection

`macro_output` rows with `ts_date` = the orders' date and STATUS blank (`NULL`, `''`, or only spaces: `TRIM`
through the grammar's `wrap`, as `MacroCheckerController::blankRowsQuery` does), whatever the six fields hold
and whether or not an AI tried them. Ordered by `TIMESTAMP` then `id` (within one date `H:i d-m-Y` sorts by
time). Rows with a null `ts_date` aren't selected (production fills it by trigger).

"Yesterday" is computed with `now('Asia/Manila')`, never from UTC.

Safety maximum: when more rows are found than `night_astra_max_rows`, the oldest `max` are taken; the step keeps
`rows_found` and `rows_over_max`, and the page says "N not run: over the safety maximum of 1,500". No row
records are made for the excess (a bug that selects 500,000 rows must not write 500,000 records).

### 6.2 Start, and waiting for the import

A tick, `php artisan night:astra-tick`, runs every minute (§7). For tonight (`night_date` = today in Manila):

- Before the Astra time, or when the switch is off: nothing to start.
- From the Astra time to the stop time, when no `astra` step exists for tonight: check the condition
  **no macro import is `queued` or `running`, and a macro import with `status = done` has `finished_at` ≥ 00:00
  Manila today**.
  - Met → start.
  - Not met and it is still within 60 minutes of the Astra time → step `waiting` with the reason ("Waiting: an
    import is still running" / "Waiting: no finished macro import since midnight"); the next ticks check again.
  - Not met after 60 minutes → `did_not_run`, reason "Did not run: an import was still running" or "Did not run:
    no finished macro import since midnight".
- A step that exists and isn't `waiting` is never started again by the schedule: one run per date.

Start is **one database transaction**: a conditional update (`waiting`/new → `running`), and only the caller
that wins it selects rows, inserts the row records as `queued` (chunks of 500) and sets `rows_found`,
`stop_at`. A crash inside rolls everything back, so a night can't end as "Finished, 0 rows" with its date used
up. After the commit, `dispatchPending`: for each `queued` row with `dispatched_at` **null**, a conditional
update sets `dispatched_at` and one `RunNightAstraRow` job is dispatched to queue `astra`. The tick calls
`dispatchPending` too, so a crash between insert and dispatch heals; a row is dispatched once per time it is
queued.

A `waiting` step whose 60 minutes have passed is closed as `did_not_run` by the tick whatever the switch says,
so a switch turned off mid-wait can't leave a step waiting (and the tick scheduled) for good.

No API key at start (`AstraEncoder::resolveApiKey()` null) → `stopped`, "Stopped: no API key set", no rows run.
Zero rows found → `finished` at once with 0 rows.

### 6.3 The shared run-one-row function

`App\Services\AiCheckerRowRunner::run(int $id, string $engine, ?string $host, array $ctx): array` holds what
`runRow` does today: load the row, load the address maps, pick the engine, `processRow`, compute `outcome`,
write the log row (`writeLog` and `logDetail` move with it), build the response payload. `$ctx` carries
`source`, `batch_id`, `batch_total`, `user_id`, `user_name`. It returns the HTTP status and payload the browser
gets today, plus the engine result, the log id and the encoder's last transport error.

- `MacroCheckerController::runRow` keeps the role check and `set_time_limit`, calls the runner with the request's
  values and `Auth` user, and returns the same JSON and status. A characterization test is written **first**,
  against today's code, and must pass unchanged after the move (same JSON, same log row).
- The night job calls it with engine `astra`, `source = night`, `user_id = null`, `user_name = 'Night run'`,
  `batch_id = 'night-<orders date>'`, `batch_total = rows taken`. With a `batch_id` the night also shows as one
  line in the page's existing batch table instead of flooding "singles".
- Host: the whole `config('app.url')` string is passed as `$host` (the rule is a `str_contains`, so no parsing
  that could fail on a URL without a scheme). The existing rule in `MacroChecker::loadValidationRefs()` and
  `PhoneWhitelist::phonesForHost` decides the scope. Nothing is hardcoded.
- Engine seam for tests: `Http::fake` on `api.openai.com/v1/responses` (the only place the engine leaves PHP),
  with `Http::preventStrayRequests()`. No test calls OpenAI.

The runner also covers what the move carries besides the Astra happy path: the classic engine, the 404 for a
missing row, the 500 for missing address maps and the exception path; the characterization test pins all five.

Three additions to `AstraEncoder`, none touching prompts, tools, gates or the PROCEED rules, all without effect
on the browser path:

1. `post()` remembers the last transport failure as `['kind' => 'http'|'exception', 'status' => int|null,
   'code' => string|null]` (`code` = OpenAI's `error.code` or `error.type`, a short identifier), readable with
   `lastError()`. It is cleared by every successful post, so a recovered 429 followed by an unusable answer
   isn't classed as transient. No body, no message. The result array and the log detail don't change.
2. An opt-in `httpTimeout(int $seconds)`; default stays `TIMEOUT_S` (300). The night job sets 120, so a stalled
   call returns to PHP after at most 241 s (two attempts) and is retried as a timeout, instead of the worker
   being killed at 540 s with the cause hidden.
3. An opt-in `onlyWhenStatusBlank(true)`: when set, the final `$row->update($updates)` becomes a conditional
   update `WHERE id = ? AND (STATUS IS NULL OR TRIM(STATUS) = '')`; when no row matches, nothing is written and
   the result is `status = skipped`, `final_code` null, `all_filled` false, message "Status set by a person"
   (so the log row's outcome is `partial`, never `fixed`). Default off: the browser path is unchanged. The
   night job turns it on.

### 6.4 The row job

`App\Jobs\RunNightAstraRow(int $rowId)`, queue `astra`, `$timeout = 540`, `$tries = 3`, no `failed()` hook,
`handle()` catches everything. Steps:

1. **Claim:** `UPDATE night_astra_rows SET state='running', started_at=now, attempts=attempts+1 WHERE id=? AND
   state='queued'`. No row changed → return. A job delivered twice, or after a retry click, can't run a row twice.
2. Step not `running` → row `not_run`, "Not run: run stopped". Now ≥ `stop_at` → `not_run`, "Not run: out of
   time".
3. Re-read the order's STATUS. Not blank → `skipped`, "Status set by a person"; nothing is written to the order.
4. Empty chat (`all_user_input` blank) → `skipped`, "No chat text"; no engine call, no cost, not a failure.
5. Run the shared function with `onlyWhenStatusBlank`.
6. Result:
   - engine `fixed`/`partial` → `done`, with `code`, `proceed`, `log_id`, cost, duration; the breaker counter
     is set to 0;
   - engine `skipped` (STATUS set during the call) → `skipped`, "Status set by a person";
   - engine `failed` or an exception → classify by `lastError()`:

     | Class | When | What happens |
     |---|---|---|
     | fatal | HTTP 401 or 403; 429 with code `insufficient_quota`, `billing_hard_limit_reached` or `billing_not_active` | row `failed`; the run stops at once: "Stopped: OpenAI rejected the API key (401)" / "Stopped: OpenAI credit or spend limit reached" |
     | transient | exception (timeout, connection), other 429, any 5xx | `attempts` = 1 → back to `queued` with `dispatched_at = now` (so the tick, which dispatches only rows with a null `dispatched_at`, can't cut the delay short), job dispatched again with a 65 s delay; `attempts` ≥ 2 → `failed`. If that delayed job is ever lost the row stays `queued` until the stop time and ends "Not run: out of time". |
     | other | any other status, or no transport error (the AI gave no usable answer) | `failed`, no retry |

     Reasons: "OpenAI timeout or connection error", "OpenAI rate limit (429)", "OpenAI server error (5xx)",
     "OpenAI error (4xx)", "No usable answer from the AI", "Error: <exception class name>".
   - a final `failed` adds 1 to the step's `consecutive_failures` (one atomic increment). At 10 the run stops:
     "Stopped: 10 rows failed in a row (last: <reason>)".
7. The row's final write is `WHERE id=? AND state='running'`, nothing else. If the sweep already marked the row
   "Worker stopped", the late result is not written to the night table (the order and its log row are as the
   engine left them; the row can be seen through its log link). `cost_usd` adds up over a row's attempts.
   A row whose rounds add up to more than 540 s is killed by the job timeout and ends "Worker stopped".
8. `settle(step)`: when the run is `running` and no row is `queued` or `running` → `finished`.

Stopping a run sets the step to `stopped` with the reason and all `queued` rows to `not_run`, "Not run: run
stopped". Rows already running on another worker finish normally.

`AstraEncoder::post` keeps its own quick retry (1.2 s) as today, so a transient row makes up to four HTTP
attempts across its two tries. The engine isn't changed to avoid that; the browser path must behave as today.

With several workers "10 in a row" means ten final failures with no success between them, in the order they
finish.

### 6.5 The tick's sweeps

Every minute, for every Astra step that is `running`:

- rows `running` for more than 10 minutes → `failed`, "Worker stopped", counted by the breaker (the job's
  timeout, 540 s, is below this);
- now ≥ `stop_at` → every `queued` row → `not_run`, "Not run: out of time";
- `dispatchPending`, then `settle`.

The tick never sets a row to `queued`.

### 6.6 Manual actions (CEO only)

- **Run now**, `POST /encoder/checker_1/ai-checker/night/run-now` with `date` (the orders' date, `Y-m-d`,
  **yesterday or earlier**: a run for today's orders would use up tomorrow night's one run). Same condition as
  §6.2 but no waiting: when it isn't met the click is refused with the reason. No step for that date → a normal
  start, `trigger = manual`. A step exists and is `finished`, `stopped` or `did_not_run` → the same run is
  opened again: its `failed` and `not_run` rows that are still blank are queued (as Retry failed), and blank
  rows of that date not yet in the run are added, up to the safety maximum. Rows that ended `done` or `skipped`
  are never run again. A step that is `waiting` or `running` → refused, "Already running".
- **Retry failed**, `POST /encoder/checker_1/ai-checker/night/{step}/retry-failed`: only for a step that is
  `finished` or `stopped`; only its `failed` and `not_run` rows whose order STATUS is still blank go back to
  `queued` (`attempts` 0, `dispatched_at` null); the step goes back to `running`, the breaker counter to 0.
  The step's move back to `running` is a conditional update inside one transaction with the row resets, as in
  the scheduled start, so a double click re-opens it once.
- For both, `stop_at` is the next time the stop time comes round after the click.
- Both redirect back to the logs page with a one-line message.

## 7. Scheduler (`routes/console.php`)

Following the `hold_snapshot_time` block (settings read in try/catch, `Schema::hasTable`, defaults):

| Entry | When | Scheduled if |
|---|---|---|
| `night:import macro 1` | daily at time 1 | macro switch on |
| `night:import likha 1` | daily at time 1 | Likha switch on |
| `night:import macro 2` | daily at time 2 | macro switch on |
| `night:import likha 2` | daily at time 2 | Likha switch on |
| `night:astra-tick` | every minute | Astra switch on, **or** an Astra step is `waiting` or `running` (so a manual run with the switch off is still swept and finished) |

All with `->timezone('Asia/Manila')` and `withoutOverlapping`; the tick uses `withoutOverlapping(5)` so a crashed
tick can't block the next ones for a day. With all switches off and no active run, `schedule:list` shows none of
them.

## 8. Routes and access

| Route | Access |
|---|---|
| `POST /encoder/checker_1/settings/night` | CEO, else 403 |
| `POST /encoder/checker_1/ai-checker/night/run-now` | CEO, else 403 |
| `POST /encoder/checker_1/ai-checker/night/{step}/retry-failed` | CEO, else 403 |
| `GET /encoder/checker_1/ai-checker/night/{step}/rows` (JSON, the night's rows) | CEO, else 403 |

CEO check: the controllers' existing `isCeo()` pattern. All four sit in the same `web`, `auth`, `allowed_ip`
group as the page. Inputs are validated (`date` as `Y-m-d` and not after yesterday, `step` numeric and of kind
`astra`, times `H:i`, switches boolean, max rows integer 1–20000). The "Night run" section itself is shown to
whoever may see the logs page today (`AiCheckerAccess`); the estimated cost, the buttons and the row list are
CEO only, and the cost is left out of the data for others, not just hidden.

## 9. The page

At the top of `/encoder/checker_1/ai-checker/logs`, above "Batches":

- **Banner (red)** when, for the latest night whose times have passed, a switched-on step failed, was stopped,
  did not run, or has no record at all ("Night macro import 01:00 has no record: is the scheduler running?").
- **Night run**, last 14 nights, one line each: the date · Imports (one badge: Done / Running / Failed /
  Skipped, the worst of the four, with "3 of 4 done") · Astra (state badge: Waiting, Waiting for the worker,
  Running, Finished, Stopped, Did not run; then "185 rows · 141 PROCEED · 38 for a person · 6 failed") · ›.
- **Expand (›):** the four imports, one line each (time, Done / Failed / Skipped / Running, processed /
  inserted / updated, reason); Astra: rows found, PROCEED, left for a person by code (e.g. "TO FIX 31 ·
  Barangay 4 · CANCEL? 2"), failed, skipped, not run, duration, the reason, and for the CEO the estimated cost
  ("≈ $0.41 estimated") and **Retry failed**. For the CEO, "Show rows" loads the row list: order id, page, result
  (PROCEED / code / Failed / Skipped / Not run), reason, and a link to its log entry
  (`/encoder/checker_1/ai-checker/answers?date=…&mid=…`).
- "Waiting for the worker": the run is `running`, rows are `queued`, none is `running`, and no row started or
  finished in the last 3 minutes.
- CEO: a date field and **Run now** with a confirm.
- English labels, the page's existing classes, no horizontal scroll (the expand stacks on a phone), every value
  through `{{ }}` or `x-text`.

## 10. Cost

`gpt-6-luna` gets a price of USD 0.10 per 1M input tokens and 0.50 per 1M output tokens. Because prices live in
`config/services.php` and not in a table, the price is added there (one line), and a test pins that a
gpt-6-luna row's `cost_usd` is no longer 0. See question 1. When a night used a model with no price (the
engine's existing `cost_known` flag is false) the page says "estimated, some rows have no price" instead of
showing a low number as if it were complete.

## 11. Tests (each a named test; sqlite, tables made by hand as `ItemTestCase` does)

Base `tests/Feature/NightRun/NightRunTestCase.php`: users, employee_profiles, app_settings, macro_output (the
columns Astra reads and writes, `ts_date` set by the fixtures), ai_checker_logs, the import run tables, the new
tables (real migrations), jobs; `Http::preventStrayRequests()`. The test environment has the `sync` queue and
the `array` cache: every test that dispatches uses `Queue::fake()` (a delayed retry would otherwise run
inline), and the held-lock test sets the starter's wait to 0.

| File | Cases |
|---|---|
| `RunRowCharacterizationTest` | browser `runRow` JSON and log row, written before the refactor, unchanged after: Astra engine, classic engine, row not found (404), address maps missing (500), exception path (500 and a `failed` log row) |
| `ImportStartTest` | second macro start while queued and while running is refused; API 409 with the same JSON; button message unchanged; a start while the lock is held creates no run, and two starts create one run; archived settings excluded on button, API and scheduler; Likha guard (start and single sheet) |
| `NightImportCommandTest` | stale macro run (61 min) and Likha run (121 min) closed with the message, then a new run starts; a fresh active run → "Skipped: run #N was still running"; switch off → nothing starts and nothing is recorded; second call same night and kind does nothing; a job whose run was closed does not import (macro and Likha) |
| `NightSettingsTest` | invalid time falls back to the default (table of bad values); max rows out of range falls back; schedule has the entries with switches on and none with them off |
| `NightAstraSelectionTest` | yesterday by Manila date at 00:30 Manila (16:30 UTC the day before); STATUS NULL, '' and spaces in; other dates and statused rows out; oldest first; safety maximum |
| `NightAstraStartTest` | waits while an import runs, starts after it; "Did not run" after 60 minutes (both reasons); one run per date; no API key → stopped; Astra switch off → the tick starts nothing; a waiting step past its window is closed with the switch off |
| `NightAstraRowJobTest` | STATUS set meanwhile → skipped, order untouched, no HTTP call; STATUS set during the call → nothing written; retry once then failed; breaker at 10 with rows left `not_run`; 401 and `insufficient_quota` stop at once; a second delivery of the same job makes no second call; log row has source `night`, null user; host scope from `app.url` (likha and incepxion lists); stop time → "Not run: out of time"; reasons contain no response body |
| `NightAstraTickTest` | a row running 10 minutes is failed by the tick; the tick never re-queues; finishes the run |
| `NightRunRoutesTest` | each of the four routes: CEO passes, another allowed role gets 403 (one table); Run now rejects today and bad dates; Retry failed takes only failed and not-run rows still blank; the logs page shows the section, and the cost only to the CEO |
| `PriceTest` (in `NightSettingsTest` if it stays one case) | gpt-6-luna cost is computed from the config price |

## 12. Docs

`docs/night-run.md`: deploy steps (the migrations, the supervisor program text for queue `astra` with 2
processes and a `stopwaitsecs` above the job timeout, re-running `config:cache` for the new price, the
cache-store note: the lock is per cache store, so it only guards starts that share one store), one paragraph
on turning it on and what the page shows.
`RESULT.md` repeats the deploy notes for Mira.

## 13. Out of scope

As the handoff §9. Also not done here: making the classic checker or the escalation callable at night; alerts
outside the page; changing `AstraEncoder::post`'s own retry; cleaning the three copies of the validation rules.

## 14. Conflicts with CLAUDE.md, noted for RESULT.md

- CLAUDE.md asks for a git worktree per work branch; `git worktree` isn't in the handoff's allowed commands, so
  the branch is worked in place, as in 006.
- CLAUDE.md's `/ship` pushes and opens a PR; the handoff says no push and no PR. RESULT.md stands in for the PR.
- The handoff says PHP 8.2, CLAUDE.md 8.4 locally: code stays 8.2-compatible (`composer.json` `^8.2`).

## 15. Questions for Mira (each with the default that will be built)

1. **The price isn't in a table.** `ai_checker_prices` is a config array (`config/services.php:61`), read by
   both engines. Default: add `'gpt-6-luna' => [0.10, 0.50]` there, no migration, and replace the "price
   migration is idempotent" test with "gpt-6-luna cost is computed". A new table plus migration would mean
   changing how both engines read prices.
2. **Astra never reports why OpenAI failed** (§2). Default: the two small additions in §6.3 (`lastError()`,
   `onlyWhenStatusBlank`). Without the first, the night job can't tell an invalid key from a timeout; without the
   second, a status a person sets *during* a row's 17–70 s call could be overwritten.
3. **Run now on a date that already has a run.** Default: it re-opens that run: failed and not-run rows that
   are still blank, plus blank rows not yet in it; rows Astra already finished (including "TO FIX" ones still
   blank) are not run again. The other reading (run every blank row again) spends more.
4. **Run now only for yesterday or earlier.** Default: yes, because a run for today's orders would block
   tomorrow night's run for that date. Likewise a Run now for yesterday's orders clicked *before* tonight's
   Astra time is that date's one run: the schedule then does nothing for it. Default: allowed, and the confirm
   says so ("This is the run for <date>; the 03:00 run will not start again").
5. **Late start.** If the scheduler was down at the Astra time and comes back before the stop time: default is
   to start then (the condition still applies; no waiting once 60 minutes have passed).
6. **Stop time for manual actions.** Default: the next time the stop time comes round after the click (a 2 pm
   click may run until 07:00 the next day).
7. **Retry failed and the import condition.** Default: Retry failed doesn't wait for imports; Run now uses the
   condition without the 60-minute wait.
8. **Rows with no chat text.** Default: skipped ("No chat text"), no engine call, not counted as a failure.
9. **The night's row list is CEO only** (the testing decisions say every new route is CEO only), so other
   allowed users see the night's line and its summary but not the rows. Default: as written.
10. **Likha single-sheet start** (`startOne`) gets the same guard as the full start. Default: yes.
11. **Log row name.** `user_id` null, `user_name` "Night run", `batch_id` "night-<date>" so the night is one line
    in the existing batch table. Default: as written.
12. **A Likha import still running at 03:00.** The decision makes Astra wait for the macro import only. The
    Likha job also writes `macro_output` (shop details, item, COD when blank). Default: as decided, macro only.
13. **Schedule entry for the tick** stays listed while a manual run is active even with the Astra switch off
    (§7). Default: as written.
14. **Existing log of OpenAI error bodies.** `AstraEncoder::post` writes up to 500 characters of an error body
    to `storage/logs` (`ASTRA_ENCODER_HTTP`), and a 401 body carries a fragment of the key. It exists today on
    the browser path; at night it would be written for every failing row until the run stops (one row for a
    401). Default: left as it is, because the handoff says not to change Astra; say the word and the body is
    replaced by status and error code in that log line.
15. **Dead Likha runs.** The decision says the *scheduled* start closes stale runs. For Likha the default is to
    close a run older than 2 hours on every path (§4.1), because Likha has no Force-stop and a killed job
    leaves its run `running` for ever; with the guard, that would refuse every Likha import on a site whose
    night switch is off (incepxion).
16. **One failed sheet fails the whole macro run** (`ImportMacroFromGoogleSheet.php:332`), and then Astra's
    condition "a macro import finished successfully since midnight" isn't met, so one broken sheet means "Did
    not run" every night until someone fixes or archives it. Default: as decided (only `done` counts); the
    banner shows it the next morning. The alternative is to accept a run that ended with at least one sheet
    done.
17. **Stale limit equals the gap between the two imports.** A macro run that started at 01:00 is 59 or 60
    minutes old at 02:00, so the minute decides between "Skipped" and "closed as stale". Default: as decided
    (60 minutes from `started_at`). The alternative is the Force-stop button's signal, no progress
    (`updated_at`) for 10 minutes.
18. **Timeout of one OpenAI call at night:** 120 s instead of the browser's 300 s (§6.3), so a hung call is
    retried rather than ending as "Worker stopped". Default: as written.

## 16. Spec review (skeptic-reviewer, spec-review mode, opus, 2026-10-04)

Verdict: **needs revision**, 3 majors, no blocker. The reviewer found no path that sends a row to the engine
more than twice per run without a CEO click, and no path that writes to an order whose STATUS a person set.
It read code only; nothing was run.

| # | Finding | Level | What changed in this spec |
|---|---|---|---|
| S1 | An OpenAI timeout was never retried: one stalled call takes 601 s, the job is killed at 540 s | major | §6.3 item 2: night HTTP timeout 120 s; question 18 |
| S2 | The transient retry didn't say what happens to `dispatched_at`; the tick could cut the 65 s delay | major | §6.4 table: `dispatched_at = now` on re-queue, the tick dispatches only null ones |
| C1 | A dead Likha run would refuse every Likha import for good | major | §4.1: Likha closes a run older than 2 hours on every path; question 15 |
| m | `MacroChecker::lists()` doesn't exist | minor | §2, §6.3: `loadValidationRefs()` |
| m | "never logged" was untrue for the existing error-body log | minor | §1; question 14 |
| m | §6.4 step 7 contradicted itself | minor | one rule: write only `WHERE state='running'` |
| m | No test for "Astra switch off: the tick starts nothing" | minor | §11 |
| m | Characterization test covered only the Astra happy path | minor | §6.3, §11: five cases |
| m | `config:cache`, `cost_known`, cost of a retried row | minor | §10, §12, §6.4 step 7 |
| m | Start wasn't atomic (state flipped before the inserts) | minor | §6.2: one transaction |
| m | `lastError()` never cleared | minor | §6.3 item 1 |
| m | Lock timeout with no run to name | minor | §4.1 |
| m | Stale message overwritten by the cancel branch | minor | §4.3 (c) |
| m | Stale limit equals the import gap | minor | question 17 (decision kept) |
| m | One failed sheet blocks Astra | minor | question 16 (decision kept) |
| m | A `waiting` step could stay for ever; time order | minor | §6.2, §3 |
| m | Per-sheet messages are raw exception text | minor | §1: run-level message only |
| m | Log outcome for a skipped write | minor | §6.3 item 3 |
| m | `parse_url` on a URL with no scheme | minor | §6.3: the whole string is passed |
| m | Run now before the Astra time uses the night's run | minor | question 4 |
| m | Retry failed double click | minor | §6.6 |
| m | Supervisor `stopwaitsecs` | minor | §12 |
| m | `sync` queue and `array` cache in tests | minor | §11 |

Declined to judge (environment facts): the production cache store and whether the two servers share one;
whether production PHP has `pcntl` (the 540 s job timeout depends on it; without it the 10-minute sweep is the
only stop); the middleware group round the new routes; OpenAI's real error codes for the fatal class. These go
to the deploy notes and to the task reviews. The fixes above were not re-checked by the reviewer; the per-task
reviews check the code against them.
