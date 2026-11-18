# 298 result: Account registration inside the app

Base: live 2940b58b. Branch `feat/298-account-registration`.

## Plan

Base 2940b58b: full suite once with `vendor/bin/phpunit` under Herd PHP before any code change; base failures listed under Evidence.

Files:
- New `app/Support/AccountRoles.php`: the four roles in order (CEO, Marketing - OIC, Data Encoder - OIC, Marketing), `canonical()` (trimmed, spaces collapsed, case-insensitive → canonical spelling or null), `mayCreateAccounts()`, `creatableBy()` (CEO: all four; Marketing - OIC: all but CEO; others: none). The page and the server check both read it.
- New `app/Http/Controllers/AccountController.php`: `create` (form) and `store`. 403 for any signed-in role other than CEO / Marketing - OIC (as `RoleAssignmentController` / `EnsureCeo` refuse). `store` validates name, email (unique), role (must canonicalise into the actor's `creatableBy` list), password (`confirmed`, min 6 as the old sign-up had); then in one transaction `User::create` (name, email, bcrypt password; the existing `User::booted` makes the profile), sets the profile's role, and writes `password_plain` and `created_by` on the user by query builder (never from the request beyond the validated fields). Redirects back to the form with the new account (name, email, role, employee code) in a success message; no password is ever echoed.
- New view `resources/views/accounts/create.blade.php` in `<x-layout>`, Tailwind, `@csrf`, role dropdown from `creatableBy()`.
- `routes/web.php`: `GET /accounts/create` (`accounts.create`) and `POST /accounts` (`accounts.store`, throttled) inside the existing `['web','auth','allowed_ip']` group next to `/owner/users`; `/registerlogin` becomes a redirect to `/login` (GET and POST), route name `register` gone; `RegisterController` and `auth/register.blade.php` deleted; the `Register` link in `welcome.blade.php` and the "Register here" line on the login page removed.
- Creator record: migration `2026_11_29_298000_add_created_by_to_users` (nullable `users.created_by`), because `staff_events` is pruned after 90 days and has no field for the new account's id (see Rulings). `OwnerUsersController@index` + `owner/users.blade.php`: a "Created by" column (creator's employee name, else email) and a "New account" button.
- Nav: migration `2026_11_29_298100_add_new_account_nav_link` (`/accounts/create`, "New Account", visible to CEO and Marketing - OIC only, `section = 'Settings'` only when the column exists), plus its row in `NavLink::defaultData()` and `/accounts/create => Settings` in `NavSections` (both 295 files, in their own commit so the other site can skip it).
- Tests `tests/Feature/Accounts/*` (sqlite in memory, own base case that does not need spec 295); `AssignRolesGuardTest::test_sign_up_page_stays_open_signed_out` replaced (the sign-up page is now closed).

Order: roles class + tests; controller, routes, view + AR-1/AR-2 tests; AR-3 creator column + owner page; AR-4 close registration; AR-5 nav link; full suite vs base; route:list and HTML evidence; result; push.

## What shipped

Accounts are now made inside the app at **`/accounts/create`**, only by a CEO or a Marketing - OIC, with the role set at creation. The public sign-up is closed.

- **AR-1 Create an account.** Form: name, email, role, password, password confirmation (`@csrf`, `<x-layout>`, Tailwind like `/profile`). Saving runs `User::create` (the existing `User::booted` makes the one employee profile: name, `EMP-00N`, `Active`), sets the profile's role, and redirects back to the form with "Account created." and the new account's name, email, role and employee code. Invalid input comes back on the form with the field errors; nothing is created (validation runs before anything is written, and the writes are in one transaction).
- **AR-2 Who may create which role.** `App\Support\AccountRoles` holds the four roles in order (CEO, Marketing - OIC, Data Encoder - OIC, Marketing) and is the only list: the dropdown is `creatableBy(actor role)` and the server validates the posted role against the same call. CEO: all four. Marketing - OIC: all but CEO. Anyone else (Marketing, Data Encoder - OIC, Data Encoder, no role): 403 on GET and POST, as `RoleAssignmentController` / `EnsureCeo` refuse. Guests: redirected to `/login` by `auth`. Roles (actor's and posted) are compared trimmed, inner spaces collapsed, case-insensitive; the stored role is the canonical spelling.
- **AR-3 Password and creator.** Stored exactly as `/owner/users` stores the passwords it sets: bcrypt `password` and plain `password_plain`. The creator's id goes in a new nullable `users.created_by`; `users.created_at` is the when. `/owner/users` (still CEO only) has a "Created by" column (the creator's employee name, else their email; "—" for accounts made before this page) and a "＋ New account" button. The new-account page never shows any password (not the new one, not the creator's, not anyone's).
- **AR-4 Public sign-up closed.** `/registerlogin` is `Route::redirect` to `/login` (any method, so GET and POST both go to `/login`, nothing is created). `RegisterController` and `auth/register.blade.php` are deleted; the `register` route name is gone. Its only users were `auth/register.blade.php` (deleted) and `welcome.blade.php` (the `Register` link removed). The login page's "Don't have an account? Register here" (which pointed at `/login`) now reads "Don't have an account? Ask your CEO or Marketing - OIC to create one."
- **AR-5 Find the page.** A nav link "New Account" (`/accounts/create`, `fa-user-plus`, active pattern `accounts*`) visible to CEO and Marketing - OIC only, like every role-gated link (a `nav_links` row with `nav_link_role_visibility`); in the sidebar's Settings section next to Users. Plus the button on `/owner/users` for the CEO.

### Files
- New: `app/Support/AccountRoles.php`, `app/Http/Controllers/AccountController.php`, `resources/views/accounts/create.blade.php`, migrations `2026_11_29_298000_add_created_by_to_users.php` and `2026_11_29_298100_add_new_account_nav_link.php`, `tests/Feature/Accounts/*` (base case + 4 test files).
- Changed: `routes/web.php` (old sign-up routes → one redirect; two new routes after `/owner/users`; the `RegisterController` import removed), `app/Http/Controllers/OwnerUsersController.php` (index: creator), `resources/views/owner/users.blade.php` (column, button, footnote), `resources/views/auth/login.blade.php`, `resources/views/welcome.blade.php`, `tests/Feature/Auth/AssignRolesGuardTest.php` (its "sign-up page stays open" test now expects the redirect), `app/Models/NavLink.php` + `app/Support/NavSections.php` (spec 295 files: the link in the Reset defaults and in Settings).
- Deleted: `app/Http/Controllers/Auth/RegisterController.php`, `resources/views/auth/register.blade.php`.

### Routes
| Method | Path | Name | Middleware | Who |
|---|---|---|---|---|
| GET | `/accounts/create` | `accounts.create` | web, auth, allowed_ip | CEO, Marketing - OIC (else 403) |
| POST | `/accounts` | `accounts.store` | web, auth, allowed_ip, throttle:30,1 | CEO (any role), Marketing - OIC (not CEO) |
| ANY | `/registerlogin` | — | web | redirect to `/login` |

The new routes sit in the same `['web','auth','allowed_ip']` group as `/owner/users`, so a Marketing - OIC needs an allowed IP like every other page there (the CEO is exempt, as everywhere).

### Migrations
| File | What |
|---|---|
| `2026_11_29_298000_add_created_by_to_users` | `users.created_by` unsigned big int, nullable, no foreign key, no index. Guarded by `hasColumn`; `down()` drops it |
| `2026_11_29_298100_add_new_account_nav_link` | nav link `accounts_create` → `/accounts/create` ("New Account", last in the menu); visible rows for CEO and Marketing - OIC, hidden rows for every other role found in `nav_link_role_visibility` plus the standard five; `section = 'Settings'` only when that column exists. Idempotent; `down()` removes the link and its rows |

Both are additive. Neither was run against any server.

## Evidence per done-when item

### 1. Tests

New tests, all passing (`vendor/bin/phpunit tests/Feature/Accounts`):

| File | Covers |
|---|---|
| `CreateAccountTest` (39) | AR-1: CEO creates Marketing → one profile, role Marketing, Active, `EMP-00N`, page shows it, logs out and **logs in at `/login`** with the new email and password; Marketing - OIC creates Data Encoder - OIC; 12 invalid inputs (bad / empty / used email, password 5 chars, mismatch, empty password, empty / blank name, role "Data Encoder", "Super Admin", empty, array) → error on that field, user and profile counts unchanged; errors and old input render, no password echoed. AR-2: **CEO creates each of the 4 roles**; **Marketing - OIC creates Marketing - OIC, Data Encoder - OIC, Marketing**; **Marketing - OIC posting "CEO" / "ceo" / "  Ceo " is refused, nothing created**; **Marketing, Data Encoder - OIC, Data Encoder, no role, empty role → 403 on GET and POST, nothing created**; **guest → `/login` on GET and POST, nothing created**; actor role matched trimmed / case-insensitive / doubled spaces; posted "  data encoder - oic " stored as "Data Encoder - OIC"; dropdown order per creator; CSRF token in the form; extra fields (`created_by`, `password_plain`, `remember_token`, `id`) never reach the account |
| `AccountCreatorTest` (5) | AR-3: **an account by a Marketing - OIC is on `/owner/users` with its password and "Created by" naming her**; `password_plain` and `created_by` stored, `created_at` now; older accounts show "—"; creator falls back to email; the new-account page never contains the creator's, another user's or the new password; `/owner/users` still 404 for Marketing - OIC |
| `PublicRegistrationClosedTest` (6) | AR-4: **guest POST `/registerlogin` → `/login`, no user, no profile, still a guest**; signed-in POST creates nothing either; GET → `/login`, no form; `Route::has('register')` is false; login page has no "Register"; welcome page has no register link |
| `NewAccountNavLinkTest` (10) | AR-5: the link renders in the layout for CEO and Marketing - OIC, not for Marketing, Data Encoder - OIC, Data Encoder; the migration's row and visibility; idempotent and `down()`; Settings when the column exists; works with no `section` column; `/owner/users` has the New account button |

Changed: `AssignRolesGuardTest::test_sign_up_page_stays_open_signed_out` → `test_old_sign_up_page_sends_to_login_signed_out` (it pinned the open sign-up this spec closes).

Related suites on the branch: `tests/Feature/Nav tests/Feature/Access tests/Feature/Accounts tests/Feature/Auth` → OK (256 tests, 1509 assertions).

Full suite (`vendor/bin/phpunit`, Herd PHP 8.4):

| Run | Tests | Assertions | Failures | Skipped |
|---|---|---|---|---|
| Base 2940b58b (clean export of that commit + the same vendor/) | 5679 | 85557 | 3 | 6 |
| Branch `14b986ad` | 5739 (+60, all new ones in `tests/Feature/Accounts`) | 85811 | 3 | 6 |

The same three fail on both, none new:
1. `ExampleTest::test_the_application_returns_a_successful_response`: `/` answers 302 (to login), not 200.
2. `Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`: actual size 0, expected 45.
3. `OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_…` with `/owner/private`: render hash `3fe659e1…` instead of the pinned `cb5ad1ee…`; the branch renders the same `3fe659e1…` as the base (its test layout has no nav rows, so the new link does not change it).

### 2. `php artisan route:list`

```
  POST      accounts ........................................................ accounts.store › AccountController@store
            ⇂ web
            ⇂ auth
            ⇂ allowed_ip
            ⇂ throttle:30,1
  GET|HEAD  accounts/create ............................................... accounts.create › AccountController@create
            ⇂ web
            ⇂ auth
            ⇂ allowed_ip

$ php artisan route:list --path=registerlogin -v
  ANY       registerlogin .................................................... Illuminate\Routing › RedirectController
            ⇂ web

$ php artisan route:list --name=register
   ERROR  Your application doesn't have any routes matching the given criteria.
```

`php artisan view:cache`: "Blade templates cached successfully." (then `view:clear`).

### 3. Rendered form (HTML excerpts from the test app, sqlite)

CEO, role list:
```html
<form method="POST" action="http://localhost/accounts" class="space-y-6">
  <input type="hidden" name="_token" value="…" autocomplete="off">
…
<select id="role" name="role" required class="…">
  <option value="" selected>Choose a role</option>
  <option value="CEO" >CEO</option>
  <option value="Marketing - OIC" >Marketing - OIC</option>
  <option value="Data Encoder - OIC" >Data Encoder - OIC</option>
  <option value="Marketing" >Marketing</option>
</select>
```

Marketing - OIC, role list (no CEO):
```html
<select id="role" name="role" required class="…">
  <option value="" selected>Choose a role</option>
  <option value="Marketing - OIC" >Marketing - OIC</option>
  <option value="Data Encoder - OIC" >Data Encoder - OIC</option>
  <option value="Marketing" >Marketing</option>
</select>
```

Marketing - OIC posting role "CEO" with a bad email (form re-rendered):
```html
<p class="text-sm text-red-600 mt-1">The email field must be a valid email address.</p>
…
<p class="text-sm text-red-600 mt-1">Choose one of the roles in the list.</p>
```

After a Marketing - OIC creates a Data Encoder - OIC:
```html
<div class="mb-6 rounded border border-green-200 bg-green-50 text-green-800 px-4 py-3" role="status" data-account-created>
  <p class="font-semibold">Account created.</p>
  <dl class="mt-2 grid grid-cols-[auto,1fr] gap-x-4 gap-y-1 text-sm">
    <dt class="text-green-700">Name</dt><dd>Nina Santos</dd>
    <dt class="text-green-700">Email</dt><dd>nina@example.test</dd>
    <dt class="text-green-700">Role</dt><dd>Data Encoder - OIC</dd>
    <dt class="text-green-700">Employee code</dt><dd>EMP-003</dd>
  </dl>
  <p class="mt-2 text-sm">They can sign in at <a href="http://localhost/login" class="underline">/login</a> with this email and the password you set.</p>
</div>
```

`/owner/users` (CEO): `<th style="min-width:180px;">Created by</th>`, `<a href="http://localhost/accounts/create" class="u-btn" … data-new-account>＋ New account</a>`, and in the sidebar's `data-nav-section="Settings"` the links end `… data-nav-link="/owner/users" … data-nav-link="/system-monitor/storage" … data-nav-link="/accounts/create"`.

### 4. Branch pushed

`git push -u origin feat/298-account-registration`: new branch on origin; code head `14b986ad`, result commits on top. No PR opened, nothing deployed, no migration run on any server.

## Story changes

None to the stories. Two additions inside them: the login page's "Register here" line (it pointed at `/login`, not at the sign-up) is replaced by a sentence, since the page no longer offers a sign-up; and `/owner/users` also gets a "New account" button besides the nav link (AR-5 allowed either; both are there).

## Rulings

1. **Creator: a nullable `users.created_by`, not `staff_events`.** `staff_events` is the event table for this kind of record, and `RecordStaffActivity` already writes a row for every `POST /accounts` that answers 2xx/3xx (kind `accounts.store`, the creator's id and role; a validation redirect counts too, so it cannot even tell a made account from a refused form). But it is pruned after 90 days (`staff-events:prune`) and has no field for the new account's id (`ref` is filled only from route parameters, and `POST /accounts` has none), so it cannot trace an account to its creator for good. No created_by-style column exists on `users` or `employee_profiles` (`supervisor_id` means something else; `remarks` is free text). So one nullable column, no foreign key (a deleted creator leaves the id, as the other `created_by` columns that have none). The when is `users.created_at`.
2. **Path `/accounts/create` + `POST /accounts`**, not under `/owner/users`: the Users nav link covers `owner/users*` and is visible to the CEO only, so under spec 294's enforce mode a Marketing - OIC would be refused there. The new link covers `accounts*` and is visible to both roles, so access mode and page check agree.
3. **Password minimum 6**, the old public sign-up's rule (`/owner/users` password edit uses 4). Max 255. The new account's password is not shown back after saving; the creator typed it and `/owner/users` shows it to the CEO.
4. **A Marketing - OIC posting CEO gets a validation error on `role`** ("Choose one of the roles in the list.", redirect back to the form), not a 403: the page is hers, only that value is refused. Nothing is created. A user who may not use the page at all gets 403.
5. **403 for the wrong role**, as the spec asks and as `RoleAssignmentController` and `EnsureCeo` do (`/owner/users` itself answers 404; left as is).
6. **"Data Encoder" is not in the list.** The spec names four roles in use; the nav migrations also seed a fifth, "Data Encoder". It cannot be given here (posting it is "role not in the list"). If staff with that role are still hired, add `'Data Encoder'` to `AccountRoles::ALL` (one line; the page and the check follow). **Reviewer to decide.**
7. **`/registerlogin` redirects rather than 404s**, so an old bookmark lands on the login page. CSRF still applies to the POST outside tests (a guest POST without a token gets 419); either way no code path creates an account there.
8. **Left untouched on purpose** (harmless, outside this spec's files): `AllowedIpMiddleware` still lets the path `registerlogin` through (it only redirects now), and `config/access.php` keeps `register` in the open list (a path that never existed).
9. **The new routes are in the `allowed_ip` group** with `/owner/users`: a Marketing - OIC creates accounts from an allowed IP, like her other work pages.

## Merge danger

**What ships**: two routes + controller + view, the closed sign-up, a column on `/owner/users`, one nav link. Commits (oldest first):

| Commit | What | Other site |
|---|---|---|
| `b420e323` | AccountRoles, AccountController, view, `created_by` migration, routes (new + `/registerlogin` redirect), `AccountsTestCase`, `CreateAccountTest` | needed |
| `a4ccfb2b` | `/owner/users` Created by + New account button, `AccountCreatorTest` | needed |
| `25f4a5d9` | RegisterController + register view deleted, login/welcome links, `PublicRegistrationClosedTest`, `AssignRolesGuardTest` change | needed |
| `dce015c6` | nav link migration + `NewAccountNavLinkTest` | needed |
| `14b986ad` | `NavLink::defaultData()` row + `NavSections` Settings entry | **only with spec 295**; skip otherwise |

**Migrations**: two, additive (`users.created_by`; one `nav_links` row + its visibility rows). Run `php artisan migrate` on deploy. Until it runs, the page still works: `created_by` is written and read only when the column exists (as `password_plain` already is), so accounts made before the migration have no creator.

**Revert**: revert the five commits (newest first). That brings back the open `/registerlogin`. To drop the data too: `php artisan migrate:rollback --path=database/migrations/2026_11_29_298100_add_new_account_nav_link.php` and `--path=database/migrations/2026_11_29_298000_add_created_by_to_users.php` (drops `users.created_by`: the creator of accounts made meanwhile is lost; the accounts themselves stay).

**Other site's cherry-pick (Incepxion, may lack 293-296)**:
- The first four commits touch no 295 file. Expected conflicts are context only: `routes/web.php` (the import line and the two blocks; the `/owner/users` routes must exist there; if they don't, put the two new routes in that site's `['web','auth','allowed_ip']` group), `owner/users.blade.php` / `OwnerUsersController.php` if that site's copies differ, and `AssignRolesGuardTest` if that site has no such test (drop the hunk).
- Without spec 295's sidebar: the nav link migration only sets `section` when the column exists, so it works on the old nav (the link shows wherever that nav lists links, visible to CEO and Marketing - OIC). `NewAccountNavLinkTest` checks the link by its url, skips the Settings check without the column, and has a test that runs the migration with no `section` column. `AccountsTestCase` does not use spec 295's `NavTestCase`; it runs whatever `*nav*` migrations the line has.
- Skip `14b986ad` there (or, if that site's `NavLink::defaultData()` has no `section` key, add just the row without it). Without the row, the nav settings Reset leaves the link as it is (Reset only upserts its defaults; it deletes nothing).
- Check on that site that `User::booted` still creates the employee profile (AR-1 relies on it) and that `users.password_plain` exists (else the password copy is skipped silently, like `/owner/users`).
