# Plan 007: Night run

Spec: `docs/specs/007-night-run.md` (spec review in its §16). Branch `feat/007-night-run` from `develop` at
`91c470a`. Tasks run **one after another on this branch**: none is parallel-safe (they share
`routes/console.php`, `routes/web.php`, `MacroCheckerController.php`, the night models and the test base), and
`git worktree` isn't in the allowlist. Every task: red test first (one-line red run reported), then the code,
one `feat:` commit per slice, only the affected test files during loops. Developers and the reviewer run in the
foreground.

| # | Task | Side | Tier | Files | Tests (red first) |
|---|---|---|---|---|---|
| T1 | **Import start services and guard.** `MacroImportStarter`, `LikhaImportStarter` with the cache lock; button, API and both Likha starts call them; archived settings excluded everywhere; Likha stale close on every path; the job changes of spec §4.3; the Likha page shows `data.message`. | backend | high | `app/Services/Imports/*`, `AutomationController`, `MacroGsheetController::import`, `LikhaOrderImportController::start/startOne`, the two import jobs, `likha_order/import.blade.php` (one line), `NightRunTestCase` | `ImportStartTest` |
| T2 | **Night records, settings, night imports, scheduler.** Migrations and models for `night_run_steps` and `night_astra_rows`; `NightRunSettings`; `night:import {kind} {slot}` with the stale rule and step records; the four import entries in `routes/console.php`. | backend | high | 2 migrations, `app/Models/NightRunStep.php`, `NightAstraRow.php`, `app/Support/NightRunSettings.php`, `app/Console/Commands/NightImport.php`, `routes/console.php` | `NightImportCommandTest`, `NightSettingsTest` |
| T3 | **Shared run-one-row function.** Characterization test of `runRow` against today's code (five cases), committed green **before** any move; then `AiCheckerRowRunner`; `AstraEncoder` gets `lastError()`, `httpTimeout()`, `onlyWhenStatusBlank()`; the gpt-6-luna price line. | backend | high | `tests/Feature/NightRun/RunRowCharacterizationTest.php` (own `test:` commit first), `app/Services/AiCheckerRowRunner.php`, `MacroCheckerController::runRow`, `AstraEncoder.php`, `config/services.php` | `RunRowCharacterizationTest` unchanged and green after; the price and the three additions in `NightAstraRowJobTest` (started here) |
| T4 | **Astra night run.** Selection; start in one transaction with the import condition, waiting and "Did not run"; `RunNightAstraRow` on queue `astra` (claim, person-wins checks, classify, retry once, breaker, fatal stop); the tick (`night:astra-tick`: sweep, stop time, `dispatchPending`, `settle`); its scheduler entry. | backend | high | `app/Services/NightAstraRun.php`, `app/Jobs/RunNightAstraRow.php`, `app/Console/Commands/NightAstraTick.php`, `routes/console.php` | `NightAstraSelectionTest`, `NightAstraStartTest`, `NightAstraRowJobTest`, `NightAstraTickTest` |
| T5 | **CEO routes and page data.** `POST settings/night`, `POST night/run-now`, `POST night/{step}/retry-failed`, `GET night/{step}/rows`; the night summary (14 nights, banner, cost only for the CEO) built for `logs()`. | backend | high | `app/Http/Controllers/NightRunController.php`, `Checker1SettingsController` (index data only), `MacroCheckerController::logs`, `app/Services/NightRunSummary.php`, `routes/web.php` | `NightRunRoutesTest` |
| T6 | **The page.** CEO block on the settings page; "Night run" section, banner, expand, row list, Run now and Retry failed on the logs page; English labels, no horizontal scroll, phone layout. | frontend | medium | `encoder/checker_1/settings.blade.php`, `encoder/ai_checker_logs.blade.php` (+ a partial `encoder/_night_run.blade.php`) | markup cases added to `NightRunRoutesTest` (section, labels, CEO-only parts); `npm run build` |
| T7 | **Docs and result.** `docs/night-run.md`, `TODO.md` entries, the result notes with evidence. | both | low | docs only | full suite, `php -l` on changed PHP, `schedule:list` with switches on and off |

**Review per task:** T1–T5 `skeptic-reviewer` at adversarial depth (developer opus, reviewer opus) on the
task's diff; T4's brief names the re-queue loop and the "never overwrite a person's status" checks, T1's the
lock and the stale jobs, T5's the CEO gate on each route and the cost leak. T6 standard depth (sonnet). T7 is
checked by the main session. Two fix loops at most; an open money, data-loss or secrets major goes to the reviewer.
Minors on untrusted paths are fixed in one wave before T7; the rest go to `TODO.md` with a reason.

**Order and cut points.** T1 and T2 are the "imports first" half and can ship alone if the work has to be
split (the task brief, Budget); T3–T6 are the Astra half. Each task ends in a green commit, so a cut-off run resumes
at the next task.

**After T6:** the full suite once; `schedule:list` twice (switches on, switches off); `npm run build`. How the
switches get turned on for that listing without touching the local database by hand is settled in T2 (the
allowlist has no `tinker` or `db` command). The browser check is skipped: running the app reads the local
environment file's database, and the task allows no such command.

**Not done without the reviewer's "go":** any code. The 18 questions in spec §15 each carry the default that will be
built; her "go" may change any of them.
