# Result 059: receive customer chats from Botcake's JSON API block

Branch `feat/059-botcake-chat-inbox`, cut from `main` at 5e916162. Not pushed.

## Commits

| Commit | What |
|---|---|
| `1965912f` | docs: the brief, verbatim |
| `fecefcf0` | feat: the endpoint, the `botcake_chats` table, the Chats page with the Botcake setup box, the 90-day prune, stories and tests |
| `7169001e` | fix (review): the key is checked before the body is read; messages are kept up to 5000 characters (they were cut at the 2000-character payload cap); invalid UTF-8 is stored without an error; duplicate text is compared exactly; tests pin the caps and the escaping |
| `d79e1aad` | fix (review re-check): a `key` param inside a text/plain JSON body is read before the key check again |
| (this commit) | docs: result, stories, TODO |

## Migration

`database/migrations/2026_10_20_100000_create_botcake_chats_table.php` creates `botcake_chats`, guarded with `Schema::hasTable`:

- **Columns:** id, page_id (64), page_name, psid (64), name, message (text), message_id (191, nullable, indexed), payload (json), received_at.
- **Indexes:** (page_id, psid, received_at) and received_at.
- **Running it:** by hand at release with `php artisan migrate --force`. Until then, the Chats page shows "The chats table is not set up yet", and `chatblast:auto` and the prune skip the table.

## Endpoint contract

`POST /api/botcake/chat` (GET also works). It sits in the `api` group only: no session, no CSRF, no IP allow-list.

- **Key:** header `X-Chat-Key`, or a param named `key` in any letter case, checked with `hash_equals` against `services.botcake.chat_key`. A missing or wrong key, or no key set on the server, returns 401 `{"ok":false}` and nothing else.
- **Body:** JSON, form, query params, or JSON sent as `text/plain`.
- **Known fields:** `page_id`, `psid`, `name`, `message` and `message_id`.
  - Names are matched case-insensitively.
  - Aliases: `user_id`/`fb_id` → psid, `text`/`last_input`/`last_user_input` → message, `full_name` → name.
  - `sent_at` and every other param stay in `payload`, minus the key.
- **Cuts:** page_id and psid to 64 characters, name to 255, message to 5000, message_id to 191. The payload keeps at most 50 params at 2000 characters each; arrays are stored as JSON, and invalid bytes are replaced.
- **page_name** comes from the active page setting `botcake.page_id` equal to the page id. It is empty when no page matches.
- **Not stored:**
  - a request with neither psid nor message (for example Botcake's test Send left empty);
  - a repeat `message_id`;
  - the same page + psid + exact text within 10 seconds.
- **Reply:** always `{"ok":true}` once the key is right. It makes no outbound call and sends nothing to the customer.
- **Rate limit:** 1200 requests per minute per IP (named limiter `botcake-chat`); over that, 429.

```bash
curl -X POST https://likhaaitech.com/api/botcake/chat \
  -H "X-Chat-Key: $BOTCAKE_CHAT_KEY" \
  -H "Content-Type: application/json" \
  -d '{"page_id":"1234567890","psid":"9876543210","name":"Juan Dela Cruz","message":"Magkano po?"}'
# {"ok":true}
```

## Env keys to set at release

- `BOTCAKE_CHAT_KEY`: a long random string, also pasted into the Botcake block as the `X-Chat-Key` header. Until it is set, every request gets 401.
- `BOTCAKE_CHAT_RATE` (optional, default 1200 per minute per IP).

Both are in `.env.example`. After setting them, run `php artisan config:cache` if the server caches its config. Set the key on Incepxion too if Botcake is pointed there.

## The Chats page

`/chatblast/chats`, a new **Chats** item in the Chatblast nav after Sequences. It has the same access as /chatblast: `NavAccess` for `/chatblast`, CEO always, 404 for everyone else.

- **List:** time, page, customer and message, newest first, 100 per page, with Newer and Older links.
- **Filters:** page (by page id), date (one day, Manila), and a search over name or text. The search is case-insensitive, and `%` and `_` are treated as letters.
- **Customer view:** clicking a customer opens `/chatblast/chats/customer?page_id=…&psid=…`, which shows their messages oldest first (the latest 500).
- **Botcake setup button:** opens a modal (not sticky; close with ✕, Escape or a click outside) with:
  - Method POST;
  - the URL `{APP_URL}/api/botcake/chat` with a Copy button;
  - header `X-Chat-Key` = `••••` plus the last 4 characters, or "Not set on the server yet" when there is no key;
  - params `page_id`, `psid`, `name` and `message`, with the note that the values are Botcake variables.

  The full key is in the page only for CEO, behind a "Copy key" button. Other roles never receive it.
- **Layout:** fixed table layout with words breaking, the page column folded under the customer name at phone width, and no horizontal scroll. Every UI string is in English.

## Prune

- `BotcakeChat::prune()` deletes rows with received_at older than 90 days.
- `botcake:prune-chats` runs it from the scheduler, daily at 04:25 Manila.
- `chatblast:auto` calls `BotcakeChat::pruneDaily()` first, at most once per Manila day (a cache flag), inside a try/catch so it never stops the auto run. This covers Incepxion, where only `chatblast:auto` is in cron.

## Case table

`tests/Feature/Chatblast/BotcakeChatTest.php`. Every row passed on 2026-10-18. The run listed each test with a `!` for the environment's `file_get_contents` warning (this worktree has no env file); that is not a failure, and the same warning appears across the whole suite.

| Case | Test | Result |
|---|---|---|
| S-059.1 | `test_S_059_1_a_missing_or_wrong_key_gets_401_with_no_detail` (no key, wrong header, wrong param, not on server) | pass ×4 |
| S-059.2 | `test_S_059_2_json_form_and_get_are_stored` (json, form, get, json as text/plain, json as text/plain with the key in the body) | pass ×5 |
| S-059.3 | `test_S_059_3_aliases_and_any_case_fill_the_fields_and_extra_params_are_kept_without_the_key` | pass |
| S-059.4 | `test_S_059_4_duplicates_are_not_stored_twice` | pass |
| S-059.5 | `test_S_059_5_the_page_name_comes_from_the_page_settings_page_id` | pass |
| S-059.6 | `test_S_059_6_one_ip_over_the_limit_gets_429` | pass |
| S-059.7 | `test_S_059_7_a_request_without_psid_or_message_is_answered_but_not_stored` | pass |
| S-059.8 | `test_S_059_8_the_chats_page_lists_the_latest_messages_for_roles_with_chatblast_access` | pass |
| S-059.9 | `test_S_059_9_the_page_date_and_search_filters_narrow_the_list` | pass |
| S-059.10 | `test_S_059_10_a_customer_shows_their_messages_oldest_first` | pass |
| S-059.11 | `test_S_059_11_the_setup_box_shows_the_values_with_the_key_masked_and_the_full_key_for_ceo_only` | pass |
| S-059.12 | `test_S_059_12_the_prune_deletes_chats_older_than_90_days_from_its_command_and_from_chatblast_auto` | pass |
| S-059.13 | `test_S_059_13_oversized_nested_or_invalid_values_are_cut_and_stored_without_an_error` | pass |
| S-059.14 | `test_S_059_14_stored_name_and_text_show_as_text_not_html` | pass |

Plus `ChatblastToSendTest::test_S_056_18…` (the nav list now includes Chats): pass.

**Red runs:**
- First run: all 17 tests failed with "File does not exist" for the migration not yet written.
- After the table and model: 404 instead of 401/200, and `CommandNotFoundException` for the prune.
- In the fix loop, S-059.13 failed on the stored message length (expected 5000, actual 2000). That was a real bug, fixed in `7169001e`.

## Full suite

`php artisan test` on `fecefcf0`: **2 failed, 158 passed, 1517 passed with warnings** (18865 assertions, 316 s). The two failures are the known ones:

- `Tests\Feature\ExampleTest > the application returns a successful response`
- `Tests\Feature\NightRun\ImportStartTest > macro job final write applies only while the run is still active` (InvalidArgumentException)

Final run on `d79e1aad` (after both fix loops): **2 failed, 158 passed, 1520 passed with warnings** (18885 assertions, 288 s). The only failures are the same two known tests, `ExampleTest` and `ImportStartTest`.

## Review

One `skeptic-reviewer` pass at adversarial depth (opus) on `1965912f..fecefcf0`, then one scoped re-check of the fix.

- **No blockers.**
- **Two majors, both missing tests on the untrusted path:**
  - The caps on oversized, nested and many-key payloads had no test. Writing that test exposed the 2000-vs-5000 message cut, which is now fixed.
  - The escaping of stored name and text on both Chats pages had no test.

  Both are fixed in `7169001e` (S-059.13, S-059.14).
- **Minors on the untrusted path, fixed in the same commit:**
  - The key is now checked before the body is walked.
  - Invalid UTF-8 no longer gives a 500.
  - Duplicate text is compared exactly; MySQL's case-insensitive `=` would have dropped "OK" after "ok".
- **Minors accepted with reasons in `TODO.md`:**
  - the per-IP limit and `trustProxies('*')`;
  - the duplicate race between two simultaneous requests;
  - `pruneDaily` marking the day before the prune runs;
  - a few display or trusted-path tests left out;
  - the mask format existing twice.
- **Minors kept as decisions** (see below): the payload caps, and the `BOTCAKE_CHAT_RATE` knob.
- **Re-check of the fix:** see "Re-check" below.

### Re-check

The re-check of `7169001e` confirmed its five changes. It found one new **major**: moving the key check earlier meant a `key` param inside a `text/plain` JSON body was no longer seen. If the owner put the key in Params and Botcake posted text/plain, every chat would have been refused with 401.

- **Fix loop 2 (`d79e1aad`):** a new S-059.2 data set came first and was red (401 instead of 200). The fix decodes the text/plain JSON before the key lookup. All 21 chat tests then passed.
- **Not re-checked by the reviewer:** this was the second loop, the cap. It is recorded in `TODO.md`.
- **Two new minors from the re-check, accepted in `TODO.md`:** there is no test for the exact-text duplicate check, because sqlite cannot tell the difference; and NUL bytes would break an insert on PostgreSQL only.

## Browser check

Skipped, for time (the brief's budget was about 1.5 hours). The pages are server-rendered Blade. The tests check the HTML (the list, the filters, the customer view, the setup values, the masking and the escaping). The no-sticky and no-horizontal-scroll rules were followed in the markup: a modal like the existing ones, a fixed table layout with words breaking, and the page column folded at phone width. Not walked in a browser: phone width, keyboard focus in the modal, and the Copy buttons (the clipboard API needs https or localhost).

## Story changes

- **Stories written by the main session** (the brief brought a test list, not stories): S-059.1 to S-059.12. S-059.13 and S-059.14 were added from the review's missing tests.
- **S-059.7 is a decision the brief left open:** a request with neither psid nor message is answered `{"ok":true}` and not stored. This keeps Botcake's test "Send" from filling the list with empty rows.
- **`ChatblastToSendTest::test_S_056_18`'s expected nav list now includes "Chats".** The spec adds the item.

## What the brief left open, and the decisions taken

- **Payload caps:** at most 50 params at 2000 characters each. The brief says "the full payload is stored". The cap bounds what one caller can store. The key never reaches storage.
- **Rate:** 1200 per minute per IP, configurable with `BOTCAKE_CHAT_RATE`, so it can be raised without a deploy if Botcake uses few outbound IPs for about 170 pages.
- **Field cuts:** the known fields are cut to their column sizes (message 5000).
- **`sent_at`:** kept in the payload only. The table has no `sent_at` column (the brief's column list); `received_at` is the server time.
- **The Chats page filter param is `page_id`, not `page`,** because `page` is the paginator's page number.
- **401 body:** `{"ok":false}`, no detail. The same 401 is returned when no key is set on the server (it is not a 503), so an unconfigured server accepts nothing.

## Process notes

- **No plan gate:** the session was non-interactive and the brief already fixed every decision, so the main session did the work directly instead of through developer agents. No plan was sent for a "go".
- **High risk:** this is a new public route, so the review ran at adversarial depth on opus.
- **`vendor`:** the worktree had none. A link to the main checkout's `vendor` made the autoloader load the main checkout's app code, so I removed the link and copied `vendor` instead. The main checkout's `vendor` was not changed.

## Merge danger

- **Low.** The new table, routes and views are additive.
- **Existing files touched:**
  - `AutoChatblast` gains a guarded prune call at the top.
  - `ChatblastNav` gains one item.
  - `AppServiceProvider::boot` gains one rate limiter.
  - `routes/console.php` gains one schedule entry.
- **At release:**
  1. Run the migration.
  2. Set `BOTCAKE_CHAT_KEY`.
  3. Paste the setup values into Botcake.
- **Until the key is set,** the endpoint refuses everything (401). That is safe, but Botcake's test Send will show 401.
