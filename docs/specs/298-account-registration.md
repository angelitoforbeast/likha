# 298 — Account registration inside the app, by CEO and Marketing - OIC only

## Why
Accounts are made at `/registerlogin` (`Auth\RegisterController`), which is public: anyone on the internet can open it and create a user (both sites answer 200 without a login). The new account gets an `employee_profiles` row (User::booted) with no role, and someone then has to set the role elsewhere. The owner wants accounts created only inside the app, by the people allowed to, with the role set at creation.

Roles in use (`employee_profiles.role`, trimmed), highest first: **CEO**, **Marketing - OIC** (second highest), **Data Encoder - OIC**, **Marketing**.

## Stories and cases
**AR-1 Create an account (P1).** A signed-in CEO or Marketing - OIC opens a new page (for example `/accounts/create`; pick the path and record it) with: name, email, role, password and password confirmation. Saving creates the user and its employee profile with that name and role (the existing User::booted profile creation, then the role set; no second profile), and shows the new account with a success message.
- H: CEO creates a Marketing account → can log in at `/login` with that email and password; the profile has role "Marketing", status Active, the usual employee code.
- H: Marketing - OIC creates a Data Encoder - OIC account → succeeds.
- N: invalid input (bad email, email already used, password under the existing minimum, confirmation mismatch, empty name, role not in the list) → the form shows the errors, nothing is created.

**AR-2 Who may create which role (P1).** Only CEO and Marketing - OIC may open or post the page; everyone else gets 403 (or the app's usual refusal for role-gated pages; match the existing CEO checks). The role choices are enforced on the server:
- CEO may create any role, CEO included.
- Marketing - OIC may create every role except CEO: Marketing - OIC, Data Encoder - OIC, Marketing.
- N: Marketing - OIC posts role "CEO" (bypassing the dropdown) → refused, nothing created.
- N: Marketing, Data Encoder - OIC, a user with no role, and a guest → cannot open or post (guest goes to login).
- E: role names are compared trimmed and case-insensitively, as the existing role checks do; the stored role uses the canonical spelling above.
- E: the list of roles and their order lives in one place in code (a small constant or class), reused by the page and the server check.

**AR-3 Password and who created it (P1).** The creator sets the password. Store it the way `/owner/users` (OwnerUsersController) already stores passwords it sets, so the new account appears there like every other account: the bcrypt `password` and the `password_plain` column that page shows to the CEO. Record who created each account and when, with no new table: prefer an existing column or event table already used for this kind of record (look at `staff_events` and any created_by-style columns first); add a nullable column only if nothing fits, and record the ruling. The CEO can see the creator somewhere sensible (for example a "Created by" column on `/owner/users`).
- H: Marketing - OIC creates an account → `/owner/users` lists it with the password visible to the CEO and "created by" naming the Marketing - OIC user.
- N: the creator's own password and other users' passwords are never shown to a Marketing - OIC on the new page.

**AR-4 Close the public registration (P1).** `/registerlogin` (GET and POST) no longer creates accounts or shows a form: remove the routes (or send them to `/login`), and remove any link to it (for example in `welcome.blade.php`). The route name `register` must not break other views: search for every use and fix it.
- N: a guest POSTs to `/registerlogin` → no user is created (test).

**AR-5 Find the page (P2).** CEO and Marketing - OIC see a link to the new page where they manage people (the sidebar/nav or the `/owner/users` page; follow how other role-gated links are shown); others don't see it. Keep it working on a site without spec 295's nav (state how in the result).

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Only CEO and Marketing - OIC create accounts | Account creation is a privilege of the top two roles |
| Marketing - OIC is second highest and may create every role except CEO | Only a CEO makes another CEO |
| The creator sets the password; it is stored like `/owner/users` stores it | The CEO already reads staff passwords there and wants new accounts there too |
| Record who created each account | So every account can be traced to its creator |
| Replace the public `/registerlogin` | Anyone could create an account there |

## Constraints
- Lean schema: no new table. A nullable column only if no existing place fits (ruling in the result).
- Same style as the app's existing pages (Blade, Tailwind, the layout other pages use); keep every comment in the language and style of the file around it.
- CSRF on the form; validation on the server; no mass assignment of role or creator from the request beyond the validated fields.
- Feature tests for AR-1..AR-4 in the existing test layout. The full suite adds no new failures compared with base 2940b58b (list the base failures).
- Keep the change easy to cherry-pick onto the other site's line: touch only the files this spec needs.

## Threat model and risk
Untrusted: every form field, especially role (a Marketing - OIC posting CEO) and email; guests hitting the old and new routes. Trusted: the signed-in user's own role from employee_profiles. Risk: privilege escalation (a non-CEO making a CEO), an open signup left behind, a broken page that linked to `register`. Risk tier: medium (auth surface, public routes).

## Allowed commands
composer install; vendor/bin/phpunit and php artisan test (with filters) via Herd PHP; php artisan route:list; php artisan view:cache; php artisan migrate on the test database only if a column is truly needed; git add, git commit, git push -u origin feat/298-account-registration.

## Done when
1. Tests for AR-1..AR-4 pass, including: CEO creates each role, Marketing - OIC creates Marketing - OIC, Data Encoder - OIC and Marketing, Marketing - OIC posting CEO is refused, other roles and guests are refused, invalid input creates nothing, the account logs in, it appears on `/owner/users` with password and creator, and a guest POST to `/registerlogin` creates nothing. The full suite adds no new failures compared with base 2940b58b (list them).
2. `php artisan route:list` evidence: the new routes and their middleware; `/registerlogin` gone or redirecting.
3. Evidence of the rendered form for a CEO and for a Marketing - OIC (role list differs), as HTML excerpts, in the result.
4. The branch is pushed; the result has a merge-danger section (what ships, any migration, how to revert, what the other site's cherry-pick needs).

## Out of scope
Editing or deleting accounts; changing roles of existing users; password reset by email; the nav redesign; deploy (the reviewer does it).

## Budget
Small-to-medium job. At most 2 attempts at the same failing step, then stop and report.
