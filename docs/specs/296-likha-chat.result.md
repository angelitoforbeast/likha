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

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
