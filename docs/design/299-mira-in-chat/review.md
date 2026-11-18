# 299 design review: critique, audit, fixes

I took the screenshots in this folder from a local run:
- the server was `php artisan serve` on 127.0.0.1:8299;
- the database was a sqlite file in the developer's scratchpad, seeded by a throwaway test with placeholder people (Angelito the CEO, Joy Ramos, Ben Cruz), never a real database;
- the browser was headless Chrome driven over CDP.

The red storage banner comes from the dev machine's disk.

## Method

- **Critique and audit.** One isolated reviewer agent ran the `impeccable` critique and audit references on the changed Blade files, the brief, the 293/296 briefs and the first screenshots. It was single-context and read-only. No live overlay was used, because the sessions were headless.
- **Detector.** `impeccable detect --json` ran on `checklist/ai-followups.blade.php` and `checklist/partials/ai-followup-card.blade.php`: 0 findings before the fixes and 0 after.

## Scores before the fixes

- Critique: **30/40**. Each of the ten heuristics scored 3.
- Audit: **17/20**:

  | Dimension | Score |
  |---|---|
  | Accessibility | 3 |
  | Performance | 4 |
  | Theming | 3 |
  | Responsive | 3 |
  | Integrity | 4 |

## Contrast (Tailwind hex)

| Element | Colours | Ratio |
|---|---|---|
| Auto chip | violet-800 on violet-50 | 8.19:1 |
| Not sent to chat chip | amber-800 on amber-50 | 6.84:1 |
| Retry and Open chat | blue-700 on white | 6.70:1 |
| Time line | gray-500 on white | 4.83:1 |
| Switch tracks on gray-50 | on | 5.45:1 |
| Switch tracks on gray-50 | off | 4.63:1 |

## Fixed (commit `34aa1051`, plus `1ee749c5` from my own look at the first screenshots)

| # | Sev | Finding | Fix |
|---|---|---|---|
| 1 | P2 | An auto row read "Sent automatically" next to "Not sent to chat" | The time line says "Auto-approved <time>" |
| 2 | P2 | Old drafts waiting while auto-approve is on, with no explanation | Added the line "Made before auto-approve was on. These still wait for you." above the drafts |
| 3 | P2 | The chat-failure reason was only in `title`, which phones never show | A visible "Still on their checklist" after the chip, and a warning icon on the chip |
| 4 | P3 | Every Retry and Open chat had the same accessible name | Added `aria-label`s with the person's name |
| 5 | P3 | Retry had no pending state, so a double tap posted twice (the server was already idempotent) | "Sending…" and disabled after the first submit |
| 6 | P3 | Retry was about 38 px wide on phones | `min-w-[44px]` on phones |
| 7 | P3 | The switch's state line was not tied to the control, and auto looked active while the main switch was Off | Added `aria-describedby`. The track is violet-300 while the main switch is Off; the line already reads "Applies when the Marketing head is on" |
| 8 | P3 | "new ones" was vague in the header | Changed to "Auto-approve is on: new follow-ups go to staff at once, without your review." |
| – | own | The header said "Staff see one only after you approve it" while auto-approve was on | The line now follows the switch |
| – | own | An auto row could show "Edited by you" | `wasEdited()` is false when no person approved |

## Not changed, out of this spec's files (for spec 300 / 296 owners)

- Mira's thread header subtitle says "Direct chat". It could say "AI follow-ups from the Marketing head". This is in the chat JS.
- The chat page draws AI avatars in amber, while the inbox and the staff card use violet for AI. This is a colour mismatch between two surfaces; the chat view owns it.
- The task link in the bubble shows the full URL. ChatText only links raw http(s) URLs, so a short label would need a ChatText change.

## Screenshots

| File | What |
|---|---|
| `sidebar-chat-first-1280.png`, `sidebar-chat-first-390.png` | Chat first in Home, above Checklist (390 with the drawer open) |
| `followups-settings-1280.png`, `followups-settings-390.png` | The On/Off tile with Auto-approve, plus a draft made before auto was on |
| `followups-sent-1280.png`, `followups-sent-390.png` | The Auto chip, Auto-approved time, Not sent to chat with Retry, and Open chat |
| `mira-conversation-1280.png`, `mira-conversation-390.png` | Joy's Mira conversation: two follow-ups and her reply |
| `mira-in-list-390.png` | The Mira row in Joy's chat list |
| `ceo-reading-mira-1280.png` | The CEO reading it ("Mira and Joy Ramos") |
| `staff-card-auto-390.png`, `staff-card-auto-1280.png` | Staff cards: "approved by Angelito" and "sent automatically" |
