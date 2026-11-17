# Design brief: Likha Chat (`/chat`)

Surface: `/chat` (Blade `resources/views/chat/index.blade.php`, inside `<x-layout>`, under the fixed 64 px Likha nav).
Mode: **Operate.** The visitor completes a task (read, reply, start a chat, make a group) many times a day, mostly on a phone. Ambition shows as precision and calm, not expression: patterns people already know from Messenger earn their place.
Files in this folder: this brief, the screenshots of the built page (`390-*.png`, `1280-*.png`), and `review.md` (impeccable critique and audit findings, with what was fixed).

Assumptions (no interview was possible in this run; inferred from spec 296 only): PRODUCT.md was not written, because the spec allows only this folder. Likha's incumbent look is the visual authority (Tailwind gray scale, white panels, gray-800 nav, Font Awesome icons, system font). The owner's words are "para messenger", so the shape is Messenger's, in Likha's colours.

## 1. Job and audience
- **Who:** about 10-30 staff (encoders, marketing, OICs) and the owner (CEO). They move between Likha pages all day and today talk in Facebook Messenger on their phones.
- **Need:** say something to one person or a group and see the answer arrive, without leaving Likha. Glance: who wrote, is there anything unread, did they see it.
- **Success:** a message is sent in one keystroke, new messages appear within about 3 s, and nobody wonders whether the owner can read the chat (the page says it).

## 2. Direction
**"A quiet back room of the office."** White panels on Likha's gray-50, one accent, nothing else coloured. Everything that is not a message is gray; the only blue is your own words, the unread count and the primary button. Messages carry the colour, so the eye goes to the conversation, not the chrome.

- **Accent:** Likha's blue, one shade: `#2563eb` (blue-600). White text on it is 5.2:1. Hover `#1d4ed8`. Unread pill, own bubble, send button, focus ring. No second hue except a muted red for "Remove" actions and an amber hint on the AI mark.
- **Type:** the system UI stack Likha uses everywhere (no new font in an Operate surface inside an existing app). Body 15 px on phones / 14 px on desktop, names 600 weight, meta 12 px gray-500 (≥4.5:1 on white). Tabular numerals for times and counts.
- **Avatars:** initials in a circle; the tint is derived from the person's id from a fixed set of six soft gray-blue-green backgrounds with dark text (all ≥4.5:1), so people are told apart without adding brand colours. Groups show a two-letter mark from the group name on a gray square with a rounded corner (square = group, circle = person, readable without colour).
- **AI member:** name plus a small "AI" mark (amber-50 background, amber-800 text, 10 px uppercase, rounded). No robot emoji.
- **What makes it ours:** the transparency line. The thread header always carries "The owner can read every conversation in Likha Chat" as a small lock line under the title, not a banner that shouts. For the owner reading a chat they are not in, the composer is replaced by a single bar: "You are reading as the owner. Join to post" with a Join button.

## 3. Layout and topology
```
Desktop (≥ 1024 px)                                    Phone (< 1024 px)
┌───────────── 64 px Likha nav ─────────────┐          one column:
│ List 340 px          │ Thread             │          [List]  →tap→  [Thread with ← back]
│ Chats  [All][New]    │ ← Title · members  │          the URL keeps ?c=<id>, so Back and
│ search people/chats  │ lock line          │          refresh land in the right place
│ row row row …        │ messages …         │
│                      │ composer           │
└──────────────────────┴────────────────────┘
```
- The page fills the viewport under the nav (`height: calc(100dvh - 64px)`); the list and the message area scroll on their own; the composer sticks to the bottom (safe-area inset on phones).
- **List row:** avatar 40 px, name (600, one line, ellipsis), time at the right (12 px), last line "Ana: text" or "You: text" one line ellipsis, unread pill at the right in accent when > 0 and the name goes 700. Selected row: gray-100 background. Rows are 64 px, full-width buttons/links (big tap target).
- **List header:** "Chats", a "New chat" button (pen icon + label), and for the CEO a two-state segmented control "Mine | All conversations".
- **New chat:** a panel that replaces the list (not a modal; a phone keeps one column): a search field focused on open, people results (avatar, name, role), "Direct" on tap; for the CEO and OICs a "New group" mode with a name field and checkable people, then "Create group".
- **Thread header:** back arrow (phones), avatar, title, member count or the other person's role, a "Group info" button for groups (members, roles, rename, add, remove, leave), lock line.
- **Messages:** others left with avatar (only on the first of a run) and name above the first of a run in groups; own right in accent with white text; bubbles 18 px radius, max 75% width; consecutive messages from one sender within 5 minutes stack tightly (2 px), other gaps 12 px. Day separator: centered 12 px gray label ("Today", "Yesterday", "Mon, Nov 16"). Time: shown on hover and focus (desktop) and on tap (phones) under the bubble. Reply: a quoted block above the bubble (sender name, one-two lines, a 2 px rule on its start side inside the bubble only), clicking it scrolls to the original. Removed: italic gray "Message removed" in an outline bubble, no menu.
- **Attachments:** images in a grid inside the message (1 → full width up to 280 px; 2-5 → 2 columns of squares, cover crop), tap opens the full image in a lightbox (Esc and close button). Files: a row with file icon, name (ellipsis), size, as a download link.
- **Message actions:** a small "…" button on hover/focus (always visible on touch for the long-pressed row): Reply, Remove (own only). Plain menu, no reactions.
- **Seen by:** under your last message, right aligned, "Seen by" plus up to 5 initials circles 16 px and "+N".
- **Composer:** reply preview bar (quote + ×) above; paper-clip button (up to 5 files, chips with name, size and ×), auto-growing textarea (1 to 6 lines), Send button (paper-plane icon with "Send" as the accessible name). Enter sends, Shift+Enter new line; the counter appears only past 3,600 of 4,000 characters.

## 4. States and ranges
- **No conversations:** centered in the list: "No chats yet" and one line "Start a direct chat or a group with the team." plus the New chat button. On desktop the thread pane shows "Pick a chat on the left, or start a new one."
- **Empty thread:** "No messages yet. Say hello to <name>." centered above the composer.
- **Left / removed:** the composer is replaced by "You left this group" (gray bar); messages up to the leave stay readable.
- **Sending:** the message appears at once with lowered opacity and "Sending…"; on failure "Not sent. Retry" in red text with a retry button. Upload errors name the file and the rule ("photo.heic is not an allowed type", "over 10 MB").
- **Polling:** silent. If the poll fails three times, a thin bar under the header: "Reconnecting…". Paused while the tab is hidden.
- **Ranges:** names up to 60 characters (ellipsis), group names up to 80, messages up to 4,000 characters (wrap anywhere for long links), 0-500 conversations in the list, threads load the last 50 then "Load earlier".

## 5. Interaction details
- Focus visible everywhere: 2 px accent ring with 2 px offset. Text selection tinted with accent at 20%.
- Keyboard: the list is a list of links; Esc closes panels and the lightbox; the composer is reachable with Tab after the thread.
- Motion: one moment only, a new incoming message slides up 6 px and fades in 160 ms ease-out; `prefers-reduced-motion` removes it. Opening a thread does not animate.
- Scrolling: opening a thread lands at the bottom; a new message keeps you at the bottom only if you were already there, else a "New messages ↓" pill appears.

## 6. Boundaries and anti-goals
- Do not edit the main layout or nav (spec 295). Everything lives in the chat view.
- No reactions, editing, voice, typing indicators or online dots (out of scope). No gradients, glass, coloured side stripes on rows, or emoji for icons (Font Awesome, as the rest of Likha).
- Not dark: Likha's pages are light, used in lit offices and on phones in daylight.
- English everywhere, including errors.
