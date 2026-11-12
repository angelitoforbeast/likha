# Result 270: Checklist task instructions keep their lines and links are clickable

Branch `feat/270-checklist-instructions` from `e4f37b91` (live). Risk tier kept: medium (staff text rendered as HTML). No migration, table, route or access change.

## What changed

| File | Change |
|---|---|
| `app/Support/ChecklistText.php` | New. `ChecklistText::render(?string): HtmlString`: escapes all text, adds `<br>` per line and `<a>` for the link rules below. |
| `app/Http/Controllers/ChecklistController.php` | `storeTask` and `updateTask`: `description` max 1000 → **3000**; CRLF/CR from the textarea is saved as one `\n` per line (`normalizeDescriptionLines`), so the form's `maxlength="3000"` and the server count the same. |
| `resources/views/checklist/manage.blade.php` | Add and edit forms: the description `<input type="text">` is now a `<textarea rows="5" maxlength="3000">` that grows as you type. In the edit row it spans the full row (`sm:col-span-4`) so the instructions are readable; the time, deadline and group fields move to the line below. The one-line preview in the task list stays as it was (truncated). |
| `resources/views/checklist/index.blade.php` | Staff page Description column: `ChecklistText::render`. |
| `resources/views/checklist/report.blade.php` | Both Description cells: `ChecklistText::render`. |
| `tests/Unit/ChecklistTextTest.php` | New: 23 cases (lines, links, XSS, broken UTF-8). |
| `tests/Feature/Checklist/ChecklistInstructionsTest.php` | New: add/edit forms (3000 limit, CRLF, textarea), staff page, report, one-line descriptions. |

## Database
`checklist_tasks.description` is already `TEXT` (create migration `2026_04_11_000001`): up to 65,535 bytes, so 3000 characters fit even at 4 bytes each. No migration.

## Where the description is shown
- `/checklist` (staff page), Description column: rendered.
- `/checklist/report`, both Description cells (day view and the per-user table): rendered.
- `/checklist/manage` task list: one-line truncated preview, unchanged (escaped).
- The enable/disable form in manage keeps it in a hidden input (newlines survive in hidden inputs).
- AI prompts (analyze / approval) use the raw text, unchanged.

## Link rules
1. `http://` or `https://` followed by a host: linked as written.
2. `likhaaitech.com/...` or `www.likhaaitech.com/...` (not inside an email or a longer domain such as `likhaaitech.com.ph`): linked to the same path on the current host (relative `href="/..."`); no path → `/`.
3. `/` followed by a letter, digit, `_` or `-` (e.g. `/ads_manager/daily-output`), not after a word character, `.`, `:`, `/`, `@` or `<` (so `1/2`, `and/or`, `24/7`, `</script>`, `//evil.com` and `file:///` are not links): linked relative, current host.
4. Trailing `.,;:!?'"]}` is left out of the link; a trailing `)` is left out unless the link has a matching `(`.
5. All links: `target="_blank" rel="noopener"`, class `text-blue-600 underline break-all`. Lines like `1) `, `2) `, `- ` stay plain text.

## Safety
Text between links is passed through `e()` (htmlspecialchars, ENT_QUOTES); the link text and the `href` are also passed through `e()`. A link stops at whitespace, `< > " '` and backtick, so it cannot break out of the attribute. Only rules 1-3 make an `<a>`, so `javascript:`, `data:`, `vbscript:`, `ftp:`, `file:` and protocol-relative `//host` stay text. Broken UTF-8 → escaped text without links. Blade prints the result with `{{ }}` (it is an `HtmlString`), no `{!! !!}`.

Tested inputs (escaped, no tag, no link): `<script>alert(1)</script>`, `"><img src=x onerror=alert(1)>`, `javascript:alert(1)`, `javascript://x/%0Aalert(1)`, `JAVASCRIPT:/x`, a `data:text/html;base64,...` string, `ftp:// vbscript: file:///`, `//evil.example/x`, `/\evil.example`, `https://x.com/"onmouseover="alert(1)`, `https://x.com/<script>`, `/ads_manager/'onclick='alert(1)`, `&lt;b&gt;`. Each case also asserts the output has no `<` other than `<a href="http(s)://…`, `<a href="/…`, `</a>` and `<br>`. The feature test checks the staff page and the report with the same text.

## Tests
- New: `tests/Unit/ChecklistTextTest.php` + `tests/Feature/Checklist/ChecklistInstructionsTest.php`: 23 passed (65 assertions).
- Full suite: 3 failed (the known ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest), all other tests passed. The warnings come from the worktree having no `.env` (the existing checklist tests show the same warning).
- Pint: run on the three new PHP files. `ChecklistController.php` was already not pint-clean on `main`, so it was not reformatted (the diff stays small).

## Not done
- Not checked in a browser (no local app with data in this worktree); the forms and rendering are covered by the feature tests.
