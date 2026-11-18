# 299 result: Mira in Likha Chat

Base: live main 2940b58b. Branch `feat/299-mira-in-chat`.

## Plan

Read first: `AiFollowups`, `ChecklistAiFollowupController`, `ChecklistAiFollowup`, the inbox view and the staff card (293); `ChatAccess`, `ChatPresenter`, `ChatController`, the chat models, `config/chat.php` and the chat view's JS (read only, 296); the nav migrations `2026_11_27_100000_add_nav_sections_and_pins`, `2026_11_27_296300_add_chat_nav_link`, `2026_11_28_100000_narrow_chat_nav_link_pattern`, `NavLink`, `NavSections` and the Nav test base (295).

**Mira's conversation (MC-3), no new table, no change to the chat tables.** A `chat_conversations` row with `kind = 'direct'`, `title = 'Mira'`, `direct_key = 'ai:mira:<user_id>'` (unique already, so one per person even in a race; it can never collide with a person-to-person key `<low>:<high>`), `created_by = null`, and **one** `chat_members` row: the staff member. So 296's rules hold unchanged: the member and the CEO read it (`ChatAccess::mayRead`), only the member posts (the CEO reads, as in every direct chat of others), a third person gets 403. Mira is never a member: her messages are `sender_ai = 'mira'`.
- New `app/Services/Chat/AiPost.php`: `conversation($ai, $userId)` (find or make, race-safe on the unique key, as `ChatController::direct`), `post($ai, $userId, $body)` (one message, `last_message_at`), `isAiConversation($c)`.
- `ChatPresenter::titleAndAvatar`: an AI conversation is titled with the AI's name and gets the AI avatar for its member; the CEO reading it sees "Mira and <name>" (same pattern as "Ana and Ben"). Without this the title would be blank (no other member). `ChatAccess` needs no change; access is tested.

**Follow-up columns (MC-3).** Migration `2026_11_29_299000_add_chat_columns_to_checklist_tasks`: `chat_message_id` (nullable) and `chat_failed_at` (nullable timestamp), each guarded, sqlite-safe, `down()` drops them.
- `AiFollowups::sendToChat($f)`: only an approved follow-up; inside a transaction with the row locked (`lockForUpdate`), returns at once when `chat_message_id` is set (idempotent: approve twice, retried job, double retry click); posts the text, stores the message id, clears `chat_failed_at`. On any exception: logs one warning, sets `chat_failed_at`, returns false; the approval stands.
- The text: the follow-up's final text (the CEO's edit when edited), a blank line, "Due by 3:00 PM · <task title>", then the link `<APP_URL>/checklist#task-<follows_task_id>` (ChatText makes http(s) links clickable).
- Called from `AiFollowups::approve()` (owner approve and edit-and-approve) and from the auto path. Reject and expiry never call it.

**Auto-approve (MC-2).** `ai_followups.auto_approve` in `AiFollowups::DEFAULTS` (`'0'`), read by `settings()`, saved by `saveSettings()`; the settings action validates it (`sometimes|boolean`, CEO only, same check).
- In `AiFollowups::draft()`, after the draft row is saved: when `auto_approve` is on, `autoApprove()` sets `approved`, `reviewed_by = null`, `reviewed_at = now`, `is_active = true`, then sends to chat. Main switch, window, budget and daily cap are checked before the draft exactly as now, so they hold. Drafts made while it was off are untouched.
- `examples()` (the CEO's past decisions fed to the prompt) skips auto-approved rows (`reviewed_by` null): they are not the CEO's decisions.
- No `ai_followup.approved` staff event for an auto approval (no person approved it).
- Staff card: "From Mira · sent automatically" when `reviewed_by` is null. Sent tab: an "Auto" chip.

**Inbox (MC-3, MC-4).** Sent rows show "Not sent to chat" (when `chat_failed_at` is set and no message) with a Retry button (CEO, route `POST /checklist/ai-followups/{id}/chat`, `checklist.ai-followups.chat`), and "Open chat" (`/chat?c=<conversation id>`) once sent. Settings get an Auto-approve switch next to On/Off.

**Chat first (MC-1).** Migration `2026_11_29_299100_move_chat_link_to_top` in the style of the narrow-pattern migration: only rows matched as the Chat link (`key = chat` or url `/chat`) change; `section = 'Home'` and `sort_order = (lowest sort_order in Home, other links) - 1`. The old section and order of each moved row are kept in `app_settings` (`nav.299_chat_before`) so `down()` puts them back exactly; without it, `down()` falls back to section Chat, last. Only when the `section` column exists. The name has no "nav" so the Nav test base (which globs `*nav*` migrations before adding the section column) is unaffected.

**Tests first** (failing before code): `tests/Feature/Chat/MiraConversationTest.php` (keyed, one per person, reused, title, member and CEO read, 403 for a third person, staff reply kept, CEO reads it), `tests/Feature/Chat/ChatFirstMigrationTest.php` (only chat moves, rollback), `tests/Feature/Checklist/AiFollowupAutoApproveTest.php` (default off, CEO only, auto approve with no reviewer, card text, Auto chip, On/Off, budget, window, cap, old drafts wait, prompt examples), `tests/Feature/Checklist/AiFollowupChatTest.php` (content and link, edited text, reject/expire send nothing, idempotent, failure keeps approval and retry works, retry CEO only, Open chat link). `AiFollowupTestCase` gets the chat tables and `ChecklistTestCase::MIGRATIONS` the new column migration.

**Design**: impeccable brief at `docs/design/299-mira-in-chat/brief.md` before the views; screenshots at 390 / 1280 from `artisan serve` on 127.0.0.1 with a local seeded sqlite file; critique and audit; fixes.

**Files touched**: new `AiPost.php`, 2 migrations, 4 test files, design folder; changed `AiFollowups.php`, `ChecklistAiFollowupController.php`, `ChatPresenter.php`, `routes/web.php` (one line in the checklist group), `checklist/ai-followups.blade.php`, `checklist/partials/ai-followup-card.blade.php`, `AiFollowupTestCase.php`, `ChecklistTestCase.php`. Not touched: `resources/views/chat/*`, chat JS, `ChatController`, the sidebar.

## What shipped

The plan held. Two changes against the plan:
- `app/Models/ChecklistAiFollowup.php` also changed (one cast, `isAuto()`, and an auto row is never `wasEdited()`);
- `ChatAccess` needed no change.

### MC-1: Chat first in the sidebar
- **Migration.** `database/migrations/2026_11_29_299100_move_chat_link_to_top.php`.
- **What it does.** It finds the Chat link (`key = chat`, or url `/chat`), then sets `section = 'Home'` and `sort_order = (lowest sort_order of the other Home links) - 1`. No other row changes, and visibility rows are untouched.
- **Rollback data.** The old section and order are kept in `app_settings` under `nav.299_chat_before`, as JSON keyed by link id. `down()` restores them exactly and deletes the key. Without that row, `down()` falls back to section Chat, last in the menu.
- **Safe to run anywhere.** It is idempotent, and it does nothing when `nav_links.section` does not exist.

### MC-2: Auto-approve
- **Setting.** `ai_followups.auto_approve` in `app_settings` (default `'0'`). It is read and saved through `AiFollowups::settings()` / `saveSettings()` (`DEFAULTS` gained the key).
- **Settings action.** It accepts `auto_approve` (`sometimes|boolean`, CEO-only check unchanged) and flashes "Auto-approve is on. New drafts go to staff at once." / "Auto-approve is off. New drafts wait for your review."
- **When it is on.** `AiFollowups::draft()` saves the draft, then `autoApprove()` sets:
  - `review_status = approved`;
  - `reviewed_by = null`;
  - `reviewed_at = now`;
  - `is_active = true`.

  It then sends the follow-up to chat.
- **What still applies first.** The main switch, the 9 AM to 9 PM window, the monthly budget and the 3-a-day cap are all checked before a draft is made, exactly as before. With the main switch Off, `run()` returns before drafting.
- **What auto-approval does not do.** It writes no `ai_followup.approved` staff event, because no person approved. Auto rows are left out of `ceo_reviews` in the prompt, because they are not the CEO's decisions.
- **Staff card.** "From Mira · sent automatically".
- **Sent tab.**
  - an "Auto" chip (violet);
  - "Auto-approved <time>";
  - the header line says new follow-ups go out without review;
  - Drafts shows "Made before auto-approve was on. These still wait for you."
- **Settings tile.** The Auto-approve switch sits in the On/Off tile and asks for confirmation before switching on.

### MC-3: Follow-ups in Likha Chat
- **Posting.** New `app/Services/Chat/AiPost.php`:
  - `conversation($ai, $userId)` finds or makes the conversation. It is race-safe on the unique key.
  - `post($ai, $userId, $body)` refuses empty text, trims to `chat.max_body`, and sets `last_message_at`.
  - `isAiConversation()`, `aiOf()` and `name()` are small helpers.
- **How the Mira conversation is keyed.** It is an existing `chat_conversations` row:
  - `kind = 'direct'`;
  - `direct_key = 'ai:mira:<user_id>'`;
  - `title = 'Mira'`;
  - `created_by = null`;
  - exactly one `chat_members` row: the staff member.

  Mira is `sender_ai = 'mira'`, never a member. No new table, and no change to the chat tables. The `ai:` prefix can never collide with a person-to-person key `<low>:<high>`.
- **Access.** It comes unchanged from 296's `ChatAccess`:
  - the member reads and posts;
  - the CEO reads without being a member;
  - the CEO cannot post or join (direct chats have no Join);
  - anyone else gets 403.
- **`ChatPresenter::titleAndAvatar`.** An AI conversation is titled with the AI's name and gets the AI avatar (`ai: true`). For the member it reads "Mira"; for the CEO reading it, "Mira and Joy Ramos".
- **Sending: `AiFollowups::sendToChat()`.**
  - It is called from `approve()` (approve, and edit and approve) and from `autoApprove()`.
  - It runs in a transaction with the follow-up row locked (`lockForUpdate`), and returns at once if `chat_message_id` is already set.
  - It posts, then stores `chat_message_id` and clears `chat_failed_at`.
  - On any error it logs one warning ("AI follow-up: not sent to chat", with `followup_id` and the error), sets `chat_failed_at`, and leaves the approval in place.
  - Rejected and expired follow-ups never send anything: the function returns false unless the row is approved.
- **Message text** (`AiFollowups::chatText()`). The final text (the CEO's edit when edited), a blank line, then:

  ```
  Due by 5:00 PM · <task title, up to 120 chars>
  Open the task: <APP_URL>/checklist#task-<follows_task_id>
  ```

  ChatText escapes everything and makes the link clickable.
- **Migration.** `database/migrations/2026_11_29_299000_add_chat_columns_to_checklist_tasks.php` adds `chat_message_id` (unsigned bigint, nullable) and `chat_failed_at` (timestamp, nullable). Each column is guarded, it is sqlite-safe, and `down()` drops them.
- **Retry.** `POST /checklist/ai-followups/{followup}/chat` (`checklist.ai-followups.chat`), CEO only. It answers:
  - 409 unless the follow-up is approved and still Waiting today;
  - 502 when the send fails again;
  - 200 with "Sent to Joy in Likha Chat."

  The inbox shows "Not sent to chat" (amber) and "Still on their checklist". The Retry button appears on Waiting rows only.

### MC-4: Open chat
- Sent rows that reached chat link to `/chat?c=<conversation id>`, looked up from `chat_message_id`. The CEO reads the conversation there.

### Files
- **New:**
  - `app/Services/Chat/AiPost.php`;
  - the two migrations above;
  - `tests/Feature/Chat/MiraConversationTest.php` and `ChatFirstMigrationTest.php`;
  - `tests/Feature/Checklist/AiFollowupAutoApproveTest.php` and `AiFollowupChatTest.php`;
  - `docs/design/299-mira-in-chat/` (brief, review and 12 screenshots).
- **Changed:**
  - `app/Services/AiFollowups.php`;
  - `app/Http/Controllers/ChecklistAiFollowupController.php`;
  - `app/Models/ChecklistAiFollowup.php`;
  - `app/Services/Chat/ChatPresenter.php` (one block in `titleAndAvatar`);
  - `routes/web.php` (one line in the checklist group);
  - `resources/views/checklist/ai-followups.blade.php`;
  - `resources/views/checklist/partials/ai-followup-card.blade.php`;
  - `tests/Feature/Checklist/AiFollowupTestCase.php` (chat tables);
  - `tests/Feature/Checklist/ChecklistTestCase.php` (the new column migration).
- **Not touched:**
  - `resources/views/chat/*` and the chat JS;
  - `ChatController`, `ChatAccess` and the chat models;
  - the sidebar, the layout, `NavLink` and `NavSections`.

## Evidence per done-when item

**1. Tests.** 47 new tests, all passing. `vendor/bin/phpunit tests/Feature/Checklist tests/Feature/Chat`: OK (362 tests).

**`ChatFirstMigrationTest`** (Chat; built on the Nav test base, which has production-like rows):
- only `chat` moves; every other link's section, order, pattern and active flag, and every visibility row, stay identical (`test_only_the_chat_link_moves_to_the_top_of_home`);
- for each of the 5 roles, Chat is the first sidebar link and Home reads Chat, then Checklist (`test_chat_is_the_first_sidebar_link_for_every_role_that_sees_it`);
- a rerun changes nothing (`test_running_it_twice_changes_nothing_more`);
- rollback restores the exact rows (`test_rollback_puts_it_back_where_it_was`, `test_rollback_puts_back_a_place_the_ceo_had_chosen`);
- with no Chat link, nothing changes (`test_without_a_chat_link_nothing_changes`).

**`AiFollowupAutoApproveTest`**:
- off by default, and a draft waits;
- the switch is CEO only: four other roles get 403, the CEO turns it on and off, and junk input gets 422 (`test_only_the_ceo_can_switch_it`);
- the flash messages after saving;
- the switch is shown in Settings;
- when on, a draft is approved at once with no reviewer, the card reads "sent automatically", the message is in chat, and no `approved` staff event is written;
- the Auto chip, "Auto-approved <time>", an auto row is never "Edited by you", and the header line;
- with the main switch Off, nothing is drafted or sent;
- the budget, the 9 AM to 9 PM window and the 3-a-day cap still hold;
- drafts made while it was off still wait;
- auto rows are not used as the CEO's examples in the prompt.

**`AiFollowupChatTest`**:
- the message content and the link, and the link renders as `<a>`;
- edit-and-approve sends the edited text and deadline;
- rejected and expired follow-ups send nothing;
- each person has one conversation, reused;
- sending is idempotent: a second approve, a direct `sendToChat` and a retry still leave one message;
- a failed post:
  - the follow-up stays approved and on the checklist;
  - one warning is logged and the inbox shows "Not sent to chat";
  - a retry that fails again gets 502;
  - a retry that works posts once and clears the failure;
- retry is CEO only, and only for an approved follow-up that is still Waiting;
- Open chat links to the right conversation (MC-4), and the CEO can read it;
- HTML is escaped;
- the staff reply is kept and Mira does not answer.

**`MiraConversationTest`**:
- the key, one conversation per person, reused, and Mira is not a member;
- a conversation made by a racing request is reused;
- empty text is refused;
- the member sees "Mira" with the AI avatar and an unread count, and can post but not leave or manage;
- the reply is kept;
- the CEO reads it as "Mira and Joy Ramos" and cannot post;
- **a third person gets 403** (an OIC and another staff member), on show, poll, send, read, remove and join, and does not see it in `?all=1`;
- a person cannot remove Mira's message;
- person-to-person direct chats are unchanged.

**Full suite.** `vendor/bin/phpunit`, whole suite, Herd PHP 8.4.16, at code commit `34aa1051`:

| Run | Tests | Assertions | Errors | Failures | Skipped |
|---|---|---|---|---|---|
| Branch | 5715 | 85740 | 2 | 12 | 6 |

The 14 are:
- **The three known base failures the spec names:**
  - `ExampleTest`;
  - `ItemTableFitScriptTest`;
  - `SharedPagesBasePinTest` `/owner/private`.
- **The rest are Chatblast Speed tests, which the spec calls load-sensitive:**
  - `SpeedPrewarmTest`: 9 failures and 1 error;
  - `SpeedMemoryTest`: 1 error.

  Nothing on this branch touches Chatblast. Run alone after the full run, both pass: `SpeedPrewarmTest` OK (20 tests, 249 assertions) and `SpeedMemoryTest` OK (4 tests, 56 assertions).

So **the branch adds no new failures** compared with the known base set.

**I have no clean base-run total.** I started the base run (2940b58b) at the start in this same worktree, but it was still running while I committed code, so its results would mix base and branch files. I stopped it and do not report its numbers. The comparison above is against the base failures the spec lists, which are also in 296's result, not against a fresh base run from this session. The base has 47 fewer tests (the new files exist only on the branch).

**2. Screenshots** at 390 and 1280 px are in `docs/design/299-mira-in-chat/`, with a table in `review.md`:

| What | Files |
|---|---|
| Sidebar with Chat on top | `sidebar-chat-first-1280.png`, `sidebar-chat-first-390.png` |
| Follow-ups Settings with Auto-approve | `followups-settings-1280.png`, `followups-settings-390.png` |
| Joy's Mira conversation with two follow-ups and a reply | `mira-conversation-1280.png`, `mira-conversation-390.png` |
| Staff card "sent automatically" | `staff-card-auto-390.png`, `staff-card-auto-1280.png` |
| Extras | `followups-sent-*.png` (Auto, Not sent to chat with Retry, Open chat), `mira-in-list-390.png`, `ceo-reading-mira-1280.png` |

They were taken from `artisan serve` on 127.0.0.1 with a seeded local sqlite file in the scratchpad (never a real database), in headless Chrome.

Design review with impeccable:
- critique 30/40 and audit 17/20 before the fixes;
- the detector found 0 issues, before and after;
- every P2 and every in-scope P3 is fixed (see `review.md`).

**3.** Migrations, the settings key (`ai_followups.auto_approve`) and how the Mira conversation is keyed: see "What shipped". Merge danger: below.

**4.** Pushed to `origin/feat/299-mira-in-chat`. Not merged, not deployed, and no migration was run against any server.

## Story changes
None. Details the stories left open are under Rulings.

## Rulings
1. **Mira's conversation is `kind = 'direct'` with `direct_key = 'ai:mira:<user_id>'` and one member**, not a new `kind = 'ai'`.
   - It is the smallest change that keeps every 296 rule and test true.
   - The chat JS (spec 300's) already handles `direct`; a new kind would need view changes this spec may not make.
   - The unique `direct_key` gives one per person, even in a race.
2. **The CEO sees "Mira and <name>"** in the list and the thread header when reading it. Plain "Mira" would give many identical rows in their "All conversations" list. This is the same pattern as 296's "Ana and Ben".
3. **The CEO reads but cannot post** in Mira's conversations. That is 296's direct-chat rule (Join is for groups only), and the spec only asks that the CEO reads the replies.
4. **Idempotency** comes from `chat_message_id` on the follow-up row plus a row lock (`lockForUpdate`). A second approve also gets 409 from the existing draft check.
5. **Failure state is a timestamp column, `chat_failed_at`.**
   - "Not sent to chat" shows only after a send was tried and failed.
   - Follow-ups approved before this spec show neither "Not sent" nor "Open chat".
   - There is no backfill: old follow-ups are not posted.
6. **Retry only while the follow-up is Waiting** (today and not Done): a stale "by 3:00 PM" the next day would mislead. On Done or Expired rows the chip stays as a record without a button. The server enforces this with 409.
7. **Auto-approved rows have `reviewed_by = null`.**
   - They write no `ai_followup.approved` staff event, because no person approved them.
   - They are left out of the prompt's `ceo_reviews`, because they are not the CEO's decisions; the AI would otherwise learn from itself.
   - They are never "Edited by you".
8. **Inbox wording.** An auto row's time line reads "Auto-approved <time>" rather than "Sent automatically", so it never contradicts "Not sent to chat". The staff card keeps the spec's "From Mira · sent automatically".
9. **One `confirm()` before switching auto-approve on**, because it is the only path where AI text reaches staff unreviewed. Switching it off needs no confirm.
10. **The chat link uses `url('/checklist')`**, so it depends on `APP_URL` when the scheduler auto-approves from the CLI. Production's `APP_URL` must be the public address.
11. **The chat text is plain, with the full URL.** ChatText only links raw http(s) URLs; a short label would need a ChatText change, which belongs to the chat owner.
12. **The nav migration keeps its rollback data in `app_settings`** (`nav.299_chat_before`). "Rolling back puts it back where it was" needs the old place, and production's Chat row may be hand-made with an unknown order. The migration name has no "nav", so the Nav test base (which globs `*nav*` migrations) is unaffected; it is tested on that base explicitly.
13. **`NavLink::defaultData()` and `NavSections` are not changed** (they are spec 295 files). So **Reset in `/owner/nav-settings` would put Chat back in the Chat section.** Reviewer decision: accept this, or change NavSections' `/chat` to Home in a follow-up.
14. **Comments** are in Taglish in the Taglish files (AiFollowups, the controller, AiPost), and in English in the migration that follows the English nav migrations.

Two chat-page items are out of this spec's files and left for spec 300:
- Mira's thread subtitle reads "Direct chat".
- AI avatars are amber in chat but violet on the checklist.

## Merge danger

### What ships
- **Two migrations:**
  - two columns added to `checklist_tasks`;
  - one `nav_links` row moved, plus one `app_settings` row.
- **One route:** `POST /checklist/ai-followups/{id}/chat` (CEO only).
- **A new class,** `AiPost`.
- **Both approve paths now also post to Likha Chat.**
- **The inbox and the staff card** change.

### Behaviour change even with auto-approve off
Every follow-up the owner approves after the deploy is also posted to that staff member's "Mira" conversation (MC-3). Auto-approve is off by default, so nothing goes out unreviewed until the owner switches it on.

### Order
Both migrations rely on 293's columns, 295's `section` and 296's chat tables, all live on main. If the chat tables were missing, sending would fail and be logged, the inbox would show "Not sent to chat", and the approval would still stand.

### Conflicts with spec 300 (built in parallel)
This branch touches two shared files. Keep both sides on a conflict.
- `ChatPresenter::titleAndAvatar`: one block at the top of the direct-chat branch.
- `routes/web.php`: one line in the checklist group, not in the chat block.

### How to switch it off
- **Auto-approve:** the switch on `/checklist/ai-followups`, or set `app_settings` `ai_followups.auto_approve` to `0`.
- **Everything:** the Marketing head's On/Off switch.
- **The chat posting:** it has no separate switch. Remove the `self::sendToChat($f)` calls in `AiFollowups::approve()` and `autoApprove()`.

### How to revert
1. Revert the branch's commits.
2. Roll back the two migrations:
   - `2026_11_29_299100_move_chat_link_to_top` puts Chat back where it was;
   - `2026_11_29_299000_add_chat_columns_to_checklist_tasks` drops the two columns.
3. Mira's conversations and messages stay in `chat_*` as ordinary rows. Delete them (`direct_key LIKE 'ai:%'`) only if wanted, and dump them first.
