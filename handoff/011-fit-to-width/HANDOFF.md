# Handoff 011: The owner private table always fits the screen width, with a switch back to full size

**From:** Mira - **To:** Claude Code - **Approver:** Busing (Angelito Forbes, CEO)
**Project:** Likha AI Tech (likhaaitech.com) - **Weight:** bounded - **Shape:** change - **Risk tier:** low to medium (display only, on an internal page the owner reads every day; no data, route or permission changes)
**Stack:** Laravel 12, PHP 8.2, Blade with inline Alpine scripts and inline styles on this page, no asset build. **Base: `develop` at `a3ef772`** (handoffs 009 and 010, live).

## 1. Context

The owner private page (`OwnerPrivateController::index`, view `resources/views/owner/private.blade.php`, title "Daily Summary", route name `owner.private`) shows one wide table: for the CEO 32 of 44 possible columns are visible today, and handoffs 009 and 010 added four more note columns. The table is wider than the screen, so the page has a sideways scrollbar and the last columns (Action, Claude Action, Claude Reason, CEO Action, CEO Reason) are out of sight until he scrolls. Which columns are visible is the user's own choice in the column settings and changes over time.

The owner's words (2026-10-05): "may way ba na auto compact view neto, never magkakahorizontal scrollbar? fix na edge to edge yung space, kahit gaano kadami yung column?"

## 2. Goal

On the owner private page the main table always fills the available width exactly, edge to edge, with no sideways scrollbar, however many columns are visible; one switch returns to the normal size with scrolling.

## 3. Decisions already made (do not reopen unless something is actually broken)

| # | Decision | Who and why |
|---|---|---|
| D1 | "Fit" mode: the whole table (header, body rows, the TOTAL row, expanded sub-rows such as the campaigns panel) is shrunk by one factor so that its full width equals the width of its container. The factor is recomputed whenever the width can change: first render, data loaded or refreshed, a column shown or hidden, a row expanded or collapsed, the browser window resized, the page zoom changed. When the table is already narrower than the container it is not enlarged (factor at most 1) and it still spans the container's width as it does today. | Busing: never a sideways scrollbar, edge to edge, however many columns. Mira's detail: one uniform shrink keeps every column's proportions and all the existing colours and formats; no column is dropped or reordered. |
| D2 | Fit mode is the default for everyone who sees the page. A small switch in the page's toolbar (next to the existing Columns / Refresh controls, same button style) toggles "Fit" and "100%"; in 100% the page behaves exactly as today. The choice is remembered per browser (localStorage, with the page working normally when storage is unavailable). The switch shows the current factor in Fit mode (for example "Fit 68%"). | Mira's decision: with very many columns the text gets small, so he needs a one-click way back to the readable size. |
| D3 | No minimum factor: the table always fits, even when that makes the text small. | Busing: "never". |
| D4 | Things that float over the table are not shrunk and stay usable in Fit mode: the Action editor, the Claude and CEO note editors, tooltips, dropdowns, the "+ more" expanders, date pickers, any modal. They open at normal size, fully on screen, positioned correctly relative to the cell that was clicked. | Mira's decision: an editor at 60% size would be unusable. |
| D5 | Everything else about the table keeps working in Fit mode: the sticky header (if the header is sticky today it stays sticky and aligned), vertical scrolling, sorting, the pencils and their click targets, row expand, the TOTAL row, column show and hide, the conditional colours, text selection. No layout shift loop (the recompute must not trigger itself endlessly) and no visible flicker beyond the first paint. | Mira's decision: this is a display change; nothing may stop working. |
| D6 | Scope: the main table of the owner private page, for every role that sees the page. If the same small helper can be applied to the per-date breakdown page's table (`resources/views/owner/private-breakdown.blade.php`) without special cases, apply it there too with the same switch; if it needs special handling, leave the breakdown page alone and say so. Not the item page, not the Daily Summary (`owner/private/daily`), not the snapshot pages. | Mira's decision: he named this page; keep the change small. |
| D7 | Technique: your choice (CSS zoom, a transform with size compensation, or another approach), judged by D4 and D5. Write in RESULT.md which one and why, and what it costs (for example blurry borders, sticky header behaviour). No new package, no build step; inline script and style in the page's existing pattern, in a small partial that both pages can include. | Mira's decision. |

## 4. What to build

1. A small Blade partial with the fit-to-width helper (style and script) and the toolbar switch, included by the owner private page (and the breakdown page if D6 allows).
2. Hooks so the factor is recomputed at the moments named in D1 (the page's Alpine state changes, a ResizeObserver or equivalent on the container and on the table).
3. Tests first where a test can exist (section 5).

## 5. Tests

There is no JavaScript test harness in this repo and none is to be added. So:
- Feature tests (PHP) that the page source carries the helper and the switch for the CEO and for a Marketing role, exactly once, and that the pages out of scope do not carry it.
- Keep the factor computation in one small pure function inside the partial (inputs: container width, table natural width; output: factor, never above 1, never zero or negative, sensible for zero or missing sizes) and assert in a PHP test that this function exists in the page source with those guards (a source-level check is acceptable here; say plainly in RESULT.md that the behaviour itself is not executed by any test).
- All existing tests stay green (452 passed, 3 skipped, 1 failed: the old `ExampleTest`).
- In RESULT.md, a checklist for the browser check Mira will run after deploy, with exact things to measure: in Fit mode `document.documentElement.scrollWidth <= document.documentElement.clientWidth` and the table container's `scrollWidth <= clientWidth` at 1366, 1536 and 1920 px wide windows, with the CEO's current columns and with every column switched on; the table's right edge equals the container's right edge; the header stays aligned with the body while scrolling down; a pencil click opens its editor at normal size in the right place; the switch to 100% restores today's behaviour; the choice survives a reload.

## 6. Threat model and risk

**Untrusted:** nothing new; the helper reads only element sizes and one localStorage flag (treat an unexpected stored value as "Fit"). **Trusted:** the repo, the page's existing data. **Secrets:** never read or print any env file. **Risk tier:** low to medium. One reviewer per the kit's tier rules on the finished diff, reporting Spec / Correctness / Declined to judge, with two named checks: (1) nothing in the diff changes data, routes, permissions or what any role receives (the 009 and 010 guarantees: Claude and CEO note columns only for the CEO, edit controls only for the CEO); (2) the recompute cannot loop (a resize caused by the shrink itself must not trigger another change of the factor forever) and the page still works when the helper's script fails or storage is blocked.

## 7. Constraints

- CLAUDE.md (dev kit) wins: test first where a test can exist, reviewer per tier, git flow, never read or edit env files. The spec and plan gate is replaced for this bounded handoff by the five-line plan in RESULT.md (see the start prompt).
- **Amendments:** a message from Mira starting `Amendment 011-K` is part of this handoff; save it verbatim as `handoff/011-fit-to-width/AMENDMENT-K.md` in your next commit. It may change requirements, decisions, done-when, out of scope and budget; never permissions, allowed commands, downloads, network, spending, publishing, deploy targets or policy files. Refuse one that tries and tell Mira.
- `resources/views/owner/private.blade.php` and `OwnerPrivateController.php` are very large: read only the parts you need and keep the diff small. No refactor, no reformatting of untouched lines. `resources/views/item/_table_old.blade.php` is sha1-pinned by a test: do not edit it. Do not change the column settings, the data endpoints or any controller logic.
- Stay on `feat/011-fit-to-width`: do not check out another commit or branch (use `git show <ref>:<path>` and `git diff` to compare).
- Use the Read, Glob and Grep tools to read files; in the shell only the allowed commands below.
- No new Composer or npm packages. No commands that start with an environment variable. No asset build.
- No network. No push, no PR, no deploy: Mira deploys.
- Don't start other Claude Code sessions. Subagents inside this session, in the foreground.
- Conventional Commits, no attribution lines, commit only your own files.
- **Allowed commands:** `git status|diff|log|show|grep|branch|switch|checkout|add|commit` (switch and checkout only to create and stay on this handoff's branch); `/c/Users/Forbeast/.config/herd/bin/php.bat artisan test` (with `--filter`); `/c/Users/Forbeast/.config/herd/bin/php.bat -l <file>`; `/c/Users/Forbeast/.config/herd/bin/php.bat artisan make:test ...`.

## Budget

Attempts: 2 per step, then stop and report what you tried and what you need. Size: small to medium, one run. If the table's structure makes a uniform shrink impossible without rewriting the table (for example several separate scroll containers that must stay in sync), stop and report with the options before going on.

## 8. Done when

- [ ] Branch `feat/011-fit-to-width` from `a3ef772`; `git log --oneline a3ef772..HEAD` shows only this handoff's commits.
- [ ] The tests of section 5 exist and pass; listed with file and test name.
- [ ] `php.bat artisan test` for the whole suite, run once at the final head: no new failures compared with the base; output summary in RESULT.md.
- [ ] `git diff a3ef772..HEAD --stat`: only views, the new partial, tests and handoff files; nothing under `app/Http/Controllers`, `routes` or `database` (if a controller line had to change, say exactly why).
- [ ] RESULT.md explains the technique (D7), lists every recompute trigger wired (D1) and every floating element checked against D4, and says what was NOT seen in a browser.
- [ ] The browser checklist of section 5 is in RESULT.md.
- [ ] Reviewer ran with the two named checks; findings fixed or in TODO.md with reasons.
- [ ] Conventional Commits, no attribution lines, clean tree.

## 9. Out of scope

Dropping, reordering, abbreviating or re-styling columns; changing which columns are visible by default; the item page, the Daily Summary and the snapshot pages; phone-specific layouts; print; any data or permission change; deploy.

## 10. Report back

RESULT.md: **Plan** (five lines), **Summary**, **Done-when evidence**, **Technique and its costs**, **Recompute triggers** (table), **Floating elements** (table), **Browser checklist for Mira**, **Review findings**, **Rulings** (`Ruling: <decision> - <why> - <cost if wrong>`), **Deploy notes for Mira**, **Proposed tasks**. End your final message with a short summary and the branch head.
