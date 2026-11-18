# Design brief: Likha Chat works like Messenger (`/chat`, spec 300)

Surface: `/chat` (`resources/views/chat/index.blade.php`) plus one small piece on every signed-in Likha page (the Chat link's unread count in 295's sidebar, the tab title, alerts).
Mode: **Operate.** Staff read and answer many times a day, mostly on phones; the owner reads across the team. Ambition shows as precision: Messenger's patterns, which every person on the team already knows by hand, in Likha's quiet look.

Visual authority: `docs/design/296-likha-chat/brief.md` and the shipped page (white panels on gray-50, one blue accent `#2563eb`, system font, Font Awesome icons, initials avatars, square = group / circle = person). This spec **extends** that world; it does not replace it.

Assumptions (non-interactive run, impeccable's interview could not be asked; inferred from spec 300 only, marked so the reviewer can correct them):
- A1. "Feels like Messenger" means Messenger's *gestures and shapes* (bubble grouping, hover reactions, long-press, hold-to-record, seen heads, green dot), not its colours: the bubble stays Likha blue `#2563eb`, not Messenger's gradient.
- A2. Emoji are content here (the six reactions), so they are real emoji; every other icon stays Font Awesome as in 296.
- A3. The alert sound is a short, soft two-note blip generated in the browser (no audio file), quiet enough for an office.

## 1. Job and audience
- **Who:** 10-30 staff and the owner. Today their talk is in Facebook Messenger; they will move only if Likha Chat does what their thumbs expect.
- **Need:** know there is something new without opening `/chat`; answer with one tap (a reaction) or a few words; fix a typo; send a voice note while packing; find what was said last week; silence a noisy group.
- **Success:** from any Likha page you see "Chat 3" in the sidebar and "(3) …" in the tab; a new message chimes and shows a notification; reacting, editing and recording each take one gesture you already know.

## 2. Direction: "the same back room, now with the habits"
Nothing new is coloured except where meaning needs it:
- **Red** only for the sidebar's unread count (as the checklist badge already is) — the one place an alert must be seen from across the page.
- **Green** only for the active dot (8 px, white ring) on avatars — the universal "online" sign.
- **Blue** stays the only accent in the chat: own bubbles, unread pills, send, focus, the recording bar's timer.
- **Muted** conversations are gray, never hidden: a bell-slash icon after the name and a gray (not blue) unread pill.
- Reactions sit on the bubble's lower edge as a small white pill with a hairline border and soft shadow (the one place a shadow is allowed, because it overlaps the bubble).

## 3. Layout and interaction

**Sidebar and tab (every page)**
- Chat link: red count at the row's right (same component and size as the checklist badge), red dot on the icon in the 64 px rail, the same in the phone drawer. "99+" past 99. Hidden at 0.
- Tab title: "(3) " before the page's own title. Nothing when 0.
- Alerts: a browser notification "Ana Cruz" / "in Ops team" line / first 80 characters (or "Sent a photo", "Voice message"); click focuses the tab and opens `/chat?c=<id>`. Sound plays with it. No alert for the open, visible conversation, own messages, muted chats, or anything already read.

**List (left / first screen)**
- Search field ("Search chats and messages"). Under 2 characters it filters names locally as today. From 2 characters it asks the server and shows two groups: **Chats** (rows as usual) and **Messages** (avatar, conversation name, a one-line snippet with the match in bold, date). Tapping a message result opens the conversation scrolled to it, with a brief highlight (accent-soft background fading over 1.5 s; none with reduced motion).
- Direct rows show the green dot on the avatar when the person is active (≤ 5 min). Muted rows: bell-slash after the name, gray pill.
- List header gets a bell button (Font Awesome bell) opening a small "Alerts" popover: "Allow notifications" (only while the browser has not decided; it asks once, on that click), status text when allowed or blocked ("Notifications are blocked in this browser's site settings"), and a "Sound" switch.

**Thread header**
- Direct: subtitle "Active now" (with the green dot on the avatar) / "Active 12m ago" / the role as before when longer than an hour.
- A bell button with a menu: "Mute for 8 hours", "Mute until I turn it back on", or "Unmute" + "Muted until 6:40 PM" / "Muted" as the subtitle's second half.

**Messages**
- Grouping like Messenger: a run from one sender within 5 minutes stacks at 2 px; the inner corners of a run are 4 px (first: big top, small bottom; middle: small both; last: small top) on the sender's side; the avatar sits on the last bubble of a run.
- Desktop hover (and keyboard focus) shows two small round buttons beside the bubble: a smiley (react) and "…" (Reply, Edit, Remove). The smiley opens a horizontal bar of the six emoji (👍 ❤️ 😆 😮 😢 😡) above the bubble; the current one is ringed in blue. Phone: long-press (450 ms) a bubble opens the same bar plus the action sheet; a short tap shows the time as today.
- Reaction pill: up to three distinct emoji then the total ("👍❤️ 3"); tapping it opens a small list "Who reacted" (avatar, name, emoji) — yours first with "Tap to remove".
- "Edited" in 12 px gray beside the time line under the bubble (always visible, not only on hover). The owner sees "Edited · History", which opens the earlier versions with times.
- Editing: menu → Edit. The composer turns into an edit bar ("Editing message" + ×) with the text, Enter saves, Esc cancels. After 15 minutes "Edit" is not offered.
- Typing: a line at the bottom of the messages, above the composer, "Rey is typing…" with three gray dots pulsing (static with reduced motion); "Rey and Ana are typing…"; "Several people are typing…".
- Seen heads: each other member's 16 px initials circle under the last message they read (right-aligned), replacing the single "Seen by" row; their names in the accessible label.
- Voice message: a bubble with a round play/pause button, a thin progress track and the duration ("0:42"); own in blue, others in gray, like text bubbles.
- Photos: as 296 (preview grid, lightbox).
- New messages while scrolled up: the "New messages ↓" pill (as 296); arrivals scroll smoothly when you are at the bottom.

**Composer**
- Desktop: Enter sends, Shift+Enter new line. Phone (coarse pointer): Enter is a new line; send is a 44 px blue circle.
- Mic button (Font Awesome microphone) beside the paper clip, only where `MediaRecorder` and `getUserMedia` exist. Hold to record (pointer down), release to send; the composer becomes a recording bar: red dot pulsing, timer "0:07 / 2:00", "Release to send · slide away to cancel" (phone) or "Release to send · Esc to cancel" (desktop). Under 1 s = cancelled. At 2:00 it sends by itself. Keyboard: Enter/Space on the mic toggles start/stop-and-send.

## 4. States and ranges
- Unread 0 / 1-99 / 99+. Reactions 0-30 people on one message. Edits 0-many (history list scrolls). Search: 0 results ("Nothing found for "…"") / up to 30 messages + matching chats. Voice 1-120 s, up to 5 MB.
- Notifications: not supported (button hidden), default (button), granted ("Notifications are on"), denied (instruction text). Sound is **on** by default (Messenger's default); the switch is remembered per user in this browser.
- Errors (reaction, edit, mute, voice upload) show in the composer's error line in plain English ("This message can no longer be edited.", "Voice message is too long.").
- Mic permission refused: "Allow the microphone in the browser to record." in the error line.

## 5. Boundaries and anti-goals
- The layout change is only the badge (and the one alert script include) — 295's sidebar look is untouched.
- No new colours beyond red count and green dot. No gradients, no glass, no emoji as UI icons, no animated waveform art.
- English everywhere. Visible focus everywhere (2 px blue ring). Contrast AA. Every gesture (hover, long-press, hold) has a keyboard/click equivalent.
- No asking for notification permission on load; never a modal on arrival.
