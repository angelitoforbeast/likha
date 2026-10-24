# Brief 146: the chatbot pages "Changes" list shows every change — who set, changed or removed each value — including the flow and the bot switch

## Why
`ChatbotController::changes()` (around line 474) lists writes (new page_settings rows) and "cleared" values, but a clear is shown only when no newer row exists for the same page + provider + key. On Hanna Dela Vega, Francis Avila removed the flow at 2026-10-24 19:02 (page_settings rows 5379/5380, replaced_by set); after the owner set it again at 2026-10-25 06:52 the removal vanished from the list, and the 06:52 entry showed "Main Flow → Main Flow" because "before" is taken from the previous row, not from "(none)". The owner: "laging ipakita yung galaw dun sa flow, sino nag-alis, nagpalit" and "kita dapat lahat ng logs".

## Decisions
| Decision | Why |
|---|---|
| Every event is listed, newest first, never hidden by a later value: each write (created_at, created_by) and each clear (a row deactivated without being replaced by a new row at that moment: `replaced_at` set and no row for the same page+provider+key created within a few seconds after it; use `replaced_by` as the user, "system" if null). | Nothing disappears. |
| The "before" value of a write is "(none)" when the field had been cleared before it (the previous row's replaced_at is earlier than this write by more than a few seconds); otherwise the previous row's value. A clear shows "value → (none)". | Correct story: removed, then set again. |
| Include the fields that matter for "who touched the bot": FLOW (show the flow name; `flow_id` events fold into the same line as `flow`, or show `flow_id` as "Flow ID" — no duplicate noise), the bot switch `active` (On/Off), the Forbeast/Botcake connection keys already listed, and every chatbot field. Secrets stay masked (token values never shown in full, as today). Keep `mock_ai*` hidden. | "kita dapat lahat ng logs". |
| Filters (page, user, field, from/to) and paging keep working with the merged events; a user filter matches the writer of a write and the remover of a clear. English UI, no horizontal scroll. | Same modal. |

## Cases (S-146 with tests)
P1: .1 the Hanna case: set 16:07 by A, cleared 19:02 by A, set 06:52 by B → three lines: "(none) → Main Flow" by A, "Main Flow → (none)" by A at 19:02, "(none) → Main Flow" by B at 06:52; .2 a normal replacement (A → B) shows one line with the right before/after, no extra clear line; .3 bot switch Off/On by a user shows with the user and time; .4 user filter = remover finds the clear; field filter "flow" finds all three; .5 date filter uses the clear's replaced_at; .6 token values stay masked. P2: .7 paging across mixed writes/clears is stable.

## Done when
- P1 tests pass; `php vendor\phpunit\phpunit\phpunit tests/Feature/Chatblast tests/Feature/PageSettings` adds no new failures.
- Result: files, the clear-detection rule, evidence, commits. End with "146: <done|blocked> — <one line>".

## Out of scope
Other pages' change logs; deploying.
