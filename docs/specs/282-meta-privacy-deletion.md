# 282 Public privacy policy and Meta data deletion callback

## Why
The site's Meta Ads app ("Connect Facebook" on /ads_manager/report; code in `app/Services/MetaAds/` — `Connections` stores one row per user in `app_settings`, key `meta_ads.connection.<user id>`, JSON with `fb_user_id`, `fb_user_name`, encrypted token, `ad_accounts`; `AppConfig` resolves app id / secret / config id) is going to Meta App Review so staff without an app role can connect. Meta requires a public Privacy Policy URL and a Data Deletion Callback URL on the app. Neither exists in this code.

## Decisions already made (do not reopen)
| Decision | Reason |
|---|---|
| `GET /privacy` is public (no login), plain page in the site's existing public style, describing only the Facebook connection: what is read (Facebook name and app-scoped ID, the list of ad accounts the person can access, ads reports/insights of those accounts, an access token stored encrypted), why (internal ads reporting), who sees it (the site's staff), retention (until the person disconnects or asks for deletion; reports already pulled stay as company records with no personal data), how to delete (Disconnect button, removing the app in Facebook settings, or emailing the contact), contact email, company name | Meta checks the URL loads and describes the data |
| Company name and contact email come from two new optional fields in the 281 "Meta app" settings block (CEO only): `meta_ads.app.company_name`, `meta_ads.app.contact_email` (validated email). The page falls back to `config('app.name')` and omits the email line when empty | Each site has its own company and email |
| `POST /meta/data-deletion` is public and CSRF-exempt. It parses Meta's `signed_request` (base64url payload + HMAC-SHA256 signature with the app secret from `AppConfig`, algorithm HMAC-SHA256; reject anything else with 400 and no detail). For a valid request it deletes every connection whose `fb_user_id` equals the payload `user_id` (use `Connections::disconnect`), records a deletion entry (confirmation code, time, count; no Facebook ID stored in clear — store a sha256 of it), and answers JSON `{"url": APP_URL + "/meta/data-deletion/<code>", "confirmation_code": "<code>"}` | Meta's documented callback contract |
| `GET /meta/data-deletion/{code}` is public and shows only the status ("Your Facebook data was deleted on <date>" or "Not found"), no personal data | Meta's status URL |
| The 281 settings block also shows the Privacy Policy URL and Data Deletion Callback URL with copy buttons | The team pastes them into the Meta app |

## Stories
1. Anyone (logged out) opens /privacy and sees the policy with the saved company name and contact email.
2. A CEO saves company name and contact email in the Meta app block; invalid email is refused; others get 403/404 as for the other 281 fields.
3. A valid signed_request deletes the matching connection(s), returns the JSON with url and code, and the status page shows deleted.
4. A bad signature, wrong algorithm, malformed body or missing app secret answers 400 and deletes nothing.
5. A valid request for a Facebook user with no connection still answers 200 with a code (status page says deleted / nothing stored).
6. The token, the app secret and the Facebook ID never appear in any response, page, or log line.
7. The Meta app block shows both URLs with copy buttons.

## Constraints
- A feature test per story that fails before and passes after; the whole suite adds no new failures (state base and branch counts).
- Testing: a `vendor/` junction to another worktree makes phpunit load THAT worktree's classes. Run phpunit with `--bootstrap "C:/Users/Forbeast/AppData/Local/Temp/claude/2e760ef270dbe960/a4a4c79e-cc36-40fa-9909-6277e4ca57f2/scratchpad/autoload-local.php"` from this worktree's root, and if `vendor/` is missing create the junction first: `cmd //c mklink /J vendor ..\\likha-wt277l\\vendor` (do not commit it). PHP: `C:/Users/Forbeast/.config/herd/bin/php84/php.exe`.
- No migration if `app_settings` fits (it should). Never read any `.env` file.
- Comments follow the file's style (Taglish is fine). Commits and code never mention who asked or any assistant; no Co-Authored-By lines.
- Never deploy, never touch a server, never push to main. When done: `git push origin feat/282-meta-privacy-deletion`.
- Budget: medium job, at most 2 attempts per step. Stop and write why under Questions if it needs much more.

## Report back
Write and commit `docs/specs/282-meta-privacy-deletion.result.md`: changes per story, routes, app_settings keys, test output (red then green), full-suite counts, head commit. Final message: head commit sha and a 3-line summary.
