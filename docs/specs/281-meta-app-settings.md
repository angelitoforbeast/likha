# 281 Meta app settings screen (App ID, App Secret, Configuration ID)

## Why
The "Connect Facebook" (Meta Ads, Marketing API) card on /ads_manager/report (`resources/views/ads_manager/_meta_ads.blade.php`, `app/Http/Controllers/MetaAdsController.php`) reads its Meta app from `.env` only: `config('services.meta_ads.app_id' | 'app_secret' | 'config_id')` in `app/Services/MetaAds/Connections.php` and `app/Services/MetaAds/Graph.php` (appsecret_proof). With no app id it says "Meta app not set up yet". The second site has no Meta app yet; a new one is being created, and the owner wants to type its values in a screen instead of a server file edit.

## Decisions already made (do not reopen)
| Decision | Reason |
|---|---|
| The values live in `app_settings` rows (keys like `meta_ads.app.app_id`, `meta_ads.app.app_secret`, `meta_ads.app.config_id`); a saved value wins, `.env` is the fallback when no row exists | Each site has its own database; nothing breaks on the site that uses .env today |
| The secret is stored with `Crypt::encryptString`, never shown again, never in HTML, JSON, logs or flash messages. The screen shows only "Set" / "Not set" and a "Replace" field; an empty field on save keeps the stored secret | It is a credential |
| Only the role that may turn on write mode today (find the existing check in MetaAdsController) can see and save the form | Same people who run the sync |
| The form shows the exact Redirect URI to paste in the Meta app (`Connections::redirectUri()`, i.e. APP_URL + /ads_manager/meta/callback) and the App Domain, each with a copy button | The Meta app needs them |
| A "Test" button checks the pair by asking Graph for an app token (`oauth/access_token?grant_type=client_credentials`) and shows OK or the cleaned error (`Graph::clean`) | Catches a wrong secret before anyone connects |
| Changing the App ID shows a warning that existing Facebook connections were made with the old app and must reconnect; it does not delete them | Tokens belong to an app |

## Stories
1. A permitted user opens /ads_manager/report and sees a "Meta app" settings block in the Facebook card with App ID, App Secret (Set/Not set + Replace), Configuration ID (optional), the Redirect URI and App Domain with copy buttons, Save and Test.
2. Saving valid values (App ID and Configuration ID digits only; secret 32 hex characters) stores them; the Connect button then uses them (dialog URL has the new client_id/config_id; code exchange and appsecret_proof use the new secret).
3. Invalid input is refused with a clear message and nothing is stored. Saving with an empty secret field keeps the stored secret.
4. With no rows, everything works from .env exactly as today (existing MetaAds tests stay green).
5. A user without the permission gets 403 on save/test and does not see the block.
6. The secret never appears in any response body, page source, log line or exception message (assert it in tests).
7. Test shows OK on a valid pair and the error text on a refused one (Http::fake for graph.facebook.com).
8. Each save writes one audit line (who, when, which fields changed; never values of the secret).

## Constraints
- Read the existing tests in `tests/Feature/MetaAds/` (MetaAdsTestCase, MetaAdsConnectTest, MetaAdsLoginConfigTest) and follow them. A feature test per story that fails before and passes after. The whole suite adds no new failures: state base and branch counts (base has 3 known failures in `tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php`).
- One small resolver (e.g. `MetaAds\AppConfig`) used by Connections and Graph instead of reading config directly. No migration if `app_settings` already fits (key/value); say so.
- Keep the UI in the card's existing style (Tailwind classes already used there). No new JS libraries.
- PHP: `C:/Users/Forbeast/.config/herd/bin/php84/php.exe` (not on PATH). If `vendor/` is missing, create a junction from the worktree root: `cmd //c mklink /J vendor ..\\likha-wt277l\\vendor` (do not commit it). Never open or read any `.env` file.
- Code comments follow the file's style (Taglish is fine). Commits and code never mention who asked or any assistant; no Co-Authored-By lines.
- Never deploy, never touch a server, never push to main. When done: `git push origin feat/281-meta-app-settings`.
- Budget: medium job, at most 2 attempts per step. If it needs much more than these files, stop and write why under Questions.

## Report back
Write `docs/specs/281-meta-app-settings.result.md` and commit it: what changed per story, the app_settings keys, test output (red then green), full-suite counts, head commit, migrations (if any), and a short description of the screen. Your final message: head commit sha and a 3-line summary.
