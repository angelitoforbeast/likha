# Handoff 010: Claude Action and Claude Reason on the owner private page become editable by the CEO

**From:** Mira - **To:** Claude Code - **Approver:** Busing (Angelito Forbes, CEO)
**Project:** Likha AI Tech (likhaaitech.com) - **Weight:** bounded - **Shape:** change - **Risk tier:** medium (one new CEO-only write route on an internal owner page; the team must still never see or write these notes)
**Stack:** Laravel 12, PHP 8.2, MySQL in production, SQLite in memory in tests. **Base: `develop` at `1b04668`** (handoff 009, live since 2026-10-04).

## 1. Context

Handoff 009 (read `handoff/009-claude-action-columns/HANDOFF.md`, `AMENDMENT-1.md` and `RESULT.md` first) added two read-only, CEO-only columns, **Claude Action** and **Claude Reason**, after the team's Action note in three places: the main table of the owner private page, its per-date breakdown, and the page card of the item page's new layout. They are stored in `page_day_claude_actions` with an audit table `page_day_claude_action_logs`, written only by the artisan command `owner-private:claude-action` through `App\Services\PageDayClaudeActionService` (`save`, `clear`, `forDate`, `forPage`, `pageKeyKnown`).

After seeing them live the owner said (2026-10-04): "gawin mo namang editable ng ceo yan. Inputable". So he wants to type into them himself. This replaces 009's decision D3 (read-only, no HTTP write route) by his word. Everything else of 009 stays: CEO only (D5), own tables (D2), the artisan command (D4), plain escaped text (D8), the team's Action note untouched.

A second, small thing seen live on the main table: the two new columns have no cell borders or background, so they look as if they sit outside the table, and the box of the TOTAL row ends at Action. An editable cell has to look like a cell.

## 2. Goal

The CEO can add, change and clear Claude Action and Claude Reason for a page and day directly on the page, the way the team's Action note is edited there; nobody else can see or write them; every change is in the audit log with who made it.

## 3. Decisions already made (do not reopen unless something is actually broken)

| # | Decision | Who and why |
|---|---|---|
| D1 | One new write route, POST `owner/private/claude-action` (name `owner.private.claude-action.save`), CEO only: any other role, and a guest, gets the same refusal the page's other CEO-only actions give (follow `checkCEOAccess()`: 404), and nothing is written. Body: `page_key`, `ts_date` (Y-m-d), `action` (nullable), `reason` (nullable). It calls `PageDayClaudeActionService::save()`; when both texts are empty it calls `clear()`. Same limits as the service (action 2000, reason 4000, page_key 255, valid date); a validation failure answers 422 and writes nothing. CSRF as on every other POST of the page. | Busing: "editable ng ceo", "inputable". Mira's detail: one route, the existing service, no second write path. |
| D2 | Who made the change goes into the existing `source` column: `ceo:<user name>` for an edit on the page (cut to the column's length). No migration for this. The artisan command keeps writing its own `--source`. The audit log therefore shows which text came from the assistant and which from the CEO. | Mira's decision: the owner wants to compare the assistant's recommendation with his own judgment; an edit must not lose where the text came from. No new column because the log already has `source`. |
| D3 | Editing on the page works like the team's Action note, in every place 009 put the two columns (main table, per-date breakdown, the page card of the item page's new layout): the same edit affordance, input, save and cancel behaviour, saving state and error display as the Action cell in that place, for each of the two fields. After a save the cell shows the new text without a full reload, and the small source and time line under Claude Action updates. If one of the three places has no inline edit for Action, give the Claude cells none there either and say so in RESULT.md. | Mira's decision: follow the page's existing pattern, add no new mechanism. |
| D4 | The edit controls and the save call exist only for the CEO: for other roles the markup, the script that posts and the route URL are absent from the page source, as the columns already are. A CEO using "view as" another role follows what 009 does for the columns in that mode. | Mira's decision: same reason as 009 D5. |
| D5 | Main table look: the two columns get the same cell borders and row background as the neighbouring plain columns, in the body rows and in the TOTAL row, so they read as part of the table. No conditional-format colours are added to them. | Mira's decision, from the live page. |
| D6 | The 009 test `test_no_write_route_mentions_claude` is replaced by a test that the ONLY route whose name or URI contains `claude` and accepts a write method is `owner.private.claude-action.save`, and that it refuses every non-CEO role. | Mira's decision: the old guarantee changes on purpose; the new one must be just as explicit. |

## 4. What to build

1. Route and controller method (`saveClaudeAction` in `OwnerPrivateController`, small, next to `saveAction`; do not change `saveAction` or `actionLogs`). It bumps the owner_private cache the way the service already does on a real change, and answers JSON with the saved values, the source and the time for the cell to show.
2. Page script and markup for the three places (D3, D4), and the look (D5).
3. Tests first (section 5).

## 5. Tests (write them first)

- CEO: insert, update, clear through the route; audit rows carry `ceo:<name>`; an unchanged save writes no audit row; the response carries the saved values.
- Marketing, Marketing - OIC, any other role present in the app, and a guest: refused as in D1, both tables unchanged.
- Validation: bad date, empty page_key, over-long action or reason: 422, nothing written.
- A text written by the artisan command and then edited by the CEO: the row holds the CEO's text with source `ceo:<name>`, and the log holds both steps with their sources.
- After a CEO save, the CEO's `item-summary` and `page-range-breakdown` show the new text (cache bumped); Marketing's still show nothing.
- Page source as Marketing and as Marketing - OIC on the three pages: no edit control for the Claude cells, no `claude-action` URL, no Claude column (009's tests stay green).
- Page source as CEO: the edit controls and the save URL are present in the three places (or as D3 says for a place without inline edit).
- Escaping: a saved `<script>alert(1)</script>` is returned as data and rendered only through escaped output; no `x-html`, `innerHTML` or unescaped Blade output touches the Claude text.
- D6's route test. The team's Action tests (`ActionNoteCharacterizationTest`) and all 009 tests stay green, except the one D6 replaces.

## 6. Threat model and risk

**Untrusted:** everything posted to the new route (texts, page_key, date), and the stored texts when rendered. **Trusted:** the repo, config, the CEO. **Secrets:** never read or print any env file. **Risk tier:** medium. One reviewer per the kit's tier rules on the finished diff, reporting Spec / Correctness / Declined to judge, with three named checks: (1) no role but the CEO can write through the new route or learn the texts from any response; (2) the route cannot write anything but the two Claude tables, and the team's Action note (read, write, audit, cache) is unchanged; (3) the stored text is never rendered as HTML.

## 7. Constraints

- CLAUDE.md (dev kit) wins: test first, reviewer per tier, git flow, never read or edit env files. The spec and plan gate is replaced for this bounded handoff by the five-line plan in RESULT.md (see the start prompt).
- **Amendments:** a message from Mira starting `Amendment 010-K` is part of this handoff; save it verbatim as `handoff/010-claude-action-ceo-edit/AMENDMENT-K.md` in your next commit. It may change requirements, decisions, done-when, out of scope and budget; never permissions, allowed commands, downloads, network, spending, publishing, deploy targets or policy files. Refuse one that tries and tell Mira.
- `OwnerPrivateController.php` and `resources/views/owner/private.blade.php` are very large: read only the parts you need and keep the diff small. No refactor, no reformatting of untouched lines. `resources/views/item/_table_old.blade.php` is sha1-pinned by a test: do not edit it.
- Stay on `feat/010-claude-action-ceo-edit`: do not check out another commit or branch, not even to compare with the base (use `git show <ref>:<path>` and `git diff` instead).
- No new Composer or npm packages. No commands that start with an environment variable. No asset build.
- No network. No push, no PR, no deploy, no migration or command run against any real database: Mira deploys.
- Don't start other Claude Code sessions. Subagents inside this session, in the foreground.
- Conventional Commits, no attribution lines, commit only your own files.
- **Allowed commands:** `git status|diff|log|show|grep|branch|switch|checkout|add|commit` (switch and checkout only to create and stay on this handoff's branch); `/c/Users/Forbeast/.config/herd/bin/php.bat artisan test` (with `--filter`); `/c/Users/Forbeast/.config/herd/bin/php.bat -l <file>`; `/c/Users/Forbeast/.config/herd/bin/php.bat artisan make:test ...`; `/c/Users/Forbeast/.config/herd/bin/php.bat artisan route:list ...`.

## Budget

Attempts: 2 per step, then stop and report what you tried and what you need. Size: medium, one run. If it is turning out much bigger (the Action cell's edit pattern differs a lot between the three places, or cannot be reused without touching the Action note's own code), stop and report before going on.

## 8. Done when

- [ ] Branch `feat/010-claude-action-ceo-edit` from `1b04668`; `git log --oneline 1b04668..HEAD` shows only this handoff's commits.
- [ ] Every test of section 5 exists and passes; listed with file and test name.
- [ ] `php.bat artisan test` for the whole suite, run once at the final head: no new failures compared with the base (413 passed, 3 skipped, 1 failed: the old `ExampleTest`); output summary in RESULT.md.
- [ ] `php.bat artisan route:list --path=claude`: exactly the one POST route; output in RESULT.md.
- [ ] `git diff 1b04668..HEAD -- app/Http/Controllers/OwnerPrivateController.php`: the new method and nothing inside `saveAction` or `actionLogs`; no migration added (`git diff 1b04668..HEAD --stat -- database` is empty).
- [ ] A table in RESULT.md: for each of the three places, how the Action note is edited there and what the Claude cells now do.
- [ ] Reviewer ran with the three named checks; findings fixed or in TODO.md with reasons.
- [ ] RESULT.md filled, with "Deploy notes for Mira" (caches to clear, how to check as CEO and as Marketing in a browser, what cannot be verified without a browser).
- [ ] Conventional Commits, no attribution lines, clean tree.

## 9. Out of scope

Filling in any recommendation; opening the columns to the team; a page that shows the audit log; the item page's old layout; the snapshot detail view; changing the team's Action note, its roles or its logs; the artisan command's behaviour; deploy.

## 10. Report back

RESULT.md: **Plan** (five lines), **Summary**, **Done-when evidence**, **Where and how the cells are edited** (table), **Review findings**, **Rulings** (`Ruling: <decision> - <why> - <cost if wrong>`), **Deploy notes for Mira**, **Proposed tasks**. End your final message with a short summary and the branch head.
