# Spec 243: the Storage view and its banner follow the nav settings, not a fixed role

Type: change. Weight: bounded. Risk tier: low. Budget: small job, at most 2 attempts per step.

## Why

Spec 242 (live at 71cf1635) gave the Storage view (`/system-monitor/storage`) and the 80% storage banner to two fixed roles in code (`StorageWarning::canSee`: CEO or `Marketing - OIC`). Every other page in Likha is opened per role in `/owner/nav-settings` (`App\Support\NavAccess`, the `nav_links` and `nav_link_role_visibility` tables): checking a role there both shows the menu link and opens the page. The owner wants Storage to work the same way: decided by the checkbox, not by code. There is also no menu link today, so the OIC can only reach the page by typing the URL.

## Stories

S1 (P1). A nav link "Storage" → `/system-monitor/storage` exists and appears in `/owner/nav-settings` like the others.
- Happy: a migration adds it the way `2026_10_18_300100_add_chatblast_nav_link.php` adds Chatblast (idempotent; key `storage`; icon in the Font Awesome style the others use, e.g. `fa-solid fa-hard-drive`; sort order at the end), with a visibility row for each role the Chatblast migration lists, checked (`is_visible` true) for `CEO` and `Marketing - OIC`, unchecked for the others; `down()` removes it.
- A role checked in nav settings sees the link in the menu; an unchecked role does not.

S2 (P1). Access to the Storage view follows `NavAccess::allows($role, '/system-monitor/storage')`.
- Happy: a role checked for Storage opens the page; CEO always does (NavAccess already lets CEO in).
- Negative: an unchecked role gets 404, as today.
- Edge: after the owner unchecks `Marketing - OIC` in nav settings, the OIC gets 404 and no banner; after checking `Marketing` (no OIC), Marketing sees both.

S3 (P1). The storage banner (both layouts: `components/layout` and `layouts/chatblast`) shows to exactly the roles that may open the Storage view (the same `NavAccess` check), with the same thresholds (80% amber, 90% red), cache and failure behaviour as spec 242.
- Performance: the banner must not add a database query on every page view for every user. Cache the role's access answer (for example per role for 60 seconds, alongside the disk cache), and run no nav query at all while the disk is under 80% (check the cached disk first, then access).

## Decisions already made (do not reopen unless something is actually broken)

| Decision | Reason |
|---|---|
| Access comes from the nav settings checkbox, not from a role written in code. | The owner: it should be checked in nav settings, not hard-coded. |
| CEO and Marketing - OIC start checked; every other role starts unchecked. | That is who the owner gave Storage to in spec 242. |
| Rows in the existing nav tables; no new table. | The owner prefers no new tables; nav links are rows already. |
| The CEO's full System Monitor (`/system-monitor`) keeps its own CEO-only check. | Out of this change. |

## Threat model

Trusted: the nav tables (written only by the CEO through nav settings) and the server's disk numbers. Untrusted: request input (none used).

## Constraints

- Tests first: feature tests for every case above, using the nav tables as the existing nav tests do (see `tests/Feature/Chatblast/ChatblastAppTest.php` or `tests/Feature/AdAccount/AdAccountAccessTest.php` for how they seed `nav_links` and visibility).
- Update spec 242's tests that assumed the fixed roles, so they seed the nav rows instead.
- Keep pages byte for byte unchanged when there is no banner (the pin test and the chatblast layout check from spec 242).
- English UI text. Code comments explain the reason itself. Commit messages carry no Co-Authored-By, Claude-Session or "Generated with Claude Code" lines and name no people.
- No `.env`, server or deploy changes; the reviewer releases it after the owner's yes and runs the migration.

## Done when

1. Feature tests prove every case of S1, S2 and S3.
2. The test folders `tests/Feature/SystemMonitor`, `tests/Feature/Chatblast` and any nav-settings tests pass, and the full suite adds no new failures compared with `71cf1635` (2 known failures: ExampleTest and ItemTableFitScriptTest).
3. Exactly one new migration (the nav link), and `php artisan migrate --pretend` shows only its inserts.
4. Branch `feat/243-storage-nav-access` from `71cf1635`, pushed to `origin`; the result lists its head commit.

## Out of scope

The CEO's System Monitor access; other pages' access; any change to thresholds or what the Storage view shows.

## Report back

In `docs/specs/243-storage-nav-access.result.md`: plan, done-when evidence (commands and output), files changed, head commit, and the exact migration command for the release.
