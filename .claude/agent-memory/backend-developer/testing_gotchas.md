---
name: testing-gotchas
description: Non-obvious facts about testing this repo's import, queue, cache and AI-engine code in sqlite (handoff 007 T1 to T3)
metadata:
  type: project
---

Gotchas when testing server code here (PHPUnit, sqlite in memory, no RefreshDatabase; each test case builds its tables in setUp).

- The test env runs the `sync` queue and the `array` cache. Any test that reaches a `dispatch()` must call `Queue::fake()`, or the import job runs inline. `Cache::lock` works with the array store inside one process, so a held lock is simulated with `Cache::lock(name, 30)->get()` in the test.
- The legacy migrations for the import tables (settings, runs, run items/sheets, `cancel_requested`, `is_archived`), `app_settings` and `jobs` all run on sqlite, so list them in `migrationPaths()` instead of copying columns by hand. `users` and `employee_profiles` are created by hand (see `tests/Feature/Item/ItemTestCase.php`).
- Creating a `User` auto-creates its `EmployeeProfile`; set the role with `$user->employeeProfile()->update(['role' => 'CEO'])`. Web routes sit behind `web, auth, allowed_ip`; tests disable `AllowedIpMiddleware`.
- `back()` needs `$this->from('/the/page')` in a test, otherwise the redirect target is the site root.
- `url()` in a test depends on the local app URL, so build the expected value with `url()` rather than a literal host.
- `routes/console.php` is loaded by the first `Artisan::call` in `setUp` (the migrate), before any setting exists. To test schedule entries: set the settings, `$this->app->forgetInstance(Schedule::class)`, `ScheduleFacade::clearResolvedInstance(Schedule::class)`, `require base_path('routes/console.php')`, then read `events()` or `Artisan::call('schedule:list')` + `Artisan::output()`.
- A `date` column with Eloquent's `date` cast is stored as `Y-m-d 00:00:00` on sqlite and as `Y-m-d` on MySQL, so equality lookups and unique keys differ. Keep such columns uncast and pass `Y-m-d` strings (as `NightRunStep::night_date`).
- The app timezone is already Asia/Manila; a test that must prove "Manila, not server time" switches `date_default_timezone_set('UTC')` and restores it in `finally`.
- No tinker and no local-database edits are allowed in handoffs: evidence that needs settings turned on comes from a test that prints to STDERR only when it is named in `--filter=`.

AI engines (AstraEncoder, MacroChecker, AiCheckerRowRunner; learned in 007 T3):

- The only seam is `Http::fake()` + `Http::preventStrayRequests()`. Astra calls `api.openai.com/v1/responses`; the classic engine calls `/v1/responses` (RESOLVE) and `/v1/chat/completions` (NAMEADDR, VERIFYK: tell them apart by the system prompt). The first registered stub wins, so never put a default fake in `setUp` if a test needs its own. A fake callback's second argument is the Guzzle options (`$options['timeout']`).
- `AstraEncoder::apiKeyInfo()` reads the Astra key variable from the environment directly, before config. For a key source that doesn't depend on the machine, call `AstraEncoder::storeApiKey('test-key-not-real')` (source `settings`) and set every `services.openai.*` value the engine reads with `config([...])`.
- `AstraEncoder::post` sleeps 1.2 s before its own retry on 429, 5xx and exceptions: each such test case costs 1.2 s; 401/403 return at once.
- Process-wide static caches survive between tests: the runner's `logDetail` caches "has the detail column", MacroChecker caches its city and barangay indexes. Never drop `ai_checker_logs` in a test; to make its insert fail use a sqlite `BEFORE INSERT ... RAISE(ABORT)` trigger.
- To make `MacroChecker::loadAddressMaps()` return empty without touching the repo file: `$this->app->setBasePath(<a folder that doesn't exist>)` around the request, restored in `finally`.
- Two fixture orders with the same phone and the same `ts_date` trip the gate "PHONE duplicate sa parehong petsa" (TO FIX instead of PROCEED): give each order its own date.
- `RunRowCharacterizationTest` pins the browser run-row JSON and log row with `assertSame` (types and key order). It must stay byte-unchanged; a change in engine output shows there first.

Astra night run (learned in 007 T4):

- Start Astra night tests from `tests/Feature/NightRun/NightAstraTestCase.php`: it fakes the queue, captures every log line in `$this->logLines` (so nothing reaches the real log and leaks can be asserted), fixes the time, and has `runningStep()` / `work()` to run one row job by calling `handle()`.
- In a table loop, register `Http::fake` once with a closure that calls a variable (`$answer`), and swap the variable per case: a second `Http::fake` for the same URL never wins.
- "No API key" can't be reached by clearing the stored key alone, because the engine also reads the machine's environment. Blank `ASTRA_ENCODER_API_KEY` and `OPENAI_API_KEY` in `$_SERVER` and `$_ENV` for the test process and restore them in `finally` (see `NightAstraStartTest`).
- To make the engine's write to an order throw (runner 500 path): a sqlite `BEFORE UPDATE ON macro_output ... RAISE(ABORT)` trigger, dropped after the case.
- Timestamps are stored as wall time in PHP's default timezone and the query builder doesn't convert a Carbon's timezone: convert a Manila time with `->setTimezone(date_default_timezone_get())` before binding or storing it.
- `count()` on a query that has `orderBy` fails on pgsql: `reorder()` first.

**Why:** each of these cost a wrong first guess or would silently run a real job.
**How to apply:** start new backend test cases from `tests/Feature/NightRun/NightRunTestCase.php` and extend its migration list.
