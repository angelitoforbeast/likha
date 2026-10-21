# Result 109: Incepxion brought up to Likha's main (specs 066–105)

Branch `release/incepxion-port`, cut from `vps2/main` at `4a241ce0` (spec 065). Nothing was pushed, and no server was touched.

## Commits

| Commit | What |
|---|---|
| `3ac78aea` | docs: brief for the Incepxion port (spec 109) |
| `9f51029d` | chore: merge Likha main (specs 066-105) into the Incepxion line (spec 109). Merge commit, parents `3ac78aea` and `7234bd0a` |
| (this commit) | docs: result for the Incepxion port (spec 109) |

`git merge-base --is-ancestor 7234bd0a HEAD` returns true.

## Incepxion's own commits

Incepxion's two commits after the common base `20b92422` are the same patches Likha's main already holds under other ids. I compared the diffs line by line and they match exactly:

| Incepxion | Likha main | Spec |
|---|---|---|
| `f8f4fb19` | `4d7b90c8` | 064, message field name |
| `4a241ce0` | `5d288da9` | 065, chat setup variables |

**Incepxion has no code of its own.** After the merge, the tree is byte-identical to `7234bd0a` except for the two spec 109 files (`git diff 7234bd0a HEAD --stat` lists only `docs/specs/109-*`).

## Conflicts and how each was resolved

All four conflicts are in the spec 065 files. The cause: main applied 065 too, and later specs (067 chat endpoint security, 072, 083, 095 and others) rewrote the same lines. Each one takes **main's side**. Main contains 065's change, and nothing in them is specific to Incepxion.

| File | Incepxion side (065) | Main side | Resolution |
|---|---|---|---|
| `app/Http/Controllers/BotcakeChatController.php` | the `page()` lookup from `page_id` / `page` | the same `page()` lookup, now done earlier, with refusal of unknown pages, a check of each page's key, the bot-off switch and the PSID check; at this spot main cleans the message (`ChatMessageCleaner`) | main. The page lookup is still at main's line 115 |
| `app/Http/Controllers/ChatblastChatController.php` | `setup()` with one shared key and the 065 variables | `setup()` with a key per page, a list of pages and refusal counts, built on `BotcakeChatBlock::BODY` | main. `BODY` holds 065's `{{page_id}}`, `{{psid}}`, `{{user_full_name}}` and `{{last_text_input}}`, plus `source`, `ad_id` and `page_name` |
| `resources/views/chatblast/chats.blade.php` | the help text for "one block for every page" | the "Chat to site" block, written by `botcake:chat-setup` | main |
| `tests/Feature/Chatblast/BotcakeChatTest.php` | 065's tests | main's tests (an unknown page now gets 401) | main |

No Incepxion-only difference was kept, because there was none.

## Tests

- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake tests/Feature/AdAccount tests/Feature/OwnerPrivate`: **OK, 1057 tests, 7232 assertions**, 1 skipped (S-088.4's Redis data set: no Redis on this Windows machine), 50 PHPUnit deprecations.
- Full suite (`vendor/bin/phpunit`): **2219 tests, 22256 assertions, 1 failure, 1 error**, 4 skipped (3 Boardroom live tests, off by default; S-088.4's Redis data set), 50 PHPUnit deprecations. Both reds come from this machine, not the code, so they fail on Likha main too:
  - Failure: `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response`. Laravel's stock test expects 200 at `/`, and the app redirects guests to login (302).
  - Error: `Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. It needs `storage/app/credentials.json`, the Google service-account file, which is not in git and not in this worktree.
- No separate run on Likha main was needed. This tree's code is identical to `7234bd0a`, so any failure here would fail there too.

## Likha-specific grep

I searched the diff `4a241ce0..HEAD` (app, config, routes, database, resources, bootstrap) for `likhaaitech`, `likha`, `LDT`, IPv4 addresses, `spreadsheets/d/` and sheet-id-shaped strings, and listed the hard-coded name arrays (`const … = [`):

| Hit | Where | What it is | What I did |
|---|---|---|---|
| `likhaaitech.com` | none in code | — | — |
| LDT sheet id, any fixed sheet id | none. `docs.google.com/spreadsheets/d/` appears only as a URL builder and parser for sheet links stored per flow step, and as a placeholder | sheet links are DB data | nothing |
| Likha page names | none. Page names come from `page_settings` / `botcake_chats` rows | DB data | nothing |
| `203.171.22.6` | `config/services.php`, default of `BOTCAKE_CHAT_IPS` | Botcake's sending IP, not Likha's. The default mode is `log` (accept and count), not `enforce` | kept. Already env-driven; on Incepxion, set `BOTCAKE_CHAT_IPS` if Botcake sends from other addresses |
| `100.64.0.0/10`, `::ffff:10.0.0.1` | IP guard comment | network ranges | nothing |
| `'UPDATE SHIPPING STATUS 2026'`, `'message'`, `'post_order'` | `ChatblastSetupCheck::DEFAULTS` | default Botcake flow, field and template names for the nightly setup check, overridable per page or for all pages (`setup.flow_name`, `setup.field_name`, `setup.template_name` page settings) | kept. Per-business through DB rows. Incepxion sets them on `/chatblast/page-settings` if its names differ |
| `setApplicationName('Likha Chatbot')` | `app/Services/Chatbot/ChatbotSheet.php:30` | the label sent to the Google API client | kept (no product change). Suggestion: `config('app.name')` |
| "Share it with the Likha service account as Editor" | `app/Services/Chatbot/TestOutbox.php:44` | fallback text, shown only when no service-account email is known | kept (no product change). Suggestion: neutral wording |
| "Likha" in Taglish comments (`AutoChatblast`, `JntTrackSyncAuto`, nav, icons) | comments | describe Likha versus Incepxion behaviour | nothing |
| `deploy/supervisor/likha-*.conf`, `deploy/redis/likha-*.conf` | server files | program names and paths `/var/www/likha`, `/var/log/supervisor/likha-*.log`, `requirepass CHANGE_ME_ON_THE_SERVER` | kept. On Incepxion, copy them with the right `command` / `directory` path and a new password. Don't use them as they are |
| `LIKE_FILES` / `LIKE_IDS` | `ChatMessageCleaner` | Facebook's own like-sticker ids | nothing |

## Migrations that will run on Incepxion

The last migration at `4a241ce0` is `2026_10_20_100000_create_botcake_chats_table`. These 17 are new. Each one guards with `Schema::hasTable` / `hasColumn`, and every table they alter already exists at `4a241ce0`.

| # | Migration | Tables / columns | Data backfill | Safe without the chatbot |
|---|---|---|---|---|
| 1 | `2026_10_18_510000_add_deadline_time_to_checklist_tasks` | `checklist_tasks.deadline_time` (string, nullable) | none | yes |
| 2 | `2026_10_18_510100_add_is_override_to_checklist_analysis_logs` | `checklist_analysis_logs.is_override` (bool, default false) | none | yes |
| 3 | `2026_10_21_100000_create_jnt_latest_scans_table` | new `jnt_latest_scans`; `jnt_track_sync_runs.kind` (nullable, indexed) | none | yes |
| 4 | `2026_10_22_100000_widen_source_on_botcake_psids` | `botcake_psids.source` widened to string(20), default `sheet` (`->change()`) | none | yes |
| 5 | `2026_10_23_100000_create_chatbot_tables` | new `chatbot_flows`, `chatbot_customer_values`, `chatbot_replies` | none | yes, new tables |
| 6 | `2026_10_24_100000_add_draft_to_chatbot_flows` | `chatbot_flows.draft_graph`, `draft_version`, `published_at`, `published_by`; new `chatbot_flow_versions` | `published_at` set on existing flows that have a graph (none on Incepxion) | yes |
| 7 | `2026_10_24_100000_add_inbox_indexes` | index `(page_id, psid, id)` on `botcake_chats` and `chatbot_replies` | none | yes. On a large `botcake_chats`, building the index takes a moment (the table has existed since Oct 20) |
| 8 | `2026_10_24_100100_create_chatbot_events_and_reply_user` | new `chatbot_events`; `chatbot_replies.user_id` | none | yes |
| 9 | `2026_10_25_100000_create_chatbot_waits_table` | new `chatbot_waits` | none | yes |
| 10 | `2026_10_26_100000_create_chatbot_sequence_tables` | new `chatbot_sequences`, `chatbot_sequence_steps` (FK to `chatbot_flows`), `chatbot_sequence_subscriptions` | none | yes, `chatbot_flows` is created in #5 |
| 11 | `2026_10_27_100000_add_chatbot_nav_link` | — | **inserts** a `nav_links` row `chatbot` (`/chatbot`) right next to `chatblast`, copying chatblast's role visibility (or CEO only) | yes. Skipped if the row exists |
| 12 | `2026_10_28_100000_create_chatbot_ai_requests_table` | new `chatbot_ai_requests` | none | yes |
| 13 | `2026_10_29_100000_create_botcake_send_requests_table` | new `botcake_send_requests` | none | yes |
| 14 | `2026_10_30_098000_add_raw_to_chatbot_replies` | `chatbot_replies.raw` (text, nullable) | none | yes |
| 15 | `2026_10_30_100000_add_results_to_chatbot_replies` | `chatbot_replies.flow_version_id`, `sequence_id`, `ad_id`, `tags`, `replied_at`, `reply_seconds` plus 3 indexes; new `chatbot_reply_tags`; index `(type, created_at)` on `chatbot_events` | none | yes |
| 16 | `2026_10_31_100000_add_history_to_ad_accounts_table` | **drops the unique index on `ad_accounts.ad_account_id`**; adds `created_by`, `active` (default true), `replaced_at`, `replaced_by`, `action`, index `(ad_account_id, active)` | existing rows become `active = true`, `created_by = NULL` (through defaults) | yes. Needs the unique index from `2025_10_19_154416_create_ad_accounts_table`, which is on Incepxion. Not reversible if duplicate ids get added later |
| 17 | `2026_11_01_100000_add_source_to_page_item_settings_log` | `page_item_settings_log.source` (nullable, indexed) | none | yes |

Ordering note: #1 and #2 are dated before the last migration already on Incepxion. Laravel runs every pending migration in name order, so they still run, first.

## Server prerequisites

The chatbot can be tested **without Redis**. `CHATBOT_QUEUE_CONNECTION` and `CHATBLAST_QUEUE_CONNECTION` default to `QUEUE_CONNECTION` (`database`), and the async AI and Botcake sends fall back to blocking calls when no pool is alive (heartbeat). Redis and Horizon are the speed-up (specs 088, 102), not a requirement.

**Needed for the chatbot to work:**

1. **Composer:** new packages `laravel/horizon` ^5.50 (pulls `laravel/sentinel`) and `predis/predis` ^3.7. Run `composer install` on deploy. Horizon needs `ext-pcntl` and `ext-posix` in the CLI PHP; I did not check Incepxion.
2. **A worker for queue `chatbot`** (`queue:work --queue=chatbot` on `database`, under supervisor), plus the existing ones (`default`, `chatblast`, astra, J&T). The flow runs only from that queue (`RunChatbotFlow`, `MarkChatbotReplied`, resume jobs).
3. **The scheduler: `* * * * * php artisan schedule:run` in cron.** Spec 061's result says Incepxion has **no scheduler**, only `chatblast:auto` in cron every 5 minutes. The chatbot's waits (`chatbot:resume-skipped`) and sequences (`chatbot:sequence-tick`) need it every minute. **Owner decision:** turning on `schedule:run` also starts everything else in `routes/console.php` on Incepxion:
   - `holds:snapshot`, `night:import …`, `night:astra-tick`, `supply:refresh-primary-items`, `staff-events:prune`
   - `chatbot:resume-skipped`, `chatbot:sequence-tick`, `chatbot:replied-backfill`
   - `botcake:setup-check --all` (21:30), `botcake:setup-delivered`, `botcake:prune-chats`, `botcake:psid-import`, `botcake:sync-customers`
   - `chatblast:auto` (then remove the separate cron line; the command claims each campaign hour under a row lock, but two triggers are still redundant), `horizon:snapshot`, `jnt:track-sync-auto`

   The other way: add cron lines for just the three chatbot commands.
4. **`OPENAI_API_KEY`** for the AI steps (an existing key, already used for card reconcile).
5. **Google service account** (`storage/app/credentials.json`, the existing Google client) for the chatbot's Sheet steps, if they are used. Each sheet is shared with that account.
6. **Botcake per page:** each page's Default Reply gets the "Chat to site" block with its own key (`php artisan botcake:chat-setup --all --dry-run`, then `--all --confirm`). `BOTCAKE_CHAT_SHARED_KEY_OK` (default true) keeps the old shared key working until then.

**Optional, for speed (Likha's setup):**

7. **Redis**, two instances: `deploy/redis/likha-redis.conf` on 6379 for the queue and locks (`noeviction`, `appendonly yes`, 1 GB) and `deploy/redis/likha-redis-cache.conf` on 6380 for cache data (`allkeys-lru`, no persistence, 512 MB). Both bind to 127.0.0.1 and need a new password each.
8. **Supervisor programs** (copy from `deploy/supervisor/` with Incepxion's path):
   - `horizon`: replaces the chatbot and chatblast database workers, only after the switch to Redis. Supervisors `chatbot` (2–`HORIZON_CHATBOT_MAX`, timeout 300) and `chatblast` (`chatblast-high` then `chatblast`, 2–`HORIZON_CHATBLAST_MAX`)
   - `chatbot:ai-pool --max-time=3600`, `numprocs=1`
   - `botcake:send-pool --max-time=3600`, `numprocs=1`
   - the `default`, astra and J&T queues stay on database workers
9. **Deploy hook** after each deploy: `php artisan horizon:terminate` (if Horizon runs) and `supervisorctl restart` of both pools and the queue workers, **after** `migrate --force`.
10. **nginx / PHP-FPM:** results 066–105 recorded no change. Earlier results expect nginx's 60 s `fastcgi_read_timeout` and 100–200 MB upload limits; 073 suggests a `client_max_body_size` above 1 MB. The `/horizon` dashboard is CEO only, so no nginx rule is needed.

## Env keys (names only)

New in 066–105. All have defaults, and none must be set for a database-queue test:

- Queue / Redis: `QUEUE_CONNECTION`, `CHATBOT_QUEUE_CONNECTION`, `CHATBLAST_QUEUE_CONNECTION`, `CACHE_STORE`, `REDIS_CLIENT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_URL`, `REDIS_CACHE_HOST`, `REDIS_CACHE_PORT`, `REDIS_CACHE_PASSWORD`, `REDIS_CACHE_URL`, `REDIS_QUEUE_BLOCK_FOR`, `REDIS_QUEUE_RETRY_AFTER`, `REDIS_READ_WRITE_TIMEOUT`
- Horizon: `HORIZON_CHATBOT_MAX`, `HORIZON_CHATBLAST_MAX`, `HORIZON_NAME`, `HORIZON_DOMAIN`, `HORIZON_PATH` (the Redis prefix comes from `APP_NAME`)
- Chatbot: `CHATBOT_REPLIES_ENABLED`, `CHATBOT_AI_ASYNC`, `CHATBOT_AI_CONCURRENCY`, `CHATBOT_POOL_IDLE_MS`, `CHATBOT_POOL_BUSY_MS`, `OPENAI_TIMEOUT` (`OPENAI_API_KEY` already existed)
- Botcake: `BOTCAKE_ASYNC_SENDS`, `BOTCAKE_RATE_PER_PAGE`, `BOTCAKE_SEND_CONCURRENCY`, `BOTCAKE_CHAT_IPS`, `BOTCAKE_CHAT_IP_MODE`, `BOTCAKE_CHAT_SHARED_KEY_OK`, `BOTCAKE_CHAT_VERIFY_PER_MINUTE`
- J&T: `JNT_TRACK_SYNC_AUTO` (default off; can also be turned on at `/jnt/track-sync`)

## Story changes

None. This is a port with no product change, so it has no stories or cases.

## Conflicts with CLAUDE.local.md

The brief asked for a branch cut from `vps2/main`, not from `develop`, and named it `release/…`. I followed the brief. Merging and deploying stay with the reviewer.

## Suggestions for the owner (not done; no product change)

- `ChatbotSheet` application name and the `TestOutbox` fallback text say "Likha". Use `config('app.name')` or neutral wording.
- Rename the `deploy/` files with the business name taken from a variable, or add Incepxion copies.
