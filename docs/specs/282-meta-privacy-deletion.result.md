# 282 Public privacy policy and Meta data deletion callback: result

Branch `feat/282-meta-privacy-deletion`, base `c7233e7c`. Commits: `ad3d070e` spec, `47d51022` code and tests, then
this result file (the head commit; `git log -1 feat/282-meta-privacy-deletion`).

## Routes
| Method | Path | Name | Access |
|---|---|---|---|
| GET | `/privacy` | `privacy` | public, no login |
| POST | `/meta/data-deletion` | `meta.data_deletion` | public, CSRF-exempt (`bootstrap/app.php` `validateCsrfTokens(except: ['meta/data-deletion'])`), `throttle:60,1` |
| GET | `/meta/data-deletion/{code}` | `meta.data_deletion.status` | public, `code` = `[A-Za-z0-9]{1,64}` |

All three are in `app/Http/Controllers/MetaPrivacyController.php`. The existing `POST /ads_manager/meta/app` (281) takes
the two new fields.

## app_settings keys (no migration)
| Key | Value |
|---|---|
| `meta_ads.app.company_name` | text, max 150; `''` or no row = use `config('app.name')` |
| `meta_ads.app.contact_email` | validated email, max 150; `''` or no row = no email line |
| `meta_ads.deletion.<code>` | JSON `{deleted_at (ISO 8601), count, fb_user_sha256}`; code = 20 lowercase hex chars (`random_bytes(10)`) |

No token, name or Facebook ID in clear in the deletion row. Each deletion also writes one `upload_logs` line
(type `ads_manager_api_event`, user "system"): `meta data deletion: N connection(s) removed (code X)`, so staff see in the
card's "API runs and changes" why a connection disappeared.

## What changed per story
1. `resources/views/meta/privacy.blade.php`: plain Tailwind page like the login page (same gradient + white card). It covers
   what is read (Facebook name and app-scoped ID, the ad accounts the person can access, ads reports/insights, an access
   token stored encrypted), why (internal ads reporting only, not sold or shared), who sees it (the company's staff),
   retention (until disconnect or deletion request; pulled reports stay as company records with no personal data), how to
   delete (Disconnect my Facebook, removing the app in Facebook settings, or email), company name and contact email.
2. `AppConfig::companyName()`, `contactEmail()`, `savePrivacy()` (writes only what changed). `saveApp` validates
   `company_name` (nullable, max 150) and `contact_email` (nullable, email, max 150; "Contact email: enter a valid email
   address"); a refused save stores nothing. The fields are saved only when they are in the request (the block always
   sends them), so 281's existing posts without them behave as before. Same access as the other 281 fields (404 without
   the Ads link, 403 for non-CEO). The audit line lists `company_name` / `contact_email` with before/after.
3. `app/Services/MetaAds/DataDeletion.php`: `userId()` parses `signed_request` (`<sig>.<payload>`, base64url, algorithm
   must be exactly `HMAC-SHA256`, `hash_equals` against `hash_hmac('sha256', payload, AppConfig::appSecret())`, `user_id`
   required); `delete()` calls `Connections::disconnect` for every connection whose `fb_user_id` matches, writes the
   deletion row and returns the code. Answer: `{"url": APP_URL/meta/data-deletion/<code>, "confirmation_code": <code>}`.
   Status page (`resources/views/meta/data-deletion.blade.php`): "Your Facebook data was deleted on <F j, Y>".
4. Bad signature, wrong/missing algorithm, tampered payload, no dot / three parts, non-base64, non-JSON payload, missing or
   empty `user_id`, empty or missing `signed_request`, and no app secret all answer `400 {"error":"invalid request"}`
   and delete nothing (no deletion row either). The secret used is the saved one when a row exists (281 rules).
5. A valid request with no matching connection still answers 200 with a code (count 0); the status page adds "Nothing was
   stored for this Facebook account." An unknown code shows "Not found" with HTTP 404.
6. Nothing logs; responses, the status page, /privacy and the report page never contain the token, the app secret, the
   Facebook ID or the Facebook name (test checks responses, pages, log lines, `upload_logs` and `app_settings`).
7. The 281 copy grid now has four items: Redirect URI, App Domain, **Privacy Policy URL** (`APP_URL/privacy`) and
   **Data Deletion Callback URL** (`APP_URL/meta/data-deletion`), each with the same Copy button. CEO only.

## Tests
New file `tests/Feature/MetaAds/MetaPrivacyDeletionTest.php`: 14 methods / 25 cases (S-282.1 to S-282.7), plus a CSRF
test that runs `ValidateCsrfToken` with the unit-test shortcut off (the callback passes, `/ads_manager/meta/app` throws
`TokenMismatchException`).

Red (base code, new test file): `Tests: 25, Assertions: 34, Errors: 3, Failures: 20` (23 of 25 fail). The 2 that pass on
base are guards: `test_S_282_2_other_roles_get_403_or_404_like_the_other_meta_app_fields` and
`test_S_282_7_other_roles_do_not_see_the_urls`.

Green (branch): `OK (25 tests, 175 assertions)`. Whole `tests/Feature/MetaAds`: `OK (248 tests, 1187 assertions)`
(223 before + 25).

Full suite:
- base `c7233e7c`: `Tests: 5292, Assertions: 83008, Failures: 3, PHPUnit Deprecations: 106, Skipped: 6`
- branch: `Tests: 5317, Assertions: 83185, Failures: 3, PHPUnit Deprecations: 106, Skipped: 6` (+25 = the new cases)
- The same 3 fail on both: `Feature\ExampleTest::test_the_application_returns_a_successful_response`,
  `Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`,
  `Feature\OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_..._byte_for_byte_as_at_the_base` (`/owner/private`).
  No new failures.

All runs used the local bootstrap (`--bootstrap .../scratchpad/autoload-local.php`) with `vendor/` as a junction to
`../likha-wt277l/vendor` (not committed). The base suite ran on a `git archive c7233e7c` copy with the same bootstrap.

## Notes for the Meta app
- Privacy Policy URL: `<APP_URL>/privacy`; Data Deletion Callback URL: `<APP_URL>/meta/data-deletion` (both on each site's
  own domain, copy from the block).
- Set the company name and contact email on each site before submitting for review.

## Migrations
None.

## Questions
None.
