# 272: Botcake Accounts — result

## Summary
New page `/chatbot/botcake-accounts` ("Botcake Accounts"). A person types a label and pastes their Botcake account
Access Token (password input). Likha calls `POST /integration_page/list_access_token` (no id, empty body) and
`GET /pages` (every cursor) with the token in the `user-access-token` header; only when both answer properly is
anything saved. The list shows each account's label, who added it and when, page count, last check (OK / Failed with
time and reason, failed rows red) and the buttons View pages, Update tokens in Likha and Remove (with a confirm).
View pages lists every Botcake page (name, page id, role) with the Likha status computed on the server, sorted
differs / no token first, then matches, then not in Likha, under a count line such as
"51 pages: 1 in Likha (1 match), 50 not in Likha". Update tokens in Likha writes the Botcake page token into
`botcake.token` (through `PageSettings::set`: encrypted, history kept, acting user, source
`botcake account: <label>`) only for pages already in Likha whose token differs or is missing. A daily command
`botcake:accounts-check` (05:10 Manila) re-checks every account with `list_access_token` and records OK/failed,
reason and page count; it writes no page token. Access is by the page's own nav link through `NavAccess` (CEO always),
403 on the page and on every action for everyone else.

## Storage choice
`page_settings` rows, no new table: page `_all` (`ChatblastSetupCheck::ALL_PAGE`, the same reserved page the AI key
pool uses), provider `botcake_accounts`, three keys per account `a<8 hex>`:
- `a<id>.token` — the Access Token, `is_secret` (Crypt-encrypted like other page_settings secrets);
- `a<id>.info` — JSON: label, Botcake `user_id` from the JWT payload, HMAC hash of the token, added/updated by and at;
- `a<id>.check` — JSON: ok, at, reason, pages.
Every write goes through `PageSettings::set`, so a new value closes the old row (history with who/when). The same
Botcake `user_id` (or, for a token without one, the same token hash) reuses the account's id: the token is replaced
and the screen says "Updated". Remove closes every active row of the account (history kept, `replaced_by` set) and
nulls the encrypted value of every `a<id>.token` row, history included, so the secret is gone, not only hidden —
the same rule as the AI key pool's delete.

## Files changed
- `app/Services/BotcakeAccounts.php` (new): Botcake calls (no retry, connect 10 s / total 30 s), storage, page list
  and statuses, Update tokens, Remove, daily check, `redact()` for JWT-looking strings.
- `app/Http/Controllers/BotcakeAccountController.php` (new): the page and its actions, `NavAccess` on each.
- `app/Console/Commands/BotcakeAccountsCheck.php` (new): `botcake:accounts-check`.
- `resources/views/chatbot/botcake-accounts.blade.php` (new): form, folded "How to get your Access Token", list, pages.
- `database/migrations/2026_11_26_100000_add_botcake_accounts_nav_link.php` (new): nav link next to the Chatbot link.
- `routes/web.php`: five routes in the `/chatbot` group (web, auth, allowed_ip); add / view pages / update tokens
  share a 10-a-minute per-user throttle, Remove 30.
- `routes/console.php`: the daily schedule.
- `bootstrap/app.php`: `access_token` added to `dontFlash`, so no exception path flashes the token into the session.
- `app/Http/Controllers/PageSettingController.php`: the generic page-settings Deactivate refuses `botcake_accounts`
  rows (it would close a token row without wiping it), like it already does for the AI key pool.
- `tests/Feature/Chatblast/BotcakeAccountsTest.php` (new): 25 tests.

## Tests and evidence
New tests (`php artisan test tests/Feature/Chatblast/BotcakeAccountsTest.php`):

```
  Tests:    25 passed (243 assertions)
  Duration: 2.89s
```

Covered: add OK (encrypted, label, added by/at, Botcake user id, page count, check OK; the exact calls: POST
list_access_token with no id and an empty body, GET /pages following the cursor, the token only in the header);
refused (401 with Botcake's message, 200 `success:false`, 403 on /pages: nothing saved, red box, label kept, token
not); network error, 429 and 5xx shown as such, nothing saved, no retry; same Botcake user replaces (one account,
"Updated", old token is history); bad input refused before any call; the token in no response body, session or log
line, also when Botcake echoes it in an error (shown and logged as `[redacted]`); `access_token` in `dontFlash`;
pages list with match / differs / no token in Likha / not in Likha, order and count lines, no page token in the HTML,
Botcake page names escaped; Update tokens writes exactly the differing and missing pages already in Likha (encrypted,
user, source), leaves every other row and page alone, "Nothing to update" the second time; a Botcake failure writes
nothing; Remove (rows closed as history, token value nulled, other account untouched, 404 afterwards); the generic
Deactivate leaves account rows alone; nav link migration (label, URL, pattern, next to Chatbot, CEO only, idempotent,
down); 403 for a role without the link (no row and an unchecked row) on the page and all four actions, with nothing
sent to Botcake; 200 when checked; CEO always; guests to login; the daily command (OK with new page count, failed with
reason, no page token written, only list_access_token called, failed row red); the schedule (`10 5 * * *`,
Asia/Manila); the folded help.

Full suite (`php artisan test`, once):

```
  Tests:    3 failed, 6 skipped, 5146 passed (81331 assertions)
  Duration: 1189.27s
Failed: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest (the three known pre-existing failures).
```

Done-when grep `git grep -nP "eyJ[A-Za-z0-9_-]{10,}" -- app resources routes database tests ':!tests/**/fixtures/**'`:
no output (exit 1). The tests build their JWT-shaped tokens at run time from JSON parts with a fake signature.

`vendor/bin/pint` was run on the five new PHP files. The four existing files touched here (PageSettingController,
routes/web.php, routes/console.php, bootstrap/app.php) already fail pint's rules as a whole on main; formatting them
would rewrite hundreds of unrelated lines, so only the added lines follow the surrounding style.

## Story changes
- S4: a Likha page whose Botcake page is in the account but for which `list_access_token` returned no token gets its
  own status "Botcake gave no token for this page" (sorted after differs/no token, counted separately); Update tokens
  skips it. A Botcake page id used by two Likha pages shows one row per Likha page.
- S3/S4: when View pages or Update tokens finds the token refused by Botcake, the account's last check is recorded as
  failed too (so the list turns red at once); a success there does not rewrite the check (the daily command and Save do).
- S6: no "Botcake Accounts" tab was added to the Chatbot top bar; the page is reached from the main nav link (the
  migration) or the URL. Adding a tab changes the tab list that three existing Chatbot tests pin down.
- Page count = the number of rows `list_access_token` returns (the same number on Save and in the daily check).

## Open points
- The nav link starts checked for the CEO only. The marketing roles must be checked in /owner/nav-settings before
  they can open the page (deliberately not pre-checked: the page stores tokens that can edit and broadcast on every
  page of an account, and the spec asks for no role names in code).
- Not verified against the live Botcake API (fakes only, as the spec requires); the shapes follow the spec's facts.
- Test environment: `.env.example` sets `CHATBOT_QUEUE_CONNECTION=database` and `CHATBLAST_QUEUE_CONNECTION=database`.
  A local `.env` copied from it makes about 266 Chatblast/Chatbot tests fail ("no such table: jobs"), because those
  keys override phpunit's `QUEUE_CONNECTION=sync`. The suite above ran with those two lines commented out in the
  untracked local `.env`; nothing about it is committed. phpunit.xml could pin both keys to `sync` (not done here).
