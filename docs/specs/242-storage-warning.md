# Spec 242: the marketing OIC sees the server storage, and every screen warns at 80%

Type: change. Weight: bounded. Risk tier: low-medium (a banner on every screen: a slow or failing check must never slow or break a page). Budget: small job, at most 2 attempts per step.

## Why

On 2026-11-08 the server disk reached 100% (MySQL binary logs, 127 GB of 193 GB) and Likha was down for about 3.5 hours: every page returned 502 and the chatbot queue stopped. Nobody saw it coming. The System Monitor (`/system-monitor`, `SystemMonitorController`) shows the disk, but only to the CEO (`checkCEO`), it has no warning, and it shows MySQL table sizes only, never the binary logs.

## Stories

S1 (P1). A user whose employee role is `Marketing - OIC` (normalized the way `checkCEO` normalizes: trimmed, spaces collapsed, case-insensitive) opens a Storage view.
- Happy: they see the disk of `/`: total, used, free in GB and used %; the database size (the existing per-table query, summed); the size of the MySQL binary logs (sum and file count from `SHOW BINARY LOGS`) when the app's database user may read them; and the Likha `storage/app` and `storage/logs` sizes only if the existing monitor already measures them cheaply (otherwise leave them out and say so in the result).
- Negative: any other role, except CEO, gets 404 (the same as the System Monitor today). The CEO sees the full System Monitor as today and the Storage view too.
- Edge: `SHOW BINARY LOGS` is refused (the database user has no REPLICATION CLIENT privilege, which is the case on the live server today): the binary log line says "Not available" and the page still renders. No error is logged at a level that alerts anyone.
- The view is read-only: no buttons that change anything.

S2 (P1). A banner on top of every Likha screen (the main layout every authenticated page uses) when the disk of `/` is 80% used or more.
- Happy: at 80% to under 90% an amber banner: "Server storage is at NN% (FF GB free)." with a link to the Storage view; at 90% or more the same in red.
- It shows only to CEO and `Marketing - OIC` users; other roles never see it.
- Negative: under 80%, nothing is rendered.
- Edge: the measurement fails (disk functions return false): no banner, no error, the page renders.
- Performance: the check is cached (60 seconds is enough); a page view never runs a directory size scan or an information_schema query for the banner. Only `disk_total_space` / `disk_free_space` feed the banner.

## Decisions already made (do not reopen unless something is actually broken)

| Decision | Reason |
|---|---|
| The OIC gets the storage numbers only, not the rest of the System Monitor (server, processes, tables). | The owner wants the OIC to watch storage; the rest stays with the CEO. |
| The warning is a banner inside Likha only; no group chat or Messenger message. | The owner said no group chat message for now. |
| Thresholds: 80% amber, 90% red. | The owner asked for a warning at 80%. |
| No new table, no migration. | The owner prefers no new tables; nothing here needs storing. |
| No database grants, server settings or cleanup in this spec. | Granting REPLICATION CLIENT is the owner's step; this spec only shows "Not available" until then. |

## Threat model

Trusted: the server's own disk numbers and database answers. Untrusted: any request parameter (none is needed; ignore any). The view and banner expose sizes only: no file names beyond the fixed labels, no paths from input, no table contents.

## Constraints

- Tests first: a feature test per case of S1 and S2 (fake the disk numbers through a small seam, for example a class the controller and the banner call, bound in the container; never depend on the test machine's real disk).
- Keep `SystemMonitorController`'s existing behaviour for the CEO unchanged.
- English UI text.
- Code comments explain the reason itself. Commit messages carry no Co-Authored-By, Claude-Session or "Generated with Claude Code" lines, and name no people.
- No `.env`, server or deploy changes; the reviewer releases it after the owner's yes.

## Done when

1. Feature tests prove every case of S1 and S2.
2. The PHP test suite adds no new failures compared with `57aa21b8` (run per folder if the time limit stops a full run; name the folders and the before/after counts).
3. `git diff 57aa21b8 -- database` is empty.
4. Branch `feat/242-storage-warning` from `57aa21b8`, pushed to `origin`; the result lists its head commit.

## Out of scope

Group chat or Messenger alerts; automatic cleanup; binary log settings; other System Monitor sections for the OIC; any other role.

## Report back

In `docs/specs/242-storage-warning.result.md`: the plan you followed, the done-when evidence (commands and output), files changed, the head commit, and for the reviewer: the exact place the banner renders and how long its check takes.
