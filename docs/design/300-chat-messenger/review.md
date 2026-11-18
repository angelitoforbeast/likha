# Review: Likha Chat works like Messenger (spec 300)

Method: impeccable `critique` (two isolated sub-agents: A = design review, B = detector + evidence) and impeccable `audit` (a third sub-agent), all read-only, on `resources/views/chat/index.blade.php` and `resources/views/components/chat-unread.blade.php`, with the screenshots in this folder. No browser overlay: the sub-agents had no browser tool and the detector's URL mode cannot sign in, so B scanned the logged-in HTML fetched with curl and the screenshots. The critique snapshot was not written to `.impeccable/` (outside the files the spec allows); this file is the record. The user questions at the end of a critique were skipped (non-interactive run); the open questions are in the result for the reviewer.

Screenshots: headless Chrome over CDP of `php artisan serve` on 127.0.0.1 with a local sqlite file seeded with placeholder people (no real data). `before-fixes/` holds five shots from before the fixes below; the others are after.

## Scores (before fixes)
- Critique (A): **26/40** (Acceptable). Strongest: colour discipline, Messenger-accurate grouping and reaction shapes, accessibility basics. Weakest: system status outside the page (alerts), consistency (one bell icon for two things; selected reaction looked like focus), recognition (long-press undiscoverable, tiny seen heads).
- Audit: **14/20** (Good). Accessibility 2, Performance 3, Theming 3, Responsive 3, Integrity 3.
- Detector: 0 findings on the Blade files (regex mode only); 4 on the rendered HTML, all judged false positives or inherited: `flat-type-hierarchy` (296/295 heading sizes in a dense panel), `pulsing-dot` ×2 (typing dots and the recording dot: live state, under reduced-motion), `dark-glow` (the 296 "New messages" pill shadow on a light page).

## Findings and what was done

| # | Sev | Finding (source) | Fixed? |
|---|---|---|---|
| 1 | P0 (A) / P1 (audit) | Alerts stop while the tab is hidden; Android Chrome throws on `new Notification()` without a service worker; the Alerts box claimed "Notifications are on" | **Partly.** Spec 300 says the poll does not run while the tab is hidden, and web push / service worker is a later spec, so the poll stays as specified. Fixed: the Alerts box now says alerts come "while Likha is open on screen in a tab", and when the browser refuses `new Notification` it says alerts come as a sound only. On return to the tab the count refreshes at once but what arrived meanwhile is not chimed (no burst). **Reviewer decision** (see result). |
| 2 | P1 (audit) | Focus fell to `<body>` when a poll redrew the thread or after reacting | **Fixed.** Every control in a message has a stable `data-k`; focus is restored after each redraw, also inside a reopened menu, reaction bar or "who reacted"; after reacting, focus returns to the message's reaction pill. |
| 3 | P1 (A) / P2 (audit) | "Who reacted" opened downward and was cut off by the composer (390) | **Fixed.** It opens above the message when there is no room below (`390-who-reacted.png` vs `before-fixes/`). |
| 4 | P1 (A) | Typing line / recording bar could hide the newest message | **Fixed.** Both keep the thread pinned to the bottom when you were at the bottom; the typing line has a fixed height. |
| 5 | P2 (A) | One bell meant both Alerts settings and Mute | **Fixed.** Alerts uses a sliders icon ("Alerts and sound"); the bell is only Mute. |
| 6 | P2 (A) | Selected reaction ring looked like the focus ring | **Fixed.** Your reaction is a filled soft-blue disc with a small dot under it; the ring is only focus. |
| 7 | P2 (A, B) | Search snippet hid the match; repeated the person's name in a direct chat | **Fixed.** Snippet starts near the match (server) and wraps to two lines; no sender in a direct chat except "You:" (`1280-search.png` vs `before-fixes/`). |
| 8 | P2 (audit) | Typing line re-announced every 3 s; recording timer announced every second; search results were a live region | **Fixed.** Typing changes the DOM only when the words change; the timer is hidden from screen readers and "Recording…", "10 seconds left", "Recording cancelled" are said once; search announces "3 chats, 5 messages found" / "Nothing found", and a failed search says so. |
| 9 | P2 (audit) | A recording could outlive its chat and send to another; kept going on blur | **Fixed.** A voice message goes to the chat it was recorded in; opening another chat, leaving the thread, window blur, hiding the tab or losing the pointer cancels it. |
| 10 | P2 (audit) | Audio players and recording blob URLs never released; playback continued after switching chats | **Fixed.** Opening another chat pauses and releases players; a recording's local URL is revoked once the server has it. |
| 11 | P2 (audit) | The chime's AudioContext could stay suspended | **Fixed.** `resume()` before each chime. |
| 12 | P2 (audit) | Long-press competed with text selection; `_long` could swallow a later tap; right-click taken over on desktop | **Fixed.** No callout / selection on bubbles for coarse pointers; the flag resets; contextmenu is intercepted only on touch devices. |
| 13 | P2 (audit, B) | Non-text contrast: sound switch off 1.47:1, voice track 1.36–1.87:1, play button 1.10:1, "mine" pill border 1.80:1 | **Fixed.** Switch has a `--lc-field-edge` border (3.3:1); grey-bubble track `--lc-field-edge`, own-bubble track dark navy with a white fill (10:1 fill vs track); play button has a border; mine border is the accent (5.2:1). |
| 14 | P2 (B) | Phone targets under 44 px: header bell / info, alerts button, reply / edit cancel, emoji buttons, reaction pill 22 px, History link ~17 px, play 32 px | **Fixed.** 44 px for header and bar buttons and emoji on phones; reaction pill 30 px on phones; History link has padding; play 36 px. |
| 15 | P2 (audit) | Edit mode not announced | **Fixed.** The input is described by "Editing message" and Send becomes "Save edit". |
| 16 | P2 (A) | Recording mic turned pink/red (outside the brief's palette) | **Fixed.** Accent-soft while recording; the pulsing red dot stays. |
| 17 | P3 | Active dot only `title`; seen heads `aria-label` on an `li`; emoji `aria-label` on a span; react button `aria-haspopup="true"` on a toolbar; who-reacted wording | **Fixed.** Screen-reader text "(active now)" in rows; visually hidden "Seen by …" span; emoji names as hidden text; `aria-haspopup` removed; heading "Who reacted", "Remove". |
| 18 | P3 (audit) | Spec-300 colours hard-coded | **Fixed.** `--lc-online`, `--lc-rec`, `--lc-mine-edge`, `--lc-knob`. Dot 10/8 px. |
| 19 | minor (A) | "Edited" had no time; group header showed no presence | **Fixed.** "Edited 8:13 AM"; "5 members · Active now" when someone is active. |
| 20 | P2 (audit) | Two polls on `/chat` (3 s chat poll + 15 s page poll) | **Not changed.** About 24 tiny requests a minute per open chat tab (37–79 B, ~40 ms each). Merging them needs the chat poll to carry alerts; left for a follow-up to keep this change small. |
| 21 | P2 (A) | Hover time shifts the layout and repeats the date under "Today" | **Not changed.** Inherited from 296 (`.lc-meta`), not part of this spec. |
| 22 | P2 (A) | Long-press is undiscoverable on phones | **Not changed.** Same gesture as Messenger, which the team uses daily; a tap still shows the ⋯ actions. |
| 23 | minor (A) | No shortcut to chat search, no ↑ to edit last message | **Not changed.** Out of scope; noted. |

## Verdict after fixes
Every P1 and every P2 that this spec owns is fixed except the background-alert limit, which the spec's own decisions set (no polling while hidden, no web push). That limit is the main thing the owner should know: on a phone, a Likha chat chimes and notifies only while Likha is open on screen.
