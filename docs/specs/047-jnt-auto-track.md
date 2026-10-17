# Brief 047: J&T status and rider refresh by itself, and chatblast uses the rider

## Why

Chatblast (spec 046) picks orders by their J&T status in `from_jnts` and can use `{rider}` / `{rider_phone}`. `from_jnts` is filled from uploaded J&T reports, so its status lags, and `from_jnts.rider_name` / `rider_phone` are empty (0 of 15,388 rows of the last 7 days). The app already has an API refresh: `/jnt/track-sync` (`JntTrackSyncController`, job `SyncFromJntStatusByTrack`, `JntClient::trackForJson`, `JntScanStatusMapper::fromDetails`, which already parses the rider from the latest "On Delivery" scan: `sprinter【<CODE>_<name> : <phone>】`). It works live (a read-only probe on 2026-10-17 returned full scans, an On Delivery scan with the rider, and a newer Problematic scan) but is manual and was last run on 2026-09-19. Credentials are in `.env` (JNT_*), already set on Likha.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| Targeted refresh, no schedule: J&T reports are already uploaded about every hour (06:00-23:00 plus early-morning runs), so status is fresh enough; what is missing is the rider. When chatblast builds its list (button and `chatblast:build`), and right before a `delivering` row is sent if its rider data is older than 30 minutes, call J&T TRACKQUERY only for the waybills being built or sent, through the same code as `/jnt/track-sync` (refactor it into a service both use; keep the manual page working). Batches of 20 waybills per call (J&T silently drops extras beyond about 20: retry dropped ones one by one, as TeleSMS `JntStatusSync` does), at most 2 calls per second. The answer updates that waybill`s from_jnts row (status/status_logs as the manual tool does, rider_name, rider_phone). If the fresh status shows the parcel moved on (no longer On Delivery / Delivering for a delivering row, delivered, returned, problematic...), the row is not sent (`skipped`, reason) | The owner: uploads are hourly; TeleSMS uses 20 per call; only the rider is missing |
| Isolation: tracking runs inside the chatblast build (web request or command) in chunks with a time budget (build: at most ~60 s of tracking per press, the rest marked "rider pending" and tracked on the next build or at send time); never on `default` | The owner: "di yun makakagulo sa worker ng iba?" |
| Writes: per row update by id (status + status_logs as the manual tool does, rider_name, rider_phone, and a `rider_updated_at` only if such a column already exists; NO new column on from_jnts and no ALTER on from_jnts or macro_output) | from_jnts is a big table; ALTERs lock imports |
| Rider format for messages: name without the leading code (`OCW_Ruzzel C. Lumbang` → `Ruzzel C. Lumbang`), phone as `09XXXXXXXXX` (from `63...`); store the raw values as the job does now and clean at render time in the chatblast placeholder code (or store cleaned if the manual tool's readers don't need the raw form: check and say which) | Clean messages |
| Chatblast: `{rider}` and `{rider_phone}` render from the waybill's `from_jnts` row; the spec 046 fallback (a version without rider placeholders when rider data is missing) stays | The owner wants the rider in the delivering message |
| Incepxion runs the same code: nothing in the code may assume Incepxion's credentials exist (a missing JNT config makes the automatic run log one warning and do nothing) | Shared codebase |
| A small status on `/jnt/track-sync`: last automatic run (time, rows checked, updated, rider found, errors) | Visibility |

## Tests

Only built/sent waybills are tracked, batching by 20 with dropped ones retried singly, status and rider written by id, rider cleaning, the build time budget, a moved-on parcel is skipped, missing JNT config does nothing, chatblast renders `{rider}`/`{rider_phone}` from from_jnts and falls back when missing, the manual page still works. Red first where natural. Whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

`For Return` / RTS reasons; new columns on big tables; changing how uploaded reports write `from_jnts`.

## Final message

Commits; calls per build and per send, longest build; what is written per row; rider cleaning; test lines and suite result; review findings and fixes; anything the brief got wrong.
