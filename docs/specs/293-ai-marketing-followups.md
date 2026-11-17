# 293 — AI follow-ups for Marketing: drafted by AI, approved by the CEO, shown on /checklist

## Why
The owner wants an AI that tells his staff what to do, follows up, and learns from his choices, starting with Marketing (plan "LDT Virtual Office", Phase 1, 2026-11-18). His answers: "1. oo 2. chatgpt 3. 3k 4. ako muna", and "wag muna idamay yung MA". So: an AI "Marketing head" inside Likha, on the existing OpenAI key, drafts a short follow-up for a marketing staff member whose checklist task today is **late** or **not approved**. The CEO approves, edits or rejects each draft. An approved follow-up shows to that person on `/checklist` as **"From Mira · approved by <CEO first name>"**. Every draft, edit, rejection and reason is kept so the drafts improve. Budget: **₱3,000 a month**, enforced in code.

## Stories and cases
**AF-1 Drafts (P1).** A scheduled command `checklist:ai-followups` (every 30 minutes, 09:00–21:00 Asia/Manila, registered in `routes/console.php` like the other entries) finds, for today, each (marketing staff member, checklist task) that is **late and not submitted**, or whose **latest approval verdict is `not_approved`**, using the existing rules (`ChecklistDueToday::rows`, `ChecklistDueToday::isLate`, the latest verdict as `withApproval()` reads it). For each one without a follow-up yet (any status) for the same task, person and date, it asks OpenAI for one follow-up and saves it as a **draft** (not visible to staff).
- Marketing = active staff whose department key is Marketing: `ChecklistBadge::departmentKey()` / the controller's `isMarketing()` rule (`^MARKETING(\s*-\s*OIC)?$`). The Marketing OIC is included. CEO accounts, Marketing Assistants and every other department are excluded (test each).
- H: a marketing task due today with its deadline passed and no submission → one draft for that person and task.
- H: a submission whose latest verdict is `not_approved` → one draft that names what to fix, using the verdict's reason.
- E: the command runs again → no second draft for the same task, person and date.
- E: at most 3 follow-ups per person per day (drafts and approved together); the 4th is skipped.
- E: `enabled` is off (default) → the command does nothing and calls no API.
- E: this month's spend has reached the cap → no API call; the drafts page shows "Budget reached".
- N: OpenAI fails, times out or returns something that is not the expected JSON → no draft, one log line, the command goes on with the next row and exits 0.

**AF-2 What the AI writes (P1).** One call per draft, through the Laravel Http client like `runApprovalCheck()`. Model from a new config key `services.openai.followup_model` (env `OPENAI_FOLLOWUP_MODEL`, default `gpt-4o-mini`). The prompt gives: the person's first name, the task title and description, today's deadline, whether it is late or not approved, and the verdict's reason; plus the **last 20 reviewed follow-ups** (approved as drafted, edited with the before/after text, rejected with the CEO's reason), newest first, as examples of what the CEO wants. Output is JSON only: `{"message": "...", "deadline": "HH:MM"}`.
- The message is English, plain, polite, at most 240 characters, addressed to the person by first name, says what to do and by when. No money, no threats, no promises (in the system prompt; the CEO reviews every draft anyway).
- `deadline` is today, between now + 30 minutes and 21:00; outside that it is clamped (test).
- Everything quoted from staff notes or verdict reasons is data inside the prompt, never instructions; output longer than 240 characters is refused as a draft (not cut silently).

**AF-3 The CEO reviews (P1).** A CEO-only page `/checklist/ai-followups` (same middleware as the checklist routes, CEO check as `ChecklistController` does it; everyone else gets 403) with a nav link added by a migration in the style of the recent `add_*_nav_link` migrations, visible to the CEO only.
- **Drafts**, newest first: person, task, date, why (Late since 15:30 / Not approved: <reason>), the drafted message, the deadline, the cost of that draft in pesos.
- Actions per draft: **Approve**; **Edit and approve** (message and deadline, same limits as AF-2); **Reject** (a reason is required, at most 300 characters). Each action records who, when, and the note.
- **Sent**: approved follow-ups with their state: Waiting, Done (the original task got `approved` for that date), Expired (the day ended first).
- Top of the page: an **On/Off switch** for the Marketing head, and "This month: ₱x of ₱3,000" (cap editable on the same page, CEO only).
- A draft not reviewed by 23:59 Manila becomes Expired and is never shown to staff.

**AF-4 Staff see it (P1).** On `/checklist`, an approved follow-up shows to its one person as a card at the top: "From Mira · approved by <CEO first name>", the message, "by <deadline>", and a link to the original task. It shows only on its date, only while Waiting. It is not a checklist item: it has no submit button and is **not counted** anywhere tasks are counted (badge, `ChecklistDueToday`, the staff feed, reports, manage lists): test each of these stays the same with and without follow-ups present.
- H: the original task becomes `approved` for that date → the card is gone and the follow-up shows Done on the CEO page.
- E: another staff member never sees it; a draft or a rejected one is never shown.

**AF-5 Learning record (P1).** For every follow-up, keep: the AI's original draft (message, deadline, model, tokens, cost), the final text, the review action, the reviewer, the time and the note. Nothing is deleted. The staff feed (`/api/staff-events`) gains an event per approved follow-up and per Done, so Mira can record the owner's decisions later (event shape in the result).

**AF-6 It looks finished, not like a test (P1).** The owner: "dapat maganda yan, pati ui ayusin mo". Design both surfaces before building them, with the `impeccable` skill (shape the page first, then build, then its critique and audit, fixing what they find). Keep Likha's look (Tailwind, `x-layout`, white cards on `bg-gray-50`, the checklist's type sizes) and add **one** AI accent colour used only for what comes from the AI. Write the brief and the renders to `docs/design/293-ai-followups/` as earlier Likha designs did (`docs/design/199-live/brief.md` is the model).
- **The CEO page is an inbox you clear in a minute.** One card per draft: the person (initials avatar, name, department), the original task, a "why" chip (amber "Late since 15:30", red "Not approved" with the reason on hover or expand), the drafted message as the card's main text, the deadline as a chip, the cost in small grey. Actions on the card: Approve (primary), Edit (the message and deadline become editable in place, then "Save and approve"), Reject (a reason box opens in place). Keyboard: J/K move between cards, A approve, E edit, R reject. After an action the card leaves with a short transition and the next one is focused; an undo for 5 seconds on Approve.
- **Header of that page:** the On/Off switch with what it means in one line ("Off: the Marketing head drafts nothing"), the budget as a meter "₱x of ₱3,000 this month" that turns amber at 80% and red at 100%, and tabs Drafts (count) / Sent / Rejected. Sent rows show Waiting, Done or Expired as chips. Each tab has a real empty state ("No drafts. The Marketing head checks every 30 minutes from 9 AM to 9 PM.").
- **The staff card on /checklist** is the first thing on the page, in the AI accent: a small Mira mark, "From Mira · approved by Angelito", the message in readable size, "by 4:00 PM" as a chip, and "Open task" that scrolls to and highlights the original task. It never looks like an error; it reads like a note from the boss.
- **Phone first for staff:** both the card and /checklist work at 390 px wide with no horizontal scroll; the CEO page works at 390 px and 1280 px.
- **English everywhere on these pages,** including the `/checklist` header counts that are Tagalog today ("kailangang ayusin", "hindi pa naisumite", "sinusuri pa" become "need a fix", "not submitted", "checking").
- Accessibility: visible focus, buttons with labels, colour never the only signal (chips carry words), contrast AA.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| ChatGPT on Likha's existing `OPENAI_API_KEY` | Owner's answer 2 |
| ₱3,000 a month cap, enforced before every call | Owner's answer 3 |
| Only the CEO approves in this phase | Owner's answer 4 |
| Marketing only; Marketing Assistants out | "wag muna idamay yung MA" |
| A follow-up is a nudge tied to an existing task, not a new checklist item | Staff do the task they already have; nothing new to submit |
| Off by default; the CEO switches it on after deploy | Nothing reaches staff before he chooses |
| All UI text English | His rule: websites in English, Tagalog only in chat |
| Likha's own look plus one AI accent, designed with `impeccable` first | "dapat maganda yan, pati ui ayusin mo"; earlier Likha designs were done this way |

## Constraints
- **Lean schema** (his rule: no new table unless needed). Store follow-ups as rows of `checklist_tasks` with new nullable columns, guarded with `Schema::hasColumn`: `origin` (null or `ai`), `follows_task_id`, `follows_date`, `follows_reason` (`late` / `not_approved`), `review_status` (`draft` / `approved` / `rejected` / `expired`), `reviewed_by`, `reviewed_at`, `review_note`, `ai_draft` (json: drafted message and deadline, model, prompt and completion tokens), `ai_cost_usd` (decimal). An `ai` row is `frequency = once`, `is_active = false` until approved, one assignee in `checklist_task_users`. If you find these columns would break existing queries in a way a separate table avoids, stop and say so in the result before building; do not add a table silently.
- **Every existing query that lists or counts checklist tasks must exclude `origin = 'ai'`** (index, badge, ChecklistDueToday, staff feed, reports, manage, history). List each place you changed in the result.
- Settings live in `app_settings` (`AppSetting::get/set`): `ai_followups.enabled` (default off), `ai_followups.monthly_cap_php` (default 3000), `ai_followups.php_per_usd`, and the model's input/output price per 1M tokens. Set the price defaults from OpenAI's official pricing page and the peso rate from a named source, each with its URL and date in the result; the CEO can edit them on the page.
- Monthly spend = sum of `ai_cost_usd` of `origin = 'ai'` rows created this Manila month × `php_per_usd`.
- Keep every comment in the language and style of the file around it. Follow the existing Blade and controller style of the checklist pages.
- Feature tests in `tests/Feature/Checklist/` with `Http::fake()` for OpenAI; no real API call in tests.

## Threat model and risk
Untrusted: staff notes, verdict reasons and task text inside the prompt (prompt injection: the output only ever reaches staff after the CEO approves it, is length-capped, JSON-validated and HTML-escaped); the review form inputs (validate, CSRF, CEO-only). New write surface: the review actions and the settings (CEO-only, tested 403 for OIC and staff). Spending: the cap is checked before every call, and the switch is off by default. Risk tier: medium (a new scheduled job that calls a paid API, and changes to queries that count tasks).

## Allowed commands
`composer.bat install`, `php.bat artisan test` (with filters) and `vendor/bin/phpunit` via Herd PHP (`C:\Users\Forbeast\.config\herd\bin\php.bat`); `php.bat artisan route:list`; `php.bat artisan view:cache` (to check Blade compiles); `php.bat artisan schedule:list`; `npm ci` and `npm run build`; `php.bat artisan serve` on 127.0.0.1 with a local sqlite database seeded for the screenshots (never the real database); a headless browser for screenshots of 127.0.0.1 pages and local files only; the `impeccable` skill; web pages of OpenAI's pricing and a peso rate source (read only); git add, git commit, git push -u origin feat/293-ai-marketing-followups.

## Done when
1. Tests for AF-1..AF-5 pass, including: who is marketing (OIC in, MA out, CEO out), no duplicate draft, the 3-a-day cap, switch off = no call, budget reached = no call, bad API answer = no draft, deadline clamp, length refusal, CEO-only page and actions (403 for OIC and staff), reject needs a reason, expiry at day end, the staff card shows only to its person and clears when the original is approved, and **every count and list stays the same** with follow-up rows present. The full suite adds no new failures compared with base `dea0c621` (list the base failures).
2. Evidence in the result: screenshots from a local run with seeded data, saved in `docs/design/293-ai-followups/`: the CEO page with three drafts at 1280 px and 390 px, the edit and reject states, each empty state, and `/checklist` with the staff card at 390 px and 1280 px; the impeccable critique and audit findings and what was fixed; the exact prompt sent for one seeded row.
3. The result lists the migration, the new routes, the schedule entry, the settings keys with defaults and their sources, the staff-events shape, and a merge-danger section (what ships, the migration, the schedule, how to switch it off, how to revert).
4. The branch is pushed. No merge, no deploy, no migration on any server.

## Out of scope
Threads or replies from staff (Phase 2); push notifications; other departments; OIC approval; Mira reading the events into her memory; the 3D office; deploy (the reviewer does it on the owner's yes).

## Budget
Medium-to-large job (logic plus two designed pages). At most 2 attempts at the same failing step, then stop and report.
