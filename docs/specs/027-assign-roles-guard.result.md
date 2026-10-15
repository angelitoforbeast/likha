# Result 027: only a signed-in CEO may open or use the role assignment page

## What changed

- `routes/web.php`: `GET /assign-roles` and `POST /assign-roles/{id}` now carry the `auth` middleware. Signed out, the GET redirects to `/login`; the POST is refused (401 for a JSON request, a redirect to login otherwise). Nothing else in the file changed.
- `app/Http/Controllers/RoleAssignmentController.php`: a private `isCeo()` check (the employee profile's role, trimmed, compared case-insensitively to `CEO`) at the top of `index` (403 page) and `update` (403 with `{"success":false,"error":"..."}`). `update` now refuses a `role_name` that is not a row in the Spatie `roles` table (the same list the page shows) with 422 and `{"success":false,"error":"..."}`; the success response is unchanged.
- The existing `EnsureCeo` middleware was not used: it compares `role !== 'CEO'` exactly (no trim, case-sensitive), and changing it would also change the Boardroom routes.
- The page, its JS, login, sign-up and every other route are untouched.

## Tests

`tests/Feature/Auth/AssignRolesGuardTest.php` (extends `AuthTestCase`, adds a `roles` table):

| Case | Test | Result |
| --- | --- | --- |
| Signed-out GET redirects to login | `test_signed_out_get_redirects_to_login` | pass (red before: 200) |
| Signed-out POST refused, no role change | `test_signed_out_post_is_refused_and_changes_no_role` | pass (red before: 200) |
| Non-CEO (Marketing) 403 on both, no role change | `test_non_ceo_gets_403_on_both_and_changes_no_role` | pass (red before: 200) |
| CEO opens page and sets an existing role (`CEO`, `  ceo `) | `test_ceo_opens_the_page_and_sets_an_existing_role` | pass (pins today's behaviour; green before and after) |
| Unknown role name refused, no role change | `test_unknown_role_name_is_refused` | pass (red before: 200) |
| `/registerlogin` stays 200 signed out | `test_sign_up_page_stays_open_signed_out` | pass |

Full suite (`vendor/bin/phpunit`): 921 tests, 14396 assertions; 1 error, 1 failure, 3 skipped. The two red tests are the known ones: `ExampleTest::test_the_application_returns_a_successful_response` and `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (credentials file). No other test is red.

## Notes and suggestions for the owner

- `php artisan test` prints a `file_get_contents(...)` warning on every test in this checkout (also on existing tests); `vendor/bin/phpunit` shows none. It comes from the runner, not this change.
- The route's `{id}` is ignored; the controller uses the posted `user_id`. Left as is.
- A failed `user_id` validation still answers with a redirect rather than JSON, as before.
