# Brief 050: a setup page per chatblast campaign, Botcake-style sequence and a flexible schedule

## Why

Spec 048 put every campaign and every day's text on one page (`/chatblast/campaigns`: list on top, one flat sheet of all texts below, send times as a simple list). The owner wants it like Botcake: "ihiwalay na route mo yung actual setup nung campaigns, parang sa botcake, flow, sequence, etc; tapos maseset yung oras (mga oras), daily, flexible". Live data today: two campaigns on each site, "In transit" (10 days x 2 versions) and "Delivering" (5 days x 2 versions), both manual; the texts were just rewritten and must be kept exactly.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| `/chatblast/campaigns` becomes the list only: one card per campaign with name, trigger statuses, pages (count or "Lahat"), on/off, manual/auto, next scheduled run (date and time, or "mano-mano"), today's counts (built / sent / failed / skipped), and buttons "Buksan" and "+ Bagong campaign". The flat sheet of every text is removed from this page (a read-only "Lahat ng mensahe" view may stay as a link) | The owner's request |
| `/chatblast/campaigns/{id}`: the setup of one campaign in three sections, saved per field like the page-settings grid (no big Save button; per-field Saving/Saved/error marks), with the change log kept: (1) **Trigger**: J&T statuses (from those present in from_jnts), pages (all or chosen, searchable), order window in days, the existing "requires On Delivery" option; (2) **Sequence**: vertical steps Day 1 -> Day 2 -> ... -> Day N like a Botcake sequence: each step a card with its versions (A, B, ...), each version an editable text with the placeholder chips, a live preview of what the customer sees (the page's template line + the rendered text, using a sample order or a real recent order of the campaign), the step's counts (sent, failed, skipped), and actions: add version, remove version, add step after, remove step, move up/down; (3) **Schedule**: mode manual/auto (switching to auto keeps 048's confirmation and backlog rule), on/off, one or more send times (add/remove time chips), days of the week (Lun-Lin checkboxes, default every day), optional per-step time override (a step may send at its own time(s) instead of the campaign's), optional per-page daily cap override, and a plain-language summary ("Araw-araw, 9:15 AM at 3:00 PM; Day 1 tuwing 10:00 AM") | Botcake-style setup, flexible time |
| Auto runs follow the new schedule: a run happens at each campaign send time (or a step's own time for rows of that step) on the chosen weekdays only; each time runs once per day; the 3-hour lateness limit, pause, off, cap, the backlog rule and one-per-waybill-per-status-per-day all stay as in 048. Manual campaigns ignore the schedule (shown greyed) | Same rules, flexible times |
| Storage: extend `chatblast_campaigns` (small table) with the new schedule fields (weekdays, per-step times as part of the days JSON or a sibling JSON) via an additive migration; existing campaigns keep their texts, versions, statuses, pages and send times exactly; default weekdays = every day | Keep the live texts |
| Same access as /chatblast (NavAccess '/chatblast'); works on Likha and Incepxion (Incepxion runs `chatblast:auto` from cron every 5 minutes, not schedule:run) | Shared codebase |

## Tests

List page cards and counts; setup page per section saves each field with validation and log; sequence: add/remove/move steps and versions, texts kept on migration; preview renders; schedule: weekdays filter, multiple times, per-step time, auto run only at the right times and weekdays, once per time per day, nothing for manual/off; migration keeps both live campaigns exactly; access. Red first where natural. Whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

New J&T statuses logic, new placeholders, Botcake flow editing (the flow stays the page's flow.shipping).

## Final message

Commits; migration; the list page and the setup page (what each section does); how auto runs pick times, weekdays and per-step times; test lines and suite result; review findings and fixes; anything the brief got wrong.
