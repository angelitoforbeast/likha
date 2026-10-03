---
name: testing-gotchas
description: Non-obvious facts about testing this repo's import/queue/cache code in sqlite (learned in handoff 007 T1)
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

**Why:** each of these cost a wrong first guess or would silently run a real job.
**How to apply:** start new backend test cases from `tests/Feature/NightRun/NightRunTestCase.php` and extend its migration list.
