# Result 046: `/chatblast` sends order updates to customers through Botcake, and shows every message like a sheet

## What shipped

Commits on `feat/046-chatblast` (cut from `develop` at bef411e):

- `docs: brief for spec 046 chatblast`
- `docs: plan for spec 046 chatblast` (`docs/plans/046-chatblast.md`)
- `feat: chatblast message table and list building: one row per waybill out for delivery in the last 7 days, ready or missing PSID or settings, with a template per kind (spec 046)`
- `feat: chatblast sends each message on its own queue by writing the custom field, reading it back and sending the flow, with retries, a shared 2 a second rate per token, a daily cap, pause and resume, test sends and a stop on an invalid token (spec 046)`
- `feat: /chatblast routes for the list with grouped status counts, the debug panel, build, send, send ready, retry failed, pause, resume, test send, template and test PSID picker, open to the roles checked in nav settings, and a Test PSID column on /page-settings (spec 046)`
- `feat: the /chatblast page: status chips, sending state with pause and resume, filters, a 100-row sheet with a sticky header, actions, the message text and test PSID picker, and a side panel with every send step for debugging (spec 046)`
- `fix: chatblast never repeats a send_flow whose outcome is unsure (5xx, no answer, or a job killed after calling it): such a message is unknown and no Send or Retry takes it, steps are saved as they happen, a page with an invalid token is not called again, and Retry-After is capped at 10 s so a message fits its job (spec 046)`
- `fix: a send_flow answer of 408 or a 2xx without a clear success is unknown, not retryable, and unknown messages count toward the daily cap (spec 046)`
- `docs: accepted review findings for spec 046 in TODO`
- `docs: result for spec 046`

### Table and migrations

- **`2026_10_18_300000_create_botcake_messages_table`.** A new table, the send log; there was no existing table for it.
  - One row per message: `page_name`, `macro_output_id`, `waybill`, `kind`, `fb_name`, `psid`, `vars`, `text`, `jnt_status`, `status`, `reason`, `attempts`, `last_http`, `steps`, `is_test`, `test_of`, `dedupe_key`, `queued_at`, `sent_at`, `created_by`, `sent_by`, timestamps.
    - `vars` (JSON) holds name, item, cod, waybill and page, so the text can be rendered again.
    - `text` is the exact text that will be sent.
    - `jnt_status` is the J&T status at build time.
    - `reason` says why a row is `no_psid`, `no_config`, `failed`, `skipped` or `unknown`.
    - `steps` (JSON) records each step's request, HTTP code, attempts, response (cut to 1,500 characters) and time. It never holds the token.
  - **No double sends:** real rows have `dedupe_key` = `waybill|kind`, with a unique index, so there is one real row per waybill and kind. Test rows have NULL there; MySQL allows many NULLs in a unique index.
  - **Indexes:** `created_at`; `(status, created_at)`; `(page_name, created_at)`; `(waybill, kind)`; `(page_name, sent_at)`; `sent_at`; `fb_name`.
  - **Statuses:** the brief's nine, plus `unknown` (see "Review").
- **`2026_10_18_300100_add_chatblast_nav_link`.** A "Chatblast" nav link to `/chatblast`, visible to the CEO and listed (unchecked) for the other roles. It is idempotent.
- **Nothing else.** No other table is touched: no `macro_output`, no `from_jnts`. The template and the pause flag live in `app_settings`, and `botcake.test_psid` lives in `page_settings`.

### How the list is built (`Chatblast::build`; button "Kunin ang mga bago"; command `chatblast:build {--kind=delivering}`, not scheduled)

- **Which orders:** `macro_output` orders with `ts_date >= today − 7` (Manila) and a waybill.
- **"Delivering":** the waybill's `from_jnts` row has `status = 'Delivering'` and an empty `rts_reason`. That is the rule `/jnt` status and the orders list already use.
- **No repeats:** orders that already have a real row of that kind are left out, and only one row is kept per waybill.
- **Name:** the order's FB name, or the J&T receiver when the FB name is empty.
- **Status of a new row:**
  - **`no_config`:** the page is missing one of `page_id`, `token`, `flow.shipping`, `field.message`. The reason lists exactly which, for example `walang flow.shipping, field.message`. `page_id` is checked too because every API path needs it.
  - **`no_psid`:** the order has no `botcake_psid`.
  - **`ready`:** otherwise.
- **Queries:** one query reads the settings of all pages. The orders are read in chunks of 500 and inserted with `insertOrIgnore`, so a build running at the same time cannot double a row.
- **A second build** adds nothing new for the same waybills. It re-checks the rows still `no_psid` or `no_config` and turns them `ready` once the order has a PSID or the page's settings are complete.
- **Text:**
  - **The template** is per kind, in `app_settings` key `chatblast.template.<kind>`. The default for `delivering` is the brief's text, with no emoji.
  - **Placeholders:** `{name}`, `{item}`, `{cod}`, `{waybill}`, `{page}`.
  - **Saving a template** re-renders the text of rows not yet sent (`ready`, `no_psid`, `no_config`, `failed`, `field_mismatch`, `skipped`). Sent, queued, sending and unknown rows keep their text, so a read-back always compares against the text being sent.

### The send job, retries, rate and cap

- **Which rows:** "Send", "Send selected" (up to 1,000), "Send lahat ng ready" (the ready rows of the current filter) and "Retry failed" (`failed` and `field_mismatch` in the current filter).
  - Only rows in `ready`, `failed`, `field_mismatch` or `skipped` that have a PSID are taken.
  - Each becomes `queued`, and `SendChatblastMessage` is dispatched on queue **`chatblast`**.
  - The job has `$tries = 1` and `$timeout = 300`.
- **The job:**
  1. **Pause:** if sending is paused, the row stays `queued`.
  2. **Claim:** `queued → sending`, attempts + 1, in one guarded UPDATE, so a duplicate job does nothing.
  3. **Settings:** missing settings make the row `no_config`.
  4. **Invalid token:** if `token_status` is already `invalid`, the row is `failed` without calling Botcake.
  5. **Daily cap:** see below.
  6. **The three steps,** exactly as the brief describes:
     - **Write:** `POST /customer/{psid}/customer_fields` with `{"data":[{"id":field,"value":text}]}`. It must answer `success: true`.
     - **Read back:** `GET` the same path. The row whose `id` or `custom_field_id` equals the field must hold the text exactly; otherwise the row is **`field_mismatch`** and nothing is sent.
     - **Send:** `POST /flows/send_flow` with `{"psid":…, "flow_id":<int>}`. It must answer `success: true`.
  7. **Steps are saved before and after each call,** so even a killed job leaves a trace.
- **Retries:**
  - **Write and read:** 429, 5xx and connection errors are retried up to 3 times, with a 2, 4, 8 s backoff (a Retry-After is honoured up to 10 s).
  - **`send_flow`:** only a 429 is retried, because Botcake refused it.
  - **Unsure sends:** an `unknown` row is never queued again. That covers:
    - a 5xx, 408, timeout, or 2xx without a clear `success` from `send_flow`;
    - an exception thrown after it was called;
    - a job killed after it was called.

    An explicit `success: false` means `failed`, which may be retried.
- **401/403** on any step:
  - `botcake.token_status` is set to `invalid`, as the sync does, and the row is `failed`.
  - Every other `queued` row of that page goes back to `ready` with "Huminto: token invalid".
  - The page is not called again until the token is fixed.
- **Rate:** at most 2 requests a second per page token across all chatblast jobs. The spacing is kept in the cache under a hash of the token, never the token itself. Each request has a 15 s timeout.
- **Daily cap:** `BOTCAKE_CHATBLAST_DAILY_CAP` (default 3000) real messages per page per day (Manila). Messages already sent or `unknown` today count. Once the cap is reached, the row is `skipped` with the reason, and it can be sent the next day.
- **Pause / Resume:** stored in `app_settings`. Resume dispatches one job per queued row.
- **Test send:**
  - **The test PSID** is chosen per page by searching the page's `botcake_psids` (source api) by name; the picker shows name and last 4 digits only. It is stored as `botcake.test_psid` in page_settings, not secret.
  - **"Send test"** copies the row into a new row with `is_test`, `test_of` and no `dedupe_key`, aimed at the test PSID. It never blocks the real send.
- **Longest single job:** about 270 s in the worst case: 3 steps × 4 tries × 15 s, plus backoff capped at 10 s, plus the rate waits.

### The page (`GET /chatblast`) and its queries

- **Top:**
  - Status chips for the current filter. Each is clickable, for example `lahat 1,650 · ready 420 · sent 1,203 · failed 3 · walang PSID 35 · walang config 12`.
  - The sending state: `tumatakbo` / `naka-pause`, how many are queued, and how many were sent in the last hour, with Pause / Resume.
  - The actions: Kunin ang mga bago, Send selected (N), Send lahat ng ready, Retry failed. Every send action asks for confirmation.
- **Filters:** date range (default today, created date, up to 92 days), page, kind, status (the chips), and search. The search matches the start of the name or the exact waybill; both use an index.
- **"Text ng mensahe at Test PSID":**
  - **Template editor:** one template per kind, with the placeholders listed.
  - **Test PSID picker:** search a page's Botcake customers by name, pick your own account, save.
- **Table:** 100 rows a page, newest first, sticky header, a checkbox per row. Columns: Oras, Page, Customer (plus "(test)"), Waybill, J&T (current, from `from_jnts`), PSID (`…` plus last 4), Mensahe (first line; the full text on hover), Status badge (the reason on hover), Subok, HTTP, Naipadala. Prev and next buttons page through.
- **Polling:** every 4 s while anything is queued or sending, and only while the tab is visible.
- **`GET /chatblast/list` queries:** at most 7, the same for 10 and 1,000 rows (tested):
  - the counts (`GROUP BY status`);
  - the 100 rows;
  - the J&T status of those waybills (one grouped query);
  - the pause flag, the global queue size, and the count sent in the last hour.
  - The total comes from the counts.
- **Customer text** (names, items) is rendered with `textContent` only.

### The debug side panel (`GET /chatblast/messages/{id}`)

- **Click a row (or press Enter on it):**
  - the status and its reason;
  - the full text;
  - page, customer, the full PSID, kind, J&T status;
  - attempts and last HTTP code;
  - created (when and by whom), queued, and sent (when and by whom);
  - "test of", and the order id.
- **Each step:** method and path, the request body (never the token, which only travels in the header), HTTP code, attempts, time and the response.
- **Buttons:** Send (when the row can be sent) and Send test.
- **Tested:** the panel JSON and the page HTML never contain the token.

### Access

- **Who:** every `/chatblast` route checks `NavAccess::allows(role, '/chatblast')`, the same as /page-settings. The CEO always passes, another role passes when it is checked in /owner/nav-settings, and everyone else gets 404.
- **Rate limits:** each POST has its own throttle counter.
- **`/page-settings`:** it now has a `Test PSID` known column (`botcake.test_psid`, not secret) after `Message field ID`.

### Supervisor program for the `chatblast` queue (add on both servers)

Likha (adjust the path and user to the server, and the same on Incepxion):

```ini
[program:likha-chatblast]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/likha/artisan queue:work database --queue=chatblast --sleep=3 --tries=1 --timeout=320 --max-time=3600
directory=/var/www/likha
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=330
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/likha/storage/logs/chatblast-worker.log
```

Then run `supervisorctl reread` and `supervisorctl update`.
- **Timeout:** `--timeout=320` is above the job's 300 and below the database queue's `retry_after` of 1900. Incepxion must keep `retry_after` above 320 as well.
- **Keep numprocs at 1:** the daily cap is counted, not reserved.
- **The existing `default` worker** does not take `chatblast`, because it only works `default`.
- **Without this program,** queued messages wait in `jobs` and nothing is sent.

## Tests

| Case | Test | Result |
|---|---|---|
| ready / no_psid / no_config with the missing keys; not Delivering, over 7 days, an RTS reason, all left out; page spellings merged; second build adds nothing | `ChatblastBuildTest::test_delivering_orders_become_rows_by_status_and_a_second_build_adds_nothing` | pass |
| A second build turns a row ready once its PSID or settings exist | `…::test_a_second_build_turns_a_row_ready_once_its_psid_or_settings_exist` | pass |
| Default text, receiver when there is no FB name, every placeholder, a template change re-renders unsent rows only | `…::test_the_text_fills_the_placeholders_and_the_template_can_be_changed` | pass |
| `chatblast:build` builds the list | `…::test_the_command_builds_the_list` | pass |
| Success: write, read back, send_flow in order; exact bodies (`flow_id` an int); the token in the header only; 3 steps recorded without the token | `ChatblastSendTest::test_a_message_is_written_read_back_and_sent_and_every_step_is_recorded_without_the_token` | pass |
| Write fails; read-back differs or has no field (no send_flow); send_flow `success:false` (4 rows) | `…::test_a_failing_step_stops_the_message_and_says_why` | pass |
| 429 then success, with a 2 s backoff | `…::test_a_429_is_retried_after_a_backoff_then_sent` | pass |
| 5xx on the write is retried, then sent | `…::test_a_5xx_on_the_write_is_retried_then_sent` | pass |
| 401: token_status invalid, the other queued row of the page back to ready, no further call | `…::test_a_401_marks_the_token_invalid_and_stops_the_page` | pass |
| After a 401, a new send of the page makes no call | `…::test_after_a_401_the_page_is_not_called_again_until_the_token_is_fixed` | pass |
| A sent message is never sent again (queue refuses, build adds no row) | `…::test_a_sent_message_is_never_sent_again` | pass |
| Test send to the test PSID; none without one; the real row stays ready and is then sent | `…::test_a_test_send_goes_to_the_test_psid_and_does_not_block_the_real_send` | pass |
| Pause keeps queued, Resume sends | `…::test_pause_keeps_queued_messages_waiting_and_resume_sends_them` | pass |
| Daily cap skips the rest; unknown messages count | `…::test_the_daily_cap_per_page_skips_the_rest` | pass |
| Requests of one page 0.5 s apart across two jobs | `…::test_requests_of_one_page_stay_half_a_second_apart_across_messages` | pass |
| send_flow 503, timeout, 408, 200 without success → one call, `unknown`, never queued again (4 rows) | `…::test_an_unsure_send_flow_is_not_retried_and_never_sent_again` | pass |
| send_flow 429 retried; Retry-After capped at 10 s | `…::test_a_429_on_send_flow_is_retried_because_botcake_refused_it` | pass |
| Killed job: `failed` before the flow, `unknown` after it; steps kept | `…::test_a_killed_job_keeps_its_steps_and_is_unknown_once_the_flow_was_called` | pass |
| Steps saved while sending (flow recorded before it is called) | `…::test_the_steps_are_saved_as_they_happen` | pass |
| Counts per status for the filter (default today), 100 per page, first line, PSID last 4 only, page/status/date filters, sending state | `ChatblastPageTest::test_the_list_counts_each_status_for_the_filter_and_pages_100_rows_newest_first` | pass |
| Search: name start or exact waybill | `…::test_search_finds_a_name_start_or_an_exact_waybill` | pass |
| Query count the same for 10 and 1,000 rows, at most 7 | `…::test_the_query_count_is_the_same_for_10_and_1000_rows` | pass |
| Side panel: steps, full PSID and text; no token in the panel or the page | `…::test_the_side_panel_shows_every_step_and_never_the_token` | pass |
| Buttons: build, send ready (no_psid left out), retry failed, pause/resume, template save and validation, bad ids | `…::test_the_buttons_build_send_retry_pause_and_save_the_template` | pass |
| Test PSID by name (last 4 only), saved, another page's customer refused, used by Send test; Test PSID column on /page-settings | `…::test_the_test_psid_is_picked_from_the_page_customers_by_name_and_used_by_send_test` | pass |
| Nav link seeded for the CEO; CEO and a checked role reach it; another role gets 404 on every route and nothing changes | `…::test_the_ceo_and_a_checked_role_reach_chatblast_and_others_get_404` | pass |

Red runs:
- **Build:** the migration was missing ("File does not exist … create_botcake_messages_table.php").
- **Send:** "Target class [App\Services\ChatblastSender] does not exist".
- **Page:** "Expected response status code [200] but received 404" on every route, and the nav-link migration was missing.
- **Fix loop 1:**
  - "Failed asserting that 4 is identical to 1": `send_flow` was retried.
  - "an array contains 10.0": Retry-After was not capped.
  - "two strings are identical": killed and 401 cases.
  - `array_column(): … null given`: steps were not saved during the send.
- **Fix loop 2:** the 408 and 200-without-success rows failed on "two strings are identical", and the cap with an `unknown` row failed.
- **One existing test changed:** `PageSettingsGridTest`'s known-column list now includes `botcake.test_psid`.

Full suite (`vendor/bin/phpunit`) on the final tree: `Tests: 1418, Assertions: 17081, Errors: 1, Failures: 1, PHPUnit Deprecations: 8, Skipped: 3`. The only reds are the known `ExampleTest::test_the_application_returns_a_successful_response` and, in this fresh worktree, `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`.

Other checks:
- `php -l` passes on every changed PHP file.
- `node --check` passes on the page's script.
- `artisan view:cache` compiles.

## Review

- **One adversarial review:** two majors, several minors. Then one scoped re-check, which confirmed both majors closed and found three more minors.
- **Major 1 (fixed):** `send_flow` was retried on a timeout or 5xx, so a customer could get 2–4 copies when Botcake accepted the first try late. Now only a 429 is retried on that step. A timeout, 5xx, 408 or 2xx without a clear success makes the row `unknown`.
- **Major 2 (fixed):** "Retry failed" re-queued rows that might already have been sent: a flow error, an exception after the flow, or a killed job. The 300 s timeout was also below the worst case, a Retry-After of up to 60 s × 3.
  - Steps are now saved before each call.
  - A killed job or an exception after the flow call makes the row `unknown`, which no action re-queues.
  - Retry-After is capped at 10 s for chatblast, so the worst case is about 270 s.
- **Minors fixed:**
  - An invalid token stops the page before any call.
  - Invalid UTF-8 in names or answers is substituted before it reaches the JSON columns.
  - Build counts stay exact when two builds run at the same time.
  - The daily cap counts `unknown` messages.
- **Accepted in `TODO.md` under 046, with reasons:**
  - The rate is shared by chatblast jobs only, not by the Botcake sync.
  - An `unknown` message cannot be resolved on the page.
  - The cap is counted, not reserved, which is safe with numprocs 1.
  - The J&T column uses `MAX(status)`.
  - Build counts can be off when two builds land in the same second.
  - Saving a template updates the rows one by one.

## Browser check

Not done. The app needs the server database (`.env`, not read), and this session has no running app or Botcake access. The page's script is checked by syntax and by reading only. To check on the server, before any real send:
- Press "Kunin ang mga bago" and look at the chips.
- Open a row's side panel.
- Pick your own Test PSID for a page and press "Send test" on one row. The message should arrive on your account through the page's utility template.
- Pause and Resume.
- Only then send real rows.

## After release (server)

1. `php artisan migrate --force` runs two migrations: the new `botcake_messages` table and the nav link.
2. Add the `chatblast` supervisor program above on Likha and on Incepxion, then `supervisorctl reread` and `supervisorctl update`.
3. `php artisan queue:restart`.
4. **Optional:** set `BOTCAKE_CHATBLAST_DAILY_CAP` (default 3000), then `php artisan config:cache` if the config is cached.
5. **No `npm run build`:** the page uses the Tailwind CDN.

## What the brief got wrong, and decisions it left open

- **"429/5xx: back off and retry (max 3)" is unsafe for `send_flow`.** A 5xx or a timeout can come after Botcake has accepted the flow, so retrying it can send the customer the same message several times. Only a 429 is retried there. An unsure outcome becomes the new status `unknown`, which no Send or Retry takes again. This adds a tenth status to the brief's list.
- **`page_id` is also required** for `ready`. The brief lists `token`, `flow.shipping` and `field.message`, but every API path needs the page id.
- **"Delivering"** is taken as the J&T status `Delivering` with no RTS reason, the rule the app already uses. A Delivering row that carries an RTS reason is left out.
- **The date filter** is the message's created date (when it entered the list), not the order's date.
- **"At most 2 requests/second per page token"** holds across chatblast jobs. The Botcake sync keeps its own 2 a second, so the two together can reach 4 a second on one token (in `TODO.md`).
- **Retry-After is capped at 10 s for chatblast** (the sync keeps 60 s), so one message always fits its job.
- **A template change** re-renders only rows not yet sent or queued, so a queued message's read-back always compares against the text it will send.
- **The Test PSID picker** shows only pages that already have messages in the list. Set a page's Test PSID after its first "Kunin ang mga bago", or type it in the new `Test PSID` column on /page-settings.
- **Conflict with `CLAUDE.local.md`:** the kit asks for a plan and a "go" before code. The brief asked for the plan file first and set every decision, so I wrote the plan (`docs/plans/046-chatblast.md`) and worked from it without waiting.

## Story changes

None. The brief brought no `qa/stories.md` stories. The cases above come from its Tests list.

## Amendment 1

### Amendment changes (kept in the handoff folder, not committed)

1. Days per kind (Araw 1, Araw 2, …), each day with one or more text versions, stored in `app_settings`, with an editor on /chatblast that has add/remove day, add/remove/edit version and a live preview. Defaults: `delivering` has 1 day (the brief's text); `in_transit` has 5 days with different, polite Tagalog texts and no emoji.
2. A new kind `in_transit`: J&T `In Transit`, at most one message per waybill per Manila day. The day is the number of messages already sent + 1. It stops when the days run out or the status changes. `delivering` stays one message per waybill.
3. Version rotation: deterministic, and different for neighbouring customers. Each row stores its day, version and text, shown as "Araw 3 · B" in the table and in the side panel.
4. Uniqueness is now (waybill, kind, day), and a second build the same day adds nothing.
5. `{rider}` / `{rider_phone}` are reserved and render empty. A version that uses them falls back to one that doesn't; if no version of the day can be used, the row is `no_config` with the reason "walang rider info".
6. Tests for all of the above.

Commits:
- `feat: chatblast days per kind with versions, an in_transit kind sent once a day until its days run out or the waybill moves on, rotating versions by waybill, rider placeholders with a fallback, and an editor with a live preview (spec 046 amendment 1)`
- `fix: chatblast sends at most one message of a kind per waybill per Manila day even when the row was built the day before, takes a waybill's most advanced J&T row as its status, never sends a removed day, and refuses unknown placeholders and oversized days (spec 046 amendment 1)`
- `fix: the day editor refuses any unknown placeholder and caps the stored size in bytes, and the same-day guard is tested for unsure and sending rows (spec 046 amendment 1)`
- `docs: accepted review findings for spec 046 amendment 1 in TODO`
- `docs: result for spec 046 amendment 1`

### What I changed in parts already built

- **Text:** the single template per kind (`chatblast.template.<kind>`, `POST /chatblast/template`) is replaced by days, stored as `chatblast.sequence.<kind>` (a JSON list of days, each a list of version texts) and saved with `POST /chatblast/sequence`. If no days are saved, the defaults in `Chatblast::KINDS` apply. The old template editor on the page is replaced by the day editor.
- **`botcake_messages`:**
  - two new columns, `day` (default 1) and `version` (A, B, …);
  - `dedupe_key` is now `waybill|kind|day`.
  - I added these to the create migration itself. It has not been released or run anywhere, so editing it breaks no rule.
- **"Kunin ang mga bago"** and `chatblast:build` build every kind when none is chosen. `--kind` / the kind filter limits it to one. The result also counts the rows skipped because their waybill moved on.
- **The list and the side panel** show `step` ("Araw 1 · B"), `day` and `version`.
- **Tests:** `ChatblastBuildTest` (the template test became a days test) and `ChatblastPageTest` (template → sequence endpoint) were adapted.

### How it works now

- **Kinds (`Chatblast::KINDS`):**
  - `delivering`: J&T `Delivering`, orders of the last 7 days, 1 day.
  - `in_transit`: J&T `In Transit`, orders of the last 14 days, 5 days.
- **A waybill's status:** for both kinds, the waybill must be at that status with no RTS reason. When a waybill has several `from_jnts` rows, its status is its **most advanced** row (In Transit < Delivering < Delivered / For Return / Returned; ties go to the latest row). A stale copy still saying "In Transit" can't keep a delivered waybill in transit.
- **Day number:** the waybill's real messages of that kind that are `sent` or `unknown` (possibly sent), + 1. Past the last day, nothing more is built.
- **One per day:**
  - **The build** adds nothing for a waybill and kind that already has a row created, sent or queued today (Manila), or an `unknown` updated today.
  - **The sender** refuses a real send (row `skipped`, "May naipadala nang mensahe … ngayong araw; ipadala bukas") when another row of the same waybill and kind was sent or `unknown` today, or is being sent. This catches a row built yesterday and sent today.
  - **An unsent day** is not built again the next day: its `waybill|kind|day` key exists. It stays in the list to be sent.
- **Stop on a status change:**
  - Each build marks the unsent rows of a waybill that moved on as `skipped`, with the reason, for example "Hindi na In Transit ang waybill (ngayon: Delivered)".
  - The sender checks again just before step 1 and skips such a row.
  - A day removed in the editor is never sent: the row becomes `skipped` with "Wala nang Araw N".
- **Version:**
  - The version index is the last 9 digits of the waybill, mod the number of usable versions; with no digits, `crc32` is used instead. Consecutive waybills therefore get different versions, and building again picks the same one.
  - **Without rider info**, only versions without `{rider}` / `{rider_phone}` are usable.
  - The row stores the version letter (its position in the day) and the final text.
- **Saving the days:**
  - Every unsent real row of the kind is re-rendered, keeping its day and version choice. Queued, sending, sent and unknown rows keep their text.
  - **Validation:**
    - 1–30 days, 1–10 versions per day.
    - Each text non-empty and at most 1,000 characters.
    - Every `{…}` must be a known placeholder: name, item, cod, waybill, page, rider, rider_phone.
    - All texts together at most 60,000 bytes (`app_settings.value` is TEXT).
    - Anything else → 422, and nothing is saved.
- **The editor:**
  - Per kind: a box per day with "+ bersyon", "alisin", "alisin ang araw", "+ Araw" and "I-save ang mga araw".
  - Under each version, a live preview with a sample order. It notes when a version needs rider info and whether the day will fall back or become "walang config".

### Tests (amendment 1)

| Case | Test | Result |
|---|---|---|
| 5 different default days; one row a day; numbered by the ones sent; nothing after Araw 5; dedupe `waybill|kind|day` | `ChatblastSequenceTest::test_one_in_transit_message_per_day_numbered_by_the_ones_sent_until_the_days_run_out` | pass |
| An unsent Araw 1 is not repeated the next day | `…::test_an_unsent_day_is_not_repeated_the_next_day` | pass |
| Status changes to Delivering: no Araw 2, the unsent row skipped with the new status, the delivering kind picks it up | `…::test_the_messages_stop_when_the_waybill_is_no_longer_in_transit` | pass |
| Sender skips a row whose waybill became Delivered (no HTTP) | `…::test_the_sender_skips_a_message_whose_waybill_changed_status_before_it_was_sent` | pass |
| Built yesterday, sent today: no Araw 2 today; an Araw 2 row is refused today; unknown or sending today also block | `…::test_a_day_sent_today_blocks_the_next_day_until_tomorrow_even_if_it_was_built_yesterday` | pass |
| A later `from_jnts` copy (Delivered / Delivering) ends In Transit, both in the build and in the sender | `…::test_a_waybill_with_a_later_jnt_row_is_no_longer_in_transit` | pass |
| Rotation: 6 consecutive waybills, neighbours differ, all 3 versions used, same versions after building again | `…::test_versions_rotate_across_neighbouring_waybills_and_a_rebuild_keeps_the_same_version` | pass |
| A waybill without digits gets the same version each time; very long digit strings use the last 9 | `…::test_a_waybill_without_digits_still_gets_the_same_version_every_time` | pass |
| Rider fallback to the version without rider; all versions need rider → no_config "walang rider info" | `…::test_rider_placeholders_fall_back_to_a_version_without_them_or_no_config` | pass |
| Editor saves; refuses no days, an empty day, blank, over 1,000, not a list, unknown kind; list row shows "Araw 1 · B" | `…::test_the_editor_saves_the_days_and_refuses_empty_ones` | pass |
| Editor refuses non-strings, nested lists, 31 days, 11 versions, `{nmae}`, `{Name}`, `{rider-phone}`, over 60,000 bytes; nothing saved | `…::test_the_editor_refuses_bad_shapes_unknown_placeholders_and_huge_payloads` | pass |
| A removed day is skipped and never sent; a queued row keeps its text | `…::test_a_removed_day_is_never_sent_and_queued_rows_keep_their_text` | pass |

Red runs:
- **First run:**
  - "Call to undefined method Chatblast::sequence()" and "…::setSequence()".
  - 404 on `/chatblast/sequence`.
  - "Call to a member function update() on null" (no in_transit rows).
- **Fix loop 1:**
  - "Failed asserting that 1 is identical to 0": an Araw 2 was built the same day.
  - "Requests were recorded" (twice): a sender with no status/day guard.
  - 200 instead of 422: no placeholder or size check.
- **Fix loop 2:** 200 instead of 422 for `{Name}`, `{rider-phone}` and the byte size.
- **Already green when written:**
  - the waybill-without-digits case;
  - the unknown and sending branches of the same-day guard.

  They pin behaviour the code already had.
- **One test mistake, corrected:** "Araw 1 · A" expected for `WB-001`, where the rotation (1 mod 2) picks B by design.

Full suite after amendment 1: `Tests: 1430, Assertions: 17155, Errors: 1, Failures: 1, PHPUnit Deprecations: 8, Skipped: 3`. The only reds are the known `ExampleTest` and, in this fresh worktree, `ImportStartTest`. `php -l`, `node --check` and `artisan view:cache` pass.

### Review (amendment 1)

- **One adversarial review:** two majors, then two scoped re-checks.
  - **Major (fixed):** an in_transit row built late one day and sent the next morning let that morning's build add the next day. The customer got two messages in one day.
  - **Major (fixed):** a duplicate `from_jnts` row still saying "In Transit" kept a delivered waybill getting in-transit messages.
- **Minors fixed:**
  - a removed day could still be sent by hand;
  - re-rendering touched test rows;
  - unknown or misspelled placeholders and oversized payloads were accepted;
  - two branches of the same-day guard had no tests.
- **Accepted in `TODO.md` (046, amendment 1):**
  - a skipped row keeps its day key;
  - saving re-renders row by row;
  - the preview uses a fixed sample.

### What the amendment left open, and decisions

- **Also counted:** `unknown` counts as sent for the day number and the one-a-day rule, because it may have been delivered. A customer may therefore get the next day's text even if the unsure one never arrived; never the same day twice.
- **"No RTS reason":** applies to `in_transit` too, so a waybill on its way back to the seller gets no "on its way to you" message.
- **The order window:** 14 days for `in_transit` (5 daily messages need a wider window than the 7 for `delivering`). Both are in `Chatblast::KINDS`.
- **Version letters** follow the version's position in the day. Removing version A re-letters the rest, and building again re-picks among the remaining versions for unsent rows.
- **A cap-skipped day** (daily cap) stays `skipped` and can be sent by hand the next day. The next day's build does not make a new row for it.

## Amendment 2

### Amendment changes (kept in the handoff folder, not committed)

1. **One message at a time per customer:**
   - a per page + PSID lock from the field write until 20 s after send_flow (config);
   - no second message of the same kind to the same page + PSID in a Manila day.
2. **No blank or partial text:**
   - a version whose placeholder has no value for the order is not used ("walang {x}");
   - `deliver()` refuses blank text, and text that still contains a known `{placeholder}`.
3. **Send only to the order's current PSID and page:** they are re-read right before the field write; on a change the row is skipped and the next build makes a fresh row.
4. **Minor 4:** the daily cap uses two indexed counts instead of an OR.
5. **Minor 6:** the Test PSID picker confirms with the full name and the last 4 digits of the PSID, and the header shows whose Test PSID the filtered page has.
6. **Minor 7:** the read-back matches on `id` or `custom_field_id`.
7. **Minors 5 and 8:** a lower id bound for the build's walk, and the counts kept off the 4 s poll.

Commits:
- `fix: chatblast sends one message at a time per customer with a 20 s gap and one of a kind per customer a day, never a text with an empty or unfilled placeholder, only to the order's current PSID and page, counts the cap on two indexes, reads back either field id, shows whose test PSID is set and polls without the counts (spec 046 amendment 2)`
- `fix: a chatblast row refused for its text stays refused after the next build, and a row left sending by a killed worker blocks its customer for 10 minutes at most (spec 046 amendment 2)`
- `docs: accepted review findings for spec 046 amendment 2 in TODO`
- `docs: result for spec 046 amendment 2`

### What changed

- **One customer, one message at a time (`ChatblastSender`).**
  - **The lock:** before the field write, the job takes a cache lock for the customer, `chatblast-customer:<sha1(page|psid)>`, for 300 s. That is longer than the slowest message.
  - **The gap:** after the three steps, whatever their outcome, it sets a `:gap` key for `BOTCAKE_CHATBLAST_CUSTOMER_GAP` seconds (default 20, added to `config/services.php` and `.env.example`), then releases the lock.
  - **Requeue:** a job that finds the lock taken or the gap still running puts its row back to `queued` without counting the attempt, and dispatches itself again after the gap plus 1 s. Pause still holds: a requeued job that wakes while paused leaves the row queued.
  - **Store:** the lock needs a cache store with locks. `database`, as in `.env.example`, works.
- **One of a kind per customer a day (`Chatblast::blockedToday`).** A real message is skipped with "may mensahe na ngayong araw sa customer na ito" when another real message of the same kind to the same page + PSID was sent or `unknown` today (Manila), or is being sent. A row counts as "being sent" only for 10 minutes, so a row left `sending` by a killed worker can't block the customer for good. This comes after the per-waybill check from amendment 1.
- **No blank or partial text.**
  - **Choosing a version:** a version is used only if every placeholder in it has a value for the order. A COD of 0 counts as empty, since nothing is to be paid.
  - **When no version fits:** the row is `no_config` with "walang rider info" (only rider data missing) or "walang {x}" (the first missing other placeholder, e.g. `walang {cod}`, `walang {name}`).
  - **Re-rendering after the days change** uses the same rule.
  - **`deliver()`** refuses a text that is blank or still contains a known placeholder: the row becomes `no_config` "hindi buo ang text: …". The next build leaves it refused, because it is treated as a text reason.
- **Current PSID and page.**
  - **The check:** right before the write, `deliver()` re-reads `botcake_psid` and `PAGE` of the row's order. If either is empty or differs (the page compared by its key), nothing is sent. The row becomes `skipped` with "nagbago ang PSID/page".
  - **A fresh row:** the skipped row's `dedupe_key` is cleared. The next build's one-a-day check ignores rows with no `dedupe_key`, so it makes a fresh row with the current PSID.
  - **Resending by hand:** the old row hits the same check again, so it cannot reach the old person.
- **Daily cap:** `count(sent today)` + `count(unknown today)`, each on its own index. New indexes on `botcake_messages`: `(page_name, status, sent_at)`, `(page_name, status, updated_at)`, `(page_name, psid, kind)`. They were added to the create migration, which is still unreleased.
- **Read-back:** a row matches when either its `id` or its `custom_field_id` equals the field id.
- **Test PSID:**
  - **The picker** shows the full Botcake name and the last 4 digits of the PSID, and asks "Gawing Test PSID ng … si "…" (PSID …1234)?" before saving.
  - **With a page filtered,** `/chatblast/list` returns `test_psid` {name, psid_last4}, and the header shows "Test PSID ng <page>: <name> (…1234)" or "Walang Test PSID ang <page>".
- **Poll:** `GET /chatblast/list?light=1` skips the grouped count. The page's 4 s poll uses it and fetches the counts every 30 s, and after every action or filter change.
- **Build:** the chunk walk starts at the lowest `macro_output.id` inside the window, one `MIN(id)` on the `ts_date` index, instead of id 1.

### Tests (amendment 2)

| Case | Test | Result |
|---|---|---|
| Lock taken: the row goes back to queued with a delayed retry and no call; once sent, a second message to the same customer waits for the 20 s gap and is sent after it | `ChatblastSafetyTest::test_one_message_at_a_time_per_customer_and_a_short_gap_after_each` | pass |
| Two in-transit orders of one customer: one message that day, the other skipped "may mensahe na ngayong araw sa customer na ito" | `…::test_a_customer_gets_one_message_of_a_kind_per_day_even_with_two_orders` | pass |
| COD null (other version used), COD 0 (`walang {cod}`), no name and no receiver (`walang {name}`), no rider phone (other version used) (4 rows) | `…::test_a_version_with_a_placeholder_without_a_value_is_never_used` | pass |
| Blank text and text with `{name}` are refused, nothing sent | `…::test_deliver_refuses_a_blank_or_unfilled_text` | pass |
| PSID changed: not sent, skipped "nagbago ang PSID/page", the next build makes a fresh row with the new PSID; page changed: not sent, skipped, key cleared | `…::test_a_changed_psid_or_page_is_not_messaged_and_the_next_build_makes_a_fresh_row` | pass |
| Read-back on `custom_field_id` when `id` differs | `…::test_the_read_back_matches_on_either_id_or_custom_field_id` | pass |
| Header shows the Test PSID owner for a page filter; `light=1` returns no counts | `…::test_the_header_shows_whose_test_psid_is_set_and_the_poll_can_skip_the_counts` | pass |
| Cap counts sent and unknown with no OR; the two indexes exist | `…::test_the_daily_cap_uses_the_two_indexed_counts` | pass |
| A row refused for its text stays refused after the next build | `…::test_a_row_refused_for_its_text_stays_refused_after_the_next_build` | pass |
| A row stuck in `sending` for 2 hours does not block the customer | `…::test_a_row_stuck_in_sending_for_long_does_not_block_the_customer` | pass |

Red runs:
- **First run:**
  - "Call to undefined method ChatblastSender::customerLockKey()".
  - "two strings are identical" (a second message the same day).
  - "'A' is identical to null" (COD 0, no name).
  - "Requests were recorded" (blank text, changed PSID).
  - "two strings are identical" (read-back).
  - "null is identical to Array" (header).
  - "walang OR sa bilang ng cap".
- **Fix loop:** "hindi bumabalik sa ready …" and "ang naiwang sending nang 2 oras …" failed on "two strings are identical".
- **Already green when written:** the "COD null, may ibang bersyon" and "walang rider phone" rows passed before the change, because the rotation happened to pick the other version. The COD 0 and no-name rows carry the red.

Full suite after amendment 2: `Tests: 1443, Assertions: 17200, Errors: 1, Failures: 1, PHPUnit Deprecations: 9, Skipped: 3`. The only reds are the known `ExampleTest` and, in this fresh worktree, `ImportStartTest`. `php -l`, `node --check` and `artisan view:cache` pass.

### Review (amendment 2)

- **One adversarial re-check:** no blockers or majors. It confirmed:
  - two messages to one customer cannot interleave: the lock outlives the job, the gap is set before the lock is released, and the requeue loop ends;
  - a cleared `dedupe_key` cannot cause a second send;
  - no path sends partial text;
  - Pause still holds across a requeue.
- **Minors fixed:**
  - a refused text row came back as `ready` on the next build;
  - a stuck `sending` row blocked the customer with no time limit;
  - the changed-page test now asserts the status, the reason and the cleared key.
- **Accepted in `TODO.md` (amendment 2):**
  - two same-kind rows for one customer claimed at the same moment are both skipped (fails safe);
  - a Facebook name containing a placeholder makes the text refused (fails safe);
  - the per-customer lock needs a cache store with locks.

### After release, added by amendment 2

- **Check the server's `CACHE_STORE`:** it must support locks (`database` with the `cache_locks` table, or redis). `file` works only with one server.
- **Optional:** set `BOTCAKE_CHATBLAST_CUSTOMER_GAP` (default 20).
- **One worker is enough:** a worker that meets a busy customer requeues and moves on to the next message, so numprocs 1 stays right.

## Amendment 3

### Amendment changes (kept in the handoff folder, not committed)

1. Remove amendment 2's rule "never two messages of the same kind to the same page+PSID in one Manila day", with its `skipped` reason and its test. The rule is per waybill: one message per waybill per kind per Manila day. A customer with two waybills gets one message for each, even of the same kind on the same day. The per page+PSID lock and the gap after send_flow stay, so the messages go one at a time and each carries its own text.

Commits:
- `fix: chatblast limits one message per waybill per kind a day, not per customer: two waybills of one customer both get their own message the same day, one after the other through the customer lock and gap (spec 046 amendment 3)`
- `docs: result for spec 046 amendment 3`

### What changed

- **Removed from `Chatblast::blockedToday()`:** the per-customer check and its reason "may mensahe na ngayong araw sa customer na ito". The per-waybill check (amendment 1) is unchanged, as is its 10-minute limit on `sending` rows.
- **Removed from the create migration:** the index `(page_name, psid, kind)`, which only served that check. The migration is still unreleased.
- **Kept in `ChatblastSender`:** the per page+PSID lock (300 s) and the `:gap` (`BOTCAKE_CHATBLAST_CUSTOMER_GAP`, default 20 s). A second message to the same customer goes back to the queue and is sent after the gap, never skipped.
- **Removed from `TODO.md`:** the amendment 2 entry about two same-customer rows both being skipped, which no longer applies.

### Tests (amendment 3)

| Case | Test | Result |
|---|---|---|
| Two waybills of one customer, same kind, same day: the first is sent, the second waits for the gap (back to queued) and is then sent. The field writes go in that order, each with its own text, in 6 requests | `ChatblastSafetyTest::test_two_waybills_of_one_customer_both_get_their_own_message_the_same_day_one_after_the_other` (replaces `test_a_customer_gets_one_message_of_a_kind_per_day_even_with_two_orders`) | pass |
| A row of the same waybill left in `sending` for 2 hours does not block its next day | `…::test_a_row_of_the_waybill_stuck_in_sending_for_long_does_not_block_its_next_day` (was the per-customer version) | pass |

- **Red run:** the new test failed first on "two arrays are identical": the second waybill was skipped.
- **Full suite after amendment 3:** `Tests: 1443, Assertions: 17202, Errors: 1, Failures: 1, PHPUnit Deprecations: 9, Skipped: 3`. The only reds are the known `ExampleTest` and, in this fresh worktree, `ImportStartTest`.
- **No separate review:** the change only removes a check, and the lock and gap it relies on were reviewed in amendment 2.
