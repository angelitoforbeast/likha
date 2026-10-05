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

Routes and pages (learned in 007 T5):

- Every Blade view rendered for a logged-in user, the 403 and 404 pages included, runs the `View::composer('*')` in `AppServiceProvider`, which counts the user's rows in `tasks`. A test that asserts 403/404 on a web route or renders a page must create a small `tasks` table (`id`, `user_id`, `status`, timestamps), or the response is a 500. Page tests also need `$this->withoutVite()`.
- `php.bat` is a Windows batch file: a `|` inside `--filter=` is taken as a pipe by cmd even when quoted. Run one filter name per command.
- To go back to a guest after `actingAs` inside one test, call `auth()->forgetGuards()`.
- `isCeo()` in the controllers reads the stored role only; it does not follow the CEO's "view as" role (`AiCheckerAccess::allows()` does). A CEO previewing another role still passes CEO-only routes.
- Columns not in a model's `$fillable` (`created_at`) are silently dropped by `create()`: set them with a query-builder update in the fixture.

/owner/private endpoints (learned in 009 T1):

- Start from `tests/Feature/OwnerPrivate/OwnerPrivateTestCase.php`. `itemSummary()` and `pageRangeBreakdown()` both run on sqlite with: hand-made `macro_output` (ITEM_NAME, PAGE, TIMESTAMP, STATUS, waybill, COD, ts_date) + `from_jnts` + `supply_excluded_pages`, and real migrations for ads_manager_reports, cogs, fee_settings, page_item_settings, daily_page_primary_item. Do NOT run the cogs_ceo migration (MySQL-only `INSERT IGNORE`), nor 2026_04_24_000008 (needs item_class_rules).
- `fee_settings` migration seeds only cod_fee_rate and cod_fee_vat_rate for scope `likha`; insert `shipping_fee_per_order` (scope `likha`, the test host maps to it) or itemSummary aborts 422.
- itemSummary's page roster comes from `daily_page_primary_item` rows on end_date (page_key is the lowercase label); a row there is enough to get a payload row. Add `refresh=1` to bypass the per-role cache.
- Laravel's JSON response does NOT hex-escape `<` (only `/` becomes `\/`), so a raw-body "no literal <script>" assertion fails; assert JSON content-type + round-trip instead.
- `php.bat -l` and git commands: a Bash call with a `for` loop variable (`$f`) is refused by the sandbox; run one command per call.

Login, session and remember cookie (learned in 012):

- Start from `tests/Feature/Auth/AuthTestCase.php`. Any test that signs in through the guard (POST `/login` or a remember cookie, not `actingAs`) fires the `Login` event, and the listeners in `app/Listeners` are auto-discovered (no provider registers them): `CopyEverydayTasksOnLogin` needs `everyday_tasks` and `tasks` tables or the request is a 500.
- One test = one app instance, so state leaks between requests the way it never does between browsers: the session store keeps old attributes (`start()` merges, it doesn't replace), the guard keeps its cached user and its `loggedOut` / `recallAttempted` flags, the cookie jar keeps queued cookies, and `withCookie` values stay for every later request. Before a "new browser" request call `freshBrowser()` (session flush, `Auth::forgetGuards()`, `Cookie::flushQueuedCookies()`); without `forgetGuards` a "still a guest" assertion passes for the wrong reason. `refreshApplication()` is not an option: it drops the in-memory sqlite database.
- The session id changes on every test request unless the session cookie is sent. To assert `regenerate()`, send a known 40-character id as the session cookie and compare with `session()->getId()` afterwards (same id after a failed login is the control).
- `withCookie` encrypts and `$response->getCookie($name)` decrypts, so a remember cookie can be read from a login response and replayed as plain `id|token|hash`. The guard never checks the third segment. Never print the value.
- `/debug/ip` is the cheapest page behind `web, auth` only (JSON, no view, no role check).

**Why:** each of these cost a wrong first guess or would silently run a real job.
**How to apply:** start new backend test cases from `tests/Feature/NightRun/NightRunTestCase.php` and extend its migration list.
