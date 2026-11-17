# Likha Chat: impeccable critique and audit (spec 296)

Run on the built page (`resources/views/chat/index.blade.php`) against a local seeded sqlite `artisan serve` on 127.0.0.1 with placeholder people. Three isolated reviewers: critique A (design review), critique B (detector + in-page overlay and measurements), and the technical audit. All findings below were fixed in commits `b1bab18d` and `ac876fa6` unless marked otherwise.

## Scores (before fixes)
- Critique A, Nielsen heuristics: **25/40** (Acceptable). Design specificity: "authored for this product" (the lock line and the owner's Join bar give it its own character).
- Audit: **12/20** (Acceptable): accessibility 2, performance 3, theming 3, responsive 2, implementation integrity 2.
- Detector: 1 finding (`broken-image`, the empty lightbox `<img>`); after the fixes it reports **0 findings** (exit 0).

## Findings and what was done

| Sev | Finding (source) | Fix |
|---|---|---|
| P0 | No back arrow on phones: a later `.lc-back{display:none !important}` beat the phone rule; `replaceState` made browser Back leave `/chat` (A, audit) | Back arrow hidden only at ≥1024 px; on phones opening a thread `pushState`s, the arrow and browser Back return to the list (`popstate`). Checked in headless Chrome at 390 px: view goes back to `list`, URL `/chat`. |
| P1 | Whole message list was an `aria-live` region and re-rendered every poll, so screen readers re-read the thread (A, audit) | List is no longer live; a hidden `role="status"` announces only new messages from others ("Ana Cruz: …"). |
| P1 | Row `aria-label` hid the last line and time; unread count only in the label (A, audit) | Label removed; the row's own text is read, plus hidden "N unread". |
| P1 | Poll re-renders closed the "…" menu and dropped focus to `<body>` (audit) | Focus (row, action button, quote, file) and an open menu are restored after each re-render. |
| P1 | Reply/Remove unreachable by keyboard below 1024 px (audit) | The action button stays in the tab order (transparent until tapped or focused). |
| P1 | Blue focus ring invisible on your own blue bubble; reply quote in own bubble 3.9:1 (audit, B) | White focus ring inside own bubbles; quote background darkened (white text now above 4.5:1). |
| P1 | No sender or time for screen readers on own messages and direct chats (audit) | Each message starts with hidden "You / name, time:". |
| P1 | Panels did not move or return focus; content behind Group info stayed tabbable (audit) | Group info focuses its back button and makes the thread `inert`; closing (button or Esc) returns focus to the opener; same for New chat. |
| P1 | iOS zooms into 14-15 px fields (A) | Fields are 16 px on phones. |
| P1 | A failed send looked sent; raw "Failed to fetch" (A) | Failed bubble turns white with a red ring, icon and the reason; Retry is 32 px tall; network errors read "No connection. Check your internet and try again." |
| P2 | Composer placeholder 2.4:1; time on the selected row 4.3:1; small grey text 4.39:1 on hover (B, audit) | Placeholder uses the faint token; faint token darkened to `#5b6270` (≥5.3:1 on every panel background); selected row is gray-100 as the brief says. |
| P2 | Field borders 1.47:1, selected segment 1.10:1 (audit) | Field edge token `#868e9a` (3:1); selected segment gets an edge. |
| P2 | Message menu: no arrow keys, no `aria-expanded`, Esc lost focus, every button "Message actions" (audit) | Arrow keys, Esc returns to the button, `aria-expanded`, label names the message ("Actions for the message from Ben Reyes"). Long-press / right-click opens it too (A). |
| P2 | Lightbox not trapping focus (A, audit) | Tab cycles between "Open original" and Close; focus returns to the photo if it still exists. |
| P2 | CEO "All": chats she isn't in had no marker; direct chats drawn as group squares (A) | "Reading" tag; non-member direct chats get a gray circle. |
| P2 | "AI" mark 10 px (B) | 11 px; a space before it so it is not read "MiraAI" (audit). |
| P2 | Empty state: two "New chat" buttons plus an empty search field; right pane said "Pick a chat on the left" (A) | Search hidden when there are no chats; right pane says "Start your first chat with New chat." |
| P3 | Join error used `alert()`; "Replying to yourself" (A) | Inline error in the owner's bar; "Replying to your message". |
| P3 | A poll landing while a chat opened could put its messages under the old chat (audit) | Poll merges only into the thread that is loaded. |
| P3 | Two `<h1>` (the layout's covered heading and "Chats") (audit) | "Chats" is an `h2`. |
| P3 | `fitTop` only on resize (audit) | Also on `load`. |
| — | Detector `broken-image` on the lightbox `<img alt="">` (false positive: filled on open) | The image is now created when the lightbox opens; detector clean. |

## Not changed (with reason)
- **The lock line wording for the owner** (A suggested "As the owner, you can read every conversation"): kept identical for everyone, because the spec pins the sentence "The owner can read every conversation in Likha Chat" in the header for everyone.
- **Whole-list re-render on change** (audit, performance 3): lists are at most 500 rows and threads load 50 at a time; focus and menus are now preserved, so the cost is a few ms per change. A keyed diff is left for later if threads grow.
- **Hard-coded colours outside tokens** (audit, theming 3): avatar tones, the AI mark and the lightbox backdrop are fixed values by design (one light theme, Likha has no dark mode).
- **400% zoom leaves a small message area** (audit, P2): the fixed nav (64 px, not ours to change, spec 295) and the composer take most of a 256 px-high viewport.
- **Keyboard shortcut to switch chats, jump to first unread, search inside messages** (A, Alex persona): out of this spec's scope.
- **Owner questions raised by A** (should staff see when the owner has read a chat; a one-time acknowledgment instead of the line on every thread): for the owner, listed in the result.

## Screenshots (after fixes)
- 390 px: `390-list.png`, `390-group-thread.png` (bottom of the group thread: photo, Seen by), `390-group-photos-reply.png` (two photos and a reply quote), `390-new-chat.png` (new chat search).
- 1280 px: `1280-list-and-thread.png` (list and thread side by side), `1280-direct.png` (a direct chat), `1280-empty.png` (no chats), `1280-ceo-reading.png` (the owner reading a group she is not in, "All conversations" with "Reading" tags, Join bar).
