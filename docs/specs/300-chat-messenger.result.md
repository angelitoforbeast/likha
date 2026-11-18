# 300 result: Likha Chat works like Messenger

Base: live main 2940b58b. Branch `feat/300-chat-messenger`.

## Plan

Base 2940b58b: `composer install` (with `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`, Windows), full suite once with `vendor/bin/phpunit` under Herd PHP (no `.env`; phpunit.xml uses sqlite `:memory:`); base failures listed under Evidence.

Read first: spec 296 and its result, 295's result, `ChatController`, `ChatGroupController`, `ChatAccess`, `ChatPresenter`, `ChatPeople`, `ChatText`, the three chat models, `config/chat.php`, `chat/index.blade.php`, `components/layout.blade.php`, `components/sidebar.blade.php`, `components/navlink.blade.php`, `tests/Feature/Chat/*`.

**Keeping out of 299's way.** 299 adds `AiPost.php` and may touch `ChatAccess` and `ChatPresenter`. All new server logic goes into new files: `App\Services\Chat\ChatMessenger` (reactions, edits, mute, alerts, search, decorating rows and messages), `App\Services\Chat\ChatLive` (typing and "active" in the cache) and `App\Http\Controllers\ChatMessengerController` (the new routes). `ChatAccess` is not changed. `ChatPresenter` gets three small, marked hooks only: the muted filter in `unreadTotal`, a "Voice message" last line, and one call to `ChatMessenger::decorate()` at the end of `messages()`. `ChatController` gets small calls into the new services (poll, show, conversations, unread, index).

**Data (one migration, `2026_11_28_300000_add_messenger_columns_to_chat_tables`, additive)**
- `chat_messages`: `reactions` json null (user id → emoji), `edited_at` timestamp null, `edit_history` json null (the earlier bodies with the time each was replaced), index `(conversation_id, updated_at)` for "changed since" in the poll.
- `chat_members`: `muted_until` datetime null ("until I turn it back on" = 9999-12-31).
- No new table. Typing and "active" live in the cache (`chat:typing:{c}` a small map with a few seconds' life; `chat:active:{user}` written at most once a minute).

**Routes (new, in the existing `/chat` block, all `['web','auth']`; every one with a conversation checks membership on the server)**
- `POST /chat/{c}/messages/{m}/reactions` (emoji from the six-emoji allowlist; same emoji again removes, another changes) — active member (the CEO after Join).
- `PATCH /chat/{c}/messages/{m}` (body) — the sender, active member, text message, not removed, within 15 minutes; earlier body pushed to `edit_history`, `edited_at` set.
- `POST /chat/{c}/typing` — active member; poll returns who types in the open conversation.
- `POST /chat/{c}/mute` (`8h` / `on` / `off`) — member.
- `POST /chat/{c}/voice` (audio file, duration) — active member; audio allowlist from the file's bytes (webm, ogg, mp4/m4a, mp3), max 5 MB, 1-120 s; stored like 296 files (`chat/<date>/<random>.<ext>` on the chat disk).
- `GET /chat/{c}/messages/{m}/voice` — member or CEO (as 296's file route); served with a fixed audio content type, `nosniff`, sandbox CSP, range requests.
- `GET /chat/search?q=` — conversations by name and messages by text, in your own conversations only (a departed member up to leaving).
- `GET /chat/unread` keeps `{unread}`; with `?alerts=1&after=<id>` it also returns `last_id` and up to 5 new-message alerts (not own, not muted, not read, id > after). It also marks you active.

**Poll payload additions**: list rows get `muted` and, for direct chats, `active_at` of the other person; `thread` gets `changed` (messages edited, reacted or removed since the last poll, as full message JSON) and `typing` (names). Each message gets `reactions` (emoji, count, names, mine), `edited`, `can_edit_until`, and `history` for the CEO only; voice attachments come as `kind: audio` with their own URL and duration.

**UI**
- `components/chat-unread.blade.php` (new), included once in the layout for a signed-in user: every 15 s while visible (at once on return) reads `/chat/unread?alerts=1`, sets the red count on the sidebar Chat link (rail dot and drawer included) and "(n) " before the tab title, plays a short sound and shows a browser notification for new messages outside the open conversation; clicking opens `/chat?c=<id>`. Sound on/off and the last alerted id are kept per user in this browser.
- `components/sidebar.blade.php` / `navlink`: only the badge hook for the Chat link (`data-badge="chat"`).
- `chat/index.blade.php`: Allow notifications button and sound switch (chat page only, never asked on load), reactions (hover bar on desktop, long-press on phones, counts with who reacted), edit (menu → edit bar, "Edited", CEO history), typing line, active dots and "Active 12m ago", mute menu in the thread header, search (names and messages, opens the conversation at the message), voice (hold the mic, up to 2 min, inline player; hidden without MediaRecorder), Messenger-like grouping corners, seen avatars under the last message each person saw, Enter/Shift+Enter on desktop and a 44 px send on phones, smooth scroll and the "New messages ↓" pill.

**Order (test first, a commit per step)**: migration + test case; MP-3 reactions; MP-4 edit; MP-6 mute + unread total; MP-1/MP-2 unread with alerts; MP-5 typing + active; MP-6 search; MP-7 voice; poll payload (changed, typing); 403 on every new route; the layout component + badge; design brief with `impeccable` before the view; the view; screenshots (390 / 1280) on a local seeded sqlite `artisan serve` with headless Chrome; impeccable critique + audit and fixes; full suite against base; result; push.
