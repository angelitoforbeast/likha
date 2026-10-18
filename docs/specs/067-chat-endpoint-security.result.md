# Result 067: the Botcake chat endpoint only accepts real chats from our own pages

Branch `feat/067-chat-endpoint-security`, cut from `main` at 149260df. Not pushed. The command was not run against any live page.

## Commits

| Commit | What |
|---|---|
| `f8274fb6` | docs: the brief, verbatim |
| `7a2a5356` | feat: the three layers, refusal counters, `botcake:chat-setup`, and the Setup box without keys |
| `6de9ec3e` | fix (review): `botcake_psids.source` widened for `chat_verify`; the PSID check fails closed except when Botcake is down; a per-page cap on PSID checks; the IP check uses the connecting address |
| (this commit) | docs: result, stories, TODO |

## Auth order (`BotcakeChatController::receive`)

1. **IP.** The connecting address (`REMOTE_ADDR`) is checked against `BOTCAKE_CHAT_IPS` (IPs or CIDRs).
   - `enforce`: an address off the list gets 403 and is counted (`bad_ip`).
   - `log`: the request goes on. It is logged (`ip`, `forwarded_ip`) and counted only once the key is known (step 4), so the log lists the addresses of real key holders.
2. **Header key known.** `X-Chat-Key` must be some page's `chat.key` (looked up by its sha256 in a 10-minute cache that is cleared on every save) or the shared key while `BOTCAKE_CHAT_SHARED_KEY_OK` is true. Otherwise 401 (`bad_key`), before the body is read.
3. **Size.** A body over 64 KB gets 413.
4. **Key param.** If there is no header, the `key` param from the body is used (old GET blocks). It is checked the same way (401, `bad_key`).
5. **Our page.** `page_id` (or the `page` name) must map to a page with a `botcake.page_id` in page settings. Otherwise 401 (`unknown_page`).
6. **Key fits the page.** The key must be that page's own `chat.key` (`hash_equals`, read fresh from page settings) or the shared key while the flag is on. Otherwise 401 (`bad_key`).
7. **PSID on the page.** In order:
   1. A row in `botcake_psids` for that page (name compared trimmed and case-insensitive): keep the chat.
   2. A "not on page" answer cached in the last hour: not kept, counted `psid_not_found`.
   3. The page has no token, or its per-page cap of checks (`BOTCAKE_CHAT_VERIFY_PER_MINUTE`, default 60) is used up: not kept, counted `psid_unchecked`.
   4. Otherwise one customer-info call, with the page token, 5 seconds, no retry, sharing the page's Botcake rate key with chatblast:
      - **found:** a `botcake_psids` row is inserted (`source='chat_verify'`, `status='verified'`) and the chat is kept;
      - **"Not found customer" / "psid wrong format":** cached for 1 hour, not kept, counted `psid_not_found`;
      - **no connection, timeout or 5xx:** kept, and a warning is logged ("PSID not verified, chat kept");
      - **any other answer (401/403/429/404/other):** not kept, counted `psid_unchecked`, logged.

   Every "not kept" case answers `{"ok":true}`. A chat without a PSID is not kept (`psid_unchecked`).

No log line, response, HTML or command output carries a key or token. The cache holds only sha256 hashes of the keys.

## Command

```
php artisan botcake:chat-setup "Page name"          # one page, by name (or its Botcake page ID)
php artisan botcake:chat-setup "Page name" --rotate # new key for that page
php artisan botcake:chat-setup --all --dry-run      # what it would do on every page, writes nothing
php artisan botcake:chat-setup --all --confirm      # every page with a Botcake ID and token
```

- **Pages:** every page with an active `botcake.page_id` and `botcake.token`.
- **Key:** created (48 random characters) when the page has none, or always with `--rotate`.
- **Order:** the Default Reply content is written first (`POST /pages/{id}/default_reply/content` with the exact "Chat to site" block, including the padding rows and `body_preview`). Then the key is saved as the secret system setting `botcake.chat.key`. Then the Default Reply is published (`POST /pages/{id}/default_reply` with `{"update":{"is_published":true,"unlimit_default_reply":true}}`).
- **Failures:** a failed write keeps the old key. The output names pages and statuses only.
- **Guard:** `--all` without `--dry-run` or `--confirm` is refused.
- **Overwrites:** writing the content replaces the page's whole Default Reply.

## Setup box

The Setup box on /chatblast/chats shows:
- the method, URL, header name and the body, `{"page_id":"{{page_id}}","psid":"{{psid}}","name":"{{user_full_name}}","message":"{{last_text_input}}","source":"default_reply"}`;
- the command to use;
- a table of pages with their Botcake ID and "Own key: yes/no";
- today's refusals by reason: IP not on the list, Bad key, Unknown page, PSID not on the page, PSID could not be checked.

No key is shown to anyone: there is no copy button and no masked value.

## Config (all in `.env.example`)

| Variable | Default | What it does |
|---|---|---|
| `BOTCAKE_CHAT_IPS` | `203.171.22.6` | Allowed IPs or CIDRs, comma-separated |
| `BOTCAKE_CHAT_IP_MODE` | `log` | `log` or `enforce` |
| `BOTCAKE_CHAT_SHARED_KEY_OK` | `true` | Whether the old `BOTCAKE_CHAT_KEY` is still accepted |
| `BOTCAKE_CHAT_VERIFY_PER_MINUTE` | `60` | Maximum new-PSID checks per page per minute (new) |

## Migration

`2026_10_22_100000_widen_source_on_botcake_psids.php` widens `botcake_psids.source` from 10 to 20 characters.
- It is needed because `chat_verify` is 11 characters and MySQL strict mode would refuse the insert with a 500.
- Run it at release, before the first chat that needs a PSID check. Without it, every first chat from a new customer is lost.
- On MySQL, growing a varchar below 255 doesn't copy the table.
- No test can pin the width: sqlite stores no varchar length. That is why the tests missed the bug.

## Case table

`tests/Feature/Chatblast/BotcakeChatSecurityTest.php`. Every test passed on 2026-10-19.

| Case | Test | Result |
|---|---|---|
| S-067.1 | `test_S_067_1_an_ip_off_the_list_is_logged_and_counted_in_log_mode_and_refused_in_enforce_mode` | pass |
| S-067.2 | `test_S_067_2_a_page_key_only_opens_its_own_page_and_an_unknown_page_is_refused` | pass |
| S-067.3 | `test_S_067_3_the_shared_key_works_only_while_the_flag_is_on` | pass |
| S-067.4 | `test_S_067_4_a_psid_already_in_botcake_psids_needs_no_api_call` | pass |
| S-067.5 | `test_S_067_5_an_unknown_psid_is_checked_once_with_the_page_token_and_remembered` | pass |
| S-067.6 | `test_S_067_6_a_psid_not_on_the_page_is_not_stored_counted_and_not_asked_again_for_an_hour` (2 data sets) | pass ×2 |
| S-067.7 | `test_S_067_7_when_botcake_cannot_answer_the_chat_is_stored_and_logged` (timeout, 500) | pass ×2 |
| S-067.8 | `test_S_067_8_the_setup_command_dry_run_writes_nothing_and_prints_no_key` | pass |
| S-067.9 | `test_S_067_9_the_setup_command_writes_the_exact_block_and_publishes_it` | pass |
| S-067.10 | `test_S_067_10_a_failed_block_write_keeps_the_old_key` | pass |
| S-067.11 | `test_S_067_11_the_setup_box_shows_which_pages_have_a_key_and_todays_refusals_but_no_key` | pass |
| S-067.12 | `test_S_067_12_any_other_answer_or_no_token_keeps_nothing_and_is_counted` (token refused, 429, unknown answer, no token) | pass ×4 |
| S-067.13 | `test_S_067_13_psid_checks_per_page_are_capped_per_minute` | pass |
| S-067.14 | `test_S_067_14_the_ip_check_uses_the_connecting_address_not_a_forwarded_header` | pass |
| S-067.15 | `test_S_067_15_after_rotation_or_deactivation_the_old_key_is_refused` | pass |

**Red runs:**
- **First implementation:** all 14 tests failed. The endpoint gave 401 for page keys, and `BotcakeChatGuard` and the command did not exist.
- **Fix loop:**
  - S-067.12 failed on every data set (the chat was kept).
  - S-067.13 failed (3 calls instead of 2).
  - S-067.14 failed (200 instead of 403).
  - The extended S-067.2 and S-067.3 and the new S-067.15 passed at once: the key binding was already right, and they now pin it.

`php artisan test tests/Feature/Chatblast`: every test passes (1594 assertions). The only warnings come from the environment: the worktree has no env file.

## Full suite

`php artisan test` on `6de9ec3e`: **2 failed, 175 passed, 1654 passed with warnings** (19680 assertions, 345 s). The only failures are the known `ExampleTest` and `NightRunImportStartTest`. There are no new failures. The run on `7a2a5356` before the fix loop showed the same two.

## Review

`skeptic-reviewer`, adversarial depth on opus, on `f8274fb6..7a2a5356`, looking hardest at the auth paths. It found 1 blocker and 3 majors, all fixed in `6de9ec3e`:
- **blocker:** `chat_verify` didn't fit `botcake_psids.source` (`varchar(10)`). On MySQL strict, every first chat from a new customer would have been a 500 and lost. Fixed with a new migration that widens the column to 20.
- **major (spec):** the PSID check accepted the chat on any answer except "not on page": no token, 401, 429 or an unknown answer. Someone could force that path, for example through an expired token or Botcake rate limits. It now keeps the chat only when Botcake confirms it, or on no connection, timeout or 5xx.
- **major:** enforce mode used `ip()`. With `trustProxies('*')`, a forged `X-Forwarded-For` could pass it. It now uses `REMOTE_ADDR`.
- **major:** nothing capped the Botcake checks one key holder could trigger, and they shared the page's chatblast rate. Now there is a per-page cap per minute; over it, the chat is not kept.
- **major (missing tests):** key A with page B's name, a page without its own key with the flag off, rotation and deactivation over HTTP, a forged IP, and Botcake refusals. All are added.
- **Minors:** fixed where they sat on the untrusted path. In log mode the IP log line and counter now come only after the key is known, and a chat without a PSID is counted as "PSID could not be checked". The rest are accepted in `TODO.md`.

### Re-check

The re-check of `6de9ec3e` (adversarial depth) found **no new or open blocker or major.** It confirmed each fix:
- the widened column;
- the chat is kept only on found or down;
- enforce mode checks the connecting address;
- the per-page cap;
- the missing auth tests are added.

Its minors are accepted in `TODO.md`:
- failing closed can drop real chats when a page's token has expired or a page is over the cap (both are counted);
- counter writes before the key check in enforce mode;
- an unproven 5xx bypass with a crafted PSID; refusing PSIDs that aren't all digits would close it.

## Story changes

- **S-059.5 and S-059.15:** an unknown page id or name is now refused with 401 instead of being stored as "Unknown page". The 067 decision is "only our own pages". Old rows still show "Unknown page".
- **S-059.11 and S-065.1:** replaced by S-067.11. The Setup box shows no key and no GET Params rows any more, and its body gains `"source":"default_reply"`.
- **S-067.7:** narrowed to timeout and 5xx. The "token refused" case moved to S-067.12, where nothing is kept, because of the review.
- **S-067.12 to S-067.15:** added from the review.
- **`BotcakeChatTest`:** its setUp now configures Page Uno with a known `psid-a` and a fake Botcake that confirms every PSID, because every chat now needs our page and a checked PSID.

## What the brief got wrong

- **`source='chat_verify'` does not fit `botcake_psids.source`, which is `varchar(10)`.** I kept the value and added a migration that widens the column to 20; it has to run at release.
- **"If Botcake is down or times out, accept":** the brief is silent on other failures (no token, 401/429, unknown answers). Accepting those would let an attacker turn the PSID check off, so they keep nothing. A new reason, "PSID could not be checked", counts them. There are five refusal reasons, not four.
- **"Botcake posts from 203.171.22.6":** the brief doesn't say how that address was seen. With `trustProxies('*')`, `ip()` comes from the client's own header, so the check now uses `REMOTE_ADDR`. If the site is behind a proxy or CDN, `REMOTE_ADDR` is the proxy's address, and enforce mode would refuse every chat. Compare `ip` and `forwarded_ip` in the log first.
- **Running the command on a page replaces that page's whole Default Reply.** The brief implies this; it is stated here so it isn't a surprise.
- **Unknown pages are refused now** (see Story changes). That follows from "only our own pages", but it changes 059's "Unknown page" storage.

## Release steps (for whoever deploys)

1. Run the migration (`2026_10_22_100000_widen_source_on_botcake_psids`) before or with the deploy.
2. Leave `BOTCAKE_CHAT_SHARED_KEY_OK=true` and `BOTCAKE_CHAT_IP_MODE=log`.
3. Run `botcake:chat-setup` page by page (`--dry-run` first).
4. Watch "Refused today" and the IP log lines.
5. Then set the flag to false and, if the addresses check out, set the mode to `enforce`.

## Merge danger

- **Medium.** Chats from pages not in page settings, or from customers Botcake can't confirm (no token on the page, refusals, over the cap), are no longer stored. Pages that have no token today will stop collecting chats until a token is set.
- **The migration must run.**
- **No other behaviour of the Chats page changes.**
