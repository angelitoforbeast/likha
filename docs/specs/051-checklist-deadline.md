# Brief 051: checklist deadlines, late tasks in the staff feed, and a to-do badge on the Checklist nav link

## Why

Checklist tasks have a scheduled time (`checklist_tasks.scheduled_time`) but no moment when a task counts as late, so nobody is told until someone reads the report at night. The owner wants three things: a deadline per task ("by default, 15 mins nung nakaset na oras"); the staff feed (`/api/staff-status`, read every 5 minutes by an outside monitor) to list each person's tasks that are past their deadline today, so the monitor can raise an alarm; and a badge on the "Checklist" nav link ("may notif pag may need gawin, tapos nasa ibabaw") so every staff member sees on every page what they still have to do.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| New nullable field on `checklist_tasks`: the deadline time of day (e.g. `deadline_time`, same kind of value as `scheduled_time`; parse both the same way the app already parses `scheduled_time`). Editable in `/checklist/manage` wherever `scheduled_time` is editable, by the same roles (CEO and the OICs who can edit tasks today), and logged in `checklist_task_changes` like the other fields (label "Deadline") | The owner's ask; one more column, no new table |
| Effective deadline = the field if set, otherwise `scheduled_time` + 15 minutes. A task with neither has no deadline: never late, not in the late list, counted in the badge only while it is open today | "by default, 15 mins nung nakaset na oras" |
| "Due today" and "submitted" follow exactly what `/checklist/report` already counts for a day (frequency, active/inactive, group vs individual: a group task is done when anyone assigned submitted; an individual task per person). Reuse that logic, don't write a second version | One truth for the report, the feed and the badge |
| Feed: in `/api/staff-status`, per person, add `checklist_late_today`: a list of that person's tasks due today whose effective deadline has passed, each with task id, title, deadline (HH:MM), submitted (true/false), submitted_at (HH:MM or null; for a group task the first submission by anyone), latest verdict (`approved`, `not_approved` or null when not checked), and approval_checks (how many approval checks that submission has had). Plus a top-level count of late, not-submitted tasks. No photos, notes, prompts or AI text in the feed. Keep every existing field and its meaning unchanged | The monitor needs who, what and when, nothing private |
| Badge: on the "Checklist" nav link, for the signed-in user, on every page that uses the layout: the number of their own tasks that need action now = tasks due today, not yet submitted, whose effective deadline has passed or is within the next 30 minutes, plus their submissions today whose latest verdict is `not_approved`. Red when any of them is past its deadline; otherwise a neutral colour; hidden when zero. Hovering (title attribute) says the split, e.g. "2 late, 1 due soon, 1 not approved" | Visible where they work, not only on /checklist |
| For CEO and the OIC roles (Marketing - OIC, Data Encoder - OIC): a second small number on the same link for their team's late, not-submitted tasks (CEO: every staff member; an OIC: the users of their own department as the checklist already groups them; if the code has no department link for a user, leave that user out and say so in the result) | The leads need to see the team at a glance |
| Cost: the badge is computed with a few aggregate queries per request, cached per user for 60 seconds; it must not slow page loads noticeably (measure the queries on the test database and state the number in the result) | It runs on every page |
| No re-check limit in this spec (a separate decision is still open) | Not decided yet |

## Stories and cases

S1 Deadline field. (1) A CEO sets a task's deadline to 10:30 in manage; it is saved, shown, and one checklist_task_changes row records old and new. (2) A Marketing user without edit rights cannot set it (same refusal as other fields). (3) Clearing it saves null. (4) An invalid time is refused with the form's usual validation message.
S2 Effective deadline. (1) scheduled 10:00, no deadline set: deadline 10:15. (2) deadline set to 10:45: 10:45. (3) neither: no deadline. (4) scheduled 11:50 PM: 12:05 AM is next day, so it never becomes late today (state how you handle the day boundary).
S3 Feed. (1) At 10:16 a task due today with deadline 10:15, not submitted, appears in each assigned person's checklist_late_today with submitted false. (2) At 10:14 it does not appear. (3) Submitted at 10:20 with latest verdict not_approved after 3 checks: submitted true, submitted_at 10:20, verdict not_approved, approval_checks 3. (4) An inactive task or one not due today never appears. (5) A group task submitted by one person shows submitted true for every assigned person. (6) No photo path, note or AI text anywhere in the response. (7) The existing fields (including checklist_approved_today) are unchanged.
S4 Badge. (1) A staff member with one late task and one due in 20 minutes sees 2, red. (2) With only one due in 20 minutes: 1, neutral. (3) With one not_approved submission today: 1. (4) With nothing open: no badge. (5) Shown on a non-checklist page too. (6) A Marketing - OIC sees a second number for late tasks of their department; a Marketing user does not see a second number. (7) The CEO's second number covers all staff. (8) The cache: two page loads within 60 seconds run the badge queries once.

## Tests

One test or more per case above, red first where natural. The whole suite must pass except the known failures on develop (`ExampleTest`, and `ImportStartTest` in a fresh worktree): adds no new failures. `php.bat -l` on every changed PHP file. `npm run build` passes if you touch assets.

## Threat model and risk

Untrusted: what staff type in manage (the deadline value) and any request parameter. Trusted: the database rows and the app's roles. The feed is already behind its own key; do not widen what it returns beyond the fields above. Risk tier: medium (every page renders the badge; a slow or failing query there breaks the whole app), so the badge must fail quietly (no badge, logged class only) rather than error the page.

## Out of scope

Re-check limits per role; changing approval prompts; sending messages or alerts anywhere (the outside monitor does that); push or browser notifications; any change to chatblast, Botcake or campaign code; deploying.

## Done when

All cases above have passing tests; the suite adds no new failures; the migration runs up and down on the test database; the result lists the badge's query count and timing, the feed's new JSON shape with a sample (fake data), and every place the brief was wrong.

## Final message

Commits; migration; where the deadline is edited and logged; the effective-deadline rule as built (day boundary); the feed's new fields with a sample; the badge (query count, cache, how OIC teams were found); test lines and suite result; review findings and fixes; anything the brief got wrong.
