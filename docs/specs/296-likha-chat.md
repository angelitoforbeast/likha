# 296 — Likha Chat: the team's Messenger inside Likha

## Why
The owner: "prepare ka na din para messenger na route sa likha, dun na kami maguusap usap, pwede magcreate ng gc, etc.", then "gomo na lahat", "deploy na agad". Today the team talks in Facebook Messenger; Likha has no chat between people (Astra is a per-user AI chat; the chatbot inbox is for customers). Moving the team's talk into Likha puts every message beside the tasks and the data, where the owner's AIs will later read it and post in it (his plan "LDT Virtual Office", Likha Chat).

## Stories and cases
**CH-1 Conversations (P1).** `/chat` lists the signed-in person's conversations, newest activity first: avatar (initials), name, the last line (sender and text, one line), time, unread count. Two kinds:
- **Direct:** between two people; at most one per pair (opening a direct chat with someone you already have opens that one).
- **Group:** a name and members. The CEO and any OIC may create a group, rename it, and add or remove members; the creator and the CEO are its admins. Any member may leave. A removed or departed member keeps nothing new and sees "You left this group".
- **People:** any active employee with a Likha account (active as the staff feed defines it: `employee_profiles.status` null or not inactive/resigned/terminated). "New chat" searches people by name.
- E: a user with no employee profile can still open `/chat` and use direct chats.

**CH-2 Messages (P1).** In a conversation: messages oldest to newest, your own on the right in the accent colour, others on the left with name and initials, day separators, times on hover/tap.
- Send text (1 to 4,000 characters; Enter sends, Shift+Enter new line), photos and files (up to 10 MB each, at most 5 per message; images show inline and open full size; files show name and size), reply to a message (quoted above yours).
- Delete your own message: members see "Message removed"; the row is kept (soft delete). No editing in this spec.
- Links in text are clickable; text is escaped (no HTML from users).
- **Seen:** under your last message, "Seen by" with initials, from each member's last read message.

**CH-3 Live enough (P1).** While `/chat` is open it asks for new messages every 3 seconds (a small JSON endpoint returning only what is new since the last id) and pauses when the tab is hidden, as Likha's live pages do. Opening a conversation marks it read. A `GET /chat/unread` JSON endpoint returns the person's total unread count for a nav badge (the badge itself is wired in a later spec; do not edit the main layout).

**CH-4 The CEO can open any conversation (P1).** The CEO has an "All conversations" filter and can read any conversation without being a member; the page states it in the header for everyone ("The owner can read every conversation in Likha Chat"). The CEO cannot post in a conversation they are not a member of unless they join it (Join button, shown to members as "<name> joined").

**CH-5 Ready for AI members (P1).** A message has either a human sender or an AI sender (`sender_ai`, a short key like `mira` or `marketing_head`); an AI message shows the AI's name with a small AI mark. No endpoint lets an AI post yet (later spec); test the rendering with a seeded AI message.

**CH-6 Phone first, finished look (P1).** Design first with the `impeccable` skill (brief and renders in `docs/design/296-likha-chat/`, like `docs/design/199-live/`). It should feel like a real messenger, calm and fast, in Likha's look with one accent: on phones one column (list, then thread with a back button); on desktop list and thread side by side. Empty states for no conversations and an empty thread. English everywhere, visible focus, contrast AA.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Three new tables: `chat_conversations` (kind direct/group, title, created_by, last_message_at, timestamps, soft delete), `chat_members` (conversation_id, user_id, role member/admin, last_read_message_id, joined_at, left_at, unique per conversation and user), `chat_messages` (conversation_id, sender_user_id nullable, sender_ai nullable, body, reply_to_id, attachments json, timestamps, soft delete) | A group with members is a shape no current table holds; Astra's tables stay for Astra |
| `/chat` behind `auth` only, not the office IP allowlist | Staff chat from their phones, like `/checklist` and `/order-encode` today |
| The CEO can read every conversation, and the page says so | The owner's business chat; no hidden reading |
| Polling, not websockets | Likha has no broadcasting; polling is what its live pages use |
| Nothing is deleted | Soft deletes; the owner keeps the record |

## Constraints
- Do not edit the main layout or the nav (spec 295 is rebuilding them). Add a "Chat" nav link with a migration in the style of the recent `add_*_nav_link` migrations, visible to every role in `nav_link_role_visibility` today (CEO, Marketing - OIC, Marketing, Data Encoder - OIC, Data Encoder); if `nav_links.section` exists, set it to `Chat`.
- Store files on the same disk and with the same checks as checklist uploads (look at how `ChecklistController::submit` stores and serves files); serve them only to members and the CEO.
- Every chat route checks membership (or CEO) on the server, including the polling and file routes: test a non-member gets 403 on each.
- Indexes for the two hot queries: a person's conversations by last activity, and messages after an id in one conversation.
- Comments in the language and style of the file around them. Tests in `tests/Feature/Chat/`; the full suite adds no new failures compared with base 8164024f (list the base failures).

## Threat model and risk
New untrusted input: message text (escaped; links made clickable without HTML), file uploads (type and size limits, stored outside the public web root or with unguessable names, served through an authorised route with the right content type, no SVG or HTML served inline), group names. New access surface: conversations and files (membership checked on every route; the CEO rule tested). Reachable outside the office IP list by design (auth only). Risk tier: high (new data that staff write, new files, reachable from phones).

## Allowed commands
`composer.bat install`, `php.bat artisan test` (with filters) and `vendor/bin/phpunit` via Herd PHP (`C:\Users\Forbeast\.config\herd\bin\php.bat`); `php.bat artisan route:list`; `php.bat artisan view:cache`; `npm ci` and `npm run build`; `php.bat artisan serve` on 127.0.0.1 with a local seeded sqlite database (never the real database); a headless browser for screenshots of 127.0.0.1 pages and local files only; the `impeccable` skill; git add, git commit, git push -u origin feat/296-likha-chat.

## Done when
1. Tests pass for CH-1..CH-5, including: one direct chat per pair; who may create and manage groups; leaving and removal; text limits and escaping; upload limits, types and authorised download; reply; soft delete shows "Message removed"; seen by; polling returns only new messages and only to members; unread count; non-members get 403 on every chat route; the CEO reads any conversation and must join to post; AI message rendering. The full suite adds no new failures compared with base 8164024f.
2. Evidence in `docs/design/296-likha-chat/`: the brief; screenshots at 390 px (list, a group thread with photos and a reply, new chat search) and 1280 px (list and thread side by side, a direct chat, an empty state); the impeccable critique and audit findings with what was fixed.
3. The result lists the migrations, routes, the file storage rule, the query plans for the two hot queries, and a merge-danger section (what ships, migrations, how to hide the feature, how to revert).
4. The branch is pushed. No merge, no deploy.

## Out of scope
AIs posting; moving the AI follow-ups into chat; push notifications; the nav badge; voice, video, reactions, message editing; importing Messenger history.

## Budget
Large job. At most 2 attempts at the same failing step, then stop and report.
