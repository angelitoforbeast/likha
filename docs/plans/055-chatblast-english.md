# Plan 055: the chatblast pages in English

Spec: `docs/specs/055-chatblast-english.md`. Text only; no migration, no behaviour change.

## What is UI here

Everything a user reads on `/chatblast`, `/chatblast/campaigns` (Messages) and `/chatblast/sequence` (Sequences):

- the three Blade views and their scripts (titles, labels, buttons, chips, notices, confirmations, empty states, tooltips);
- JSON `error` messages of `ChatblastController`, `ChatblastCampaignController`, `ChatblastMessageController`, and the
  validation messages they pass through from `Chatblast::invalid()`, `Chatblast::invalidTimes()`,
  `ChatblastSchedule::invalidRange()` and the service exceptions;
- the schedule summary (`ChatblastSchedule::summary`, day names) and the "Sequence for <name>" title;
- the flow panel's checks and errors (`ChatblastFlowInfo`);
- the row reasons shown in the /chatblast list and side panel (`botcake_messages.reason` for new rows) and the step
  label ("Day 3 · B").

Not UI, left as is: code comments, `Log::` lines, the artisan console output of `chatblast:build` / `chatblast:auto`
(no page; noted in the result), stored data (message versions and names, campaign days, `botcake_messages.text`, the
page template line, change-log entries, and the reasons already stored on existing rows).

## Reasons that code also matches

Four reasons are compared by code (`Chatblast::isTextReason`, and the build's same-day exclusion for the cross-sequence
and sequence-cap skips). New rows get the English text; the matchers accept both the English and the old Tagalog text,
so rows written before the release keep behaving the same. The old texts stay as `LEGACY_*` constants.

## Glossary

Messages, Sequences, Step, Version, Trigger, Schedule, Manual / Auto, On / Off, Cap; Day N; Mon Tue Wed Thu Fri Sat
Sun; "Every day, 9:15 AM and 3:00 PM; every hour, 9 AM–9 PM; Day 1 at 10:00 AM"; "Manual (times are not followed).";
"Off."; "sent (last 30 days)"; "Fetch new"; "Reload the page: this changed in another tab".

## Tasks

| # | Task | Side | Tier |
|---|------|------|------|
| 1 | Services and controllers: messages, reasons (with the legacy matchers), summary | backend | medium |
| 2 | The three views | frontend | low |
| 3 | Tests: update the string assertions; one English test per page; one English validation error | both | low |
