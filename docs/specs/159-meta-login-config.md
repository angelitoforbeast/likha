# Brief 159: Connect Facebook uses the Meta app's Login for Business configuration

## Why
Spec 154's Connect button opens `https://www.facebook.com/<ver>/dialog/oauth` with `scope=ads_read` (`App\Services\MetaAds\Connections.php` ~line 41). The owner's Meta app (App ID 614139088366911, "Marketing Apps") can't have plain Facebook Login together with the Marketing API use case; it has "Facebook Login for Business" instead, with a configuration (config_id 995680132834288: General variation, **User access token**, permission `ads_read` only, no assets — the user picks ad accounts at login). Login for Business takes `config_id` instead of `scope`. The redirect URI `https://likhaaitech.com/ads_manager/meta/callback` is registered there.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | New config `services.meta_ads.config_id` from env `META_ADS_CONFIG_ID` (document in .env.example). When set, the dialog URL sends `config_id=<id>` and `response_type=code` (plus client_id, redirect_uri, state) and **no** `scope`; when empty, the current `scope=ads_read` URL is unchanged. | Works with the owner's app; old behaviour kept as fallback. |
| 2 | The callback stays the same (code → token exchange). Then try the long-lived exchange (`fb_exchange_token`) as today; if that call fails for a Login-for-Business token, keep the code-exchanged token and take its expiry from `debug_token` (don't fail the connect). Record which path was used in the connection row's non-secret metadata. | Login for Business user tokens may already be long-lived. |
| 3 | The connect page's "Meta app not set up yet" check also accepts app id + config id (secret still required for the exchange). | Clear setup state. |

## Cases (S-159)
P1: .1 with config_id set the redirect has config_id, response_type=code, no scope, state, redirect_uri from config; .2 without config_id the URL is exactly as before (154 tests still pass); .3 callback works when fb_exchange_token succeeds and when it fails (token kept, expiry from debug_token); .4 the token never appears in logs/responses (reuse 154's assertion helpers).

## Done when
P1 pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/MetaAds tests/Feature/Chatblast` adds no new failures vs 816c5baa. Result with files, evidence, commits; end with "159: <done|blocked> — <one line>".

## Out of scope
Anything else in Meta Ads; deploying.
