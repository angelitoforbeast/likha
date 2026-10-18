# Plan 051: checklist deadlines, late tasks in the staff feed, to-do badge on the Checklist link

Spec: `docs/specs/051-checklist-deadline.md`. Threat model: untrusted = the deadline value typed in
`/checklist/manage` and every request parameter (`?date=` of the feed); trusted = database rows,
roles, config, migrations. The feed stays behind `X-STAFF-FEED-KEY` and gains only the fields the
spec lists.

## What the code already has (read before the design)

- `scheduled_time` is a nullable string column ("HH:MM" from `<input type="time">`, sometimes
  "HH:MM:SS"); `ChecklistController::index` parses it with `explode(':')`. Validation is only
  `nullable|string|max:20`.
- Edit rights: `updateTask` → `canEditTaskContent` (CEO, OIC of the task's department, the creator,
  or a Marketing / Marketing - OIC user assigned to the task). Changes go through `dirtyChanges` +
  `logTaskChange`; labels in `ChecklistTaskChange::FIELD_LABELS`.
- "Due on a day" exists three times today: `report()` day view (Eloquent, `once` = no submission on
  or before the day, so a `once` task submitted today drops out), `rangeReport()`'s `$isDue`, and
  `StaffFeedController::checklistDueNotSubmitted` (assigned users, active, created/deleted bounds,
  frequency, `once` due through the day of its first submission, group = anyone submitted,
  individual = own submission). The last two agree; the day view differs on `once`.
- The nav is DB-driven (`nav_links`, managed at `/owner/nav-settings`); no migration seeds a
  Checklist link, so its key is unknown. `NavBadgeService` exists but the layout has badges switched
  off (`$navBadges = []`) because its queries are slow.

## Design decisions the spec leaves open

- **Column**: `checklist_tasks.deadline_time`, nullable string after `scheduled_time` (same kind of
  value). Migration guarded with `Schema::hasColumn`, `down()` drops it.
- **Validation**: `deadline_time` → `nullable|date_format:H:i,H:i:s` on store and update (the form's
  usual `$errors` box shows Laravel's message). `scheduled_time` validation is not touched.
- **Edit and log**: a time input "Deadline" beside the time input in the add form and the edit form
  of `/checklist/manage`; the table's Time cell shows "⏰ deadline" under the time. Added to
  `LOGGED_TASK_FIELDS`, the HH:MM vs HH:MM:SS no-change rule of `dirtyChanges`, the duplicate copy,
  and `FIELD_LABELS` ("Deadline"). The Disable/Enable form does not send it, so it stays as is.
- **One "due today" source**: new `App\Services\ChecklistDueToday`, built by moving the body of the
  feed's `checklistDueNotSubmitted` into it (unchanged rules) and adding the deadline and the
  submission facts. The feed's existing `checklist_due_not_submitted` is then read from it, so the
  feed, its late list and the badge share one version. The `/checklist/report` day view is not
  touched (its `once` rule differs; noted in the result).
- **Effective deadline** (minutes after midnight): the deadline field if it parses, else
  `scheduled_time` + 15, else none. Both parse as the app does (`H:MM[:SS]`); a value that does not
  parse counts as not set. **Day boundary**: a deadline of 24:00 or later (scheduled 23:50 → 24:05)
  belongs to the next day, so it is never late on the task's own day; it can still be "due soon" in
  the badge in the last 30 minutes before midnight. Late = now (Manila) is past the deadline
  minute: deadline 10:15 is late from 10:15:01.
- **Feed** `?date=`: for a past day every deadline before midnight has passed; for a future day
  nothing is late; for today the Manila clock decides.
- **Feed fields**: per person `checklist_late_today` = `[{task_id, title, deadline "HH:MM",
  submitted, submitted_at "HH:MM"|null, verdict "approved"|"not_approved"|null, approval_checks}]`,
  ordered by deadline then task id. Submission facts come from the submission that counts: group =
  the first submission of the day by anyone (lowest created_at, then id); individual = the person's
  own. `verdict` = latest approval log (`log_type = approval`, newest created_at then id); any other
  value (e.g. `unknown`) is null. Top-level `checklist_late_not_submitted` = number of
  (person, task) entries with `submitted: false`.
- **Badge** (`App\Services\ChecklistBadge`), for the signed-in user (real role, not "view as"):
  late = assigned tasks due today, not submitted, deadline passed; soon = deadline within the next
  30 minutes (incl. the next-day minutes above); open = no deadline and still open (the spec counts
  these "while open today"); not approved = own submissions dated today whose latest verdict is
  `not_approved`. Total = sum; red when late > 0, else grey; hidden at 0. Title lists the non-zero
  parts: "2 late, 1 due soon, 1 open, 1 not approved".
- **Team number**: CEO = all active staff (same inactive-status rule as the feed); an OIC = active
  staff whose department key equals the OIC's. Department key = profile department (trimmed,
  lowercase), else the role without "- OIC" (the way the checklist already finds an OIC's
  department when the profile is blank). Users with neither are left out. The viewer is left out
  of their own team number (their own tasks are the first number). Shown only when > 0, as a small
  second number with title "N late sa team".
- **Where**: the layout finds the Checklist link by its URL (`/checklist`), both desktop and mobile
  nav. `x-navlink` gets optional `badgeTone`, `badgeTitle`, `badgeTeam` props; defaults keep today's
  rendering.
- **Cost and failure**: `Cache::remember("checklist_badge:{user}", 60 s)`; a few grouped queries
  (assigned tasks, today's submissions with latest verdict and check count as subselects, `once`
  history only when needed, staff list for leads). Any exception → `Log::warning` with the class
  name only, no badge, not cached.

## Stories

Stories S-051.1–S-051.23 (slice 051 in `qa/stories.md`) are the spec's cases, numbered in order:
S1.1–4 → S-051.1–4, S2.1–4 → S-051.5–8, S3.1–7 → S-051.9–15, S4.1–8 → S-051.16–23.

## Tasks

| # | Task | Side | Risk | Covers |
|---|---|---|---|---|
| 1 | Migration, model, validation, manage form, change log | backend + frontend (one view) | medium | S-051.1–4 |
| 2 | `ChecklistDueToday`: effective deadline, due rows; feed's due list moved onto it | backend | medium | S-051.5–8, existing feed tests |
| 3 | Feed `checklist_late_today` + top-level count | backend | medium | S-051.9–15 |
| 4 | `ChecklistBadge`, cache, quiet failure, layout + navlink | backend + frontend | medium | S-051.16–23 |

Tasks share files in order (2 → 3 → 4), so they run one after another. One skeptic review
(standard depth) on the whole diff before the result.
