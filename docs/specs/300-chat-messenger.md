# 300 · Likha Chat works like Messenger

## Why
The owner, 2026-11-18, on Likha Chat (spec 296, live at `/chat`): "priority yung chat ... tapos same function sa messenger". His staff talk in Messenger today. For them to move to Likha Chat it has to do what they use Messenger for. 296 left out the nav badge, notifications, reactions, editing and voice ("Out of scope" there). This spec adds them.

## Stories and cases
**MP-1 Unread everywhere (P1).** Every signed-in Likha page shows unread chats.
- A red count on the sidebar's Chat link, in the rail and the drawer.
- The count in the browser tab title, e.g. "(3) Checklist · Likha".
- It is read from `/chat/unread` every 15 seconds, and not while the tab is hidden; it refreshes at once when the tab comes back.
- A muted conversation does not count (MP-6).
- Pages without a signed-in user make no request.

**MP-2 New-message alert (P1).** When a new message arrives in a conversation that is not open on screen, Likha plays a short sound and shows a browser notification: sender, conversation and the first 80 characters. The alert works on any Likha page, using the MP-1 poll.
- The chat page has an "Allow notifications" button, which asks the browser once. Never ask on page load.
- Clicking the notification opens that conversation.
- No alert for your own messages, for muted conversations, or for messages you already saw.
- A setting in the chat page turns sound off, remembered per user.

**MP-3 Reactions (P1).** Long-press on a phone, or hover on a desktop, to react with one of 👍 ❤️ 😆 😮 😢 😡.
- One reaction per person per message. Pick another to change it, or pick the same one to remove it.
- The message shows the reactions with counts. Tap them to see who reacted.
- Reactions arrive through the existing poll.
- Members only; the CEO can react only in conversations he has joined, like posting.

**MP-4 Edit your message (P1).** Your own text message can be edited within 15 minutes of sending. It then shows "Edited".
- The earlier text is kept on the server, because nothing is deleted. Members do not see it; the CEO can see the history.
- A removed message cannot be edited. Attachments do not change.

**MP-5 Typing and active (P2).**
- "Rey is typing…" shows in the open conversation while another member types. The typing state lasts a few seconds and travels with the poll, not a new socket.
- A green dot on a person who used Likha in the last 5 minutes. "Active 12m ago" when it was under an hour ago.
- Both use cheap, short-lived state (cache), not a row per keystroke.

**MP-6 Mute and search (P2).**
- Mute a conversation for 8 hours or until you turn it back on: no alerts and no unread count from it.
- Search your conversations by name and by message text. The results open the conversation at that message.

**MP-7 Voice message (P2).** Hold the mic to record (up to 2 minutes), then release to send. Members play it inline.
- It is stored and served like 296's attachments, under the same membership checks, with an audio type allowlist and a size limit.
- Where the browser cannot record, the mic is hidden.

**MP-8 Feels like Messenger (P1).** Use the `impeccable` skill.
- Own messages on the right in a blue bubble, others on the left with their initials. Messages from the same person in a row are grouped.
- Day separators. Seen avatars under the last message the person saw (from 296's seen data).
- Enter to send and Shift+Enter for a new line on a desktop. A big tap target to send on a phone.
- Smooth scroll to the newest message, with a "New messages ↓" pill when you are scrolled up.
- Photos show as previews; tap one to open it full.
- English UI.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Polling, not websockets | 296's decision; Likha has no broadcasting |
| No push when the browser is closed | That needs web push (a service worker and keys); a later spec if he wants it |
| Nothing is deleted | Edits keep the earlier text; reactions are changed, not erased from history |
| Lean schema: no new table unless the shape needs one | His rule. Reactions as a json column on `chat_messages` (user id → emoji); edits as `edited_at` and a json list of earlier bodies; mute as a `muted_until` on `chat_members`. If one of these truly needs a table, say why in the result |

## Constraints
- Spec 299 (Mira in Likha Chat) is being built at the same time in `projects/likha-wt299`. It adds `app/Services/Chat/AiPost.php` and may touch `ChatAccess.php` and `ChatPresenter.php` for a one-human conversation with Mira. Keep your changes to those two files small and well separated so the merge is easy. Do not edit `app/Services/AiFollowups.php` or the follow-ups views.
- The sidebar badge goes in 295's sidebar (layout and NavMenu). Keep the change there to the badge.
- Every new route checks membership (or CEO) on the server. Test that a non-member gets 403 on each.
- Comments in the language and style of the file around them. Tests go in `tests/Feature/Chat/`. The full suite adds no new failures compared with base 2940b58b. The known base failures are ExampleTest, ItemTableFitScriptTest and SharedPagesBasePinTest `/owner/private`; the Chatblast Speed tests may fail under load but pass alone.

## Threat model and risk
- New input:
  - reactions: allowlisted emoji only;
  - edited text: the same escaping and limits as sending;
  - voice files: type and size allowlist, served through the authorised route, never inline HTML.
- Notifications show message text on the user's screen (their own browser, after their permission).
- Risk tier: high (staff-written content and files, reachable from phones).

## Allowed commands
- Through Herd PHP (`C:\Users\Forbeast\.config\herd\bin\php.bat`):
  - `composer.bat install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`
  - `php.bat artisan test` (with filters) and `vendor/bin/phpunit`
  - `php.bat artisan route:list` and `php.bat artisan view:cache`
- `npm ci` and `npm run build`.
- `php.bat artisan serve` on 127.0.0.1, with a local seeded sqlite database only (never the real database).
- A headless browser for screenshots of 127.0.0.1 pages only.
- The `impeccable` skill.
- `git add`, `git commit` and `git push -u origin feat/300-chat-messenger`.

## Done when
1. Tests pass for MP-1 to MP-7. They include:
   - unread count with mute;
   - the poll returning new reactions, edits and typing;
   - reaction rules;
   - the edit window and history (the CEO sees it, members don't);
   - mute;
   - search scoped to your conversations;
   - voice upload limits and authorised playback;
   - 403 for non-members on every new route.

   The full suite adds no new failures compared with base 2940b58b.
2. Evidence in `docs/design/300-chat-messenger/`:
   - the brief;
   - screenshots at 390 px: a thread with grouped bubbles, reactions, an edited message, a voice message, the typing line;
   - screenshots at 1280 px: the list with unread counts and active dots, the sidebar badge, a search;
   - the impeccable critique and audit findings, with what was fixed.
3. The result lists the migrations, routes, the poll payload, and a merge-danger section: what ships, how to revert, what to watch on 299's overlap.
4. The branch is pushed. No merge, no deploy.

## Out of scope
- Web push when the browser is closed.
- Voice and video calls.
- Forwarding, pinning, stories.
- Importing Messenger history.
- Mira's follow-ups in chat (spec 299).

## Budget
Large job. At most 2 attempts at the same failing step, then stop and report.
