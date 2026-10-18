# Result 068: `/chatbot`, the page list for our own chatbot

Branch `feat/068-chatbot-pages`, cut from `main` at dbdfb0dc. Not pushed. No real Botcake call was made: the tests use `Http::fake`, and the browser check never clicked On/Off or submitted "+ Page".

## Commits

| Commit | What |
|---|---|
| `6b0906e8` | docs: the brief, verbatim |
| `06c08c0e` | refactor: the Chat to site block, key and publish move from `botcake:chat-setup` into `BotcakeChatBlock` |
| `241966bb` | feat: `/chatbot` (list, On/Off, FLOW and botfields, + Column, + Page, chats today); the chat endpoint refuses "bot off" |
| `5ff75633` | fix (review): ACTIVE is a system key; "+ Page" refuses a Botcake ID another page uses; "+ Column" is capped and locked; previews send no referrer; `--all` skips pages that are Off |
| `4c4b2016` | fix (re-check): Page settings cannot deactivate `chatbot.active` either |
| (this commit) | docs: result, stories, TODO, screenshots |

## Routes

All routes sit in the `web`, `auth` and `allowed_ip` middleware groups. Signed out gets 302 to the login page. Without `/chatblast` access (NavAccess) every route gives 403.

| Method | Path | Who | What |
|---|---|---|---|
| GET | `/chatbot` | /chatblast access | The page |
| GET | `/chatbot/data` | /chatblast access | Columns and rows as JSON, with no token or key |
| POST | `/chatbot/cell` | /chatblast access | Save one botfield (blank clears it); throttle 120/min |
| POST | `/chatbot/column` | /chatblast access | "+ Column"; throttle 30/min |
| POST | `/chatbot/active` | CEO | On or Off; throttle 30/min |
| POST | `/chatbot/pages` | CEO | "+ Page"; throttle 20/min |

There is a new "Chatbot" item in the Chatblast nav, after Chats.

## The service I extracted: `App\Services\BotcakeChatBlock`

- **`turnOn($page, $rotate = false)`:**
  - The key is created when missing, or rotated.
  - The exact "Chat to site" block from spec 067 is written (`default_reply/content`). Only then is the key saved.
  - It publishes with `unlimit_default_reply: true`, then sets `chatbot.active = 1`.
  - It returns `ok`, `key` (create / rotate / keep), `step` (setup / write / publish) and an `error` in plain English, for example `Could not write the block: Botcake said "…" (HTTP 200).`
- **`turnOff($page)`:** sends only `{"update":{"is_published":false}}`, then sets `chatbot.active = 0`. The block and the key are kept.
- **`block($key)` and `BODY`:** the 067 block and body.

`botcake:chat-setup` now calls `turnOn` and keeps its 067 output and tests. `--all` now skips pages switched Off and prints "bot is Off, skipped".

## Storage

- **Botfields:** in `page_settings`, provider `chatbot`, one key per botfield. ACTIVE is `active` (`1`/`0`) and FLOW is `flow`.
- **`chatbot.active` is a system key.** Only the switch writes it (through `BotcakeChatBlock`); Page settings refuses it with 422.
- **Columns:** the defaults in the sheet's order, plus the columns added with "+ Column", plus any key already used under provider `chatbot`.
  - The added columns are one `app_settings` row (`chatbot.columns`, JSON key → label). A new column has no value anywhere, so `page_settings` alone can't hold it; `app_settings` is an existing table.
  - At most 200 columns can be added. The read and write happen inside a transaction that locks the row.
- **Column kinds:** `picture` and `seq_img_N` are images (http/https links only, with a small preview). `prompt_N` and `seq_N` open the modal editor. The rest are text.
- **"+ Page"** saves `botcake.page_id` and `botcake.token` (secret, encrypted) with `PageSettings::set`, as Page settings does, then runs the same token test (`BotcakePageSetup::check`).
  - From an existing page, only the missing value is needed.
  - It refuses a Botcake ID that another page already uses, and a page that is already on the list.
- **Chats today and last chat** are per Botcake page ID and cached for 1 minute. "Today" is today's rows, by the `received_at` index. "Last" is one MAX per page, by the `(page_id, received_at)` index.

## Bot off on the chat endpoint

After the key is matched to the page (step 6 of 067), a page whose `chatbot.active` is `0` is answered `{"ok":true}` and nothing is kept. It is counted as the new reason "Bot off", which also shows in the Chats setup box. A page with no `active` row counts as On.

## Case table

All in `tests/Feature/Chatblast/ChatbotPagesTest.php`, except S-068.8, which is in `BotcakeChatSecurityTest.php`. Every case passed on 2026-10-19.

| Case | Test | Result |
|---|---|---|
| S-068.1 | `test_S_068_1_the_list_shows_pages_with_a_botcake_id_and_token_with_their_chats_and_access_rules` | pass |
| S-068.2 | `test_S_068_2_the_ceo_adds_a_page_manually_or_from_an_existing_page` | pass |
| S-068.3 | `test_S_068_3_switching_on_writes_the_block_then_publishes_and_marks_the_page_on` | pass |
| S-068.4 | `test_S_068_4_switching_off_only_unpublishes_and_keeps_the_block_and_key` | pass |
| S-068.5 | `test_S_068_5_a_botcake_error_is_shown_on_the_row_and_changes_nothing` | pass |
| S-068.6 | `test_S_068_6_a_botfield_edit_writes_one_row_and_checks_its_value` | pass |
| S-068.7 | `test_S_068_7_a_new_column_shows_for_every_page` | pass |
| S-068.8 | `BotcakeChatSecurityTest::test_S_068_8_a_page_with_the_bot_off_keeps_no_chats_and_is_counted` | pass |
| S-068.9 | `test_S_068_9_only_the_switch_changes_active_and_writes_need_chatblast_access` | pass |
| S-068.10 | `test_S_068_10_a_page_cannot_take_a_botcake_id_another_page_uses_or_join_twice` | pass |
| S-068.11 | `test_S_068_11_on_that_fails_at_publish_keeps_the_new_key_and_says_so` | pass |
| S-068.12 | `test_S_068_12_values_columns_and_names_have_limits_and_a_column_never_reads_botcake_settings` | pass |
| S-068.13 | `test_S_068_13_setup_all_leaves_pages_that_are_switched_off` | pass |

**Red runs:**
- **First implementation:** S-068.1 to S-068.8 all failed (6 × 404, the route didn't exist; S-068.8 kept the chat).
- **Fix loop:**
  - S-068.9: Page settings accepted `chatbot.active` (200).
  - S-068.10: 200 instead of 422.
  - S-068.12: `ChatbotFields::MAX_ADDED` didn't exist.
  - S-068.13: Page Dos was republished.
  - S-068.11 passed at once: it pins behaviour the code already had.

The 067 command tests (S-067.8, .9, .10, .15) pass unchanged after the refactor. `php artisan test tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes (3243 assertions). The only warnings come from the environment: this worktree has no env file.

## Full suite

`php artisan test` on `4c4b2016` (the final code): **2 failed, 175 passed, 1667 passed with warnings** (19789 assertions, 317 s). The only failures are the known `ExampleTest` and `NightRun\ImportStartTest`, so there are no new failures. The warnings come from the environment: the worktree has no env file.

An earlier run, on `5ff75633`, also failed `NightRun\AstraReplayCommandTest` S-31.3 ("changes no table and no file"). It passes alone and in the final run. It ran at the same time as the reviewer's test run, which wrote files during that window.

## Browser check

This session had no browser tools, and the `browser-checker` agent had none either. Like spec 056, I drove headless Chrome over CDP with a Node script outside the repo, against a scratch app:
- `php artisan serve` on 127.0.0.1:8068, with a scratch sqlite file under `%TEMP%`;
- 12 made-up pages, one with a very long name, with fake tokens;
- a session minted for a scratch CEO.

| Check | 1366 × 768 | 390 × 844 |
|---|---|---|
| No page-level horizontal scroll (`scrollWidth <= clientWidth`) | 1366 ≤ 1366 ✔ | 390 ≤ 390 ✔ |
| The table box scrolls on its own (scroll width vs client width) | 10448 vs 1301 ✔ | 10576 vs 356 ✔ |
| Header sticky after scrolling the box down (offset from the box top) | 1 px ✔ | 1 px ✔ |
| Left columns after scrolling 900 px right (offsets of Page, Active, Flow) | 1, 193, 289 px: all three sticky ✔ | 1, −771, −675: only Page sticky ✔ |
| Chatbot nav item active | ✔ | ✔ |
| No token text on the page | ✔ | ✔ |
| Image preview shown | ✔ | ✔ |

**Interactions at 1366 px:**
- **PRICE:** edited and saved with Enter; it shows 777 after a reload.
- **Prompt 1:** the modal editor shows "Saved" while typing, closes with Escape, and keeps the new text after a reload. The first scripted try failed because of a string-escaping bug in my script, not in the page; the second run passed.
- **"+ Column":** "Promo Text" appears as the last column for every row.
- **Filters:** "beauty" leaves 1 row; Bot = Off leaves only Off rows.
- **"+ Page":** the dialog opens and closes with Escape. It was not submitted.

Screenshots are in `docs/specs/068-chatbot-pages/`:
- `desktop.png`: the page at 1366 px;
- `desktop-scrolled.png`: the box scrolled right and down, with the sticky header and left columns;
- `long-editor.png`: the modal editor;
- `phone.png` and `phone-scrolled.png`: 390 px, with only the Page column sticky.

**Finding (minor, in `TODO.md`):** at phone width the page itself scrolls a little vertically, so the filters can scroll out of view. Nothing scrolls sideways.

**Not done:** walking the page by keyboard only, by hand. Cells are focusable and open with Enter, and the dialogs close with Escape.

The scratch server was stopped. Its gitignored session and cache files and `%TEMP%\cb068` were left in place, because the project guard blocks deleting files from the session (`TODO.md`).

## Review

`skeptic-reviewer`, adversarial depth on opus, on `6b0906e8..241966bb`. It found 3 majors, all fixed in `5ff75633`:
- **A non-CEO could switch the bot Off through Page settings.** `chatbot.active` wasn't a system key, so a role with Page settings access could write it there and silently stop a page's chats. It is now a system key that only the switch writes (S-068.9).
- **"+ Page" could take a live page's Botcake ID.** The new page then took over that page's chats, and they were refused as a bad key and lost. It now refuses an ID that another page uses (S-068.10).
- **Missing test:** there was no 403 test for `/chatbot/cell` and `/chatbot/column` without access. Added (S-068.9).

Minors:
- **Fixed in the same loop:**
  - "+ Column" is capped at 200 and locked;
  - previews send no referrer;
  - `--all` no longer turns back On the pages the CEO switched Off;
  - tests now cover publish failing, the 10,000-character cap, and a "Token" column.
- **Accepted in `TODO.md`:**
  - the phone vertical scroll;
  - no way to remove a column;
  - auto-save history rows;
  - image loads in the viewer's browser;
  - the "today" count at 1M chats a day;
  - the sticky offsets with a long error;
  - concurrency between two tabs or the command;
  - the `role()` helper name.

### Re-check

The re-check of `5ff75633` found **no new or open blocker or major.** All three majors are closed. It raised two new minors:
- **Page settings' deactivate action could remove `chatbot.active`.** A non-CEO could make an Off page look On, and `--all` would then republish it. Fixed in `4c4b2016`: deactivate refuses `chatbot.active`. Pinned in S-068.9, which was red first.
- **The duplicate Botcake ID check compares stored text,** so a `page_id` someone marked secret would slip past it. Trusted-only; accepted in `TODO.md`.

The last fix was not re-checked by the reviewer: it is a one-line guard pinned by its test, and two loops is the limit.

## Story changes

- **S-068.9 to S-068.13:** added from the review.
- **`ChatblastToSendTest::test_S_056_18`:** its expected nav list now includes "Chatbot".
- **Existing tests that set `chatbot.active`:** they now pass `system: true`, because it is a system key.

## What the brief got wrong, or left open

- **"The list of botfield columns is the set of keys in use":** a column added with "+ Column" has no value on any page yet, so it isn't "in use". The added columns are kept in one `app_settings` row, an existing table, not a new one.
- **"Sticky top bar, filters and table header":** the shared Chatblast layout has no sticky parts, and the house rule was "no sticky UI". So only the table box scrolls, and its header and left columns stick inside it. On desktop the page doesn't scroll, so the top bar and filters stay in view. On phone the page scrolls a little vertically.
- **"403 without access":** done, though `/chatblast` gives 404 in the same case.
- **"From an existing page":** read as a page in page settings that lacks a Botcake ID or token. The form takes the missing values; the list itself is "pages with a Botcake ID and token".
- **"Bot off refuses chats":** the chat is answered `{"ok":true}` (like the other refusals after the key check), not stored, and counted as "Bot off".
- **"A Botcake error … changes nothing locally":** if On fails at the publish step, the new key is still saved, because the block written on Botcake already holds it. ACTIVE doesn't change (S-068.11).
- **On now also writes `chatbot.active = 1`**, from the switch and from the command. So `botcake:chat-setup` marks pages On, and `--all` skips pages that are Off.
- **No HTTP 0 ("Botcake did not answer") test for On/Off.** The text comes from the same `error()` that S-068.5 pins.

## Merge danger

- **Low to medium.** No migration.
- **The chat endpoint gains one query per accepted chat** (the bot-off check).
- **`chatbot.active` becomes read-only in Page settings.**
- **Running On** from `/chatbot` writes the live Botcake Default Reply, as the command does. It is CEO-only and asks for confirmation first.
