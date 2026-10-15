# Result: brief 028 Checklist fields to extract, and a change log for tasks

> Committed beside the spec: names no people and no decision ids.

**Status:** done (not seen in a browser: no browser in this session, the owner's clicks are listed below)
**Date:** 2026-10-15
**Branch / PR:** `feat/028-checklist-fields-log`, cut from `feat/026-checklist-access-report` at bfcbc74 (no PR, no push)

## Commits

| Commit | What |
| --- | --- |
| `1fcf6b8` | docs: the brief, verbatim |
| `876995e` | Part A: the change log, the history page and its tests |
| `898e296` | Part B: fields to extract, the extraction in the approval check, chips, Values CSV and tests |
| (this one) | docs: this result and the accepted points in `TODO.md` |

## Tables and columns (3 new migrations, all guarded with `Schema::hasTable` / `hasColumn`)

- `checklist_task_changes`: `id`, `checklist_task_id` (FK, null on delete; always filled), `user_id` (FK, null on delete),
  `action` (string 40), `changes` (JSON `{field: [before, after]}`), `created_at`. Indexes (task, created_at) and created_at.
- `checklist_tasks.extract_fields`: JSON, nullable: `[{key, label, type, hint}]`.
- `checklist_submission_values`: `id`, `submission_id` (FK, cascade), `checklist_task_id` (FK, cascade), `date`,
  `field_key` (40), `value_number` decimal(18,4), `value_text` text, `value_bool` boolean, `source` ('ai'), `raw` text,
  timestamps. Unique (submission_id, field_key); index (date, checklist_task_id).

Run on the server: `php artisan migrate --force` (the three `2026_10_15_00000*` files).

## Part A: the change log

Every task write route writes its row in the same `DB::transaction` as the change, as the signed-in user; the access
rules of brief 026 run first, so a refused write writes nothing. A save that changes nothing writes nothing.

| Route | Action | `changes` |
| --- | --- | --- |
| POST `/checklist/tasks` | `created` | every field set, `[null, value]`; `assigned_users [[], ids]` |
| PATCH `/checklist/tasks/{task}` | `updated`, or `activated` / `deactivated` when `is_active` is among the changes | only the changed fields; `assigned_users [before ids, after ids]` (sorted) |
| same, when the fields to extract changed | `fields_changed` (its own row) | `extract_fields [before list, after list]` |
| DELETE `/checklist/tasks/{task}` | `deleted` | `deleted [false, true]` |
| POST `/checklist/tasks/{task}/duplicate` | `duplicated` (on the new task) | `copy_of [null, "#id title"]`, `title [null, "… (Copy)"]` |
| POST `/checklist/tasks/reorder` | `reordered`, one row per task whose position changed | `sort_order [before, after]` |
| POST `/checklist/tasks/bulk-assign`, `/bulk-type`, `/bulk-submission-type` | `bulk_assigned`, `bulk_type`, `bulk_submission_type`, one row per task that changed | the one field |

"08:00" vs "08:00:00" in the time is not a change, and neither is the same fields list in another JSON key order
(MySQL's JSON column returns keys reordered; without this every "Save Changes" would log a false `fields_changed`).

**Shown:** `/checklist/manage` has a **🕘 History** button in the header and a **History** link in each task's actions
(only on tasks you may edit). `/checklist/manage/history` lists changes newest first: when (Manila time), who, what
("Created", "Edited", "Disabled", "Enabled", "Deleted", "Created as a copy", "Reordered", "Bulk assign", "Bulk type",
"Bulk submission type", "Fields to extract"), the task, and one line per field: "Active: Yes → No",
"Assigned to: Ana → Ana, Ben" (names, "Anyone" for an empty list). Filters: From / To (default the last 30 days; a
single task's link shows its whole history) and a user select. 100 per page.
**Who sees what:** the same rule as editing (one helper, `canEditTask()`): CEO all tasks, OIC the tasks of their
department, the creator their own tasks, deleted tasks included. `?task=` of a task you may not edit answers 403; the
list simply leaves those tasks out.

## Part B: fields to extract

**Manage form:** in "+ Add New Task" and in each task's Edit, under the two prompts, "Fields to extract" with
**+ Add field** and one row per field: Label, key (filled from the label when left empty; snake_case), Type (Number,
Money (₱), Percent, Text, Date, Yes / No), a hint for the AI, and ✕ to remove. A 12-column grid of 5 controls, no
fixed widths, so it adds no width at 1366 px. The task row shows "📊 label, label" when it has fields. Validation:
key required, `^[a-z][a-z0-9_]*$`, max 40, unique in the task; label required; type one of the six; hint max 300;
at most 20 fields. Removing every row clears the fields; the **Disable/Enable** button's hidden form does not send
fields, so it leaves them as they are. Who may edit: brief 026's `update-task` rule (unchanged).

**Extraction call:** in the **same** OpenAI call as the approval check, on every save (`runAutoAnalysis()`) and every
manual re-run (`approvalCheck()`), only for tasks with fields. The prompt keeps the verdict instruction ("MUST start
with APPROVED / NOT APPROVED on the first line") and adds: after the explanation, ONE last line
`EXTRACTED_VALUES: {json}` with exactly the field keys, each with its label, kind and hint, values copied as written,
`null` when not found. The verdict is parsed from the first line exactly as before; the values line is then cut off,
so the stored and shown approval text looks as before. Parsing takes the last `EXTRACTED_VALUES:` and the `{…}` after
it; anything that is not a JSON object (missing, cut off, a list) stores nothing, logs
`Checklist values: malformed AI answer` with the submission id, and the verdict saves as usual. A good answer replaces
that submission's values in one transaction (a key missing from the answer is stored as not found). A malformed re-run
keeps the previous values (see `TODO.md`).

**Normalising** (`ChecklistSubmissionValue::fromAi()`): number / money / percent → `value_number`: `1,612` → 1612,
`1612` → 1612, `2,01%` → 2.01 (a comma is decimal only when it is the only separator, alone, and followed by 1-2
digits), `₱2,295.67` → 2295.67, `1,612,000` → 1612000, `-₱500` → -500, `ORDER- 23` → 23 (a dash before a space is
not a minus); date → `value_text` Y-m-d (text without digits, like "today", is not a date); yes_no → `value_bool`
(yes/true/oo/opo/done/ok, no/false/hindi/wala/none); text → `value_text` trimmed. `raw` keeps the AI's string.

**Cost:** no extra call and no image is sent twice. Extra input ≈ 90 tokens plus ≈ 25 per field (with its hint);
extra output ≈ 10-15 tokens per field. A 3-field task: ≈ 165 input + 45 output tokens, about $0.0009 per save at
GPT-4o's list price of $2.50 / $10 per million tokens (check the current price); 50 saves a day ≈ $0.05. A separate
call would have re-sent the screenshots (roughly 800-1,500 tokens each).

**Report:** the one-day view shows the values under each submission's notes (group and individual rows) as small
"Label: value" chips ("Total sticker: 1,612", "Bank balance: ₱2,295.67", "Pending rate: 2.01%", "All posted: No");
hovering a chip shows what the AI read. The "Over several days" view has **⬇ Values CSV** beside the two CSVs:
`Date, Task, Field, Value, Submitter`, one row per stored value in the range, for the tasks that view shows (same
visibility and department filter). Numbers are plain (`1612`, `2295.67`, `2.01`), yes/no as `yes`/`no`, text with the
formula guard. The range page's own queries are unchanged (the CSV branch runs one query); the day view adds one.

No values are made for tasks without fields (they are not asked); old submissions are not back-filled.

## Tests

New: `ChecklistTaskChangeLogTest` (24), `ChecklistExtractFieldsTest` (31); with brief 026's 95, the checklist folder runs 150. `ChecklistTestCase` now runs the three new
migrations (they are sqlite-safe) and lets a test choose the fake OpenAI answer (`$aiAnswer`); OpenAI stays
`Http::fake()`d with stray requests blocked.

| Test | Result |
| --- | --- |
| Each write route logs one row with the right action, task, user and before/after (create, edit, disable, delete, duplicate, reorder, 3 bulk) | 9 pass |
| A no-op edit, bulk type, bulk assign and reorder write nothing | pass |
| An edit that also switches the task off logs `deactivated` with every change (title, is_active, assignee id lists) | pass |
| A refused write (403) logs nothing (update, disable, delete, reorder, bulk type) | 5 pass |
| History visibility per role (CEO, OIC same dept, creator see; OIC other dept, staff, assigned staff get 403 / an empty list; the other department's task only for CEO and its OIC) | 6 pass |
| History shows who, readable labels, before → after, newest first | pass |
| History filters by date range and by user | pass |
| Fields editor saves the list, logs `fields_changed`, the Disable form keeps it, an empty list clears it | pass |
| Same fields in another JSON key order log nothing; manage shows "📊 Total sticker" | pass |
| A new task can be created with fields (and the `created` row carries them) | pass |
| Editor refuses a duplicate key, a bad type, a non-snake_case key, an empty label (nothing saved, nothing logged) | 4 pass |
| A save asks for the values in the approval call (once, with the hint) and stores them normalised: `1,612`, `₱2,295.67`, `2,01%`, text, date, yes, null | pass |
| Normalising table (16 inputs incl. `1,612`, `1612`, `2,01%`, `₱2,295.67`, null, `-₱500`, `ORDER- 23`, dates, yes/no) | 16 pass |
| A malformed answer (cut-off JSON, no values line, a list) stores nothing, logs one warning, the verdict saves | 3 pass |
| A re-run of the approval check replaces the values; a malformed re-run keeps them and still returns its verdict | pass |
| A task without fields is not asked and gets no values | pass |
| Day report shows the chips under the submission | pass |
| Values CSV header and rows (with the formula guard), and a non-CEO gets only their task's rows | pass |

**Red runs:** Part A, every write test failed with `Failed asserting that actual size 0 matches expected size 1` and
the history tests errored because the route did not exist yet. Part B, the editor tests failed with
`Failed asserting that null is identical to Array`, the validation cases with `Failed asserting that false is true`,
extraction/CSV/chips with missing values, and the key-order case with `Failed asserting that 1 is identical to 0`.
Not red first: the normalising table, because `ChecklistSubmissionValue::fromAi()` was written just before its test.

**Full suite** (`vendor/bin/phpunit`): 1064 tests, 14879 assertions, 1 error and 1 failure, both known:
`ImportStartTest` (`storage/app/credentials.json` missing in a fresh worktree) and `ExampleTest` (302 instead of
200). No other failure. `php -l` clean on every changed PHP file. `php artisan test` also prints a
`file_get_contents` warning on each checklist test in this fresh worktree (also on brief 026's tests); `phpunit` runs
them clean. No `npm run build` needed: the layout loads Tailwind from its CDN script and no JS/CSS asset changed.

## Review

No separate reviewer run (fast mode). Self-checked: the history route's `task`, `user`, `from`, `to` parameters
(arrays or junk fall back or still pass the edit rule), the CSV's visibility (the range view's own task list), the
transaction around each write and its log, and that a refused write stops before any write or log.
Accepted points with reasons are in `TODO.md` under 028, including one pre-existing bug found while reading the
manage form: **weekly tasks are saved without their day** (two inputs named `frequency_day`; the hidden monthly box
is sent last), so they count as due on Sundays. The new history will show it as "Frequency day: 3 → —".

## Decisions made here

- Values come from the approval call, not a separate one: cheaper (no image re-sent) and the verdict parsing is
  unchanged because the values line is the last line.
- `checklist_task_id` on the change log is always filled (bulk actions write one row per task), so each task's
  history is complete and follows that task's edit rights.
- A malformed re-run keeps the previous values; a missing key in a good answer is stored as "not found".
- The Values CSV's "Field" column uses the field's current label (the key when the field was since removed).

## For the owner, after release

1. Open **Checklist → Manage**. Click **Edit** on a task that has a number in its notes (e.g. the sticker count).
2. Under the prompts, click **+ Add field**. Label `Total sticker` (the key fills itself on leaving the box), Type
   **Number**, hint `the number after TOTAL STICKER in the notes`. For a screenshot value, e.g. Label `Bank balance`,
   Type **Money (₱)**, hint `Bank balance in the second screenshot`. Click **Save Changes**; the task row shows
   "📊 Total sticker".
3. Try a second field with the same key: the page says the keys must differ.
4. Click **History** on that task: one row "Fields to extract" with your name and the list. Click **🕘 History** at the
   top: all changes of the last 30 days; pick yourself in **Everyone** and click **Show**.
5. Click **Disable** on a test task, then **Enable**: the history shows "Disabled … Active: Yes → No" and "Enabled".
6. Ask the staff member of that task to submit as usual. Open **View Report**: under the notes you see
   "Total sticker: 1,612". Click **↻ Re-analyze** on that row (it re-runs the approval check), then reload the page: the chip shows the new reading.
7. Open **Over several days** and click **⬇ Values CSV**: a row with the date, task, `Total sticker`, `1612` and the
   submitter.
8. Fill in the fields for the other number tasks the same way; the values start from each task's next save.

## Story changes

None (fast mode, no stories file for this brief).

## Conflicts

None between the brief and the project's instructions.
