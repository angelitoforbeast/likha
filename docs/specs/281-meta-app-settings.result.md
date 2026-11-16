# 281 Meta app settings: result

Branch `feat/281-meta-app-settings`, base `16dade83`. Commits: `96996914` spec, `8b4f6b87` code and tests, then
this result file (the head commit; `git log -1 feat/281-meta-app-settings`).

## The screen
In the "Facebook (Meta Ads API)" card on /ads_manager/report, the CEO sees a collapsible block
**Meta app (CEO): App ID …** (open by default when no Meta app is set, after an error or after an App ID change):

- Redirect URI (`APP_URL/ads_manager/meta/callback`) and App Domain (host of APP_URL), each with a **Copy** button
  (plain `navigator.clipboard`, no library).
- **App ID** (pre-filled), **App Secret**: a green "Set" or red "Not set" badge and an empty password field
  "Replace: 32 hex characters" (blank keeps the stored secret), **Configuration ID (optional)** (pre-filled).
  Under each field: where the value comes from ("saved here", "from the server file (.env)" or "none").
- **Save Meta app** and **Test** (tests the saved App ID + App Secret). Errors show in a red box inside the block;
  an App ID change with existing connections shows a yellow warning; OK / saved messages use the page's status line.
- A yellow note when connections exist: changing the App ID means those people must Connect again (rows are kept).

Same Tailwind classes as the rest of the card (border-t pt-3 details, gray-800 Save, indigo Test, red/yellow boxes).

## app_settings keys (no migration)
`app_settings` (id, key unique, value text, timestamps) already fits, so there is **no migration**.

| Key | Value |
|---|---|
| `meta_ads.app.app_id` | digits |
| `meta_ads.app.app_secret` | `Crypt::encryptString(secret)` |
| `meta_ads.app.config_id` | digits, or `''` = saved as "no configuration" (turns off a .env one) |

A row wins; with no row the value comes from `config('services.meta_ads.*')` (.env) as before. Save writes only the
fields that differ from the current value, so saving the form unchanged on the site that runs on .env writes no row.

## What changed per story
1. `MetaAdsController::panel()` adds `app` (CEO only: app_id, config_id, secret_set bool, sources, redirect_uri,
   app_domain, connection count; never the secret). `_meta_ads.blade.php` renders the block described above.
2. New resolver `app/Services/MetaAds/AppConfig.php` (appId / appSecret / configId / source / save).
   `Connections::appId()/configId()` and the code exchange + debug_token app token, and `Graph::get()`'s
   appsecret_proof, read it instead of `config(...)`. `Connections::appDomain()` added.
   Routes: `POST /ads_manager/meta/app` (`ads_manager.meta.app`) and `POST /ads_manager/meta/app/test`
   (`ads_manager.meta.app.test`).
3. Validation in `saveApp`: App ID required, digits only; App Secret empty or exactly 32 hex characters;
   Configuration ID empty or digits only; an empty secret with no secret anywhere is refused
   ("App Secret is required (none is saved yet)"). Refused input stores nothing and writes no audit line.
   Empty secret field keeps the stored one.
4. No rows: every value comes from .env, unchanged behaviour; all 204 existing MetaAds tests stay green.
5. Same check as write mode: `checkAccess()` (404 without the Ads link, like every Meta route) then role `CEO`
   (403 otherwise). Marketing / Marketing - OIC get 403 on save and test and do not see the block.
6. The secret is never rendered (password field is always `value=""`), never in old input (`app_secret` added to
   `dontFlash` in `bootstrap/app.php`, and `saveApp` re-flashes only app_id and config_id), never logged, never in the
   audit line. Test errors pass through `Graph::clean` and the raw secret is also replaced with `[hidden]`.
7. `testApp` asks Graph `oauth/access_token` with `grant_type=client_credentials` (one try, 20 s timeout, token not
   kept): "Meta app test: OK (Facebook accepted App ID …)" or "Meta app test failed: Graph error N: …".
8. Each save writes one `upload_logs` row (type `ads_manager_api_event`, `user_id`, created_at):
   `meta app saved: app_id, app_secret` (or `no change`); `protected_details` = `changed` list and the before/after
   of App ID and Configuration ID only. It also shows in the card's "API runs and changes" list.
   Decision 6: when the App ID changes and connections exist, a warning says the N connections must reconnect; they
   are not deleted.

## Tests
New file `tests/Feature/MetaAds/MetaAdsAppSettingsTest.php`, 15 test methods / 19 cases (S-281.1 to S-281.8).

Red (base `16dade83` + the new test file): `Tests: 19, Assertions: 22, Errors: 2, Failures: 16` — 18 of 19 fail.
The one that passes on base is `test_S_281_5_a_role_without_the_ads_link_gets_404_like_the_rest_of_the_card`
(a guard: without the routes the answer is also 404).

Green (branch): `OK (19 tests, 136 assertions)`. Whole `tests/Feature/MetaAds`: `OK (223 tests, 1010 assertions)`.

Full suite:
- base `16dade83`: `Tests: 5273, Assertions: 82872, Failures: 3, PHPUnit Deprecations: 106, Skipped: 6`
- branch: `Tests: 5292, Assertions: 83008, Failures: 3, PHPUnit Deprecations: 106, Skipped: 6` (+19 = the new cases)
- The same 3 fail on both: `Feature\ExampleTest::test_the_application_returns_a_successful_response`,
  `Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`,
  `Feature\OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_the_shared_owner_pages_render_byte_for_byte_as_at_the_base`.
  This differs from the "3 in SharedPagesBasePinTest" in the spec, which was probably counted with the vendor
  junction problem below (the app under test was likha-wt277l's). No new failures.

## How the tests were run (important)
`vendor/` is a junction to `../likha-wt277l/vendor`. Composer resolves it to its real folder, so a plain
`vendor/bin/phpunit` in this worktree loads `App\` and `Tests\` classes from **likha-wt277l** (optimized classmap),
and Laravel's test case finds `bootstrap/app.php` through the vendor dir (`Application::inferBasePath`), so routes,
views and config also came from likha-wt277l. The first red run here showed it ("Class AppConfig not found", the
new routes 404). All runs above used a bootstrap kept outside the repo that sets `$_ENV['APP_BASE_PATH']` to the
current folder and prepends an autoloader for `App\`, `Tests\`, `Database\` from the current folder, then
`require vendor/autoload.php`. The base suite ran on a `git archive 16dade83` copy with the same bootstrap.
Earlier results from worktrees that use this junction may have tested likha-wt277l's code, not their own.

## Migrations
None.

## Questions
None.
