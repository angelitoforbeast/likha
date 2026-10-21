# Result 105: the Ad Accounts page follows the Ad Payment access

## What shipped

- **Helper reused:** `App\Support\NavAccess::allows` (`app/Support/NavAccess.php:16`), the same one `/chatblast` and `/chatblast/page-settings` use. CEO is always allowed (the helper does that); any other role is allowed only when the `ads_payment` nav link (`/ads_manager/payment/upload`) is active and checked for that role. Refusal is **404**, like `ChatblastController::checkAccess`.
- `app/Http/Controllers/AdAccountController.php`: `NAV_URL = '/ads_manager/payment/upload'` (line 16), `getNormalizedRole()` and `checkAccess()` (lines 18–28, same shape as `ChatblastController`), called first in `index` (line 36, serves both `ad_accounts.index` and `ad_accounts.edit`), `store` (line 109) and `destroy` (line 178). So all four routes are gated, and a refused POST/DELETE writes nothing.
- `resources/views/ads_manager/payment/upload.blade.php:53`: the "Ad Account ID" button is wrapped in the same `NavAccess::allows(..., AdAccountController::NAV_URL)` check.
- No new nav link, no change to nav_links or nav_link_role_visibility rows, no migration. Spec 103's history and change log code is unchanged.

## Commits

- `6657b613` docs: brief for ad accounts access (spec 105)
- `86af053f` fix: ad accounts page follows the ad payment nav access (spec 105)
- this result: docs: result for ad accounts access (spec 105)

## Case table

Tests in `tests/Feature/AdAccount/AdAccountAccessTest.php`; they use the real `2026_05_18_140000_create_nav_links_and_visibility_tables` migration, whose seed gives Ad Payment to Marketing, Marketing - OIC and CEO.

| Case | Test | Result |
|---|---|---|
| S-105.1 Marketing / Marketing - OIC / CEO can open, open edit, add, rename and delete | `test_S_105_1_roles_that_see_ad_payment_can_open_add_rename_and_delete` (data set per role) | pass ×3 |
| S-105.2 Data Encoder gets 404 on all four routes, and no row changes | `test_S_105_2_a_role_without_ad_payment_gets_404_on_all_four_routes_and_nothing_changes` | pass |
| S-105.3 Turning Ad Payment on for Data Encoder opens Ad Accounts; turning it off for Marketing closes it | `test_S_105_3_flipping_ad_payment_visibility_flips_ad_accounts_access` | pass |
| S-105.4 The "Ad Account ID" button shows for Marketing and is hidden for Data Encoder | `test_S_105_4_the_ad_account_id_button_shows_only_to_roles_that_pass` | pass |

Red run before the fix: S-105.2 got `open 200, edit 200, add 302, rename 302, delete 302` instead of 404; S-105.3 `Expected response status code [404] but received 200`; S-105.4 found "Ad Account ID" on the Data Encoder's page. S-105.1 passed before and after; it pins that the allowed roles keep working.

## Test runs

- `vendor/bin/phpunit tests/Feature/AdAccount`: OK (19 tests, 99 assertions).
- `vendor/bin/phpunit tests/Feature/AdAccount tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: Tests: 855, Assertions: 5636, 0 failures, 0 errors, Skipped: 1 (PHPUnit deprecations: 50).
- Full suite: Tests: 2219, Assertions: 22256, Errors: 1, Failures: 2, Skipped: 4. The red tests are all outside this change, and they fail the same way with this branch's two source files set back to `main`:
  - `Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (error)
  - `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (failure)
  - `Tests\Feature\NightRun\AstraAddressRulesTest::test_S_26_18_over_every_pair_of_a_name_and_its_one_word_longer_sibling_a_filler_word_never_confirms_the_longer_name` (failure; when this file runs alone on `main`, `test_S_26_23_…` fails too)
  - So this change adds no new failures.
- `php -l app/Http/Controllers/AdAccountController.php`: no syntax errors.

## Story changes

None. The spec brought its cases in the done-when list; no `qa/stories.md` section was added (the brief asked for a small task, and the cases are in the table above). Open to adding S-105 to `qa/stories.md` if the reviewer wants it there.

## Test fixture change

`tests/Feature/AdAccount/AdAccountHistoryTest.php` (spec 103): its `staff()` users had no role, so the new gate refused them. They now get role `Marketing`, and the nav-links migration is added to the test's migrations. No assertion changed; all spec 103 tests pass.

## Notes and suggestions for the owner

- `/ads_manager/payment/upload` itself (`PaymentActivityController`) has no role check; anyone logged in on an allowed IP can open it, with or without the Ad Payment link. Out of scope here; a candidate for its own spec using the same `NavAccess::allows` call.
- The worktree had no `vendor/`; it was installed with `composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` (lock file unchanged).
- Not done: no reviewer pass (skeptic-reviewer) and no browser check; nothing pushed, no PR.
