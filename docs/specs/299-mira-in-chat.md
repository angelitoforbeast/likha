# 299 · Mira in Likha Chat: follow-ups sent in chat, auto-approve, Chat first in the sidebar

## Why
The owner, 2026-11-18, after 293 and 296 went live:
- "autoapprove na to" about `/checklist/ai-followups`;
- "may bagong route ba tayo para dito, yung parang magiging messenger talaga?";
- "priority yung chat sa listahan, sa ibabaw. sa ibabaw ng checklist".

The Marketing head (spec 293) drafts follow-ups that only show as a card on the staff member's `/checklist`. He wants them to go out without his review, and to reach the staff in Likha Chat (spec 296), the way a boss's message reaches them in Messenger. He also wants Chat at the very top of the sidebar.

## Stories and cases
**MC-1 Chat first (P1).** The Chat link is the first link of the sidebar, in the Home section above Checklist, for every role that sees it.
- On a fresh database and on production, the migration moves only the `chat` link: no other link changes section or order.
- Rolling back puts it back where it was.

**MC-2 Auto-approve (P1).** The follow-ups page gets an **Auto-approve** switch in its Settings, next to On/Off.
- Off by default (the owner switches it on after the deploy).
- When it is on, each new draft is approved the moment it is made, with no reviewer. It then goes to the staff exactly as an approved one does, as a card and in chat (MC-3).
- The staff card reads "From Mira · sent automatically" instead of "approved by Angelito".
- The Sent tab marks these with an "Auto" chip.
- The budget, the window (9 AM to 9 PM), the per-person daily limit and the On/Off switch work as before. When the main switch is Off, nothing is drafted or sent.
- Drafts made while it was off still wait in Drafts for review.
- Only the CEO can change the switch (the same check as the other settings), tested.

**MC-3 The follow-up in chat (P1).** When a follow-up is approved, by the owner or automatically, Mira sends it to that staff member in Likha Chat, as an AI message (`sender_ai = 'mira'`, shown as "Mira" with the AI mark).
- Each staff member has one conversation with Mira, reused for every follow-up. Its title is "Mira".
- Mira posts a message, never an empty or system line. It contains the follow-up text and the deadline ("by 3:00 PM"), and links to the task on `/checklist` (the same `#task-<id>` anchor the card uses).
- The staff member can reply in that conversation. Replies are kept. The CEO reads them, as he reads every conversation (296).
- Mira does not answer replies in this spec.
- Editing the message on approve (the owner's edits) sends the edited text. A rejected or expired draft sends nothing.
- Sending is idempotent: approving twice, or a retried job, posts once. Keep the message id on the follow-up row, or use an equivalent check.
- If posting to chat fails, the follow-up is still approved and shows on the checklist. The failure is logged and the inbox shows "Not sent to chat", with a retry button for the CEO.

**MC-4 Open the chat from the inbox (P2).** Each sent follow-up in the inbox has an "Open chat" link to the staff member's Mira conversation.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Auto-approve is a setting row `ai_followups.auto_approve` in `app_settings`, read and saved through `AiFollowups::settings()` / `saveSettings()` | Lean schema: settings already live in rows there |
| The chat message is in addition to the checklist card, not instead of it | The card is where the work is; chat is how they hear it |
| Mira is an AI sender, not a user account | 296's CH-5 already shows AI senders (`config('chat.ai_members')`) |
| No new table | Mira's conversation fits `chat_conversations` / `chat_members`: decide how a one-human conversation with an AI is keyed (for example a `direct_key` like `ai:mira:<user_id>`, or `kind = 'ai'`), the smallest change that keeps 296's access rules and tests true |

## Constraints
- Spec 300 (Messenger features in Likha Chat) is being built at the same time in `projects/likha-wt300`. It owns the chat page, its views, scripts and controllers, and the sidebar badge. Here:
  - put the posting in a new class, e.g. `app/Services/Chat/AiPost.php`;
  - touch `app/Services/Chat/ChatAccess.php` and `ChatPresenter.php` only where a one-human AI conversation needs it, with tests;
  - do not edit `resources/views/chat/*` or the chat JS.
- Who may open Mira's conversation: that staff member and the CEO. 296's membership checks must stay true for every other conversation; test that a third person gets 403.
- The nav change is a migration in the style of `2026_11_28_100000_narrow_chat_nav_link_pattern`, reading the current section and order columns from `2026_11_27_100000_add_nav_sections_and_pins`.
- Comments in the language and style of the file around them. Tests go in `tests/Feature/Checklist/` and `tests/Feature/Chat/`. The full suite adds no new failures compared with base 2940b58b. The known base failures are ExampleTest, ItemTableFitScriptTest and SharedPagesBasePinTest `/owner/private`; the Chatblast Speed tests may fail under load but pass alone.

## Threat model and risk
- New automatic path: AI text reaches staff with no human review when auto-approve is on. The text is escaped like any chat message, and the follow-up's length limit holds.
- Access: one new conversation shape. Membership is tested.
- Risk tier: medium (no new public surface; one new automatic send, behind a switch that is off by default).

## Allowed commands
- Through Herd PHP (`C:\Users\Forbeast\.config\herd\bin\php.bat`):
  - `composer.bat install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`
  - `php.bat artisan test` (with filters) and `vendor/bin/phpunit`
  - `php.bat artisan route:list` and `php.bat artisan view:cache`
- `php.bat artisan serve` on 127.0.0.1, with a local seeded sqlite database only (never the real database).
- A headless browser for screenshots of 127.0.0.1 pages only.
- The `impeccable` skill.
- `git add`, `git commit` and `git push -u origin feat/299-mira-in-chat`.

## Done when
1. Tests pass for MC-1 to MC-4. They include:
   - the migration (only `chat` moves; rollback);
   - the auto-approve switch: CEO only, off by default, auto drafts approved with no reviewer, card text, Auto chip;
   - On/Off, budget and window still respected;
   - one Mira conversation per person, reused;
   - message content and link;
   - idempotent posting;
   - a failed post leaves the follow-up approved, and the retry works;
   - access: the person and the CEO, 403 for others.

   The full suite adds no new failures compared with base 2940b58b.
2. Screenshots at 390 px and 1280 px in `docs/design/299-mira-in-chat/`:
   - the sidebar with Chat on top;
   - the follow-ups Settings with Auto-approve;
   - a staff member's Mira conversation with two follow-ups and a reply;
   - the staff card "sent automatically".
3. The result lists the migrations, the settings key, how the Mira conversation is keyed, and a merge-danger section: what ships, how to switch it off, how to revert.
4. The branch is pushed. No merge, no deploy.

## Out of scope
- Mira answering replies.
- Other AI heads posting.
- Messenger features in the chat page (spec 300).
- Push notifications.

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and report.
