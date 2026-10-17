# Result 047: J&T status and rider refresh by itself, and chatblast uses the rider

## What shipped

Commits on `feat/047-jnt-auto-track` (cut from `develop` at f21c8509):

- `docs: brief for spec 047 J&T auto track`
- `feat: chatblast tracks the waybills it builds and the delivering ones before sending through J&T TRACKQUERY (shared with /jnt/track-sync), 20 a call, 2 calls a second and 60 s a build, writes status and rider to from_jnts by id, skips parcels that moved on, and fills {rider} and {rider_phone} (spec 047)`
- `fix: J&T tracking stops its single retries at the build budget and counts the untried as pending, the chatblast job allows one J&T call before the send steps, and the build request may run past the 60 s budget (spec 047)`
- `fix: a delivering message is neither built nor sent when J&T's latest scan is a failed attempt (Problematic, Delivery Fail) or anything but On Delivery, only waybills J&T answered count as tracked, and the rider name is cleaned with the mapper's pattern (spec 047)`
- `fix: a delivering row skipped for a failed attempt frees its waybill for the next attempt, and each build also re-tracks unsent delivering rows and skips those whose latest scan is a failed attempt (spec 047)`
- `docs: accepted review findings for spec 047 in TODO`
- `docs: result for spec 047`

No migration. No column was added, and nothing was altered on `from_jnts` or `macro_output`.

### The shared tracker (`App\Services\Jnt\JntTracker`)

- **`trackSet()`:**
  - one TRACKQUERY call per up to 20 waybills;
  - the billcodes J&T drops are retried one by one, as before;
  - at most 2 calls a second, timed on `BotcakeClock` (a fake clock in tests);
  - an optional deadline stops the one-by-one retries.
- **`changesFor()`:** what one `from_jnts` row gets, the same rules the manual tool used.
  - `status` and `status_logs` change only when the mapped status changes. Problematic and unknown scans still write no status. The log entry is `{batch_at, from, to, src, run_id}`, with `src` = `track_sync` (manual) or `chatblast` (automatic).
  - `signingtime` is set for Delivered / Returned.
  - `rider_name` and `rider_phone` are set when non-empty and different.
  - `updated_at` is set on every write.
  - There is no `rider_updated_at` column, so none is written.
- **`refresh()`:** for chatblast.
  - It tracks the given waybills within a time budget. Every `from_jnts` row of each waybill (copies included) is updated by id.
  - It returns which waybills J&T answered and each one's **latest raw scantype**, which may be Problematic or Delivery Fail, scans that never reach `from_jnts.status`.
  - It records an automatic run in `jnt_track_sync_runs` with no date range: processed, updated, skipped (no answer), and `result_sample` = `{source, rider_found, pending}`.
- **Without J&T config** (no `JNT_ECCOMPANYID` / `JNT_CUSTOMERID` / `JNT_SECRET`, e.g. on Incepxion): nothing is tracked and chatblast works as before. The log gets one warning, `J&T auto track: walang J&T config…`, at most once an hour (a cache key).
- **The manual job `SyncFromJntStatusByTrack`** now uses `trackSet` and `changesFor`. Its query, counters (updated, unchanged, skipped, unmapped), categories and dry run are unchanged, and it is now limited to 2 calls a second. `/jnt/track-sync` works as before. Its history lists only manual runs, and the page shows a new line: "Huling awtomatikong track (chatblast): time · nasuri · nag-iba ang status · rider · walang sagot · hindi naabot".

### When chatblast calls J&T

- **"Kunin ang mga bago" and `chatblast:build`:**
  - **What is tracked:** the waybills about to be built for the chosen kinds (the same candidate query as the build), plus the waybills of unsent delivering rows. Delivering comes first.
  - **Budget:** 60 s of calls (`Chatblast::TRACK_BUDGET`).
    - Waybills not reached are "rider pending": no `tracked_at`, so they are tracked at send time or on the next build.
    - The notice and the command show "J&T: N na-track, M rider pending".
  - **The build then reads `from_jnts` again**, so a parcel that moved on (Delivered, Returned, For Return, …) is not a candidate.
  - **Failed attempts:**
    - For `delivering`, a waybill whose **latest scan** is not On Delivery (Problematic, Delivery Fail, …) gets no row.
    - An unsent delivering row of such a waybill is `skipped` with "Hindi na On Delivery ang huling scan ng J&T (…)", and its key is cleared. The next attempt, e.g. tomorrow's On Delivery, then gets its own row.
- **Before sending a delivering row:**
  - **When:** the row's `vars.tracked_at` is missing or older than 30 minutes (`RIDER_MAX_AGE`).
  - **The call:** the waybill is tracked again (one call, 20 s budget).
  - **Not On Delivery:** if the latest scan is not On Delivery, the row is `skipped`, and its key is cleared for the next attempt.
  - **Otherwise:** the rider is read again and the text re-rendered, possibly a different version (the spec 046 rotation over the usable versions). The existing status check (`staleFor`) then runs on the fresh `from_jnts` status.
  - **No answer or no config:** the row is sent with its existing text.
- **Calls:**
  - **Per build:** ceil(N / 20) batch calls plus one call per dropped waybill, for at most 60 s of calls. At 2 calls a second that is up to about 120 batch calls, or about 2,400 waybills, per press.
  - **Per send:** 0 calls when the row was tracked less than 30 minutes ago, otherwise 1.
- **Longest build:** about 60 s of tracking, plus the last call already in progress (up to J&T's 30 s HTTP timeout), plus the build itself. The web build sets `set_time_limit(180)`.
- **Longest send job:** about 30 s (one J&T call) + about 270 s (the send steps). The job timeout is raised from 300 to **330 s**.
- **Where it runs:** inside the build request or command, and inside the `chatblast` queue job. Never on `default`, and there is no schedule.

### The rider in messages

- **Stored:** the mapper already strips the code from the name before storing (`OCW_Juan D. Cruz` → `Juan D. Cruz`), and stores the phone as digits as J&T gives it (`639171234567`). No other code reads these columns, so they are kept exactly as the manual tool writes them.
- **Cleaned at render** (`Chatblast::riderName` / `riderPhone`):
  - **Name:** any leftover code prefix is stripped, with the mapper's own pattern.
  - **Phone:** becomes `09XXXXXXXXX` (from `639…` or `9…`). Anything else is empty.
- **`{rider}` and `{rider_phone}`** come from the waybill's latest `from_jnts` row that has them. When they are empty, the spec 046 fallback applies: a version of that day without rider placeholders, or `no_config` "walang rider info".

## Tests

| Case | Test | Result |
|---|---|---|
| Only the waybills being built are tracked (an In Transit order is not, when building delivering); 25 waybills → 20 in one call, the 2 dropped retried singly, then 5; calls 0.5 s apart | `ChatblastTrackTest::test_only_the_waybills_being_built_are_tracked_in_batches_of_20_with_dropped_ones_retried_singly` | pass |
| Status and rider written to every row of the waybill by id (two copies); a Delivered parcel is written with `src = chatblast` in `status_logs` and gets no row; the automatic run is recorded (processed 2, updated 1, rider 1) | `…::test_the_status_and_the_rider_are_written_by_id_and_a_moved_on_parcel_is_not_built` | pass |
| `{rider}` / `{rider_phone}` from `from_jnts`, cleaned ("Juan D. Cruz", "09171234567"); no rider → the version without; `riderName` / `riderPhone` cases | `…::test_the_rider_is_cleaned_for_the_message_and_versions_without_it_stay_the_fallback` | pass |
| 60 waybills at 35 s a call: 2 calls, then stop; all 60 rows built; 20 rider pending; the run records 20 pending | `…::test_the_build_tracks_for_at_most_60_seconds_and_leaves_the_rest_pending` | pass |
| The single retries also stop at the budget; the untried 14 are pending, not "no answer" | `…::test_the_single_retries_of_dropped_waybills_also_stop_at_the_budget` | pass |
| Older than 30 minutes: tracked one by one before sending; the rider fills the text (version A); a Delivered parcel is skipped | `…::test_a_delivering_row_is_tracked_again_before_sending_when_its_data_is_older_than_30_minutes` | pass |
| Tracked 10 minutes ago: no call before sending | `…::test_a_fresh_row_is_not_tracked_again_before_sending` | pass |
| No J&T config: no call, chatblast still sends, one warning | `…::test_without_jnt_config_nothing_is_tracked_and_one_warning_is_logged` | pass |
| Manual job: status, rider, `src = track_sync`, counters; `/jnt/track-sync` shows the last automatic run | `…::test_the_manual_track_sync_still_works_and_shows_the_last_automatic_run` | pass |
| On Delivery then Problematic / Delivery Fail: no row at build; at send the row is skipped and nothing goes to Botcake (2 rows) | `…::test_a_failed_delivery_attempt_after_on_delivery_stops_the_delivering_message` | pass |
| No J&T answer: not stamped as tracked; sent with the fallback text | `…::test_a_jnt_error_at_send_time_still_sends_with_the_fallback_and_is_not_stamped_as_tracked` | pass |
| A failed attempt at send, then On Delivery the next day: a new row | `…::test_after_a_failed_attempt_the_next_on_delivery_gets_its_message` | pass |
| A later build that sees Delivery Fail skips the unsent row and frees its key | `…::test_a_later_build_that_sees_a_failed_attempt_skips_the_unsent_row` | pass |

Red runs:
- **First run:**
  - "Undefined array key 0" (no J&T call).
  - "Method warning … should be called 1 times but called 0 times".
  - "two arrays are identical" (no rider, Delivered still built).
  - "tumigil pagkalipas ng 60 s".
  - "isa-isa bago ipadala".
  - The track-sync page lacked the automatic run.
- **Fix loop 1:**
  - "actual size 16 matches expected size 2" (singles past the budget).
  - "walang row ang bigong subok … two arrays are identical".
  - `'2026-10-18T09:00:00+08:00' is null` (an unanswered waybill stamped as tracked).
- **Fix loop 2:** "may bagong row para sa bagong subok … 0 is identical to 1" and "two arrays are identical" (the later build).
- **Already green when written:** the "fresh row" test (no call before sending) passed from the start. It pins the 30-minute rule. The manual job's writes also passed before the refactor, which pinned its behaviour before I touched it.
- **Test mistakes, corrected:**
  - the fake dropped billcodes from every batch, not only full ones;
  - the expected pending counts were wrong twice;
  - the build test's result now carries `tracked` and `rider_pending`.
- **Test base:** the chatblast test base now has `from_jnts.rider_name` / `rider_phone`, as production does (migration 2026_09_16_110000).

Full suite (`vendor/bin/phpunit`) on the final tree: `Tests: 1457, Assertions: 17259, Errors: 1, Failures: 1, PHPUnit Deprecations: 10, Skipped: 3`. The only reds are the known `ExampleTest` and, in this fresh worktree, `ImportStartTest`.

Other checks:
- `php -l` passes on every changed PHP file.
- `node --check` passes on the /chatblast script.
- `artisan view:cache` compiles.

## Review

- **One adversarial review, then two scoped re-checks** (two fix loops, the limit).
  - **Major (fixed):** a delivering message could still go out after a failed delivery attempt. The mapper ignores Problematic and leaves Delivery Fail unmapped, so `from_jnts.status` stays Delivering. The latest raw scan must now be On Delivery, at build and at send.
  - **Major (fixed in loop 2):** that skip kept the row's key, so the waybill never got a message for its next attempt. The key is now cleared.
- **Minors fixed:**
  - the single retries ran past the budget;
  - unanswered waybills were stamped as tracked;
  - the name pattern differed from the mapper's;
  - a later build left an unsent row of a failed attempt untouched.
- **Accepted in `TODO.md` under 047, with reasons:**
  - the 30-minute window at send;
  - the unlocked `status_logs` rewrite against an upload, and its growth;
  - text re-picked at send after the owner looked at it;
  - the web build's nginx timeout, not checked;
  - the heavier candidate query used for tracking;
  - three missing tests.

## Browser check

Not done. There is no running app with the server database, and no J&T access here. To check on the server:
- Press "Kunin ang mga bago" on /chatblast. The notice should show "J&T: N na-track".
- Open a delivering row. Its text should carry the rider when the delivering version uses `{rider}`.
- /jnt/track-sync should show "Huling awtomatikong track".

## After release (server)

1. **No migration.**
2. **`php artisan queue:restart`,** so the `chatblast` worker loads the new job timeout.
3. **Raise the chatblast supervisor timeout:** set `--timeout=350` (the job is now 330 s; spec 046's result said 320), then `supervisorctl reread` / `update`. Laravel applies the job's own timeout, but the worker flag should not be lower.
4. **Credentials:** Likha already has `JNT_*`. Incepxion without them logs one warning an hour while chatblast builds or sends, and works as before.
5. **nginx:** check that `fastcgi_read_timeout` allows a build of about 90 s, or build large lists with `chatblast:build`.

## What the brief got wrong, and decisions it left open

- **"Problematic" never reaches `from_jnts.status`.** The mapper deliberately ignores Problematic and leaves Delivery Fail unmapped, so "moved on" can't be read from the status alone. For delivering, the latest raw scan must be On Delivery. Other kinds use the status as before.
- **A failed attempt is temporary:** J&T tries again. The skipped row's key is cleared so the next On Delivery gets a message. The brief's "skipped" rows otherwise keep their key.
- **The rider name is already stored clean** by the mapper (code stripped). The phone is stored raw and cleaned at render.
- **Copies:** every `from_jnts` row of a waybill is updated, not just one, because uploads can leave copies.
- **No `rider_updated_at`:** the time a waybill was tracked lives on the chatblast row (`vars.tracked_at`), not on `from_jnts`.
- **The manual job is now limited to 2 calls a second too** (it had no limit). A large manual run takes longer.
- **The spec 046 supervisor timeout** must rise from 320 to 350 for the new 330 s job timeout.
- **Conflict with `CLAUDE.local.md`:** the kit asks for a plan and a "go" before code. The brief set every decision, so I worked straight from it.

## Story changes

None. The brief brought no `qa/stories.md` stories. The cases above come from its Tests list.
