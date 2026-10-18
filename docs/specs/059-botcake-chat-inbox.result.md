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

## Amendment 1: Botcake's own variable names, pages without a page variable

Commits `9a2e60d1` (amendment) and `795f3c66` (review fix: the filter lists page-settings pages).

### What changed
- **Aliases:**
  - psid: `user.id`, `user_id`, `fb_id`, `id`;
  - name: `full_name`, `user.full_name`, `user_full_name`;
  - message: `last_text_input` (second only to `message`).
  - In GET and form bodies PHP turns the dot into `_`, so `user.id` arrives as `user_id` and `user.full_name` as `user_full_name`. Both spellings are accepted.
- **Page:** Botcake has no page variable, so each page's block identifies itself with `page_id` (its Botcake ID, typed as fixed text) or `page` (its name as in page settings, trimmed and case-insensitive). A match by name stores that page's name and its `botcake.page_id`. With no match, page_name stays empty and the Chats page shows "Unknown page" (the filter shows "Unknown page (ID …)").
- **No text:** a message with no text (a sticker or photo) is kept when it has a PSID, with an empty message, and shows "no text (sticker or photo?)" in the list and the customer view. A request with neither a PSID nor a message is still answered `{"ok":true}` and not stored (S-059.7).
- **Setup box:**
  - (a) **Recommended: POST with a JSON body.** The URL, the `X-Chat-Key` header, and the body `{"page_id":"<this page's Botcake ID>","psid":"{{user.id}}","name":"{{user.full_name}}","message":"{{last_text_input}}"}` with a Copy button.
  - (b) **GET with Params rows:** `key` (masked; Copy key for CEO only), `page_id`, `psid`, `name`, `message`, each with a Copy button.
  - With a page selected in the Chats filter, its Botcake ID replaces the placeholder in both forms.

### Case table (amendment 1)
All 24 tests in `BotcakeChatTest` passed (144 assertions); the whole `tests/Feature/Chatblast` folder passed too (1350 assertions; warnings only, from the missing env file).

| Case | Test | Result |
|---|---|---|
| S-059.3 (extended) | `test_S_059_3_aliases_and_any_case_fill_the_fields_and_extra_params_are_kept_without_the_key` | pass |
| S-059.15 | `test_S_059_15_the_page_is_found_by_its_name_when_there_is_no_page_id_and_unknown_pages_are_labelled` | pass |
| S-059.16 | `test_S_059_16_the_setup_box_gives_the_json_body_and_the_get_params_with_the_selected_page_id_filled_in` | pass |
| S-059.17 | `test_S_059_17_an_empty_message_from_a_customer_is_kept_and_labelled` | pass |

**Red run:** before the code, S-059.3 failed (no psid for `user.id`), S-059.15 failed (page_id and page_name null for a page named by `page`), S-059.16 failed (no JSON body in the page) and S-059.17 failed (no label).

### Review (amendment 1)
One `skeptic-reviewer` pass at adversarial depth on `7c12d3ba..9a2e60d1`.

- **Correctness and security:** no blockers or majors.
  - The aliases prefer `psid` and `user.id` before the generic `id`.
  - The page-name lookup uses bound parameters and is capped at 255 characters.
  - The `{{user.id}}` style values print as escaped literal text.
  - The selected page_id reaches the body only JSON-encoded and Blade-escaped.
  - The full key still appears for CEO only.
- **One spec major, fixed in `795f3c66`:** the page filter, and so the pre-fill, listed only pages that already had chats. Before the first chat, the setup box could never fill in a page's ID.
  - The filter now also lists every page with an active `botcake.page_id` in page settings.
  - S-059.16 gained an assertion for a page with a Botcake ID and no chats. It was red first (option missing).
  - All of `tests/Feature/Chatblast` then passed (1352 assertions).
  - This fix got no reviewer re-check; recorded in `TODO.md`.
- **One minor, accepted in `TODO.md`:** "Unknown page" does not show the `page` text the block sent.
- **The reviewer also asked about the earlier text/plain key major:** it was fixed in `d79e1aad`, pinned by S-059.2's fifth data set.

### Story changes (amendment 1)
- S-059.3 extended with Botcake's variable names; S-059.15 to S-059.17 added.
- S-059.7 is unchanged: "empty messages are kept" applies to a customer message with no text (it has a PSID); a request with no PSID and no message still stores nothing.

### Merge danger (amendment 1)
- Low. No migration. The plain `id` param now counts as the PSID when no `psid`, `user.id`, `user_id` or `fb_id` is sent.

## Independent review fixes (two performance majors, five minors)

Commit `4b7f2561`.

### What changed
1. **Page list (major):** the Chats page no longer runs a GROUP BY over `botcake_chats`. The list comes from `BotcakeChat::pageMap()`, the pages with an active `botcake.page_id` in page settings (cached 10 minutes). Page ids seen only in chats come from a cached (10 min) DISTINCT list and show as "Unknown page (ID …)".
2. **Pagination and index (major):** `simplePaginate()` replaces `paginate()`, so there is no COUNT(*). The footer reads "Page N · X messages" with Newer and Older. The create-table migration (not yet run anywhere) gains a `(page_id, received_at)` index for the page filter ordered by time.
3. **Prune:** it now deletes in batches of 5000, looping until done, with at most 40 batches (200,000 rows) per run.
4. **Page map:** the page_id → page name map is cached for 10 minutes and used for every incoming chat (no query per message). `PageSettings::set` and `deactivate` clear it when a `botcake.page_id` changes, both at once and after the commit. The lookup by page name checks the map first, then falls back to page settings as before.
5. **Body size:**
   - A header key, when present, is checked first (401).
   - Then a body over 64 KB, by Content-Length or raw length, gets 413 before it is read or decoded.
   - A key sent as a param is checked after the size check, as before.
6. **Setup box:** it says "Prefer POST + header; GET puts the key in server logs."
7. **Payload:** the payload no longer repeats page_id, psid, name or message (whichever alias carried them, and any empty alias of them). Other params, `message_id`, `sent_at` and `page` stay in it.

The rate limiter is unchanged, on the default cache.

### Case table (review fixes)
| Case | Test | Result |
|---|---|---|
| S-059.18 | `test_S_059_18_the_chats_page_never_counts_or_groups_the_whole_table_and_lists_pages_from_settings` | pass |
| S-059.19 | `test_S_059_19_the_prune_deletes_in_batches_until_done` | pass |
| S-059.20 | `test_S_059_20_the_page_name_map_is_cached_and_cleared_when_a_botcake_page_id_is_saved` | pass |
| S-059.21 | `test_S_059_21_a_body_over_64_kb_gets_413_after_a_header_key_check` (header key, param key, wrong header) | pass ×3 |
| S-059.3, S-059.13 (extended) | payload without the column fields | pass |
| S-059.16 (extended) | the "Prefer POST + header" note | pass |

**Red run:**
- **S-059.3 and S-059.13:** `Page_ID` and `message` were still in the payload.
- **S-059.16:** the note was missing.
- **S-059.18:** `select count(*) as aggregate from "botcake_chats"` was still running.
- **S-059.19 and S-059.20:** `PRUNE_BATCH` and `pageMap()` didn't exist yet.
- **S-059.21:** the oversized body got 200 instead of 413.

After the change, `BotcakeChatTest` passed 30 tests (178 assertions), and the Chatblast, PageSettings and Botcake folders passed with 2760 assertions.

### Full suite (review fixes)
`php artisan test` on `4b7f2561`: **2 failed, 158 passed, 1529 passed with warnings** (18942 assertions, 278 s). The only failures are the known `ExampleTest` and `ImportStartTest`.

### Merge danger (review fixes)
- **Migration:** the create-table migration was edited, not added to. This is safe only because it has not run on any server. If it has run anywhere, that server lacks the `(page_id, received_at)` index.
- **Cache:** the page map lives in the app cache. A `botcake.page_id` changed outside `PageSettings` (by hand in the database) takes up to 10 minutes to apply.
