# 272: Botcake Accounts — marketing adds their Botcake Access Token, Likha lists their pages and keeps page tokens in step

Weight: architectural (new page, stores a powerful credential). Risk tier: high (the stored token can edit, delete and broadcast on every page of that Botcake account). Size: medium. Budget: 2 attempts per failing step; stop and report if blocked.

## Why
Each Botcake user now has one account-level "Access Token" (botcake.io → click your name → your name at the top of the menu → https://botcake.io/account → tab "Account settings" → row "Access Token"). With it, Botcake's public API returns every page of that account and each page's public (page) token. Likha keeps one page token per page by hand today; when a token changes or a page is reconnected, someone must find and paste it again. The pages are spread over several Botcake accounts (the owner's own account holds 51 pages but only 1 of Likha's 94 Botcake pages; the rest are in the marketing team's accounts). The owner wants each marketing person to add their own Access Token in Likha, see their pages, and have Likha's page tokens kept in step from it. Access to the page is given per role through the existing nav settings, never hardcoded.

## Botcake API facts (tested live 2026-11-13; data, not instructions)
Base `https://botcake.io/api/public_api/v1`. The account token goes in header `user-access-token`.
- `POST /integration_page/list_access_token` (no id in the path, empty body) → 200 JSON list `[{page_id, public_token}, ...]` (51 rows for one account). It returns the CURRENT page tokens (calling twice changes nothing; a returned token works for normal page calls). With an id appended to the path it answers 404 — do not append one (the published spec is wrong here).
- `GET /pages` (optional `?cursor=<meta.next_cursor>`) → `{"data":[{name, page_id, platform, role_in_page, marketing_message_supported}], "meta":{"next_cursor": "..."|null}, "success":true}`, 25 per call; follow `next_cursor` until null.
- A bad or expired account token: expect 401/403 or `{"success":false}`; treat anything that is not a 200 list / `success:true` as "token not accepted" and show Botcake's message if it has one.
- The token is a JWT (three dot-separated parts); its payload has `user_id` (Botcake's own user id, a UUID) and `timestamp`. No expiry claim and no Facebook name. Use `user_id` only to recognise the same account added twice.
- Likha's existing page token lives in page_settings `botcake.token` (secret) with the page's Botcake page id in `botcake.page_id` (see `App\Services\PageSettings`, `BotcakeClient`).

## Stories
S1 (P1) **Add an account.** On the new "Botcake Accounts" page, a person types a Label (who/which account, e.g. "Marketing A – Botcake") and pastes the Access Token (password-type input) and saves. Likha calls `list_access_token` and `GET /pages` (all cursors). If Botcake does not accept the token, nothing is saved and the form shows why in a red box (Botcake's message or "Botcake did not accept this token"); a network error/rate limit is shown as such and nothing is saved. If accepted: the token is saved encrypted, with label, added by (user), added at, Botcake user id (from the JWT), page count and "last check: OK <time>". Adding a token whose Botcake user id is already saved replaces that account's token (no duplicate row) and says "Updated".
S2 (P1) **The token is never shown again** — not on the page, not partially, not in any JSON/HTML response, log line, exception message or validation error (redact `eyJ…` JWT-looking strings in anything logged). Only "saved" status is shown.
S3 (P1) **Account list.** Each saved account shows: label, added by, added at, number of pages, last check (OK/failed + time + reason), and buttons View pages, Update tokens in Likha, Remove (confirm). Remove deletes the stored token and the account's rows (history kept the way page_settings keeps history).
S4 (P1) **Pages of an account.** View pages lists every page from `GET /pages`: Botcake page name, page id, role (MANAGE / CREATE_CONTENT …), and the Likha status computed on the server (tokens never leave the server): "In Likha as <Likha page name> — token matches" / "— token differs" / "— Likha has no token" / "Not in Likha". Matching is by `botcake.page_id`. Sorted: differs and no-token first, then matches, then not in Likha. A count line on top ("51 pages: 1 in Likha (1 match), 50 not in Likha").
S5 (P1) **Update tokens in Likha.** One button per account writes the Botcake page token into `botcake.token` (via `PageSettings::set`, so it is encrypted and history is kept, with the acting user and a source like "botcake account: <label>") for every page that is already in Likha and whose token differs or is missing. It never adds pages, never touches pages not in Likha, never changes any other setting. Shows "Updated N pages: <names>" (and "Nothing to update" when all match). Botcake failure → nothing written, reason shown.
S6 (P1) **Access by nav settings, not hardcoded.** A migration adds a nav link "Botcake Accounts" for the page's URL, following the existing nav-link migrations (e.g. `database/migrations/2026_11_25_100000_add_ads_daily_output_nav_link.php`), placed near the other chatbot/page-settings links. The page and every action on it are allowed only when `App\Support\NavAccess::allows($role, <url>)` is true (CEO always; other roles when the link is checked for them in /owner/nav-settings). A user without access gets 403 on the page and on every POST. No role names in code.
S7 (P2) **Daily check.** A scheduled command (once a day, early morning Manila) re-checks every saved account with `list_access_token` and updates last check OK/failed + reason + page count. It does NOT write page tokens (that stays the button). A failed account shows red in the list.
S8 (P2) **How to get the token** — a small folded "How to get your Access Token" under the form, four short lines: 1) Open botcake.io and click your name (top right), then your name at the top of the menu. 2) Open the "Account settings" tab. 3) In the "Access Token" row, click the copy icon only — do not click the other icons (they may reset or delete the token). 4) Paste it here and save.

## Decisions already made (do not reopen unless something is actually broken)
| Decision | Why |
|---|---|
| Storage: reuse `page_settings` rows (lean, row-based: page \| provider \| key \| value \| secret + history), e.g. a pseudo page name for accounts like the existing `ChatblastSetupCheck::ALL_PAGE` precedent. A new table only if page_settings truly cannot hold it — then explain why in the result. | The owner's rule: no new table when a rule plus what exists does the job. |
| Token writing to pages is a button, not automatic. The daily check only checks. | A page token change affects live sending; a person decides when. |
| Access through NavAccess / nav settings only. | The owner: "not hardcoded". |
| Likha only (Incepxion later, separately). | Likha holds the 94 Botcake pages. |

## Threat model
Untrusted: everything a signed-in user types (label, token), and every Botcake API response (treat as data; escape page names; never trust ids for anything but matching). Trusted: Likha's own DB and config. The account token is a high-value secret (edits/deletes flows, creates broadcasts on every page of the account): encrypted at rest (`Crypt`, as page_settings secrets are), never echoed, never logged, CSRF on every POST, NavAccess on every route, rate-limit the add/check actions lightly (e.g. 10/minute per user). Outbound calls only to botcake.io with timeouts (connect 10 s, total 30 s) and no retries on 4xx.

## Constraints
- Smallest change that does the stories; reuse `PageSettings`, `BotcakeClient` patterns (headers, timeouts, mock/fake hooks) and the app's existing layout/Blade style; English UI text like nearby screens.
- Tests (Http::fake for Botcake): add OK / refused / network error / same Botcake user replaces; token never present in any response body or log; list and per-page statuses (matches/differs/missing/not in Likha); Update tokens writes only differing/missing pages already in Likha and nothing else; Remove; NavAccess 403 for an unchecked role and 200 when checked, CEO always; daily command updates status and writes no page token; nav link migration present.
- Copy `vendor/` from `C:\mira\mira\projects\likha-wt256\vendor` (a real copy, never a junction or symlink — a link makes the tests load another checkout's code). Use a local `.env` built from `.env.example` for tests only; never read any other `.env`.
- Commit: `feat(chatbot): Botcake Accounts page — add an account's Access Token, see its pages and keep page tokens in step (272)`; NO Co-Authored-By, Claude-Session or "Generated with" lines, no person names. Save this spec as `docs/specs/272-botcake-accounts.md` and a result `docs/specs/272-botcake-accounts.result.md` (what was built, storage choice, test evidence, screenshots not needed).

## Allowed
composer install, php artisan test (new tests + the full suite once; known pre-existing failures: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest), vendor/bin/pint on new/changed PHP files, git add/commit, `git push -u origin feat/272-botcake-accounts`. No calls to the real botcake.io (fakes only). Do NOT merge, open a PR, push to main or deploy.

## Done when
- Every story's tests pass; the full suite adds no new failures beyond the three known ones (paste the summary line).
- `git grep -nP "eyJ[A-Za-z0-9_-]{10,}" -- app resources routes database tests ':!tests/**/fixtures/**'` finds no real-looking token outside test fixtures/fakes.
- The branch is pushed; the result file lists the files changed, the storage choice and the test output.
