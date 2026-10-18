# Plan 050: a setup page per chatblast campaign

Spec: `docs/specs/050-campaign-setup.md`.

Threat model: untrusted = everything a logged-in user sends to the campaign routes (texts, step positions, times,
weekdays, caps, pages, statuses) and order data shown in previews (customer names are stored external text: rendered as
text only). Trusted = migrations, config, the scheduler / cron.

## Design

**Migration** `2026_10_19_100000_add_schedule_to_chatblast_campaigns.php` (additive, guarded with `hasColumn`):

- `weekdays` JSON, nullable: ISO days 1 (Lun) … 7 (Lin). NULL = every day (the default; the two live campaigns get NULL).
- `step_times` JSON, nullable: one entry per step, aligned with `days`. An entry is `null` (the step uses the campaign's
  send times) or a list of `HH:MM`. NULL as a whole = no step has its own time.
- `daily_cap` unsigned int, nullable: at most N messages of this campaign per page per Manila day. It never raises the
  global per-page cap (`services.botcake.chatblast_daily_cap`), which still applies to all chatblast messages of a page.

Nothing else changes: `days`, `statuses`, `pages`, `send_times`, `mode`, `active` stay exactly as they are.

**Schedule logic** (`App\Services\ChatblastSchedule`, pure on a campaign + a Manila time):

- `timesFor(c, stepIndex)`: the step's own times, else the campaign's `send_times`.
- `allTimes(c)`: every distinct time of the campaign and its steps.
- `due(c, now)`: the times `t` of today with: today's weekday in `weekdays` (or every day), `t <= now < t + 3 h`, and
  `t > auto_ran_at` (the last auto run, also stamped when switching to auto, turning on, or adding a past time).
- `nextRun(c, now)`: the next date and time a run can happen (looking ahead up to 7 days), or null.
- `summary(c)`: "Araw-araw, 9:15 AM at 3:00 PM; Day 1 tuwing 10:00 AM", "Lun, Miy, Biy, …", "Mano-mano (hindi
  sinusunod ang oras)".

**Auto** (`chatblast:auto`): for every campaign that is on and in auto with due times: build, claim (the atomic
`auto_ran_at` update, now guarded by the latest due time), then queue the ready rows built today after the switch
(048) whose step is due: a row of step d is queued when any due time is one of `timesFor(c, d - 1)`. Rows of a step whose
time has not come stay ready for that step's own run. Pause, off, the 3-hour limit, the cap, the backlog rule and one
message per waybill per status per day are unchanged.

**Cap**: the sender checks the campaign's `daily_cap` for the row's page (this campaign's sent + unknown today), after
the global cap; a capped row is `skipped` as the global cap does ("Naabot ang daily cap ng campaign …").

**Routes** (same NavAccess `/chatblast`, named throttles):

- `GET /chatblast/campaigns` (list), `GET /data` (now with next run, summary, today's skipped).
- `GET /chatblast/campaigns/{id}` (setup page), `GET /{id}/detail` (campaign, steps with counts, preview orders).
- `POST /{id}` per field: adds `weekdays`, `daily_cap` to the 048 fields.
- Steps: the 048 `text`, `version`, `remove` (version), plus `step` (add after `day`, a copy of that day's A),
  `remove-step`, `move` (`day`, `dir` up/down), `step-times` (`day`, `times`, empty = the campaign's). Every step change
  edits `days` and `step_times` together under the row lock, logs, and re-renders the unsent rows (as 048).

**UI**: the list page becomes cards (name, trigger, pages count or "Lahat", on/off, mode, next run or "mano-mano",
today's built / sent / failed / skipped, "Buksan"); "+ Bagong campaign" opens a small form (name, statuses, first text)
and goes to the new setup page. The setup page has the three sections, each field saving on change or blur with its own
Saving / Saved / error mark. The flow panel (048-1) moves to the setup page's Sequence section as the template line
of the preview. The overlap warning stays on the list.

## Tasks

| # | Task | Side | Tier | Cases |
|---|------|------|------|-------|
| 1 | Migration and model | backend | medium | the two live campaigns keep every field; defaults |
| 2 | Schedule service and auto by time, weekday and step | backend | high | weekdays, several times, a step's own time, once per time per day, 3 h limit, nothing for manual/off, next run, summary |
| 3 | Per-campaign cap at send | backend | high | cap per page for the campaign; the global cap still applies |
| 4 | Setup endpoints: fields, steps, step times, detail | backend | high | validation and log per field; add/remove/move steps and versions with their times; access |
| 5 | List page as cards | frontend | medium | cards, counts, next run |
| 6 | Setup page (three sections) | frontend | medium | renders; preview |

Tasks 2–4 share `Chatblast*.php` and the controller: done in order. One adversarial review of the whole diff before the
result.

## Amendment 1: messages library and sequences (replaces the setup page and the list-as-cards)

**Storage.** New table `chatblast_messages` (`name` unique, `versions` JSON, `log` JSON). `chatblast_campaigns` gets
`step_messages` (JSON list of message ids, one per step). The campaign's `days` stays, as the resolved texts of its
steps: it is rewritten in the same transaction whenever a step or one of its messages changes, so every build and send
rule (046-048) keeps reading `days` unchanged. Migration `2026_10_19_100100`: for each live campaign, each day becomes a
message "<campaign> · Day N" with exactly that day's versions, and `step_messages` points to them. `botcake_messages`
rows are untouched (kind, day, version).

**Service.** `Chatblast::editMessage()` (lock the message, validate its versions, save, re-resolve every campaign that
uses it, re-render their unsent rows) and `Chatblast::editSteps()` (lock the campaign, change its message ids and own
times together, resolve, validate, re-render). `setSequence()` (tests) writes through the step messages.

**Pages.**
- `/chatblast/campaigns` = **Mga mensahe**: every message with its versions edited in place (blur / Enter), placeholder
  chips, a preview with the chosen page's template line, where it is used, send counts; add, rename, duplicate,
  delete (refused while used); search by name, filter by sequence. The flow panel (048-1) stays here.
- `/chatblast/sequence` = **Sequences**: one block per campaign ("Sequence for <name>"), all on one page: trigger
  fields, the step chain (message picker with its text inline, own times, today's counts, add after / remove / move),
  schedule (time chips, weekdays, manual/auto with the 048 confirmation, on/off, cap), summary, next run; "+ Bagong
  sequence"; the overlap warning.

**Routes.** Messages: `POST /chatblast/messages`, `POST|DELETE /chatblast/messages/{id}`, `/{id}/duplicate`,
`/{id}/text`, `/{id}/version`, `/{id}/remove-version`, and `GET /chatblast/campaigns/data`. Sequences:
`GET /chatblast/sequence`, `/data`, `POST /chatblast/sequence`, `POST|DELETE /chatblast/sequence/{id}`,
`/{id}/step`, `/remove-step`, `/move`, `/step-message`, `/step-times`. The 048 text/day/version/remove routes go.

| # | Task | Side | Tier |
|---|------|------|------|
| A1 | Messages table, step_messages, conversion migration | backend | medium |
| A2 | editMessage / editSteps and the resolve of days | backend | high |
| A3 | Message and sequence endpoints (048 tests moved to them) | backend | high |
| A4 | Mga mensahe page | frontend | medium |
| A5 | Sequences page | frontend | medium |
