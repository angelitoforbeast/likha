# Brief 027: only a signed-in CEO may open or use the role assignment page

`routes/web.php` ~105-106: `GET /assign-roles` (`RoleAssignmentController@index`, lists every user with their employee profile) and `POST /assign-roles/{id}` (`update`, sets any user's `employee_profiles.role`, CEO included) have no `auth` middleware and no role check. A signed-out request to the live page answers 200. Anyone can open it and make any account CEO, which defeats every role rule in the app. The public sign-up page `/registerlogin` stays open (the owner's decision); this fix must not change it.

The fix:
1. Both routes require `auth`; a signed-out GET redirects to the login page as other pages do; a signed-out POST is refused.
2. Both routes are allowed only to a user whose `employeeProfile->role` is CEO, compared trimmed and case-insensitive (use the app's existing CEO middleware if one fits, e.g. `EnsureCeo`, after reading what it compares; otherwise a small check in the controller). Anyone else gets 403 (JSON `{"success":false,"error":"..."}` for the POST, which the page calls with fetch, see `resources/views/assign_roles.blade.php`).
3. Validate `role_name` against the existing roles (`Spatie\Permission\Models\Role` names, the same list the page shows) instead of any string up to 255 chars. Keep the response shape the page expects.
4. Nothing else changes: the page's look and its JS, login, sign-up, every other route.

Tests (feature, as the repo does): signed-out GET redirects to login and POST is refused with no role change; a signed-in non-CEO (e.g. role Marketing) gets 403 on both and no role change; a signed-in CEO can open the page and change a user's role to an existing role; an unknown role name is refused; `/registerlogin` still answers 200 signed out. Red first, then green. The whole suite must pass except the known `ExampleTest` and `ImportStartTest` (credentials file); name any other.
