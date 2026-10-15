# Brief 028: checklist tasks get "fields to extract" filled by the AI, and every task change is logged

The staff checklist (`ChecklistController`, views `resources/views/checklist/*`, models `Checklist*`) takes about 50 submissions a day. Each save runs the AI analysis and the approval check (`runAutoAnalysis()`, GPT-4o, reading the notes and the uploaded screenshots). The owner wants the numbers and facts in those submissions kept as data he and his assistant can read back and analyse day by day, without parsing free text. Today 8 tasks carry a number in a note line (e.g. `TOTAL STICKER (WAREHOUSE MAKATI) : 1,612`, `TOTAL RETRIEVED ORDER- 23`, `Pending Rate ... 2,01%` with a decimal comma, `Likha Website Import - 1645 | Likha Gsheet Order - 1645`), about 10 tasks carry numbers only in the screenshot (amount spent per campaign, Finance vs Bank balance, shipping fee, ChatGPT balance, Gsheet column values), and the rest are status checks. He also wants to know who changed which task: this morning several tasks were switched off and nobody knows by whom.

## Part A: a change log for tasks (commit on its own)

- New table `checklist_task_changes`: id, checklist_task_id (nullable for bulk rows if you need), user_id, action (created, updated, deleted, duplicated, activated, deactivated, reordered, bulk_assigned, bulk_type, bulk_submission_type, fields_changed), changes JSON (`{field: [before, after]}` for the fields that changed; assignee lists as before/after id lists), created_at.
- Written by every task write route of the controller (create, update, delete, duplicate, reorder, the bulk actions, and the new fields of Part B), in the same transaction as the change, by the signed-in user. A save that changes nothing writes nothing.
- Shown: on `/checklist/manage`, a small "History" link per task that opens its changes newest first (who, when, what changed, before → after, readable labels), and a page `/checklist/manage/history` listing all changes with a date range and a user filter. Visible to the same people who may edit that task under brief 026's rules (CEO all; OIC their department; creator their own tasks).

## Part B: fields to extract per task

- A task gets an optional list of fields (store as JSON on `checklist_tasks`, e.g. `extract_fields`): each field has a key (snake_case, unique in the task), a label, a type (`number`, `money` (PHP), `percent`, `text`, `date`, `yes_no`), and an optional hint for the AI (where to find it, e.g. "the number after TOTAL STICKER in the notes" or "Bank balance in the second screenshot"). Edited in the task's create/edit form on `/checklist/manage` with simple add/remove rows (no horizontal scroll at 1366 px). Who may edit follows brief 026's rules. Changes are logged by Part A (`fields_changed`).
- When a task has fields, every submission save and every manual re-run of the approval check also asks the AI for the values, from the notes AND the screenshots, as strict JSON keyed by field key (`null` when not found), in the same OpenAI call as the approval check if that keeps the verdict parsing reliable, otherwise in one extra call only for tasks that have fields. Parse defensively: a malformed answer stores nothing and logs a warning; it never breaks the save or the verdict.
- Normalise before storing: numbers accept `1,612`, `1612`, `2,01%` (decimal comma when it is the only separator and followed by 1-2 digits), `₱2,295.67`; dates to Y-m-d; yes_no to true/false.
- New table `checklist_submission_values`: id, submission_id, checklist_task_id, date (the submission's date), field_key, value_number (decimal 18,4, nullable), value_text (nullable), value_bool (nullable), source (`ai`), raw (the AI's string for that field), created_at, updated_at; unique (submission_id, field_key); a re-run replaces that submission's values.
- Shown: in the one-day report, under each submission, the extracted values as small label: value chips; in brief 026's "Over several days" view, a "Values" CSV (date, task, field, value, submitter) for the range and visibility rules of that view.
- No values are invented for tasks without fields; existing submissions are not back-filled in this brief.

## Tests

Part A: each write route writes the right change row (before/after), a no-op save writes none, a refused write (403) writes none, history visibility per role. Part B: the fields editor saves and validates (duplicate key, bad type); extraction with a faked OpenAI answer stores normalised values for each type (incl. `1,612`, `2,01%`, `₱2,295.67`, null), a malformed answer stores nothing and the verdict still saves, a re-run replaces values, the values CSV header and a row, visibility. Red first where natural. The whole suite must pass except the known `ExampleTest` and `ImportStartTest`; name any other.

## Out of scope

Back-filling old submissions, choosing the fields for each real task (the owner or his assistant will fill them in on the manage page after release), moving the AI to a queue, limiting re-checks, any change to who may submit.

## Final message

Commits; tables and columns; what the manage form, history and report show; how the extraction call is made and what one costs in extra tokens roughly; the test lines; exactly what the owner should click after release (no browser here).
