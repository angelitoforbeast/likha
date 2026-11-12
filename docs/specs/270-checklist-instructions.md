# 270: Checklist tasks can carry multi-line instructions with clickable links

Weight: bounded. Risk tier: medium (rendering text written by staff as HTML must stay XSS-safe). Size: small. Budget: 2 attempts per failing step; stop and report if blocked.

## Why
The owner added checklist tasks for marketing (e.g. "Ads Daily Output Check", "Encoding Timetable Check") and wants real instructions there: several steps on separate lines and a link that opens the page. Today the description is a one-line text input, printed escaped on one line, and URLs are not clickable.

## Stories
S1 (P1) In /checklist/manage, the task description (add and edit forms) is a textarea (about 5 rows, auto-grow is fine) that keeps line breaks; max length 3000 (validation and DB column fit — check the column type; widen with a migration only if needed, ALGORITHM=INPLACE where possible).
S2 (P1) On the staff checklist page (and anywhere else the description is shown), the description is shown with its line breaks, and links are clickable: full URLs (http/https), and in-app paths written as `likhaaitech.com/...` or starting with `/` followed by a path segment (e.g. `/ads_manager/daily-output`) become links that open in a new tab (`rel="noopener"`); in-app ones point to the current host. Lines starting with "1) ", "2) " or "- " may simply stay as text lines (no markdown needed).
S3 (P1) **Safe:** everything is HTML-escaped first; only the generated `<a>` and `<br>` are added; `javascript:`, `data:` and any other scheme never become links; test with `<script>`, `"><img onerror>`, and a `javascript:alert(1)` text.
S4 Existing one-line descriptions look the same as before (plus clickable links if they contain URLs).

## Constraints
- Smallest change: a small helper (e.g. App\Support\ChecklistText::render) used by the views, the two form fields, validation, tests. No other UI change.
- Commit: `feat(checklist): task instructions keep their lines and links are clickable (270)`; NO Co-Authored-By, Claude-Session or "Generated with" lines, no person names. Save spec as docs/specs/270-checklist-instructions.md and a result .result.md.

## Allowed
composer install, php artisan test (new tests + full suite once; known pre-existing failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest), vendor/bin/pint on changed PHP files, git add/commit, `git push -u origin feat/270-checklist-instructions`. Do NOT merge, open a PR, push to main or deploy.
