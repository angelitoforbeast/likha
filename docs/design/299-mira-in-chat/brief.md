# Design brief: Mira in Likha Chat, "the boss's note reaches the phone"

Surfaces (all existing, refined, not replaced):
- `/checklist/ai-followups` (CEO, `resources/views/checklist/ai-followups.blade.php`): an Auto-approve switch in Settings, an "Auto" chip, "Not sent to chat" with Retry, and "Open chat" on Sent rows.
- The staff card on `/checklist` (`resources/views/checklist/partials/ai-followup-card.blade.php`): "From Mira · sent automatically".
- Mira's conversation in `/chat`: data only (title, avatar, message text). The chat page's views and JS belong to spec 300 and are not edited here.
- The sidebar: Chat moves to the top of Home by a data migration; no sidebar code changes.

Mode: **Operate** everywhere. Shaped with `impeccable` (shape). No owner interview was possible in this session (no question tool): the answers come from spec 299 and the owner's words quoted there; assumptions are marked **(assumed)**. Visual authority is the incumbent: the 293 brief (`docs/design/293-ai-followups/brief.md`) for the inbox and card, the 296 brief for chat. Nothing here introduces a new colour, font or component shape.

## 1. Job and audience
| | CEO inbox | Staff (card and chat) |
|---|---|---|
| Who | The owner, the only reviewer | One marketing staff member |
| When | A few times a day, on a phone, often after the fact once auto-approve is on | Between tasks, on a phone, in Likha Chat the way a boss's Messenger message arrives |
| Need | Decide whether drafts go out without him; then audit what went out on its own and fix a send that failed | Hear the follow-up where they already look (chat), then open the task |
| Success | Can tell at a glance which sent follow-ups he approved and which went automatically; a failed chat send is visible and one tap fixes it | Tapping the link in Mira's message lands on the task row |

What is uniquely true: with auto-approve on, a message reaches staff that **no person read**. The UI must say so plainly on both sides: "sent automatically" on the staff card, "Auto" on the CEO's Sent row. It never pretends a person approved it.

## 2. Direction (incumbent world, no new visual decisions)
- **Violet stays the AI colour and only the AI colour** (293): the Auto-approve switch's on state is violet (it hands a decision to the AI, like the main switch); the "Auto" chip is violet-50 / violet-800 / violet-200 border, because it marks the AI acting alone. "Edited by you" stays grey (the CEO's own words).
- **Failure is amber, in words**: "Not sent to chat" chip in amber with the reason as the chip's title, and a small Retry button (blue text button, the inbox's quiet-button shape). Not red: the follow-up still reached the checklist; only the chat copy is missing.
- **Open chat** is a plain blue text link with a `fa-comment-dots` icon (the Chat link's icon in the sidebar), on Sent rows that reached chat.
- **Staff card**: same card; only the "From Mira · …" line changes. No new badge: the words carry it.

## 3. Inbox: Settings
- The header keeps its two tiles (On/Off switch, budget). Auto-approve is a **second switch inside the On/Off tile**, under a hairline, so the two switches about "how drafts go out" sit together and the budget tile is untouched **(assumed: "next to On/Off" read as the same tile; a third tile would push the tabs below the fold on a 390 px phone)**.
  - Label "Auto-approve", state line: Off "Off: drafts wait for your review"; On "On: new drafts go to staff at once, no review".
  - When the main switch is Off, the Auto-approve switch still works but its line reads "Applies when the Marketing head is on" (nothing is drafted or sent while Off).
  - Switching it **on** asks once in the browser (`confirm`): "New drafts will go to staff without your review. Turn on auto-approve?" Switching off needs no confirm. **(assumed: one confirm is the lightest guard for the only no-review path.)**
  - Flash after saving: "Auto-approve is on. New drafts go to staff at once." / "Auto-approve is off. New drafts wait for your review."
- Drafts tab, when auto-approve is on and there are drafts: those are old drafts made while it was off; the empty state of Drafts when on says "Auto-approve is on: new follow-ups go out at once. See Sent."

## 4. Inbox: Sent rows
```
(JR) Joy Ramos · Post 3 stories          Nov 18 · by 5:00 PM
Hi Joy, please post the 3 stories and submit by 5:00 PM.
[Waiting] [Auto] Sent 4:02 PM       💬 Open chat
[Waiting] [Not sent to chat] Retry   (when the chat post failed)
```
- "Auto" chip next to the state chip. The "Approved <time>" line becomes "Sent automatically <time>" for auto rows.
- "Open chat" opens `/chat?c=<id>` in the same tab (the CEO reads there; 296 says the owner reads every conversation).
- Retry only on Waiting rows (today, not done); on Done/Expired rows the "Not sent to chat" chip stays as a record without a button (a stale "by 3:00 PM" message would mislead). After Retry the page reloads with a flash: "Sent to Joy in Likha Chat." or "Still not sent to chat. Try again later."

## 5. Staff card
- "From Mira · approved by Angelito" (unchanged) or "From Mira · sent automatically".

## 6. Mira's conversation (data the chat page renders)
- Title "Mira", the AI avatar (296's amber AI avatar, initials "M"), every message shown with the AI mark. For the CEO reading it: "Mira and Joy Ramos".
- Message body (plain text, escaped like any chat text; the link is the only markup ChatText makes):
  ```
  Hi Joy, please post the 3 stories and submit them on the checklist by 5:00 PM. Thank you!

  Due by 5:00 PM · Post 3 stories on the main page
  Open the task: https://<app>/checklist#task-12
  ```
- The staff member can reply; no answer from Mira in this spec. The empty composer and everything else are 296/300's.

## 7. Sidebar
- Chat is the first link in Home, above Checklist, for every role that sees it. The Chat section keeps its other links (Chatbot, Chatblast…); for a role whose only Chat-section link was Chat, the section simply no longer shows.

## 8. States and ranges
- Message ≤ 240 characters + two short lines; task titles up to 255 (shown up to 120 in chat).
- Sent rows: Waiting / Done / Expired × approved by you / Auto × in chat / not sent / never tried (rows from before this spec: no chip, no link).
- Phone first: chips wrap; the Retry and Open chat targets are 44 px tall on phones.

## 9. Constraints
- Tailwind CDN + Alpine as the page uses now; no new assets. Do not edit `resources/views/chat/*`, chat JS, the sidebar or the layout.
- Accessibility: both switches are `role="switch"` with `aria-checked` and a label; chips carry words, never colour alone.
