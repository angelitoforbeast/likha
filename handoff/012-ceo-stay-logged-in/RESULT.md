# Result 012: The CEO account stays logged in for 30 days

Status: **built and reviewed, with one security major open for Mira's ruling** (the 30 days are enforced only by the browser; see "Question for Mira"). Branch `feat/012-ceo-stay-logged-in`, cut from `develop` at `9332e43`. Nothing was pushed, merged or deployed, and there is no PR, so this file stands in for the PR body. Nothing was seen in a browser (no browser tools, no dev server in the allowed commands).

## Plan

1. First commit: this handoff saved verbatim, this file, and the row in `handoff/README.md`.
2. Tests first (`tests/Feature/Auth/`): pin today's login, failure and logout behaviour for the non-CEO roles, then the red tests of section 5 (CEO remember cookie with a 30-day expiry, sign-in from the cookie alone, refusal after a role change or a removed profile, logout cycles the token, a tampered cookie signs nobody in).
3. `LoginController::login()`: the same `Auth::attempt($credentials)` as today; after the password is accepted, the account's role decides: for a CEO the guard's remember duration is set to 43,200 minutes (30 days) and the remember cookie is issued. No request parameter is read; `session()->regenerate()` and the redirect stay as they are. (Corrected after review: the first wording had the role decide the `remember` argument of `Auth::attempt` before the password check; that read the profile on failed logins, see finding 4.)
4. D3 as one listener class on the framework's `Login` event (`app/Listeners/`, next to the existing `CopyEverydayTasksOnLogin`, picked up by the framework's listener discovery so there is no registration line): when the sign-in came from the remember cookie (`viaRemember()`) and the account is not CEO, logout. It runs inside the guard at the moment of the cookie sign-in, so no route or middleware order can skip it.
5. `skeptic-reviewer` at adversarial depth (high tier) with the three named checks, fixes or `TODO.md`, the full suite at the end, evidence per done-when item in this file; no push, no PR, no deploy.

## Summary

When an account whose role reads as CEO logs in with its password, the browser now also gets Laravel's remember cookie with a 30-day lifetime, counted from that login (the cookie is not renewed by later visits). When the session lapses or the browser is restarted, the next page load signs him in again from the cookie. Logout clears the cookie and cycles the stored token. Every other role logs in exactly as before and gets no cookie, whatever the form posts.

Whenever the framework signs someone in from the cookie, `App\Listeners\RefuseRememberedLoginUnlessCeo` reads the role again. If the account is not CEO then (role changed, employee profile removed, or the role cannot be read because of an error), it is logged out on the spot: the request goes to the login page, the cookie is cleared and the token is cycled, so the old cookie is dead for good.

Six things for Mira to know:

1. **Open major: the 30 days are a browser-side limit only.** A copied cookie value works after day 30 until the CEO presses Logout. Details and options under "Question for Mira". The handoff's own threat model line ("is the CEO for up to 30 days") is not true for the build as specified in D2.
2. **The app has no flag that blocks a login.** Login is a bare `Auth::attempt`; `employee_profiles.status` is set to `Active` on creation and nothing reads it at login. So D3's "account disabled by whatever flag the app uses" has nothing to check; the re-check covers a role change and a removed profile.
3. **Changing a password in this app does not end a remembered login** (section 6 question). `OwnerUsersController::updatePassword` writes only the password with a direct table update and never touches `remember_token`, and this framework version checks only the id and the token of the cookie, not the password part. Proposed task 2.
4. **No registration line.** The listener is found by the framework's listener discovery, exactly as the existing `CopyEverydayTasksOnLogin` is (`app/Providers/EventServiceProvider.php` and `app/Http/Kernel.php` are dead files in this Laravel 12 app; only `AppServiceProvider` is registered). If the server has a cached events file, the listener is not loaded until the cache is rebuilt: see the deploy notes, this one matters.
5. **One CEO password login fires the framework's `Login` event twice** (once from `Auth::attempt`, once from issuing the cookie). The only other listener copies the day's everyday tasks and skips ones that exist, so the effect today is a few repeated queries. The `Login` event also fires on every cookie sign-in, so his everyday tasks are now copied on those too (useful: before, only a password login did it).
6. **Stale page (D6):** saves from a page left open past the session lifetime answer "CSRF token mismatch." until he reloads; nothing typed is lost while the editor stays open. See "The stale page case".

## Question for Mira (blocker for "safe to ship", not for the code as specified)

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
6. Suggestions for the owner, not done: remove the `/debug/ip` route (returns request IP headers to any signed-in user); `OwnerUsersController::updatePassword` also writes the new password in plain text to `users.password_plain` when that column exists and returns it in the response, which deserves its own decision; public registration at `/registerlogin`; delete the dead `app/Http/Kernel.php` and `app/Providers/EventServiceProvider.php`; `ExampleTest` still fails (expects 200 on `/`, gets 302).
