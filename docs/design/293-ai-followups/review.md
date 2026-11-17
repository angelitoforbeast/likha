# 293 AI follow-ups: impeccable critique and audit, and what was fixed

Run on 2026-11-18 against a local seeded run (sqlite file in the developer's scratchpad, `php artisan serve` on 127.0.0.1:8293, headless Chrome). Screenshots before the fixes stayed in the scratchpad. The screenshots in this folder were taken after the fixes.

Method: `critique` used two isolated sub-agents. Assessment A was the design review: source, brief, spec AF-6 and screenshots, with no detector output. Assessment B was the detector: `impeccable detect` on the two Blade files, plus `--cdp` scans of the logged-in CEO inbox at 1280 px and Joy's `/checklist` at 390 px. The live overlay was skipped because the sessions were headless, with no human tab. `audit` ran as a third sub-agent: the five dimensions, with contrast computed from the Tailwind hex values. After the fixes, `detect` ran once more on the two Blade files and found nothing. That source scan is regex-only; the rendered checks were the `--cdp` scans above.

## Scores (before the fixes)

| Review | Score | Band |
|---|---|---|
| Critique (Nielsen, A) | 26 / 40 | Acceptable |
| Audit | 14 / 20 | Good |

**Critique heuristics:**

| Heuristic | Score |
|---|---|
| Status | 3 |
| Real world | 2 |
| Control | 3 |
| Consistency | 2 |
| Error prevention | 2 |
| Recognition | 3 |
| Efficiency | 3 |
| Minimalism | 2 |
| Recovery | 3 |
| Help | 2 |

**Audit dimensions:**

| Dimension | Score |
|---|---|
| A11y | 2 |
| Performance | 3 |
| Theming | 3 (against the app: no tokens or dark mode exist anywhere in Likha) |
| Responsive | 3 |
| Integrity | 3, Pass |

**Detector findings:**
- **Source:** 1 finding: `border-accent-on-rounded` on the tab underline. False positive.
- **CEO inbox, 1280 px:** 5 `nested-cards`. The bordered violet draft block was real, though minor; the two header tiles were false positives.
- **Staff `/checklist`, 390 px:** 13 findings.
  - 1 low-contrast on the "approved" count. The class is old, but the line was rewritten for this spec, so it was fixed.
  - 1 straggler advisory on the card message.
  - 11 low-contrast on the existing table. That code is older and outside this spec.

## Fixed

| Finding | Severity, from | Fix |
|---|---|---|
| A, E and R acted on the first draft with nothing focused, and on any focused control (WCAG 2.1.4) | P1 audit, P2 critique | Shortcuts act only when a draft card itself has focus. The first press with nothing focused only focuses the first draft. Keys are ignored on buttons, links and fields. |
| Editing the deadline left the old time in the message text | P1 critique | `syncClock()`: changing the time field rewrites the time in the message. `ceo-edit.png` shows 7:30 PM changed to 8:00 PM. |
| Undo could only be reached with a mouse within 5 seconds | P1 critique, P2 audit | **U** undoes, and the toast says "Press U to undo". The timer pauses while the mouse or focus is on the toast. A screen-reader live region is always in the page. Undo is a 44 px button. |
| "N approved" count at 3.3:1 | P1 audit | `text-green-600` changed to `text-green-700` (5.0:1). |
| Staff card sat under the table's column headers and read as a row; the counts line was squeezed to ~50 px at 390 px | P2 critique | The card is now the first thing on the page, above the page header. The counts line gets its own full-width row on phones (`staff-390.png`). |
| "Open task" did not move focus and did not scroll in this layout (the page scrolls inside a container) | P2 audit, found in testing | `scrollIntoView` with a scroll margin for the sticky header, and focus moved to the task (`staff-open-task.png`). |
| Tabs used `role="tab"` without tab behaviour | P2 audit | Plain navigation links with `aria-current="page"`. |
| Off switch track at 1.4:1 | P2 audit | Off track is `bg-gray-500`. |
| A failed settings save closed the form and lost the input | P2 audit | The form stays open on error and keeps `old()` values. |
| Small "Edit" link in the budget tile | P2 audit | Renamed "Settings", with a larger hit area. |
| Action buttons 40 px on phones | P2 critique (Casey) | 44 px under `sm`. |
| The "Not approved" reason was hidden behind a disclosure | P2 critique | Shown inline as one line ("Why not approved: ..."), and also as the chip's `title`. |
| One agent with several names | P2 critique | The sub line now says "Mira, the AI marketing head, drafts follow-ups ...". The spec's names stay: "Marketing head" for the switch, "From Mira" on the staff card. |
| Violet "Edited" tag marked the CEO's change, not the AI's words | audit drift, critique | Grey "Edited by you". |
| The draft block was a bordered card inside a card | detector, B | Border removed; it is now a violet tint only. |
| "PM." alone on the last line of the staff message | detector advisory | `text-wrap: pretty` on every message. |
| "Waiting · Not done yet · approved by Angelito" on every Sent row | critique minor | The chip carries the state. The line now says "Approved <time>", or the Done/Expired reason. |
| J/K scrolling ignored reduced motion; keyboard legend unstyled; card focus ring showed on mouse click; the toast could cover the last card | P3 | All fixed: `matchMedia` check, styled key caps, `focus-visible` only, bottom padding. |
| The switch said "Settings saved." | found in testing | Now says "The Marketing head is on/off" with what that means. |

## Not changed, and why

- **Approving several drafts at once** (Alex). Out of scope. The 3-a-day cap keeps the queue short; worth adding if the queue grows.
- **"From Angelito, drafted with Mira"** instead of "From Mira · approved by Angelito" (critique question). The spec fixes the wording in AF-4.
- **Sending "late" nudges automatically** (critique question). The spec decides that only the CEO approves in this phase.
- **Merging the switch and budget into one line on phones.** The header is now shorter (no borders, shorter tiles), and each control still says what it means in words. The reviewer can decide whether to go further.
- **The red storage banner, and the empty grey band under it on CEO pages.** Both come from the shared layout (`x-layout`) and appear on every page, not only these two.
- **Low-contrast grey and indigo text in the existing checklist table, and its sideways scroll at 390 px.** Existing code that this spec does not touch. The page itself has no horizontal scroll: the table scrolls inside its own box, as before.
