# Result 159: Connect Facebook uses the Meta app's Login for Business configuration

## What changed
- `config/services.php`: `meta_ads.config_id` from `META_ADS_CONFIG_ID`. `.env.example` documents it (set on the server to `995680132834288`; APP_ID and APP_SECRET are still needed).
- `app/Services/MetaAds/Connections.php`:
  - `configId()` (trimmed, null when blank).
  - `dialogUrl()` sends `config_id` instead of `scope` when a config id is set; without one the URL is unchanged (same parameters, same order).
  - `connect()`: when a config id is set and the `fb_exchange_token` call fails, the code-exchanged token is kept and its expiry comes from `debug_token`. Without a config id a failed exchange still fails the connect, as before.
  - Every connection row records `token_from` (`fb_exchange_token` or `code`), which is not secret.
- Decision 3 needed no code change. The card and Connect already treat "app id present" as set up, which covers app id + config id. A test pins that Connect is offered with app id + config id and no secret.
- `tests/Feature/MetaAds/MetaAdsLoginConfigTest.php` (new), `qa/stories.md` (S-159), `TODO.md` (accepted minors).

## Case table
| Case | Test | Result |
|---|---|---|
| S-159.1 | `test_S_159_1_with_a_config_id_the_dialog_sends_config_id_and_no_scope` | pass |
| S-159.2 | `test_S_159_2_without_a_config_id_the_dialog_is_as_before` (unset, blank) + all S-154 tests unchanged | pass |
| S-159.3 | `test_S_159_3_callback_keeps_a_token_whether_the_long_lived_exchange_works_or_fails` (works / fails) | pass |
| S-159.3 negative | `test_S_159_3_without_a_config_id_a_failed_long_lived_exchange_still_fails_the_connect` | pass |
| S-159.3 edge (decision 3) | `test_S_159_3_with_a_config_id_and_no_app_secret_the_card_still_offers_connect` | pass |
| S-159.4 | covered inside the S-159.3 table test (log, callback response, page, upload_logs, unencrypted row; token, its last 4, app secret) | pass |

Red run (before the code): `test_S_159_1_…`: "Failed asserting that two arrays are identical." (scope still present). `test_S_159_3_… "long-lived exchange works"`: `Undefined array key "token_from"`. `test_S_159_3_… "long-lived exchange fails"`: the connection is null (connect failed). The no-config negative and the decision-3 edge passed from the start, because they pin existing behaviour.

## Evidence
- `php vendor\phpunit\phpunit\phpunit tests/Feature/MetaAds`: `OK (41 tests, 266 assertions)`.
- `php vendor\phpunit\phpunit\phpunit tests/Feature/MetaAds tests/Feature/Chatblast`: 1052 tests, 1 failure: `ForbeastStandbyHiddenTest::test_S_117_15_a_standby_twin_is_not_counted_in_results_nor_the_chat_stats`. The same test fails on 816c5baa (baseline: 1045 tests, the same 1 failure), so there are no new failures.
- `php -l app/Services/MetaAds/Connections.php`: no syntax errors.
- Review: skeptic-reviewer at standard depth found no blockers or majors. Two minors are accepted in `TODO.md`: the fallback fires on any exchange error, and there is no test for `expires_at=0`. The reviewer could not see the red run, which is listed above.
- No call to Meta was made; every Graph call is faked.

## Story changes
- S-159 was added from the spec's cases. S-159.3 gained a negative (without a config id, a failed exchange still fails) and an edge (decision 3, no secret). Reason: they pin the unchanged fallback behaviour and the set-up check.

## Commits
- `4327b027` docs: spec 159 and its result template
- `b1a201d8` feat: config_id in Connect, code-token fallback, `token_from`
- (this commit) docs: result, accepted minors

## Deploy note
Set `META_ADS_CONFIG_ID=995680132834288` in the server's environment, then run `php artisan config:clear` (or the cache command used). No migration.

159: done — Connect sends the Login for Business config_id when it is set (scope=ads_read otherwise) and keeps the code token when the long-lived exchange fails; MetaAds is green and there are no new failures vs 816c5baa.
