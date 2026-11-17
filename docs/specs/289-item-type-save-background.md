# 289: saving Item Type Mapping answers at once; the 90-day recompute runs in the background

## Why
On `/jnt/item-types`, `POST /jnt/item-types/save` (Option 1, paste from sheets) writes the mappings, then calls `aliasesChanged()` inside the web request. That calls `DailyPrimaryItemService::recomputeForItemNames()`, which recomputes the main item for the last 90 days, then `OwnerPrivateController::bumpCacheVersion()`.

On the second site this ran past nginx's 60 s and returned **504** (2026-11-16 19:15 and 2026-11-17 18:44). The mappings were already saved. When staff saved again they got "18 skipped (no change)" and thought it saved twice.

## Stories
- **IT-1 (P1).** Save (and save-one / inline edit, if it also calls `aliasesChanged`) writes the mappings and answers at once with today's counts message. The recompute plus the cache bump run in a queued job on the default queue (database connection, queue `default`, as spec 283's jobs do). The job's `$timeout` is enough for 90 days, and `$tries = 1`.
- **IT-2 (P1).** Several saves in a row do not pile up duplicate full recomputes. Merge the item names into one pending job, or use a unique job per short window, and say which.
- **IT-3 (P1).** The result is the same as today once the job has run: the same `daily_page_primary_item` rows and the cache version bumped. A job failure is logged by class and does not lose the mapping.
- **IT-4 (P2).** The page shows a short note after save: "Saved. Updating owner reports in the background (a few minutes)."

## Done when
- Feature tests: save answers without running the recompute inline (assert the job is dispatched with the item names); running the job gives the same rows as calling the service directly; double saves give at most one pending job, or the merged behaviour.
- Existing ItemTypeMapping / DailyPrimaryItem tests pass.
- Full suite: no new failures compared with base `ce591677`. Known base failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest.
- Branch pushed.
