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

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
