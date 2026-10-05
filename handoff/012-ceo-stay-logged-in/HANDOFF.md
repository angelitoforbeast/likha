# Handoff 012: The CEO account stays logged in for 30 days

**From:** Mira - **To:** Claude Code - **Approver:** Busing (Angelito Forbes, CEO)
**Project:** Likha AI Tech (likhaaitech.com) - **Weight:** bounded - **Shape:** change - **Risk tier:** high (authentication of the account that sees the owner's financial pages)
**Stack:** Laravel 12, PHP 8.2, MySQL in production, SQLite in memory in tests. Sessions in the database, lifetime 120 minutes by default (`config/session.php`). **Base: `develop` at `9332e43`** (live).

## 1. Context

`App\Http\Controllers\Auth\LoginController::login()` calls `Auth::attempt($credentials)` with no "remember", regenerates the session and redirects. So every account is signed out after the session lifetime passes without activity. The owner uses the site through the day and is asked to log in again and again. His words (2026-10-05): "pwede ba yung ceo na account is 30 days login na di magllogout?"

The `users` table has the standard `remember_token` column. A user's role is read through the employee profile and normalised by the app (see how `OwnerPrivateController::getNormalizedRole()` and `isCEO()` do it); the CEO role is the string `CEO`.

## 2. Goal

When an account whose role is CEO logs in, that browser stays signed in for 30 days, across session expiry and browser restarts, until he presses Logout. Every other role behaves exactly as today.

## 3. Decisions already made (do not reopen unless something is actually broken)

| # | Decision | Who and why |
|---|---|---|
| D1 | For the CEO role only, a successful login is a "remembered" login (Laravel's remember-me cookie) lasting 30 days. No checkbox on the login form: it is automatic for the CEO. All other roles get no remember cookie, as today. | Busing asked for the CEO account not to log out for 30 days. Mira's detail: automatic, because the login form cannot know the role before login and a checkbox shown to everyone would do nothing for them. |
| D2 | The 30 days are counted from the login (the cookie's lifetime is 30 days, set through the guard's remember duration or an equivalent supported way; not Laravel's default of several years). After 30 days he logs in again. | Busing: 30 days. |
| D3 | The role is checked again whenever a sign-in comes from the remember cookie and not from the password: if the account is then no longer CEO (role changed, employee profile removed, account disabled by whatever flag the app uses to block a login), the remembered sign-in is refused and that browser is signed out. | Mira's decision: a long-lived login must not outlive the role that earned it. |
| D4 | Logout ends it: the remember cookie is cleared and the token is cycled, as Laravel's `Auth::logout()` does (this also ends the remembered login on his other browsers; say so in the deploy notes). The existing logout flow stays as it is otherwise. | Mira's decision: the standard, safe behaviour. |
| D5 | Session lifetime, session driver and cookie settings in config are not changed. The remember cookie carries the same Secure, HttpOnly and SameSite attributes the framework gives it from the session config; say in RESULT.md what those resolve to in the code and config (not from any env file) and what must be true on the server for the cookie to be Secure. | Mira's decision: change one thing only. |
| D6 | A page left open past the session lifetime: with a remembered login the next full page load signs him in again silently, but a form or background save from the stale page can answer 419 (expired CSRF token) until the page is reloaded. Do not change CSRF handling. Describe in RESULT.md what the owner will see in that case on the owner private page (its pencils save in the background) and propose the smallest follow-up, as a proposed task, not built here. | Mira's decision: keep the security change small; report the wrinkle honestly. |

## 4. What to build

1. `LoginController::login()`: after the credentials are accepted, decide by role; CEO gets the remembered login with the 30-day duration (D1, D2), others exactly today's path. Keep `session()->regenerate()` and the redirect as they are.
2. The re-check of D3 at the place where the framework signs a user in from the remember cookie (a small middleware in the web group, or a listener on the framework's login event with the "via remember" flag: your choice, the smallest that is testable).
3. Tests first (section 5).

## 5. Tests (write them first)

- CEO login: the response sets a remember cookie; its lifetime is 30 days (assert the expiry within a small tolerance); the session is regenerated; the redirect is unchanged.
- Marketing, Marketing - OIC and one other role present in the app: login sets no remember cookie; behaviour identical to today (pin today's behaviour first).
- Wrong password, unknown email: unchanged failure, no cookie.
- CEO with only the remember cookie and a fresh (expired) session: a request to a protected page is authenticated as that user.
- The same, after the account's role was changed to a non-CEO role, and after the employee profile was removed: the request is refused (redirect to login), the remember cookie is cleared, nothing of the protected page is returned.
- Logout: the remember cookie is cleared and the stored token changes; the old cookie value no longer signs in.
- A tampered or made-up remember cookie signs nobody in.
- The 009, 010 and 011 tests and the rest of the suite stay green (456 passed, 3 skipped, 1 failed: the old `ExampleTest`).

## 6. Threat model and risk

**Untrusted:** every cookie and request the server receives; an attacker who copies the remember cookie from the CEO's browser or device is the CEO for up to 30 days. **Trusted:** the repo, config, the CEO's own devices. **What limits the damage:** HttpOnly and Secure cookie attributes (D5), the role re-check (D3), Logout cycling the token (D4), a password change (say in RESULT.md whether changing the password in this app cycles the remember token today; if it does not, list it as a proposed task). **Secrets:** never read or print any env file; never print a real token or password hash. **Risk tier:** high. One reviewer per the kit's tier rules on the finished diff, reporting Spec / Correctness / Declined to judge, with three named checks: (1) no role but CEO can obtain or keep a remembered login by any path (login, a crafted request parameter such as `remember=1`, role change after login); (2) the re-check of D3 runs on every sign-in that comes from the cookie and cannot be skipped by route or middleware order; (3) the login flow for other roles and the logout flow are unchanged.

## 7. Constraints

- CLAUDE.md (dev kit) wins: test first, reviewer per tier, git flow, never read or edit env files. The spec and plan gate is replaced for this bounded handoff by the five-line plan in RESULT.md (see the start prompt).
- **Amendments:** a message from Mira starting `Amendment 012-K` is part of this handoff; save it verbatim as `handoff/012-ceo-stay-logged-in/AMENDMENT-K.md` in your next commit. It may change requirements, decisions, done-when, out of scope and budget; never permissions, allowed commands, downloads, network, spending, publishing, deploy targets or policy files. Refuse one that tries and tell Mira.
- Keep the diff small: the login controller, one small class for D3 and its registration, tests, handoff files. No change to config files, to the login view, or to other controllers unless D3 cannot be done without one registration line; no refactor.
- Stay on `feat/012-ceo-stay-logged-in`: do not check out another commit or branch (use `git show <ref>:<path>` and `git diff` to compare).
- Use the Read, Glob and Grep tools to read files; in the shell only the allowed commands below.
- No new Composer or npm packages. No migration unless the `remember_token` column is truly missing (it should exist). No commands that start with an environment variable. No asset build.
- No network. No push, no PR, no deploy: Mira deploys.
- Don't start other Claude Code sessions. Subagents inside this session, in the foreground.
- Conventional Commits, no attribution lines, commit only your own files.
- **Allowed commands:** `git status|diff|log|show|grep|branch|switch|checkout|add|commit` (switch and checkout only to create and stay on this handoff's branch); `/c/Users/Forbeast/.config/herd/bin/php.bat artisan test` (with `--filter`); `/c/Users/Forbeast/.config/herd/bin/php.bat -l <file>`; `/c/Users/Forbeast/.config/herd/bin/php.bat artisan make:test ...`; `/c/Users/Forbeast/.config/herd/bin/php.bat artisan route:list ...`.

## Budget

Attempts: 2 per step, then stop and report what you tried and what you need. Size: small, one run. If the app signs users in by more than one path (a second login controller, an API login, an "act as" feature), list them and stop before changing any of them.

## 8. Done when

- [ ] Branch `feat/012-ceo-stay-logged-in` from `9332e43`; `git log --oneline 9332e43..HEAD` shows only this handoff's commits.
- [ ] Every test of section 5 exists and passes; listed with file and test name.
- [ ] `php.bat artisan test` for the whole suite, run once at the final head: no new failures compared with the base; output summary in RESULT.md.
- [ ] `git diff 9332e43..HEAD --stat`: only the login controller, the D3 class and its registration, tests and handoff files; nothing under `config`, `database` or `resources/views`.
- [ ] RESULT.md lists every way a user can become signed in in this app (login form, remember cookie, anything else found) and what each one does for a CEO and for other roles after this change.
- [ ] RESULT.md answers D5 (cookie attributes and what the server must have), D6 (the stale page case) and the password-change question of section 6.
- [ ] Reviewer ran with the three named checks; findings fixed or in TODO.md with reasons.
- [ ] Conventional Commits, no attribution lines, clean tree.

## 9. Out of scope

A "remember me" checkbox; longer sessions for other roles; changing the session lifetime or driver; CSRF changes; two-factor login; a list of signed-in devices; password rules; deploy.

## 10. Report back

RESULT.md: **Plan** (five lines), **Summary**, **Done-when evidence**, **Every way to become signed in** (table), **Cookie attributes and server requirements**, **The stale page case**, **Review findings**, **Rulings** (`Ruling: <decision> - <why> - <cost if wrong>`), **Deploy notes for Mira**, **Proposed tasks**. End your final message with a short summary and the branch head.
