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
