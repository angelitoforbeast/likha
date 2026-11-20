# 310 · The Marketing Head's work in the staff status feed

## Why
The owner, 2026-11-20: in Mira's 3D office, the LDT AI building should show the Marketing Head's real work ("Real Marketing Head work"), not a decoration. The office reads Likha only through `GET /api/staff-status` and `GET /api/staff-events` (`StaffFeedController`). Today the only AI follow-up trace there is the `ai_followup.approved` event, credited to the reviewer; drafts, auto-approvals and chat sends are invisible.

## Stories and cases
**MH-1 A day summary of the Marketing Head (P1).** `GET /api/staff-status?date=YYYY-MM-DD` (default today, Asia/Manila, as now) gains one top-level block `ai_followups` for that date:

| Field | Meaning |
|---|---|
| `drafted` | follow-ups the AI wrote that day (`checklist_tasks` origin `ai`, created that day) |
| `approved` | of those, approved (by the owner or auto) |
| `auto_approved` | of those, approved with no reviewer (299's auto-approve) |
| `rejected` | of those, rejected |
| `waiting` | of those, still drafts |
| `sent` | of those, posted in Likha Chat (`chat_message_id` set) |
| `failed` | of those, approved but the chat post failed (`chat_failed_at` set, no `chat_message_id`) |
| `last_at` | ISO time of the newest of: a follow-up created, reviewed, or sent that day; null when none |
| `last` | the newest follow-up of that day: `{at, person (first name of the assignee), state: draft/approved/rejected/sent/failed}`, or null. No message text. |

- "That day" is the follow-up's `follows_date` when set, else its created date in Asia/Manila. Say which you used in the result if the code shows a better rule.
- A date with no follow-ups gives zeros and nulls, never an error.
- Deleted (soft-deleted) follow-ups do not count.
- Nothing else in the response changes (the existing keys and their values are byte-for-byte the same for the same data).

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| A block on the existing status endpoint, no new route | The office already calls it every 5 minutes with its key |
| Counts and the latest one only, no message text | The office shows activity; the text stays in Likha |
| No migration | Everything is already in `checklist_tasks` |

## Constraints
- Change `StaffFeedController::status` and, if it keeps the controller small, a small method on `AiFollowups` or the model. Do not change how follow-ups are drafted, approved or sent.
- One query (or two) for the block, using the date bounds the controller already has; no per-row queries.
- Tests in `tests/Feature/` next to the existing staff feed tests: each field, a day with none, the date parameter, soft-deleted rows not counted, auth unchanged (the endpoint's existing key check), and the old keys unchanged.
- Comments in the language and style of the file around them. The full suite adds no new failures compared with base 6c3ec11e. Known base failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest `/owner/private`, and the Chatblast speed tests (SpeedMemory, SpeedPrewarm, ResponseSpeed, SpeedPrecomputed, BigBroadcast) that fail under load but pass alone.

## Threat model and risk
- Read-only, behind the existing API key. New output: counts, times, a first name. Risk tier: low.

## Allowed commands
- Through Herd PHP (`C:\Users\Forbeast\.config\herd\bin\php.bat`):
  - `composer.bat install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`
  - `php.bat artisan test` (with filters) and `vendor/bin/phpunit`
  - `php.bat artisan route:list`
- `git add`, `git commit` and `git push -u origin feat/310-marketing-head-in-staff-status`.

## Done when
1. Tests pass for MH-1 as listed. The full suite adds no new failures compared with base 6c3ec11e.
2. The result shows a sample `ai_followups` block from a test, lists what changed, and has a merge-danger section (what ships, how to revert).
3. The branch is pushed. No merge, no deploy.

## Out of scope
- The 3D office itself (the architect changes it in the Mira plugin).
- Any change to follow-up drafting, approval or sending.

## Budget
Small job. At most 2 attempts at the same failing step, then stop and report.
