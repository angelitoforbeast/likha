# Result 012: The CEO account stays logged in for 30 days

Status: **done: first run, Amendment 012-1 and Amendment 012-2 built and reviewed; no blocker or major open; waiting for Mira's review of the branch.** The file is in three parts, oldest first; **"Amendment 012-2" at the end is the current state** and wins wherever an earlier section disagrees (its sign-in table and its deploy checklist replace the earlier ones). The first run's open major (30 days enforced only by the browser) was closed by A1; the second (open sessions outliving Logout and a password change) by B1 and B2. Branch `feat/012-ceo-stay-logged-in`, cut from `develop` at `9332e43`. Nothing was pushed, merged or deployed, and there is no PR, so this file stands in for the PR body. Nothing was seen in a browser (no browser tools, no dev server in the allowed commands).

## Plan

1. First commit: this handoff saved verbatim, this file, and the row in `handoff/README.md`.
2. Tests first (`tests/Feature/Auth/`): pin today's login, failure and logout behaviour for the non-CEO roles, then the red tests of section 5 (CEO remember cookie with a 30-day expiry, sign-in from the cookie alone, refusal after a role change or a removed profile, logout cycles the token, a tampered cookie signs nobody in).
3. `LoginController::login()`: the same `Auth::attempt($credentials)` as today; after the password is accepted, the account's role decides: for a CEO the guard's remember duration is set to 43,200 minutes (30 days) and the remember cookie is issued. No request parameter is read; `session()->regenerate()` and the redirect stay as they are. (Corrected after review: the first wording had the role decide the `remember` argument of `Auth::attempt` before the password check; that read the profile on failed logins, see finding 4.)
4. D3 as one listener class on the framework's `Login` event (`app/Listeners/`, next to the existing `CopyEverydayTasksOnLogin`, picked up by the framework's listener discovery so there is no registration line): when the sign-in came from the remember cookie (`viaRemember()`) and the account is not CEO, logout. It runs inside the guard at the moment of the cookie sign-in, so no route or middleware order can skip it.
5. `skeptic-reviewer` at adversarial depth (high tier) with the three named checks, fixes or `TODO.md`, the full suite at the end, evidence per done-when item in this file; no push, no PR, no deploy.

## Summary

When an account whose role reads as CEO logs in with its password, the browser now also gets Laravel's remember cookie with a 30-day lifetime, counted from that login (the cookie is not renewed by later visits). When the session lapses or the browser is restarted, the next page load signs him in again from the cookie. Logout clears the cookie and cycles the stored token. Every other role logs in exactly as before and gets no cookie, whatever the form posts.

Whenever the framework signs someone in from the cookie, `App\Listeners\RefuseRememberedLoginUnlessCeo` reads the role again. If the account is not CEO then (role changed, employee profile removed, or the role cannot be read because of an error), it is logged out on the spot: the request goes to the login page, the cookie is cleared and the token is cycled, so the old cookie is dead for good.

Six things for Mira to know (as written after the first run; Amendment 012-1 closed 1, 3 and 4):

1. **Open major: the 30 days are a browser-side limit only.** A copied cookie value works after day 30 until the CEO presses Logout. Details and options under "Question for Mira". The handoff's own threat model line ("is the CEO for up to 30 days") is not true for the build as specified in D2.
2. **The app has no flag that blocks a login.** Login is a bare `Auth::attempt`; `employee_profiles.status` is set to `Active` on creation and nothing reads it at login. So D3's "account disabled by whatever flag the app uses" has nothing to check; the re-check covers a role change and a removed profile.
3. **Changing a password in this app does not end a remembered login** (section 6 question). `OwnerUsersController::updatePassword` writes only the password with a direct table update and never touches `remember_token`, and this framework version checks only the id and the token of the cookie, not the password part. Proposed task 2.
4. **No registration line.** The listener is found by the framework's listener discovery, exactly as the existing `CopyEverydayTasksOnLogin` is (`app/Providers/EventServiceProvider.php` and `app/Http/Kernel.php` are dead files in this Laravel 12 app; only `AppServiceProvider` is registered). If the server has a cached events file, the listener is not loaded until the cache is rebuilt: see the deploy notes, this one matters.
5. **One CEO password login fires the framework's `Login` event twice** (once from `Auth::attempt`, once from issuing the cookie). The only other listener copies the day's everyday tasks and skips ones that exist, so the effect today is a few repeated queries. The `Login` event also fires on every cookie sign-in, so his everyday tasks are now copied on those too (useful: before, only a password login did it).
6. **Stale page (D6):** saves from a page left open past the session lifetime answer "CSRF token mismatch." until he reloads; nothing typed is lost while the editor stays open. See "The stale page case".

## Question for Mira (blocker for "safe to ship", not for the code as specified)

**Answered by Amendment 012-1: option c, built (A1).** Kept as written for the record.

**Finding:** the remember cookie's value is `user id | remember token | password hash`, encrypted. It carries no issue time. On a cookie sign-in the framework compares only the id and the token (`SessionGuard::userFromRecaller` → `retrieveByToken`). The stored token is created once and reused by every later login (`ensureRememberTokenIsSet`); only `Auth::logout()` cycles it. So the 30 days are the browser's expiry date and nothing else: whoever copies the cookie value from the CEO's browser or device can send it on day 31 or day 300 and is the CEO, and D3 passes because the account is still CEO. The feature's purpose is that he does not press Logout, so the token may never cycle.

Options, smallest first:

| | Fix | Cost |
|---|---|---|
| a | Cycle the token on every CEO password login, before the cookie is issued | No migration, about 3 lines. A copied cookie dies at his next password login. But each login signs out his other browsers' remembered login: with a phone and a laptop he is asked to log in whenever he switches after the session lapsed, which is the complaint this handoff fixes. Only good if he uses one browser. |
| b | A stored expiry (`remember_expires_at` on `users`, set at CEO login, checked in the listener) | A migration (the handoff forbids one, so an amendment). One expiry for the account: a login on a second device extends it for the first device's cookie too. Simple and visible in the database. |
| c | A second encrypted cookie holding the issue time and a hash of the token, issued with the remember cookie; the listener refuses a cookie sign-in when it is missing, does not match the token, or is older than 30 days | No migration, about 20 lines in the same two files. True per-browser 30 days, enforced by the server (the app key protects the value). A new small design in the auth path; needs its own tests and review. |

**Recommendation: c**, or **b** if Mira prefers state in the database over a second cookie. I did not build either: D2 names the mechanism ("the cookie's lifetime is 30 days, set through the guard's remember duration"), every fix changes a decision or a constraint, and Mira's go covers a plan inside section 4. Until it is decided, the honest description for Busing is: "a stolen cookie works until you press Logout", not "for up to 30 days".

## Done-when evidence

**1. Branch from `9332e43`, only this handoff's commits.** `git log --oneline 9332e43..HEAD` (before this file's own commit, which is the last one, `docs:`):

```
34ea737 docs: re-check findings and reviewer memory for handoff 012
8baeb93 fix: failed login reads no employee profile, CEO role is checked after the password is accepted
f1aac2c docs: review findings and reviewer memory for handoff 012
70db3df fix: remembered sign-in fails closed when the role check throws
ee40909 feat: CEO login is remembered for 30 days, cookie sign-in refused for any other role
2c24de1 test: pin today's login, logout and forged remember cookie behaviour
b7e46d2 docs: handoff 012 ceo-stay-logged-in, result skeleton and index row
```

**2. Section 5 tests exist and pass.** Base class `tests/Feature/Auth/AuthTestCase.php` (hand-made `users`, `employee_profiles`, `everyday_tasks`, `tasks`; the protected page is `/debug/ip`, behind `web` + `auth` only).

| Section 5 line | File and test | Rows |
|---|---|---|
| CEO login: cookie, 30 days, session regenerated, redirect | `CeoRememberedLoginTest::test_ceo_login_sets_a_remember_cookie_that_lasts_30_days` | `CEO`, ` ceo ` (expiry = now + 30 days within 120 s; redirect `/`; session id changed) |
| Marketing, Marketing - OIC, one other role: no cookie, as today | `LoginCharacterizationTest::test_non_ceo_login_signs_in_regenerates_the_session_and_sets_no_remember_cookie` | Marketing, Marketing - OIC, Data Encoder, Marketing posting `remember=1`, an account with no role posting `remember=on`; also asserts the stored token stays null |
| Wrong password, unknown email | `LoginCharacterizationTest::test_failed_login_goes_back_with_the_email_error_and_sets_no_cookie` | wrong password, unknown email, no email, array email, wrong password + `remember=1` (on a CEO account); also asserts no query touches `employee_profiles` |
| CEO with only the cookie and a fresh session | `CeoRememberedLoginTest::test_remember_cookie_alone_signs_the_ceo_in_on_a_fresh_session` | `CEO`, ` ceo ` |
| Refused after a role change and after the profile was removed | `CeoRememberedLoginTest::test_remembered_sign_in_is_refused_when_the_account_is_not_ceo` | demoted, profile deleted, a Marketing account holding a hand-made valid cookie: redirect to `/login`, cookie expired in the response, guest, stored token changed, no page content, old cookie still refused on the next request |
| (review) re-check fails closed | `CeoRememberedLoginTest::test_remembered_sign_in_leaves_no_signed_in_session_when_the_role_check_fails` | the role read throws; the follow-up request with that session is a guest |
| Logout | `CeoRememberedLoginTest::test_logout_clears_the_remember_cookie_and_the_old_cookie_no_longer_signs_in`; `LoginCharacterizationTest::test_logout_signs_out_and_redirects_to_login` | cookie expired in the response, stored token changed, old cookie value refused |
| Tampered or made-up cookie | `LoginCharacterizationTest::test_forged_remember_cookie_signs_nobody_in`; `::test_tampered_real_remember_cookie_signs_nobody_in` | right shape with a wrong token, a made-up string, another user's id; a real encrypted cookie with one character changed (with a control: the untouched one signs in) |

`php.bat artisan test --filter Feature.Auth` at `8baeb93`:

```
   PASS  Tests\Feature\Auth\CeoRememberedLoginTest      (9 tests)
   PASS  Tests\Feature\Auth\LoginCharacterizationTest   (15 tests)
  Tests:    24 passed (183 assertions)
```

Red runs (from the developer's reports):

- Pins (`2c24de1`): no red run by design, 14 passed on the base code. The forged and tampered cookie tests are pins too.
- CEO cookie: `test_ceo_login_sets_a_remember_cookie_that_lasts_30_days`, `Walang remember cookie sa login ng CEO. Failed asserting that null is not null.` (the cookie sign-in and logout tests went red on this same line in their login helper, not on a failure of their own).
- D3: `test_remembered_sign_in_is_refused_when_the_account_is_not_ceo`, all three rows, `Expected response status code [201, 301, 302, 303, 307, 308] but received 200.`
- Fail closed: `test_remembered_sign_in_leaves_no_signed_in_session_when_the_role_check_fails`, `Expected response status code [201, 301, 302, 303, 307, 308] but received 200.`
- Role read after the password: `test_failed_login_goes_back_with_the_email_error_and_sets_no_cookie` (rows "maling password", "maling password + remember=1"), `Binasa ang employee_profiles sa bigong login. Failed asserting that 1 is identical to 0.`

**3. Whole suite.** `php.bat artisan test`, on the tree of `8baeb93` (the last code commit; the commits after it change only `TODO.md`, agent memory and handoff files):

```
   FAILED  Tests\Feature\ExampleTest > the application returns a successful response
  Expected response status code [200] but received 302.

  Tests:    1 failed, 3 skipped, 480 passed (5007 assertions)
  Duration: 60.05s
```

Base: 456 passed, 3 skipped, 1 failed. Now 480 passed (the 24 new), the same 3 skipped, the same 1 failed (`ExampleTest`). No new failure. Said plainly: the suite ran twice, not once. The first run (480 passed, 5002 assertions) was before the last fix (`8baeb93`), so it was run again on the final code.

**4. Diff scope.** `git diff 9332e43..HEAD --stat`:

```
 .../backend-developer/testing_gotchas.md           |  11 ++
 .../skeptic-reviewer/repo_weak_spots.md            |  12 ++
 TODO.md                                            |  27 ++++
 app/Http/Controllers/Auth/LoginController.php      |   9 ++
 app/Listeners/RefuseRememberedLoginUnlessCeo.php   |  54 ++++++++
 handoff/012-ceo-stay-logged-in/HANDOFF.md          |  83 +++++++++++
 handoff/012-ceo-stay-logged-in/RESULT.md           |  47 +++++++
 handoff/README.md                                  |   1 +
 tests/Feature/Auth/AuthTestCase.php                | 119 ++++++++++++++++
 tests/Feature/Auth/CeoRememberedLoginTest.php      | 154 +++++++++++++++++++++
 tests/Feature/Auth/LoginCharacterizationTest.php   | 137 ++++++++++++++++++
```

(Taken before this file's own commit; `RESULT.md` and `handoff/README.md` grow by that commit, nothing else.) `git diff 9332e43..HEAD --stat -- config database resources/views routes bootstrap` prints nothing. Beyond "the login controller, the D3 class, tests and handoff files" there are `TODO.md` and two agent-memory files, which CLAUDE.md requires (workflow steps 5 and 9). There is no registration line.

**5. Every way to become signed in:** the table below.

**6. D5, D6 and the password question:** the next sections; the password answer is Summary 3.

**7. Reviewer:** ran at adversarial depth with the three named checks, and once more on the fixes; see "Review findings".

**8. Commits:** Conventional Commits; `git log 9332e43..HEAD -i -E --grep="co-authored|claude-session|generated with" --oneline` prints nothing; `git status --short` is empty after this file's commit.

## Every way to become signed in

Searched `app/`, `routes/`, `bootstrap/` and `config/` for `attempt`, `login`, `loginUsingId`, `once`, impersonation and token logins. `config/auth.php` has one guard, `web` (session).

| # | Way in | CEO after this change | Other roles after this change |
|---|---|---|---|
| 1 | Login form, `POST /login` (`LoginController::login`) | Signed in as today, plus the remember cookie for 30 days from this login | Exactly as today: session only, no cookie, no token written. A posted `remember` field is never read |
| 2 | Remember cookie, on any request where the `web` guard resolves the user without a session (new in practice: nobody had the cookie before) | Signed in silently with a new session; the cookie is not renewed, so day 30 still counts from the login | Refused: logged out inside the guard, redirect to `/login` on protected pages, cookie cleared, token cycled. Same when the role cannot be read |
| 3 | An existing session (the session cookie of a login that has not lapsed) | Unchanged | Unchanged. Not re-checked by this change: an account demoted while its session is active keeps that session until it lapses, as today; the pages check the role per request |
| 4 | Registration, `GET`/`POST /registerlogin` (public) | Does not sign anyone in; creates an account with an employee profile that has no role | Same |
| 5 | `GET /api/user` behind `auth:sanctum` (`routes/api.php`) | Not a way in locally: the Sanctum package is not installed here (`vendor/laravel/sanctum` is absent), so that guard does not exist. If it exists on the server it authenticates through the `web` guard, so a cookie sign-in there passes through the same listener | Same |
| 6 | Not found | No second login controller, no API or token login, no "act as" or impersonation, no `loginUsingId`, no password-reset flow, no basic auth route (the `auth.basic` alias is only in the dead `app/Http/Kernel.php`) | Same |

One path only, so the budget's "list them and stop" did not apply.

## Cookie attributes and server requirements

From the code and `config/session.php` defaults, not from any env file. The remember cookie is made by `SessionGuard::createRecaller()` → `CookieJar::make($name, $value, $minutes)`; the jar's defaults come from the session config (`CookieServiceProvider`).

| Attribute | Resolves to | Where it comes from |
|---|---|---|
| Name | `remember_web_` + a hash of the guard class | `SessionGuard::getRecallerName()` |
| Value | `id\|token\|password hash`, encrypted with the app key | the `web` group's cookie encryption |
| Expires | login time + 43,200 minutes (30 days) | `setRememberDuration(60 * 24 * 30)` in the login controller; the framework default would be 576,000 minutes (400 days) |
| HttpOnly | always `true` | the default argument of `CookieJar::make`; it does not read `session.http_only` |
| SameSite | `lax` | `session.same_site`, default `'lax'` (`SESSION_SAME_SITE`) |
| Path | `/` | `session.path`, default `'/'` (`SESSION_PATH`) |
| Domain | none (host only) | `session.domain`, default null (`SESSION_DOMAIN`) |
| Secure | not fixed by config: `session.secure` is `env('SESSION_SECURE_COOKIE')` with **no default**, so it is null unless the server sets it | With null, Symfony marks the cookie Secure only when the request itself is seen as HTTPS (`Cookie::isSecure()` falls back to the request's scheme) |

What must be true on the server for the cookie to be Secure, one of:

- `SESSION_SECURE_COOKIE=true` in the server's environment (the sure way; I did not and may not look), or
- the request reaches Laravel as HTTPS: PHP sees TLS directly, or the web server or proxy in front sends `X-Forwarded-Proto: https`. `bootstrap/app.php` trusts proxy headers from any address (`trustProxies(at: '*')`), so the header is honoured when it is sent.

If neither holds, the cookie is issued without Secure and would also travel over plain HTTP. Check after deploy: log in as the CEO and look at the `remember_web_…` cookie in the browser's devtools; it must show Secure, HttpOnly, SameSite Lax and an expiry 30 days out. The session cookie follows the same Secure rule, so if that one is Secure today, this one will be.

## The stale page case

What happens when `/owner/private` stays open past the session lifetime (120 minutes without a request) and he then uses it without reloading:

- **A pencil save** (Action note, Claude Action / Reason, CEO Action / Reason): the POST carries the page's old CSRF token; the CSRF check runs before authentication, so the answer is 419 and the remember cookie is not even looked at. The editor stays open and shows the red error line **"CSRF token mismatch."**; the typed text is still in the editor and nothing was saved. Pressing Save again gives the same answer, as long as the page is not reloaded. The RTS / Promo / COGS edit modal shows an alert, "Save failed:" with the same message.
- **Refresh, a date change or any other background read** (GET): works, silently signed in from the cookie. So the page looks alive while saves fail. This is the new part: before this change the same reads failed too and a reload led to the login page.
- **A reload (F5) or any full page load:** signed in silently, new token, saves work again. Text typed in an open editor is lost by the reload, so he should copy it first.
- The 419 on a stale save is not new (it happens today as well); what changes is that the fix is now a reload, not a login.

Proposed follow-up, not built (proposed task 3): in the page's save handlers, when the answer is 419, show "Nag-expire ang page. I-copy ang tinype mo, i-reload (F5), tapos i-save ulit." in place of "CSRF token mismatch.". Views only, three handlers (`saveActionNote` and the edit modal in `owner/private.blade.php`, the shared `_claude_action_modal_js.blade.php`), no CSRF change.

## Review findings

`skeptic-reviewer`, adversarial depth (high tier, opus), on `9332e43..ee40909`, then a re-check scoped to the fixes (`ee40909..8baeb93`). One loop used.

- **Named check 1** (no role but CEO can obtain or keep a remembered login by any path): **pass.** The only call that issues the cookie is behind `isCeo(Auth::user())` after the password is accepted; no request parameter is read (`remember=1` and `remember=on` rows); odd credential shapes cannot borrow another account's role; a non-CEO holding a valid cookie is refused and the token cycled. "Keep" is subject to the open major for how long a copied CEO cookie lives.
- **Named check 2** (the D3 re-check runs on every cookie sign-in and cannot be skipped by route or middleware order): **pass, with one accepted gap.** The only place a cookie becomes a user is `SessionGuard::user()`, which fires `Login` straight after; the listener runs inside the guard, also on routes with no `auth` middleware and when a view composer resolves the user. The refusal sticks for the rest of the request. The listener's own failure now fails closed. Gap: a throw from the other `Login` listener before this one runs (accepted minor 3).
- **Named check 3** (login for other roles and logout unchanged): **pass.** `logout()` is identical to the base. A non-CEO login is the base `Auth::attempt($credentials)`, plus one read of the employee profile after success, then the same regenerate and redirect. The failure branch is identical to the base.
- **Spec:** D1, D3, D4, D5 (no config change), D6 (no CSRF change) met; D2 met as written, with finding 1.

| # | Severity | Finding | Outcome |
|---|---|---|---|
| 1 | **major** | The 30 days are enforced only by the browser: a copied cookie value signs in on day 31 or day 300 until Logout cycles the token (attacker with one copy of the cookie; the CEO never logs out) | **Open, escalated to Mira** ("Question for Mira"); in `TODO.md` |
| 2 | minor, untrusted path | If the role read threw during a cookie sign-in, the session had already been written signed in and the next request skipped the re-check | Fixed (`70db3df`): logout, then rethrow; red test |
| 3 | minor | The same if the other `Login` listener (`CopyEverydayTasksOnLogin`) throws before the re-check has run; discovery order is not guaranteed | Accepted, `TODO.md`: the cookie holder cannot cause the throw; proposed task 5 |
| 4 | minor, untrusted path | The role was read before `Auth::attempt`, outside the guard's 200 ms timebox: a failed login cost one more query for an existing e-mail, so response time told whether an account exists | Fixed (`8baeb93`): the role is read only after the password is accepted; red test. My first triage accepted this with a wrong reason (I had read the timebox as absent); the test timings showed otherwise and it was fixed |
| 5 | minor | No registration line: a cached events file on the server would hide the listener | Accepted, `TODO.md`; deploy notes 2 |
| 6 | minor | Everyday tasks are copied on cookie sign-ins too, once even for a refused account | Accepted, `TODO.md` |
| 7 | minor, test | The "session regenerated" assertion would pass with the controller's `regenerate()` removed (the guard already changes the id) | Accepted, `TODO.md`; the line is unchanged |
| 8 | minor, missing tests | A tampered real cookie; a non-CEO login in a browser holding a CEO cookie; a CEO's second login reusing the token | First one added (`70db3df`); the other two accepted, they pin behaviour the open major would change |
| 9 | minor | `isCeo()` is one more copy of the CEO rule; `EnsureCeo` is a strict `=== 'CEO'` while this and the owner pages are case-insensitive | Accepted, `TODO.md` (no refactor) |
| 10 | minor | A stale CEO cookie survives another account's password login in the same browser | Accepted, `TODO.md`; proposed task 4 |
| 11 | minor (re-check) | One CEO login fires `Login` twice | Accepted, `TODO.md` (Summary 5) |
| 12 | minor (re-check) | The fail-closed logout also cycles the token: a database error during a cookie sign-in signs the CEO out of every browser | Accepted, `TODO.md`; deploy notes 6 |
| 13 | minor (re-check) | If the profile read throws right after the password is accepted: server error while the session is signed in | Accepted, `TODO.md` |
| 14 | minor (re-check) | `TODO.md` and plan line 3 were stale after the second fix | Fixed in `34ea737` and in this file |

Declined to judge by the reviewer: the full suite (run by the main session), production state (events cache, listener order on the server's filesystem, whether `CopyEverydayTasksOnLogin` can throw on the real `tasks` table), MySQL and the database session driver (tests use sqlite and array sessions), and the red runs of the fix slices (they are listed above from the developer's reports).

## Rulings

- Ruling: D3 is a listener on the `Login` event, not a web-group middleware - it runs inside the guard at the exact moment of a cookie sign-in, so no route group or middleware order can skip it, and it needs no registration line - a throw from another listener before it can leave a session signed in (accepted minor 3), and a cached events file can hide it (deploy notes 2).
- Ruling: the CEO test is the app's normalised reading (trimmed, spaces collapsed, case-insensitive `ceo`), kept as one static method on the listener and used by both the login and the re-check - the handoff points at `getNormalizedRole()`, and one copy means login and re-check cannot disagree - an account stored as `ceo ` is remembered although Boardroom's strict check refuses it (same split as before).
- Ruling: the role is read after `Auth::attempt` succeeds and the cookie is issued by a second `Auth::login(..., true)` - a failed login stays byte-identical to the base and inside the timebox, and the handoff says "after the credentials are accepted" - the `Login` event fires twice for a CEO login.
- Ruling: no "disabled account" check in D3 - the app has no flag that blocks a login today - if Mira wants `employee_profiles.status` to block logins, that is a new rule for every role and its own task.
- Ruling: when the role cannot be read during a cookie sign-in, log out (and so cycle the token) and return the error - refusing is the safe side for the account that sees the financial pages - a database hiccup at that moment signs the CEO out everywhere and he logs in again.
- Ruling: finding 1 was escalated, not fixed - D2 names the mechanism and every fix changes a decision (other browsers signed out), a constraint (a migration) or adds a new design to the auth path - the branch as it stands should not be described to Busing as "30 days at most for a stolen cookie".
- Ruling: worked on the branch in the main checkout, not in a git worktree under `.claude/worktrees/` - the handoff's allowed commands have no `git worktree` and say to create and stay on the branch - none; this is a conflict between CLAUDE.md's git flow and the handoff, resolved on the side of the narrower permission, as in the earlier handoffs.
- Ruling: `/ship` (clean install, push, PR) was not run - the handoff forbids push, PR and installs - Mira does it.
- Ruling: the tests have their own small base class and use `/debug/ip` as the protected page - `OwnerPrivateTestCase` runs seven unrelated migrations, and `/debug/ip` is behind `web` + `auth` only - if the owner removes that route (suggested), the constant in `AuthTestCase` needs another page.

Deviations to report: one shell command outside the allowed list (`cat >>` with a here-document to append the 012 section to `TODO.md`; later edits used the Edit tool), and the full suite ran twice (see evidence 3). The handoff says PHP 8.2; CLAUDE.md and the local Herd say 8.4; nothing here depends on it.

## Deploy notes for Mira

**Replaced by "Deploy notes (current)" in the Amendment 012-1 section.** The list below is the first run's and is kept for the record; its notes 2 and 7 no longer hold.

1. No migration, no config change, no asset build, no queue restart. `users.remember_token` already exists (standard column; the hand-made test table mirrors it). Worth one look on the server before deploy: `SHOW COLUMNS FROM users LIKE 'remember_token'`.
2. **Rebuild the cached events, or D3 can be silently off.** After pulling, run `php artisan optimize:clear` (or `php artisan event:clear`; if the server uses `optimize` / `event:cache`, run it again afterwards). Then check `php artisan event:list --event=Login`: under `Illuminate\Auth\Events\Login` it must list both `CopyEverydayTasksOnLogin` and `RefuseRememberedLoginUnlessCeo`. If the second is missing, CEO remember works but the role re-check does not run.
3. Check the cookie in the browser after the CEO's first login: Secure, HttpOnly, SameSite Lax, expiry 30 days out (see "Cookie attributes"). If Secure is missing, set `SESSION_SECURE_COOKIE=true` on the server.
4. He must log in once with his password after deploy to get the cookie; an existing session does not get one.
5. **Logout ends the remembered login on all his browsers** (D4: the token is cycled), not only on the one where he pressed it. Each other browser asks for the password once its session lapses.
6. A database error at the moment of a cookie sign-in also signs him out everywhere (fail closed); he logs in again.
7. Tell Busing: a page left open for hours may answer "CSRF token mismatch." on a save; copy the text, reload, save again. And until the open major is decided: on a shared or lost device, press Logout (from any browser) to kill every remembered login.
8. Rollback: revert the branch's merge, **and then end the cookie already issued**: the framework itself accepts a remember cookie, with or without this code, so after a revert the CEO's cookie keeps signing him in and the role re-check is gone. Have him press Logout once, or set his `users.remember_token` to null.
9. Not seen in a browser; a five-minute check after deploy: CEO login → cookie present; close and reopen the browser → still in; Logout → cookie gone; a Marketing login → no `remember_web_…` cookie.

## Proposed tasks

1. **Decide and build the server-side 30-day limit** (the open major): option c (second encrypted cookie with the issue time) or b (stored expiry, needs a migration). Small, high tier.
2. **Cycle `remember_token` when a password is changed** in `OwnerUsersController::updatePassword` (one more column in its update), so a password change ends remembered logins. Section 6 names this as a damage limit; today it is not one.
3. **Friendly message on 419** in the three save handlers of `/owner/private` (see "The stale page case"). Views only.
4. **Clear a leftover remember cookie when a non-CEO logs in** on the same browser (one line in the login controller), so a shared computer cannot fall back to the CEO when the other account's session lapses.
5. **Make the re-check independent of other listeners:** wrap `CopyEverydayTasksOnLogin`'s work in its own try/catch (log and continue), so a task-copy error can neither block a login nor leave a cookie sign-in unchecked.
(After Amendment 012-1: tasks 1 and 2 are done; task 5's security reason is gone because the re-check now runs first. The current list is in the amendment section.)

6. Suggestions for the owner, not done: remove the `/debug/ip` route (returns request IP headers to any signed-in user); `OwnerUsersController::updatePassword` also writes the new password in plain text to `users.password_plain` when that column exists and returns it in the response, which deserves its own decision; public registration at `/registerlogin`; delete the dead `app/Http/Kernel.php` and `app/Providers/EventServiceProvider.php`; `ExampleTest` still fails (expects 200 on `/`, gets 302).

---

# Amendment 012-1

Saved verbatim as `AMENDMENT-1.md` (`278bcd3`). It stays inside what an amendment may change (requirements, done-when, scope); nothing in it touches permissions, commands or policy files. This section is the current state of the handoff.

## Plan (amendment)

1. Save the amendment; pin today's behaviour of the owner users password endpoint.
2. A1: at a CEO password login also queue a second encrypted cookie `ceo_remember_since` = `user id|login time`; the listener accepts a cookie sign-in only for a CEO whose second cookie is present, parses strictly, carries the same user id and is at most 30 days old; a refusal clears both cookies; a `Logout` listener clears the second cookie on every logout path.
3. A3: both listener methods registered explicitly in `AppServiceProvider::register()`, renamed so the framework's discovery ignores them (no double run), and placed so the re-check runs before every discovered listener.
4. A2: `OwnerUsersController::updatePassword` writes a fresh `remember_token` in the same update (the only place in the app that changes a password).
5. Reviewer at adversarial depth with the five named checks, fixes or `TODO.md`, the full suite, this section.

## Summary (amendment)

- **A1, done.** A CEO password login now sets two cookies with the same 30-day lifetime: the framework's remember cookie and `ceo_remember_since`, which holds the user id and the time of that login and is encrypted by the framework like every other cookie (the encryption is bound to the cookie's name, so a value cannot be moved in from another cookie). A sign-in from the remember cookie is accepted only when the account is CEO and the second cookie is there, decrypts, has exactly the form `digits|digits`, names the same user id and is between 0 and 30 days old (a time up to 5 minutes in the future is tolerated for clock differences). Anything else is refused the way a non-CEO is: logged out, both cookies cleared, token cycled. Exactly 30 days is accepted, 30 days and one minute is refused. The server does the counting; the browser's expiry no longer matters. No migration, no custom guard, no config change.
- **A2, done.** A password change on the owner's users page (`POST /owner/users/{id}/password`) cycles that user's remember token in the same update, so every remember cookie issued before it stops working. I searched `app/`, `routes/` and `database/seeders` for other places that change a password: there is none (no self-service change, no reset flow, no console command; registration only creates an account). One place, so the "more than two" stop did not apply.
- **A3, done.** The listener is registered explicitly in `AppServiceProvider::register()` and its methods are no longer named `handle`, so the framework's listener discovery and a cached events file play no part: a stale cache cannot switch the re-check off, and a rebuilt one cannot run it twice. Registering in `register()` puts it before every discovered listener, which also closes the first run's accepted minor (another `Login` listener throwing before the re-check).
- **New open major, for Mira's ruling: sessions.** See the next section. A1 to A4 are met as written; what they say about a stolen cookie and a lost device is still stronger than what the build gives.

## Question for Mira (open major)

**Finding (reviewer, adversarial depth):** everything built here stops a *new* sign-in from the cookies. Nothing ends a session that such a sign-in already started. The session lifetime (120 minutes) is an idle limit, Logout ends only the session of the browser that pressed it, and the password change touches only the `users` row.

**Scenario:** someone copies both cookies from the CEO's device, signs in once within the 30 days, and then sends any request at least every 120 minutes. They stay the CEO past day 30, past the CEO's own Logout and past a password change.

A copied session cookie behaves the same on the live base, so this is not new exposure in kind. But A1's reason ("a copied cookie that works for longer than that is not what he approved") and A2's ("it has to cut that device off") are not true for that case.

| | Fix | Cost |
|---|---|---|
| i | On a password change, also delete that user's rows in the `sessions` table (keep the caller's own session when he changes his own password), guarded by `Schema::hasTable` | About 5 lines in `updatePassword`. Makes "change the password to cut a lost device off" true at once. Works only with the database session driver (the config default; I may not look at the server's setting). |
| ii | The same on the CEO's Logout: Logout ends every session of that account, not only this browser's | A few lines in a `Logout` listener. Makes Logout the kill switch it is described as. Changes D4's wording ("the existing logout flow stays as it is otherwise") for the CEO. |
| iii | A hard age for remembered sessions: the listener writes the second cookie's login time into the session at a cookie sign-in, and a per-request check ends the session when that time is more than 30 days old | A small middleware in the web group plus tests; its own task. Closes "past day 30" for a session kept alive. |

**Recommendation: i and ii together** (small, and they make the two things the owner would actually do, change the password or press Logout, cut a lost device off immediately); iii only if Mira wants the 30-day ceiling to hold against someone who keeps a stolen session alive. Not built: each one changes more than A1 to A4 name (the `sessions` table, the logout flow, a per-request check).

Until then, the honest wording for Busing: "After 30 days, after Logout, or after a password change, a copied cookie can no longer start a login. A login somebody already started with it stays alive for as long as they keep using it at least every two hours."

## Done-when evidence (amendment)

**A5.1 The A4 tests exist and pass.**

| A4 line | File and test | Rows |
|---|---|---|
| Second cookie missing, tampered, for another user id, older than 30 days: refused, both cookies cleared | `CeoRememberedLoginTest::test_remembered_sign_in_is_refused_without_a_valid_second_cookie` | missing; ciphertext changed; other user id; 30 days + 1 minute; 1 hour in the future; time not digits; user id with a leading zero; no time part; array-shaped cookie. Each: redirect to `/login`, guest, both cookies expired in the response, stored token changed |
| 29 days old: accepted | `CeoRememberedLoginTest::test_remembered_sign_in_is_accepted_with_a_valid_second_cookie` | 29 days; exactly 30 days; 60 seconds in the future; a second cookie from a later password login with the earlier remember cookie |
| Logout clears both cookies | `CeoRememberedLoginTest::test_logout_clears_the_remember_cookie_and_the_old_cookie_no_longer_signs_in` | both expired in the response, token changed, old cookies refused |
| A non-CEO never receives the second cookie | `LoginCharacterizationTest::test_non_ceo_login_signs_in_regenerates_the_session_and_sets_no_remember_cookie`, `::test_failed_login_goes_back_with_the_email_error_and_sets_no_cookie`, `::test_logout_signs_out_and_redirects_to_login` | no `ceo_remember_since` on a non-CEO login, on a failed (CEO) login, or on a non-CEO logout |
| CEO login sets it for 30 days | `CeoRememberedLoginTest::test_ceo_login_sets_a_remember_cookie_that_lasts_30_days` | both cookies, both expiring 30 days out |
| After a password change the old remember cookie no longer signs in | `OwnerPasswordChangeTest::test_password_change_ends_the_remembered_logins_of_that_user` (pins: `::test_ceo_sets_a_new_password_for_a_user`, `::test_non_ceo_gets_a_404_and_the_password_stays`) | the CEO changing his own password; another CEO account changing it. A control sign-in with the cookies succeeds first; after the change it is refused and the stored token differs |
| The listener is registered without discovery | `CeoRememberedLoginTest::test_the_recheck_is_registered_explicitly_once_and_runs_before_other_login_listeners` | the dispatcher's raw `Login` listeners hold `[RefuseRememberedLoginUnlessCeo::class, 'recheckRememberedLogin']` at index 0 (the array form only explicit registration produces), exactly once, before `CopyEverydayTasksOnLogin`; one `Logout` listener of the class |
| The first run's 24 stay green | all three files | 42 tests now |

`php.bat artisan test --filter Feature.Auth` at `3f20091`:

```
   PASS  Tests\Feature\Auth\CeoRememberedLoginTest          (23 tests)
   PASS  Tests\Feature\Auth\LoginCharacterizationTest       (15 tests)
   PASS  Tests\Feature\Auth\OwnerPasswordChangeTest         (4 tests)
  Tests:    42 passed (355 assertions)
```

Red runs (from the developer's reports):

- Password endpoint pins (`e7a6e06`): no red run by design.
- A1: `remembered sign in is refused without a valid second cookie`, all rows then present, `Expected response status code [201, 301, 302, 303, 307, 308] but received 200.`
- A1 login: `ceo login sets a remember cookie that lasts 30 days`, `Walang pangalawang cookie sa login ng CEO. Failed asserting that null is not null.`
- A1 logout: `logout clears the remember cookie and the old cookie no longer signs in`, `Cookie [ceo_remember_since] is not expired, it expires at [2026-11-04 15:26:23].`
- A3: `the recheck is registered explicitly once and runs before other login listeners`, `Failed asserting that 'App\Listeners\CopyEverydayTasksOnLogin@handle' is identical to Array [RefuseRememberedLoginUnlessCeo, 'recheckRememberedLogin']`
- A2: `password change ends the remembered logins of that user`, both rows, `Failed asserting that two strings are not identical.`
- The four rows added in `3f20091` are pins (no red run).

**A5.2 Whole suite.** `php.bat artisan test`, once for the amendment, on the tree of `3f20091` (the last code commit; the commits after it change only `TODO.md`, reviewer memory and handoff files):

```
   FAILED  Tests\Feature\ExampleTest > the application returns a successful response
  Expected response status code [200] but received 302.

  Tests:    1 failed, 3 skipped, 498 passed (5179 assertions)
  Duration: 59.14s
```

Base: 456 passed, 3 skipped, 1 failed. Now 498 passed (the 42 new), the same 3 skipped, the same 1 failed (`ExampleTest`). No new failure.

**A5.3 Diff scope.** `git log --oneline d17658a..HEAD` (before this section's own commit):

```
46651f2 docs: review findings and reviewer memory for amendment 012-1
3f20091 test: second CEO cookie rows for the future tolerance, the 30 day boundary, an array shape and a later login
e93cbcf refactor: the value of the second CEO cookie is built in one place, next to its parser
4f174ee feat: a password change on the owner users page cycles that user's remember token
05c9b10 feat: server enforces the 30 days of the CEO remembered login with a second cookie, listener registered explicitly
e7a6e06 test: pin the owner users password change endpoint
278bcd3 docs: amendment 012-1 saved verbatim
```

`git diff 9332e43..HEAD --stat` (same moment; `RESULT.md` and `handoff/README.md` grow by the last commit, nothing else):

```
 .../backend-developer/testing_gotchas.md           |  17 ++
 .../skeptic-reviewer/repo_weak_spots.md            |  19 ++
 TODO.md                                            |  47 ++++
 app/Http/Controllers/Auth/LoginController.php      |  18 ++
 app/Http/Controllers/OwnerUsersController.php      |   3 +
 app/Listeners/RefuseRememberedLoginUnlessCeo.php   | 116 ++++++++
 app/Providers/AppServiceProvider.php               |  12 +-
 handoff/012-ceo-stay-logged-in/AMENDMENT-1.md      |  12 +
 handoff/012-ceo-stay-logged-in/HANDOFF.md          |  83 ++++++
 handoff/012-ceo-stay-logged-in/RESULT.md           | 232 ++++++++++++++++
 handoff/README.md                                  |   1 +
 tests/Feature/Auth/AuthTestCase.php                | 160 +++++++++++
 tests/Feature/Auth/CeoRememberedLoginTest.php      | 294 +++++++++++++++++++++
 tests/Feature/Auth/LoginCharacterizationTest.php   | 142 ++++++++++
 tests/Feature/Auth/OwnerPasswordChangeTest.php     |  83 ++++++
 15 files changed, 1238 insertions(+), 1 deletion(-)
```

`git diff 9332e43..HEAD --stat -- config database resources/views routes bootstrap` prints nothing. In scope by the amendment: `OwnerUsersController.php` (A2, three lines in `updatePassword`) and `AppServiceProvider.php` (A3, the body of `register()`; the one deleted line is its `//` placeholder; the rest of that legacy file is untouched).

**A5.4 Commits and tree:** Conventional Commits; `git log 9332e43..HEAD -i -E --grep="co-authored|claude-session|generated with" --oneline` prints nothing; `git status --short` is empty after this section's commit.

## Every way to become signed in (current)

| # | Way in | CEO | Other roles |
|---|---|---|---|
| 1 | Login form, `POST /login` | Signed in as before, plus two cookies for 30 days from this login: the remember cookie and `ceo_remember_since` (user id and login time) | Exactly as on the base: session only, neither cookie, no token written. A posted `remember` field is never read |
| 2 | Remember cookie, on any request where the `web` guard resolves the user without a session | Signed in with a new session only when the account is CEO now **and** the second cookie is present, decrypts, names the same user id and is at most 30 days old. Neither cookie is renewed. Otherwise refused: redirect to `/login` on protected pages, both cookies cleared, token cycled | Always refused (same refusal), also with a valid-looking pair of cookies |
| 3 | An existing session | Unchanged, and not re-checked by anything in this handoff: it lives while it is used at least every 120 minutes (the open major) | Unchanged |
| 4 | Registration, `/registerlogin` (public) | Signs nobody in; the new account has no role | Same |
| 5 | `GET /api/user` behind `auth:sanctum` | Not a way in locally (Sanctum is not installed). Where it exists it goes through the `web` guard and so through the same listener; on a route group that does not decrypt cookies the remember cookie is not readable and nobody is signed in | Same |
| 6 | Not found | No second login controller, no API or token login, no impersonation, no `loginUsingId`, no password-reset flow, no basic auth route | Same |

What ends a remembered login: 30 days after the password login (server-side); Logout in any of his browsers (token cycled, so all browsers); a password change for that account on `/owner/users` (token cycled, all browsers); a role change or a removed employee profile (refused at the next cookie sign-in); any refusal in one browser (token cycled, all browsers). None of these ends a session that is already open.

## Cookie attributes (second cookie)

`ceo_remember_since` is queued through the same cookie jar as the remember cookie, so the table in "Cookie attributes and server requirements" applies to it line for line: value encrypted with the app key, expires at login + 43,200 minutes (asserted by the CEO login test), HttpOnly always true, SameSite `lax`, path `/`, host-only domain, and Secure by the same rule (`SESSION_SECURE_COOKIE=true` on the server, or the request arriving as HTTPS). Both cookies are cleared with an expired cookie of the same name and path.

## Review findings (amendment)

`skeptic-reviewer`, adversarial depth (opus), on `d17658a..4f174ee`. No fix loop was needed (no blocker or major against the spec); the minors went into one wave.

- **Check 1** (no role but CEO obtains or keeps a remembered login): **pass.** Both cookies are issued only behind the CEO check after `Auth::attempt`; a Marketing account with a hand-made token and a valid second cookie is refused.
- **Check 2** (the re-check runs on every cookie sign-in, cannot be skipped): **pass.** One place in the guard turns a cookie into a user and fires `Login` at once; the listener is attached in `register()`, before discovered or cached listeners; discovery cannot pick the renamed methods up, so there is no double run with or without a cache.
- **Check 3** (other roles' login and the logout flow unchanged): **pass.** `logout()` and the failure branch are identical to `9332e43`; a non-CEO login differs by one profile read; the `Logout` listener adds nothing unless the request carries the second cookie.
- **Check 4** (no remembered sign-in without a valid second cookie younger than 30 days): **pass for new sign-ins**; a session already started is the open major. Tried and blocked: a value moved from another cookie name, another account's cookie, a raw or tampered value, an array, whitespace, a trailing newline, huge numbers, a leading zero, routes that do not decrypt cookies.
- **Check 5** (a password change invalidates every earlier remember cookie of that user): **pass.** The token is replaced in the same update; `updatePassword` is the only place that changes a password.
- **Spec:** A1, A2, A3 (code) and every A4 line met. A3's deploy note and A5's section were not written yet at review time; they are this section.

| # | Severity | Finding | Outcome |
|---|---|---|---|
| 1 | **major**, spec gap | A session started from the cookies outlives day 30, Logout and a password change (someone holding both copied cookies who keeps the session active) | **Open, escalated to Mira**, options above; in `TODO.md` |
| 2 | minor | `RESULT.md` and `TODO.md` were stale after A1 and A3 | Fixed (`46651f2`, this section) |
| 3 | minor | A refusal cycles the token, so one browser's bad or expired second cookie ends the remembered login everywhere | Accepted, `TODO.md` (A1 asks for it); deploy note 5 |
| 4 | minor, missing tests, untrusted path | The window was bracketed loosely: no row inside the future tolerance, at exactly 30 days, for an array cookie, or for a later second cookie | Fixed (`3f20091`), four rows |
| 5 | minor, DRY | The cookie value's format was written in the controller and parsed in the listener | Fixed (`e93cbcf`): built next to its parser |
| 6 | minor | A password change gives a token to a user who never had one | Accepted, `TODO.md` |

Not re-reviewed: the minors wave (`e93cbcf`, `3f20091`) is four test rows and one moved line; the main session read its diff and ran the tests. Declined to judge by the reviewer: the full suite (run by the main session), the server's state (events cache, real cookie attributes behind the proxy, clock agreement between `vps` and `vps2`, MySQL with the database session driver), and the red runs (listed above from the developer's reports).

## Rulings (amendment)

- Ruling: the second cookie holds `user id|unix time` and is read strictly (`digits|digits`, ids compared as strings) - A1 names exactly that content, and a strict reader has no edge cases to argue about - it is not bound to the remember token, so a newer second cookie works with an older remember cookie for someone holding both (pinned by a test, accepted minor).
- Ruling: exactly 30 days is accepted, and a time up to 5 minutes in the future is accepted - "not older than 30 days", and two servers' clocks may differ a little - if `vps` and `vps2` disagree by more than 5 minutes, a login made on one is refused on the other (the CEO is asked to log in again; nothing unsafe).
- Ruling: Logout clears the second cookie through a listener on the framework's `Logout` event, only when the request carries it - every logout path is covered (the controller's, a refusal, the fail-closed one) and `LoginController::logout()` and other roles' logout responses stay byte-identical - a second registration line.
- Ruling: registration in `AppServiceProvider::register()` with the methods renamed away from `handle` - explicit, first in order, and invisible to discovery, so neither a stale nor a rebuilt events cache can remove or double it - `register()` is an unusual place for `Event::listen`; the comment there says why.
- Ruling: the open sessions major was escalated, not fixed - deleting session rows or adding a per-request age check goes beyond A1 to A4 - until it is ruled, a lost device with a live stolen session is not cut off by Logout or a password change.
- Ruling: the full suite ran on the last code commit, not on the final docs commit - the commits after it touch only `TODO.md`, reviewer memory and handoff files - none.

No conflict between CLAUDE.md and the amendment. All file writes in this run used the Write and Edit tools (no shell redirection).

## Deploy notes (current)

**What to run**

1. Pull; no migration, no config change, no asset build, no queue restart.
2. `php artisan optimize:clear`, then whatever caching the server normally runs (`php artisan optimize` or `config:cache` / `route:cache`). The role re-check no longer depends on the events cache; clearing is only so no cache from before this code is left.

**What to check on the server**

3. `php artisan event:list --event=Login`: under `Illuminate\Auth\Events\Login` the first listener is `App\Listeners\RefuseRememberedLoginUnlessCeo@recheckRememberedLogin`, listed once; `php artisan event:list --event=Logout` shows `…@forgetSinceCookie` once.
4. In a browser, as the CEO (he must log in once with his password after the deploy; an old session gets no cookies): devtools shows `remember_web_…` and `ceo_remember_since`, both Secure, HttpOnly, SameSite Lax, expiring 30 days out. If Secure is missing, set `SESSION_SECURE_COOKIE=true` on the server.
5. The behaviour check that proves the server-side rule: delete the session cookie and reload: still signed in. Then delete the session cookie **and** `ceo_remember_since` and reload: the login page, and `remember_web_…` is gone too. Log in again; press Logout: both cookies gone. Log in as a Marketing user: neither cookie.

**What to tell Busing**

6. Logout on any browser ends the remembered login on all of his browsers; each asks for the password once its session lapses. The same happens when one browser is refused (cookies older than 30 days, a damaged cookie, or a database error at that moment).
7. Changing the account's password on `/owner/users` ends its remembered logins everywhere. If he changes his own, the browser he is in stays signed in until its session lapses.
8. A lost or shared device: press Logout from any browser **and** change the password. Today this stops new logins from that device's cookies; a login that is already open there stays alive while it is in use (the open major).
9. A page left open for hours can answer "CSRF token mismatch." on a save: copy the text, reload, save again.

**Server facts worth one look**

10. Both cookies are encrypted with the app key: rotating it ends every remembered login. If `vps` and `vps2` serve the same site they need the same key (already true if sessions work across them) and clocks within 5 minutes.
11. Rollback: revert the merge, **then** have the CEO press Logout once or set his `users.remember_token` to null. The framework accepts a remember cookie with or without this code; after a revert neither the role re-check nor the 30-day check would run.

## Proposed tasks (current)

1. **End open sessions** (the open major): options i and ii above, small, high tier; option iii as its own task if wanted.
2. **Friendly message on 419** in the three save handlers of `/owner/private`. Views only.
3. **Clear leftover CEO cookies when a non-CEO logs in** on the same browser, so a shared computer cannot fall back to the CEO when the other account's session lapses.
4. **`CopyEverydayTasksOnLogin` in its own try/catch** (log and continue), so a task-copy error cannot block a login. No longer a security matter; the re-check runs first.
5. Suggestions for the owner, not done: remove the `/debug/ip` route; the plain-text `password_plain` column and the password echoed in the response of `updatePassword` deserve their own decision; public registration at `/registerlogin`; delete the dead `app/Http/Kernel.php` and `app/Providers/EventServiceProvider.php`; `AppServiceProvider` has a stray `deleteAll()` method and reads `env()` in `boot()` (breaks under `config:cache`: the production HTTPS forcing would be skipped); `ExampleTest` still fails.

---

# Amendment 012-2

Saved verbatim as `AMENDMENT-2.md` (`67ef3c1`). It changes requirements, a decision (D4) and done-when; nothing in it touches permissions, commands or policy files. This section is the current and final state of the handoff. In the 012-1 section above, "Question for Mira (open major)", "Every way to become signed in (current)", "Deploy notes (current)" and "Proposed tasks (current)" are replaced by the sections below.

## Plan (amendment 2)

1. Save the amendment; pin, under the database session driver, that a non-CEO Logout leaves that user's other session signed in.
2. One helper, `RefuseRememberedLoginUnlessCeo::endSessionsOf(int $userId, ?string $exceptSessionId)`: with the `database` session driver it deletes that user's rows in the session table (always with the `user_id` condition), optionally keeping one session id; with any other driver it does nothing.
3. B1: `OwnerUsersController::updatePassword` calls it for the changed user, keeping the caller's current session id.
4. B2: `LoginController::logout()` calls it for a CEO after the three existing lines; other roles get no delete. Not on the `Logout` event, which also fires when a cookie sign-in is refused.
5. Reviewer at adversarial depth with checks 6 and 7, minors wave, the full suite, this section.

## Summary (amendment 2)

- **B1, done.** A password change on `/owner/users` now also deletes that user's rows in the `sessions` table, in the same request and the same database transaction as the password and the remember token. Every browser already signed in as that user is a guest at its next request, and its remember cookie is dead because the token changed. The person who makes the change keeps the session he is in: when he changes someone else's password his own rows are not that user's, and when he changes his own, only his other sessions end. This applies to whatever account's password is changed, CEO or not.
- **B2, done.** When a CEO presses Logout, every session of that account ends, on every device, besides the token cycle that already killed the remember cookies. For every other role Logout ends only the session it was pressed in, as before (pinned under the database driver, also for a demoted CEO and for an account without a profile).
- **B3, not built**, as ruled. It is proposed task 1 with its cost.
- **Driver.** Both paths act only when the running session driver is `database` (the config default). With any other driver they do nothing extra and do not fail. The checklist has the command to see the running value.
- **Review:** no blocker, no major. One accepted minor is worth Mira's eye because its real fix is a design decision (below).

## For Mira's attention: a sign-in in flight during the cut (accepted minor, not started)

The reviewer traced this from the framework source; it was not run (sqlite cannot show it). If someone already holds working credentials for the account (a still-valid CEO cookie pair, or the current password) and sends sign-in requests in a loop, then at the moment the CEO presses Logout or the password is changed, one of those requests may have passed its check just before the cut and write its new session row just after the delete. That session lives on like any session.

It is a minor, not a major: it needs a prepared loop by someone who already has the credentials; a lost device or a copied session cookie alone is cut cleanly; and a second Logout or password change ends the survivor, because by then its credentials are dead and its row carries the user id.

| | Option | Cost |
|---|---|---|
| a | Accept, and tell the owner the order for a suspected theft: change the password, wait a few seconds, press Logout | None. **This is what the branch does** (checklist step 13) |
| b | A per-request validity check (the framework's `AuthenticateSession`, or a "sessions valid after" time per user that every request compares with its own login time) | The same design class as B3: a middleware in the web group and a stored value; its own task |
| c | A second, delayed delete a few seconds later | Narrows the window, does not close it; needs a queued job |

Per the amendment's last-amendment rule, nothing was started on b or c.

## Done-when evidence (amendment 2)

**B5.1 The B4 tests exist and pass.** All in `tests/Feature/Auth/EndOpenSessionsTest.php`, which switches the session driver to `database` by config, creates the `sessions` table with the migration's columns, and proves the driver is really in use (two rows with the user's id after two logins). Every "still signed in?" check sends only the session cookie, so neither a pass nor a failure can come from the remember cookies.

| B4 line | Test | Rows and what is asserted |
|---|---|---|
| Password change of someone else: that user's old session is no longer authenticated | `test_password_change_ends_the_open_sessions_of_that_user_and_no_others` | "password ng ibang user": the staff user's session is sent to login; both CEO sessions and a bystander's session still answer 200 |
| Password change of one's own: current session kept, other session ended | same test | "sariling password": the session he acted from answers 200, his other browser is sent to login; staff and bystander untouched |
| (review) access is checked before anything is deleted | same test | "Marketing ang nagpapalit: tinanggihan": 404, all four open sessions still answer 200 |
| (developer) all or nothing | `test_password_stays_when_the_sessions_of_that_user_cannot_be_ended` | when the session delete fails, the password is unchanged |
| After a CEO Logout, a second session of the same CEO is no longer authenticated | `test_ceo_logout_ends_every_session_of_that_account_and_no_others` | the second session is sent to login, the CEO has no session rows left, a bystander's session still answers 200 |
| A non-CEO Logout leaves that user's other session as today (pinned first, `5ea183c`) | `test_non_ceo_logout_leaves_the_other_session_of_that_user_signed_in` | Marketing; a CEO made Marketing after login; a CEO whose employee profile was deleted. The pressed session is sent to login, the other answers 200 |
| (review) a failed role read leaves nothing half-done | `test_logout_that_cannot_read_the_role_signs_nobody_out` | server error; both sessions still signed in |
| With a non-database driver the two paths run without error | existing tests, no new one: `OwnerPasswordChangeTest::test_ceo_sets_a_new_password_for_a_user`, `CeoRememberedLoginTest::test_logout_clears_the_remember_cookie_and_the_old_cookie_no_longer_signs_in`, `LoginCharacterizationTest::test_logout_signs_out_and_redirects_to_login` | they run the password change and the CEO and Marketing logout under the `array` driver with no `sessions` table in the database; a delete attempt would fail them with "no such table" |
| The 42 earlier Auth tests stay green | all files | 51 tests now |

`php.bat artisan test --filter Feature.Auth` at `65fc6c5`:

```
   PASS  Tests\Feature\Auth\CeoRememberedLoginTest      (23)
   PASS  Tests\Feature\Auth\EndOpenSessionsTest         (9)
   PASS  Tests\Feature\Auth\LoginCharacterizationTest   (15)
   PASS  Tests\Feature\Auth\OwnerPasswordChangeTest     (4)
  Tests:    51 passed (465 assertions)
```

Red runs (from the developer's reports):

- Pin (`5ea183c`): no red run by design; green on the code before B1 and B2.
- B1: `test_password_change_ends_the_open_sessions_of_that_user_and_no_others`, both rows then present, `Expected response status code [201, 301, 302, 303, 307, 308] but received 200.`
- B1 transaction: `test_password_stays_when_the_sessions_of_that_user_cannot_be_ended`, `Napalitan ang password kahit hindi natapos ang mga session. Failed asserting that false is true.`
- B2: `test_ceo_logout_ends_every_session_of_that_account_and_no_others`, `Expected response status code [201, 301, 302, 303, 307, 308] but received 200.`
- Logout order (`cd65bc3`): `test_logout_that_cannot_read_the_role_signs_nobody_out` failed before the fix; its first failure line was not readable (the error page's trace filled the output); the cause seen was the role query failing after the sign-out.
- The rows of `5bf439d` are pins (no red run).

**B5.2 Whole suite.** `php.bat artisan test`, once for this amendment, on the tree of `65fc6c5` (the last commit that touches code or tests is `5bf439d`; the commits after it change only agent memory, `TODO.md` and handoff files):

```
   FAILED  Tests\Feature\ExampleTest > the application returns a successful response
  Expected response status code [200] but received 302.

  Tests:    1 failed, 3 skipped, 507 passed (5289 assertions)
  Duration: 62.79s
```

Base: 456 passed, 3 skipped, 1 failed. Now 507 passed (the 51 new), the same 3 skipped, the same 1 failed (`ExampleTest`). No new failure.

**B5.3** `git diff 9332e43..HEAD --stat -- app`:

```
 app/Http/Controllers/Auth/LoginController.php    |  31 +++++
 app/Http/Controllers/OwnerUsersController.php    |  14 ++-
 app/Listeners/RefuseRememberedLoginUnlessCeo.php | 141 +++++++++++++++++++++++
 app/Providers/AppServiceProvider.php             |  12 +-
 4 files changed, 196 insertions(+), 2 deletions(-)
```

The whole branch is 18 files (these four, four test files, three handoff files plus the index, `TODO.md`, agent memory). `git diff 9332e43..HEAD --stat -- config database resources/views routes bootstrap` prints nothing.

`git log --oneline 964572b..HEAD` (before this section's own commit):

```
3217fc0 docs: review findings and reviewer memory for amendment 012-2
65fc6c5 docs: developer memory for the 012-2 minors wave
5bf439d test: pin refused password change and demoted-account logout under the database session driver (012-2 minors)
cd65bc3 fix: logout reads the role before signing out, and endSessionsOf takes an int user id (012-2 minors)
8994e71 docs: developer memory for database session driver tests (012-2)
07e1ad5 feat: a CEO logout ends every other session of that account (012-2 B2)
8d13eca feat: a password change on the owner users page ends the open sessions of that user (012-2 B1)
5ea183c test: pin that a non-CEO logout leaves that user's other session signed in (database session driver)
67ef3c1 docs: amendment 012-2 saved verbatim
```

**B5.4 Commits and tree:** Conventional Commits; `git log 9332e43..HEAD -i -E --grep="co-authored|claude-session|generated with" --oneline` prints nothing; `git status --short` is empty after this section's commit.

## Every way to become and to stop being signed in (final)

**Becoming signed in**

| # | Way in | CEO | Other roles |
|---|---|---|---|
| 1 | Login form, `POST /login` | Session, plus two cookies for 30 days from this login: the remember cookie and `ceo_remember_since` (user id and login time, encrypted) | Session only, exactly as on the base. A posted `remember` field is never read |
| 2 | The two cookies, on any request where the `web` guard finds no session | A new session, only when the account is CEO now and the second cookie is present, decrypts, names the same user id and is at most 30 days old. The cookies are not renewed | Always refused, also with a valid-looking pair |
| 3 | An existing session cookie | Stays signed in while used at least every 120 minutes | Same, as on the base |
| 4 | Registration (`/registerlogin`), `GET /api/user` (`auth:sanctum`, not installed locally) | Neither signs anyone in | Same |
| 5 | Not found | No second login controller, API or token login, impersonation, `loginUsingId`, password reset or basic auth route | Same |

**Stopping being signed in**

| Event | CEO | Other roles |
|---|---|---|
| 120 minutes without a request | The session ends; the next page load signs him in again from the cookies (within the 30 days) | The session ends; login page, as on the base |
| Logout | **Every session of the account ends, on every device**; both cookies cleared in this browser; token cycled, so the cookies in his other browsers are dead | Only the session it was pressed in, exactly as on the base |
| The account's password is changed on `/owner/users` | Every session of that account ends except, when he changes his own, the one he is in; token cycled, so every remember cookie of the account is dead | **Same for any account** (new with B1: before, a password change left a user's open sessions alone) |
| 30 days after the password login | A sign-in from the cookies is refused (server-side count): login page, both cookies cleared, token cycled. A session that is open at that moment continues until it has been idle for 120 minutes (B3 not built) | Not applicable |
| Role changed away from CEO, or employee profile removed | The next sign-in from the cookies is refused. An open session continues (the pages check the role on every request) | Not applicable |
| A sign-in from the cookies is refused in one browser (too old, second cookie missing or damaged, role, a database error at that moment) | That browser goes to the login page; the token is cycled, so the cookies in his other browsers are dead too. Open sessions elsewhere are **not** ended by a refusal | Not applicable |
| Session driver is not `database` | Logout and the password change still cycle the token; open sessions elsewhere are not ended | Logout as on the base; a password change ends no sessions |

## Review findings (amendment 2)

`skeptic-reviewer`, adversarial depth (opus), on `964572b..8994e71`. No fix loop was needed; the minors went into one wave.

- **Check 6** (a password change and a CEO Logout really end sessions that were already open, and cannot end sessions of any other user): **pass**, with the in-flight caveat above. Every save of a signed-in session writes `user_id` with the payload, so no signed-in row lacks it; the delete uses the same connection and table as the session driver; a request in flight in a deleted session updates zero rows and cannot write itself back; the condition is always `user_id = <int>`, guest rows (null) never match; the password route checks access first and answers 404 for a missing id before any delete; Logout uses the authenticated user's own id; a refused cookie sign-in cannot trigger the delete (the only callers are the two controllers); `POST /logout` stays behind CSRF.
- **Check 7** (nothing changes for non-CEO logins and logouts): **pass.** `login()` is untouched in this amendment. A non-CEO Logout gives the same redirect, session invalidation and cookies as `9332e43`; the one difference is one read of the employee profile before it (accepted minor 3).
- **Earlier five checks:** not weakened. The login path, the `Login` listener and its registration are unchanged; the token cycle on a password change is still there, now inside the transaction.
- **Spec:** B1, B2, B3 and every B4 line met; the deploy notes B1 and B2 ask for are in the checklist below.

| # | Severity | Finding | Outcome |
|---|---|---|---|
| 1 | minor, scope | The transaction around the password update and the session delete was not asked for | Kept (reviewer: keep on its merits), ruling below; `TODO.md` |
| 2 | minor, security, design | A sign-in in flight during the cut survives it | Accepted, `TODO.md`; described above with options; not started |
| 3 | minor | Logout read the role after the sign-out: a failed read left the pressing browser signed out and the other sessions alive | Fixed (`cd65bc3`): role read first; red test. Cost: every role's Logout now depends on one profile read (`TODO.md`) |
| 4 | minor | `endSessionsOf` took an untyped user id | Fixed (`cd65bc3`): `int` |
| 5 | minor, missing test | No named test for the non-database driver | Accepted, `TODO.md`: three existing tests prove it (table above) |
| 6 | minor, missing test | A non-CEO caller of the password route under the database driver | Fixed (`5bf439d`) |
| 7 | minor, missing test | Logout by a demoted CEO and by an account without a profile | Fixed (`5bf439d`) |
| 8 | minor | The empty-id guard has no reachable caller and no test | Accepted, `TODO.md` |
| 9 | minor, architecture | A static session helper in a listener class, called from two controllers | Accepted, `TODO.md` |

Not re-reviewed: the minors wave (`cd65bc3`, `5bf439d`): one moved line, one type, and test rows; the main session read its diff and ran the tests. Declined to judge by the reviewer: the full suite (run by the main session), the server's real session driver, connection and table, MySQL locking, the race of finding 2 (not executed), and the red runs (listed above from the developer's reports).

## Rulings (amendment 2)

- Ruling: B2 lives in `LoginController::logout()`, not on the framework's `Logout` event - that event also fires when a cookie sign-in is refused, and an expired cookie in one old browser must not end the CEO's live sessions everywhere; B2 says "presses Logout" - none.
- Ruling: one delete with `user_id = ?` and `id != <caller's session>` serves both B1 cases - for someone else's password the caller's row is not among that user's, for his own it is the one kept - none.
- Ruling: the password update and the session delete share one transaction - a failed delete then changes nothing and says so, where otherwise the password would be changed, the sessions alive and the error would read as "nothing happened" - a database error on the `sessions` table blocks a password change until it is fixed.
- Ruling: Logout reads the role before signing out - a failed read then leaves everything as it was and he presses Logout again, where otherwise the CEO would be half signed out - a Logout by any role now fails with a server error if the profile cannot be read at that instant.
- Ruling: the helper is a static method on `RefuseRememberedLoginUnlessCeo` - no new class was allowed, and it keeps the 012 rules in one file - a listener class that two controllers call; a suggestion for the owner if the auth code grows.
- Ruling: finding 2 (in flight) was accepted and described, not built - its fix is the per-request design B3 rules out, and this is the last amendment - a prepared attacker who already holds working credentials can keep one session through a single cut; a second cut ends it.
- Ruling: the full suite ran on the last code commit, not on the final docs commit - the commits after it touch only agent memory, `TODO.md` and handoff files - none.

No conflict between CLAUDE.md and the amendment. All file writes used the Write and Edit tools.

## Deploy checklist for Mira (complete; replaces every earlier list)

**Before deploying**

1. On the server, see which session driver is running, from the running config and without opening any env file: `php artisan config:show session.driver` (or `php artisan about`, section "Drivers", line "Session"). It must say `database` for B1 and B2 to end open sessions. With anything else the deploy is still safe, but Logout and a password change only kill the cookies; tell me and it becomes a task.
2. `php artisan config:show session.connection` and `session.table`: expect `null` (the default connection) and `sessions`. The delete uses exactly these.
3. `SHOW COLUMNS FROM users LIKE 'remember_token';` and `SHOW COLUMNS FROM sessions LIKE 'user_id';` both return a row (standard columns; no migration in this branch).

**Deploy**

4. Pull the merged code. No migration, no config change, no asset build, no queue restart.
5. `php artisan optimize:clear`, then the caching the server normally runs (`php artisan optimize`, or `config:cache` and `route:cache`). The role re-check does not depend on the events cache; this only removes caches from before this code.

**Check on the server**

6. `php artisan event:list --event=Login`: under `Illuminate\Auth\Events\Login` the first listener is `App\Listeners\RefuseRememberedLoginUnlessCeo@recheckRememberedLogin`, once. `php artisan event:list --event=Logout`: `…@forgetSinceCookie`, once.

**Check in a browser** (nothing in this handoff was seen in a browser)

7. Log in as the CEO with the password (an old session gets no cookies). Devtools shows `remember_web_…` and `ceo_remember_since`, both Secure, HttpOnly, SameSite Lax, expiring 30 days out. If Secure is missing, set `SESSION_SECURE_COOKIE=true` on the server and repeat.
8. Delete only the session cookie and reload: still signed in (cookie sign-in works).
9. Delete the session cookie and `ceo_remember_since` and reload: the login page, and `remember_web_…` is gone too (the server-side rule works). Log in again.
10. Open a second browser (or a private window) and log in as the CEO there too. In the first, press Logout. Reload the second: the login page (B2). Its cookies are still shown but no longer work.
11. Log in as the CEO in two browsers and as a staff user in a third. As the CEO, change the staff user's password on `/owner/users`: the staff browser goes to the login page on its next click, both CEO browsers stay in. Then change the CEO's own password: the browser he did it in stays in, the other goes to the login page (B1).
12. Log in as a Marketing user in two browsers and press Logout in one: the other stays signed in, and neither ever had the two cookies (unchanged for other roles).

**Tell Busing**

13. **Logout signs him out on every device at once.** For a lost or shared device, or if he suspects someone else is in: change the password on `/owner/users`, wait a few seconds, then press Logout. After that nothing that was signed in or remembered before works any more.
14. He stays signed in for 30 days from each password login, per browser; on day 31 that browser asks for the password. If one browser is refused (too old, a damaged cookie), his other browsers ask for the password once their own session has been idle for two hours.
15. Changing any user's password on `/owner/users` now signs that user out everywhere at once.
16. A page left open for hours can answer "CSRF token mismatch." on a save: copy the text, reload, save again.

**Server facts**

17. Both cookies are encrypted with the app key: rotating it ends every remembered login. If `vps` and `vps2` serve the same site they need the same key and clocks within 5 minutes.
18. Rollback: revert the merge, then have the CEO press Logout once or set his `users.remember_token` to null. The framework accepts a remember cookie with or without this code; after a revert neither the role re-check nor the 30-day check would run.

## Proposed tasks (final)

1. **B3, a hard 30-day age for a remembered session, checked on every request.** What it would cost: the login controller and the listener write the login time into the session; one small middleware in the web group compares it on each request and ends the session past 30 days (one new class, one registration line in `bootstrap/app.php`, about 40 lines with tests; no query per request, the session is already loaded). Decisions needed: CEO only or every role, and whether it should also carry a "sessions valid after" time per user, which would close the in-flight case above but needs a stored value (a column or the cache). High tier.
2. **Friendly message on 419** in the three save handlers of `/owner/private`. Views only.
3. **Clear leftover CEO cookies when a non-CEO logs in** on the same browser, so a shared computer cannot fall back to the CEO when the other account's session lapses.
4. **`CopyEverydayTasksOnLogin` in its own try/catch** (log and continue), so a task-copy error cannot block a login.
5. Suggestions for the owner, not done: remove the `/debug/ip` route; the plain-text `password_plain` column and the password echoed in the response of `updatePassword` deserve their own decision; public registration at `/registerlogin`; delete the dead `app/Http/Kernel.php` and `app/Providers/EventServiceProvider.php`; `AppServiceProvider` has a stray `deleteAll()` method and reads `env()` in `boot()`; `ExampleTest` still fails.
