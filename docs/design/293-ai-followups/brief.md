# Design brief: AI follow-ups, "the boss's inbox and the boss's note"

Surfaces: `/checklist/ai-followups` (new, CEO only, Blade `resources/views/checklist/ai-followups.blade.php`) and a card at the top of `/checklist` (Blade `resources/views/checklist/partials/ai-followup-card.blade.php`, included by `checklist/index.blade.php`). Layout `x-layout`, Tailwind from the CDN, Alpine.
Mode: **Operate** on both. The CEO clears a queue; staff read one note and act on it. Ambition shows as precision and restraint, not decoration.
Shaped with the `impeccable` skill (shape, then build, then critique and audit). No owner interview was possible in this session: the answers below come from spec 293 (AF-6) and the owner's own words quoted there ("dapat maganda yan, pati ui ayusin mo"). Assumptions are marked **(assumed)**.
Files in this folder: this brief, then the screenshots of the built pages from a local seeded run (`ceo-1280.png`, `ceo-390.png`, `ceo-edit.png`, `ceo-reject.png`, `ceo-empty-*.png`, `staff-390.png`, `staff-1280.png`) and the critique and audit notes in `review.md`.

---

## 1. Job and audience

| | CEO inbox | Staff card |
|---|---|---|
| Who | The owner (CEO), the only reviewer in this phase | One marketing staff member (or the Marketing OIC) |
| When | A few times a day, between other work, often on a phone | When they open `/checklist`, mostly on a phone |
| Need | Decide fast whether each AI draft goes out: yes, yes with a change, or no with a reason | Know what the boss wants done and by when, then go do the task |
| Success | Three drafts cleared in under a minute, without reading anything twice | They tap "Open task", land on the right row, and finish it |

What is uniquely true here: every message a staff member sees was written by an AI **and** approved by a named person. Both facts are shown, always, and the AI part is the only thing in the accent colour.

## 2. Visual authority

Likha's existing checklist pages are the authority (no DESIGN.md exists): `bg-gray-50` page, white `rounded-2xl` cards with `border-gray-100 shadow-sm`, a sticky white header with `text-base font-bold text-gray-800` title and `text-xs text-gray-400` sub line, body text `text-sm`, meta `text-xs`, blue for the primary action and progress, green / amber / red for states, `fa-solid` icons and plain words. Nothing here replaces that.

**One AI accent: violet.** `violet-600` (#7C3AED) for the Mira mark, `violet-50` for AI surfaces (the drafted message block), `violet-200` for their border, `violet-800` for text on violet. Reason: the checklist report already marks AI analysis in purple, so violet continues an existing meaning instead of inventing one, and it is far from the green/amber/red state colours, so "from the AI" never reads as "error" or "warning". Violet is used **only** on what the AI wrote (the drafted message block, the Mira mark, the "From Mira" line, the switch's on state because it switches the AI). Buttons, chips for state, the budget meter and links stay in Likha's own colours.

The Mira mark: a small violet rounded square with a white sparkle glyph (`fa-solid fa-wand-magic-sparkles`, 12 px) **(assumed: no Mira logo asset exists in this repo)**.

## 3. CEO inbox `/checklist/ai-followups`

### Header (scrolls with the page; changed after the critique, see `review.md`: a sticky header this tall would cover half a phone screen)
- Left: title "Marketing head", sub line "AI follow-ups for marketing · drafted by AI, sent only when you approve".
- **Switch** (a real `role="switch"` button, label "Marketing head"), state word beside it: "On: drafts every 30 minutes, 9 AM to 9 PM" / "Off: the Marketing head drafts nothing". Off is grey, on is violet.
- **Budget meter**: "₱412 of ₱3,000 this month", a 6 px bar (the checklist's progress-bar shape). Blue under 80%, amber from 80% ("Close to the cap" in words), red at 100% ("Budget reached: no new drafts this month"). The words carry the state, not only the colour. A small "Settings" link opens the settings drawer (cap ₱, peso rate, input and output price per 1M tokens, model shown read-only) inline under the header.
- **Tabs**: Drafts (count) · Sent · Rejected. Underline tabs, `aria-selected`, the count in a grey pill.

### Draft card (one per draft, newest first, max width 760 px centred; full width on phone)
```
┌──────────────────────────────────────────────────────────────┐
│ (JR) Joy Ramos · Marketing              [Late since 3:30 PM] │  ← avatar initials, name, dept; why-chip right
│ Task: Post 3 stories on the main page                        │  ← text-xs gray, links to the task in the report
│ Hi Joy, please post today's 3 stories on the main page     │  ← the draft: text-[15px] gray-900 in a rounded
│ and submit them on the checklist by 4:30 PM. Thank you!    │    violet-50 block (the AI's words), no side rule
│ [by 4:30 PM]   ₱0.02 · gpt-4o-mini · 18 Nov                  │  ← deadline chip; cost small grey
│ [ Approve ]  [ Edit ]  [ Reject ]           A · E · R        │  ← primary blue, two quiet buttons, key hints
└──────────────────────────────────────────────────────────────┘
```
- **Why chip**: amber "Late since 3:30 PM" (deadline passed, nothing submitted) or red "Not approved"; the verdict's reason is one line under the task ("Why not approved: ...") and also the chip's `title` on hover. Changed after the critique: the CEO needs the reason to judge the draft, so it is not hidden behind a disclosure. Words always present.
- **Edit**: the message becomes a textarea in place (same box, same size, counter "184 / 240", red past 240 and the save disabled) and the deadline a time input (min now + 30 min, max 9:00 PM, the limits written under it). Buttons become "Save and approve" (primary) and "Cancel".
- **Reject**: a reason box opens in place ("Why not? The Marketing head learns from this.", required, counter / 300), buttons "Reject" (red outline) and "Cancel". Empty reason: the button stays disabled and the server also refuses.
- **After an action**: the card collapses (opacity and height, 200 ms, `prefers-reduced-motion` turns it off), focus goes to the next card. Approve shows a toast at the bottom "Approved · Joy will see it on the checklist" with **Undo** for 5 seconds; the approval is sent when the 5 seconds end (or at once if the page is left), so an undone approval never reaches staff.
- **Keyboard**: J / K next and previous card (focus ring on the card), A approve, E edit, R reject, U undo, Esc cancels edit/reject, Ctrl+Enter saves. A, E and R act only on the focused card (the first press with nothing focused only focuses the first card); keys are ignored while typing in a field.
- **Deadline and text agree**: changing the deadline in edit rewrites the time written in the message ("by 7:30 PM" becomes "by 8:00 PM"), so staff never see two different times. A one-line legend under the tabs: "J/K move · A approve · E edit · R reject".

### Sent and Rejected tabs
- Sent: compact rows (avatar, name, the final message on one line that wraps on phone, "by 4:30 PM", date) with a state chip: **Waiting** (blue), **Done** (green, "the task was approved"), **Expired** (grey, "the day ended first"). An "Edited by you" tag in grey outline when the CEO changed the AI's text (grey, not violet: it marks the CEO's words, not the AI's); the original is under a "Show the AI's draft" disclosure.
- Rejected: the AI's draft, the CEO's reason in a quote line, who and when. Expired drafts (never reviewed) also live here with the word "Expired, not reviewed".

### Empty states (real sentences, the checklist's empty-state shape: centred, icon, line, sub line)
- Drafts: "No drafts." / "The Marketing head checks every 30 minutes from 9 AM to 9 PM." When off: "The Marketing head is off. Switch it on to start drafting." When the budget is reached: "Budget reached. No new drafts until next month, or raise the cap."
- Sent: "Nothing sent yet." / "Approved follow-ups show here with Waiting, Done or Expired."
- Rejected: "Nothing rejected." / "When you reject a draft, your reason teaches the next ones."

## 4. Staff card on `/checklist`

The first thing on the page, above the page header and the table's column headers (moved there after the critique: under the column headers it read as a table row). One card per approved follow-up for that person today, at most three.
```
┌──────────────────────────────────────────────┐
│ [✦] From Mira · approved by Angelito          │ ← violet mark + text-xs violet-800
│ Hi Joy, please post today's 3 stories on the │ ← text-[15px] leading-relaxed gray-900
│ main page and submit them by 4:30 PM.        │
│ [by 4:30 PM]                    Open task →  │ ← chip; link button
└──────────────────────────────────────────────┘
```
- White card with a 1 px `violet-200` border all round (no thick side rule: the craft floor refuses it). It reads as a note from the boss: no red, no warning icon, no exclamation.
- "Open task" scrolls to the original task row (`#task-<id>` on its `<tbody>`) and highlights it for 2 seconds with a violet-50 wash and ring; the task row's own state is untouched. If the task row is not on the page (time gate), the link falls back to the top of the table.
- Not a checklist item: no checkbox, no submit, not in the header counts or the progress bar.
- At 390 px: full width with the page's 16 px gutter, the chip and link wrap under the message, tap target ≥ 44 px for "Open task".

**Header counts in English** (same page): "{n} approved · {n} need a fix · {n} not submitted · {n} checking".

## 5. States and ranges
- Drafts: 0 to ~30 a day (marketing team × 3 cap). Messages up to 240 characters; names up to ~30 characters; task titles up to ~120 (wrap, no truncation on the card; one-line clamp in Sent rows on desktop only).
- Budget: ₱0 to the cap; cap editable 0-100,000.
- Staff card: 0-3 per person per day.

## 6. Constraints
- Phone first: both surfaces at 390 px with no horizontal page scroll; the CEO page also at 1280 px.
- English everywhere on these pages.
- Accessibility: visible focus (`focus-visible:ring-2 ring-offset-2`), every icon button labelled, chips carry words, AA contrast (violet-800 on violet-50, amber-800 on amber-50, red-700 on red-50, gray-500 minimum for meta text on white).
- All AI and staff text is HTML-escaped (`{{ }}`), never `{!! !!}`.
- No new build step: Tailwind CDN classes and Alpine, like the checklist pages.

## 7. Anti-goals
- No chatbot look, no gradients, no glow, no robot icon. No "AI magic" copy.
- The staff card must not look like an error or a task.
- No new colours beyond violet; no new fonts.
