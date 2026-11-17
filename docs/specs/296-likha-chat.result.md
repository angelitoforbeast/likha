# 296 result: Likha Chat

Base: live 8164024f. Branch `feat/296-likha-chat`.

## Plan

Base 8164024f: `composer install` (with `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`, Windows), full suite once with `vendor/bin/phpunit` under Herd PHP; base failures listed under Evidence.

Files (all new unless marked):
- Migrations `2026_11_27_296000..296300`: `chat_conversations`, `chat_members`, `chat_messages`, and the `Chat` nav link (every role in `nav_link_role_visibility` plus the five named; `section = 'Chat'` when the column exists).
- Models `ChatConversation`, `ChatMember`, `ChatMessage` (soft deletes on conversation and message).
- `App\Services\Chat\ChatAccess` (CEO / OIC, member, admin, may read, may post, may manage), `ChatPeople` (active people, names, initials), `ChatText` (escape + clickable http(s) links + line breaks), `ChatPresenter` (list rows and message JSON).
- `ChatController` (page, list, people search, open direct, thread, poll, send, remove, read, unread, file) and `ChatGroupController` (create, rename, add, remove, leave, join).
- `routes/web.php` (changed: one block, `['web','auth']`, prefix `chat`, no `allowed_ip`), `config/chat.php` (AI member names, limits).
- View `resources/views/chat/index.blade.php` in `<x-layout>`; vanilla JS + the Tailwind CDN the layout already loads; no layout or nav edit.
- Tests `tests/Feature/Chat/*` on sqlite, the chat migrations themselves run in the test case.

Order (test first, a commit per step): tables + models + access; CH-1 list / direct / people; groups (create, rename, add, remove, leave, CEO join); CH-2 send, limits, escaping, uploads, files, reply, remove, seen; CH-3 poll, read, unread; CH-4 CEO; CH-5 AI rendering; 403 on every route for a non-member; nav link migration; the view; screenshots at 390 / 1280 on a local seeded sqlite `artisan serve`; impeccable critique + audit and fixes; full suite against base; result; push.

Design: brief at `docs/design/296-likha-chat/brief.md` (impeccable shape, Operate mode, Likha look, one accent blue-600).

## What shipped

`/chat`, Likha Chat: the team's own messenger, behind `auth` only (no office IP check).

- **List** (CH-1): your conversations, newest activity first, with initials avatar (circle = person, rounded square = group), name, one-line last message ("You: …" / "Ana Cruz: …"), time and unread count; a filter box; "New chat" (people search; "New group" with a name and checked people for the CEO and OICs).
- **Thread** (CH-2): oldest to newest; your own on the right in the accent blue; others on the left with name and initials; day separators; time on hover / focus / tap; text (Enter sends, Shift+Enter new line, 1-4,000 characters), up to 5 photos or files of 10 MB each (photos inline with a full-size viewer, files with name and size), reply (quoted above), remove your own ("Message removed", row kept), clickable http(s) links, "Seen by" initials under your last message; system lines ("Ana created the group", "Ben left the group", "Rosa joined").
- **Groups**: create (CEO, OICs), rename / add / remove (the group's admins = creator, and the CEO in every group), leave (any member). Someone who left or was removed sees the conversation up to that moment, "You left this group", and nothing new.
- **Live enough** (CH-3): one small poll every 3 s (`/chat/poll`: list rows changed since the last poll, new messages after the last id, removals, Seen by, unread total), paused while the tab is hidden, backing off on errors with "Reconnecting…". Opening a chat (and every poll that brings new messages into it) marks it read. `GET /chat/unread` gives the total for a future nav badge.
- **Owner** (CH-4): "All conversations" filter for the CEO (rows she is not in carry "Reading"); she reads any conversation without becoming a member; to post in a group she presses Join ("Rosa Villanueva joined"). Every thread header (and the empty pane) says "The owner can read every conversation in Likha Chat".
- **AI members** (CH-5): a message with `sender_ai` shows the AI's name (from `config/chat.php`, else the key in words) with an amber "AI" mark. No route posts as an AI.
- **Phone first** (CH-6): one column under 1024 px (list, then thread with a back arrow; the browser Back button also returns to the list), side by side above. Empty states for no chats and an empty thread. The page is a fixed panel under the existing nav; the layout and nav are untouched.

### Files
- New: `app/Http/Controllers/ChatController.php`, `ChatGroupController.php`; `app/Services/Chat/ChatAccess.php`, `ChatPeople.php`, `ChatPresenter.php`, `ChatText.php`; `app/Models/ChatConversation.php`, `ChatMember.php`, `ChatMessage.php`; `config/chat.php`; `resources/views/chat/index.blade.php`; 4 migrations; `tests/Feature/Chat/*` (8 files).
- Changed: `routes/web.php` (one block appended at the end).
- Design: `docs/design/296-likha-chat/` (brief, review, 8 screenshots).

### Migrations
| File | What |
|---|---|
| `2026_11_27_296000_create_chat_conversations_table` | `chat_conversations`: kind (direct/group), title (80), `direct_key` (unique, direct chats only), created_by, last_message_at, timestamps, soft deletes; index `last_message_at` |
| `2026_11_27_296100_create_chat_members_table` | `chat_members`: conversation_id, user_id, role (member/admin), last_read_message_id, joined_at, left_at, timestamps; unique (conversation_id, user_id); index (user_id, conversation_id) |
| `2026_11_27_296200_create_chat_messages_table` | `chat_messages`: conversation_id, sender_user_id (null), sender_ai (null, 40), body (text, null), reply_to_id, attachments (json), timestamps, soft deletes; index (conversation_id, id) |
| `2026_11_27_296300_add_chat_nav_link` | nav link `chat` → `/chat` ("Chat", `fa-comment-dots`, active pattern `chat`, last in the menu), visible to every role found in `nav_link_role_visibility` plus CEO, Marketing - OIC, Marketing, Data Encoder - OIC, Data Encoder; `section = 'Chat'` when that column exists. Idempotent; `down()` removes it |

All four are additive (new tables, one inserted row set). None was run against any server.

### Routes (all `['web','auth']`, prefix `chat`, names `chat.*`)
| Method | Path | Who |
|---|---|---|
| GET | `/chat` | any signed-in person (page; `?c=<id>` opens a chat) |
| GET | `/chat/conversations` | own list; `?all=1` honoured for the CEO only |
| GET | `/chat/people?q=` | active employees (as the staff feed defines it), not yourself |
| GET | `/chat/unread` | own total |
| GET | `/chat/poll?since=&c=&after=&all=` | list rows for yourself; `c` = member or CEO, else 403 (throttle 120/min) |
| POST | `/chat/direct` | any signed-in person, with an active employee (one per pair) |
| POST | `/chat/groups` | CEO or OIC, else 403 |
| GET | `/chat/{c}` (`?before=`) | member (incl. former, up to leaving) or CEO, else 403 |
| PATCH | `/chat/{c}` | group admin or CEO (rename) |
| POST | `/chat/{c}/messages` | active member only (CEO must join) (throttle 60/min) |
| DELETE | `/chat/{c}/messages/{m}` | the sender, while an active member |
| GET | `/chat/{c}/messages/{m}/files/{i}` | member or CEO |
| POST | `/chat/{c}/read` | member or CEO (no-op for a non-member) |
| POST | `/chat/{c}/members`, DELETE `/chat/{c}/members/{user}` | group admin or CEO |
| POST | `/chat/{c}/leave` | group member |
| POST | `/chat/{c}/join` | CEO, groups only |

### File storage rule
Same disk and same type checks as checklist uploads (`ChecklistController::submit`, type "any"): the `public` disk, types jpg/jpeg/png/gif/webp/pdf/doc/docx/xls/xlsx/csv read from the file's bytes (`mimes`, so an HTML or SVG renamed `.jpg` is refused), at most 10 MB each, at most 5 per message. Stored as `chat/<Y-m-d>/<40 random chars>.<ext>` (the uploaded name is kept only as a label). The app never hands out the `/storage/...` URL: files are served only through `GET /chat/{c}/messages/{m}/files/{i}`, which checks member-or-CEO, that the message belongs to that conversation and is not removed (and, for someone who left, was sent before they left). The content type comes from a fixed extension map; photos are served `inline`, everything else as `attachment`; with `X-Content-Type-Options: nosniff`, a `sandbox` CSP and `Cache-Control: private`. SVG and HTML can never be stored, so they are never served inline.

### Query plans for the two hot queries (sqlite, `SchemaTest::test_the_two_hot_queries_use_their_indexes`)
```
[296 list] a person's conversations by last activity
SEARCH m USING COVERING INDEX chat_members_user_id_conversation_id_index (user_id=?)
SEARCH c USING INTEGER PRIMARY KEY (rowid=?)
USE TEMP B-TREE FOR ORDER BY
[296 after] messages after an id in one conversation
SEARCH chat_messages USING INDEX chat_messages_conversation_id_id_index (conversation_id=? AND id>?)
```
The list sorts the person's own conversations (tens, at most 500 by the limit) in memory after an index lookup; there is no full scan. On MySQL the same indexes apply (`(user_id, conversation_id)` and `(conversation_id, id)`).

## Evidence per done-when item

**1. Tests.** `tests/Feature/Chat/`: 63 tests, 298 assertions, all pass.
- One direct chat per pair: `ConversationsTest::test_opening_a_direct_chat_twice_returns_the_same_one_from_either_side`, `SchemaTest::test_a_pair_cannot_have_two_direct_chats_even_in_a_race`.
- Who may create and manage groups: `GroupsTest::test_the_ceo_and_any_oic_may_create_a_group_and_staff_may_not`, `…admins_and_the_ceo_may_rename…`, `…admins_add_and_remove_members…`, `…the_ceo_added_to_a_group_is_an_admin`.
- Leaving and removal: `GroupsTest::test_a_removed_or_departed_member_sees_you_left_and_gets_nothing_new`, `…added_again_is_back`, `…leaving_twice…`.
- Text limits and escaping: `MessagesTest::test_text_from_1_to_4000…`, `…empty_blank_and_too_long…`, `…text_is_escaped_and_only_web_links_become_links`, `…link_text_cannot_break_out…`.
- Upload limits, types and authorised download: `MessagesTest::test_photos_and_files_up_to_5_of_10_mb…`, `…upload_limits_and_types_are_enforced` (6 files, 10 MB + 1 KB, SVG, HTML, EXE, HTML renamed `.jpg`, SVG renamed `.png`), `…files_are_served_to_members_and_the_ceo_with_a_safe_type`, `…a_removed_messages_files_are_no_longer_served`.
- Reply: `MessagesTest::test_reply_quotes_the_message_above_and_must_be_in_the_same_conversation`.
- Soft delete shows "Message removed": `MessagesTest::test_removing_your_own_message_keeps_the_row…`, `…you_may_not_remove_someone_elses_message`.
- Seen by: `MessagesTest::test_seen_by_comes_from_each_members_last_read_message`.
- Polling returns only new messages, only to members: `PollTest::test_poll_returns_only_messages_after_the_last_id`, `…list_rows_changed_since`, `…removed_since_the_last_poll`, `…refused_to_non_members`, `…page_polls_every_3_seconds_and_pauses_when_hidden`.
- Unread count: `PollTest::test_opening_and_polling_a_conversation_marks_it_read`, `…unread_counts_messages_from_others_in_active_conversations_only`.
- Non-members get 403 on every chat route: `AccessTest::test_a_non_member_gets_403` (11 routes × a staff member, an OIC not in the group, and a user with no profile).
- The CEO reads any conversation and must join to post: `CeoTest` (5 tests).
- AI message rendering: `AiMessageTest` (2 tests).
- Also: the nav link migration (`SchemaTest`, with and without `section`), a user with no employee profile (`ConversationsTest`).

Full suite: see "Full suite against base" below.

**2. Design evidence** in `docs/design/296-likha-chat/`: `brief.md`; screenshots at 390 px (`390-list.png`, `390-group-thread.png`, `390-group-photos-reply.png` with two photos and a reply, `390-new-chat.png` new chat search) and 1280 px (`1280-list-and-thread.png` side by side, `1280-direct.png` a direct chat, `1280-empty.png` empty state, plus `1280-ceo-reading.png`); `review.md` with the critique (25/40 before fixes), the audit (12/20 before fixes), the detector (1 finding, 0 after) and what was fixed. Screenshots were taken with headless Chrome over CDP of `php artisan serve` on 127.0.0.1 with a local sqlite file seeded with placeholder people (no real data, no real database).

**3.** Migrations, routes, file storage rule and query plans: above. Merge danger: below.

**4.** Pushed to `origin/feat/296-likha-chat`. Not merged, not deployed.

### Full suite against base
`vendor/bin/phpunit` under Herd PHP 8.4 (no `.env` in the worktree; composer install needed `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` on Windows).

| Run | Tests | Failures | Errors | Skipped |
|---|---|---|---|---|
| Base 8164024f | 5418 | 3 | 0 | 6 |
| Branch (last code commit `ac876fa6`) | 5481 (+63 chat tests) | 3 | 1 | 6 |

- The 3 failures are the base failures, the same three 292 listed: `ExampleTest::test_the_application_returns_a_successful_response`, `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`, `SharedPagesBasePinTest::test_S_42_4_… with data set "/owner/private"`.
- The 1 error is `Chatblast\SpeedMemoryTest::test_S_198_1_a_late_day_volume_renders_under_192_mb_with_the_same_numbers` ("Trying to access array offset on null" at line 152: the Speed Sequences endpoint returned no numbers). That endpoint has a time budget (spec 201, S-201.3: over budget it answers "too large" without numbers), and the test renders 150,000 rows. It is timing under load, not this branch: the file passes alone on the branch (`SpeedMemoryTest.php`: OK, 4 tests, 56 assertions), and nothing on the branch touches the chatbot or Speed code. **Reviewer: re-run that file if it shows up again on merge.**
- So, no new failures from this branch.

## Story changes
None to the stories. Details the stories left open are under Rulings.

## Rulings
1. **`direct_key` column on `chat_conversations`** (not in the decided column list): a unique "low:high" user-id key on direct chats only. It is the only way to make "at most one per pair" hold when both people open the chat at the same moment; a code-only check can race. Additive, nullable.
2. **System lines are messages with no sender** (neither `sender_user_id` nor `sender_ai`): "X created the group / renamed / added / removed / left / joined". The spec asks for "<name> joined"; using the same row shape needs no extra column and shows every membership change to everyone.
3. **The CEO is an admin of every group, member or not** ("the creator and the CEO are its admins"), so she can rename and add/remove without joining; posting still needs Join.
4. **Join is for groups only.** Joining a direct chat between two others would make it a three-person "direct" chat and break one-per-pair. For direct chats of others the owner reads only; the bar says so.
5. **Direct chats cannot be left**; leaving is a group action.
6. **Someone who left or was removed** keeps read access to the conversation up to the moment they left (messages and files sent before `left_at`), and gets nothing after: no new messages, no current member list, no read receipts, no unread count. Added back = active again (unread counts start from that moment). They still see the group's current title.
7. **People search lists active employees only** (profile status null or not inactive/resigned/terminated, as the staff feed). A user with no employee profile does not appear in search but can open `/chat` and start direct chats with employees; they then show in the other person's list.
8. **Unread** counts messages from people and AIs after your last read message, not your own, not removed ones, not system lines, and only in conversations you are still in.
9. **Storage disk**: the spec says the same disk as checklist uploads, which is `public`; files there are reachable under `/storage/` only by their 40-character random name, which the app never shows (all links go through the authorised route). Moving chat files to the private `local` disk is a one-line config change (`config/chat.php` `disk`) if the owner wants no public path at all.
10. **The page covers the layout's heading.** The layout prints a heading and a padded container on every page unless the path is listed in it, and the layout may not be edited (spec 295). The chat is a fixed panel under the nav (it measures the nav and the storage banner), so the layout's heading is under it. When 295 lands, `/chat` can be added to its full-width list.
11. **Comments** in new PHP files are in the codebase's Taglish style; the UI is English.
12. **Text is trimmed** before the 1-4,000 check (Laravel's TrimStrings), so leading/trailing blank lines are not stored.
13. **Seeding for screenshots**: a scratch PHP script built a local sqlite file (tables via the chat migrations themselves); nothing in the repo, no artisan migrate.
14. **Not changed from the critique**: the lock line keeps the spec's exact sentence for the owner too.

Questions for the owner (from the critique, not decided here): should staff see when the owner has read their chat? Would a one-time notice plus a small lock icon be better than the line on every thread?

## Merge danger
- **What ships**: a new page and 17 routes under `/chat`, 3 new tables and one nav link. No existing table, route, view, layout or nav code changes; `routes/web.php` gets one appended block (other branches appending at the end of the same file will conflict textually; keep both blocks).
- **Migrations**: 4, all additive. Running them creates the tables and shows a "Chat" link to the five standard roles (and any other role present in nav settings).
- **Reachable from outside the office IP list** by design (auth only). Every route checks membership or CEO on the server.
- **How to hide it**: uncheck the roles on the "Chat" link in `/owner/nav-settings` (the link disappears; the page still opens for anyone who types `/chat`). To close the page itself, remove the route block from `routes/web.php` (or wrap it in a config flag).
- **How to revert**: revert the branch's commits; rolling back the four `2026_11_27_2963xx`/`2962xx`/`2961xx`/`2960xx` migrations (with `migrate:rollback --step=4` only if they are still the last batch) drops the three tables and removes the nav link (all chat data is lost; take a dump of `chat_*` first if anything was written). Files under `storage/app/public/chat/` stay on disk until removed by hand.
- **Load**: one poll per open tab every 3 s (two small indexed queries plus the unread count); hidden tabs do not poll.
- **Spec 295 overlap**: the nav link migration sets `section` only if that column exists at run time; if 295's migration that adds `section` runs later, set the Chat link's section by hand or in 295.
